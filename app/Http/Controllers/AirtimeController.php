<?php

namespace App\Http\Controllers;

use App\Http\Resources\NetworkResource;
use App\Models\Network;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class AirtimeController extends Controller
{
    use ApiResponses;
    public function index()
    {
        $data = Network::all();
        return $this->ok('success', [
            'type' => ['VTU', 'Share and sell'],
            'Network' => NetworkResource::collection($data)
        ]);
    }
}
