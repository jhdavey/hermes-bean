#!/usr/bin/env python3
"""Run Bean KPI smoke tests against a public HeyBean API base URL.

This intentionally uses only the normal client API surface so it can measure a
real production account without SSH access to the Laravel server.
"""

from __future__ import annotations

import argparse
import json
import secrets
import string
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Any
from zoneinfo import ZoneInfo


LOCAL_TIMEZONE = ZoneInfo("America/New_York")
PROMPTS = [
    "KPI-001: Can you create calendar events for me?",
    "KPI-002: What can you help me manage in HeyBean?",
    "KPI-003: What is the difference between a task and a reminder?",
    "KPI-004: Can you help me organize notes into folders?",
    "KPI-005: How should I think about using Bean for my day?",
    "KPI-006: What do I have coming up today?",
    "KPI-007: What tasks, reminders, and events are left today?",
    "KPI-008: Review today and suggest my next useful step.",
    "KPI-009: What is on my calendar and reminders today?",
    "KPI-010: What do you remember about my preferences?",
    "KPI-011: Create a calendar event called KPI Focus Block tomorrow at 9am for 30 minutes.",
    "KPI-012: Add a task to review KPI notes tomorrow morning.",
    "KPI-013: Set a reminder tomorrow at 8am to check the KPI dashboard.",
    "KPI-014: Create a note called KPI Test Note with three short bullets.",
    "KPI-015: Remember that I prefer concise KPI updates.",
    "KPI-016: Create a calendar event tomorrow at 10am called KPI Planning, add a task to prepare the agenda, and remind me 30 minutes before.",
    "KPI-017: Create a note called KPI Errand Plan, pin it, and remind me tomorrow at 4pm to review it.",
    "KPI-018: Add a workout tomorrow from 5pm to 6pm, then add grocery shopping after it for 45 minutes.",
    "KPI-019: Create a task to organize receipts tomorrow, a reminder 20 minutes before, and a note called Receipt Checklist.",
    "KPI-020: Plan Friday morning with a calendar focus block at 9am, task to gather notes, and reminder Thursday afternoon.",
    "KPI-021: Find the weather for tomorrow in Orlando and tell me if an evening walk makes sense.",
    "KPI-022: Find the weather for tomorrow in Tampa and suggest morning or evening errands.",
    "KPI-023: Find the nearest Wawa to 32820 and tell me the address quickly.",
    "KPI-024: Find the closest Starbucks to 32820 and give me the address.",
    "KPI-025: Find the nearest Home Depot to 32820 and tell me the address quickly.",
    "KPI-026: Remember that KPI errands should be short and practical, then tell me what you saved.",
    "KPI-027: What did you just save about KPI errands?",
    "KPI-028: Add three calendar events: KPI Dentist 7/15 at 3pm, KPI Oil Change 7/16 at 8am, and KPI Review 7/17 at 5pm.",
    "KPI-029: Create a home reset plan for Saturday: calendar block at 10am, task to gather supplies, reminder Friday at 5pm, and a note called KPI Saturday Reset.",
    "KPI-030: What request did I make about KPI Dentist earlier in this smoke run?",
]

