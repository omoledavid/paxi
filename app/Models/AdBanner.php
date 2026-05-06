<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdBanner extends Model
{
    protected $table = 'ad_banners';

    protected $fillable = ['image', 'link_url', 'active'];

    protected $casts = [
        'active' => 'boolean',
    ];
}
