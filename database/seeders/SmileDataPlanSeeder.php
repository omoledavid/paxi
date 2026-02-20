<?php

namespace Database\Seeders;

use App\Models\DataPlan;
use Illuminate\Database\Seeder;

class SmileDataPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // URL for Smile Direct variations
        $url = config('vtpass.base_url') . 'service-variations?serviceID=smile-direct';

        // Fetch data from API
        $response = @file_get_contents($url);

        if ($response === false) {
            $this->command->error("Failed to fetch data from VTpass API.");
            return;
        }

        $data = json_decode($response, true);

        if (!isset($data['content']['variations'])) {
            $this->command->error("Invalid API response structure.");
            return;
        }

        $variations = $data['content']['variations'];

        // Delete existing Smile plans (datanetwork = 5)
        DataPlan::where('datanetwork', 5)->delete();

        $count = 0;
        foreach ($variations as $variation) {
            $name = $variation['name'];
            $variationCode = $variation['variation_code'];
            $variationAmount = floatval($variation['variation_amount']);

            // Exclude variations with "SmileVoice" in the name
            if (stripos($name, 'SmileVoice') !== false) {
                continue;
            }

            // Extract days from name (e.g., "for 30days")
            $day = 30; // Default
            if (preg_match('/(\d+)\s*days/i', $name, $matches)) {
                $day = (int) $matches[1];
            }

            // Calculate prices with percentage increases
            $userPrice = $variationAmount;   // 1.5% increase
            $agentPrice = $variationAmount;   // 1% increase
            $vendorPrice = $variationAmount; // 1.2% increase

            // Create DataPlan
            DataPlan::updateOrCreate([
                'planid' => $variationCode,
                'name' => $name,
                'price' => $variationAmount,
                'userprice' => number_format($userPrice, 2, '.', ''),
                'agentprice' => number_format($agentPrice, 2, '.', ''),
                'vendorprice' => number_format($vendorPrice, 2, '.', ''),
                'day' => $day,
                'type' => 'SME',
                'datanetwork' => 5,
                'service_type' => 'vtpass',
            ]);

            $count++;
        }

        $this->command->info("Successfully seeded {$count} Smile data plans from API.");
    }
}
