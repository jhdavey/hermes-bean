#!/usr/bin/env python3
"""Train Bean's one proposal-aligned browser wake classifier.

The bundled keyword spotter proposes candidate timestamps only. This single
three-class model is the final acceptance authority for both strict Hey Bean
(including Hey beam) and missed-Hey Bean activation. The fixed run may read
only fit voices and Kathy; sealed benchmark voices have no code path here.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import platform
import random
import subprocess
import tempfile
import wave
from collections import Counter
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
TRAINING_BATCH_SIZE = 1_024
MODEL_SCHEMA_VERSION = "2.0.0"
MODEL_ID = "bean-first-party-wake-v2"
CLASSES = ("reject", "strict_wake", "missed_hey_confirmation")
ACCEPTED_CLASSES = CLASSES[1:]
MIN_ACCEPTANCE_THRESHOLD = 0.95
FIT_REQUIRED_RECALL = 0.95
FIT_REQUIRED_PROPOSAL_COVERAGE = 0.95
KATHY_REQUIRED_RECALL = 0.80
KATHY_REQUIRED_PROPOSAL_COVERAGE = 0.80
PARITY_TOLERANCE = 1e-4
PINNED_NUMPY_VERSION = "2.3.1"
PINNED_TORCH_VERSION = "2.13.0"
TORCH_INTRAOP_THREADS = 1
TORCH_INTEROP_THREADS = 1
DEFAULT_SEED = 20260712
DEFAULT_EPOCHS = 20
DEFAULT_LEARNING_RATE = 0.0015
DEFAULT_WEIGHT_DECAY = 0.0005
PROPOSAL_CONTEXT_SAMPLES = 19_200
PROPOSAL_TAIL_SAMPLES = 2_560
PROPOSAL_WINDOW_SAMPLES = PROPOSAL_CONTEXT_SAMPLES + PROPOSAL_TAIL_SAMPLES
PROPOSAL_CHUNK_SAMPLES = 1_280
NON_WAKE_SILENCE_RESET_SAMPLES = 11_200
STRICT_PROPOSAL = "strict"
ADDRESS_PROPOSAL = "address"
PROPOSAL_CACHE_SCHEMA_VERSION = 1
PROPOSAL_RUNTIME_ASSETS = (
    "kws-api.js",
    "kws-model.data",
    "kws-runtime.js",
    "kws-runtime.wasm",
)

FIT_VOICES = (
    "Albert", "Aman", "Junior", "Ralph", "Tara",
    "Samantha", "Daniel", "Karen", "Moira", "Rishi",
    "Fred", "Tessa", "Eddy (English (UK))", "Eddy (English (US))",
    "Flo (English (UK))", "Flo (English (US))",
    "Grandma (English (UK))", "Grandma (English (US))",
    "Reed (English (US))", "Rocko (English (UK))",
    "Sandy (English (US))", "Shelley (English (UK))",
    "Reed (English (UK))", "Sandy (English (UK))", "Shelley (English (US))",
)
VALIDATION_VOICES = ("Kathy",)
RATES = (145, 160, 185, 220)

STRICT_PHRASES = (
    "Hey Bean.", "Hey, Bean.", "Hey Bean!", "Hey Bean, can you hear me?",
    "Hey Bean, what time is it?", "Hey Bean, what's the weather this evening?",
    "Hey Bean, set a reminder for four p.m. today.",
    "Hey Bean, create a note called groceries.",
    "We should finish the shopping list before dinner and, hey Bean.",
    # "Hey beam" is an explicitly supported strict wake pronunciation.
    "Hey beam.", "Hey beam, can you hear me?",
)

ADDRESS_PHRASES = (
    "Bean, can you.", "Bean, could you.", "Bean, are you.",
    "Bean, check my.", "Bean, what time.", "Bean, what is today's.",
    "Bean, when is my.", "Bean, where is the.", "Bean, why is it.",
    "Bean, how do I.", "Bean, how can I.", "Bean, set a reminder for.",
    "Bean, create a note called.",
)

REJECT_PHRASES = (
    "Hey Ben.", "Hey bead.", "Hey being.", "Hey Dean.", "Hey Gene.",
    "Hey Bing.", "Hey beans.", "Hey team.", "Hey B.",
    "I told Sarah about Bean yesterday.", "Bean is a useful assistant.",
    "Bean was mentioned in the meeting.", "Green bean casserole is for dinner.",
    "They have been waiting outside.",
    "We should finish the shopping list before dinner.",
    "Can you help me with this tomorrow?", "What time is the meeting?",
    "Set a reminder for four p.m.", "Create a note called groceries.",
    "The music is quiet in the background.", "I think we should leave soon.",
    "Thanks, that is all for today.", "Maybe we can talk about it later.",
    "Please check the calendar after lunch.", "Bean.",
) + tuple(
    f"{near_match.rstrip('.!?')}, {continuation}"
    for near_match in (
        "Hey Ben.", "Hey bead.", "Hey being.", "Hey Dean.", "Hey Gene.",
        "Hey Bing.", "Hey beans.", "Hey team.", "Hey B.",
    )
    for continuation in ("can you hear me?", "what time is it?")
)


@dataclass(frozen=True)
class Utterance:
    voice: str
    rate: int
    phrase: str
    label: str
    path: Path


@dataclass(frozen=True)
class AcousticVariant:
    samples: np.ndarray
    view: str


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
        if (handle.getnchannels(), handle.getsampwidth(), handle.getframerate()) != (
            1, 2, SAMPLE_RATE,
        ):
            raise RuntimeError(f"Unexpected WAVE format: {path}")
        data = handle.readframes(handle.getnframes())
    return np.frombuffer(data, dtype="<i2").astype(np.float32) / 32768.0


def speech_onset(samples: np.ndarray) -> int:
    """Return the production-aligned first active 20 ms block."""
    value = np.asarray(samples, dtype=np.float32)
    frame = 320
    rms = np.asarray([
        math.sqrt(float(np.mean(np.square(value[start:start + frame]))))
        for start in range(0, value.size, frame)
    ])
    active = np.flatnonzero(rms >= 0.008)
    return int(active[0]) * frame if active.size else int(value.size)


def production_reset_boundary(samples: np.ndarray) -> int:
    """Return the exact fixed-batch source boundary where production resets."""
    value = np.asarray(samples, dtype=np.float32)
    onset = speech_onset(value)
    if onset >= value.size:
        raise RuntimeError("Rendered training audio contains no active speech.")
    boundary = (onset // PROPOSAL_CHUNK_SAMPLES + 1) * PROPOSAL_CHUNK_SAMPLES
    silent_samples = 0
    while True:
        start = boundary - PROPOSAL_CHUNK_SAMPLES
        chunk = np.zeros(PROPOSAL_CHUNK_SAMPLES, dtype=np.float32)
        overlap_start = max(0, start)
        overlap_end = min(value.size, boundary)
        if overlap_end > overlap_start:
            chunk[overlap_start - start:overlap_end - start] = value[
                overlap_start:overlap_end
            ]
        active = False
        for frame_start in range(0, chunk.size, 320):
            frame = chunk[frame_start:frame_start + 320]
            rms = math.sqrt(float(np.mean(np.square(frame))))
            if rms >= 0.008:
                active = True
                break
        silent_samples = 0 if active else silent_samples + PROPOSAL_CHUNK_SAMPLES
        if silent_samples >= NON_WAKE_SILENCE_RESET_SAMPLES:
            return boundary
        boundary += PROPOSAL_CHUNK_SAMPLES


def proposal_aligned_window(samples: np.ndarray, candidate_end_sample: int) -> np.ndarray:
    """Extract the sole raw classifier window, zero-padding either boundary."""
    value = np.asarray(samples, dtype=np.float32)
    end = int(candidate_end_sample) + PROPOSAL_TAIL_SAMPLES
    start = int(candidate_end_sample) - PROPOSAL_CONTEXT_SAMPLES
    result = np.zeros(PROPOSAL_WINDOW_SAMPLES, dtype=np.float32)
    source_start = max(0, start)
    source_end = min(value.size, end)
    if source_end > source_start:
        destination_start = source_start - start
        result[destination_start:destination_start + source_end - source_start] = (
            value[source_start:source_end]
        )
    return result


def feature_vector(samples: np.ndarray) -> np.ndarray:
    value = np.asarray(samples, dtype=np.float32)
    if value.shape != (PROPOSAL_WINDOW_SAMPLES,):
        raise RuntimeError(
            f"Classifier input must contain exactly {PROPOSAL_WINDOW_SAMPLES} samples."
        )
    frame_count = 1 + max(0, (value.size - WINDOW_SAMPLES) // HOP_SAMPLES)
    frames = np.empty((frame_count, WINDOW_SAMPLES), dtype=np.float32)
    for index in range(frame_count):
        start = index * HOP_SAMPLES
        frames[index] = value[start:start + WINDOW_SAMPLES] * HANN_WINDOW
    spectrum = np.fft.rfft(frames, n=FFT_SIZE, axis=1)
    power = np.square(np.abs(spectrum)).astype(np.float32)
    mel = np.log1p(np.maximum(power @ MEL_FILTERS.T, 0.0))
    positions = np.linspace(0.0, max(0, frame_count - 1), NORMALIZED_FRAMES)
    left = np.floor(positions).astype(int)
    right = np.minimum(left + 1, frame_count - 1)
    fraction = (positions - left).astype(np.float32)[:, None]
    normalized = mel[left] * (1.0 - fraction) + mel[right] * fraction
    normalized -= np.mean(normalized)
    normalized /= max(float(np.std(normalized)), 0.12)
    return normalized.astype(np.float32).reshape(FEATURE_SIZE)


def slug(value: str) -> str:
    return "-".join(
        "".join(character.lower() if character.isalnum() else " " for character in value).split()
    )


def utterance_path(cache: Path, voice: str, rate: int, label: str, phrase: str) -> Path:
    digest = hashlib.sha256(phrase.encode()).hexdigest()[:10]
    return cache / f"{slug(voice)}-{rate}-{slug(label)}-{digest}.wav"


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


def render_utterance(item: Utterance) -> Utterance:
    if item.path.exists() and item.path.stat().st_size > 100:
        return item
    item.path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="bean-wake-tts-") as directory:
        aiff = Path(directory) / "speech.aiff"
        wav = Path(directory) / "speech.wav"
        subprocess.run(
            ["/usr/bin/say", "-v", item.voice, "-r", str(item.rate), "-o", str(aiff), item.phrase],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        subprocess.run(
            ["/usr/bin/afconvert", "-f", "WAVE", "-d", f"LEI16@{SAMPLE_RATE}",
             "-c", "1", str(aiff), str(wav)],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        os.replace(wav, item.path)
    return item


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


def assert_no_conflicting_acoustic_labels(items: list[Utterance]) -> None:
    first_by_hash: dict[str, Utterance] = {}
    for item in items:
        digest = hashlib.sha256(item.path.read_bytes()).hexdigest()
        first = first_by_hash.get(digest)
        if first is not None and first.label != item.label:
            raise RuntimeError(
                "Acoustically identical wake inputs cannot have conflicting labels: "
                f"{first.phrase!r} and {item.phrase!r}."
            )
        first_by_hash[digest] = item


PROPOSAL_HARVEST_SOURCE = r"""
const fs = require('node:fs');
const path = require('node:path');

