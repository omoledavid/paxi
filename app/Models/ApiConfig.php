<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiConfig extends Model
{
    protected $table = 'apiconfigs';

    protected $primaryKey = 'aId';

    public $timestamps = false;

    protected $guarded = ['aId'];
}
