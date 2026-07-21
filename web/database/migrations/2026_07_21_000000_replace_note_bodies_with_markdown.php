<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->longText('body_markdown')->nullable()->after('title');
        });

        DB::table('notes')->whereNotNull('plain_text')->update([
            'body_markdown' => DB::raw('plain_text'),
        ]);

        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn(['body_html', 'body_delta']);
        });
    }

    public function down(): void
    {
        Schema::table('notes', function (Blueprint $table): void {
            $table->longText('body_html')->nullable()->after('title');
            $table->json('body_delta')->nullable()->after('plain_text');
        });

        Schema::table('notes', function (Blueprint $table): void {
            $table->dropColumn('body_markdown');
        });
    }
};
