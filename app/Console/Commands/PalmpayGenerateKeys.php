<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PalmpayGenerateKeys extends Command
{
    protected $signature = 'palmpay:generate-keys';

    protected $description = 'Generate RSA 2048-bit key pair for PalmPay API signature. Store the private key in your .env and upload the public key to PalmPay merchant platform.';

    public function handle(): int
    {
        $this->info('Generating RSA 2048-bit key pair for PalmPay...');

        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // On Windows, OpenSSL needs an openssl.cnf file. Create a minimal temp one if missing.
        $tempConf = null;
        $opensslConf = getenv('OPENSSL_CONF');
        if (!$opensslConf || !file_exists($opensslConf)) {
            $candidates = [
                dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
                dirname(PHP_BINARY) . '/../extras/ssl/openssl.cnf',
                'C:/Program Files/Common Files/SSL/openssl.cnf',
            ];
            $found = false;
            foreach ($candidates as $candidate) {
                if (file_exists($candidate)) {
                    $config['config'] = $candidate;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $tempConf = tempnam(sys_get_temp_dir(), 'openssl_') . '.cnf';
                file_put_contents($tempConf, "[req]\ndefault_bits = 2048\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
                $config['config'] = $tempConf;
            }
        }

        try {
            $resource = openssl_pkey_new($config);

            if (!$resource) {
                $this->error('Failed to generate key pair: ' . openssl_error_string());
                return self::FAILURE;
            }

            // Extract private key (PKCS#8 format)
            openssl_pkey_export($resource, $privateKeyPem, null, $config);

            // Extract public key
            $details = openssl_pkey_get_details($resource);
            $publicKeyPem = $details['key'];

            // Strip PEM headers for base64-only storage
            $privateKeyBase64 = $this->stripPemHeaders($privateKeyPem);
            $publicKeyBase64 = $this->stripPemHeaders($publicKeyPem);

            $this->newLine();
            $this->warn('=== PRIVATE KEY (store in .env as PALMPAY_MERCHANT_PRIVATE_KEY) ===');
            $this->info($privateKeyBase64);

            $this->newLine();
            $this->warn('=== PUBLIC KEY (upload to PalmPay merchant platform) ===');
            $this->info($publicKeyBase64);

            $this->newLine();
            $this->info('Add to your .env file:');
            $this->line("PALMPAY_MERCHANT_PRIVATE_KEY=\"{$privateKeyBase64}\"");

            $this->newLine();
            $this->warn('Keep your private key secure. Never share it.');

            return self::SUCCESS;
        } finally {
            if ($tempConf && file_exists($tempConf)) {
                unlink($tempConf);
            }
        }
    }

    private function stripPemHeaders(string $pem): string
    {
        $pem = preg_replace('/-----BEGIN [A-Z ]+-----/', '', $pem);
        $pem = preg_replace('/-----END [A-Z ]+-----/', '', $pem);

        return trim(str_replace(["\r", "\n"], '', $pem));
    }
}
