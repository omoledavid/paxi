<?php

namespace Database\Seeders;

use App\Models\NbDataPlan;
use Illuminate\Database\Seeder;

class DataPlanSeeder extends Seeder
{
    /**
     * Network ID mappings - Update these with actual network IDs from networkid table
     */
    private array $networkIds = [
        'MTN' => 1,      // Update with actual MTN nId
        'Glo' => 2,      // Update with actual Glo nId
        'Airtel' => 3,   // Update with actual Airtel nId
        '9mobile' => 4,  // Update with actual 9mobile nId
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedMTNPlans();
        $this->seedGloPlans();
        $this->seedAirtelPlans();
        $this->seed9mobilePlans();
    }

    private function seedMTNPlans(): void
    {
        $plans = [
            ['500.0', '500 MB - 7 days (SME)', '404.00', 'SME', 7, '500 MB'],
            ['1000.0', '1 GB - 7 days (SME)', '567.00', 'SME', 7, '1 GB'],
            ['2000.0', '2 GB - 7 days (SME)', '1134.00', 'SME', 7, '2 GB'],
            ['3000.0', '3 GB - 7 days (SME)', '1680.00', 'SME', 7, '3 GB'],
            ['5000.0', '5 GB - 7 days (SME)', '2540.00', 'SME', 7, '5 GB'],
            ['100.01', '110MB Daily Plan - 1 day (Awoof Data)', '97.00', 'Awoof Data', 1, '110 MB'],
            ['200.01', '230MB Daily Plan - 1 day (Awoof Data)', '194.00', 'Awoof Data', 1, '230 MB'],
            ['350.01', '500MB Daily Plan - 1 day (Awoof Data)', '339.50', 'Awoof Data', 1, '500 MB'],
            ['500.01', '1GB Daily Plan + 1.5mins. - 1 day (Awoof Data)', '485.00', 'Awoof Data', 1, '1 GB'],
            ['750.01', '2.5GB Daily Plan - 1 day (Awoof Data)', '727.50', 'Awoof Data', 1, '2.5 GB'],
            ['900.01', '2.5GB 2-Day Plan - 2 days (Awoof Data)', '873.00', 'Awoof Data', 2, '2.5 GB'],
            ['1000.01', '3.2GB 2-Day Plan - 2 days (Awoof Data)', '970.00', 'Awoof Data', 2, '3.2 GB'],
            ['500.02', '500MB Weekly Plan - 7 days (Direct Data)', '485.00', 'Direct Data', 7, '500 MB'],
            ['800.01', '1GB Weekly Plan - 7 days (Direct Data)', '776.00', 'Direct Data', 7, '1 GB'],
            ['1000.03', '1.5GB Weekly Plan - 7 days (Direct Data)', '970.00', 'Direct Data', 7, '1.5 GB'],
            ['1500.03', '3.5GB Weekly Plan - 7 days (Direct Data)', '1455.00', 'Direct Data', 7, '3.5 GB'],
            ['2500.01', '6GB Weekly Plan - 7 days (Direct Data)', '2425.00', 'Direct Data', 7, '6 GB'],
            ['3500.01', '11GB Weekly Bundle - 7 days (Direct Data)', '3395.00', 'Direct Data', 7, '11 GB'],
            ['1500.02', '2GB+2mins Monthly Plan - 30 days (Direct Data)', '1455.00', 'Direct Data', 30, '2 GB'],
            ['2000.01', '2.7GB+2mins Monthly Plan - 30 days (Direct Data)', '1940.00', 'Direct Data', 30, '2.7 GB'],
            ['2500.02', '3.5GB+5mins Monthly Plan - 30 days (Direct Data)', '2425.00', 'Direct Data', 30, '3.5 GB'],
            ['3500.02', '7GB Monthly Plan - 30 days (Direct Data)', '3395.00', 'Direct Data', 30, '7 GB'],
            ['4500.01', '10GB+10mins Monthly Plan - 30 days (Direct Data)', '4365.00', 'Direct Data', 30, '10 GB'],
            ['5500.01', '12.5GB Monthly Plan - 30 days (Direct Data)', '5335.00', 'Direct Data', 30, '12.5 GB'],
            ['6500.01', '16.5GB+10mins Monthly Plan - 30 days (Direct Data)', '6305.00', 'Direct Data', 30, '16.5 GB'],
            ['7500.01', '20GB Monthly Plan - 30 days (Direct Data)', '7275.00', 'Direct Data', 30, '20 GB'],
            ['9000.01', '25GB Monthly Plan - 30 days (Direct Data)', '8730.00', 'Direct Data', 30, '25 GB'],
            ['11000.01', '36GB Monthly Plan - 30 days (Direct Data)', '10670.00', 'Direct Data', 30, '36 GB'],
            ['18000.01', '75GB Monthly Plan - 30 days (Direct Data)', '17460.00', 'Direct Data', 30, '75 GB'],
            ['35000.01', '165GB Monthly Plan - 30 days (Direct Data)', '33950.00', 'Direct Data', 30, '165 GB'],
            ['40000.01', '150GB 2-Month Plan - 60 days (Direct Data)', '38800.00', 'Direct Data', 60, '150 GB'],
            ['5000.01', '20GB Weekly Plan - 7 days (Direct Data)', '4850.00', 'Direct Data', 7, '20 GB'],
            ['90000.03', '480GB 3-Month Plan - 90 days (Direct Data)', '87300.00', 'Direct Data', 90, '480 GB'],
        ];

        $this->insertPlans($plans, 'MTN');
    }

