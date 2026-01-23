<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NbDataPlan extends Model
{
    protected $table = 'nb_data_plans';

    protected $primaryKey = 'pId';

    protected $guarded = ['pId'];

    protected $fillable = ['plan_code', 'name', 'userprice', 'type', 'day', 'datanetwork', 'data_size'];

    public $timestamps = false;

    public function network()
    {
        return $this->belongsTo(Network::class, 'datanetwork', 'nId');
    }
}
