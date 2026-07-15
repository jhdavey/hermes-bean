from __future__ import annotations

import inspect
import json
import subprocess
import tempfile
import unittest
from pathlib import Path

import numpy as np

from scripts.voice import train_bean_wake_model as trainer


ROOT = Path(__file__).resolve().parents[2]
ARTIFACT = ROOT / "web/public/voice/wake/bean-wake-model-v2.json"


def zero_state() -> dict[str, np.ndarray]:
    return {
        name: np.zeros(shape, dtype=np.float32)
        for name, shape in trainer.EXPECTED_LAYER_SHAPES.items()
    }


def test_thresholds() -> dict[str, float]:
    return {
        "strict_wake": 0.9700001,
        "missed_hey_confirmation": 0.9800001,
    }


def metadata(proposal_types: list[str]) -> list[dict]:
    return [
        {
            "voice": "Test",
            "rate": 160,
            "phrase": f"row {index}",
            "proposal_type": proposal_type,
        }
        for index, proposal_type in enumerate(proposal_types)
    ]


class BeanWakeModelTests(unittest.TestCase):
    def test_runtime_identity_classes_and_shapes(self):
        self.assertEqual(trainer.MODEL_SCHEMA_VERSION, "2.0.0")
        self.assertEqual(trainer.MODEL_ID, "bean-first-party-wake-v2")
        self.assertEqual(
            trainer.CLASSES,
            ("reject", "strict_wake", "missed_hey_confirmation"),
        )
        self.assertEqual(trainer.EXPECTED_LAYER_SHAPES["dense2.weight"], (3, 64))
        self.assertEqual(trainer.EXPECTED_LAYER_SHAPES["dense2.bias"], (3,))
        self.assertEqual(trainer.PROPOSAL_CONTEXT_SAMPLES, 19_200)
        self.assertEqual(trainer.PROPOSAL_TAIL_SAMPLES, 2_560)
        self.assertEqual(trainer.PROPOSAL_WINDOW_SAMPLES, 21_760)

    def test_corpus_has_all_three_targets_and_hey_beam_is_strict(self):
        items = trainer.build_utterances(Path("/tmp/wake-corpus-contract"), ("Kathy",))
        self.assertEqual({item.label for item in items}, set(trainer.CLASSES))
        labels = {(item.phrase, item.label) for item in items}
        self.assertIn(("Hey beam.", "strict_wake"), labels)
        self.assertIn(("Hey beam, can you hear me?", "strict_wake"), labels)
        self.assertFalse(any(
            item.label == "reject" and item.phrase.lower().startswith("hey beam")
            for item in items
        ))

    def test_trainer_has_one_fixed_unsealed_path(self):
        source = inspect.getsource(trainer)
        for forbidden in (
            "HELD" + "_OUT", "certify" + "-heldout", "bean-address" + "-model",
            "train_address" + "_model", "address_logit" + "_breakpoint",
        ):
            self.assertNotIn(forbidden, source)
        parser_actions = {
            action.dest for action in trainer.build_parser()._actions
            if action.dest != "help"
        }
        self.assertEqual(
            parser_actions, {
                "output", "cache_dir", "runtime_dir", "workers",
                "development_build",
            },
        )
        self.assertIn("numTrailingBlanks: 0", trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("HH EY1 B IY1 N :3.0 #0.01 @HEY_BEAN",
                      trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("HH EY1 B IY1 M :3.0 #0.01 @HEY_BEAN",
                      trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertNotIn("@HEY_BEAM", trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn(":3.0 #0.01 @BEAN", trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("keywords: ''", trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("keywordsBuf: strictKeywords", trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("keywordsBufSize: Module.lengthBytesUTF8(strictKeywords)",
                      trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("const strictStream = kws.createStream();",
                      trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertNotIn("inputFinished", trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("if (value.keyword === 'HEY_BEAN') return null;",
                      trainer.PROPOSAL_HARVEST_SOURCE)
        self.assertIn("value.keyword === 'BEAN') return value",
                      trainer.PROPOSAL_HARVEST_SOURCE)

    def test_certification_is_fail_closed_unless_development_is_explicit(self):
        failures = ["fit strict_wake proposal coverage 0.7000 < 0.9500"]
        with self.assertRaisesRegex(RuntimeError, "proposal harvest is infeasible"):
            trainer.enforce_certification_mode(
                failures, development_build=False, phase="proposal harvest",
            )
        self.assertEqual(
            trainer.enforce_certification_mode(
                failures, development_build=True, phase="proposal harvest",
            ),
            failures,
        )
        self.assertFalse(trainer.build_parser().parse_args([]).development_build)
        self.assertTrue(
            trainer.build_parser().parse_args(["--development-build"]).development_build
        )

    def test_development_mode_does_not_relax_false_accept_safety(self):
        metrics = {
            "reject_rows": 4,
            "false_accept_rows": 1,
            "false_accept_groups": 1,
        }
        failures = trainer.hard_gate_failure_reasons(metrics, "fit")
        self.assertEqual(len(failures), 1)
        self.assertIn("false-accept", failures[0])

    def test_proposal_window_is_end_aligned_with_exact_tail(self):
        samples = np.arange(40_000, dtype=np.float32)
        candidate_end = 25_000
        window = trainer.proposal_aligned_window(samples, candidate_end)
        self.assertEqual(window.shape, (trainer.PROPOSAL_WINDOW_SAMPLES,))
        np.testing.assert_array_equal(
            window,
            samples[
                candidate_end - trainer.PROPOSAL_CONTEXT_SAMPLES:
                candidate_end + trainer.PROPOSAL_TAIL_SAMPLES
            ],
        )

    def test_proposal_window_zero_pads_both_boundaries(self):
        samples = np.arange(1_000, dtype=np.float32) + 1
        left = trainer.proposal_aligned_window(samples, 100)
        self.assertTrue(np.all(left[:trainer.PROPOSAL_CONTEXT_SAMPLES - 100] == 0))
        np.testing.assert_array_equal(
            left[trainer.PROPOSAL_CONTEXT_SAMPLES - 100:
                 trainer.PROPOSAL_CONTEXT_SAMPLES + 900],
            samples,
        )
        right = trainer.proposal_aligned_window(samples, 900)
        source = right[
            trainer.PROPOSAL_CONTEXT_SAMPLES - 900:
            trainer.PROPOSAL_CONTEXT_SAMPLES + 100
        ]
        np.testing.assert_array_equal(source, samples)
        self.assertTrue(np.all(
            right[trainer.PROPOSAL_CONTEXT_SAMPLES + 100:] == 0
        ))

    def test_production_reset_uses_first_active_message_and_nine_silent_chunks(self):
        samples = np.zeros(6_000, dtype=np.float32)
        # Activity begins exactly on boundary 2,560. Production's first active
        # message ends at 3,840, then nine all-inactive 1,280-sample messages
        # reach the 11,200-sample reset threshold at 15,360.
        samples[2_560:2_880] = 0.1
        self.assertEqual(trainer.speech_onset(samples), 2_560)
        self.assertEqual(trainer.production_reset_boundary(samples), 15_360)

    def test_executable_js_collector_ignores_inherited_alias_then_latches_bean(self):
        definitions = trainer.PROPOSAL_HARVEST_SOURCE.split("(async () => {", 1)[0]
        scenario = r"""
let latched = null;
const observed = [];
for (const entry of [
  {boundary: 1280, result: {keyword: 'HEY_BEAN', timestamps: [0.25]}},
  {boundary: 2560, result: {keyword: '', timestamps: []}},
  {boundary: 3840, result: {keyword: 'BEAN', timestamps: [0.5]}},
]) {
  if (latched === null) {
    latched = addressDetection(entry.result, 0, entry.boundary);
  }
  observed.push(latched === null ? null : latched.keyword);
}
process.stdout.write(JSON.stringify({observed, latched}));
"""
        result = subprocess.run(
            ["node", "-e", definitions + scenario],
            text=True, capture_output=True, check=True,
        )
        collected = json.loads(result.stdout)
        self.assertEqual(collected["observed"], [None, None, "BEAN"])
        self.assertEqual(collected["latched"]["keyword"], "BEAN")
        self.assertEqual(collected["latched"]["emitted_at_sample"], 3_840)

    def test_feature_path_requires_full_window_and_keeps_tail(self):
        base = np.zeros(trainer.PROPOSAL_WINDOW_SAMPLES, dtype=np.float32)
        tail = base.copy()
        time = np.arange(trainer.PROPOSAL_TAIL_SAMPLES, dtype=np.float32)
        tail[-trainer.PROPOSAL_TAIL_SAMPLES:] = 0.2 * np.sin(time * 0.17)
        with self.assertRaises(RuntimeError):
            trainer.feature_vector(base[:-1])
        base_features = trainer.feature_vector(base)
        tail_features = trainer.feature_vector(tail)
        self.assertEqual(base_features.shape, (trainer.FEATURE_SIZE,))
        self.assertGreater(float(np.max(np.abs(tail_features - base_features))), 0.01)

    def test_raw_and_feature_collisions_fail_closed(self):
        windows = np.zeros((2, trainer.PROPOSAL_WINDOW_SAMPLES), dtype=np.float32)
        values = np.zeros((2, trainer.FEATURE_SIZE), dtype=np.float32)
        targets = np.asarray([1, 2], dtype=np.int64)
        rows = [{"phrase": "strict"}, {"phrase": "address"}]
        with self.assertRaises(RuntimeError):
            trainer.assert_no_conflicting_windows(windows, targets, rows)
        with self.assertRaises(RuntimeError):
            trainer.assert_no_conflicting_features(values, targets, rows)

    def test_proposal_coverage_requires_compatible_type(self):
        cache = Path("/tmp/wake-coverage-contract")
        items = [
            trainer.Utterance("Kathy", 160, "Hey Bean.", "strict_wake", cache / "a.wav"),
            trainer.Utterance(
                "Kathy", 160, "Bean, can you.", "missed_hey_confirmation",
                cache / "b.wav",
            ),
        ]
        coverage = trainer.proposal_coverage(items, [
            {"proposal_type": "strict", "candidate_end_sample": 1},
            {"proposal_type": "strict", "candidate_end_sample": 1},
        ])
        self.assertEqual(coverage["strict_wake"]["coverage"], 1.0)
        self.assertEqual(coverage["missed_hey_confirmation"]["coverage"], 0.0)

    def test_coalescing_matches_production_tail_boundaries(self):
        def detected(keyword: str, candidate_end: int, emitted: int) -> dict:
            return {
                "keyword": keyword,
                "candidate_end_sample": candidate_end,
                "emitted_at_sample": emitted,
            }

        address = detected("BEAN", 5_100, 6_400)
        same_boundary = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 5_300, 6_400), address,
        )
        self.assertEqual(same_boundary["proposal_type"], "strict")

        # 5,100 + 2,560 is 7,660; the first 1,280-sample boundary at/after
        # that exact tail is 7,680. Strict queried on the overshoot boundary
        # wins before address finalization.
        deadline_overshoot = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 6_000, 7_680), address,
        )
        self.assertEqual(deadline_overshoot["proposal_type"], "strict")
        late = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 7_200, 8_960), address,
        )
        self.assertEqual(late["proposal_type"], "address")
        self.assertEqual(late["classification_boundary_sample"], 7_680)

        # Even when address is first observed after its tail exists, production
        # preserves exactly one following-boundary strict promotion chance.
        late_address = detected("BEAN", 3_000, 7_680)
        following = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 8_000, 8_960), late_address,
        )
        self.assertEqual(following["proposal_type"], "strict")
        too_late = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 9_000, 10_240), late_address,
        )
        self.assertEqual(too_late["proposal_type"], "address")
        self.assertEqual(too_late["classification_boundary_sample"], 8_960)

        inherited = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 5_300, 6_400),
            detected("HEY_BEAN", 5_300, 6_400),
        )
        self.assertEqual(inherited["proposal_type"], "strict")
        inherited_only = trainer.coalesced_proposal_detections(
            None, detected("HEY_BEAN", 5_300, 6_400),
        )
        self.assertIsNone(inherited_only)

        reset_before_tail = trainer.coalesced_proposal_detections(
            detected("HEY_BEAN", 10_000, 10_240), None, reset_at_sample=11_520,
        )
        self.assertIsNone(reset_before_tail)

    def test_minimal_artifact_round_trips_float32_contract(self):
        mean = np.zeros(trainer.FEATURE_SIZE, dtype=np.float32)
        deviation = np.ones(trainer.FEATURE_SIZE, dtype=np.float32)
        artifact = trainer.artifact_from_state(
            mean, deviation, zero_state(), test_thresholds(),
        )
        serialized, parsed = trainer.serialize_artifact(artifact)
        loaded_mean, loaded_deviation, state, thresholds = trainer.load_artifact(parsed)
        self.assertEqual(parsed["classes"], list(trainer.CLASSES))
        self.assertEqual(parsed["proposal_window"], {
            "alignment": "proposal_end",
            "context_samples": 19_200,
            "tail_samples": 2_560,
            "total_samples": 21_760,
        })
        self.assertEqual(parsed["thresholds"], {
            "strict_wake": {"probability": 0.9700001},
            "missed_hey_confirmation": {"probability": 0.9800001},
        })
        self.assertEqual(thresholds, test_thresholds())
        self.assertTrue(np.array_equal(loaded_mean, mean))
        self.assertTrue(np.array_equal(loaded_deviation, deviation))
        self.assertEqual(
            {name: value.shape for name, value in state.items()},
            trainer.EXPECTED_LAYER_SHAPES,
        )
        self.assertNotIn("NaN", serialized)

    def test_artifact_loader_rejects_parallel_or_invalid_contracts(self):
        artifact = trainer.artifact_from_state(
            np.zeros(trainer.FEATURE_SIZE, dtype=np.float32),
            np.ones(trainer.FEATURE_SIZE, dtype=np.float32),
            zero_state(),
            test_thresholds(),
        )
        extra = json.loads(json.dumps(artifact))
        extra["classifier"]["layers"]["guard"] = {"shape": [1], "values": [0]}
        with self.assertRaises(RuntimeError):
            trainer.load_artifact(extra)
        wrong_tail = json.loads(json.dumps(artifact))
        wrong_tail["proposal_window"]["tail_samples"] = 2_559
        with self.assertRaises(RuntimeError):
            trainer.load_artifact(wrong_tail)
        wrong_threshold = json.loads(json.dumps(artifact))
        wrong_threshold["thresholds"]["strict_wake"]["probability"] = 0.94
        with self.assertRaises(RuntimeError):
            trainer.load_artifact(wrong_threshold)
        for invalid in (1.0000001, float("nan"), float("inf"), "0.97", None):
            tampered = json.loads(json.dumps(artifact))
            tampered["thresholds"]["strict_wake"]["probability"] = invalid
            with self.assertRaises(RuntimeError):
                trainer.load_artifact(tampered)
        extra_threshold = json.loads(json.dumps(artifact))
        extra_threshold["thresholds"]["reject"] = {"probability": 0.95}
        with self.assertRaises(RuntimeError):
            trainer.load_artifact(extra_threshold)

    def test_metrics_enforce_proposal_class_compatibility(self):
        probabilities = np.asarray([
            [0.01, 0.98, 0.01],
            [0.01, 0.01, 0.98],
            [0.01, 0.98, 0.01],
            [0.01, 0.01, 0.98],
        ], dtype=np.float32)
        targets = np.asarray([1, 2, 0, 0], dtype=np.int64)
        rows = metadata(["strict", "address", "address", "strict"])
        metrics = trainer.exact_metrics(
            probabilities, targets, rows,
            {"strict_wake": 0.95, "missed_hey_confirmation": 0.95},
        )
        self.assertEqual(metrics["per_class"]["strict_wake"]["recall"], 1.0)
        self.assertEqual(
            metrics["per_class"]["missed_hey_confirmation"]["recall"], 1.0,
        )
        self.assertEqual(metrics["false_accept_rows"], 0)

    def test_calibrated_thresholds_clear_serialized_fit_and_kathy_rejects(self):
        fit_probabilities = np.asarray([
            [0.02, 0.97, 0.01],
            [0.02, 0.01, 0.96],
        ], dtype=np.float32)
        kathy_probabilities = np.asarray([
            [0.01, 0.98, 0.01],
            [0.02, 0.025, 0.955],
        ], dtype=np.float32)
        targets = np.zeros(2, dtype=np.int64)
        rows = metadata(["strict", "address"])

        thresholds, evidence = trainer.calibrate_acceptance_thresholds(
            fit_probabilities, targets, rows,
            kathy_probabilities, targets, rows,
        )
        self.assertEqual(thresholds, {
            "strict_wake": 0.9800001,
            "missed_hey_confirmation": 0.9600001,
        })
        self.assertEqual(evidence["strict_wake"]["fit_serialized_threshold"], 0.9700001)
        self.assertTrue(evidence["strict_wake"]["kathy_safety_escalation"])
        self.assertEqual(
            trainer.exact_metrics(fit_probabilities, targets, rows, thresholds)[
                "false_accept_rows"
            ],
            0,
        )
        self.assertEqual(
            trainer.exact_metrics(kathy_probabilities, targets, rows, thresholds)[
                "false_accept_rows"
            ],
            0,
        )

    def test_calibration_fails_only_when_no_threshold_at_or_below_one_is_safe(self):
        probabilities = np.asarray([[0.0, 1.0, 0.0]], dtype=np.float32)
        with self.assertRaisesRegex(RuntimeError, "at or below 1"):
            trainer.calibrate_acceptance_thresholds(
                probabilities, np.asarray([0]), metadata(["strict"]),
                np.asarray([[1.0, 0.0, 0.0]], dtype=np.float32),
                np.asarray([0]), metadata(["strict"]),
            )

    def test_development_checkpoint_selection_uses_fit_recall_then_earliest(self):
        candidates = [
            {
                "epoch": 1, "eligible": True,
                "minimum_fit_recall": 0.60, "macro_fit_recall": 0.80,
                "kathy_cross_entropy_not_for_selection": 0.01,
            },
            {
                "epoch": 2, "eligible": True,
                "minimum_fit_recall": 0.70, "macro_fit_recall": 0.71,
                "kathy_cross_entropy_not_for_selection": 999.0,
            },
            {
                "epoch": 3, "eligible": True,
                "minimum_fit_recall": 0.70, "macro_fit_recall": 0.75,
                "kathy_cross_entropy_not_for_selection": 1000.0,
            },
            {
                "epoch": 4, "eligible": True,
                "minimum_fit_recall": 0.70, "macro_fit_recall": 0.75,
                "kathy_cross_entropy_not_for_selection": 0.0,
            },
            {
                "epoch": 5, "eligible": False,
                "minimum_fit_recall": 1.0, "macro_fit_recall": 1.0,
                "kathy_cross_entropy_not_for_selection": -1.0,
            },
        ]
        selected = trainer.select_development_checkpoint(candidates)
        self.assertEqual(selected["epoch"], 3)

    def test_per_class_threshold_boundary_is_inclusive(self):
        thresholds = {"strict_wake": 0.97, "missed_hey_confirmation": 0.98}
        strict_below = float(np.nextafter(np.float32(0.97), np.float32(0)))
        address_below = float(np.nextafter(np.float32(0.98), np.float32(0)))
        probabilities = np.asarray([
            [0.01, 0.97, 0.02],
            [0.01, strict_below, 0.02],
            [0.01, 0.01, 0.98],
            [0.01, 0.01, address_below],
        ], dtype=np.float64)
        decisions = trainer.compatible_decisions(
            probabilities, metadata(["strict", "strict", "address", "address"]),
            thresholds,
        )
        np.testing.assert_array_equal(decisions, [True, False, True, False])

    def test_proposal_harvest_cache_is_atomic_and_input_keyed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            runtime = root / "runtime"
            runtime.mkdir()
            for name in trainer.PROPOSAL_RUNTIME_ASSETS:
                (runtime / name).write_bytes(name.encode())
            corpus = root / "utterance.wav"
            corpus.write_bytes(b"first")
            item = trainer.Utterance("Test", 160, "Hey Bean.", "strict_wake", corpus)
            first_key = trainer.proposal_harvest_cache_key([item], runtime)
            cache_path = root / "cache" / f"{first_key}.json"
            detections = [{"strict": None, "address": None, "reset_at_sample": 1_280}]
            trainer.store_cached_proposal_detections(cache_path, first_key, detections)
            self.assertEqual(
                trainer.load_cached_proposal_detections(cache_path, first_key, 1),
                detections,
            )
            self.assertEqual(list(cache_path.parent.glob("*.tmp")), [])
            corpus.write_bytes(b"second-value")
            second_key = trainer.proposal_harvest_cache_key([item], runtime)
            self.assertNotEqual(first_key, second_key)
            self.assertIsNone(
                trainer.load_cached_proposal_detections(cache_path, second_key, 1)
            )

    def test_group_weights_balance_source_groups_within_class(self):
        rows = [
            {"voice": "A", "rate": 1, "phrase": "one"},
            {"voice": "A", "rate": 1, "phrase": "one"},
            {"voice": "A", "rate": 1, "phrase": "two"},
            {"voice": "A", "rate": 1, "phrase": "strict"},
            {"voice": "A", "rate": 1, "phrase": "address"},
        ]
        targets = np.asarray([0, 0, 0, 1, 2], dtype=np.int64)
        weights = trainer.group_balanced_sample_weights(targets, rows)
        self.assertAlmostEqual(
            float(weights[0] + weights[1]), float(weights[2]), places=6,
        )
        self.assertAlmostEqual(float(np.mean(weights)), 1.0, places=6)

    def test_independent_node_float32_probability_and_decision_parity(self):
        raw = np.stack([
            np.linspace(-1, 1, trainer.FEATURE_SIZE, dtype=np.float32),
            np.linspace(1, -1, trainer.FEATURE_SIZE, dtype=np.float32),
            np.zeros(trainer.FEATURE_SIZE, dtype=np.float32),
        ])
        mean = np.zeros(trainer.FEATURE_SIZE, dtype=np.float32)
        deviation = np.ones(trainer.FEATURE_SIZE, dtype=np.float32)
        state = zero_state()
        values = trainer.normalize_features(raw, mean, deviation)
        probabilities = trainer.predict_probabilities(state, values)
        targets = np.asarray([0, 1, 2], dtype=np.int64)
        parity = trainer.node_parity(
            state, mean, deviation, raw, probabilities, targets,
            metadata(["strict", "strict", "address"]),
            {"strict_wake": 0.95, "missed_hey_confirmation": 0.95},
        )
        self.assertTrue(parity["passes"], parity)
        self.assertEqual(parity["threshold_decision_mismatches"], 0)

    @unittest.skipUnless(ARTIFACT.exists(), "final artifact is generated by the fixed run")
    def test_final_artifact_loads_and_has_only_one_classifier(self):
        artifact = json.loads(ARTIFACT.read_text(encoding="utf-8"))
        mean, deviation, state, thresholds = trainer.load_artifact(artifact)
        self.assertEqual(artifact["model_id"], trainer.MODEL_ID)
        self.assertEqual(artifact["classifier"]["architecture"], "temporal_conv1d_v1")
        self.assertTrue(artifact["training"]["development_build"])
        self.assertEqual(
            artifact["training"]["certification_status"],
            "uncertified_development",
        )
        self.assertFalse(artifact["evaluation"]["certified"])
        self.assertTrue(artifact["evaluation"]["development_build"])
        self.assertEqual(
            artifact["evaluation"]["certification_status"],
            "uncertified_development",
        )
        self.assertGreater(len(artifact["evaluation"]["certification_failures"]), 0)
        self.assertEqual(
            artifact["training"]["selection"],
            "highest_min_fit_recall_then_macro_fit_recall_then_earliest",
        )
        self.assertEqual(len(artifact["evaluation"]["checkpoint_candidates"]), 20)
        self.assertEqual(
            trainer.select_development_checkpoint(
                artifact["evaluation"]["checkpoint_candidates"]
            )["epoch"],
            artifact["training"]["selected_epoch"],
        )
        self.assertNotIn("guard", json.dumps(artifact).lower())
        self.assertEqual(mean.shape, (trainer.FEATURE_SIZE,))
        self.assertEqual(deviation.shape, (trainer.FEATURE_SIZE,))
        self.assertEqual(set(state), set(trainer.EXPECTED_LAYER_SHAPES))
        self.assertEqual(set(thresholds), set(trainer.ACCEPTED_CLASSES))


if __name__ == "__main__":
    unittest.main()
