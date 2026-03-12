<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateReferralPhonesToEmails extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'referrals:migrate-to-emails {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate legacy phone-based referrals (sReferal) to email-based referrals';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('🔍 Running in DRY-RUN mode - no changes will be made');
        }

        $this->info('Starting migration of phone-based referrals to emails...');

        // Find all users with phone numbers in sReferal (not null, not empty, doesn't match username pattern)
        $usersWithPhoneReferrals = DB::table('subscribers')
            ->whereNotNull('sReferal')
            ->where('sReferal', '!=', '')
            ->whereRaw('sReferal REGEXP \'^[0-9]+$\'') // Only numeric values (phone numbers)
            ->get(['sId', 'sReferal', 'sFname', 'sLname', 'sEmail']);

        $totalUsers = $usersWithPhoneReferrals->count();
        $this->info("Found {$totalUsers} users with phone-based referrals");

        if ($totalUsers === 0) {
            $this->info('✅ No users to migrate');
            return 0;
        }

        $updated = 0;
        $notFound = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($totalUsers);
        $progressBar->start();

        foreach ($usersWithPhoneReferrals as $user) {
            try {
                // Find the referrer by phone number
                $referrer = DB::table('subscribers')
                    ->where('sPhone', $user->sReferal)
                    ->first(['sEmail', 'username', 'sPhone']);

                if ($referrer && $referrer->sEmail) {
                    if (!$dryRun) {
                        DB::table('subscribers')
                            ->where('sId', $user->sId)
                            ->update(['sReferal' => $referrer->sEmail]);
                    }

                    $updated++;

                    if ($this->output->isVerbose()) {
                        $this->newLine();
                        $this->line("  ✓ User {$user->sEmail}: {$user->sReferal} → {$referrer->sEmail}");
                    }
                } else {
                    $notFound++;

                    if ($this->output->isVerbose()) {
                        $this->newLine();
                        $this->warn("  ⚠ User {$user->sEmail}: Referrer with phone {$user->sReferal} not found");
                    }
                }
            } catch (\Exception $e) {
                $errors++;

                if ($this->output->isVerbose()) {
                    $this->newLine();
                    $this->error("  ✗ Error processing user {$user->sEmail}: {$e->getMessage()}");
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Summary
        $this->info('Migration Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Total users processed', $totalUsers],
                ['Successfully updated', $updated],
                ['Referrer not found', $notFound],
                ['Errors', $errors],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('⚠️  This was a DRY-RUN. No changes were made to the database.');
            $this->info('Run without --dry-run to apply changes: php artisan referrals:migrate-to-emails');
        } else {
            $this->newLine();
            $this->info('✅ Migration completed successfully!');
        }

        return 0;
    }
}
