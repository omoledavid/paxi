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
        Schema::create('epins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // References subscribers.sId
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('network');
            $table->decimal('amount', 10, 2);
            $table->string('pin_code');
            $table->string('serial_number')->nullable();
            $table->dateTime('expiry_date')->nullable();
            $table->string('status')->default('unused'); // unused, used
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('epins');
    }
};
