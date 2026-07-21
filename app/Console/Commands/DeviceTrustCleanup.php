<?php

namespace App\Console\Commands;

use App\Models\DeviceTrustToken;
use Illuminate\Console\Command;

class DeviceTrustCleanup extends Command
{
    protected $signature = 'devicetrust:clean-expired';

    protected $description = 'Delete expired device trust tokens';

    public function handle(): void
    {
        $deleted = DeviceTrustToken::where('expires_at', '<=', now())->delete();

        $this->info("Deleted {$deleted} expired device trust token(s).");
    }
}
