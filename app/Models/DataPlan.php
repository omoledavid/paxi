<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataPlan extends Model
{
    use HasFactory;

    protected $table = 'dataplans'; // Table name
    protected $primaryKey = 'pId';  // Primary key column

    public function network()
    {
        return $this->belongsTo(Network::class, 'datanetwork', 'nId');
    }
}
