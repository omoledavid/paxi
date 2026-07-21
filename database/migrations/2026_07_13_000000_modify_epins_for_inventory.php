<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epins', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('source')->default('user_purchase')->after('network')->index();
            $table->string('batch_reference')->nullable()->after('source')->index();
            $table->decimal('purchase_cost', 10, 2)->nullable()->after('amount');
            $table->timestamp('disbursed_at')->nullable()->after('status');
            $table->unsignedInteger('purchased_by_admin_id')->nullable()->after('disbursed_at');
        });

        $exists = DB::table('apiconfigs')->where('name', 'epinSourceMode')->exists();
        if (! $exists) {
            DB::table('apiconfigs')->insert([
                'name' => 'epinSourceMode',
                'value' => 'nellobytes',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('epins', function (Blueprint $table) {
            $table->dropColumn([
                'source',
                'batch_reference',
                'purchase_cost',
                'disbursed_at',
                'purchased_by_admin_id',
            ]);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        DB::table('apiconfigs')->where('name', 'epinSourceMode')->delete();
    }
};
