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
        if (Schema::hasTable('transactions')) {
            return;
        }

        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('tId');
            $table->unsignedBigInteger('sId');
            $table->string('transref');
            $table->string('servicename');
            $table->text('servicedesc')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->decimal('oldbal', 14, 2)->default(0);
            $table->decimal('newbal', 14, 2)->default(0);
            $table->decimal('profit', 14, 2)->default(0);
            $table->timestamp('date')->nullable();
            $table->timestamps();

            $table->index('sId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
