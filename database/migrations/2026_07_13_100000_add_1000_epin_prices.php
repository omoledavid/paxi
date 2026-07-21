<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $networks = [
            ['id' => '01', 'name' => 'MTN', 'rate' => 1.0],
            ['id' => '02', 'name' => 'Glo', 'rate' => 0.99],
            ['id' => '03', 'name' => '9Mobile', 'rate' => 0.96],
            ['id' => '04', 'name' => 'Airtel', 'rate' => 0.98],
        ];
        $now = now();

        foreach ($networks as $network) {
            $payable = 1000 * $network['rate'];
            DB::table('epin_prices')->insertOrIgnore([
                'network_id' => $network['id'],
                'network_name' => $network['name'],
                'amount' => 1000,
                'user_price' => $payable,
                'agent_price' => $payable,
                'vendor_price' => $payable,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('epin_prices')->where('amount', 1000)->delete();
    }
};
