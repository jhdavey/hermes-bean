# Hermes/Agent Startup Instructions for HeyBean

When a session is working in this repository and the user asks about Bean, HeyBean, the Hermes Bean runtime, Bean voice UX, or world-class Bean UX benchmarks, bootstrap from repo state instead of relying on chat memory.

## Mandatory Bean UX continuity bootstrap

1. Read:
   - `docs/agent-project-state.md`
   - `docs/bean-world-class-ux-goal.md`
   - `docs/bean-world-class-ux-progress.json`
   - `docs/bean-ux-evaluation-scenarios.md`
2. Run the current benchmark before choosing deeper behavioral work:

   ```bash
   cd web
   php artisan bean:ux-benchmark --days=7
   ```

3. If production context is needed and safe/read-only, run:

   ```bash
   cd /home/forge/heybean.org/current
   php artisan bean:evaluate --production-smoke --recent=200
   php artisan bean:ux-benchmark --days=7
   php artisan bean:ux-evaluate-scenarios --recent=500
   ```

4. For voice-specific work, read `docs/bean-voice-live-sample-harness.md` before asking for or evaluating live voice samples.
5. Use the largest failing benchmark cluster as the next implementation target.
6. After every meaningful work block, update `docs/bean-world-class-ux-progress.json` by rerunning `php artisan bean:ux-benchmark` or manually recording:
   - timestamp
   - local commit
   - production commit if deployed
   - tests/builds run
   - latest metrics
   - blockers
   - next recommended action
6. Never claim Bean has met the world-class UX goal without measured benchmark output.

## Guardrails

- Do not add a local deterministic Bean brain.
- Do not hard-code semantic domains like recipes, travel, buying guides, etc.
- Laravel is the scoped tool host, auth/safety boundary, TimeContext normalizer, instrumentation layer, and UI mirror.
- Hermes/model owns conversation, memory, reasoning, tool choice, and final wording.
- Private dashboard facts must be grounded in `bean_dashboard` tool results.
- Never expose secrets or internal runtime/tool/provider errors to users.
