# Bean Model Routing

Bean uses models for interpretation, planning, and natural answer synthesis. Laravel owns execution and safety.

## Text planner

Use `OPENAI_BEAN_TEXT_MODEL` (default `gpt-4.1-mini`) for normal text chat and structured action/tool proposals. Bean requests strict JSON output named `bean_action_proposal` with:

- `response`: short provisional text, never claiming completion before Laravel executes;
- `actions`: zero or more allowlisted tool/action objects with `action` and `arguments`.

Planner guidance:

- Use generic read tools such as `resource.query` for factual app-data questions.
- Use strict mutation actions only when the user wants to change app state.
- Use `date_scope="today"` for today/current-day task/reminder/calendar list requests; task/reminder today means due by today, including overdue open items.
- Use `date_scope="overdue"` for overdue/past-due requests.
- Use query/title fields for item lookup; do not invent ids.
- Prefer retrieving data over guessing.

When `OPENAI_API_KEY` is not configured, or provider requests fail, Laravel falls back to the deterministic local parser so tests and local development remain credential-free.

## Answer synthesis

After Laravel executes tools/actions, production Bean should use a final synthesis pass when app data or multiple tool results are involved. The synthesis model receives:

- original user message;
- provisional planner response;
- structured tool/action results;
- recent conversation context;
- confirmation/ambiguity state.

It must:

- answer directly from verified tool results;
- hide tool names/internal implementation details;
- avoid generic `Done` for factual questions;
- say when data is missing or ambiguous;
- be concise enough for voice but complete enough to be useful.

Credential-free tests may use deterministic fallback formatting, but the production answer path should synthesize naturally from results.

## Strong reasoning

Reserve future `OPENAI_BEAN_REASONING_MODEL` for complex multi-step planning such as schedule optimization, cleanup plans, prioritization, note-to-project extraction, and proactive assistant recommendations.

## Voice

Use OpenAI Realtime only after local wake detection or explicit user activation. Do not stream always-on wake audio to Realtime because live audio input is billable and has privacy implications.

Realtime should provide quick conversational acknowledgement and transport; Laravel remains source of truth for private app data, mutations, policy, and final grounded answers.

## Deterministic tools

Use code/API tools for current date/time, timezone handling, weather, places, maps, and calculations instead of asking the LLM to guess.
