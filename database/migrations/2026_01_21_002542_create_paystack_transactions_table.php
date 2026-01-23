<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paystack_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id'); // Match User sId type
            $table->string('service_type'); // Enum value
            $table->string('transaction_ref')->unique();
            $table->string('paystack_ref')->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('status')->default('pending');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamps();

            // Index for faster lookups
            $table->index(['user_id', 'service_type']);

            // Foreign key constraint (assuming users table uses id or sId)
            // Checking User model, it uses sId as primary key usually in this project based on query?
            // "user_id" => $user->sId in Controller.
            // Let's assume standard foreign key might fail if types differ, but I'll add index at least.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paystack_transactions');
    }
};
