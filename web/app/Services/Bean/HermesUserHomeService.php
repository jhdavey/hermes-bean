<?php

namespace App\Services\Bean;

use App\Models\BeanSession;
use App\Models\User;
use Illuminate\Support\Facades\File;

class HermesUserHomeService
{
    public function ensureForUser(User $user): string
    {
        $home = $this->homePath($user);
        File::ensureDirectoryExists($home);
        File::ensureDirectoryExists($home.'/sessions');
        File::ensureDirectoryExists($home.'/memories');
        File::ensureDirectoryExists($home.'/skills/bean-dashboard');
        File::ensureDirectoryExists($home.'/plugins/bean-dashboard');
        File::ensureDirectoryExists($home.'/tmp');
        File::ensureDirectoryExists($home.'/logs');

        $this->writeConfig($home);
        $this->writeSkill($home);
        $this->writePlugin($home);

        return $home;
    }

    public function ensureForSession(BeanSession $session): array
    {
        $home = $this->ensureForUser($session->user);
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $sessionName = (string) ($metadata['hermes_session_name'] ?? 'bean-session-'.$session->id);

        $next = [
            ...$metadata,
            'runtime_driver' => 'hermes',
            'hermes_home' => $home,
            'hermes_session_name' => $sessionName,
        ];

        if (($metadata['hermes_home'] ?? null) !== $home || ($metadata['hermes_session_name'] ?? null) !== $sessionName || ($metadata['runtime_driver'] ?? null) !== 'hermes') {
            $session->forceFill(['metadata' => $next])->save();
        }

        return [$home, $sessionName];
    }

    public function homePath(User $user): string
    {
        return rtrim((string) config('bean.hermes.users_path'), '/').'/'.$user->id;
    }

    public function deleteForUser(User $user): void
    {
        File::deleteDirectory($this->homePath($user));
    }

    private function writeConfig(string $home): void
    {
        $provider = (string) config('bean.hermes.provider', 'custom');
        $model = (string) config('bean.hermes.model', 'gpt-4.1-mini');
        $baseUrl = trim((string) config('bean.hermes.base_url', 'https://api.openai.com/v1'));
        $toolsets = (string) config('bean.hermes.toolsets', 'bean_dashboard,skills,memory,session_search,web');
        $skills = (string) config('bean.hermes.skills', 'bean-dashboard');
        $maxTurns = (int) config('bean.hermes.max_turns', 24);
        $baseUrlYaml = $baseUrl !== '' ? "  base_url: {$baseUrl}\n" : '';
        $config = <<<YAML
model:
  provider: {$provider}
  default: {$model}
{$baseUrlYaml}agent:
  max_turns: {$maxTurns}
  reasoning_effort: none
  tool_use_enforcement: auto
  task_completion_guidance: true
compression:
  enabled: true
  threshold: 0.5
  target_ratio: 0.2
memory:
  memory_enabled: true
  user_profile_enabled: true
  write_approval: false
  memory_char_limit: 2200
  user_char_limit: 1375
plugins:
  enabled:
    - bean-dashboard
toolsets:
  - bean_dashboard
  - skills
  - memory
  - session_search
  - web
skills:
  template_vars: true
YAML;

        File::put($home.'/config.yaml', $config);
    }

