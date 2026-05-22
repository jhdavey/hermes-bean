# Household Workspaces Implementation Plan

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Add named household workspaces to Hermes Bean so a user can own/invite/manage households, switch between Personal and household contexts, run a dedicated agent/calendar/tasks/reminders/settings per workspace, and selectively copy/sync individual or all items plus Google calendars between workspaces.

**Architecture:** Introduce a first-class `workspaces` tenancy layer where every user has an implicit Personal workspace and can belong to many household workspaces through `workspace_memberships`. Domain resources stay user-attributed but become workspace-scoped via `workspace_id`; cross-workspace sharing is copy/link based, not global mirroring. Each workspace gets its own agent profile, settings, Google calendar selection mappings, and aggregate `/today` state.

**Tech Stack:** Laravel API + MySQL/SQLite migrations, Eloquent policies/services, Hermes structured action executor, Google Calendar sync service, Flutter API client and mobile settings/forms.

---

## Product Rules / Acceptance Criteria

- The default workspace is **Personal** for every user.
- Users can create named household workspaces; creator becomes owner by default.
- Users can be members of multiple households.
- Household owners can invite users, remove users, promote members to owner, demote themselves only when at least one other owner remains, and leave households.
- Each workspace has its own dedicated agent profile/runtime home, calendar events, tasks, reminders, categories, settings, approvals, blockers, activity, and Google calendar mappings.
- Settings gains a **Workspaces** section with:
  - active/default workspace switcher,
  - create household,
  - list joined households,
  - invite/manage members for owned households,
  - leave/remove/promote/demote owner actions,
  - per-workspace Google calendar connection/selection,
  - one-time bulk sync Personal → household and household → Personal.
- Creating or updating an event/task/reminder offers target workspace sync options:
  - from Personal: optional sync/copy to one or more specific households;
  - from household: optional sync/copy to Personal and/or other accessible households.
- Selective sync copies only the item being created/updated and records a source link. It must **not** enable all future sync by accident.
- Bulk sync all items is settings-only and should be explicit, approval-gated, idempotent, and copy/link based.
- Users can connect/sync multiple Google calendars to multiple workspaces. A Google calendar can feed Personal, one household, or several households based on selection mapping.
- Google export/import must respect workspace scope and avoid duplicate events by `(workspace_id, google_calendar_id, google_event_id)`.
- Runtime prompts/actions include active workspace context and allowed target workspace IDs/names.

## Data Model Direction

### New tables

- `workspaces`
  - `id`, `type` (`personal`, `household`), `name`, `slug`, `created_by_user_id`, `status`, `settings`, `metadata`, timestamps.
  - Unique personal workspace per user via a separate column or metadata-backed invariant; prefer `personal_owner_user_id` nullable unique for clean querying.
- `workspace_memberships`
  - `workspace_id`, `user_id`, `role` (`owner`, `member`), `status` (`active`, `invited`, `removed`, `left`), `invited_by_user_id`, `invited_email`, `accepted_at`, timestamps.
  - Unique active membership per `(workspace_id, user_id)`.
- `workspace_invitations` (optional if not using membership rows for pending invites)
  - token, email/user target, status, expires_at.
- `workspace_item_links`
  - links copied items across workspaces: `source_type`, `source_id`, `target_type`, `target_id`, `source_workspace_id`, `target_workspace_id`, `link_type` (`selective_sync`, `bulk_sync`, `google_import`), `metadata`.
- `workspace_google_calendar_mappings`
  - `workspace_id`, `google_calendar_connection_id`, `google_calendar_id`, `sync_direction` (`import`, `export`, `both`), `is_default_export`, `settings`, timestamps.

### Existing table changes

Add nullable-then-backfilled `workspace_id` to:

- `agent_profiles` (replace unique `user_id` with unique nullable user personal profile rule + unique `workspace_id` profile after migration)
- `conversation_sessions`
- `activity_events`
- `tasks`
- `reminders`
- `calendar_events`
- `approvals`
- `blockers`
- `event_categories`

Also add `created_by_user_id` where shared workspace records need attribution, especially `tasks`, `reminders`, `calendar_events`, and conversation/session/activity rows.

Google-specific changes:

- keep `google_calendar_connections` user-owned OAuth credential records;
- remove uniqueness that assumes one connection per user if multiple Google accounts are desired later, or keep one connection MVP but allow many workspace/calendar mappings;
- update `calendar_events` unique index from `user_id + google_event_id` to `workspace_id + google_calendar_id + google_event_id`.

---

## Task 1: Backend workspace schema foundation

**Objective:** Create workspace, membership, item-link, and Google calendar mapping tables plus workspace columns on domain records.

**Files:**
- Create: `web/database/migrations/2026_05_15_180000_create_workspaces_and_memberships.php`
- Create: `web/database/migrations/2026_05_15_181000_add_workspace_scope_to_domain_tables.php`
- Modify: fresh-create migrations only if necessary for clean install parity.

**Steps:**
1. Write feature tests proving a registered user gets a Personal workspace and owner membership.
2. Add migrations with safe nullable columns and backfill strategy.
3. Add indexes for `workspace_id`, membership lookup, and Google calendar de-duping.
4. Run `php artisan migrate`.
5. Run targeted tests, then `php artisan test`.

