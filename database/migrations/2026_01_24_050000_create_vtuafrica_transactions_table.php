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
        Schema::create('vtuafrica_transactions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('service_type', 50);
            $table->string('transaction_ref', 100)->unique();
            $table->string('provider_ref', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('pending');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('error_message')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('sId')
                ->on('subscribers')
                ->onDelete('cascade');

            $table->index(['user_id', 'status']);
            $table->index('transaction_ref');
            $table->index('provider_ref');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vtuafrica_transactions');
    }
};
