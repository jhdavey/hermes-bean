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
        $this->assertStringContainsString('function topWorkspaceSwitcherMarkup', $appJs);
        $this->assertStringContainsString('topWorkspaceSwitcherMarkup()', $appJs);
        $this->assertStringContainsString('.hb-top-workspace-switcher', $appCss);
    }
}
