<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('electricity_discounts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('provider_id')->unique();
            $table->enum('discount_type', ['percentage', 'flat'])->default('percentage');
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->decimal('buy_discount', 10, 2)->default(0);
            $table->decimal('user_discount', 10, 2)->default(0);
            $table->decimal('agent_discount', 10, 2)->default(0);
            $table->decimal('vendor_discount', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('electricity_discounts');
    }
};
