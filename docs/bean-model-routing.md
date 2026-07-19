# Bean Model Routing

Bean uses the text model as the primary agent. Laravel owns execution, dashboard contracts, and safety.

## Text agent loop

Use `OPENAI_BEAN_TEXT_MODEL` (default `gpt-4.1-mini`) for normal text chat and dashboard/tool use. Bean requests strict JSON output named `bean_agent_step` with:

- `final_response`: complete user-facing answer when no more tool work is needed;
- `action`: one allowlisted dashboard/external tool to execute, or `null` when answering;
- `arguments`: the structured arguments for that one tool call.

The runtime loops: model step → Laravel executes one tool/action → result is returned to the model → model chooses the next step or final answer. The model decides how to handle the request from available tools and prior results; Laravel stays a thin tool host.

Agent guidance:

- Use generic read tools such as `resource.query` for factual app-data questions.
- Use strict mutation actions only when the user wants to change app state.
- Use generic `filters`, `sort`, `limit`, `workspace_scope`, and display-only `time_label`; do not add semantic enum branches for each phrase.
- Use query/title fields for item lookup; do not invent ids.
- For public/current/source-backed information, use `external.lookup`, read its result, then answer or create dashboard content from what the model actually saw.
- For normal evergreen knowledge, brainstorming, drafting, or creative answers, answer directly unless the user asks to save/change dashboard data.
- For private dashboard facts, retrieve data before answering.

When `OPENAI_API_KEY` is not configured, or provider requests fail, Laravel falls back to the deterministic local router so tests and local development remain credential-free.

## Dashboard/data layer boundary

Laravel may enforce:

- auth and workspace scoping;
- action/schema validation;
- plan limits and permissions;
- confirmation for destructive/ambiguous/bulk side effects;
- TimeContext date normalization;
- empty/placeholder mutation rejection;
- deterministic formatting for precise local fallback answers.

Laravel should not compose domain-specific artifacts such as recipes, buying guides, or research notes. The model should search, read results, choose useful content, and pass valid dashboard fields to the relevant CRUD action.

## Strong reasoning

Reserve future `OPENAI_BEAN_REASONING_MODEL` for complex multi-step planning such as schedule optimization, cleanup plans, prioritization, note-to-project extraction, and proactive assistant recommendations.

## Voice

Use OpenAI Realtime only after local wake detection or explicit user activation. Do not stream always-on wake audio to Realtime because live audio input is billable and has privacy implications.

Realtime should provide quick conversational acknowledgement and transport; Laravel remains source of truth for private app data, mutations, policy, and final grounded answers.

## Deterministic tools

Use code/API tools for current date/time, timezone handling, weather, places, maps, and calculations instead of asking the LLM to guess.
