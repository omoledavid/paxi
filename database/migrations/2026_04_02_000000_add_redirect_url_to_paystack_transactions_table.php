<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->string('redirect_url')->nullable()->after('response_payload');
        });
    }

    public function down(): void
    {
        Schema::table('paystack_transactions', function (Blueprint $table) {
            $table->dropColumn('redirect_url');
        });
    }
};
