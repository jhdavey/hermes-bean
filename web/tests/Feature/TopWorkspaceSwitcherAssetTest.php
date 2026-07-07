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

    public function test_web_bean_chat_uses_queued_run_endpoint_for_work_bearing_turns(): void
    {
        $appJs = $this->appJsSource();

        $this->assertStringContainsString('const useRunEndpoint = useQueuedRuntime && !editingMessageId;', $appJs);
        $this->assertStringContainsString("source: useRunEndpoint ? 'web_queued_chat' : 'web_direct_chat'", $appJs);
        $this->assertStringContainsString('? `/assistant/sessions/${state.session.id}/runs`', $appJs);
        $this->assertStringContainsString("? { content, source: 'web_queued_chat', metadata }", $appJs);
        $this->assertStringContainsString('/messages/${encodeURIComponent(editingMessageId)}/branch', $appJs);
        $this->assertStringContainsString('/runs/lookup?client_request_id=', $appJs);
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
