<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epins', function (Blueprint $table) {
            // Date printed on a physical slip. Deliberately separate from expiry_date:
            // vendor slips print the sale date, not an expiry.
            $table->timestamp('printed_at')->nullable()->after('expiry_date');
            $table->string('entry_method')->nullable()->after('purchased_by_admin_id');
            $table->index('pin_code', 'epins_pin_code_index');
        });

        // A unique index is the only true guard against a double-submit racing the
        // application-level duplicate check. Existing rows may carry the literal
        // 'UNKNOWN' placeholder written when a provider response is missing a pin,
        // so skip the index rather than break a deploy on live data.
        $duplicates = DB::table('epins')
            ->select('pin_code')
            ->groupBy('pin_code')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($duplicates > 0) {
            Log::warning('Skipped unique index on epins.pin_code: duplicate pin codes already exist', [
                'duplicate_pin_codes' => $duplicates,
            ]);

            return;
        }

        Schema::table('epins', function (Blueprint $table) {
            $table->unique('pin_code', 'epins_pin_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('epins', function (Blueprint $table) {
            $table->dropIndex('epins_pin_code_index');
            $table->dropColumn(['printed_at', 'entry_method']);
        });

        if (Schema::hasTable('epins')) {
            try {
                Schema::table('epins', function (Blueprint $table) {
                    $table->dropUnique('epins_pin_code_unique');
                });
            } catch (Throwable $e) {
                // Index was never created because duplicates existed at migrate time.
            }
        }
    }
};
