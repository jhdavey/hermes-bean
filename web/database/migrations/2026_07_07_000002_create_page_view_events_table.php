<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_view_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_key', 80)->nullable()->index();
            $table->string('ip_hash', 64)->nullable()->index();
            $table->string('method', 12)->default('GET');
            $table->string('path', 255)->index();
            $table->string('route_name', 120)->nullable()->index();
            $table->string('referrer', 1024)->nullable();
            $table->string('utm_source', 120)->nullable()->index();
            $table->string('utm_medium', 120)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->timestamps();

            $table->index(['created_at', 'path']);
            $table->index(['created_at', 'visitor_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_view_events');
    }
};
