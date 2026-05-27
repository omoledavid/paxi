<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cablediscount', function (Blueprint $table) {
            $table->increments('cdId');
            $table->integer('cableProvider')->unique();
            $table->decimal('buyDiscount', 5, 2)->default(100);
            $table->decimal('userDiscount', 5, 2)->default(100);
            $table->decimal('agentDiscount', 5, 2)->default(100);
            $table->decimal('vendorDiscount', 5, 2)->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cablediscount');
    }
};