function loadScript(filename, exportedName) {
  const source = fs.readFileSync(filename, 'utf8');
  const module = {exports: {}};
  return new Function(
      'module', 'exports', 'require', '__dirname', '__filename',
      `${source}\n;return typeof ${exportedName} === 'undefined' ? ` +
      `module.exports : ${exportedName};`)(
          module, module.exports, require, path.dirname(filename), filename);
}

function readWave(filename) {
  const buffer = fs.readFileSync(filename);
  if (buffer.toString('ascii', 0, 4) !== 'RIFF') throw new Error('not RIFF: ' + filename);
  let offset = 12;
  let channels = 0;
  let sampleRate = 0;
  let bits = 0;
  let data = null;
  while (offset + 8 <= buffer.length) {
    const name = buffer.toString('ascii', offset, offset + 4);
    const size = buffer.readUInt32LE(offset + 4);
    if (name === 'fmt ') {
      channels = buffer.readUInt16LE(offset + 10);
      sampleRate = buffer.readUInt32LE(offset + 12);
      bits = buffer.readUInt16LE(offset + 22);
    } else if (name === 'data') {
      data = buffer.subarray(offset + 8, offset + 8 + size);
    }
    offset += 8 + size + (size % 2);
  }
  if (channels !== 1 || sampleRate !== 16000 || bits !== 16 || data === null) {
    throw new Error('unexpected WAVE format: ' + filename);
  }
  const samples = new Float32Array(data.length / 2);
  for (let index = 0; index < samples.length; ++index) {
    samples[index] = data.readInt16LE(index * 2) / 32768;
  }
  return samples;
}

function onset(samples) {
  for (let start = 0; start < samples.length; start += 320) {
    let power = 0;
    const end = Math.min(samples.length, start + 320);
    for (let index = start; index < end; ++index) power += samples[index] * samples[index];
    if (Math.sqrt(power / Math.max(1, end - start)) >= 0.008) return start;
  }
  return samples.length;
}

function hasActivity(samples) {
  for (let start = 0; start < samples.length; start += 320) {
    let power = 0;
    const end = Math.min(samples.length, start + 320);
    for (let index = start; index < end; ++index) power += samples[index] * samples[index];
    if (Math.sqrt(power / Math.max(1, end - start)) >= 0.008) return true;
  }
  return false;
}

function timelineSlice(samples, start, end) {
  const value = new Float32Array(Math.max(0, end - start));
  const overlapStart = Math.max(0, start);
  const overlapEnd = Math.min(samples.length, end);
  if (overlapEnd > overlapStart) {
    value.set(samples.subarray(overlapStart, overlapEnd), overlapStart - start);
  }
  return value;
}

function decodeReady(kws, stream) {
  while (kws.isReady(stream)) kws.decode(stream);
}

function detection(result, streamStartSample, emittedAtSample) {
  if (!result.keyword || !result.timestamps || !result.timestamps.length) return null;
  const timestamp = Number(result.timestamps[result.timestamps.length - 1]);
  if (!Number.isFinite(timestamp)) return null;
  return {
    keyword: String(result.keyword),
    candidate_end_sample: Math.max(
        0, Math.round(timestamp * 16000) + streamStartSample),
    emitted_at_sample: emittedAtSample,
  };
}

function strictDetection(result, streamStartSample, emittedAtSample) {
  const value = detection(result, streamStartSample, emittedAtSample);
  if (value !== null && value.keyword !== 'HEY_BEAN') {
    throw new Error('strict stream returned an unexpected alias');
  }
  return value;
}

function addressDetection(result, streamStartSample, emittedAtSample) {
  const value = detection(result, streamStartSample, emittedAtSample);
  if (value === null || value.keyword === 'BEAN') return value;
  if (value.keyword === 'HEY_BEAN') return null;
  throw new Error('address stream returned an unexpected alias');
}

function decodePair(kws, strictKeywords, addressKeywords, samples) {
  const speechStart = onset(samples);
  const strictStart = speechStart;
  const addressStart = Math.max(0, speechStart - 1600);
  const strictStreamStart = strictStart - 5120;
  const strictStream = kws.createStream();
  const addressStream = kws.createStream(addressKeywords);
  strictStream.acceptWaveform(16000, new Float32Array(5120));
  let strictCursor = strictStart;
  let addressCursor = addressStart;
  let strict = null;
  let address = null;
  let silentSamplesAfterSpeech = 0;
  let resetAtSample = null;
  const firstBoundary = Math.floor(speechStart / 1280) * 1280 + 1280;
  const maximumBoundary = Math.ceil((samples.length + 12480) / 1280) * 1280;
  for (let boundary = firstBoundary; boundary <= maximumBoundary; boundary += 1280) {
    if (boundary > strictCursor) {
      strictStream.acceptWaveform(
          16000, timelineSlice(samples, strictCursor, boundary));
      strictCursor = boundary;
    }
    if (boundary > addressCursor) {
      addressStream.acceptWaveform(
          16000, timelineSlice(samples, addressCursor, boundary));
      addressCursor = boundary;
    }
    decodeReady(kws, strictStream);
    decodeReady(kws, addressStream);
    if (strict === null) {
      strict = strictDetection(
          kws.getResult(strictStream), strictStreamStart, boundary);
    }
    if (address === null) {
      address = addressDetection(
          kws.getResult(addressStream), addressStart, boundary);
    }
    const activity = timelineSlice(samples, boundary - 1280, boundary);
    if (hasActivity(activity)) {
      silentSamplesAfterSpeech = 0;
    } else {
      silentSamplesAfterSpeech += 1280;
    }
    if (silentSamplesAfterSpeech >= 11200) {
      resetAtSample = boundary;
      break;
    }
  }
  if (resetAtSample === null) throw new Error('activity reset boundary was not reached');
  strictStream.free();
  addressStream.free();
  return {strict, address, reset_at_sample: resetAtSample};
}

