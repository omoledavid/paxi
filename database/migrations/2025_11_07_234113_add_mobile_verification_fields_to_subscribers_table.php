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
            $table->unsignedMediumInteger('sMobileVerCode')->nullable()->after('sVerCodeExpiry');
            $table->timestamp('sMobileVerCodeExpiry')->nullable()->after('sMobileVerCode');
            $table->boolean('sMobileVerified')->default(false)->after('sMobileVerCodeExpiry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['sMobileVerCode', 'sMobileVerCodeExpiry', 'sMobileVerified']);
        });
    }
};
