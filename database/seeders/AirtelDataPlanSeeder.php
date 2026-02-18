<?php

namespace Database\Seeders;

use App\Models\DataPlan;
use Illuminate\Database\Seeder;

class AirtelDataPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // URL for Airtel Data variations
        $url = config('vtpass.base_url') . 'service-variations?serviceID=airtel-data';

        // Fetch data from API
        $response = @file_get_contents($url);

        if ($response === false) {
            $this->command->error("Failed to fetch data from VTpass API (Airtel).");
            return;
        }

        $data = json_decode($response, true);

        if (!isset($data['content']['variations'])) {
            $this->command->error("Invalid API response structure (Airtel).");
            return;
        }

        $variations = $data['content']['variations'];

        // Delete existing Airtel vtpass plans (datanetwork = 3)
        DataPlan::where('datanetwork', 3)->where('service_type', 'vtpass')->delete();

        $count = 0;
        foreach ($variations as $variation) {
            $name = $variation['name'];
            $variationCode = $variation['variation_code'];
            $variationAmount = floatval($variation['variation_amount']);

            // Determine type
            $type = (stripos($name, 'SME') !== false) ? 'SME' : 'Direct';

            // Extract days from name
            $day = 30; // Default
            if (preg_match('/(\d+)\s*days?/i', $name, $matches)) {
                $day = (int) $matches[1];
            }

            // Calculate prices with percentage increases
            $userPrice = $variationAmount * 1.015;   // 1.5% increase
            $agentPrice = $variationAmount * 1.01;   // 1% increase
            $vendorPrice = $variationAmount * 1.012; // 1.2% increase

            // Create DataPlan
            DataPlan::updateOrCreate([
                'planid' => $variationCode,
                'datanetwork' => 3,
            ], [
                'name' => $name,
                'price' => $variationAmount,
                'userprice' => number_format($userPrice, 2, '.', ''),
                'agentprice' => number_format($agentPrice, 2, '.', ''),
                'vendorprice' => number_format($vendorPrice, 2, '.', ''),
                'day' => $day,
                'type' => $type,
                'service_type' => 'vtpass',
            ]);

            $count++;
        }

        $this->command->info("Successfully seeded {$count} Airtel data plans from API.");
    }
}