    private function writeSkill(string $home): void
    {
        $skill = <<<'MD'
---
name: bean-dashboard
description: Use HeyBean dashboard tools safely and conversationally.
version: 1.0.0
---

# Bean Dashboard Agent Skill

You are the user's Bean agent. Bean is the UI layer; Hermes owns the conversation, memory, reasoning, tool choice, and response wording.

## Core principle

Do not make up private dashboard facts. Use the `bean_dashboard` tool whenever the user asks about, searches, lists, creates, updates, completes, deletes, or otherwise manipulates HeyBean dashboard data.

## Tool boundary

Laravel is the authority for dashboard state. The `bean_dashboard` tool is scoped to the authenticated Bean user and current Bean session. It enforces ownership, workspace access, schemas, TimeContext, confirmations, and DB writes.

Call `bean_dashboard` with:

```json
{
  "action": "task.list",
  "arguments": {"time_label": "today"}
}
```

The tool returns structured JSON. Read it before responding. Confirm only actions that actually succeeded in the tool result.

## Supported action families

- `dashboard.summary`
- `time.now`
- `external.lookup`
- `resource.query`
- `resource.relationships`
- `task.list`, `task.search`, `task.context`, `task.create`, `task.update`, `task.complete`, `task.delete`
- `reminder.list`, `reminder.search`, `reminder.create`, `reminder.update`, `reminder.complete`, `reminder.delete`
- `calendar_event.list`, `calendar_event.search`, `calendar_event.create`, `calendar_event.update`, `calendar_event.delete`
- `note.list`, `note.search`, `note.create`, `note.update`, `note.delete`

## Response rules

- If a dashboard mutation succeeds, say what changed using returned IDs/titles/dates where useful.
- If the tool returns `requires_confirmation: true`, ask the user to confirm the returned summary. Do not claim the action happened yet.
- If the tool returns `ok: false`, explain the failure and ask for the smallest clarification needed.
- For destructive actions, rely on the tool's confirmation result rather than bypassing it.
- For dates like today/tomorrow/Tuesday, use natural language in arguments when useful; Laravel TimeContext normalizes the user's timezone.
- For source-backed notes, first gather useful source content, then call `note.create` with real content. Never save an empty placeholder note.

## Memory boundary

Use Hermes conversation memory for conversational continuity: “that one”, “same as before”, “make it shorter”, “what were we just discussing”. Use durable Hermes memory only for stable user preferences/facts. Dashboard facts must come from the dashboard tool because the app state can change.
MD;

        File::put($home.'/skills/bean-dashboard/SKILL.md', $skill);
    }

    private function writePlugin(string $home): void
    {
        File::put($home.'/plugins/bean-dashboard/plugin.yaml', <<<'YAML'
name: bean-dashboard
version: "1.0.0"
description: Scoped HeyBean dashboard tool bridge for per-user Bean agents.
provides_tools:
  - bean_dashboard
YAML);

        File::put($home.'/plugins/bean-dashboard/__init__.py', <<<'PY'
"""Bean dashboard Hermes plugin."""

import json
import os
import subprocess

_SCHEMA = {
    "name": "bean_dashboard",
    "description": (
        "Use this for any private HeyBean dashboard data or mutations: tasks, reminders, "
        "calendar events, notes, workspace-aware resource queries, dashboard summaries, "
        "time context, and source-backed external lookup. The tool is scoped to the current "
        "authenticated Bean user and returns verifiable structured results."
    ),
    "parameters": {
        "type": "object",
        "properties": {
            "action": {
                "type": "string",
                "description": "Bean dashboard action, for example task.list, task.create, note.create, dashboard.summary, external.lookup.",
            },
            "arguments": {
                "type": "object",
                "description": "JSON arguments for the action. Include only fields needed for this action.",
                "additionalProperties": True,
            },
        },
        "required": ["action"],
    },
}


def _handle(params, **kwargs):
    del kwargs
    context = os.environ.get("BEAN_TOOL_CONTEXT")
    artisan = os.environ.get("BEAN_ARTISAN")
    php = os.environ.get("BEAN_PHP", "php")
    if not context or not artisan:
        return json.dumps({"ok": False, "error": "Bean dashboard tool context is not configured."})

    payload = {
        "action": str(params.get("action") or ""),
        "arguments": params.get("arguments") if isinstance(params.get("arguments"), dict) else {},
    }
    try:
        completed = subprocess.run(
            [php, artisan, "bean:dashboard-tool", context],
            input=json.dumps(payload),
            text=True,
            capture_output=True,
            timeout=int(os.environ.get("BEAN_TOOL_TIMEOUT", "60")),
            check=False,
        )
    except Exception as exc:
        return json.dumps({"ok": False, "error": f"Bean dashboard tool failed to start: {exc}"})

    stdout = (completed.stdout or "").strip()
    stderr = (completed.stderr or "").strip()
    if completed.returncode != 0:
        return json.dumps({"ok": False, "error": stderr or stdout or f"Bean dashboard tool exited {completed.returncode}"})
    if not stdout:
        return json.dumps({"ok": False, "error": "Bean dashboard tool returned no output."})
    try:
        json.loads(stdout)
    except Exception:
        return json.dumps({"ok": False, "error": "Bean dashboard tool returned non-JSON output.", "output": stdout[:2000]})
    return stdout


def register(ctx):
    ctx.register_tool(
        name="bean_dashboard",
        toolset="bean_dashboard",
        schema=_SCHEMA,
        handler=_handle,
        description="Scoped HeyBean dashboard tool bridge.",
    )
PY);
    }
}
