<?php

namespace Database\Seeders;

use App\Models\DataPlan;
use Illuminate\Database\Seeder;

class VtuAfricaDataPlanSeeder extends Seeder
{
    /**
     * Network ID mappings - These should match your networks table
     */
    private array $networkIds = [
        'MTN' => 1,
        'Airtel' => 3,
        'Glo' => 2,
        '9mobile' => 4,
    ];

    /**
     * Service type for VTU Africa
     */
    private const SERVICE_TYPE = 'vtuafrica';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedMTNPlans();
        $this->seedAirtelPlans();
        $this->seedGloPlans();
        $this->seed9mobilePlans();
    }

    private function seedMTNPlans(): void
    {
        $plans = [
            // MTN SME Data - Service: MTNSME
            ['MTNSME', '500W', '500MB', '490', '550', '520', '500', 'SME', 7],
            ['MTNSME', '1000D', '1GB', '780', '850', '820', '800', 'SME', 7],
            ['MTNSME', '2000', '2GB', '1540', '1650', '1600', '1560', 'SME', 30],
            ['MTNSME', '3000', '3GB', '2310', '2450', '2400', '2350', 'SME', 30],
            ['MTNSME', '3500', '3.5GB', '2450', '2600', '2550', '2500', 'SME', 30],
            ['MTNSME', '5000', '5GB', '3850', '4100', '4000', '3900', 'SME', 30],
            ['MTNSME', '10000', '10GB', '4470', '4800', '4650', '4550', 'SME', 30],

            // MTN Awoof Data - Service: MTNAWOOF
            ['MTNAWOOF', '500D', '500MB', '690', '750', '720', '700', 'Awoof', 1],
            ['MTNAWOOF', '1400W', '1.4GB', '1756', '1900', '1850', '1800', 'Awoof', 7],
            ['MTNAWOOF', '7000', '7GB', '6860', '7200', '7050', '6950', 'Awoof', 30],

            // MTN Gifting Data - Service: MTNGIFT
            ['MTNGIFT', '1000D', '1GB', '490', '550', '520', '500', 'Gifting', 1],
            ['MTNGIFT', '2000', '2GB', '1465', '1600', '1550', '1500', 'Gifting', 30],
            ['MTNGIFT', '3000', '3GB', '2190', '2350', '2300', '2250', 'Gifting', 30],
            ['MTNGIFT', '5000', '5GB', '3650', '3900', '3800', '3700', 'Gifting', 30],

            // MTN Corporate Data - Service: MTNCG
            ['MTNCG', '500', '500MB', '485', '550', '520', '500', 'Corporate', 30],
            ['MTNCG', '1000', '1GB', '970', '1100', '1050', '1000', 'Corporate', 30],
            ['MTNCG', '2000', '2GB', '1940', '2100', '2050', '2000', 'Corporate', 30],
            ['MTNCG', '3000', '3GB', '2910', '3100', '3050', '3000', 'Corporate', 30],
            ['MTNCG', '5000', '5GB', '4850', '5200', '5100', '4950', 'Corporate', 30],
            ['MTNCG', '10000', '10GB', '9700', '10500', '10200', '9900', 'Corporate', 30],
        ];

        $this->insertPlans($plans, 'MTN');
    }

    private function seedAirtelPlans(): void
    {
        $plans = [
            // Airtel SME Data - Service: AIRTELSME
            ['AIRTELSME', '1000D', '1GB', '358', '400', '380', '370', 'SME', 1],
            ['AIRTELSME', '2000D', '2GB', '715', '800', '760', '740', 'SME', 2],
            ['AIRTELSME', '3000W', '3GB', '1070', '1200', '1150', '1100', 'SME', 7],
            ['AIRTELSME', '5000W', '5GB', '1785', '2000', '1900', '1850', 'SME', 7],
            ['AIRTELSME', '10000', '10GB', '3100', '3400', '3300', '3200', 'SME', 30],
            ['AIRTELSME', '15000', '15GB', '4650', '5000', '4850', '4750', 'SME', 30],
            ['AIRTELSME', '20000', '20GB', '6200', '6600', '6450', '6300', 'SME', 30],

            // Airtel Corporate Data - Service: AIRTELCG
            ['AIRTELCG', '500', '500MB', '490', '550', '520', '510', 'Corporate', 30],
            ['AIRTELCG', '1000', '1GB', '980', '1100', '1050', '1020', 'Corporate', 30],
            ['AIRTELCG', '2000', '2GB', '1960', '2150', '2100', '2020', 'Corporate', 30],
            ['AIRTELCG', '5000', '5GB', '4900', '5300', '5150', '5000', 'Corporate', 30],
            ['AIRTELCG', '10000', '10GB', '9800', '10500', '10200', '10000', 'Corporate', 30],

            // Airtel Gifting Data - Service: AIRTELGIFT
            ['AIRTELGIFT', '1000D', '1GB', '400', '480', '450', '430', 'Gifting', 1],
            ['AIRTELGIFT', '1500D', '1.5GB', '595', '700', '650', '620', 'Gifting', 2],
            ['AIRTELGIFT', '2000', '2GB', '1485', '1650', '1600', '1550', 'Gifting', 30],
            ['AIRTELGIFT', '3000', '3GB', '2225', '2450', '2350', '2300', 'Gifting', 30],
            ['AIRTELGIFT', '5000', '5GB', '3700', '4000', '3900', '3800', 'Gifting', 30],
        ];

        $this->insertPlans($plans, 'Airtel');
    }

    private function seedGloPlans(): void
    {
        $plans = [
            // Glo SME Data - Service: GLOSME
            ['GLOSME', '1500D', '1.5GB', '300', '350', '330', '320', 'SME', 1],
            ['GLOSME', '2500D', '2.5GB', '500', '580', '550', '530', 'SME', 2],
            ['GLOSME', '5000D', '5GB', '1000', '1150', '1100', '1050', 'SME', 3],
            ['GLOSME', '10000W', '10GB', '2000', '2300', '2200', '2100', 'SME', 7],
            ['GLOSME', '15000', '15GB', '3000', '3400', '3250', '3100', 'SME', 30],
            ['GLOSME', '20000', '20GB', '4000', '4500', '4300', '4150', 'SME', 30],

            // Glo Corporate Data - Service: GLOCG
            ['GLOCG', '500', '500MB', '204', '250', '230', '220', 'Corporate', 30],
            ['GLOCG', '1000', '1GB', '408', '480', '450', '430', 'Corporate', 30],
            ['GLOCG', '2000', '2GB', '816', '950', '900', '860', 'Corporate', 30],
            ['GLOCG', '3000', '3GB', '1224', '1400', '1350', '1280', 'Corporate', 30],
            ['GLOCG', '5000', '5GB', '2040', '2350', '2250', '2150', 'Corporate', 30],
            ['GLOCG', '10000', '10GB', '4080', '4600', '4400', '4250', 'Corporate', 30],

            // Glo Gifting Data - Service: GLOGIFT
            ['GLOGIFT', '1050', '1.05GB', '280', '340', '320', '300', 'Gifting', 14],
            ['GLOGIFT', '2100', '2.1GB', '560', '650', '620', '590', 'Gifting', 30],
            ['GLOGIFT', '3900', '3.9GB', '945', '1100', '1050', '1000', 'Gifting', 30],
            ['GLOGIFT', '5250', '5.25GB', '1260', '1450', '1400', '1330', 'Gifting', 30],
            ['GLOGIFT', '8400', '8.4GB', '1890', '2150', '2050', '1970', 'Gifting', 30],
        ];

        $this->insertPlans($plans, 'Glo');
    }

    private function seed9mobilePlans(): void
    {
        $plans = [
            // 9Mobile SME Data - Service: 9MOBILESME
            ['9MOBILESME', '500', '500MB', '135', '165', '150', '145', 'SME', 30],
            ['9MOBILESME', '1000', '1GB', '268', '320', '300', '285', 'SME', 30],
            ['9MOBILESME', '2000', '2GB', '535', '620', '590', '570', 'SME', 30],
            ['9MOBILESME', '3000', '3GB', '800', '930', '880', '850', 'SME', 30],
            ['9MOBILESME', '5000', '5GB', '1340', '1550', '1480', '1420', 'SME', 30],
            ['9MOBILESME', '10000', '10GB', '2680', '3100', '2950', '2800', 'SME', 30],
            ['9MOBILESME', '15000', '15GB', '3100', '3550', '3400', '3250', 'SME', 30],
            ['9MOBILESME', '20000', '20GB', '4800', '5500', '5200', '5000', 'SME', 30],

            // 9Mobile Corporate Data - Service: 9MOBILECG
            ['9MOBILECG', '500', '500MB', '145', '175', '165', '155', 'Corporate', 30],
            ['9MOBILECG', '1000', '1GB', '285', '340', '320', '305', 'Corporate', 30],
            ['9MOBILECG', '2000', '2GB', '570', '670', '640', '610', 'Corporate', 30],
            ['9MOBILECG', '3000', '3GB', '855', '1000', '950', '910', 'Corporate', 30],
            ['9MOBILECG', '5000', '5GB', '1425', '1650', '1580', '1510', 'Corporate', 30],
            ['9MOBILECG', '10000', '10GB', '2850', '3300', '3150', '3000', 'Corporate', 30],

            // 9Mobile Gifting Data - Service: 9MOBILEGIFT
            ['9MOBILEGIFT', '500', '500MB', '385', '450', '425', '405', 'Gifting', 30],
            ['9MOBILEGIFT', '1000', '1GB', '830', '950', '900', '870', 'Gifting', 30],
            ['9MOBILEGIFT', '1500', '1.5GB', '1660', '1900', '1800', '1750', 'Gifting', 30],
            ['9MOBILEGIFT', '3000', '3GB', '2075', '2350', '2250', '2150', 'Gifting', 30],
            ['9MOBILEGIFT', '4500', '4.5GB', '2905', '3300', '3150', '3000', 'Gifting', 30],

            // 9Mobile Awoof Data - Service: 9MOBILEAWOOF
            ['9MOBILEAWOOF', '100', '100MB', '93', '115', '105', '100', 'Awoof', 1],
            ['9MOBILEAWOOF', '500', '500MB', '325', '380', '360', '345', 'Awoof', 3],
            ['9MOBILEAWOOF', '1000', '1GB', '650', '750', '720', '690', 'Awoof', 7],
            ['9MOBILEAWOOF', '2000', '2GB', '1300', '1500', '1440', '1380', 'Awoof', 14],
        ];

        $this->insertPlans($plans, '9mobile');
    }

    /**
     * Insert plans into the dataplans table
     * 
     * @param array $plans Array of plan data [service, planid, name, price, userprice, agentprice, vendorprice, type, day]
     * @param string $networkName Network name (MTN, Airtel, Glo, 9mobile)
     */
    private function insertPlans(array $plans, string $networkName): void
    {
        $networkId = $this->networkIds[$networkName] ?? null;

        if (!$networkId) {
            $this->command->warn("Network ID not found for {$networkName}. Please update networkIds array.");
            return;
        }

        foreach ($plans as $plan) {
            [$service, $planid, $name, $price, $userprice, $agentprice, $vendorprice, $type, $day] = $plan;

            DataPlan::updateOrCreate(
                [
                    'planid' => $planid,
                    'datanetwork' => $networkId,
                    'service_type' => self::SERVICE_TYPE,
                ],
                [
                    'name' => $name,
                    'price' => $price,
                    'userprice' => $userprice,
                    'agentprice' => $agentprice,
                    'vendorprice' => $vendorprice,
                    'type' => $type,
                    'day' => (string) $day,
                ]
            );
        }

        $this->command->info('Seeded ' . count($plans) . " VTU Africa plans for {$networkName}");
    }
}
