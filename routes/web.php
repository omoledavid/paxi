<?php

use App\Http\Controllers\PaystackCheckoutController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('https://paxi.ng');
});

// Paystack redirects the user's browser here after payment (unauthenticated web route)
Route::get('/paystack/callback', [PaystackCheckoutController::class, 'callback']);

// Clear cache and run migrations
Route::get('/status', function () {
    $results = [];

    // Clear cache
    $cacheOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    \Illuminate\Support\Facades\Artisan::call('cache:clear', [], $cacheOutput);
    $results['cache_cleared'] = trim($cacheOutput->fetch()) ?: 'Application cache cleared successfully.';

    // Run migrations
    $migrateOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true], $migrateOutput);
    $migrateContent = trim($migrateOutput->fetch());
    $results['migration_output'] = $migrateContent ?: 'Nothing to migrate.';
    $results['migrations_ran'] = !str_contains($migrateContent, 'Nothing to migrate');

    return response()->json([
        'status' => 'success',
        'cache_cleared' => $results['cache_cleared'],
        'migrations_ran' => $results['migrations_ran'],
        'migration_output' => $results['migration_output'],
    ]);
});
