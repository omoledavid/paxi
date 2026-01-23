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
        if (Schema::hasTable('vtpass_transactions')) {
            return;
        }

        Schema::create('vtpass_transactions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->string('service_type'); // airtime, data, tv-subscription, education, electricity
            $table->string('transaction_ref')->unique();
            $table->string('vtpass_ref')->nullable(); // RequestId from VTpass
            $table->decimal('amount', 14, 2)->default(0);
            $table->string('status')->default('pending'); // pending, success, failed, cancelled
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_code')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('service_type');
            $table->index('status');
            $table->index('transaction_ref');
            $table->index('vtpass_ref');
            $table->index('created_at');

            // Assuming 'subscribers' and 'sId' based on previous context
            $table->foreign('user_id')
                ->references('sId')
                ->on('subscribers')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vtpass_transactions');
    }
};
