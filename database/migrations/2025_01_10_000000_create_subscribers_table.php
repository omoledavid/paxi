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
        if (Schema::hasTable('subscribers')) {
            return;
        }

        Schema::create('subscribers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->increments('sId');
            $table->string('sFname');
            $table->string('sLname');
            $table->string('sEmail');
            $table->string('sPhone');
            $table->string('sPass');
            $table->string('sState')->nullable();
            $table->unsignedTinyInteger('sType')->default(0);
            $table->string('sApiKey')->nullable();
            $table->string('sReferal')->nullable();
            $table->string('sPin')->nullable();
            $table->unsignedMediumInteger('sVerCode')->nullable();
            $table->unsignedTinyInteger('sRegStatus')->default(0);
            $table->string('sBankName')->nullable();
            $table->string('sBankNo')->nullable();
            $table->decimal('sWallet', 14, 2)->default(0);
            $table->decimal('sRefWallet', 14, 2)->default(0);
            $table->string('sRolexBank')->nullable();
            $table->string('sSterlingBank')->nullable();
            $table->string('sFidelityBank')->nullable();
            $table->timestamp('sRegDate')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};

