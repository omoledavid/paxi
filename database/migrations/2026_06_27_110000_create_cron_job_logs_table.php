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
        Schema::create('cron_job_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_name', 100)->index();
            $table->string('status', 20)->default('running'); // running, success, failed
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('users_checked')->default(0);
            $table->unsignedInteger('users_credited')->default(0);
            $table->text('error_message')->nullable();
            $table->string('triggered_by', 20)->default('cron'); // cron, manual
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['job_name', 'started_at']);
            $table->index(['job_name', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cron_job_logs');
    }
};
