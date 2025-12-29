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
        if (Schema::hasTable('nb_data_plans')) {
            return;
        }

        Schema::create('nb_data_plans', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('pId');
            $table->string('plan_code', 50)->nullable();
            $table->string('name');
            $table->decimal('userprice', 14, 2);
            $table->string('type', 50)->nullable();
            $table->unsignedInteger('day')->nullable();
            $table->unsignedInteger('datanetwork');
            $table->string('data_size', 100)->nullable();
            $table->timestamps();

            $table->foreign('datanetwork')->references('nId')->on('networkid')->onDelete('cascade');
            $table->index(['datanetwork', 'plan_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nb_data_plans');
    }
};
