<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEFAULT_CATEGORY_COLOR = '#34C759';

    public function up(): void
    {
        foreach (['calendar_events', 'tasks', 'reminders'] as $table) {
            DB::table($table)
                ->whereNull('category')
                ->where(function ($query): void {
                    $query->whereNull('color')
                        ->orWhere('color', '');
                })
                ->update(['color' => self::DEFAULT_CATEGORY_COLOR]);
        }
    }

    public function down(): void
    {
        foreach (['calendar_events', 'tasks', 'reminders'] as $table) {
            DB::table($table)
                ->whereNull('category')
                ->where('color', self::DEFAULT_CATEGORY_COLOR)
                ->update(['color' => null]);
        }
    }
};