COMPLEX_PROMPTS = [
    "KPI-C001: Set up tomorrow morning: calendar focus block at 8:30am for 45 minutes, task to draft the outline, reminder 20 minutes before, and a note called Morning Outline.",
    "KPI-C002: Create an admin sprint for Friday: calendar block at 10am, task to scan receipts, reminder Thursday afternoon, and a note called Admin Sprint Checklist.",
    "KPI-C003: Add three calendar events: KPI Dentist Complex 7/18 at 9am, KPI Vet Complex 7/19 at 11am, and KPI Review Complex 7/20 at 4pm.",
    "KPI-C004: Create a note called School Prep List with three short bullets, pin it, and remind me tomorrow at 7pm to update it.",
    "KPI-C005: Create a task to renew the parking pass tomorrow morning, remind me 30 minutes before, and save a note called Parking Renewal Notes.",
    "KPI-C006: Create a home reset plan for Saturday: calendar block at 9am, task to clear shelves, reminder Friday at 6pm, and a note called Garage Reset.",
    "KPI-C007: Add a workout tomorrow from 6am to 6:45am, then add grocery shopping after it for 30 minutes.",
    "KPI-C008: Create a project follow-up workflow for tax prep: calendar focus block Friday at 9am, task to prepare notes, and reminder Thursday afternoon.",
    "KPI-C009: Create a task to check smoke alarms tomorrow morning, remind me 20 minutes before, and save a note called Smoke Alarm Checklist.",
    "KPI-C010: Create a calendar event tomorrow at 2pm called KPI-C Call, add a task to prepare questions, and remind me 30 minutes before.",
    "KPI-C011: Remember that KPI-C updates should mention blockers first, then tell me what you saved.",
    "KPI-C012: What do you remember about KPI-C updates?",
    "KPI-C013: What request did I make about KPI Dentist earlier in this smoke run?",
    "KPI-C014: What do I have coming up today?",
    "KPI-C015: What tasks, reminders, and events are left today?",
    "KPI-C016: Find the weather for tomorrow in Orlando and tell me if an evening walk makes sense.",
    "KPI-C017: Find the weather for tomorrow in Tampa and suggest morning or evening errands.",
    "KPI-C018: Find the nearest Wawa to 32820 and tell me the address quickly.",
    "KPI-C019: Find the closest Starbucks to 32820 and give me the address.",
    "KPI-C020: Find the nearest Home Depot to 32820 and tell me the address quickly.",
    "KPI-C021: Create a note called Travel Packing List with a compact checklist, pin it, and remind me tomorrow at 5pm to review it.",
    "KPI-C022: Plan Monday project review: calendar block at 11am, task to collect notes, reminder Monday at 10am, and a note called Monday Project Review.",
    "KPI-C023: Add three calendar events: KPI Budget Deep Dive 7/21 at 1pm, KPI Parts Pickup 7/22 at 3pm, and KPI Weekly Wrap 7/23 at 5pm.",
    "KPI-C024: Create a home reset plan for Saturday: calendar block at 10am, task to gather supplies, reminder Friday at 5pm, and a note called KPI Complex Saturday Reset.",
    "KPI-C025: Set up tomorrow evening: calendar focus block at 7pm for 60 minutes, task to pick priorities, reminder 30 minutes before, and a note called Evening Reset.",
    "KPI-C026: Create a task to compare service quotes tomorrow morning, remind me 30 minutes before, and save a note called Service Quote Notes.",
    "KPI-C027: Create a note called Budget Review Notes with three practical bullets, pin it, and add a reminder tomorrow at 4pm to review it.",
    "KPI-C028: Find the weather for tomorrow in Miami and tell me if an outdoor lunch sounds reasonable.",
    "KPI-C029: Find the nearest Wawa to 32820 and tell me the address plus one other close option.",
    "KPI-C030: Review today and suggest my next useful step.",
]

PROMPTS_BY_SCENARIO = {
    "kpi": PROMPTS,
    "kpi_complex": COMPLEX_PROMPTS,
}


@dataclass
class ApiError(Exception):
    status: int
    body: str


class Client:
    def __init__(self, base_url: str, token: str | None = None, timeout: int = 30):
        self.base_url = base_url.rstrip("/")
        self.token = token
        self.timeout = timeout

    def request(self, method: str, path: str, body: dict[str, Any] | None = None) -> tuple[int, dict[str, Any]]:
        url = self.base_url + path
        data = None
        headers = {"Accept": "application/json", "Content-Type": "application/json"}
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        if body is not None:
            data = json.dumps(body).encode("utf-8")
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                raw = response.read().decode("utf-8")
                return response.status, json.loads(raw) if raw else {}
        except urllib.error.HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            raise ApiError(exc.code, raw) from exc

    def post(self, path: str, body: dict[str, Any] | None = None) -> tuple[int, dict[str, Any]]:
        return self.request("POST", path, body)

    def get(self, path: str) -> tuple[int, dict[str, Any]]:
        return self.request("GET", path)

    def delete(self, path: str) -> tuple[int, dict[str, Any]]:
        return self.request("DELETE", path)


