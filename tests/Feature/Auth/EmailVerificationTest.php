<?php

use App\Models\User;

test('email can be verified with valid code', function () {
    $user = User::factory()->create([
        'sVerCode' => '123456',
        'sVerCodeExpiry' => now()->addMinutes(5),
        'sRegStatus' => 3,
    ]);

    $response = $this->postJson('/api/verify-email', [
        'code' => '123456',
        'email' => $user->sEmail,
    ]);

    $response->assertStatus(200);
    $user->refresh();
    expect($user->sVerCode)->toBe(0);
    expect($user->sRegStatus)->toBe(0);
    expect($user->sVerCodeExpiry)->toBeNull();
});

test('email verification fails with invalid code', function () {
    $user = User::factory()->create([
        'sVerCode' => '123456',
        'sVerCodeExpiry' => now()->addMinutes(5),
        'sRegStatus' => 3,
    ]);

    $response = $this->postJson('/api/verify-email', [
        'code' => '999999',
        'email' => $user->sEmail,
    ]);

    $response->assertStatus(400);
    $user->refresh();
    expect($user->sRegStatus)->toBe(3);
});

test('email verification fails with expired code', function () {
    $user = User::factory()->create([
        'sVerCode' => '123456',
        'sVerCodeExpiry' => now()->subMinutes(1),
        'sRegStatus' => 3,
    ]);

    $response = $this->postJson('/api/verify-email', [
        'code' => '123456',
        'email' => $user->sEmail,
    ]);

    $response->assertStatus(400);
    $user->refresh();
    expect($user->sRegStatus)->toBe(3);
});
