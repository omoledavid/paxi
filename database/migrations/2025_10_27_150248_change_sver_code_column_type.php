<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Change sVerCode from SMALLINT to MEDIUMINT UNSIGNED to accommodate 6-digit codes (100,000 - 999,999)
            $table->unsignedMediumInteger('sVerCode')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            // Revert to SMALLINT if needed
            $table->smallInteger('sVerCode')->nullable()->change();
        });
    }
};
