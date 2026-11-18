<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('started_by')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 12)->default('audio');
            $table->string('status', 12)->default('live');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['channel_id', 'status']);
        });

        Schema::create('call_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['call_id', 'user_id']);
        });

        Schema::create('call_signals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 16);
            $table->json('payload');
            $table->timestamps();

            $table->index(['call_id', 'to_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_signals');
        Schema::dropIfExists('call_participants');
        Schema::dropIfExists('calls');
    }
};
