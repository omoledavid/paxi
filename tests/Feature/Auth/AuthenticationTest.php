<?php

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create([
        'sRegStatus' => 0,
    ]);

    // Seed a known device record matching the test client fingerprint
    $request = Request::create('/api/login', 'POST');
    $deviceHash = hash('sha256', $request->ip().'|'.$request->userAgent());

    UserDevice::create([
        'user_id' => $user->sId,
        'device_hash' => $deviceHash,
        'last_seen_at' => now(),
    ]);

    $response = $this->postJson('/api/login', [
        'sPhone' => $user->sEmail,
        'password' => 'password',
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'status',
        'message',
        'data' => [
            'token',
            'user',
        ],
    ]);
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create([
        'sRegStatus' => 0,
    ]);

    $response = $this->postJson('/api/login', [
        'sPhone' => $user->sEmail,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401);
});

test('a different device_id requires verification even when the IP/UA hash matches', function () {
    $user = User::factory()->create([
        'sRegStatus' => 0,
    ]);

    // Seed a device record matching the test client IP/UA fingerprint
    $request = Request::create('/api/login', 'POST');
    $deviceHash = hash('sha256', $request->ip().'|'.$request->userAgent());

    UserDevice::create([
        'user_id' => $user->sId,
        'device_hash' => $deviceHash,
        'last_seen_at' => now(),
    ]);

    // Login with a NEW device_id — must NOT be let in via the hash fallback
    $response = $this->postJson('/api/login', [
        'sPhone' => $user->sEmail,
        'password' => 'password',
        'device_id' => '00000000-0000-4000-8000-000000000000',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('data.requires_device_verification', true);
});

test('the same device_id is trusted across logins', function () {
    $user = User::factory()->create([
        'sRegStatus' => 0,
    ]);

    $deviceId = '11111111-1111-4111-8111-111111111111';

    UserDevice::create([
        'user_id' => $user->sId,
        'client_device_id' => $deviceId,
        'device_hash' => hash('sha256', 'legacy-hash-'.$deviceId),
        'last_seen_at' => now(),
    ]);

    $response = $this->postJson('/api/login', [
        'sPhone' => $user->sEmail,
        'password' => 'password',
        'device_id' => $deviceId,
    ]);

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'status',
        'message',
        'data' => [
            'token',
            'user',
        ],
    ]);
});

test('users can logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('auth_token')->plainTextToken;

    $response = $this->withToken($token)->postJson('/api/logout');

    $response->assertStatus(200);
    $this->assertDatabaseMissing('personal_access_tokens', [
        'tokenable_id' => $user->sId,
        'tokenable_type' => User::class,
    ]);
});