def random_password() -> str:
    alphabet = string.ascii_letters + string.digits
    return "Smoke!" + "".join(secrets.choice(alphabet) for _ in range(24))


def register_or_login(client: Client, email: str, password: str) -> str:
    try:
        _, payload = client.post(
            "/auth/register",
            {
                "name": "Bean Production KPI Smoke",
                "email": email,
                "password": password,
                "password_confirmation": password,
                "home_city": "Orlando, FL",
            },
        )
    except ApiError as exc:
        if exc.status != 422:
            raise
        _, payload = client.post("/auth/login", {"email": email, "password": password})

    token = payload.get("data", {}).get("token")
    if not isinstance(token, str) or not token:
        raise RuntimeError("Auth response did not include a bearer token.")
    return token


def iso_now(tz=timezone.utc) -> str:
    return datetime.now(tz).isoformat()


def start_session(client: Client, case: int, suite_id: str) -> int:
    _, payload = client.post(
        "/assistant/sessions",
        {
            "title": f"HTTP production KPI smoke {case}",
            "runtime_mode": "tools",
            "metadata": {"prod_smoke": True, "suite_id": suite_id, "case": case},
        },
    )
    session_id = payload.get("data", {}).get("id")
    if not isinstance(session_id, int):
        raise RuntimeError(f"Could not create session for case {case}.")
    return session_id


def classify(prompt: str) -> str:
    text = prompt.lower()
    if capability_question(text) or "difference between" in text:
        return "general_question"
    if any(word in text for word in ["weather", "nearest", "closest", "nearby", "right now", "latest"]):
        return "external_lookup"
    if any(phrase in text for phrase in ["what do i have", "what tasks", "review today", "on my calendar", "what do you remember", "what did you just save", "what request did i make"]):
        return "app_context_lookup"
    write_markers = ["create", "add", "set", "remember", "plan ", "pin it", "remind me"]
    action_count = sum(1 for marker in write_markers if marker in text)
    if action_count >= 2 or (" and " in text and action_count >= 1):
        return "complex_crud"
    if action_count >= 1:
        return "simple_crud"
    return "general_question"


def target_ms(kind: str) -> int:
    return 3000 if kind == "general_question" else 10000


def quality_failures(prompt: str, answer: str) -> list[str]:
    text = prompt.lower()
    answer_text = " ".join(answer.lower().split())
    failures: list[str] = []
    if not answer_text:
        return ["empty_response"]
    if len(answer_text.split()) < 4:
        failures.append("too_short")
    if answer_text in {"done", "ok", "okay", "sure", "yes"}:
        failures.append("generic_acknowledgement")
    if looks_like_write(text) and not any(word in answer_text for word in ["done", "added", "created", "set", "saved", "calendar", "task", "note", "reminder", "knowledge"]):
        failures.append("missing_write_confirmation")
    if "weather for tomorrow" in text and not any(word in answer_text for word in ["high", "low", "weather", "rain", "overcast", "precipitation", "°f", "degrees"]):
        failures.append("missing_weather_details")
    if looks_like_places(text) and not any(word in answer_text for word in ["address", " mi", " miles"]):
        failures.append("missing_place_details")
    if "32820" in text and looks_like_places(text) and ("ohio" in answer_text or "123 main" in answer_text):
        failures.append("wrong_place_32820")
    if "wawa" in text and "wawa" not in answer_text:
        failures.append("wrong_wawa_32820")
    if "starbucks" in text and "starbucks" not in answer_text:
        failures.append("wrong_starbucks_32820")
    if "home depot" in text and "home depot" not in answer_text:
        failures.append("wrong_home_depot_32820")
    if "remember that" in text and not any(word in answer_text for word in ["saved", "remembered", "knowledge"]):
        failures.append("missing_memory_confirmation")
    if "what did you just save" in text and not any(word in answer_text for word in ["saved", "prefer", "errands"]):
        failures.append("missing_memory_recall")
    if "what request did i make" in text and not any(word in answer_text for word in ["you asked", "kpi dentist", "request"]):
        failures.append("missing_request_history")
    if "tomorrow" in text and looks_like_write(text) and wrong_tomorrow_date(answer_text):
        failures.append("wrong_tomorrow_date")
    if any(phrase in answer_text for phrase in ["could not finish", "usage limit", "timed out", "couldn't verify", "could not verify"]):
        failures.append("failure_copy")
    return sorted(set(failures))