**Verification:**
- Every existing user has exactly one Personal workspace.
- Every existing domain row has a workspace after backfill.
- Fresh migrations create the same shape.

## Task 2: Workspace models, policies, and service layer

**Objective:** Centralize membership/owner checks and Personal workspace creation.

**Files:**
- Create: `web/app/Models/Workspace.php`
- Create: `web/app/Models/WorkspaceMembership.php`
- Create: `web/app/Models/WorkspaceItemLink.php`
- Create: `web/app/Models/WorkspaceGoogleCalendarMapping.php`
- Create: `web/app/Services/WorkspaceService.php`
- Modify: `web/app/Models/User.php`

**Steps:**
1. Add relationships: user memberships/workspaces, workspace members/resources/agent profile.
2. Implement `ensurePersonalWorkspace(User $user)`.
3. Implement `createHousehold(User $owner, string $name)`.
4. Implement owner/member authorization helpers.
5. Implement owner invariant: cannot remove/demote/leave last owner.

**Verification:**
- Unit/feature tests cover create household, promote owner, remove member, self-leave with/without another owner.

## Task 3: Workspace-scoped agent profiles

**Objective:** Give Personal and household workspaces distinct agent profiles/runtime homes.

**Files:**
- Modify: `web/app/Services/AgentProfileService.php`
- Modify: `web/app/Models/AgentProfile.php`
- Modify: `web/app/Http/Controllers/Api/AuthController.php`
- Modify: `web/app/Services/HermesCliRuntimeService.php`

**Steps:**
1. Replace `ensureForUser(User)` internals with `ensureForWorkspace(Workspace, ?User actor)` while preserving wrapper for Personal.
2. Runtime home should include workspace slug/id, e.g. `hermes-users/workspaces/{workspace_id}`.
3. Auth `/me` should return `personal_workspace`, `active_workspace`, `workspaces`, and relevant `agent_profile`.
4. Chat runtime payload must include active workspace and accessible sync targets.

**Verification:**
- Personal and household profiles have different slugs/runtime homes.
- Starting chat in a household uses the household profile.

## Task 4: Workspace API endpoints

**Objective:** Expose workspace CRUD/member/settings actions to Flutter.

**Files:**
- Create: `web/app/Http/Controllers/Api/WorkspaceController.php`
- Modify: `web/routes/api.php`

**Endpoints:**
- `GET /workspaces`
- `POST /workspaces` (create household)
- `GET /workspaces/{workspace}`
- `PATCH /workspaces/{workspace}` (rename/settings for owners)
- `POST /workspaces/{workspace}/invitations`
- `POST /workspace-invitations/{token}/accept`
- `PATCH /workspaces/{workspace}/members/{member}` (role changes)
- `DELETE /workspaces/{workspace}/members/{member}`
- `POST /workspaces/{workspace}/leave`
- `PATCH /workspaces/default` or include `default_workspace_id` on `/auth/me` update.

**Verification:**
- Non-members cannot read a household.
- Members cannot manage ownership unless owner.
- Last owner cannot be removed/demoted.

## Task 5: Workspace-scoped domain resources

**Objective:** Make tasks/reminders/calendar/categories/today scoped to the active workspace.

**Files:**
- Modify: `web/app/Http/Controllers/Api/DomainResourceController.php`
- Modify: `web/app/Http/Controllers/Api/TodaySummaryController.php`
- Modify: domain models.

**API shape:**
- Accept `workspace_id` in create/update/list query/body.
- Default missing `workspace_id` to user default/Personal workspace.
- Always authorize membership.
- Store `created_by_user_id` for household-created items.
- Return workspace metadata on resources.

**Verification:**
- Personal list excludes household items unless requested.
- Household members see household items.
- Non-members cannot access household items by ID.

## Task 6: Selective cross-workspace item sync/copy

**Objective:** Add per-create/update options to copy a specific item to chosen workspaces.

**Files:**
- Create: `web/app/Services/WorkspaceItemSyncService.php`
- Modify: `web/app/Http/Controllers/Api/DomainResourceController.php`
- Modify: `web/app/Services/StructuredHermesActionService.php`

**API shape:**
- Create/update payloads may include `sync_to_workspace_ids: [id]`.
- Household → Personal is represented by the Personal workspace id, not a magic flag.
- Service copies upsertable fields and writes `workspace_item_links` so repeated sync updates linked copies instead of duplicating.

**Verification:**
- Syncing one task creates exactly one linked task in target household.
- Re-syncing update changes linked target item rather than duplicating.
- No unrelated items are copied.

## Task 7: Settings-only bulk sync

**Objective:** Add explicit bulk sync all from one workspace to another.

**Files:**
- Add methods to `WorkspaceItemSyncService`
- Add endpoint to `WorkspaceController`

**Endpoint:**
- `POST /workspaces/{source}/sync-all`
  - body: `target_workspace_id`, `resource_types`, `direction_label`/confirmation string.

