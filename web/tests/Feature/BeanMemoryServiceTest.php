<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\MemoryItem;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BeanMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BeanMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_requires_an_explicit_exact_canonical_type(): void
    {
        [, $user, $workspace] = $this->memoryOwner();
        $memory = app(BeanMemoryService::class);

        $invalidTypes = [
            'missing' => null,
            'null' => ['type' => null],
            'empty' => ['type' => ''],
            'uppercase' => ['type' => 'Preference'],
            'space alias' => ['type' => 'temporary context'],
            'hyphen alias' => ['type' => 'temporary-context'],
            'unknown' => ['type' => 'knowledge'],
        ];

        foreach ($invalidTypes as $label => $typeAttributes) {
            try {
                $memory->createItem($user, $workspace, [
                    'content' => "Rejected memory {$label}",
                    ...(is_array($typeAttributes) ? $typeAttributes : []),
                ], $user);
                $this->fail("The {$label} memory type should have been rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('Memory type', $exception->getMessage());
            }
        }

        $this->assertSame(0, MemoryItem::query()->count());
    }

    public function test_direct_api_rejects_missing_invalid_and_alias_types_while_update_may_omit_type(): void
    {
        [$token] = $this->memoryOwner();

        $this->withToken($token)->postJson('/api/memory-items', [
            'content' => 'Missing type',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        foreach (['Preference', 'temporary context', 'temporary-context', 'knowledge'] as $type) {
            $this->withToken($token)->postJson('/api/memory-items', [
                'type' => $type,
                'content' => "Rejected {$type}",
            ])->assertUnprocessable()->assertJsonValidationErrors('type');
        }

        $itemId = $this->withToken($token)->postJson('/api/memory-items', [
            'type' => 'preference',
            'content' => 'The user prefers tea.',
        ])->assertCreated()
            ->assertJsonPath('data.type', 'preference')
            ->json('data.id');

        $this->withToken($token)->patchJson("/api/memory-items/{$itemId}", [
            'content' => 'The user prefers coffee.',
        ])->assertOk()
            ->assertJsonPath('data.type', 'preference')
            ->assertJsonPath('data.content', 'The user prefers coffee.');

        $this->withToken($token)->patchJson("/api/memory-items/{$itemId}", [
            'type' => 'Preference',
        ])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->withToken($token)->patchJson("/api/memory-items/{$itemId}", [
            'type' => 'fact',
        ])->assertOk()->assertJsonPath('data.type', 'fact');

        $this->assertSame(1, MemoryItem::query()->count());
    }

    public function test_duplicate_create_is_idempotent_only_within_the_same_canonical_type(): void
    {
        [, $user, $workspace] = $this->memoryOwner();
        $memory = app(BeanMemoryService::class);

        $first = $memory->createItem($user, $workspace, [
            'type' => 'preference',
            'content' => 'The user prefers tea.',
            'confidence' => 70,
            'importance' => 55,
            'metadata' => ['first' => true],
        ], $user);
        $duplicate = $memory->createItem($user, $workspace, [
            'type' => 'preference',
            'content' => '  THE USER PREFERS TEA.  ',
            'confidence' => 95,
            'importance' => 80,
            'metadata' => ['duplicate' => true],
        ], $user);

        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(1, MemoryItem::query()->count());
        $this->assertSame(95, $duplicate->confidence);
        $this->assertSame(80, $duplicate->importance);
        $this->assertSame(['first' => true, 'duplicate' => true], $duplicate->metadata);

        $fact = $memory->createItem($user, $workspace, [
            'type' => 'fact',
            'content' => 'The user prefers tea.',
        ], $user);

        $this->assertNotSame($first->id, $fact->id);
        $this->assertSame(2, MemoryItem::query()->count());
    }

    public function test_request_history_rejects_date_aliases_and_incomplete_or_invalid_absolute_intervals(): void
    {
        [, $user, $workspace] = $this->memoryOwner();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'status' => 'active',
        ]);
        $memory = app(BeanMemoryService::class);

        foreach ([
            ['date' => '2026-07-14'],
            ['from_date' => '2026-07-14', 'to_date' => '2026-07-15'],
            ['from' => '2026-07-14T00:00:00Z'],
            ['to' => '2026-07-15T00:00:00Z'],
            ['from' => 'tomorrow', 'to' => '2026-07-15T00:00:00Z'],
            ['from' => '2026-07-15T00:00:00Z', 'to' => '2026-07-14T00:00:00Z'],
        ] as $filters) {
            try {
                $memory->requestHistory($session, $filters);
                $this->fail('The noncanonical history interval should have been rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame([], $memory->requestHistory($session, [
            'from' => '2026-07-14T00:00:00-04:00',
            'to' => '2026-07-14T23:59:59-04:00',
        ]));
    }

    /** @return array{0:string,1:User,2:Workspace} */
    private function memoryOwner(): array
    {
        $email = 'strict-memory-'.bin2hex(random_bytes(4)).'@example.com';
        $token = $this->apiToken($email);
        $user = User::query()->where('email', $email)->firstOrFail();
        $workspace = Workspace::query()->findOrFail($user->fresh()->default_workspace_id);

        return [$token, $user, $workspace];
    }
}
