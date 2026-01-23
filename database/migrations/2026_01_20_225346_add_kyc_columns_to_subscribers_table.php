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
            $table->enum('kyc_status', ['pending', 'approved', 'rejected', 'failed'])->default('pending')->after('sEmail'); // Adjust 'after' if needed
            $table->string('kyc_provider')->nullable();
            $table->string('kyc_job_id')->nullable();
            $table->timestamp('kyc_approved_at')->nullable();
            $table->string('nin_verified')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['kyc_status', 'kyc_provider', 'kyc_job_id', 'kyc_approved_at', 'nin_verified']);
        });
    }
};
