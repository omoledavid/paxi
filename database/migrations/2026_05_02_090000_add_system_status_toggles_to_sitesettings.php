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
        Schema::table('sitesettings', function (Blueprint $table) {
            $table->tinyInteger('disable_login')->default(0)->after('airtimemax');
            $table->tinyInteger('disable_signup')->default(0)->after('disable_login');
            $table->tinyInteger('disable_transactions')->default(0)->after('disable_signup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sitesettings', function (Blueprint $table) {
            $table->dropColumn(['disable_login', 'disable_signup', 'disable_transactions']);
        });
    }
};