**Rules:**
- Requires membership in both workspaces.
- For household source or target, owners should approve/perform bulk sync for shared data safety.
- Create activity events and a summary count.
- Idempotent via `workspace_item_links`.

**Verification:**
- Bulk sync copies all selected resource types once.
- Running twice does not duplicate.
- This endpoint is not called by ordinary create/update flows.

## Task 8: Google calendar mappings per workspace

**Objective:** Allow multiple Google calendars to sync to multiple workspaces.

**Files:**
- Modify: `web/app/Services/GoogleCalendarSyncService.php`
- Modify: `web/app/Http/Controllers/Api/GoogleCalendarController.php`
- Add mapping model/controller methods.

**Rules:**
- OAuth connection remains user-owned.
- Calendar selection becomes workspace mapping rows.
- Import loops iterate selected workspace mappings and write events into that workspace.
- Export uses workspace default mapping for events from that workspace.
- De-dupe by `workspace_id + google_calendar_id + google_event_id`.

**Verification:**
- Same Google calendar can be mapped to Personal and a household.
- Imported event appears once per mapped workspace.
- Export from household uses household mapping/default calendar.

## Task 9: Runtime structured actions and prompt context

**Objective:** Let Bean create/update resources in active workspace and selectively sync targets.

**Files:**
- Modify: `web/app/Services/HermesCliRuntimeService.php`
- Modify: `web/app/Services/StructuredHermesActionService.php`

**Action parameters:**
- `workspace_id`
- `sync_to_workspace_ids`
- for bulk sync, use medium-risk action requiring approval: `workspace.sync_all`.

**Verification:**
- Low-risk create in active household succeeds.
- Sync target must be in accessible workspace list.
- Bulk sync creates approval, not immediate execution.

## Task 10: Flutter API client models

**Objective:** Add workspace models/endpoints and sync options to existing client.

**Files:**
- Modify: `app/lib/hermes_api_client.dart`
- Modify/add tests under `app/test/`.

**Steps:**
1. Add `HermesWorkspace`, `HermesWorkspaceMembership`, `WorkspaceSyncResult` models.
2. Add workspace API methods.
3. Add optional `workspaceId` and `syncToWorkspaceIds` params to create/update task/reminder/event methods.
4. Hydrate `HermesTodaySummary` with active workspace/workspaces.

**Verification:**
- Existing client tests still pass.
- New transport tests assert expected URLs and payloads.

## Task 11: Flutter Workspaces settings UI

**Objective:** Add the Settings > Workspaces section and management flows.

**Files:**
- Modify: `app/lib/main.dart` (or split into widgets if refactoring)

**UI requirements:**
- Show Personal first.
- Show household list with role badges.
- Switch active/default workspace.
- Create/rename household.
- Invite member by email.
- Member management for owners.
- Leave/remove/promote owner actions with last-owner protection feedback.
- Google calendar mapping controls per workspace.
- Bulk sync all actions with clear source/target confirmation.

**Verification:**
- Widget tests cover switcher, create household, owner controls visibility, and bulk sync confirmation.

## Task 12: Flutter item create/update sync UI

**Objective:** Add per-item sync selectors without changing the existing focused mobile UX.

**Files:**
- Modify task editor, reminder editor, event editor sections in `app/lib/main.dart`.

**UI requirements:**
- In Personal workspace, show “Also copy/sync to…” with joined households.
- In household workspace, show Personal plus other joined households as targets.
- Default no cross-workspace sync unless user opts in.
- Show linked/synced badge on copied items if backend returns link metadata.

**Verification:**
- Creating a personal task with one household selected sends `sync_to_workspace_ids` only for that household.
- Creating in household with no selected target does not copy to Personal.

## Task 13: End-to-end verification and deploy readiness

**Objective:** Prove workspace isolation, sharing, agent profiles, and Google calendar mapping before release.

**Commands:**
- `cd web && php artisan migrate`
- `cd web && php artisan test`
- `cd app && flutter analyze`
- `cd app && flutter test`

**Manual smoke:**
1. Register Alice and Bob.
2. Alice has Personal workspace.
3. Alice creates household “Davey Home”; Alice is owner.
4. Alice invites Bob; Bob accepts.
5. Alice switches to Davey Home and creates event.
6. Bob sees household event; Bob’s Personal workspace does not.
7. Alice creates Personal task and syncs only that task to Davey Home.
8. Alice bulk syncs selected Personal items to Davey Home from Settings.
9. Alice maps a Google calendar to Davey Home and syncs; imported events land in Davey Home.
10. Alice promotes Bob to owner; Alice can leave; Bob can remove Alice after ownership exists.

---

## Notes / Pitfalls

- Do not model “sync” as a global always-on mirror. Per-item sync and settings bulk sync are explicit copy/link operations.
- Avoid putting household resources under the creator’s `user_id` only; every query must scope by workspace and membership.
- Keep user-owned OAuth credentials separate from workspace-owned calendar mappings.
- Preserve Personal as a real workspace rather than a special case wherever possible.
- Bulk operations and ownership changes should be approval-gated or confirmation-gated.
- Keep Flutter bottom-nav/chat focus intact; Workspaces belongs in Settings unless active workspace label/switcher is needed at the top of Today/Chat.
