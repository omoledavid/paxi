<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sitesettings', function (Blueprint $table) {
            $table->decimal('ref_withdrawal_max_amount', 10, 2)->default(5000)->after('wallettowalletcharges');
            $table->unsignedInteger('ref_withdrawal_period_days')->default(7)->after('ref_withdrawal_max_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sitesettings', function (Blueprint $table) {
            $table->dropColumn(['ref_withdrawal_max_amount', 'ref_withdrawal_period_days']);
        });
    }
};
