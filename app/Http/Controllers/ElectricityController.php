<?php

namespace App\Http\Controllers;

use App\Http\Resources\ElectricityResource;
use App\Models\EProvider;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class ElectricityController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $electricity = EProvider::query()->get();
        return $this->ok('success', [
            'provider' => ElectricityResource::collection($electricity),
            'meter_type' => ['Prepaid', 'Postpaid']
        ]);
    }
}
