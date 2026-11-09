<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('cipher', 16)->default('server')->after('ciphertext');
        });

        Schema::create('e2e_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('public_key', 120);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique('public_key');
            $table->index('user_id');
        });

        Schema::create('channel_key_wraps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('e2e_devices')->cascadeOnDelete();
            $table->text('wrapped');
            $table->timestamps();
            $table->unique(['channel_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_key_wraps');
        Schema::dropIfExists('e2e_devices');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('cipher');
        });
    }
};
