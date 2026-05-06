<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sitesettings', function (Blueprint $table) {
            $table->tinyInteger('require_kyc_for_transactions')->default(1)->after('disable_transactions');
            $table->decimal('daily_txn_limit_user', 15, 2)->default(100000)->after('require_kyc_for_transactions');
            $table->decimal('daily_txn_limit_agent', 15, 2)->default(100000)->after('daily_txn_limit_user');
            $table->decimal('daily_txn_limit_vendor', 15, 2)->default(100000)->after('daily_txn_limit_agent');
            $table->unsignedInteger('signup_ip_max')->default(5)->after('daily_txn_limit_vendor');
            $table->unsignedInteger('signup_ip_window_hours')->default(5)->after('signup_ip_max');
            $table->unsignedInteger('signup_ip_block_hours')->default(5)->after('signup_ip_window_hours');
            $table->unsignedInteger('bot_txn_threshold')->default(5)->after('signup_ip_block_hours');
            $table->unsignedInteger('bot_txn_window_seconds')->default(120)->after('bot_txn_threshold');
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->tinyInteger('is_banned')->default(0);
            $table->timestamp('banned_at')->nullable();
            $table->string('banned_reason', 255)->nullable();
        });

        Schema::create('signup_ip_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('blocked_ips', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->timestamp('blocked_until')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('security_violations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip', 45)->nullable()->index();
            $table->string('type', 32)->index();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::table('sitesettings', function (Blueprint $table) {
            $table->dropColumn([
                'require_kyc_for_transactions',
                'daily_txn_limit_user',
                'daily_txn_limit_agent',
                'daily_txn_limit_vendor',
                'signup_ip_max',
                'signup_ip_window_hours',
                'signup_ip_block_hours',
                'bot_txn_threshold',
                'bot_txn_window_seconds',
            ]);
        });

        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['is_banned', 'banned_at', 'banned_reason']);
        });

        Schema::dropIfExists('signup_ip_attempts');
        Schema::dropIfExists('blocked_ips');
        Schema::dropIfExists('security_violations');
    }
};
