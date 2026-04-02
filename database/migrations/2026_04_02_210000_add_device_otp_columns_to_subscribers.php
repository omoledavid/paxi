<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->string('device_otp', 6)->nullable()->after('sRegStatus');
            $table->timestamp('device_otp_expires_at')->nullable()->after('device_otp');
            $table->string('device_verification_token', 64)->nullable()->unique()->after('device_otp_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['device_otp', 'device_otp_expires_at', 'device_verification_token']);
        });
    }
};