def looks_like_write(text: str) -> bool:
    if "difference between" in text or capability_question(text):
        return False
    if "find the weather" in text or "find the nearest" in text or "find the closest" in text:
        return False
    return any(word in text for word in ["add", "create", "set", "plan", "remember that", "pin it", "remind me"])


def capability_question(text: str) -> bool:
    return (
        ("can you " in text or "could you " in text)
        and any(word in text for word in ["create", "help", "organize", "manage"])
        and "?" in text
    )


def looks_like_places(text: str) -> bool:
    return any(word in text for word in ["nearest ", "closest ", "nearby "])


def wrong_tomorrow_date(answer_text: str) -> bool:
    today = datetime.now(LOCAL_TIMEZONE)
    tomorrow = today + timedelta(days=1)
    today_tokens = month_day_tokens(today)
    tomorrow_tokens = month_day_tokens(tomorrow)
    return any(token in answer_text for token in today_tokens) and not any(token in answer_text for token in tomorrow_tokens)


def month_day_tokens(value: datetime) -> list[str]:
    return [
        f"{value.strftime('%b').lower()} {value.day}",
        f"{value.strftime('%B').lower()} {value.day}",
    ]


def event_type(event: dict[str, Any]) -> str:
    return str(event.get("event_type") or event.get("eventType") or "")


def event_id(event: dict[str, Any]) -> int:
    value = event.get("id")
    return value if isinstance(value, int) else 0


def fetch_events(client: Client, session_id: int, after: int) -> list[dict[str, Any]]:
    events_path = f"/assistant/sessions/{session_id}/events?after={after}&limit=100"
    try:
        _, event_payload = client.get(events_path)
        return list(event_payload.get("data") or [])
    except ApiError:
        return []


