<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->unsignedTinyInteger('sVerificationAttempts')->default(0)->after('sMobileVerified');
            $table->timestamp('sVerificationAttemptsResetAt')->nullable()->after('sVerificationAttempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['sVerificationAttempts', 'sVerificationAttemptsResetAt']);
        });
    }
};
