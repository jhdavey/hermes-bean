#!/usr/bin/env python3
"""Train Bean's self-contained browser wake classifier from local macOS voices.

The script uses only macOS ``say``/``afconvert`` and NumPy. It never uploads
audio and stores generated speech only in an ignored local cache. Benchmark
voices are held out completely from fitting and threshold selection.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import random
import subprocess
import tempfile
import wave
from concurrent.futures import ThreadPoolExecutor, as_completed
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

import numpy as np


SAMPLE_RATE = 16_000
FFT_SIZE = 512
WINDOW_SAMPLES = 400
HOP_SAMPLES = 160
MEL_BANDS = 32
NORMALIZED_FRAMES = 48
FEATURE_SIZE = MEL_BANDS * NORMALIZED_FRAMES
CLASSES = ("reject", "strict_wake", "missed_hey_confirmation")

FIT_VOICES = (
    "Albert", "Aman", "Junior", "Ralph", "Tara",
    "Samantha", "Daniel", "Karen", "Moira", "Rishi",
    "Fred", "Tessa",
    "Eddy (English (UK))", "Eddy (English (US))",
    "Flo (English (UK))", "Flo (English (US))",
    "Grandma (English (UK))", "Grandma (English (US))",
    "Reed (English (US))", "Rocko (English (UK))",
    "Sandy (English (US))", "Shelley (English (UK))",
    "Reed (English (UK))", "Sandy (English (UK))", "Shelley (English (US))",
)
VALIDATION_VOICES = ("Kathy",)
HELD_OUT_VOICES = (
    "Grandpa (English (UK))",
    "Grandpa (English (US))",
    "Rocko (English (US))",
)
RATES = (160, 190, 220)

STRICT_PHRASES = (
    "Hey Bean.",
    "Hey, Bean.",
    "Hey Bean!",
    "Hey Bean, what time is it?",
    "Hey Bean, what's the weather this evening?",
    "Hey Bean, set a reminder for four p.m. today.",
    "Hey Bean, create a note called groceries.",
    "We should finish the shopping list before dinner and, hey Bean.",
)

ADDRESS_PHRASES = (
    "Bean, can you.",
    "Bean, could you.",
    "Bean, are you.",
    "Bean, check my.",
    "Bean, what time.",
    "Bean, what is today's.",
    "Bean, when is my.",
    "Bean, where is the.",
    "Bean, why is it.",
    "Bean, how do I.",
    "Bean, how can I.",
    "Bean, set a reminder for.",
    "Bean, create a note called.",
)

REJECT_PHRASES = (
    "Hey beam.",
    "Hey Ben.",
    "Hey been.",
    "Hey being.",
    "Hey Dean.",
    "Hey Gene.",
    "Hey Bing.",
    "Hey beans.",
    "Hey team.",
    "Hey B.",
    "Pretty girl.",
    "Pretty good.",
    "I told Sarah about Bean yesterday.",
    "Bean is a useful assistant.",
    "Bean was mentioned in the meeting.",
    "Green bean casserole is for dinner.",
    "They have been waiting outside.",
    "We should finish the shopping list before dinner.",
    "Can you help me with this tomorrow?",
    "What time is the meeting?",
    "Set a reminder for four p.m.",
    "Create a note called groceries.",
    "The music is quiet in the background.",
    "I think we should leave soon.",
    "Thanks, that is all for today.",
    "Maybe we can talk about it later.",
    "Please check the calendar after lunch.",
)


@dataclass(frozen=True)
class Utterance:
    voice: str
    rate: int
    phrase: str
    label: str
    path: Path


def hz_to_mel(value: np.ndarray | float) -> np.ndarray | float:
    return 2595.0 * np.log10(1.0 + np.asarray(value) / 700.0)


def mel_to_hz(value: np.ndarray | float) -> np.ndarray | float:
    return 700.0 * (np.power(10.0, np.asarray(value) / 2595.0) - 1.0)


def mel_filterbank() -> np.ndarray:
    mel_points = np.linspace(hz_to_mel(80.0), hz_to_mel(7_600.0), MEL_BANDS + 2)
    bins = np.floor((FFT_SIZE + 1) * mel_to_hz(mel_points) / SAMPLE_RATE).astype(int)
    bins = np.clip(bins, 0, FFT_SIZE // 2)
    filters = np.zeros((MEL_BANDS, FFT_SIZE // 2 + 1), dtype=np.float32)
    for band in range(MEL_BANDS):
        left, center, right = int(bins[band]), int(bins[band + 1]), int(bins[band + 2])
        center = max(center, left + 1)
        right = max(right, center + 1)
        for index in range(left, min(center, filters.shape[1])):
            filters[band, index] = (index - left) / max(1, center - left)
        for index in range(center, min(right, filters.shape[1])):
            filters[band, index] = (right - index) / max(1, right - center)
    return filters


MEL_FILTERS = mel_filterbank()
HANN_WINDOW = np.hanning(WINDOW_SAMPLES).astype(np.float32)


def read_wave(path: Path) -> np.ndarray:
    with wave.open(str(path), "rb") as handle:
        if handle.getnchannels() != 1 or handle.getsampwidth() != 2 or handle.getframerate() != SAMPLE_RATE:
            raise RuntimeError(f"Unexpected WAVE format: {path}")
        data = handle.readframes(handle.getnframes())
    return np.frombuffer(data, dtype="<i2").astype(np.float32) / 32768.0


def trim_speech(samples: np.ndarray) -> np.ndarray:
    if samples.size < WINDOW_SAMPLES:
        return np.pad(samples, (0, WINDOW_SAMPLES - samples.size))
    frame = 320
    rms = np.asarray([
        math.sqrt(float(np.mean(np.square(samples[start : start + frame]))) + 1e-12)
        for start in range(0, samples.size, frame)
    ])
    active = np.flatnonzero(rms >= 0.009)
    if active.size == 0:
        return samples[-min(samples.size, SAMPLE_RATE) :]
    start = max(0, int(active[0]) * frame - 1_600)
    end = min(samples.size, (int(active[-1]) + 1) * frame + 1_920)
    return samples[start:end]


def feature_vector(samples: np.ndarray) -> np.ndarray:
    samples = trim_speech(np.asarray(samples, dtype=np.float32))
    if samples.size < WINDOW_SAMPLES:
        samples = np.pad(samples, (0, WINDOW_SAMPLES - samples.size))
    frame_count = 1 + max(0, (samples.size - WINDOW_SAMPLES) // HOP_SAMPLES)
    frames = np.empty((frame_count, WINDOW_SAMPLES), dtype=np.float32)
    for index in range(frame_count):
        start = index * HOP_SAMPLES
        frames[index] = samples[start : start + WINDOW_SAMPLES] * HANN_WINDOW
    spectrum = np.fft.rfft(frames, n=FFT_SIZE, axis=1)
    power = np.square(np.abs(spectrum)).astype(np.float32)
    mel = np.log1p(np.maximum(power @ MEL_FILTERS.T, 0.0))
    positions = np.linspace(0.0, max(0, frame_count - 1), NORMALIZED_FRAMES)
    left = np.floor(positions).astype(int)
    right = np.minimum(left + 1, frame_count - 1)
    fraction = (positions - left).astype(np.float32)[:, None]
    normalized = mel[left] * (1.0 - fraction) + mel[right] * fraction
    # Preserve the relative spectral envelope that distinguishes terminal
    # consonants (Bean/beam/been/Dean). Per-band utterance normalization erased
    # those phonetic relationships and made the classifier learn duration and
    # context artifacts instead. Whole-utterance normalization remains robust
    # to microphone gain while retaining cross-band speech information.
    normalized -= np.mean(normalized)
    normalized /= max(float(np.std(normalized)), 0.12)
    return normalized.astype(np.float32).reshape(FEATURE_SIZE)


def slug(value: str) -> str:
    return "-".join("".join(character.lower() if character.isalnum() else " " for character in value).split())


def utterance_path(cache: Path, voice: str, rate: int, label: str, phrase: str) -> Path:
    digest = hashlib.sha256(phrase.encode()).hexdigest()[:10]
    return cache / f"{slug(voice)}-{rate}-{slug(label)}-{digest}.wav"


def render_utterance(item: Utterance) -> Utterance:
    if item.path.exists() and item.path.stat().st_size > 100:
        return item
    item.path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="bean-wake-tts-") as directory:
        aiff = Path(directory) / "speech.aiff"
        wav = Path(directory) / "speech.wav"
        subprocess.run(
            ["/usr/bin/say", "-v", item.voice, "-r", str(item.rate), "-o", str(aiff), item.phrase],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        subprocess.run(
            [
                "/usr/bin/afconvert", "-f", "WAVE", "-d", f"LEI16@{SAMPLE_RATE}",
                "-c", "1", str(aiff), str(wav),
            ],
            check=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        os.replace(wav, item.path)
    return item


def build_utterances(cache: Path, voices: Iterable[str]) -> list[Utterance]:
    groups = (
        ("strict_wake", STRICT_PHRASES),
        ("missed_hey_confirmation", ADDRESS_PHRASES),
        ("reject", REJECT_PHRASES),
    )
    return [
        Utterance(voice, rate, phrase, label, utterance_path(cache, voice, rate, label, phrase))
        for voice in voices
        for rate in RATES
        for label, phrases in groups
        for phrase in phrases
    ]


def render_all(items: list[Utterance], workers: int) -> None:
    pending = [item for item in items if not item.path.exists()]
    if not pending:
        return
    print(f"Rendering {len(pending)} local TTS files...", flush=True)
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(render_utterance, item) for item in pending]
        for index, future in enumerate(as_completed(futures), 1):
            future.result()
            if index % 100 == 0 or index == len(futures):
                print(f"  rendered {index}/{len(futures)}", flush=True)


def augmented_features(base: np.ndarray, rng: np.random.Generator, copies: int) -> list[np.ndarray]:
    matrix = base.reshape(NORMALIZED_FRAMES, MEL_BANDS)
    results = [base]
    for _ in range(copies):
        value = matrix.copy()
        value += rng.normal(0.0, rng.uniform(0.015, 0.06), value.shape).astype(np.float32)
        if rng.random() < 0.65:
            width = int(rng.integers(1, 6))
            start = int(rng.integers(0, NORMALIZED_FRAMES - width + 1))
            value[start : start + width] *= rng.uniform(0.45, 0.9)
        if rng.random() < 0.5:
            width = int(rng.integers(1, 4))
            start = int(rng.integers(0, MEL_BANDS - width + 1))
            value[:, start : start + width] *= rng.uniform(0.55, 0.92)
        results.append(value.reshape(FEATURE_SIZE).astype(np.float32))
    return results


def resample_ratio(samples: np.ndarray, ratio: float) -> np.ndarray:
    target_size = max(WINDOW_SAMPLES, int(round(samples.size * ratio)))
    source_positions = np.linspace(0.0, max(0, samples.size - 1), target_size, dtype=np.float32)
    return np.interp(source_positions, np.arange(samples.size), samples).astype(np.float32)


def candidate_acoustic_variants(
    samples: np.ndarray,
    rng: np.random.Generator,
    label: str,
    phrase: str,
) -> list[np.ndarray]:
    """Approximate candidate-window, browser, room, and device variation locally.

    Production classifies the timestamped candidate prefix, not a complete
    command. Every variant therefore retains the active phrase while changing
    its local boundary and acoustic channel. Raw inputs never leave this
    process and are stored only in the ignored local training cache.
    """
    value = np.asarray(samples, dtype=np.float32)
    frame = 160
    rms = np.asarray([
        math.sqrt(float(np.mean(np.square(value[start : start + frame]))) + 1e-12)
        for start in range(0, value.size, frame)
    ])
    active = np.flatnonzero(rms >= 0.006)
    if active.size == 0:
        bounded = value.copy()
    else:
        active_start = int(active[0]) * frame
        active_end = min(value.size, (int(active[-1]) + 1) * frame)
        start = max(0, active_start - 1_600)
        end = min(value.size, active_end + 1_920)
        bounded = value[start:end].copy()

    variants = [bounded]
    # Candidate timestamps and VAD boundaries vary slightly by browser/model.
    for leading, trailing in ((0, 0), (800, 0), (1_600, 0), (800, 960), (1_600, 1_920)):
        variants.append(np.pad(bounded, (leading, trailing)))
    # Speaking-rate/pitch and browser resampling variation.
    variants.extend((resample_ratio(bounded, 0.86), resample_ratio(bounded, 1.14)))

    signal_rms = max(0.01, math.sqrt(float(np.mean(np.square(bounded))) + 1e-12))
    noisy = bounded + rng.normal(0.0, signal_rms * 0.035, bounded.shape).astype(np.float32)
    variants.append(np.clip(noisy, -1.0, 1.0))

    echo = bounded.copy()
    echo_delay = min(max(1, int(0.075 * SAMPLE_RATE)), max(1, bounded.size - 1))
    if bounded.size > echo_delay:
        echo[echo_delay:] += bounded[:-echo_delay] * 0.1
    variants.append(np.clip(echo, -1.0, 1.0))

    # Low-level music-like interference without any external audio assets.
    time = np.arange(bounded.size, dtype=np.float32) / SAMPLE_RATE
    music = (
        np.sin(2 * np.pi * 196.0 * time)
        + 0.55 * np.sin(2 * np.pi * 293.66 * time + 0.4)
        + 0.35 * np.sin(2 * np.pi * 392.0 * time + 1.1)
    ).astype(np.float32)
    variants.append(np.clip(bounded + music * signal_rms * 0.025, -1.0, 1.0))

    if label == "reject":
        # Production evaluates missed-Hey address intent while an utterance is
        # still unfolding. Train every important negative at the same prefix
        # boundaries so `Bean is ...`, `green bean ...`, and `been ...` cannot
        # borrow the address class merely because their full sentence has not
        # arrived yet.
        for seconds in (0.5, 0.8, 1.1, 1.4, 1.7, 2.0, 2.3):
            end = int(round(seconds * SAMPLE_RATE))
            if WINDOW_SAMPLES <= end < bounded.size:
                variants.append(bounded[:end].copy())
    elif label == "strict_wake":
        if phrase.lower().lstrip().startswith("hey"):
            # Production may receive a candidate after command speech begins.
            # Mirror the browser's onset-anchored classifier windows.
            for seconds in (0.5, 0.55, 0.6, 0.65, 0.7, 0.75, 0.8):
                end = int(round(seconds * SAMPLE_RATE))
                if WINDOW_SAMPLES <= end < bounded.size:
                    variants.append(np.pad(bounded[:end].copy(), (0, 4_000)))
        elif bounded.size > int(1.8 * SAMPLE_RATE):
            # A deliberate wake at the end of ongoing room speech is classified
            # from a bounded trailing window rather than the full conversation.
            for seconds in (1.0, 1.2, 1.4, 1.8, 2.0):
                size = int(round(seconds * SAMPLE_RATE))
                variants.append(bounded[-size:].copy())

    return variants


def matrix_for(items: list[Utterance], augmentation_copies: int, seed: int) -> tuple[np.ndarray, np.ndarray, list[dict]]:
    rng = np.random.default_rng(seed)
    vectors: list[np.ndarray] = []
    labels: list[int] = []
    metadata: list[dict] = []
    class_index = {label: index for index, label in enumerate(CLASSES)}
    for item in items:
        for acoustic_index, samples in enumerate(candidate_acoustic_variants(
            read_wave(item.path), rng, item.label, item.phrase,
        )):
            base = feature_vector(samples)
            for vector in augmented_features(base, rng, augmentation_copies):
                vectors.append(vector)
                labels.append(class_index[item.label])
                metadata.append({
                    "voice": item.voice,
                    "rate": item.rate,
                    "label": item.label,
                    "phrase": item.phrase,
                    "acoustic_variant": acoustic_index,
                })
    return np.stack(vectors), np.asarray(labels, dtype=np.int64), metadata


def softmax(logits: np.ndarray) -> np.ndarray:
    shifted = logits - np.max(logits, axis=1, keepdims=True)
    exp = np.exp(shifted)
    return exp / np.sum(exp, axis=1, keepdims=True)


def train_softmax(x: np.ndarray, y: np.ndarray, steps: int, seed: int) -> tuple[np.ndarray, np.ndarray, list[float]]:
    rng = np.random.default_rng(seed)
    weights = rng.normal(0.0, 0.005, (x.shape[1], len(CLASSES))).astype(np.float32)
    bias = np.zeros(len(CLASSES), dtype=np.float32)
    mw = np.zeros_like(weights)
    vw = np.zeros_like(weights)
    mb = np.zeros_like(bias)
    vb = np.zeros_like(bias)
    counts = np.bincount(y, minlength=len(CLASSES)).astype(np.float32)
    sample_weights = (np.sum(counts) / np.maximum(counts, 1.0))[y]
    sample_weights /= np.mean(sample_weights)
    one_hot = np.eye(len(CLASSES), dtype=np.float32)[y]
    losses: list[float] = []
    for step in range(1, steps + 1):
        probabilities = softmax(x @ weights + bias)
        clipped = np.clip(probabilities[np.arange(y.size), y], 1e-7, 1.0)
        loss = float(np.mean(-np.log(clipped) * sample_weights) + 0.0003 * np.sum(weights * weights))
        gradient = (probabilities - one_hot) * sample_weights[:, None] / y.size
        dw = x.T @ gradient + 0.0006 * weights
        db = np.sum(gradient, axis=0)
        learning_rate = 0.035 * (0.985 ** (step // 20))
        mw = 0.9 * mw + 0.1 * dw
        vw = 0.999 * vw + 0.001 * (dw * dw)
        mb = 0.9 * mb + 0.1 * db
        vb = 0.999 * vb + 0.001 * (db * db)
        weights -= learning_rate * (mw / (1.0 - 0.9**step)) / (np.sqrt(vw / (1.0 - 0.999**step)) + 1e-8)
        bias -= learning_rate * (mb / (1.0 - 0.9**step)) / (np.sqrt(vb / (1.0 - 0.999**step)) + 1e-8)
        if step == 1 or step % 25 == 0 or step == steps:
            losses.append(loss)
            print(f"  training step {step}/{steps}: loss={loss:.5f}", flush=True)
    return weights, bias, losses


def train_temporal_cnn(
    x: np.ndarray,
    y: np.ndarray,
    validation_x: np.ndarray,
    validation_y: np.ndarray,
    epochs: int,
    seed: int,
    initial_state: dict[str, np.ndarray] | None = None,
) -> tuple[dict[str, np.ndarray], list[float], np.ndarray, np.ndarray]:
    try:
        import torch
        from torch import nn
        from torch.utils.data import DataLoader, TensorDataset
    except ImportError as error:
        raise RuntimeError(
            "Temporal CNN training requires torch in PYTHONPATH; install it only in the ignored local training directory."
        ) from error

    torch.manual_seed(seed)
    np.random.seed(seed)
    torch.set_num_threads(max(1, min(8, os.cpu_count() or 1)))

    class WakeNet(nn.Module):
        def __init__(self) -> None:
            super().__init__()
            self.conv1 = nn.Conv1d(MEL_BANDS, 32, kernel_size=5, padding=2)
            self.conv2 = nn.Conv1d(32, 48, kernel_size=3, padding=1)
            self.pool = nn.MaxPool1d(2)
            self.dense1 = nn.Linear(48 * (NORMALIZED_FRAMES // 4), 64)
            self.dense2 = nn.Linear(64, len(CLASSES))

        def forward(self, value):
            value = value.reshape(-1, NORMALIZED_FRAMES, MEL_BANDS).transpose(1, 2)
            value = self.pool(torch.relu(self.conv1(value)))
            value = self.pool(torch.relu(self.conv2(value)))
            value = value.flatten(1)
            value = torch.relu(self.dense1(value))
            return self.dense2(value)

    model = WakeNet()
    if initial_state:
        model.load_state_dict({name: torch.from_numpy(value) for name, value in initial_state.items()})
    counts = np.bincount(y, minlength=len(CLASSES)).astype(np.float32)
    class_weights = torch.tensor(np.sum(counts) / np.maximum(counts, 1.0), dtype=torch.float32)
    criterion = nn.CrossEntropyLoss(weight=class_weights)
    optimizer = torch.optim.AdamW(
        model.parameters(),
        lr=0.00035 if initial_state else 0.0015,
        weight_decay=0.0005,
    )
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(optimizer, T_max=max(1, epochs))
    dataset = TensorDataset(torch.from_numpy(x.astype(np.float32)), torch.from_numpy(y))
    loader = DataLoader(dataset, batch_size=128, shuffle=True, generator=torch.Generator().manual_seed(seed))
    validation_tensor = torch.from_numpy(validation_x.astype(np.float32))
    validation_labels = torch.from_numpy(validation_y)
    best_state = None
    best_score = -float("inf")
    losses: list[float] = []
    for epoch in range(1, epochs + 1):
        model.train()
        total_loss = 0.0
        total_samples = 0
        for batch_x, batch_y in loader:
            optimizer.zero_grad(set_to_none=True)
            logits = model(batch_x)
            loss = criterion(logits, batch_y)
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 5.0)
            optimizer.step()
            total_loss += float(loss.detach()) * batch_x.shape[0]
            total_samples += batch_x.shape[0]
        scheduler.step()
        epoch_loss = total_loss / max(1, total_samples)
        losses.append(epoch_loss)
        model.eval()
        with torch.no_grad():
            validation_logits = model(validation_tensor)
            validation_probabilities = torch.softmax(validation_logits, dim=1)
            validation_prediction = torch.argmax(validation_probabilities, dim=1)
            false_accepts = int(torch.sum((validation_labels == 0) & (validation_prediction != 0)))
            strict_mask = validation_labels == CLASSES.index("strict_wake")
            address_mask = validation_labels == CLASSES.index("missed_hey_confirmation")
            strict_correct = int(torch.sum(strict_mask & (validation_prediction == validation_labels)))
            address_correct = int(torch.sum(address_mask & (validation_prediction == validation_labels)))
            strict_recall = strict_correct / max(1, int(torch.sum(strict_mask)))
            address_recall = address_correct / max(1, int(torch.sum(address_mask)))
            positive_correct = strict_correct + address_correct
            score = (strict_recall * 500) + (address_recall * 500) - (false_accepts * 20) - (epoch_loss * 0.01)
        if score > best_score:
            best_score = score
            best_state = {name: value.detach().cpu().clone() for name, value in model.state_dict().items()}
        if epoch == 1 or epoch % 10 == 0 or epoch == epochs:
            print(
                f"  CNN epoch {epoch}/{epochs}: loss={epoch_loss:.5f} "
                f"validation_positive_correct={positive_correct} false_accepts={false_accepts}",
                flush=True,
            )
    if best_state is None:
        raise RuntimeError("Temporal CNN did not produce a checkpoint.")
    model.load_state_dict(best_state)
    model.eval()
    with torch.no_grad():
        fit_probabilities = torch.softmax(model(torch.from_numpy(x.astype(np.float32))), dim=1).cpu().numpy()
        validation_probabilities = torch.softmax(model(validation_tensor), dim=1).cpu().numpy()
    exported = {name: value.numpy().astype(np.float32) for name, value in best_state.items()}
    return exported, losses, fit_probabilities, validation_probabilities


def cnn_probabilities(state: dict[str, np.ndarray], x: np.ndarray) -> np.ndarray:
    import torch
    from torch import nn
    import torch.nn.functional as functional

    value = torch.from_numpy(x.astype(np.float32)).reshape(-1, NORMALIZED_FRAMES, MEL_BANDS).transpose(1, 2)
    value = functional.conv1d(
        value,
        torch.from_numpy(state["conv1.weight"]),
        torch.from_numpy(state["conv1.bias"]),
        padding=2,
    )
    value = functional.max_pool1d(torch.relu(value), 2)
    value = functional.conv1d(
        value,
        torch.from_numpy(state["conv2.weight"]),
        torch.from_numpy(state["conv2.bias"]),
        padding=1,
    )
    value = functional.max_pool1d(torch.relu(value), 2).flatten(1)
    value = torch.relu(functional.linear(
        value,
        torch.from_numpy(state["dense1.weight"]),
        torch.from_numpy(state["dense1.bias"]),
    ))
    logits = functional.linear(
        value,
        torch.from_numpy(state["dense2.weight"]),
        torch.from_numpy(state["dense2.bias"]),
    )
    return torch.softmax(logits, dim=1).numpy()


def thresholds_for(probabilities: np.ndarray, labels: np.ndarray) -> dict[str, dict[str, float]]:
    thresholds: dict[str, dict[str, float]] = {}
    for class_name in CLASSES[1:]:
        index = CLASSES.index(class_name)
        negatives = probabilities[labels != index, index]
        positive = probabilities[labels == index, index]
        threshold = min(0.999, max(0.55, float(np.max(negatives)) + 0.025))
        thresholds[class_name] = {
            "probability": threshold,
            "validation_positive_min": float(np.min(positive)),
            "validation_negative_max": float(np.max(negatives)),
        }
    return thresholds


def evaluate(probabilities: np.ndarray, labels: np.ndarray, thresholds: dict[str, dict[str, float]]) -> dict:
    predicted = np.zeros(labels.size, dtype=np.int64)
    for row, probability in enumerate(probabilities):
        candidates = []
        for class_name in CLASSES[1:]:
            index = CLASSES.index(class_name)
            if probability[index] >= thresholds[class_name]["probability"]:
                candidates.append((float(probability[index]), index))
        if candidates:
            predicted[row] = max(candidates)[1]
    matrix = np.zeros((len(CLASSES), len(CLASSES)), dtype=np.int64)
    for actual, result in zip(labels, predicted, strict=True):
        matrix[int(actual), int(result)] += 1
    return {
        "samples": int(labels.size),
        "accuracy": float(np.mean(predicted == labels)),
        "confusion_matrix": matrix.tolist(),
        "per_class": {
            name: {
                "total": int(np.sum(labels == index)),
                "correct": int(np.sum((labels == index) & (predicted == index))),
                "false_accepts": int(np.sum((labels != index) & (predicted == index))),
            }
            for index, name in enumerate(CLASSES)
        },
    }


def rounded(values: np.ndarray, digits: int = 7) -> list:
    return np.round(values.astype(np.float64), digits).tolist()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path("web/public/voice/wake/bean-wake-model-v1.json"))
    parser.add_argument("--cache-dir", type=Path, default=Path("web/storage/app/voice-training/bean-wake-v1"))
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument("--steps", type=int, default=250)
    parser.add_argument("--epochs", type=int, default=80)
    parser.add_argument("--initial-model", type=Path)
    parser.add_argument("--architecture", choices=("temporal_cnn", "softmax"), default="temporal_cnn")
    parser.add_argument(
        "--final-fit-all",
        action="store_true",
        help="Fit the packaged candidate on every local TTS voice after split evaluation. This is not certification evidence.",
    )
    parser.add_argument("--seed", type=int, default=20260712)
    args = parser.parse_args()

    random.seed(args.seed)
    np.random.seed(args.seed)
    all_voices = FIT_VOICES + VALIDATION_VOICES + HELD_OUT_VOICES
    items = build_utterances(args.cache_dir, all_voices)
    render_all(items, max(1, min(12, args.workers)))

    by_voices = lambda names: [item for item in items if item.voice in names]
    if args.final_fit_all:
        x_fit, y_fit, held_metadata = matrix_for(items, augmentation_copies=0, seed=args.seed)
        x_validation, y_validation = x_fit.copy(), y_fit.copy()
        x_held_out, y_held_out = x_fit.copy(), y_fit.copy()
        fit_voices = all_voices
        validation_voices = all_voices
        held_out_voices: tuple[str, ...] = ()
        evidence_classification = "seen_synthetic_regression_only"
    else:
        x_fit, y_fit, _ = matrix_for(by_voices(FIT_VOICES), augmentation_copies=0, seed=args.seed)
        x_validation, y_validation, _ = matrix_for(by_voices(VALIDATION_VOICES), augmentation_copies=0, seed=args.seed + 1)
        x_held_out, y_held_out, held_metadata = matrix_for(by_voices(HELD_OUT_VOICES), augmentation_copies=0, seed=args.seed + 2)
        fit_voices = FIT_VOICES
        validation_voices = VALIDATION_VOICES
        held_out_voices = HELD_OUT_VOICES
        evidence_classification = "voice_disjoint_split_evaluation"

    initial_state = None
    if args.initial_model:
        initial_model = json.loads(args.initial_model.read_text(encoding="utf-8"))
        mean = np.asarray(initial_model["normalization"]["mean"], dtype=np.float32)
        deviation = np.asarray(initial_model["normalization"]["deviation"], dtype=np.float32)
        initial_state = {
            name: np.asarray(layer["values"], dtype=np.float32).reshape(layer["shape"])
            for name, layer in initial_model["classifier"]["layers"].items()
        }
    else:
        mean = np.mean(x_fit, axis=0).astype(np.float32)
        deviation = np.std(x_fit, axis=0).astype(np.float32)
        deviation = np.maximum(deviation, 0.08)
    x_fit = (x_fit - mean) / deviation
    x_validation = (x_validation - mean) / deviation
    x_held_out = (x_held_out - mean) / deviation

    print(f"Training on {x_fit.shape[0]} augmented samples ({x_fit.shape[1]} features)...", flush=True)
    if args.architecture == "temporal_cnn":
        state, losses, _, validation_probabilities = train_temporal_cnn(
            x_fit,
            y_fit,
            x_validation,
            y_validation,
            args.epochs,
            args.seed,
            initial_state,
        )
        held_out_probabilities = cnn_probabilities(state, x_held_out)
        classifier = {
            "architecture": "temporal_conv1d_v1",
            "layers": {
                name: {"shape": list(value.shape), "values": rounded(value)}
                for name, value in state.items()
            },
        }
    else:
        weights, bias, losses = train_softmax(x_fit, y_fit, args.steps, args.seed)
        validation_probabilities = softmax(x_validation @ weights + bias)
        held_out_probabilities = softmax(x_held_out @ weights + bias)
        classifier = {
            "architecture": "softmax_v1",
            "weights": rounded(weights),
            "bias": rounded(bias),
        }
    thresholds = thresholds_for(validation_probabilities, y_validation)
    validation = evaluate(validation_probabilities, y_validation, thresholds)
    held_out = evaluate(held_out_probabilities, y_held_out, thresholds)

    false_samples = []
    for index, probability in enumerate(held_out_probabilities):
        actual = int(y_held_out[index])
        predicted = 0
        for class_name in CLASSES[1:]:
            class_index = CLASSES.index(class_name)
            if probability[class_index] >= thresholds[class_name]["probability"] and probability[class_index] > probability[predicted]:
                predicted = class_index
        if predicted != actual:
            false_samples.append({
                **held_metadata[index],
                "predicted": CLASSES[predicted],
                "probabilities": {name: round(float(probability[position]), 6) for position, name in enumerate(CLASSES)},
            })

    model = {
        "schema_version": "1.0.0",
        "model_id": "bean-first-party-wake-v1",
        "runtime_network_required": False,
        "external_account_required": False,
        "license_key_required": False,
        "sample_rate": SAMPLE_RATE,
        "classes": list(CLASSES),
        "feature": {
            "fft_size": FFT_SIZE,
            "window_samples": WINDOW_SAMPLES,
            "hop_samples": HOP_SAMPLES,
            "mel_bands": MEL_BANDS,
            "normalized_frames": NORMALIZED_FRAMES,
            "min_hz": 80,
            "max_hz": 7_600,
        },
        "training": {
            "generator": "macos_say_and_local_numpy",
            "evidence_classification": evidence_classification,
            "final_fit_all": args.final_fit_all,
            "fit_voices": list(fit_voices),
            "validation_voices": list(validation_voices),
            "held_out_voices": list(held_out_voices),
            "rates_wpm": list(RATES),
            "seed": args.seed,
            "fine_tuned_from": str(args.initial_model) if args.initial_model else None,
            "augmented_fit_samples": int(x_fit.shape[0]),
            "validation_samples": int(x_validation.shape[0]),
            "held_out_samples": int(x_held_out.shape[0]),
            "losses": losses,
        },
        "normalization": {"mean": rounded(mean), "deviation": rounded(deviation)},
        "classifier": classifier,
        "thresholds": thresholds,
        "evaluation": {
            "validation": validation,
            "held_out": held_out,
            "held_out_failures": false_samples,
        },
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(model, separators=(",", ":")), encoding="utf-8")
    print(json.dumps({
        "output": str(args.output),
        "bytes": args.output.stat().st_size,
        "validation": validation,
        "held_out": held_out,
        "held_out_failure_count": len(false_samples),
    }, indent=2))


if __name__ == "__main__":
    main()
