<?php

namespace App\Http\Controllers;

use App\Http\Resources\ExamProviderResource;
use App\Models\ExamProvider;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class ExamCardController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $examCard = ExamProvider::all();
        return $this->ok('success', ExamProviderResource::collection($examCard));
    }
}
