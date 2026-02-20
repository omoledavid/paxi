<?php

namespace Database\Seeders;

use App\Models\DataPlan;
use Illuminate\Database\Seeder;

class GloDataPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Delete existing Glo vtpass plans (datanetwork = 2)
        DataPlan::where('datanetwork', 2)->where('service_type', 'vtpass')->delete();

        $this->seedFromFile('glo-data', 'Direct');
        $this->seedFromFile('glo-sme-data', 'SME');
    }

    private function seedFromFile($serviceId, $defaultType)
    {
        // URL for Glo variations
        $url = config('vtpass.base_url') . "service-variations?serviceID={$serviceId}";

        // Fetch data from API
        $response = @file_get_contents($url);

        if ($response === false) {
            $this->command->error("Failed to fetch data from VTpass API ({$serviceId}).");
            return;
        }

        $data = json_decode($response, true);

        if (!isset($data['content']['variations'])) {
            $this->command->error("Invalid API response structure ({$serviceId}).");
            return;
        }

        $variations = $data['content']['variations'];
        $count = 0;

        foreach ($variations as $variation) {
            $name = $variation['name'];
            $variationCode = $variation['variation_code'];
            $variationAmount = floatval($variation['variation_amount']);

            // Determine type - prioritize argument, but check name for SME if default is Direct
            $type = $defaultType;
            if ($defaultType === 'Direct' && stripos($name, 'SME') !== false) {
                $type = 'SME';
            }

            // Extract days from name
            $day = 30; // Default
            if (preg_match('/(\d+)\s*days?/i', $name, $matches)) {
                $day = (int) $matches[1];
            }

            // Calculate prices with percentage increases
            $userPrice = $variationAmount;   // 1.5% increase
            $agentPrice = $variationAmount;   // 1% increase
            $vendorPrice = $variationAmount; // 1.2% increase

            // Create DataPlan
            DataPlan::updateOrCreate([
                'planid' => $variationCode,
                'datanetwork' => 2,
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

        $this->command->info("Successfully seeded {$count} Glo plans from {$serviceId}.");
    }
}
