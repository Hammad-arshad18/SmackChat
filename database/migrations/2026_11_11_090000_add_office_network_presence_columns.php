<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('location_allowed_ips')->nullable()->after('location_radius_m');
        });

        Schema::table('attendance_days', function (Blueprint $table) {
            $table->string('check_in_ip', 45)->nullable()->after('check_in_longitude');
            $table->string('check_out_ip', 45)->nullable()->after('check_out_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn(['check_in_ip', 'check_out_ip']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('location_allowed_ips');
        });
    }
};
