<?php

use App\Models\User;

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create([
        'sRegStatus' => 0,
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