    private function seedGloPlans(): void
    {
        $plans = [
            ['200', '200 MB - 14 days (SME)', '94.00', 'SME', 14, '200 MB'],
            ['500', '500 MB - 7 days (SME)', '235.00', 'SME', 7, '500 MB'],
            ['1000.11', '1 GB - 3 days (SME)', '282.00', 'SME', 3, '1 GB'],
            ['3000.11', '3 GB - 3 days (SME)', '846.00', 'SME', 3, '3 GB'],
            ['5000.11', '5 GB - 3 days (SME)', '1410.00', 'SME', 3, '5 GB'],
            ['1000.12', '1 GB - 7 days (SME)', '329.00', 'SME', 7, '1 GB'],
            ['3000.12', '3 GB - 7 days (SME)', '987.00', 'SME', 7, '3 GB'],
            ['5000.12', '5 GB - 7 days (SME)', '1645.00', 'SME', 7, '5 GB'],
            ['1000.21', '1 GB - 14 days Night Plan (SME)', '329.00', 'SME', 14, '1 GB'],
            ['3000.21', '3 GB - 14 days Night Plan (SME)', '987.00', 'SME', 14, '3 GB'],
            ['5000.21', '5 GB - 14 days Night Plan (SME)', '1645.00', 'SME', 14, '5 GB'],
            ['10000.21', '10 GB - 14 days Night Plan (SME)', '3290.00', 'SME', 14, '10 GB'],
            ['1000', '1 GB - 30 days (SME)', '470.00', 'SME', 30, '1 GB'],
            ['2000', '2 GB - 30 days (SME)', '940.00', 'SME', 30, '2 GB'],
            ['3000', '3 GB - 30 days (SME)', '1410.00', 'SME', 30, '3 GB'],
            ['5000', '5 GB - 30 days (SME)', '2350.00', 'SME', 30, '5 GB'],
            ['10000', '10 GB - 30 days (SME)', '4700.00', 'SME', 30, '10 GB'],
            ['100.01', '125MB - 1 day (Awoof Data)', '95.50', 'Awoof Data', 1, '125 MB'],
            ['200.01', '260MB - 2 day (Awoof Data)', '191.00', 'Awoof Data', 2, '260 MB'],
            ['500.01', '1.5GB - 14 days (Direct Data)', '477.50', 'Direct Data', 14, '1.5 GB'],
            ['1000.01', '2.6GB - 30 days (Direct Data)', '955.00', 'Direct Data', 30, '2.6 GB'],
            ['1500.01', '5GB - 30 days (Direct Data)', '1432.50', 'Direct Data', 30, '5 GB'],
            ['2000.01', '6.15GB - 30 days (Direct Data)', '1910.00', 'Direct Data', 30, '6.15 GB'],
            ['2500.01', '7.5GB - 30 days (Direct Data)', '2387.50', 'Direct Data', 30, '7.5 GB'],
            ['3000.01', '10GB - 30 days (Direct Data)', '2865.00', 'Direct Data', 30, '10 GB'],
            ['4000.01', '12.5GB - 30 days (Direct Data)', '3820.00', 'Direct Data', 30, '12.5 GB'],
            ['5000.01', '16GB - 30 days (Direct Data)', '4775.00', 'Direct Data', 30, '16 GB'],
            ['8000.01', '28GB - 30 days (Direct Data)', '7640.00', 'Direct Data', 30, '28 GB'],
            ['10000.01', '38GB - 30 days (Direct Data)', '9550.00', 'Direct Data', 30, '38 GB'],
            ['15000.01', '64GB - 30 days (Direct Data)', '14325.00', 'Direct Data', 30, '64 GB'],
            ['20000.01', '107GB - 30 days (Direct Data)', '19100.00', 'Direct Data', 30, '107 GB'],
            ['500.02', '2GB - 1 day (Awoof Data)', '477.50', 'Awoof Data', 1, '2 GB'],
            ['1500.02', '6GB - 7 days (Direct Data)', '1432.50', 'Direct Data', 7, '6 GB'],
            ['500.03', '2.5GB - Weekend Plan - [Sat & Sun] (Awoof Data)', '477.50', 'Awoof Data', 2, '2.5 GB'],
            ['200.02', '875MB - Weekend Plan [Sun] (Awoof Data)', '191.00', 'Awoof Data', 1, '875 MB'],
            ['30000.01', '165GB - 30 days (Direct Data)', '28650.00', 'Direct Data', 30, '165 GB'],
            ['36000.01', '220GB - 30 days (Direct Data)', '38200.00', 'Direct Data', 30, '220 GB'],
            ['50000.01', '320GB - 30 days (Direct Data)', '47750.00', 'Direct Data', 30, '320 GB'],
            ['60000.01', '380GB - 30 days (Direct Data)', '57300.00', 'Direct Data', 30, '380 GB'],
            ['75000.01', '475GB - 30 days (Direct Data)', '71625.00', 'Direct Data', 30, '475 GB'],
            ['150000.03', '1TB (1000GB) - 365 days (Direct Data)', '143250.00', 'Direct Data', 365, '1 TB'],
        ];

        $this->insertPlans($plans, 'Glo');
    }

