# Repository instructions

For any work involving Bean voice, wake detection, transcription, realtime audio, assistant runs initiated by voice, acknowledgements, interruptions, Stop/cancel behavior, voice latency, or voice diagnostics:

1. Read `bean-voice-rules.md` completely before planning or changing code.
2. Treat it as the authoritative product contract.
3. Name the affected rules and acceptance journeys in the working plan.
4. Update `bean-voice-rules.md` in the same change if product expectations change.
5. Do not introduce a second owner for lifecycle state, execution order, or response delivery.
6. Every voice regression fix must include deterministic coverage for the complete user journey, not only the immediate symptom.

