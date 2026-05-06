<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_banners', function (Blueprint $table) {
            $table->id();
            $table->string('image')->nullable();
            $table->string('link_url', 500)->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });

        DB::table('ad_banners')->insert([
            'id'         => 1,
            'active'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_banners');
    }
};
