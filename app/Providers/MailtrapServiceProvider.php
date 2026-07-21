<?php

namespace App\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;
use Mailtrap\Bridge\Transport\MailtrapSdkTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;

class MailtrapServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Mail::extend('mailtrap', function (array $config = []) {
            $mailtrapConfig = config('mailtrap');
            $apiKey = $mailtrapConfig['api_key'] ?? '';
            $useSandbox = $mailtrapConfig['use_sandbox'] ?? true;
            $inboxId = $mailtrapConfig['inbox_id'] ?? null;

            // Debug: Log the config (mask the API key for security)
            $maskedKey = $apiKey ? substr($apiKey, 0, 8) . '...' : 'EMPTY';
            // \Log::debug('Mailtrap config', [
            //     'api_key_masked' => $maskedKey,
            //     'use_sandbox' => $useSandbox,
            //     'inbox_id' => $inboxId,
            // ]);

            // Build DSN for Mailtrap SDK
            // Sandbox: mailtrap+sdk://APIKEY@sandbox.api.mailtrap.io?inboxId=1234
            // Production: mailtrap+sdk://APIKEY@send.api.mailtrap.io
            $host = $useSandbox ? 'sandbox.api.mailtrap.io' : 'send.api.mailtrap.io';
            $dsnString = sprintf(
                'mailtrap+sdk://%s@%s%s',
                urlencode($apiKey),
                $host,
                $inboxId !== null ? '?inboxId=' . $inboxId : ''
            );

            // \Log::debug('Mailtrap DSN', ['dsn' => 'mailtrap+sdk://' . $maskedKey . '@' . $host . ($inboxId !== null ? '?inboxId=' . $inboxId : '')]);

            $httpClient = \Symfony\Component\HttpClient\HttpClient::create();
            $factory = new MailtrapSdkTransportFactory(null, $httpClient);
            return $factory->create(Dsn::fromString($dsnString));
        });
    }
}
