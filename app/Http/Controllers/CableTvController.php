<?php

namespace App\Http\Controllers;

use App\Http\Resources\CableTvResource;
use App\Models\CableTv;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class CableTvController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $cableTv = CableTv::with('plans')->get();
        return $this->ok('success',[
            'subscription_type' => ['Chane', 'Renew'],
            'cableTv' => CableTvResource::collection($cableTv),
        ]);
    }
}
