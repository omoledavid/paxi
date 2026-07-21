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
        Schema::table('subscribers', function (Blueprint $table) {
            $table->boolean('can_transfer_to_bank')->default(false)->after('pnd_active');
            $table->boolean('can_add_bank_account')->default(false)->after('can_transfer_to_bank');
        });

        // Add global bank transfer settings to sitesettings
        if (Schema::hasTable('sitesettings')) {
            Schema::table('sitesettings', function (Blueprint $table) {
                $table->boolean('enable_bank_transfer')->default(false);
                $table->decimal('bank_transfer_fee', 10, 2)->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscribers', function (Blueprint $table) {
            $table->dropColumn(['can_transfer_to_bank', 'can_add_bank_account']);
        });

        if (Schema::hasTable('sitesettings')) {
            Schema::table('sitesettings', function (Blueprint $table) {
                $table->dropColumn(['enable_bank_transfer', 'bank_transfer_fee']);
            });
        }
    }
};
