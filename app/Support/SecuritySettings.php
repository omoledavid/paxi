<?php

namespace App\Support;

use App\Models\GeneralSetting;
use Illuminate\Support\Facades\Cache;

class SecuritySettings
{
    public static function get(): ?GeneralSetting
    {
        return Cache::remember('security_settings', 60, function () {
            return GeneralSetting::first();
        });
    }
}