def run_case(client: Client, prompt: str, case: int, suite_id: str, timeout: int) -> dict[str, Any]:
    session_id = start_session(client, case, suite_id)
    dashboard_cursor = latest_dashboard_change_id(client)
    client_request_id = f"{suite_id}-{case:03d}-{secrets.token_hex(4)}"
    metadata = {
        "source": "production_smoke_http",
        "prod_smoke": True,
        "suite_id": suite_id,
        "case": case,
        "client_request_id": client_request_id,
        "client_context": {
            "timezone": "America/New_York",
            "timezone_offset": "-04:00",
            "timezone_offset_minutes": -240,
            "current_local_time": iso_now(LOCAL_TIMEZONE),
            "current_utc_time": iso_now(),
        },
    }

    started = time.monotonic()
    status, initial = client.post(
        f"/assistant/sessions/{session_id}/runs",
        {"content": prompt, "source": "flutter", "metadata": metadata},
    )
    first_response_ms = int((time.monotonic() - started) * 1000)
    deadline = started + timeout
    last_event_id = 0
    events: list[dict[str, Any]] = list(initial.get("data", {}).get("events") or [])
    first_progress_ms = None
    completed_payload = initial.get("data", {})

    while time.monotonic() < deadline:
        for event in events:
            last_event_id = max(last_event_id, event_id(event))
            if first_progress_ms is None and event_type(event) in {
                "runtime.run_queued",
                "runtime.tool_model_started",
                "runtime.tool_model_completed",
                "runtime.message_completed",
                "assistant.work_item.planned",
            }:
                first_progress_ms = int((time.monotonic() - started) * 1000)

        lookup_path = f"/assistant/sessions/{session_id}/runs/lookup?client_request_id={urllib.parse.quote(client_request_id)}"
        _, lookup = client.get(lookup_path)
        completed_payload = lookup.get("data", {})
        status_text = str(completed_payload.get("status") or "")
        if status_text in {"completed", "failed", "cancelled"}:
            break

        events.extend(fetch_events(client, session_id, last_event_id))
        time.sleep(0.15)

    duration_ms = int((time.monotonic() - started) * 1000)
    completed_at = time.monotonic()
    final_events = fetch_events(client, session_id, last_event_id)
    if final_events:
        events.extend(final_events)
        if first_progress_ms is None and any(event_type(event) in {
            "runtime.run_queued",
            "runtime.tool_model_started",
            "runtime.tool_model_completed",
            "runtime.message_completed",
            "assistant.work_item.planned",
        } for event in final_events):
            first_progress_ms = duration_ms
    assistant = completed_payload.get("assistant_message") or {}
    answer = str(assistant.get("content") or "")
    run = completed_payload.get("run") or {}
    status_text = str(completed_payload.get("status") or run.get("status") or "unknown")
    kind = classify(prompt)
    dashboard_freshness_ms = measure_dashboard_freshness(client, dashboard_cursor, completed_at) if dashboard_applicable(kind, prompt) else None
    failures = quality_failures(prompt, answer)
    kpi_failures = []
    if first_response_ms > 3000:
        kpi_failures.append("first_response_over_target")
    if duration_ms > target_ms(kind):
        kpi_failures.append("completion_over_target")
    if kind in {"external_lookup", "app_context_lookup"} and first_progress_ms is None:
        kpi_failures.append("runtime_progress_missing")
    if kind in {"simple_crud", "complex_crud"} and not any(event_type(event) == "assistant.work_item.planned" for event in events):
        kpi_failures.append("work_item_progress_missing")
    if dashboard_freshness_ms is not None and dashboard_freshness_ms > 1000:
        kpi_failures.append("dashboard_freshness_over_target")

    return {
        "case": case,
        "session_id": session_id,
        "run_id": run.get("id"),
        "status": status_text,
        "http_status": status,
        "benchmark_class": kind,
        "first_response_ms": first_response_ms,
        "duration_ms": duration_ms,
        "completion_target_ms": target_ms(kind),
        "first_progress_ms": first_progress_ms,
        "dashboard_freshness_ms": dashboard_freshness_ms,
        "failed": status_text != "completed" or bool(failures) or bool(kpi_failures),
        "quality_failures": failures,
        "kpi_failures": kpi_failures,
        "prompt": prompt,
        "assistant": " ".join(answer.split())[:240],
    }


def latest_dashboard_change_id(client: Client) -> int:
    try:
        _, payload = client.get("/dashboard-changes?after=0&limit=1")
    except ApiError:
        return 0
    value = payload.get("data", {}).get("latest_id")
    return value if isinstance(value, int) else 0


def dashboard_applicable(kind: str, prompt: str) -> bool:
    text = prompt.lower()
    if kind not in {"simple_crud", "complex_crud"}:
        return False
    return any(word in text for word in ["calendar", "event", "task", "reminder", "note", "remember"])


def measure_dashboard_freshness(client: Client, after: int, started: float) -> int | None:
    deadline = time.monotonic() + 5
    while time.monotonic() < deadline:
        try:
            _, payload = client.get(f"/dashboard-changes?after={after}&limit=100")
        except ApiError:
            return None
        changes = payload.get("data", {}).get("changes") or []
        if isinstance(changes, list) and changes:
            return int((time.monotonic() - started) * 1000)
        time.sleep(0.2)
    return None


def metric(results: list[dict[str, Any]], predicate) -> dict[str, Any]:
    passed = sum(1 for result in results if predicate(result))
    return {"applicable": len(results), "passed": passed, "rate": passed / max(1, len(results))}


def metric_subset(results: list[dict[str, Any]], predicate, applies) -> dict[str, Any]:
    applicable = [result for result in results if applies(result)]
    if not applicable:
        return {"applicable": 0, "passed": 0, "rate": None}
    passed = sum(1 for result in applicable if predicate(result))
    return {"applicable": len(applicable), "passed": passed, "rate": passed / len(applicable)}


