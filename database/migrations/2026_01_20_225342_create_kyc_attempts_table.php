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
        Schema::create('kyc_attempts', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('job_id')->unique();
            $table->enum('status', ['pending', 'approved', 'rejected', 'failed'])->default('pending');
            $table->string('product_type')->default('verification');
            $table->integer('template_id')->nullable();
            $table->string('nin')->nullable();
            $table->boolean('face_match')->default(false);
            $table->boolean('nin_match')->default(false);
            $table->float('liveness_score')->nullable();
            $table->float('confidence_value')->nullable();
            $table->json('result_json')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('sId')->on('subscribers')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_attempts');
    }
};
