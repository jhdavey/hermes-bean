<?php

namespace Tests\Feature;

use Tests\TestCase;

class TopWorkspaceSwitcherAssetTest extends TestCase
{
    public function test_app_shell_renders_top_workspace_switcher_assets(): void
    {
        $appJs = $this->appJsSource();
        $appCss = $this->appCssSource();

        $this->assertStringContainsString('data-top-workspace-select', $appJs);
        $this->assertStringContainsString('workspaceItems.length > 1 ? `<label class="hb-profile-workspace"', $appJs);
        $this->assertStringContainsString('setWorkspace(event.currentTarget.value)', $appJs);
        $this->assertStringContainsString('.hb-profile-workspace', $appCss);
    }

    public function test_web_resource_editors_include_workspace_picker_for_tasks_reminders_and_events(): void
    {
        $appJs = $this->appJsSource();
        $appCss = $this->appCssSource();

        $this->assertStringContainsString('workspaceConnectionsMarkup(kind, item, workspaceId, editing)', $appJs);
        $this->assertStringContainsString('sync_to_workspace_ids: syncTo', $appJs);
        $this->assertStringContainsString('selectedPrimaryWorkspaceId(form, item)', $appJs);
        $this->assertStringContainsString('name="workspaceAssignmentIds"', $appJs);
        $this->assertStringContainsString('hb-workspace-assignment-list', $appCss);
        $this->assertStringNotContainsString('Also assign to', $appJs);
        $this->assertStringContainsString('reminderRecipientOptionsMarkup(selectedWorkspaceIds, item)', $appJs);
        $this->assertStringContainsString('name="notificationRecipients"', $appJs);
        $this->assertStringContainsString('notification_recipients_by_workspace: recipientsByWorkspace', $appJs);
    }

    public function test_web_resource_time_inputs_use_five_minute_steps(): void
    {
        $appJs = $this->appJsSource();

        $this->assertStringContainsString("type === 'datetime-local' || type === 'time'", $appJs);
        $this->assertStringContainsString('step="300"', $appJs);
        $this->assertStringContainsString("labelInput(isReminder ? 'Remind me at' : 'Due date', 'time', 'datetime-local'", $appJs);
        $this->assertStringContainsString("labelInput('Starts at', 'time', 'datetime-local'", $appJs);
        $this->assertStringContainsString("labelInput('Ends at', 'endsAt', 'datetime-local'", $appJs);
    }

    public function test_workspace_switcher_uses_sticky_active_workspace_without_changing_default_workspace(): void
    {
        $appJs = $this->appJsSource();

        $this->assertStringContainsString("const activeWorkspaceKey = 'heybean.web.activeWorkspace';", $appJs);
        $this->assertStringContainsString('restoreRememberedActiveWorkspace(user)', $appJs);
        $this->assertStringContainsString('api(workspaceScopedPath(\'/today\', workspaceId))', $appJs);
        $this->assertStringNotContainsString("api('/workspaces/default'", $appJs);
    }

    public function test_web_bean_chat_uses_one_durable_run_path_without_bridge_suppression(): void
    {
        $appJs = $this->appJsSource();

        $this->assertStringNotContainsString("source: 'web_chat'", $appJs);
        $this->assertStringContainsString(': `/assistant/sessions/${request.sessionId}/runs`', $appJs);
        $this->assertStringContainsString('const body = { content, metadata };', $appJs);
        $this->assertStringContainsString('/messages/${encodeURIComponent(editingMessageId)}/branch', $appJs);
        $this->assertStringContainsString('/runs/lookup?client_request_id=', $appJs);
        $this->assertStringContainsString('const activeChatRequests = new Map();', $appJs);
        $this->assertStringContainsString('activeChatRequestForBeanWorkEvent(event)', $appJs);
        $this->assertStringContainsString('request?.clientRequestId', $appJs);
        $this->assertStringContainsString('client_context: clientTemporalContext()', $appJs);
        $this->assertStringNotContainsString('chatQueue', $appJs);
        $this->assertStringNotContainsString('enqueueChatContent', $appJs);
        $this->assertStringNotContainsString('drainChatQueue', $appJs);
        $this->assertStringNotContainsString('client_queue_status', $appJs);
        $this->assertStringNotContainsString('transcript.endsWith', $appJs);
        $this->assertStringNotContainsString('beanRequestShouldUseQueuedRuntime', $appJs);
        $this->assertStringNotContainsString('beanAcknowledgementForRequest', $appJs);
        $this->assertStringNotContainsString('beanInitialWorkLabelsForRequest', $appJs);
        $this->assertStringNotContainsString('assistantMessageShouldStayOutOfChat', $appJs);
        $this->assertStringNotContainsString('missing_run_bridge', $appJs);
        $this->assertStringNotContainsString('async_queue_bridge', $appJs);
        $this->assertStringNotContainsString('beanWorkSubjectKeyForLabel', $appJs);
        $this->assertStringNotContainsString('beanWorkCategoryForLabel', $appJs);
        $this->assertStringNotContainsString('grocery shopping', $appJs);
    }

    public function test_web_calendar_editor_sends_canonical_top_level_all_day(): void
    {
        $appJs = $this->appJsSource();

        $this->assertStringContainsString('all_day: allDay', $appJs);
        $this->assertStringContainsString('delete metadata.all_day;', $appJs);
        $this->assertStringContainsString('all_day: body.all_day === true', $appJs);
        $this->assertStringContainsString("labelInput('Start date', 'allDayStart'", $appJs);
        $this->assertStringContainsString("labelInput('Ends before', 'allDayEnd'", $appJs);
        $this->assertStringContainsString('preserveLiteralAllDayBounds', $appJs);
        $this->assertStringNotContainsString('allDayEndIsExclusiveMidnight', $appJs);
        $this->assertStringNotContainsString('allDayExclusiveEndDate', $appJs);
    }

    public function test_resource_editors_use_only_the_canonical_recurrence_contract(): void
    {
        $appJs = $this->appJsSource();

        $this->assertStringContainsString('recurrence: recurrence.value', $appJs);
        $this->assertStringContainsString('...recurrence.details', $appJs);
        $this->assertStringContainsString('details.days = Array.from', $appJs);
        $this->assertStringContainsString("details.unit = ['days', 'weeks', 'months', 'years']", $appJs);
        $this->assertStringContainsString("status: completed ? 'open' : 'completed'", $appJs);
        $this->assertStringContainsString("return task?.status === 'completed';", $appJs);
        $this->assertStringContainsString("return reminder?.status === 'completed';", $appJs);
        $this->assertStringNotContainsString('normalizeRecurrenceValue', $appJs);
        $this->assertStringNotContainsString('name="specificDays"', $appJs);
        $this->assertStringNotContainsString('.specificDays', $appJs);
        $this->assertStringNotContainsString('name="intervalUnit"', $appJs);
        $this->assertStringNotContainsString('.intervalUnit', $appJs);
        $this->assertStringNotContainsString('interval_unit', $appJs);
    }

    private function appJsSource(): string
    {
        return collect([
            resource_path('js/app.js'),
            resource_path('js/heybean/config.js'),
            resource_path('js/heybean/webApp.js'),
        ])->map(fn (string $path): string => file_get_contents($path))->implode("\n");
    }

    private function appCssSource(): string
    {
        return collect([
            resource_path('css/app.css'),
            ...glob(resource_path('css/heybean/*.css')),
        ])->map(fn (string $path): string => file_get_contents($path))->implode("\n");
    }
}
