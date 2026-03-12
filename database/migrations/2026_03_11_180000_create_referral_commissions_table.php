<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('referral_commissions', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('role')->unique()->comment('0=User, 2=Agent, 3=Vendor');
            $table->string('role_name', 20);
            $table->float('upgrade_bonus')->default(0);
            $table->float('airtime_bonus')->default(0);
            $table->float('data_bonus')->default(0);
            $table->float('wallet_bonus')->default(0);
            $table->float('cable_bonus')->default(0);
            $table->float('exam_bonus')->default(0);
            $table->float('meter_bonus')->default(0);
            $table->timestamps();
        });

        // Seed with current sitesettings values for all 3 roles
        $settings = DB::table('sitesettings')->first();

        $defaultValues = [
            'upgrade_bonus' => $settings->referalupgradebonus ?? 0,
            'airtime_bonus' => $settings->referalairtimebonus ?? 0,
            'data_bonus' => $settings->referaldatabonus ?? 0,
            'wallet_bonus' => $settings->referalwalletbonus ?? 0,
            'cable_bonus' => $settings->referalcablebonus ?? 0,
            'exam_bonus' => $settings->referalexambonus ?? 0,
            'meter_bonus' => $settings->referalmeterbonus ?? 0,
        ];

        $now = now();

        DB::table('referral_commissions')->insert([
            array_merge(['role' => 0, 'role_name' => 'User', 'created_at' => $now, 'updated_at' => $now], $defaultValues),
            array_merge(['role' => 2, 'role_name' => 'Agent', 'created_at' => $now, 'updated_at' => $now], $defaultValues),
            array_merge(['role' => 3, 'role_name' => 'Vendor', 'created_at' => $now, 'updated_at' => $now], $defaultValues),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referral_commissions');
    }
};
