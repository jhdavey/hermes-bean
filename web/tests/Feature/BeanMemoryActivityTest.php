<?php

namespace Tests\Feature;

use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\MemoryItem;
use App\Models\MemorySummary;
use App\Services\BeanMemoryService;
use App\Services\StructuredHermesActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BeanMemoryActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-14 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_completed_turns_record_idempotent_activity_without_inferring_memory_from_prose(): void
    {
        $session = $this->conversation();
        $memory = app(BeanMemoryService::class);
        $turns = [
            'Remember that my preferred editor is Nova.',
            'I prefer oat milk in coffee.',
            'I am a product designer.',
        ];
        $expectedMessageIds = [];
        $expectedAssistantIds = [];

        foreach ($turns as $index => $content) {
            $userMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
            ]);
            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => 'Response '.($index + 1),
            ]);

            $memory->recordTurnActivity($session, $userMessage, $assistantMessage);
            $memory->recordTurnActivity($session->fresh(), $userMessage, $assistantMessage);

            $expectedMessageIds[] = $userMessage->id;
            $expectedAssistantIds[] = $assistantMessage->id;
        }

        $this->assertSame(0, MemoryItem::query()->count());

        $events = MemoryEvent::query()->orderBy('id')->get();
        $this->assertCount(3, $events);
        $this->assertSame($expectedMessageIds, $events->pluck('conversation_message_id')->all());
        $this->assertSame($expectedAssistantIds, $events->pluck('assistant_message_id')->all());
        foreach ($events as $event) {
            $this->assertSame(BeanMemoryService::TURN_ACTIVITY_EVENT_TYPE, $event->event_type);
            $this->assertSame('processed', $event->status);
            $this->assertNotNull($event->processed_at);
            $this->assertNull($event->content);
            $this->assertNull($event->payload);
        }

        $summary = MemorySummary::query()->sole();
        $this->assertSame('daily_activity', $summary->summary_type);
        $this->assertSame(3, (int) data_get($summary->metadata, 'request_count'));
        $this->assertSame('3 user requests recorded for this workspace day. Use request history for exact wording.', $summary->summary);

        $toolEvents = app(StructuredHermesActionService::class)->applyCanonicalSemanticAction(
            $session,
            'memory.create',
            [
                'type' => 'preference',
                'content' => 'The user prefers oat milk in coffee.',
            ],
        );

        $this->assertSame('memory.create', $toolEvents->sole()->tool_name);
        $this->assertDatabaseHas('memory_items', [
            'workspace_id' => $session->workspace_id,
            'source_type' => 'assistant_tool',
            'content' => 'The user prefers oat milk in coffee.',
        ]);
    }

    private function conversation(): ConversationSession
    {
        $token = $this->apiToken('memory-activity@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        return ConversationSession::findOrFail($sessionId);
    }
}
