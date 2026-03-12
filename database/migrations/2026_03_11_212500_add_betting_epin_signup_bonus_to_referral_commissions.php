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
            $table->float('betting_bonus')->default(0)->after('meter_bonus');
            $table->float('epin_bonus')->default(0)->after('betting_bonus');
            $table->float('referral_signup_bonus')->default(0)->after('epin_bonus');
            $table->float('min_transaction_amount')->default(0)->after('referral_signup_bonus');
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->tinyInteger('referral_bonus_credited')->default(0)->after('sRefWallet');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('referral_commissions', function (Blueprint $table) {
            $table->dropColumn(['betting_bonus', 'epin_bonus', 'referral_signup_bonus', 'min_transaction_amount']);
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn('referral_bonus_credited');
        });
    }
};
