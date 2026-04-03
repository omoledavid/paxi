<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datadiscount', function (Blueprint $table) {
            $table->increments('dId');
            $table->integer('dNetwork')->unique();
            $table->decimal('dBuyDiscount', 5, 2)->default(100);
            $table->decimal('dUserDiscount', 5, 2)->default(100);
            $table->decimal('dAgentDiscount', 5, 2)->default(100);
            $table->decimal('dVendorDiscount', 5, 2)->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datadiscount');
    }
};
