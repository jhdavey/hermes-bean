<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

class HermesSemanticSchemaCutoverTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();

        config()->set('database.connections.hermes_schema_cutover_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('hermes_schema_cutover_test');
        DB::setDefaultConnection('hermes_schema_cutover_test');
        Schema::clearResolvedInstance('db.schema');
    }

    protected function tearDown(): void
    {
        DB::purge('hermes_schema_cutover_test');
        DB::setDefaultConnection($this->originalConnection);
        Schema::clearResolvedInstance('db.schema');

        parent::tearDown();
    }

    public function test_forward_cutover_upgrades_the_pre_semantic_schema_and_is_rerunnable(): void
    {
        $this->createPreSemanticSchema();

        $migration = $this->cutoverMigration();
        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('conversation_sessions', 'session_kind'));
        $this->assertFalse(Schema::hasColumn('conversation_sessions', 'runtime_mode'));
        $this->assertTrue(Schema::hasIndex(
            'conversation_sessions',
            'conversation_sessions_session_kind_index',
        ));
        $this->assertSame('conversation', $this->columnDefault('conversation_sessions', 'session_kind'));

        foreach (['provider', 'model', 'router_mode', 'runtime_home', 'tool_policy', 'approval_policy'] as $column) {
            $this->assertFalse(Schema::hasColumn('agent_profiles', $column));
        }

        $this->assertTrue(Schema::hasColumn('agent_profiles', 'settings'));
        $this->assertTrue(Schema::hasColumn('agent_profiles', 'metadata'));
        $this->assertSame('semantic_interpretation', $this->columnDefault('ai_usage_logs', 'route_tier'));

        foreach (['client_request_id', 'request_fingerprint', 'execution_generation', 'queued_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('assistant_runs', $column));
        }

        $this->assertSame('0', $this->columnDefault('assistant_runs', 'execution_generation'));
        $this->assertTrue(Schema::hasIndex(
            'assistant_runs',
            'assistant_runs_session_client_request_unique',
            'unique',
        ));

        $this->assertTrue(Schema::hasIndex(
            'memory_events',
            'memory_events_message_type_unique',
            'unique',
        ));
        $this->assertSame(1, DB::table('memory_events')->count());
        $this->assertSame('keep this event', DB::table('memory_events')->value('content'));

        $this->assertFalse(Schema::hasColumn('voice_turns', 'lane'));
        $this->assertFalse(Schema::hasColumn('voice_turns', 'handler'));
        $this->assertFalse(Schema::hasTable('admin_command_runs'));
    }

    public function test_mysql_schema_grammar_preserves_the_cutover_defaults_and_microsecond_precision(): void
    {
        // SQL compilation does not touch the SQLite PDO; it gives this test a
        // deterministic MySQL grammar without requiring a local MySQL daemon.
        $connection = new MySqlConnection(new PDO('sqlite::memory:'));
        $connection->useDefaultSchemaGrammar();

        $assistantRunSql = (new Blueprint($connection, 'assistant_runs', function (Blueprint $table): void {
            $table->timestamp('queued_at', 6)->nullable()->change();
            $table->timestamp('created_at', 6)->nullable()->change();
        }))->toSql();

        $usageSql = (new Blueprint($connection, 'ai_usage_logs', function (Blueprint $table): void {
            $table->string('route_tier')->default('semantic_interpretation')->change();
        }))->toSql();

        $retiredAgentStateSql = (new Blueprint($connection, 'agent_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'provider',
                'model',
                'router_mode',
                'runtime_home',
                'tool_policy',
                'approval_policy',
            ]);
        }))->toSql();

        $this->assertSame([
            'alter table `assistant_runs` modify `queued_at` timestamp(6) null',
            'alter table `assistant_runs` modify `created_at` timestamp(6) null',
        ], $assistantRunSql);
        $this->assertSame([
            "alter table `ai_usage_logs` modify `route_tier` varchar(255) not null default 'semantic_interpretation'",
        ], $usageSql);
        $this->assertSame([
            'alter table `agent_profiles` drop `provider`, drop `model`, drop `router_mode`, drop `runtime_home`, drop `tool_policy`, drop `approval_policy`',
        ], $retiredAgentStateSql);
    }

    private function createPreSemanticSchema(): void
    {
        Schema::create('conversation_sessions', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->default('active')->index();
            $table->string('runtime_mode')->default('tools');
            $table->timestamps();
        });

        Schema::create('agent_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('openai');
            $table->string('model')->default('gpt-5.5');
            $table->string('router_mode')->default('fixed')->index();
            $table->string('runtime_home')->nullable();
            $table->json('settings')->nullable();
            $table->json('tool_policy')->nullable();
            $table->json('approval_policy')->nullable();
            $table->json('metadata')->nullable();
        });

        Schema::create('ai_usage_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('route_tier')->default('complex')->index();
        });

        Schema::create('assistant_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_session_id');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('hard_deadline_at')->nullable();
            $table->timestamp('last_progress_at')->nullable();
            $table->timestamp('dispatch_requested_at')->nullable();
            $table->timestamps();
        });

        Schema::create('memory_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('conversation_message_id')->nullable();
            $table->string('event_type', 80);
            $table->text('content')->nullable();
        });

        DB::table('memory_events')->insert([
            [
                'conversation_message_id' => 42,
                'event_type' => 'semantic_memory_write',
                'content' => 'keep this event',
            ],
            [
                'conversation_message_id' => 42,
                'event_type' => 'semantic_memory_write',
                'content' => 'remove duplicate event',
            ],
        ]);

        Schema::create('voice_turns', function (Blueprint $table): void {
            $table->id();
            $table->string('lane', 40);
            $table->string('handler', 120);
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('first_progress_at')->nullable();
            $table->timestamp('terminal_at')->nullable();
            $table->timestamp('hard_deadline_at')->nullable();
            $table->timestamp('no_progress_deadline_at')->nullable();
            $table->timestamps();
        });

        Schema::create('voice_turn_events', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });

        Schema::create('admin_command_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('command_key');
        });
    }

    private function cutoverMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path(
            'migrations/2026_07_14_235000_cut_over_to_single_hermes_semantic_schema.php',
        );

        return $migration;
    }

    private function columnDefault(string $table, string $column): string
    {
        $definition = collect(Schema::getColumns($table))->firstWhere('name', $column);

        $this->assertIsArray($definition);

        return trim((string) $definition['default'], "'\"");
    }
}
