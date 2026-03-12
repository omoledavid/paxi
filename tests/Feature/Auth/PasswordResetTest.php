<?php

use App\Models\User;
use App\Models\PasswordReset;

test('reset password code can be requested', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/password/email', [
        'email' => $user->sEmail,
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('password_resets', [
        'email' => $user->sEmail,
    ]);
});

test('password can be reset with valid code', function () {
    $user = User::factory()->create();

    $response = $this->postJson('/api/password/email', [
        'email' => $user->sEmail,
    ]);

    $response->assertStatus(200);

    $passwordReset = PasswordReset::where('email', $user->sEmail)->first();

    $resetResponse = $this->postJson('/api/password/reset', [
        'token' => $passwordReset->token,
        'email' => $user->sEmail,
        'password' => 'NewPassword1!',
        'password_confirmation' => 'NewPassword1!',
    ]);

    $resetResponse->assertStatus(200);
    $this->assertDatabaseMissing('password_resets', [
        'email' => $user->sEmail,
    ]);
});
