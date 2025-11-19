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
        if (!Schema::hasColumn('subscribers', 'created_at')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->timestamp('created_at')->nullable();
            });
        }

        if (!Schema::hasColumn('subscribers', 'updated_at')) {
            Schema::table('subscribers', function (Blueprint $table) {
                $table->timestamp('updated_at')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $columns = collect(['created_at', 'updated_at'])->filter(fn ($column) => Schema::hasColumn('subscribers', $column));

        if ($columns->isNotEmpty()) {
            Schema::table('subscribers', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns->all());
            });
        }
    }
};
