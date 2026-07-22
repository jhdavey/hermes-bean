# Hermes/Agent Startup Instructions for HeyBean

When a session is working in this repository and the user asks about Bean, HeyBean, the Hermes Bean runtime, Bean voice UX, or Bean UX benchmarks, bootstrap from repo state instead of relying on chat memory.

## Mandatory Bean continuity bootstrap

1. Read:
   - `docs/agent-project-state.md`
   - `docs/bean-ai-architecture.md`
   - `docs/bean-model-routing.md`
   - `docs/bean-action-schema.md`
   - `docs/bean-ux-evaluation-scenarios.md` for UX benchmark/scenario work
   - `docs/bean-elevenlabs-agent-voice.md` for voice work
2. Check local state before making assumptions:

   ```bash
   git status --short --branch
   git log -5 --oneline
   ```

3. For meaningful Bean UX/runtime work, run the current benchmark before choosing deeper behavioral work:

   ```bash
   cd web
   php artisan bean:ux-benchmark --days=7
   ```

4. If production context is needed and safe/read-only, verify it directly:

   ```bash
   cd /home/forge/heybean.org/current
   php artisan bean:evaluate --production-smoke --recent=200
   php artisan bean:ux-benchmark --days=7
   php artisan bean:ux-evaluate-scenarios --recent=500
   ```

5. Use the largest measured failing benchmark cluster as the next implementation target.
6. Never claim Bean has met a UX goal without measured benchmark output.

## Guardrails

- Do not add a local deterministic Bean brain.
- Do not hard-code semantic domains like recipes, travel, buying guides, etc.
- Laravel is the scoped tool host, auth/safety boundary, TimeContext normalizer, instrumentation layer, and UI mirror.
- Hermes/model owns conversation, memory, reasoning, tool choice, and final wording.
- Private dashboard facts must be grounded in `bean_dashboard` tool results.
- ElevenLabs owns voice turn-taking for the browser voice path; local wake detection should not become a duplicate command brain.
- Never expose secrets or internal runtime/tool/provider errors to users.
