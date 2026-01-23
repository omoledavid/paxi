<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Network extends Model
{
    protected $table = 'networkid'; // Table name if different from "networks"

    protected $primaryKey = 'nId'; // Primary key column

    public function dataPlans()
    {
        return $this->hasMany(DataPlan::class, 'datanetwork', 'nId');
    }
}
