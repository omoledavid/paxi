<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epin_prices', function (Blueprint $table) {
            $table->id();
            $table->string('network_id', 5)->comment('NelloBytes network code: 01=MTN, 02=Glo, 03=9Mobile, 04=Airtel');
            $table->string('network_name', 20);
            $table->integer('amount')->comment('Face value denomination e.g. 100, 200, 500');
            $table->float('user_price')->default(0)->comment('Payable amount for User role');
            $table->float('agent_price')->default(0)->comment('Payable amount for Agent role');
            $table->float('vendor_price')->default(0)->comment('Payable amount for Vendor role');
            $table->timestamps();

            $table->unique(['network_id', 'amount']);
        });

        // Seed with current hardcoded rates from EpinController::applyProductDiscount
        // MTN=1.0, Glo=0.99, Airtel=0.98, 9Mobile=0.96
        $networks = [
            ['id' => '01', 'name' => 'MTN', 'rate' => 1.0],
            ['id' => '02', 'name' => 'Glo', 'rate' => 0.99],
            ['id' => '03', 'name' => '9Mobile', 'rate' => 0.96],
            ['id' => '04', 'name' => 'Airtel', 'rate' => 0.98],
        ];
        $amounts = [100, 200, 500];
        $now = now();

        foreach ($networks as $network) {
            foreach ($amounts as $amount) {
                $payable = $amount * $network['rate'];
                DB::table('epin_prices')->insert([
                    'network_id' => $network['id'],
                    'network_name' => $network['name'],
                    'amount' => $amount,
                    'user_price' => $payable,
                    'agent_price' => $payable,
                    'vendor_price' => $payable,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('epin_prices');
    }
};
