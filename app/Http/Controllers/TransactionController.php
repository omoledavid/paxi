<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Traits\ApiResponses;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    use ApiResponses;
    public function index()
    {
        $user = auth()->user();
        $transaction = Transaction::query()->where('sId', $user->sId)->latest('tId')->get();
        if ($transaction) {
            return $this->ok('All transactions',  TransactionResource::collection($transaction));
        }
    }
}
