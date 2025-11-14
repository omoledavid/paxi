<?php

namespace App\Console\Commands;

use App\Services\GatewayApiService;
use Illuminate\Console\Command;

class SendGatewaySms extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gateway:sms {message : The SMS message body} {recipients* : One or more recipient phone numbers}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send an SMS message via GatewayAPI to one or more recipients.';

    public function __construct(private readonly GatewayApiService $gatewayApiService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $message = $this->argument('message');
        $recipients = $this->argument('recipients');

        if (empty($recipients)) {
            $this->error('Please provide at least one recipient phone number.');
            return self::FAILURE;
        }

        $this->info(sprintf(
            'Sending SMS "%s" to: %s',
            $message,
            implode(', ', $recipients)
        ));

        $response = $this->gatewayApiService->sendBulkSms($recipients, $message);

        if ($response['success'] ?? false) {
            $messageIds = $response['data']['ids'] ?? [];
            $this->info('SMS sent successfully.');

            if (!empty($messageIds)) {
                $this->line('GatewayAPI message IDs: ' . implode(', ', $messageIds));
            }

            return self::SUCCESS;
        }

        $this->error('Failed to send SMS via GatewayAPI.');

        if (isset($response['message'])) {
            $this->line('Message: ' . $response['message']);
        }

        if (isset($response['error'])) {
            $this->line('Error details: ' . json_encode($response['error']));
        }

        return self::FAILURE;
    }
}


