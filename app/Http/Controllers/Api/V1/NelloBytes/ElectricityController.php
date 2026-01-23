<?php

namespace App\Http\Controllers\Api\V1\NelloBytes;

use App\Services\NelloBytes\ElectricityService;
use App\Traits\ApiResponses;
use App\Models\ApiConfig; // [NEW]

class ElectricityController
{
    use ApiResponses;

    protected ElectricityService $electricityService;

    public function __construct(ElectricityService $electricityService)
    {
        $this->electricityService = $electricityService;
    }

    public function getProviders()
    {
        try {
            $providers = $this->electricityService->getProviders();

            return $this->ok('Electricity providers retrieved successfully', $providers);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve electricity providers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->error('Failed to retrieve electricity providers', 500, $e->getMessage());
        }
    }

    public function buyElectricity()
    {
        $request = request();
        $user = auth()->user();

        $validated = $request->validate([
            'provider_code' => 'required|string',
            'meter_number' => 'required|string',
            'amount' => 'required|numeric',
        ]);

        // Implementation for buying electricity will go here

        return $this->ok('Electricity purchase functionality is under development.');
    }

    private function isPaystackEnabled(): bool
    {
        static $enabled = null;

        if ($enabled === null) {
            $config = ApiConfig::all();

            $enabled = getConfigValue($config, 'paystackStatus') === 'On' &&
                getConfigValue($config, 'paystackElectricityStatus') === 'On';
        }

        return $enabled;
    }
}
