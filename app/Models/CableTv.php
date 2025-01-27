<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CableTv extends Model
{
    protected $table = 'cableid';

    public function plans()
    {
        return $this->hasMany(CablePlan::class,'cableprovider','cId');
    }
}
