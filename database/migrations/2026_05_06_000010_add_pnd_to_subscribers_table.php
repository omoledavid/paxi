<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->tinyInteger('pnd_active')->default(0)->after('is_banned');
        });
    }

    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn('pnd_active');
        });
    }
};
