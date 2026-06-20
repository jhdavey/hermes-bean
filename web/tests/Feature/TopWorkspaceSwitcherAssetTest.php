<?php

namespace Tests\Feature;

use Tests\TestCase;

class TopWorkspaceSwitcherAssetTest extends TestCase
{
    public function test_app_shell_renders_top_workspace_switcher_assets(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));
        $appCss = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-top-workspace-select', $appJs);
        $this->assertStringContainsString('workspaceItems.length > 1 ? `<label class="hb-profile-workspace"', $appJs);
        $this->assertStringContainsString('setWorkspace(event.currentTarget.value)', $appJs);
        $this->assertStringContainsString('.hb-profile-workspace', $appCss);
    }

    public function test_web_resource_editors_include_workspace_picker_for_tasks_reminders_and_events(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('workspaceConnectionsMarkup(kind, item, workspaceId, editing)', $appJs);
        $this->assertStringContainsString('sync_to_workspace_ids: syncTo', $appJs);
        $this->assertStringContainsString("if (!item && data.workspaceId) body.workspace_id = Number(data.workspaceId);", $appJs);
        $this->assertStringContainsString('name="syncWorkspaceIds"', $appJs);
        $this->assertStringContainsString('Also assign to', $appJs);
        $this->assertStringContainsString('reminderRecipientOptionsMarkup(sourceWorkspaceId, linked, item)', $appJs);
        $this->assertStringContainsString('name="notificationRecipients"', $appJs);
        $this->assertStringContainsString('notification_recipients_by_workspace: recipientsByWorkspace', $appJs);
    }

    public function test_web_resource_time_inputs_use_five_minute_steps(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("type === 'datetime-local' || type === 'time'", $appJs);
        $this->assertStringContainsString('step="300"', $appJs);
        $this->assertStringContainsString("labelInput(isReminder ? 'Remind me at' : 'Due date', 'time', 'datetime-local'", $appJs);
        $this->assertStringContainsString("labelInput('Starts at', 'time', 'datetime-local'", $appJs);
        $this->assertStringContainsString("labelInput('Ends at', 'endsAt', 'datetime-local'", $appJs);
    }

    public function test_workspace_switcher_uses_sticky_active_workspace_without_changing_default_workspace(): void
    {
        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("const activeWorkspaceKey = 'heybean.web.activeWorkspace';", $appJs);
        $this->assertStringContainsString('restoreRememberedActiveWorkspace(user)', $appJs);
        $this->assertStringContainsString('api(workspaceScopedPath(\'/today\', workspaceId))', $appJs);
        $this->assertStringNotContainsString("api('/workspaces/default'", $appJs);
    }
}
