<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 10);
            $table->string('kind', 10);
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            // The bearer token the phone signed in with. Revoking it (logout,
            // password reset) takes the phone's push tokens with it.
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->constrained('personal_access_tokens')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_push_tokens');
    }
};
