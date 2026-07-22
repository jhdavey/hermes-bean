<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('early_access_signups', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->unique()->constrained()->nullOnDelete();
            $table->string('status')->default('waitlisted')->after('source')->index();
            $table->timestamp('admitted_at')->nullable()->after('status');
            $table->timestamp('waitlisted_at')->nullable()->after('admitted_at');
            $table->timestamp('registered_at')->nullable()->after('waitlisted_at');
            $table->timestamp('notified_at')->nullable()->after('registered_at');
        });

        Schema::create('early_access_rollouts', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('admitted_count')->default(0);
            $table->timestamps();
        });

        $now = now();
        $users = DB::table('users')->select(['id', 'name', 'email'])->orderBy('id')->get();
        foreach ($users as $user) {
            $existing = DB::table('early_access_signups')->where('email', strtolower((string) $user->email))->first();
            if ($existing) {
                DB::table('early_access_signups')->where('id', $existing->id)->update([
                    'user_id' => $user->id,
                    'name' => $existing->name ?: $user->name,
                    'status' => 'internal',
                    'registered_at' => $existing->registered_at ?? $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('early_access_signups')->insert([
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => strtolower((string) $user->email),
                    'use_case' => null,
                    'requested_plan' => null,
                    'source' => 'existing_account',
                    'status' => 'internal',
                    'admitted_at' => null,
                    'waitlisted_at' => null,
                    'registered_at' => $now,
                    'notified_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $capacity = 100;
        $existingLeadIds = DB::table('early_access_signups')
            ->whereNull('user_id')
            ->orderBy('id')
            ->pluck('id');
        $admittedLeadIds = $existingLeadIds->take($capacity);
        if ($admittedLeadIds->isNotEmpty()) {
            DB::table('early_access_signups')->whereIn('id', $admittedLeadIds)->update([
                'status' => 'admitted',
                'admitted_at' => $now,
                'waitlisted_at' => null,
            ]);
        }
        $waitlistedLeadIds = $existingLeadIds->slice($capacity);
        if ($waitlistedLeadIds->isNotEmpty()) {
            DB::table('early_access_signups')->whereIn('id', $waitlistedLeadIds)->update([
                'status' => 'waitlisted',
                'waitlisted_at' => $now,
            ]);
        }

        DB::table('early_access_rollouts')->insert([
            'key' => 'public_beta',
            'capacity' => $capacity,
            'admitted_count' => $admittedLeadIds->count(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('early_access_rollouts');
        Schema::table('early_access_signups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['status', 'admitted_at', 'waitlisted_at', 'registered_at', 'notified_at']);
        });
    }
};
