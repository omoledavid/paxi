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
        Schema::table('referral_commissions', function (Blueprint $table) {
            // When a referrer's sRefWallet reaches this amount, it auto-sweeps to sWallet.
            // 0 = disabled (no auto-payout).
            $table->float('auto_payout_threshold')->default(0)->after('min_transaction_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_commissions', function (Blueprint $table) {
            $table->dropColumn('auto_payout_threshold');
        });
    }
};
