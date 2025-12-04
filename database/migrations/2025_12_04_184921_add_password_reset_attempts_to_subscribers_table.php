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
            $table->renameColumn('sVerificationAttempts', 'sEmailVerificationAttempts');
            $table->renameColumn('sVerificationAttemptsResetAt', 'sEmailVerificationAttemptsResetAt');
            $table->unsignedTinyInteger('sPasswordResetAttempts')->default(0)->after('sEmailVerificationAttemptsResetAt');
            $table->timestamp('sPasswordResetAttemptsResetAt')->nullable()->after('sPasswordResetAttempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['sPasswordResetAttempts', 'sPasswordResetAttemptsResetAt']);
            $table->renameColumn('sEmailVerificationAttempts', 'sVerificationAttempts');
            $table->renameColumn('sEmailVerificationAttemptsResetAt', 'sVerificationAttemptsResetAt');
        });
    }
};