def summarize(results: list[dict[str, Any]], suite_id: str, elapsed_ms: int) -> dict[str, Any]:
    failed = [result for result in results if result["failed"]]
    return {
        "suite_id": suite_id,
        "count": len(results),
        "passed": len(results) - len(failed),
        "failed": len(failed),
        "elapsed_ms": elapsed_ms,
        "kpis": {
            "targets": {
                "first_meaningful_response_under_ms": 3000,
                "completion_under_ms": {"general_question": 3000, "external_lookup_or_complex_action": 10000},
                "action_success_without_user_correction_rate": 0.98,
                "progress_transparency_accuracy_rate": 0.98,
                "dashboard_freshness_under_ms": 1000,
            },
            "first_meaningful_response": metric(results, lambda r: r["first_response_ms"] <= 3000),
            "completed_under_target": metric(results, lambda r: r["duration_ms"] <= r["completion_target_ms"]),
            "action_success_without_user_correction": metric(results, lambda r: r["status"] == "completed" and not r["quality_failures"]),
            "progress_transparency_accuracy": metric(results, lambda r: not any(failure.endswith("progress_missing") for failure in r["kpi_failures"])),
            "dashboard_freshness": metric_subset(
                results,
                lambda r: r.get("dashboard_freshness_ms") is not None and r["dashboard_freshness_ms"] <= 1000,
                lambda r: r.get("dashboard_freshness_ms") is not None,
            ),
            "sample_size": len(results),
        },
        "results": results,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Run Bean KPI smoke tests against a public API.")
    parser.add_argument("--base-url", default="https://heybean.org/api")
    parser.add_argument("--email", default="")
    parser.add_argument("--password", default="")
    parser.add_argument("--count", type=int, default=30)
    parser.add_argument("--timeout", type=int, default=45)
    parser.add_argument("--suite-id", default="")
    parser.add_argument("--scenario", choices=sorted(PROMPTS_BY_SCENARIO), default="kpi")
    parser.add_argument("--cleanup", action="store_true")
    args = parser.parse_args()

    suite_id = args.suite_id or "http-prod-kpi-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    prompts = PROMPTS_BY_SCENARIO[args.scenario]
    email = args.email or f"bean-prod-smoke-{suite_id}@example.com"
    password = args.password or random_password()
    client = Client(args.base_url)
    token = register_or_login(client, email, password)
    client.token = token

    total = max(1, min(args.count, len(prompts)))
    print(f"Running {total} Bean HTTP KPI smoke requests as {email}.")
    print(f"Scenario: {args.scenario}")
    print(f"Suite: {suite_id}")
    started = time.monotonic()
    results: list[dict[str, Any]] = []
    for index, prompt in enumerate(prompts[:total], start=1):
        result = run_case(client, prompt, index, suite_id, args.timeout)
        results.append(result)
        marker = "FAIL" if result["failed"] else "PASS"
        detail = ", ".join(result["quality_failures"] + result["kpi_failures"])
        if detail:
            detail = f" [{detail}]"
        print(f"[{index:03d}/{total:03d}] {marker} {result['duration_ms']}ms{detail} {result['assistant'][:140]}")
        sys.stdout.flush()

    summary = summarize(results, suite_id, int((time.monotonic() - started) * 1000))
    print(json.dumps(summary, indent=2))

    if args.cleanup:
        try:
            client.delete("/account")
            print(f"Deleted production smoke account {email}.")
        except Exception as exc:
            print(f"Cleanup failed for {email}: {exc}", file=sys.stderr)

    kpis = summary["kpis"]
    meets = (
        summary["failed"] == 0
        and kpis["first_meaningful_response"]["rate"] >= 1.0
        and kpis["completed_under_target"]["rate"] >= 1.0
        and kpis["action_success_without_user_correction"]["rate"] >= 0.98
        and kpis["progress_transparency_accuracy"]["rate"] >= 0.98
    )
    return 0 if meets else 1


if __name__ == "__main__":
    raise SystemExit(main())
