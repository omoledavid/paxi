<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert the transactions and subscribers tables from latin1 to utf8mb4
     * so that multi-byte characters (e.g. ₦, emoji) can be stored without error.
     */
    public function up(): void
    {
        // transactions — servicename / servicedesc carry ₦ symbols and Unicode text
        DB::statement(
            'ALTER TABLE `transactions` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        // subscribers — future-proof names, emails and any text fields
        DB::statement(
            'ALTER TABLE `subscribers` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE `transactions` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci'
        );

        DB::statement(
            'ALTER TABLE `subscribers` CONVERT TO CHARACTER SET latin1 COLLATE latin1_swedish_ci'
        );
    }
};