(async () => {
  const payload = JSON.parse(fs.readFileSync(0, 'utf8'));
  const directory = path.resolve(payload.runtime_dir);
  const packed = fs.readFileSync(path.join(directory, 'kws-model.data'));
  const createModule = loadScript(path.join(directory, 'kws-runtime.js'), 'createSherpaKwsModule');
  const createKws = loadScript(path.join(directory, 'kws-api.js'), 'createKws');
  const Module = await createModule({
    wasmBinary: fs.readFileSync(path.join(directory, 'kws-runtime.wasm')),
    getPreloadedPackage() {
      return packed.buffer.slice(packed.byteOffset, packed.byteOffset + packed.byteLength);
    },
    print() {},
    printErr() {},
  });
  const strictKeywords = [
    'HH EY1 B IY1 N :3.0 #0.01 @HEY_BEAN',
    'HH EY1 B IY1 M :3.0 #0.01 @HEY_BEAN',
  ].join('\n');
  const addressKeywords = 'B IY1 N :3.0 #0.01 @BEAN';
  const kws = createKws(Module, {
    featConfig: {samplingRate: 16000, featureDim: 80},
    modelConfig: {
      transducer: {encoder: './encoder.onnx', decoder: './decoder.onnx', joiner: './joiner.onnx'},
      tokens: './tokens.txt', provider: 'cpu', modelType: '', numThreads: 1, debug: 0,
      modelingUnit: 'phone+ppinyin', bpeVocab: '',
    },
    maxActivePaths: 4,
    numTrailingBlanks: 0,
    keywordsScore: 1,
    keywordsThreshold: 0.1,
    keywords: '',
    keywordsBuf: strictKeywords,
    keywordsBufSize: Module.lengthBytesUTF8(strictKeywords),
  });
  const warmStreams = [kws.createStream(), kws.createStream(addressKeywords)];
  for (const stream of warmStreams) {
    stream.acceptWaveform(16000, new Float32Array(6400));
  }
  for (const stream of warmStreams) decodeReady(kws, stream);
  for (const stream of warmStreams) {
    kws.getResult(stream);
    stream.free();
  }
  const output = [];
  for (const filename of payload.paths) {
    const samples = readWave(filename);
    output.push(decodePair(kws, strictKeywords, addressKeywords, samples));
  }
  kws.free();
  process.stdout.write(JSON.stringify(output));
})().catch((error) => {
  process.stderr.write(String(error && error.stack ? error.stack : error));
  process.exit(1);
});
"""


def coalesced_proposal_detections(
    strict: dict | None, address: dict | None, reset_at_sample: int | None = None,
) -> dict | None:
    """Apply the production candidate+160 ms promotion boundary exactly once."""
    for detection, keywords in (
        (strict, {"HEY_BEAN"}),
        # Sherpa custom streams may expose an inherited base-graph result.
        # Only BEAN from this stream is address evidence; HEY_BEAN remains
        # owned exclusively by the strict stream and is ignored here.
        (address, {"BEAN", "HEY_BEAN"}),
    ):
        if detection is None:
            continue
        if not isinstance(detection, dict) or detection.get("keyword") not in keywords:
            raise RuntimeError("Proposal harvester returned an invalid keyword.")
        for field in ("candidate_end_sample", "emitted_at_sample"):
            if not isinstance(detection.get(field), int) or detection[field] < 0:
                raise RuntimeError("Proposal harvester returned an invalid timing boundary.")
        if detection["emitted_at_sample"] % PROPOSAL_CHUNK_SAMPLES != 0:
            raise RuntimeError("Proposal emission did not occur on a production boundary.")

    if address is not None and address["keyword"] == "HEY_BEAN":
        address = None
    if reset_at_sample is not None:
        if (not isinstance(reset_at_sample, int) or reset_at_sample < 0
                or reset_at_sample % PROPOSAL_CHUNK_SAMPLES != 0):
            raise RuntimeError("Proposal harvester returned an invalid reset boundary.")

    if address is None:
        chosen = strict
        proposal_type = STRICT_PROPOSAL
    else:
        tail_end = address["candidate_end_sample"] + PROPOSAL_TAIL_SAMPLES
        tail_boundary = (
            (tail_end + PROPOSAL_CHUNK_SAMPLES - 1) // PROPOSAL_CHUNK_SAMPLES
        ) * PROPOSAL_CHUNK_SAMPLES
        # Production keeps an address provisional until at least the following
        # message. Both KWS streams are queried before finalization, so a strict
        # result on the first boundary at/after the exact tail still promotes.
        address_boundary = max(
            address["emitted_at_sample"] + PROPOSAL_CHUNK_SAMPLES,
            tail_boundary,
        )
        if strict is not None and strict["emitted_at_sample"] <= address_boundary:
            chosen = strict
            proposal_type = STRICT_PROPOSAL
        else:
            chosen = address
            proposal_type = ADDRESS_PROPOSAL
    if chosen is None:
        return None
    tail_end = chosen["candidate_end_sample"] + PROPOSAL_TAIL_SAMPLES
    tail_boundary = (
        (tail_end + PROPOSAL_CHUNK_SAMPLES - 1) // PROPOSAL_CHUNK_SAMPLES
    ) * PROPOSAL_CHUNK_SAMPLES
    classification_boundary = max(chosen["emitted_at_sample"], tail_boundary)
    if proposal_type == ADDRESS_PROPOSAL:
        classification_boundary = max(
            classification_boundary,
            chosen["emitted_at_sample"] + PROPOSAL_CHUNK_SAMPLES,
        )
    if reset_at_sample is not None and classification_boundary > reset_at_sample:
        return None
    return {
        **chosen,
        "proposal_type": proposal_type,
        "classification_boundary_sample": classification_boundary,
    }


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def proposal_harvest_cache_key(
    items: list[Utterance], runtime_dir: Path,
    harvester_source: str = PROPOSAL_HARVEST_SOURCE,
) -> str:
    corpus = []
    for item in items:
        resolved = item.path.resolve()
        stat = resolved.stat()
        corpus.append({
            "path": str(resolved),
            "size": stat.st_size,
            "mtime_ns": stat.st_mtime_ns,
            "voice": item.voice,
            "rate": item.rate,
            "phrase": item.phrase,
            "label": item.label,
        })
    runtime = []
    for name in PROPOSAL_RUNTIME_ASSETS:
        path = (runtime_dir / name).resolve()
        runtime.append({
            "path": str(path),
            "size": path.stat().st_size,
            "sha256": sha256_file(path),
        })
    payload = {
        "schema_version": PROPOSAL_CACHE_SCHEMA_VERSION,
        "corpus": corpus,
        "harvester_source": harvester_source,
        "runtime_assets": runtime,
    }
    encoded = json.dumps(
        payload, sort_keys=True, separators=(",", ":"), allow_nan=False,
    ).encode()
    return hashlib.sha256(encoded).hexdigest()


def load_cached_proposal_detections(
    path: Path, cache_key: str, expected_count: int,
) -> list | None:
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError, UnicodeError):
        return None
    if not isinstance(payload, dict) or set(payload) != {
        "schema_version", "cache_key", "detections",
    }:
        return None
    detections = payload.get("detections")
    if (payload.get("schema_version") != PROPOSAL_CACHE_SCHEMA_VERSION
            or payload.get("cache_key") != cache_key
            or not isinstance(detections, list)
            or len(detections) != expected_count):
        return None
    return detections


def store_cached_proposal_detections(
    path: Path, cache_key: str, detections: list,
) -> None:
    payload = json.dumps({
        "schema_version": PROPOSAL_CACHE_SCHEMA_VERSION,
        "cache_key": cache_key,
        "detections": detections,
    }, separators=(",", ":"), allow_nan=False)
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary: Path | None = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", encoding="utf-8", dir=path.parent,
            prefix=path.name + ".", suffix=".tmp", delete=False,
        ) as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
            temporary = Path(handle.name)
        os.replace(temporary, path)
    finally:
        if temporary is not None and temporary.exists():
            temporary.unlink()


def harvest_proposals(
    items: list[Utterance], runtime_dir: Path, cache_dir: Path,
) -> list[dict | None]:
    cache_key = proposal_harvest_cache_key(items, runtime_dir)
    cache_path = cache_dir / "proposal-harvest" / f"{cache_key}.json"
    detections = load_cached_proposal_detections(
        cache_path, cache_key, len(items),
    )
    if detections is not None:
        print("WAKE_PROPOSAL_CACHE_HIT " + json.dumps({
            "cache_key": cache_key,
            "path": str(cache_path),
            "utterances": len(items),
        }, sort_keys=True), flush=True)
    else:
        detections = run_proposal_harvester(items, runtime_dir)
        store_cached_proposal_detections(cache_path, cache_key, detections)
        print("WAKE_PROPOSAL_CACHE_WRITE " + json.dumps({
            "cache_key": cache_key,
            "path": str(cache_path),
            "utterances": len(items),
        }, sort_keys=True), flush=True)

    proposals = []
    for item, pair in zip(items, detections, strict=True):
        if not isinstance(pair, dict) or set(pair) != {
            "strict", "address", "reset_at_sample",
        }:
            raise RuntimeError("Proposal harvester returned an invalid detection pair.")
        expected_reset = production_reset_boundary(read_wave(item.path))
        if pair["reset_at_sample"] != expected_reset:
            raise RuntimeError(
                "Proposal harvester diverged from the production activity reset boundary."
            )
        proposals.append(coalesced_proposal_detections(
            pair["strict"], pair["address"], pair["reset_at_sample"],
        ))
    for proposal in proposals:
        if proposal is None:
            continue
        if proposal.get("proposal_type") not in (STRICT_PROPOSAL, ADDRESS_PROPOSAL):
            raise RuntimeError("Proposal harvester returned an invalid type.")
        if not isinstance(proposal.get("candidate_end_sample"), int):
            raise RuntimeError("Proposal harvester returned an invalid timestamp.")
    return proposals


def run_proposal_harvester(items: list[Utterance], runtime_dir: Path) -> list:
    payload = {
        "runtime_dir": str(runtime_dir.resolve()),
        "paths": [str(item.path.resolve()) for item in items],
    }
    result = subprocess.run(
        ["node", "-e", PROPOSAL_HARVEST_SOURCE],
        input=json.dumps(payload, separators=(",", ":"), allow_nan=False),
        text=True,
        capture_output=True,
        check=True,
    )
    try:
        detections = json.loads(result.stdout)
    except json.JSONDecodeError as error:
        raise RuntimeError("Proposal harvester returned invalid JSON.") from error
    if not isinstance(detections, list) or len(detections) != len(items):
        raise RuntimeError("Proposal harvester returned the wrong number of results.")
    return detections


def expected_proposal_type(label: str) -> str | None:
    return {
        "strict_wake": STRICT_PROPOSAL,
        "missed_hey_confirmation": ADDRESS_PROPOSAL,
        "reject": None,
    }[label]


def proposal_coverage(items: list[Utterance], proposals: list[dict | None]) -> dict:
    result = {}
    for label in CLASSES[1:]:
        rows = [(item, proposal) for item, proposal in zip(items, proposals, strict=True)
                if item.label == label]
        expected = expected_proposal_type(label)
        compatible = sum(
            proposal is not None and proposal["proposal_type"] == expected
            for _, proposal in rows
        )
        result[label] = {
            "utterances": len(rows),
            "compatible_proposals": int(compatible),
            "coverage": compatible / max(1, len(rows)),
        }
    return result


def acoustic_variants(window: np.ndarray, rng: np.random.Generator) -> list[AcousticVariant]:
    value = np.asarray(window, dtype=np.float32)
    if value.shape != (PROPOSAL_WINDOW_SAMPLES,):
        raise RuntimeError("Acoustic variants require one proposal-aligned window.")
    variants: list[AcousticVariant] = []
    add = lambda candidate, view: variants.append(
        AcousticVariant(np.asarray(candidate, dtype=np.float32), view)
    )
    add(value, "raw")
    signal_rms = max(0.01, math.sqrt(float(np.mean(np.square(value))) + 1e-12))
    noise = rng.normal(0.0, signal_rms * 0.025, value.shape).astype(np.float32)
    add(np.clip(value + noise, -1.0, 1.0), "room_noise")
    echo = value.copy()
    delay = int(0.075 * SAMPLE_RATE)
    echo[delay:] += value[:-delay] * 0.08
    add(np.clip(echo, -1.0, 1.0), "room_echo")
    time = np.arange(value.size, dtype=np.float32) / SAMPLE_RATE
    music = (
        np.sin(2 * np.pi * 196.0 * time)
        + 0.55 * np.sin(2 * np.pi * 293.66 * time + 0.4)
        + 0.35 * np.sin(2 * np.pi * 392.0 * time + 1.1)
    ).astype(np.float32)
    add(np.clip(value + music * signal_rms * 0.02, -1.0, 1.0), "quiet_music")
    return variants


def matrix_for(
    items: list[Utterance], proposals: list[dict | None], seed: int,
) -> tuple[np.ndarray, np.ndarray, list[dict]]:
    rng = np.random.default_rng(seed)
    vectors: list[np.ndarray] = []
    labels: list[int] = []
    metadata: list[dict] = []
    raw_windows: list[np.ndarray] = []
    raw_targets: list[int] = []
    raw_metadata: list[dict] = []
    for item, proposal in zip(items, proposals, strict=True):
        if proposal is None:
            continue
        expected = expected_proposal_type(item.label)
        if expected is not None and proposal["proposal_type"] != expected:
            continue
        target = CLASSES.index(item.label)
        window = proposal_aligned_window(
            read_wave(item.path), proposal["candidate_end_sample"],
        )
        raw_windows.append(window)
        raw_targets.append(target)
        raw_metadata.append({"phrase": item.phrase, "label": item.label})
        for variant_index, variant in enumerate(acoustic_variants(window, rng)):
            vectors.append(feature_vector(variant.samples))
            labels.append(target)
            metadata.append({
                "voice": item.voice,
                "rate": item.rate,
                "phrase": item.phrase,
                "label": item.label,
                "training_view": variant.view,
                "acoustic_variant": variant_index,
                "proposal_type": proposal["proposal_type"],
            })
    if not vectors:
        raise RuntimeError("Proposal harvesting produced no classifier rows.")
    assert_no_conflicting_windows(
        np.stack(raw_windows), np.asarray(raw_targets, dtype=np.int64), raw_metadata,
    )
    matrix = np.stack(vectors).astype(np.float32, copy=False)
    targets = np.asarray(labels, dtype=np.int64)
    assert_no_conflicting_features(matrix, targets, metadata)
    return matrix, targets, metadata


def assert_no_conflicting_windows(
    values: np.ndarray, targets: np.ndarray, metadata: list[dict],
) -> None:
    first_by_hash: dict[bytes, int] = {}
    for index, value in enumerate(np.asarray(values, dtype=np.float32)):
        digest = hashlib.sha256(np.ascontiguousarray(value).tobytes()).digest()
        first = first_by_hash.get(digest)
        if first is not None and targets[first] != targets[index] and np.array_equal(values[first], value):
            raise RuntimeError(
                "Proposal-aligned windows have conflicting targets: "
                f"{metadata[first]['phrase']!r} and {metadata[index]['phrase']!r}."
            )
        first_by_hash.setdefault(digest, index)


def assert_no_conflicting_features(values: np.ndarray, targets: np.ndarray, metadata: list[dict]) -> None:
    first_by_hash: dict[bytes, int] = {}
    for index, vector in enumerate(np.asarray(values, dtype=np.float32)):
        digest = hashlib.sha256(np.ascontiguousarray(vector).tobytes()).digest()
        first = first_by_hash.get(digest)
        if first is not None and targets[first] != targets[index] and np.array_equal(values[first], vector):
            raise RuntimeError(
                "Wake feature vectors have conflicting targets: "
                f"{metadata[first]['phrase']!r} and {metadata[index]['phrase']!r}."
            )
        first_by_hash.setdefault(digest, index)


def group_balanced_sample_weights(targets: np.ndarray, metadata: list[dict]) -> np.ndarray:
    keys = [
        (item["voice"], int(item["rate"]), item["phrase"], int(targets[index]))
        for index, item in enumerate(metadata)
    ]
    samples_per_group = Counter(keys)
    groups_per_class = Counter(key[-1] for key in samples_per_group)
    weights = np.asarray([
        1.0 / (samples_per_group[key] * max(1, groups_per_class[key[-1]]))
        for key in keys
    ], dtype=np.float32)
    return weights / max(float(np.mean(weights)), 1e-12)


def normalize_features(values: np.ndarray, mean: np.ndarray, deviation: np.ndarray) -> np.ndarray:
    matrix = np.asarray(values, dtype=np.float32)
    center = np.asarray(mean, dtype=np.float32)
    scale = np.asarray(deviation, dtype=np.float32)
    if matrix.ndim != 2 or matrix.shape[1] != FEATURE_SIZE:
        raise RuntimeError("Wake feature matrix has an invalid shape.")
    if center.shape != (FEATURE_SIZE,) or scale.shape != (FEATURE_SIZE,):
        raise RuntimeError("Wake normalization has an invalid shape.")
    if not np.all(np.isfinite(center)) or not np.all(np.isfinite(scale)) or np.any(scale <= 0):
        raise RuntimeError("Wake normalization must be finite and positive.")
    return ((matrix - center) / scale).astype(np.float32)


EXPECTED_LAYER_SHAPES = {
    "conv1.weight": (32, 32, 5), "conv1.bias": (32,),
    "conv2.weight": (48, 32, 3), "conv2.bias": (48,),
    "dense1.weight": (64, 576), "dense1.bias": (64,),
    "dense2.weight": (3, 64), "dense2.bias": (3,),
}


def rounded(values: np.ndarray) -> list:
    return np.round(np.asarray(values, dtype=np.float64), 7).tolist()


def production_roundtrip(values: np.ndarray) -> np.ndarray:
    payload = json.dumps(rounded(values), separators=(",", ":"), allow_nan=False)
    return np.asarray(json.loads(payload), dtype=np.float32)


def validate_thresholds(thresholds: dict[str, float]) -> dict[str, float]:
    if not isinstance(thresholds, dict) or set(thresholds) != set(ACCEPTED_CLASSES):
        raise RuntimeError("Wake thresholds have an invalid class inventory.")
    validated: dict[str, float] = {}
    for class_name in ACCEPTED_CLASSES:
        value = thresholds[class_name]
        if (isinstance(value, bool) or not isinstance(value, (int, float))
                or not math.isfinite(value)
                or value < MIN_ACCEPTANCE_THRESHOLD or value > 1.0):
            raise RuntimeError(f"Wake threshold {class_name!r} is invalid.")
        validated[class_name] = float(value)
    return validated


def minimum_serialized_threshold(required: np.float32) -> float:
    value = float(np.float32(required))
    if not math.isfinite(value) or value > 1.0:
        raise RuntimeError(
            "No finite seven-decimal wake threshold at or below 1 can reject all rows."
        )
    units = max(
        int(math.ceil(MIN_ACCEPTANCE_THRESHOLD * 10_000_000)),
        int(math.ceil(value * 10_000_000)),
    )
    while units <= 10_000_000:
        artifact_value = float(f"{units / 10_000_000:.7f}")
        if artifact_value >= value:
            return artifact_value
        units += 1
    raise RuntimeError(
        "No finite seven-decimal wake threshold at or below 1 can reject all rows."
    )


def compatible_winning_reject_probabilities(
    probabilities: np.ndarray, targets: np.ndarray, metadata: list[dict],
    class_name: str,
) -> np.ndarray:
    values = np.asarray(probabilities, dtype=np.float32)
    labels = np.asarray(targets, dtype=np.int64)
    if (values.shape != (labels.size, len(CLASSES))
            or len(metadata) != labels.size
            or class_name not in ACCEPTED_CLASSES):
        raise RuntimeError("Wake calibration received incompatible arrays.")
    class_index = CLASSES.index(class_name)
    proposal_type = STRICT_PROPOSAL if class_name == "strict_wake" else ADDRESS_PROPOSAL
    winning = np.argmax(values, axis=1)
    mask = np.asarray([
        labels[index] == 0
        and item["proposal_type"] == proposal_type
        and winning[index] == class_index
        for index, item in enumerate(metadata)
    ], dtype=bool)
    return values[mask, class_index]


def required_threshold_above(probabilities: np.ndarray) -> np.float32:
    values = np.asarray(probabilities, dtype=np.float32)
    if values.size == 0 or float(np.max(values)) < MIN_ACCEPTANCE_THRESHOLD:
        return np.float32(MIN_ACCEPTANCE_THRESHOLD)
    maximum = np.max(values).astype(np.float32)
    return np.nextafter(maximum, np.float32(np.inf))


def calibrate_acceptance_thresholds(
    fit_probabilities: np.ndarray,
    fit_targets: np.ndarray,
    fit_metadata: list[dict],
    kathy_probabilities: np.ndarray,
    kathy_targets: np.ndarray,
    kathy_metadata: list[dict],
) -> tuple[dict[str, float], dict]:
    thresholds: dict[str, float] = {}
    evidence = {}
    for class_name in ACCEPTED_CLASSES:
        fit_reject = compatible_winning_reject_probabilities(
            fit_probabilities, fit_targets, fit_metadata, class_name,
        )
        kathy_reject = compatible_winning_reject_probabilities(
            kathy_probabilities, kathy_targets, kathy_metadata, class_name,
        )
        fit_required = required_threshold_above(fit_reject)
        fit_serialized = minimum_serialized_threshold(fit_required)
        combined = np.concatenate((fit_reject, kathy_reject))
        safe_required = required_threshold_above(combined)
        safe_serialized = minimum_serialized_threshold(safe_required)
        thresholds[class_name] = safe_serialized
        evidence[class_name] = {
            "fit_compatible_winning_reject_rows": int(fit_reject.size),
            "fit_max_compatible_winning_probability": (
                float(np.max(fit_reject)) if fit_reject.size else None
            ),
            "fit_required_next_float32": float(fit_required),
            "fit_serialized_threshold": fit_serialized,
            "kathy_compatible_winning_reject_rows": int(kathy_reject.size),
            "combined_max_compatible_winning_probability": (
                float(np.max(combined)) if combined.size else None
            ),
            "artifact_threshold": safe_serialized,
            "kathy_safety_escalation": safe_serialized > fit_serialized,
        }
    return validate_thresholds(thresholds), evidence


def validate_checkpoint_state(state: dict[str, np.ndarray]) -> None:
    if set(state) != set(EXPECTED_LAYER_SHAPES):
        raise RuntimeError("Wake checkpoint has an invalid layer inventory.")
    for name, shape in EXPECTED_LAYER_SHAPES.items():
        value = np.asarray(state[name], dtype=np.float32)
        if value.shape != shape or not np.all(np.isfinite(value)):
            raise RuntimeError(f"Wake checkpoint layer {name!r} is invalid.")


def artifact_from_state(
    mean: np.ndarray, deviation: np.ndarray, state: dict[str, np.ndarray],
    thresholds: dict[str, float],
) -> dict:
    validate_checkpoint_state(state)
    accepted_thresholds = validate_thresholds(thresholds)
    normalize_features(np.zeros((0, FEATURE_SIZE), dtype=np.float32), mean, deviation)
    return {
        "schema_version": MODEL_SCHEMA_VERSION,
        "model_id": MODEL_ID,
        "runtime_network_required": False,
        "external_account_required": False,
        "license_key_required": False,
        "sample_rate": SAMPLE_RATE,
        "classes": list(CLASSES),
        "feature": {
            "fft_size": FFT_SIZE, "window_samples": WINDOW_SAMPLES,
            "hop_samples": HOP_SAMPLES, "mel_bands": MEL_BANDS,
            "normalized_frames": NORMALIZED_FRAMES,
        },
        "proposal_window": {
            "alignment": "proposal_end",
            "context_samples": PROPOSAL_CONTEXT_SAMPLES,
            "tail_samples": PROPOSAL_TAIL_SAMPLES,
            "total_samples": PROPOSAL_WINDOW_SAMPLES,
        },
        "normalization": {"mean": rounded(mean), "deviation": rounded(deviation)},
        "classifier": {
            "architecture": "temporal_conv1d_v1",
            "layers": {
                name: {"shape": list(EXPECTED_LAYER_SHAPES[name]), "values": rounded(state[name])}
                for name in EXPECTED_LAYER_SHAPES
            },
        },
        "thresholds": {
            class_name: {"probability": accepted_thresholds[class_name]}
            for class_name in ACCEPTED_CLASSES
        },
    }


def serialize_artifact(artifact: dict) -> tuple[str, dict]:
    serialized = json.dumps(artifact, separators=(",", ":"), allow_nan=False)
    parsed = json.loads(serialized)
    load_artifact(parsed)
    return serialized, parsed


def load_artifact(
    artifact: dict,
) -> tuple[np.ndarray, np.ndarray, dict[str, np.ndarray], dict[str, float]]:
    if artifact.get("schema_version") != MODEL_SCHEMA_VERSION or artifact.get("model_id") != MODEL_ID:
        raise RuntimeError("Wake artifact identity is invalid.")
    if artifact.get("classes") != list(CLASSES):
        raise RuntimeError("Wake artifact classes are invalid.")
    if any(artifact.get(key) is not False for key in (
        "runtime_network_required", "external_account_required", "license_key_required",
    )) or artifact.get("sample_rate") != SAMPLE_RATE:
        raise RuntimeError("Wake artifact runtime contract is invalid.")
    expected_feature = {
        "fft_size": FFT_SIZE, "window_samples": WINDOW_SAMPLES,
        "hop_samples": HOP_SAMPLES, "mel_bands": MEL_BANDS,
        "normalized_frames": NORMALIZED_FRAMES,
    }
    if artifact.get("feature") != expected_feature:
        raise RuntimeError("Wake artifact feature contract is invalid.")
    expected_window = {
        "alignment": "proposal_end",
        "context_samples": PROPOSAL_CONTEXT_SAMPLES,
        "tail_samples": PROPOSAL_TAIL_SAMPLES,
        "total_samples": PROPOSAL_WINDOW_SAMPLES,
    }
    if artifact.get("proposal_window") != expected_window:
        raise RuntimeError("Wake artifact proposal window is invalid.")
    try:
        threshold_payload = artifact["thresholds"]
        if (not isinstance(threshold_payload, dict)
                or set(threshold_payload) != set(ACCEPTED_CLASSES)):
            raise TypeError
        thresholds = validate_thresholds({
            class_name: threshold_payload[class_name]["probability"]
            for class_name in ACCEPTED_CLASSES
            if (isinstance(threshold_payload[class_name], dict)
                and set(threshold_payload[class_name]) == {"probability"})
        })
        mean = np.asarray(artifact["normalization"]["mean"], dtype=np.float32)
        deviation = np.asarray(artifact["normalization"]["deviation"], dtype=np.float32)
        classifier = artifact["classifier"]
        layers = classifier["layers"]
    except (KeyError, TypeError, ValueError, OverflowError, RuntimeError) as error:
        raise RuntimeError("Wake artifact is missing inference state.") from error
    if classifier.get("architecture") != "temporal_conv1d_v1" or set(layers) != set(EXPECTED_LAYER_SHAPES):
        raise RuntimeError("Wake artifact classifier contract is invalid.")
    normalize_features(np.zeros((0, FEATURE_SIZE), dtype=np.float32), mean, deviation)
    state: dict[str, np.ndarray] = {}
    for name, shape in EXPECTED_LAYER_SHAPES.items():
        layer = layers[name]
        if tuple(layer.get("shape", ())) != shape:
            raise RuntimeError(f"Wake artifact layer {name!r} has an invalid shape.")
        value = np.asarray(layer.get("values"), dtype=np.float32)
        if value.size != math.prod(shape) or not np.all(np.isfinite(value)):
            raise RuntimeError(f"Wake artifact layer {name!r} has invalid values.")
        state[name] = value.reshape(shape)
    return mean, deviation, state, thresholds


def inference_digest(artifact: dict) -> str:
    serialized, _ = serialize_artifact(artifact)
    return hashlib.sha256(serialized.encode()).hexdigest()


NODE_PARITY_SOURCE = r"""
const fs = require('node:fs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const mean = new Float32Array(input.mean);
const deviation = new Float32Array(input.deviation);
const layers = Object.fromEntries(Object.entries(input.layers).map(([name, values]) => [name, new Float32Array(values)]));

function conv1dRelu(inputValues, inputChannels, inputLength, weights, bias, outputChannels, kernel, padding) {
    const output = new Float32Array(outputChannels * inputLength);
    for (let channel = 0; channel < outputChannels; channel += 1) {
        for (let position = 0; position < inputLength; position += 1) {
            let sum = bias[channel];
            for (let source = 0; source < inputChannels; source += 1) {
                for (let step = 0; step < kernel; step += 1) {
                    const inputPosition = position + step - padding;
                    if (inputPosition < 0 || inputPosition >= inputLength) continue;
                    sum += inputValues[source * inputLength + inputPosition]
                        * weights[(channel * inputChannels + source) * kernel + step];
                }
            }
            output[channel * inputLength + position] = Math.max(0, sum);
        }
    }
    return output;
}

function maxPool1d(inputValues, channels, inputLength) {
    const outputLength = Math.floor(inputLength / 2);
    const output = new Float32Array(channels * outputLength);
    for (let channel = 0; channel < channels; channel += 1) {
        for (let position = 0; position < outputLength; position += 1) {
            output[channel * outputLength + position] = Math.max(
                inputValues[channel * inputLength + position * 2],
                inputValues[channel * inputLength + position * 2 + 1],
            );
        }
    }
    return output;
}

function dense(inputValues, weights, bias, outputSize) {
    const output = new Array(outputSize);
    for (let row = 0; row < outputSize; row += 1) {
        let sum = bias[row];
        for (let column = 0; column < inputValues.length; column += 1) {
            sum += inputValues[column] * weights[row * inputValues.length + column];
        }
        output[row] = sum;
    }
    return output;
}

function predict(rawRow) {
    const raw = new Float32Array(rawRow);
    const normalized = new Float32Array(raw.length);
    for (let index = 0; index < raw.length; index += 1) {
        normalized[index] = (raw[index] - mean[index]) / deviation[index];
    }
    const transposed = new Float32Array(normalized.length);
    for (let frame = 0; frame < 48; frame += 1) {
        for (let band = 0; band < 32; band += 1) {
            transposed[band * 48 + frame] = normalized[frame * 32 + band];
        }
    }
    const conv1 = conv1dRelu(transposed, 32, 48, layers['conv1.weight'], layers['conv1.bias'], 32, 5, 2);
    const pool1 = maxPool1d(conv1, 32, 48);
    const conv2 = conv1dRelu(pool1, 32, 24, layers['conv2.weight'], layers['conv2.bias'], 48, 3, 1);
    const pool2 = maxPool1d(conv2, 48, 24);
    const dense1 = new Float32Array(dense(pool2, layers['dense1.weight'], layers['dense1.bias'], 64));
    for (let index = 0; index < dense1.length; index += 1) dense1[index] = Math.max(0, dense1[index]);
    const logits = dense(dense1, layers['dense2.weight'], layers['dense2.bias'], 3);
    const maximum = Math.max(...logits);
    const exponentials = logits.map((value) => Math.exp(value - maximum));
    const total = exponentials.reduce((sum, value) => sum + value, 0);
    return exponentials.map((value) => value / total);
}

const probabilities = input.rows.map(predict);
const accepted = probabilities.map((values, index) => {
  const className = input.proposal_types[index] === 'strict'
    ? 'strict_wake' : 'missed_hey_confirmation';
  const classIndex = className === 'strict_wake' ? 1 : 2;
  const winningIndex = values.indexOf(Math.max(...values));
  return winningIndex === classIndex && values[classIndex] >= input.thresholds[className];
});
process.stdout.write(JSON.stringify({probabilities, accepted}));
"""


def exact_metrics(
    probabilities: np.ndarray, targets: np.ndarray, metadata: list[dict],
    thresholds: dict[str, float],
) -> dict:
    values = np.asarray(probabilities, dtype=np.float64)
    if values.shape != (targets.size, len(CLASSES)) or len(metadata) != targets.size:
        raise RuntimeError("Wake metrics received incompatible arrays.")
    accepted_thresholds = validate_thresholds(thresholds)
    winning = np.argmax(values, axis=1)
    compatible = np.asarray([
        CLASSES.index("strict_wake") if item["proposal_type"] == STRICT_PROPOSAL
        else CLASSES.index("missed_hey_confirmation")
        for item in metadata
    ], dtype=np.int64)
    row_thresholds = np.asarray([
        accepted_thresholds[CLASSES[index]] for index in compatible
    ], dtype=np.float64)
    accepted = (winning == compatible) & (
        values[np.arange(values.shape[0]), compatible] >= row_thresholds
    )
    false_indices = np.flatnonzero(accepted & (targets == CLASSES.index("reject")))
    false_groups = {
        (metadata[int(index)]["voice"], int(metadata[int(index)]["rate"]), metadata[int(index)]["phrase"])
        for index in false_indices
    }
    per_class = {}
    for class_name in ACCEPTED_CLASSES:
        index = CLASSES.index(class_name)
        mask = targets == index
        total = int(np.sum(mask))
        correct = int(np.sum(accepted & mask))
        per_class[class_name] = {
            "correct": correct,
            "total": total,
            "recall": correct / max(1, total),
        }
    return {
        "per_class": per_class,
        "reject_rows": int(np.sum(targets == 0)),
        "false_accept_rows": int(false_indices.size),
        "false_accept_groups": len(false_groups),
        "closest_probability_to_threshold": float(np.min(
            np.abs(values[np.arange(values.shape[0]), compatible] - row_thresholds)
        )),
    }


def parity_probe_indices(
    probabilities: np.ndarray, targets: np.ndarray, thresholds: dict[str, float],
) -> np.ndarray:
    values = np.asarray(probabilities, dtype=np.float32)
    accepted_thresholds = validate_thresholds(thresholds)
    selected: list[int] = []
    for target in range(len(CLASSES)):
        indices = np.flatnonzero(targets == target)
        if indices.size == 0:
            continue
        for class_index in range(1, len(CLASSES)):
            probability = values[indices, class_index]
            threshold = accepted_thresholds[CLASSES[class_index]]
            ordered = indices[np.argsort(np.abs(probability - threshold))[:4]]
            selected.extend(int(index) for index in ordered)
        selected.extend(int(index) for index in indices[:2])
    return np.asarray(list(dict.fromkeys(selected)), dtype=np.int64)


def compatible_decisions(
    probabilities: np.ndarray, metadata: list[dict], thresholds: dict[str, float],
) -> np.ndarray:
    values = np.asarray(probabilities, dtype=np.float64)
    accepted_thresholds = validate_thresholds(thresholds)
    winning = np.argmax(values, axis=1)
    compatible = np.asarray([
        1 if item["proposal_type"] == STRICT_PROPOSAL else 2 for item in metadata
    ], dtype=np.int64)
    row_thresholds = np.asarray([
        accepted_thresholds[CLASSES[index]] for index in compatible
    ], dtype=np.float64)
    return (winning == compatible) & (
        values[np.arange(values.shape[0]), compatible] >= row_thresholds
    )


def node_parity(
    state: dict[str, np.ndarray],
    mean: np.ndarray,
    deviation: np.ndarray,
    raw_values: np.ndarray,
    probabilities: np.ndarray,
    targets: np.ndarray,
    metadata: list[dict],
    thresholds: dict[str, float],
) -> dict:
    accepted_thresholds = validate_thresholds(thresholds)
    indices = parity_probe_indices(probabilities, targets, accepted_thresholds)
    payload = {
        "mean": np.asarray(mean, dtype=np.float32).tolist(),
        "deviation": np.asarray(deviation, dtype=np.float32).tolist(),
        "layers": {
            name: np.asarray(value, dtype=np.float32).reshape(-1).tolist()
            for name, value in state.items()
        },
        "rows": np.asarray(raw_values[indices], dtype=np.float32).tolist(),
        "proposal_types": [metadata[int(index)]["proposal_type"] for index in indices],
        "thresholds": accepted_thresholds,
    }
    result = subprocess.run(
        ["node", "-e", NODE_PARITY_SOURCE],
        input=json.dumps(payload, separators=(",", ":"), allow_nan=False),
        text=True,
        capture_output=True,
        check=True,
    )
    try:
        node_output = json.loads(result.stdout)
        node_values = np.asarray(node_output["probabilities"], dtype=np.float64)
        node_accept = np.asarray(node_output["accepted"], dtype=bool)
    except (json.JSONDecodeError, KeyError, TypeError, ValueError) as error:
        raise RuntimeError("Node wake parity output is invalid.") from error
    python_values = np.asarray(probabilities[indices], dtype=np.float64)
    if (node_values.shape != python_values.shape
            or node_accept.shape != (indices.size,)
            or not np.all(np.isfinite(node_values))):
        raise RuntimeError("Node wake parity output is invalid.")
    deltas = np.abs(node_values - python_values)
    probe_metadata = [metadata[int(index)] for index in indices]
    python_accept = compatible_decisions(
        python_values, probe_metadata, accepted_thresholds,
    )
    reject_probe = targets[indices] == CLASSES.index("reject")
    evidence = {
        "probe_rows": int(indices.size),
        "class_probe_rows": {
            class_name: int(np.sum(targets[indices] == class_index))
            for class_index, class_name in enumerate(CLASSES)
        },
        "max_absolute_probability_delta": float(np.max(deltas)),
        "mean_absolute_probability_delta": float(np.mean(deltas)),
        "threshold_decision_mismatches": int(np.sum(python_accept != node_accept)),
        "node_false_accept_probe_rows": int(np.sum(node_accept & reject_probe)),
        "tolerance": PARITY_TOLERANCE,
    }
    evidence["passes"] = bool(
        evidence["threshold_decision_mismatches"] == 0
        and evidence["node_false_accept_probe_rows"] == 0
        and evidence["max_absolute_probability_delta"] <= PARITY_TOLERANCE
    )
    return evidence


def select_development_checkpoint(candidates: list[dict]) -> dict:
    eligible = [candidate for candidate in candidates if candidate.get("eligible") is True]
    if not eligible:
        raise RuntimeError("No serialized development checkpoint passed wake safety.")
    return max(eligible, key=lambda candidate: (
        float(candidate["minimum_fit_recall"]),
        float(candidate["macro_fit_recall"]),
        -int(candidate["epoch"]),
    ))


def evaluate_checkpoint_candidate(
    epoch: int,
    state: dict[str, np.ndarray],
    mean: np.ndarray,
    deviation: np.ndarray,
    fit_raw: np.ndarray,
    fit_values: np.ndarray,
    fit_targets: np.ndarray,
    fit_metadata: list[dict],
    kathy_raw: np.ndarray,
    kathy_values: np.ndarray,
    kathy_targets: np.ndarray,
    kathy_metadata: list[dict],
) -> tuple[dict, dict]:
    fit_probabilities = predict_probabilities(state, fit_values)
    kathy_probabilities = predict_probabilities(state, kathy_values)
    if (not np.all(np.isfinite(fit_probabilities))
            or not np.all(np.isfinite(kathy_probabilities))):
        raise RuntimeError("The serialized checkpoint produced non-finite probabilities.")

    thresholds, threshold_calibration = calibrate_acceptance_thresholds(
        fit_probabilities, fit_targets, fit_metadata,
        kathy_probabilities, kathy_targets, kathy_metadata,
    )
    artifact = artifact_from_state(mean, deviation, state, thresholds)
    _, artifact = serialize_artifact(artifact)
    loaded_mean, loaded_deviation, loaded_state, loaded_thresholds = load_artifact(artifact)
    if (not np.array_equal(loaded_mean, mean)
            or not np.array_equal(loaded_deviation, deviation)
            or loaded_thresholds != thresholds):
        raise RuntimeError("Serialized checkpoint inference metadata changed on reload.")
    for name in EXPECTED_LAYER_SHAPES:
        if not np.array_equal(loaded_state[name], state[name]):
            raise RuntimeError(f"Serialized checkpoint layer {name!r} changed on reload.")

    fit_metrics = exact_metrics(
        fit_probabilities, fit_targets, fit_metadata, loaded_thresholds,
    )
    kathy_metrics = exact_metrics(
        kathy_probabilities, kathy_targets, kathy_metadata, loaded_thresholds,
    )
    fit_parity = node_parity(
        state, mean, deviation, fit_raw, fit_probabilities, fit_targets,
        fit_metadata, loaded_thresholds,
    )
    kathy_parity = node_parity(
        state, mean, deviation, kathy_raw, kathy_probabilities, kathy_targets,
        kathy_metadata, loaded_thresholds,
    )
    rejection_reasons = [
        *hard_gate_failure_reasons(fit_metrics, "fit"),
        *hard_gate_failure_reasons(kathy_metrics, "Kathy"),
    ]
    if not fit_parity["passes"]:
        rejection_reasons.append("fit Python/Node float32 parity failed")
    if not kathy_parity["passes"]:
        rejection_reasons.append("Kathy Python/Node float32 parity failed")
    fit_recalls = {
        class_name: fit_metrics["per_class"][class_name]["recall"]
        for class_name in ACCEPTED_CLASSES
    }
    kathy_recalls = {
        class_name: kathy_metrics["per_class"][class_name]["recall"]
        for class_name in ACCEPTED_CLASSES
    }
    score = {
        "epoch": int(epoch),
        "eligible": not rejection_reasons,
        "rejection_reasons": rejection_reasons,
        "thresholds": loaded_thresholds,
        "fit_recall": fit_recalls,
        "minimum_fit_recall": min(fit_recalls.values()),
        "macro_fit_recall": sum(fit_recalls.values()) / len(fit_recalls),
        "kathy_recall_recorded_not_selected": kathy_recalls,
        "fit_false_accept_rows": fit_metrics["false_accept_rows"],
        "kathy_false_accept_rows": kathy_metrics["false_accept_rows"],
        "fit_parity_passes": fit_parity["passes"],
        "kathy_parity_passes": kathy_parity["passes"],
    }
    runtime = {
        "state": state,
        "artifact": artifact,
        "thresholds": loaded_thresholds,
        "threshold_calibration": threshold_calibration,
        "fit_probabilities": fit_probabilities,
        "kathy_probabilities": kathy_probabilities,
        "fit_metrics": fit_metrics,
        "kathy_metrics": kathy_metrics,
        "fit_parity": fit_parity,
        "kathy_parity": kathy_parity,
    }
    return score, runtime


def configure_reproducible_environment(seed: int) -> dict:
    import torch
    if np.__version__ != PINNED_NUMPY_VERSION:
        raise RuntimeError(f"Expected NumPy {PINNED_NUMPY_VERSION}; found {np.__version__}.")
    if str(torch.__version__).split("+")[0] != PINNED_TORCH_VERSION:
        raise RuntimeError(f"Expected Torch {PINNED_TORCH_VERSION}; found {torch.__version__}.")
    torch.set_num_threads(TORCH_INTRAOP_THREADS)
    try:
        torch.set_num_interop_threads(TORCH_INTEROP_THREADS)
    except RuntimeError:
        if torch.get_num_interop_threads() != TORCH_INTEROP_THREADS:
            raise
    torch.use_deterministic_algorithms(True)
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    return {"python": platform.python_version(), "numpy": np.__version__,
            "torch": str(torch.__version__), "seed": seed, "deterministic": True}


def create_model():
    import torch
    from torch import nn

    class WakeNet(nn.Module):
        def __init__(self):
            super().__init__()
            self.conv1 = nn.Conv1d(MEL_BANDS, 32, kernel_size=5, padding=2)
            self.conv2 = nn.Conv1d(32, 48, kernel_size=3, padding=1)
            self.pool = nn.MaxPool1d(2)
            self.dense1 = nn.Linear(576, 64)
            self.dense2 = nn.Linear(64, len(CLASSES))

        def forward(self, value):
            value = value.reshape(-1, NORMALIZED_FRAMES, MEL_BANDS).transpose(1, 2)
            value = self.pool(torch.relu(self.conv1(value)))
            value = self.pool(torch.relu(self.conv2(value))).flatten(1)
            return self.dense2(torch.relu(self.dense1(value)))

    return WakeNet()


def predict_probabilities(state: dict[str, np.ndarray], values: np.ndarray) -> np.ndarray:
    import torch

    model = create_model()
    model.load_state_dict({name: torch.from_numpy(np.asarray(value, dtype=np.float32))
                           for name, value in state.items()})
    output = []
    model.eval()
    with torch.no_grad():
        for start in range(0, values.shape[0], 4096):
            output.append(torch.softmax(model(torch.from_numpy(values[start:start + 4096])), dim=1).numpy())
    return np.concatenate(output).astype(np.float32, copy=False)


def serialized_state(model) -> dict[str, np.ndarray]:
    state = {
        name: production_roundtrip(value.detach().cpu().numpy())
        for name, value in model.state_dict().items()
    }
    validate_checkpoint_state(state)
    return state


def coverage_failure_reasons(
    coverage: dict, required_coverage: float, split_name: str,
) -> list[str]:
    failures: list[str] = []
    for class_name in CLASSES[1:]:
        if coverage[class_name]["coverage"] < required_coverage:
            failures.append(
                f"{split_name} {class_name} proposal coverage "
                f"{coverage[class_name]['coverage']:.4f} < {required_coverage:.4f}"
            )
    return failures


def recall_failure_reasons(
    metrics: dict, required_recall: float, split_name: str,
) -> list[str]:
    failures: list[str] = []
    for class_name in CLASSES[1:]:
        if metrics["per_class"][class_name]["recall"] < required_recall:
            failures.append(
                f"{split_name} {class_name} recall "
                f"{metrics['per_class'][class_name]['recall']:.4f} < {required_recall:.4f}"
            )
    return failures


def hard_gate_failure_reasons(metrics: dict, split_name: str) -> list[str]:
    failures: list[str] = []
    if metrics["reject_rows"] < 1:
        failures.append(f"{split_name} has no proposal-conditioned reject rows")
    if metrics["false_accept_rows"] != 0 or metrics["false_accept_groups"] != 0:
        failures.append(
            f"{split_name} has {metrics['false_accept_rows']} false-accept rows "
            f"across {metrics['false_accept_groups']} utterances"
        )
    return failures


def enforce_certification_mode(
    failures: list[str], development_build: bool, phase: str,
) -> list[str]:
    unique = list(dict.fromkeys(failures))
    if unique and not development_build:
        raise RuntimeError(f"Fixed {phase} is infeasible: " + "; ".join(unique))
    return unique


def train_wake_model(args: argparse.Namespace) -> None:
    import torch
    from torch import nn
    from torch.utils.data import DataLoader, TensorDataset

    items = build_utterances(args.cache_dir, FIT_VOICES + VALIDATION_VOICES)
    render_all(items, args.workers)
    assert_no_conflicting_acoustic_labels(items)

    proposals = harvest_proposals(items, args.runtime_dir, args.cache_dir)
    fit_pairs = [
        (item, proposal) for item, proposal in zip(items, proposals, strict=True)
        if item.voice in FIT_VOICES
    ]
    kathy_pairs = [
        (item, proposal) for item, proposal in zip(items, proposals, strict=True)
        if item.voice in VALIDATION_VOICES
    ]
    fit_items = [item for item, _ in fit_pairs]
    fit_proposals = [proposal for _, proposal in fit_pairs]
    kathy_items = [item for item, _ in kathy_pairs]
    kathy_proposals = [proposal for _, proposal in kathy_pairs]
    fit_coverage = proposal_coverage(fit_items, fit_proposals)
    kathy_coverage = proposal_coverage(kathy_items, kathy_proposals)
    print("WAKE_PROPOSAL_COVERAGE " + json.dumps(
        {"fit": fit_coverage, "kathy": kathy_coverage}, sort_keys=True,
    ), flush=True)

    certification_failures = enforce_certification_mode([
        *coverage_failure_reasons(
            fit_coverage, FIT_REQUIRED_PROPOSAL_COVERAGE, "fit",
        ),
        *coverage_failure_reasons(
            kathy_coverage, KATHY_REQUIRED_PROPOSAL_COVERAGE, "Kathy",
        ),
    ], args.development_build, "proposal harvest")
    if certification_failures:
        print("WAKE_UNCERTIFIED_DEVELOPMENT " + json.dumps({
            "phase": "proposal_coverage",
            "certification_failures": certification_failures,
        }, sort_keys=True), flush=True)

    fit_raw, fit_targets, fit_metadata = matrix_for(
        fit_items, fit_proposals, DEFAULT_SEED,
    )
    kathy_raw, kathy_targets, kathy_metadata = matrix_for(
        kathy_items, kathy_proposals, DEFAULT_SEED + 1,
    )
    mean = production_roundtrip(np.mean(fit_raw, axis=0).astype(np.float32))
    deviation = production_roundtrip(
        np.maximum(np.std(fit_raw, axis=0), np.float32(0.08))
    )
    fit_values = normalize_features(fit_raw, mean, deviation)
    kathy_values = normalize_features(kathy_raw, mean, deviation)
    weights = group_balanced_sample_weights(fit_targets, fit_metadata)

    model = create_model()
    criterion = nn.CrossEntropyLoss(reduction="none")
    validation_criterion = nn.CrossEntropyLoss()
    optimizer = torch.optim.AdamW(
        model.parameters(),
        lr=DEFAULT_LEARNING_RATE,
        weight_decay=DEFAULT_WEIGHT_DECAY,
    )
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(
        optimizer, T_max=DEFAULT_EPOCHS,
    )
    loader = DataLoader(
        TensorDataset(
            torch.from_numpy(fit_values),
            torch.from_numpy(fit_targets),
            torch.from_numpy(weights),
        ),
        batch_size=TRAINING_BATCH_SIZE,
        shuffle=True,
        generator=torch.Generator().manual_seed(DEFAULT_SEED),
    )
    kathy_tensor = torch.from_numpy(kathy_values)
    kathy_labels = torch.from_numpy(kathy_targets)
    best_loss = math.inf
    best_epoch = 0
    best_state = None
    records = []
    epoch_states: list[tuple[int, dict[str, np.ndarray]]] = []
    for epoch in range(1, DEFAULT_EPOCHS + 1):
        model.train()
        total_loss = 0.0
        total_rows = 0
        for batch_values, batch_targets, batch_weights in loader:
            optimizer.zero_grad(set_to_none=True)
            loss = torch.mean(
                criterion(model(batch_values), batch_targets) * batch_weights
            )
            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), 5.0)
            optimizer.step()
            total_loss += float(loss.detach()) * batch_values.shape[0]
            total_rows += batch_values.shape[0]
        scheduler.step()
        model.eval()
        with torch.no_grad():
            kathy_loss = float(validation_criterion(
                model(kathy_tensor), kathy_labels,
            ))
        record = {
            "epoch": epoch,
            "fit_objective_loss": total_loss / max(1, total_rows),
            "kathy_cross_entropy": kathy_loss,
        }
        records.append(record)
        print("WAKE_EPOCH " + json.dumps(record, sort_keys=True), flush=True)
        epoch_state = serialized_state(model)
        epoch_states.append((epoch, epoch_state))
        if kathy_loss < best_loss:
            best_loss = kathy_loss
            best_epoch = epoch
            best_state = epoch_state

    if best_state is None:
        raise RuntimeError("The fixed run did not produce a checkpoint.")
    candidate_scores: list[dict] = []
    candidate_runtime: dict[int, dict] = {}
    states_to_evaluate = epoch_states if args.development_build else [(best_epoch, best_state)]
    for epoch, candidate_state in states_to_evaluate:
        try:
            score, runtime = evaluate_checkpoint_candidate(
                epoch, candidate_state, mean, deviation,
                fit_raw, fit_values, fit_targets, fit_metadata,
                kathy_raw, kathy_values, kathy_targets, kathy_metadata,
            )
        except (RuntimeError, subprocess.CalledProcessError) as error:
            score = {
                "epoch": int(epoch),
                "eligible": False,
                "rejection_reasons": [
                    f"serialized checkpoint evaluation failed: {type(error).__name__}"
                ],
            }
        else:
            candidate_runtime[epoch] = runtime
        candidate_scores.append(score)
        print("WAKE_CHECKPOINT_CANDIDATE " + json.dumps(score, sort_keys=True), flush=True)

    if args.development_build:
        selected_score = select_development_checkpoint(candidate_scores)
        best_epoch = int(selected_score["epoch"])
        selection_policy = "highest_min_fit_recall_then_macro_fit_recall_then_earliest"
    else:
        selected_score = candidate_scores[0]
        if not selected_score.get("eligible"):
            raise RuntimeError("The lowest-Kathy-cross-entropy checkpoint failed wake safety.")
        selection_policy = "lowest_kathy_cross_entropy_earliest_tie"
    selected = candidate_runtime[best_epoch]
    state = selected["state"]
    artifact = selected["artifact"]
    reloaded_thresholds = selected["thresholds"]
    threshold_calibration = selected["threshold_calibration"]
    fit_metrics = selected["fit_metrics"]
    kathy_metrics = selected["kathy_metrics"]
    fit_parity = selected["fit_parity"]
    kathy_parity = selected["kathy_parity"]
    certification_failures = enforce_certification_mode([
        *certification_failures,
        *recall_failure_reasons(fit_metrics, FIT_REQUIRED_RECALL, "fit"),
        *recall_failure_reasons(kathy_metrics, KATHY_REQUIRED_RECALL, "Kathy"),
    ], args.development_build, "wake certification")
    hard_failures = [
        *hard_gate_failure_reasons(fit_metrics, "fit"),
        *hard_gate_failure_reasons(kathy_metrics, "Kathy"),
    ]
    if not fit_parity["passes"]:
        hard_failures.append("fit Python/Node float32 parity failed")
    if not kathy_parity["passes"]:
        hard_failures.append("Kathy Python/Node float32 parity failed")
    if hard_failures:
        raise RuntimeError("Wake hard gates failed: " + "; ".join(hard_failures))

    artifact["training"] = {
        "generator": "macos_say",
        "proposal_harvester": "packaged_browser_kws",
        "proposal_keywords_score": 3.0,
        "proposal_keywords_threshold": 0.01,
        "proposal_max_active_paths": 4,
        "proposal_trailing_blanks": 0,
        "threshold_policy": "fit_next_float32_then_serialized_kathy_safety",
        "seed": DEFAULT_SEED,
        "epochs": DEFAULT_EPOCHS,
        "learning_rate": DEFAULT_LEARNING_RATE,
        "weight_decay": DEFAULT_WEIGHT_DECAY,
        "batch_size": TRAINING_BATCH_SIZE,
        "selection": selection_policy,
        "selected_epoch": best_epoch,
        "development_build": bool(args.development_build),
        "certification_status": (
            "uncertified_development" if args.development_build else "certified"
        ),
        "fit_voice_count": len(FIT_VOICES),
        "validation_voices": list(VALIDATION_VOICES),
        "rates_wpm": list(RATES),
        "numpy": np.__version__,
        "torch": str(torch.__version__),
    }
    artifact["evaluation"] = {
        "certified": not args.development_build,
        "development_build": bool(args.development_build),
        "certification_status": (
            "uncertified_development" if args.development_build else "certified"
        ),
        "certification_failures": certification_failures,
        "proposal_coverage": {"fit": fit_coverage, "kathy": kathy_coverage},
        "metrics": {"fit": fit_metrics, "kathy": kathy_metrics},
        "threshold_calibration": threshold_calibration,
        "node_parity": {"fit": fit_parity, "kathy": kathy_parity},
        "checkpoint_candidates": candidate_scores,
        "epochs": records,
    }
    serialized, parsed = serialize_artifact(artifact)
    loaded_mean, loaded_deviation, loaded_state, loaded_thresholds = load_artifact(parsed)
    if not np.array_equal(loaded_mean, mean) or not np.array_equal(loaded_deviation, deviation):
        raise RuntimeError("JSON normalization changed during float32 round-trip.")
    for name in EXPECTED_LAYER_SHAPES:
        if not np.array_equal(loaded_state[name], state[name]):
            raise RuntimeError(f"JSON changed layer {name!r} during float32 round-trip.")
    if loaded_thresholds != reloaded_thresholds:
        raise RuntimeError("JSON changed calibrated thresholds during final round-trip.")

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(
        mode="w", encoding="utf-8", dir=args.output.parent,
        prefix=args.output.name + ".", suffix=".tmp", delete=False,
    ) as handle:
        handle.write(serialized)
        temporary = Path(handle.name)
    os.replace(temporary, args.output)
    print(json.dumps({
        "output": str(args.output),
        "bytes": args.output.stat().st_size,
        "sha256": hashlib.sha256(args.output.read_bytes()).hexdigest(),
        "selected_epoch": best_epoch,
        "thresholds": loaded_thresholds,
        "certification_status": artifact["evaluation"]["certification_status"],
        "certification_failures": certification_failures,
        "fit": fit_metrics,
        "kathy": kathy_metrics,
    }, sort_keys=True), flush=True)


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--output", type=Path,
        default=Path("web/public/voice/wake/bean-wake-model-v2.json"),
    )
    parser.add_argument(
        "--cache-dir", type=Path,
        default=Path("web/storage/app/voice-training/bean-wake-v2"),
    )
    parser.add_argument(
        "--runtime-dir", type=Path,
        default=Path("web/public/voice/wake"),
    )
    parser.add_argument("--workers", type=int, default=6)
    parser.add_argument(
        "--development-build", action="store_true",
        help=(
            "Emit an explicitly uncertified artifact when only proposal "
            "coverage or positive recall certification gates fail."
        ),
    )
    return parser


def main() -> None:
    args = build_parser().parse_args()
    if args.workers < 1:
        raise SystemExit("--workers must be positive")
    configure_reproducible_environment(DEFAULT_SEED)
    train_wake_model(args)


if __name__ == "__main__":
    main()
