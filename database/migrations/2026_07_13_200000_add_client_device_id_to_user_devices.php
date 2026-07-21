<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('client_device_id', 64)->nullable()->after('device_hash');
            $table->index(['user_id', 'client_device_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'client_device_id']);
            $table->dropColumn('client_device_id');
        });
    }
};
