<?php

namespace App\Http\Controllers;

use App\Http\Resources\DataResource;
use App\Http\Resources\NetworkResource;
use App\Models\DataPlan;
use App\Models\Network;
use App\Traits\ApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DataController extends Controller
{
    use ApiResponses;
    public function data(): JsonResponse
    {
        $data = Network::all();
        return $this->ok('success', [
            'data_type' => ['SME', 'Gifting', 'Corporate'],
            'data' => NetworkResource::collection($data)
        ]);
    }
}