    private function seedAirtelPlans(): void
    {
        $plans = [
            ['499.91', '1GB - 1 day (Awoof Data)', '483.91', 'Awoof Data', 1, '1 GB'],
            ['599.91', '1.5GB - 2 days (Awoof Data)', '580.71', 'Awoof Data', 2, '1.5 GB'],
            ['749.91', '2GB - 2 days (Awoof Data)', '725.91', 'Awoof Data', 2, '2 GB'],
            ['999.91', '3GB - 2 days (Awoof Data)', '967.91', 'Awoof Data', 2, '3 GB'],
            ['1499.91', '5GB - 2 days (Awoof Data)', '1451.91', 'Awoof Data', 2, '5 GB'],
            ['499.92', '500MB - 7 days (Direct Data)', '483.92', 'Direct Data', 7, '500 MB'],
            ['799.91', '1GB - 7 days (Direct Data)', '774.31', 'Direct Data', 7, '1 GB'],
            ['999.92', '1.5GB - 7 days (Direct Data)', '967.92', 'Direct Data', 7, '1.5 GB'],
            ['1499.92', '3.5GB - 7 days (Direct Data)', '1451.92', 'Direct Data', 7, '3.5 GB'],
            ['2499.91', '6GB - 7 days (Direct Data)', '2419.91', 'Direct Data', 7, '6 GB'],
            ['2999.91', '10GB - 7 days (Direct Data)', '2903.91', 'Direct Data', 7, '10 GB'],
            ['4999.91', '18GB - 7 days (Direct Data)', '4839.91', 'Direct Data', 7, '18 GB'],
            ['1499.93', '2GB - 30 days (Direct Data)', '1451.93', 'Direct Data', 30, '2 GB'],
            ['1999.91', '3GB - 30 days (Direct Data)', '1935.91', 'Direct Data', 30, '3 GB'],
            ['2499.92', '4GB - 30 days (Direct Data)', '2419.92', 'Direct Data', 30, '4 GB'],
            ['2999.92', '8GB - 30 days (Direct Data)', '2903.92', 'Direct Data', 30, '8 GB'],
            ['3999.91', '10GB - 30 days (Direct Data)', '3871.91', 'Direct Data', 30, '10 GB'],
            ['4999.92', '13GB - 30 days (Direct Data)', '4839.92', 'Direct Data', 30, '13 GB'],
            ['5999.91', '18GB - 30 days (Direct Data)', '5807.91', 'Direct Data', 30, '18 GB'],
            ['7999.91', '25GB - 30 days (Direct Data)', '7743.91', 'Direct Data', 30, '25 GB'],
            ['9999.91', '35GB - 30 days (Direct Data)', '9679.91', 'Direct Data', 30, '35 GB'],
            ['14999.91', '60GB - 30 days (Direct Data)', '14519.91', 'Direct Data', 30, '60 GB'],
            ['19999.91', '100GB - 30 days (Direct Data)', '19359.91', 'Direct Data', 30, '100 GB'],
            ['29999.91', '160GB - 30 days (Direct Data)', '29039.91', 'Direct Data', 30, '160 GB'],
            ['39999.91', '210GB - 30 days (Direct Data)', '38719.91', 'Direct Data', 30, '210 GB'],
            ['49999.91', '300GB - 90 days (Direct Data)', '48399.91', 'Direct Data', 90, '300 GB'],
            ['59999.91', '350GB - 90 days (Direct Data)', '58079.91', 'Direct Data', 90, '350 GB'],
        ];

        $this->insertPlans($plans, 'Airtel');
    }

