# Bean Intelligent Assistant Runtime Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task when delegating future slices.

**Goal:** Move Bean from a patched command router to a domain-aware assistant runtime that can answer natural questions, reason over HeyBean data, act safely, and improve through evaluations rather than one-off intent patches.

**Architecture:** Laravel remains the truth/safety boundary. Bean uses the text model as the agent: each turn returns one tool/action or a final answer, Laravel executes dashboard/external tools through generic reads and strict domain services, then returns results to the model for the next step. Reads become broad and composable; writes remain strict, confirmation-aware, and thin.

**Tech Stack:** Laravel services, existing `DomainResourceService`, Eloquent models, OpenAI structured output, OpenAI Realtime/WebRTC, Bean sessions/messages/activity, feature tests, JS voice tests, production smoke scripts.

---

## Non-negotiable principles

1. **No per-phrase patch treadmill.** New user questions should usually be solved by better domain context, generic query tools, or clearer model instructions — not a bespoke action for every sentence.
2. **Laravel owns truth and safety.** The model never writes the database directly. It can choose tools and final answers, but Laravel scopes, validates, confirms, executes, logs, and emits dashboard changes.
3. **Flexible reads, strict writes.** Read/query/relationship tools can be generic and composable. Mutations stay typed, allowlisted, and domain-service-backed.
4. **Final answers are grounded in tool results.** Bean should not say “Checking… Done.” It should retrieve data, inspect it, and answer naturally from verified facts.
5. **Conversation state matters.** Bean tracks recent entities/lists/workspaces so follow-ups like “what workspace is that in?” or “move the second one” resolve without brittle phrase handling.
6. **Quality is benchmarked.** A growing eval suite covers natural language, voice, ambiguity, workspace context, and safety cases before production deploys.

## Target runtime flow

```text
User text / voice turn
→ load conversation state + recent entities + workspace context
→ model returns one next tool/action or a final answer
→ Laravel executes the tool/action or creates confirmations through thin adapters
→ tool result is returned to the model
→ model repeats, confirms, or gives the final answer
→ activity + dashboard events are emitted
→ conversation state records mentioned entities/results
→ evaluation traces are available for debugging and regression tests
```

## Runtime layers

### 1. Domain intelligence layer

Create declarative knowledge of HeyBean concepts rather than encoding it in scattered `if` statements:

- resources: tasks, reminders, calendar events, notes, folders, categories, workspaces;
- relationships: resource ↔ workspace, linked workspace copies, notes ↔ folders, reminders ↔ recipients;
- computed concepts: overdue, due by today, scheduled, completed, critical, recurring, shared;
- visibility rules: why an item appears on today/overdue/dashboard lists;
- safe/unsafe operation classes.

### 2. Universal read/query layer

Expose generic read tools such as:

- `resource.query`
- `resource.describe`
- `resource.relationships`
- `resource.aggregate`
- `resource.recent`
- `resource.explain_visibility`

The first implementation should ship `resource.query` with filters for resource type, query/title, status, date scope, workspace, and include options such as workspaces/linked copies.

### 3. Model-driven tool loop

Bean's text model is the agent. Instead of proposing every action up front, it returns one `bean_agent_step` at a time:

- final answer with `action=null`; or
- one allowlisted dashboard/external action plus structured arguments.

Laravel executes that one action, records the result, and feeds the accumulated tool results back to the model for the next step. The model decides whether to search, query dashboard state, mutate dashboard state, answer, or continue. Laravel remains the thin deterministic tool host.

The model must be instructed to:

- answer directly from tool results;
- mention uncertainty/ambiguity;
- never claim actions completed when they did not;
- avoid internal tool/action names;
- keep voice answers concise unless listing items;
- compose saved notes/artifacts itself from retrieved evidence or model knowledge, then pass valid CRUD fields.

Credential-free tests keep deterministic fallback formatting, but production should prefer the model-driven loop when API config is available.

### 4. Conversation state and reference resolution

Persist lightweight per-session context in `BeanSession.metadata`, including:

- recent entities: resource type/id/title/workspace names;
- recent lists and ordinal positions;
- current workspace;
- unresolved ambiguity;
- last user goal and last assistant answer class.

This enables:

