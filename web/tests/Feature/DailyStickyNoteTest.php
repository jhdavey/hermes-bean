<?php

namespace Tests\Feature;

use App\Models\DailyStickyNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyStickyNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_an_empty_note_then_persists_plain_text_for_a_date(): void
    {
        $token = $this->apiToken('sticky-note@example.com');
        $user = User::where('email', 'sticky-note@example.com')->firstOrFail();
        $workspaceId = $user->fresh()->default_workspace_id;

        $this->withToken($token)
            ->getJson('/api/daily-sticky-note?date=2026-07-20&workspace_id='.$workspaceId)
            ->assertOk()
            ->assertJsonPath('data.date', '2026-07-20')
            ->assertJsonPath('data.content', '')
            ->assertJsonPath('data.updated_at', null);

        $this->withToken($token)
            ->putJson('/api/daily-sticky-note', [
                'workspace_id' => $workspaceId,
                'date' => '2026-07-20',
                'content' => "Call the vet\nPick up basil",
            ])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-07-20')
            ->assertJsonPath('data.content', "Call the vet\nPick up basil");

        $this->withToken($token)
            ->getJson('/api/daily-sticky-note?date=2026-07-20&workspace_id='.$workspaceId)
            ->assertOk()
            ->assertJsonPath('data.content', "Call the vet\nPick up basil");

        $this->assertDatabaseHas('daily_sticky_notes', [
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'note_date' => '2026-07-20',
            'content' => "Call the vet\nPick up basil",
        ]);
    }

    public function test_notes_are_isolated_by_date_and_user(): void
    {
        $firstToken = $this->apiToken('sticky-first@example.com');
        $firstUser = User::where('email', 'sticky-first@example.com')->firstOrFail();

        DailyStickyNote::create([
            'user_id' => $firstUser->id,
            'workspace_id' => $firstUser->default_workspace_id,
            'note_date' => '2026-07-20',
            'content' => 'Only on Monday',
        ]);

        $this->withToken($firstToken)
            ->getJson('/api/daily-sticky-note?date=2026-07-21')
            ->assertOk()
            ->assertJsonPath('data.content', '');

        $secondToken = $this->apiToken('sticky-second@example.com');
        $this->withToken($secondToken)
            ->getJson('/api/daily-sticky-note?date=2026-07-20')
            ->assertOk()
            ->assertJsonPath('data.content', '');
    }

    public function test_it_rejects_invalid_dates_and_oversized_notes(): void
    {
        $token = $this->apiToken('sticky-validation@example.com');

        $this->withToken($token)
            ->getJson('/api/daily-sticky-note?date=tomorrow')
            ->assertUnprocessable();

        $this->withToken($token)
            ->putJson('/api/daily-sticky-note', [
                'date' => '2026-07-20',
                'content' => str_repeat('x', 12001),
            ])
            ->assertUnprocessable();
    }
}
