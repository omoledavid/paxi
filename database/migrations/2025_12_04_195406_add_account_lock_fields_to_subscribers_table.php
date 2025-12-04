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
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('sRegStatus');
            $table->timestamp('locked_at')->nullable()->after('failed_login_attempts');
            $table->timestamp('locked_until')->nullable()->after('locked_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_at', 'locked_until']);
        });
    }
};