- “that task”;
- “the second one”;
- “what workspace is it in?”;
- “move it to tomorrow”;
- “actually make it family only.”

### 5. Capability-based actions

Group behavior by capabilities rather than per-phrase handlers:

- answer resource question;
- find/describe resources;
- aggregate resources;
- explain dashboard/list visibility;
- create/update/complete/delete resource;
- schedule/move resource;
- summarize and prioritize;
- propose cleanup plan.

Planner output can still map to structured tools/actions, but the mental model is capability-first.

### 6. Safety and confirmation policy

Reads and simple/single low-risk creations can happen immediately. Destructive, bulk, ambiguous, external-send, workspace membership, billing/account/settings, and cross-workspace sync mutations require confirmation.

### 7. Evaluation system

Add a persistent assistant eval suite with scenarios for:

- today and overdue tasks/reminders;
- workspace questions;
- linked workspace copies;
- dashboard/list visibility explanations;
- ambiguous matches;
- follow-up references;
- voice wake/stop/follow-up;
- no generic “Done” when facts were requested;
- mutation safety/confirmation.

Every regression becomes an eval scenario so quality compounds instead of relying on live manual testing.

## Implementation sequence

### Slice 1: Update docs and ship generic read + synthesis foundation

**Objective:** Stop the patch treadmill for read/context questions.

**Files:**
- Modify: `docs/bean-ai-architecture.md`
- Modify: `docs/bean-action-schema.md`
- Modify: `docs/bean-model-routing.md`
- Create/modify: this plan
- Modify: `web/app/Services/Bean/BeanTextModel.php`
- Modify: `web/app/Services/Bean/BeanActionExecutor.php`
- Modify: `web/app/Services/Bean/BeanRuntimeService.php`
- Test: `web/tests/Feature/BeanRuntimeTest.php`

**Required behavior:**
- Add `resource.query` as a generic read tool.
- Planner routes flexible app-data questions to `resource.query` instead of one-off actions where possible.
- Query results include workspace names and linked-workspace context when requested or useful.
- Runtime runs a model-driven tool loop from returned results.
- Deterministic fallback answers generic query results naturally when OpenAI is disabled.
- Regression: “What workspace is Pay the travel card in?” answers with workspace names without a bespoke formatter path.
- Regression: “Why is Pay the travel card on today’s list?” explains due-by-today/overdue visibility from structured fields.

### Slice 2: Add session working memory

**Objective:** Resolve references across turns.

**Required behavior:**
- Store recent entities/lists in session metadata after every read/list/context result.
- Use recent entities in planner context.
- Deterministic fallback resolves “that task,” “it,” and ordinal references for core cases.
- Regressions for “What’s on today’s list?” → “What workspace is the first one in?”

### Slice 3: Expand generic read capabilities

**Objective:** Cover broad natural questions without new resource-specific actions.

Add:
- `resource.aggregate`
- `resource.relationships`
- `resource.explain_visibility`
- `resource.recent`

Use these for questions like:
- “Which workspace has the most overdue tasks?”
- “Why is this showing today?”
- “What changed recently?”
- “What reminders are related to this task?”

### Slice 4: Product-quality voice conversation layer

**Objective:** Voice feels like an assistant, not a slow command line.

- one-breath wake + command capture;
- short Realtime acknowledgements only;
- Laravel final answer remains source of truth for private app data;
- barge-in and cancel;
- wake-word-free follow-up window;
- no transcript spam in normal mode;
- voice evals for stop/follow-up/self-capture.

### Slice 5: Proactive intelligence

**Objective:** Bean becomes a productivity partner.

Capabilities:
- prioritize today;
- detect stale/duplicated tasks;
- suggest cleanup plans;
- identify calendar conflicts;
- propose safe bulk actions with confirmation;
- summarize workspaces.

## Acceptance criteria for the new architecture

- Most read/context questions are answerable through generic tools, not new bespoke actions.
- Final responses include actual facts from tool results and do not end with generic `Done` for factual questions.
- Mutations still go through domain services and confirmation policy.
- Conversation follow-ups work from recent entity state.
- Every live assistant bug becomes an eval scenario.
- Production deploys include smoke tests for text/runtime and web voice readiness.