    private function seed9mobilePlans(): void
    {
        $plans = [
            ['50', '50 MB - 30 days (SME)', '23.00', 'SME', 30, '50 MB'],
            ['100', '100 MB - 30 days (SME)', '46.00', 'SME', 30, '100 MB'],
            ['300', '300 MB - 30 days (SME)', '138.00', 'SME', 30, '300 MB'],
            ['500', '500 MB - 30 days (SME)', '225.00', 'SME', 30, '500 MB'],
            ['1000', '1 GB - 30 days (SME)', '450.00', 'SME', 30, '1 GB'],
            ['2000', '2 GB - 30 days (SME)', '900.00', 'SME', 30, '2 GB'],
            ['3000', '3 GB - 30 days (SME)', '1350.00', 'SME', 30, '3 GB'],
            ['4000', '4 GB - 30 days (SME)', '1800.00', 'SME', 30, '4 GB'],
            ['5000', '5 GB - 30 days (SME)', '2250.00', 'SME', 30, '5 GB'],
            ['10000', '10 GB - 30 days (SME)', '4500.00', 'SME', 30, '10 GB'],
            ['15000', '15 GB - 30 days (SME)', '6750.00', 'SME', 30, '15 GB'],
            ['20000', '20 GB - 30 days (SME)', '9000.00', 'SME', 30, '20 GB'],
            ['25000', '25 GB - 30 days (SME)', '11250.00', 'SME', 30, '25 GB'],
            ['50000', '50 GB - 30 days (SME)', '20925.00', 'SME', 30, '50 GB'],
            ['100000', '100 GB - 30 days (SME)', '41850.00', 'SME', 30, '100 GB'],
            ['100.01', '100MB - 1 day (Awoof Data)', '93.00', 'Awoof Data', 1, '100 MB'],
            ['150.01', '180MB - 1 days (Awoof Data)', '139.50', 'Awoof Data', 1, '180 MB'],
            ['200.01', '250MB - 1 days (Awooof Data)', '186.00', 'Awoof Data', 1, '250 MB'],
            ['350.01', '450MB - 1 day (Awoof Data)', '325.50', 'Awoof Data', 1, '450 MB'],
            ['500.01', '650MB - 3 days (Awoof Data)', '465.00', 'Awoof Data', 3, '650 MB'],
            ['1500.01', '1.75GB - 7 days (Direct Data)', '1395.00', 'Direct Data', 7, '1.75 GB'],
            ['600.01', '650MB - 14 days (Direct Data)', '558.00', 'Direct Data', 14, '650 MB'],
            ['1000.01', '1.1GB - 30 days (Direct Data)', '930.00', 'Direct Data', 30, '1.1 GB'],
            ['1200.01', '1.4GB - 30 days (Direct Data)', '1116.00', 'Direct Data', 30, '1.4 GB'],
            ['2000.01', '2.44GB - 30 days (Direct Data)', '1860.00', 'Direct Data', 30, '2.44 GB'],
            ['2500.01', '3.17GB - 30 days (Direct Data)', '2325.00', 'Direct Data', 30, '3.17 GB'],
            ['3000.01', '3.91GB - 30 days (Direct Data)', '2790.00', 'Direct Data', 30, '3.91 GB'],
            ['4000.01', '5.10GB - 30 days (Direct Data)', '3720.00', 'Direct Data', 30, '5.10 GB'],
            ['5000.01', '6.5GB - 30 days (Direct Data)', '4650.00', 'Direct Data', 30, '6.5 GB'],
            ['12000.01', '16GB - 30 days (Direct Data)', '11160.00', 'Direct Data', 30, '16 GB'],
            ['18500.01', '24.3GB - 30 days (Direct Data)', '17205.00', 'Direct Data', 30, '24.3 GB'],
            ['20000.01', '26.5GB - 30 days (Direct Data)', '18600.00', 'Direct Data', 30, '26.5 GB'],
            ['30000.01', '39GB - 60 days (Direct Data)', '27900.00', 'Direct Data', 60, '39 GB'],
            ['60000.01', '78GB - 90 days (Direct Data)', '55800.00', 'Direct Data', 90, '78 GB'],
            ['150000.01', '190GB - 180 days (Direct Data)', '139500.00', 'Direct Data', 180, '190 GB'],
        ];

        $this->insertPlans($plans, '9mobile');
    }

    private function insertPlans(array $plans, string $networkName): void
    {
        $networkId = $this->networkIds[$networkName] ?? null;

        if (! $networkId) {
            $this->command->warn("Network ID not found for {$networkName}. Please update networkIds array.");

            return;
        }

        foreach ($plans as $plan) {
            [$planCode, $name, $price, $type, $day, $dataSize] = $plan;

            NbDataPlan::updateOrCreate(
                [
                    'plan_code' => $planCode,
                    'datanetwork' => $networkId,
                ],
                [
                    'name' => $name,
                    'userprice' => $price,
                    'type' => $type,
                    'day' => $day,
                    'data_size' => $dataSize,
                ]
            );
        }

        $this->command->info('Seeded '.count($plans)." plans for {$networkName}");
    }
}
