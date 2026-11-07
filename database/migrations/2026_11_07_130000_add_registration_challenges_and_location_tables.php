<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_challenges', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->index();
            $table->string('password');
            $table->string('workspace_name');
            $table->string('timezone')->default('UTC');
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->boolean('location_restricted')->default(false);
            $table->decimal('location_latitude', 10, 7)->nullable();
            $table->decimal('location_longitude', 10, 7)->nullable();
            $table->unsignedInteger('location_radius_m')->default(250);
        });

        Schema::table('attendance_days', function (Blueprint $table) {
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('extension', 24)->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn(['check_in_latitude', 'check_in_longitude', 'check_out_latitude', 'check_out_longitude']);
        });
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['location_restricted', 'location_latitude', 'location_longitude', 'location_radius_m']);
        });
        Schema::dropIfExists('registration_challenges');
    }
};
