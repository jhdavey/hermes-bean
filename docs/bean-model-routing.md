# Bean Model Routing

## Text

Use `OPENAI_BEAN_TEXT_MODEL` (default `gpt-4.1-mini`) for normal text chat and structured action proposals. Bean requests a strict JSON-schema response named `bean_action_proposal` with:

- `response`: short user-facing text
- `actions`: zero or more allowlisted action objects with `action` and `arguments`

When `OPENAI_API_KEY` is not configured, or the provider request fails, Laravel falls back to the deterministic local parser so tests and local development remain credential-free.

## Voice

Use OpenAI Realtime only after local wake detection or explicit tap-to-talk. Do not stream always-on wake audio to Realtime because live audio input is billable and has privacy implications.

## Strong reasoning

Reserve future `OPENAI_BEAN_REASONING_MODEL` for complex planning such as schedule optimization or note-to-project extraction.

## Deterministic tools

Use code/API tools for current date/time, timezone handling, weather, places, and calculations instead of asking the LLM to guess.
