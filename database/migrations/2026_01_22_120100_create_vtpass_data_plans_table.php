<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vtpass_data_plans')) {
            return;
        }

        Schema::create('vtpass_data_plans', function (Blueprint $table) {
            $table->id();
            $table->string('service_id'); // mtn-data, airtel-data
            $table->string('plan_code'); // variation_code from vtpass
            $table->string('name');
            $table->decimal('amount', 14, 2); // Price from vtpass
            $table->decimal('userprice', 14, 2); // Price we sell at
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vtpass_data_plans');
    }
};
