<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use function Pest\Laravel\getJson;

uses()->group('idle-timeout');

beforeEach(function () {
    if (! Schema::hasTable('subscribers')) {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->increments('sId');
            $table->string('sFname');
            $table->string('sLname');
            $table->string('sEmail')->unique();
            $table->string('sPhone')->unique();
            $table->string('sPass');
            $table->integer('sRegStatus')->default(0);
            $table->timestamps();
        });
    }

    if (! Route::has('idle-probe')) {
        Route::middleware(['api', 'auth:sanctum', 'token.recent'])->get('/api/idle-probe', fn () => response()->json(['status' => 'ok']))->name('idle-probe');
    }
});

function createTestUser(): User
{
    return User::query()->create([
        'sFname' => 'Test',
        'sLname' => 'User',
        'sEmail' => Str::uuid().'@example.com',
        'sPhone' => '080'.random_int(10000000, 99999999),
        'sPass' => bcrypt('secret'),
        'sRegStatus' => 0,
    ]);
}

it('expires tokens inactive for longer than the idle timeout', function () {
    $user = createTestUser();
    $plainToken = $user->createToken('auth_token')->plainTextToken;

    /** @var PersonalAccessToken $tokenModel */
    $tokenModel = $user->tokens()->first();
    $tokenModel->forceFill([
        'last_used_at' => now()->subMinutes(40),
    ])->save();


    $response = getJson('/api/idle-probe', [
        'Authorization' => 'Bearer '.$plainToken,
    ]);

    $response->assertUnauthorized()
        ->assertJson([
            'message' => 'Session expired due to inactivity. Please sign in again.',
        ]);

    expect(PersonalAccessToken::query()->whereKey($tokenModel->getKey())->exists())->toBeFalse();
});

it('keeps active tokens alive and refreshes last_used_at', function () {
    $user = createTestUser();
    $plainToken = $user->createToken('auth_token')->plainTextToken;

    /** @var PersonalAccessToken $tokenModel */
    $tokenModel = $user->tokens()->first();
    $tokenModel->forceFill([
        'last_used_at' => now()->subMinutes(10),
    ])->save();

    $response = getJson('/api/idle-probe', [
        'Authorization' => 'Bearer '.$plainToken,
    ]);

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
        ]);

    $tokenModel->refresh();

    expect($tokenModel->last_used_at->greaterThan(now()->subMinute()))->toBeTrue();
});

