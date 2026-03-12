<?php

use App\Models\User;

test('registration with valid username succeeds', function () {
    $response = $this->postJson('/api/register', [
        'fname' => 'John',
        'lname' => 'Doe',
        'username' => 'john_doe',
        'sEmail' => 'john@example.com',
        'sPhone' => '2348031234567',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('subscribers', [
        'username' => 'john_doe',
        'sEmail' => 'john@example.com',
    ]);
});

test('registration with duplicate username fails', function () {
    User::factory()->create(['username' => 'taken_user']);

    $response = $this->postJson('/api/register', [
        'fname' => 'Jane',
        'lname' => 'Doe',
        'username' => 'taken_user',
        'sEmail' => 'jane@example.com',
        'sPhone' => '2348031234568',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('username');
});

test('registration with invalid username format fails', function () {
    $response = $this->postJson('/api/register', [
        'fname' => 'John',
        'lname' => 'Doe',
        'username' => 'ab',
        'sEmail' => 'john2@example.com',
        'sPhone' => '2348031234569',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('username');
});

test('registration with username containing special characters fails', function () {
    $response = $this->postJson('/api/register', [
        'fname' => 'John',
        'lname' => 'Doe',
        'username' => 'john@doe!',
        'sEmail' => 'john3@example.com',
        'sPhone' => '2348031234570',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('username');
});

test('registration without username fails', function () {
    $response = $this->postJson('/api/register', [
        'fname' => 'John',
        'lname' => 'Doe',
        'sEmail' => 'john4@example.com',
        'sPhone' => '2348031234571',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors('username');
});

test('registration with valid referral username stores it in sReferal', function () {
    $referrer = User::factory()->create(['username' => 'referrer_user']);

    $response = $this->postJson('/api/register', [
        'fname' => 'John',
        'lname' => 'Doe',
        'username' => 'new_user',
        'sEmail' => 'newuser@example.com',
        'sPhone' => '2348031234572',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
        'referral' => 'referrer_user',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('subscribers', [
        'username' => 'new_user',
        'sReferal' => 'referrer_user',
    ]);
});

test('registration with non-existent referral username fails', function () {
    $response = $this->postJson('/api/register', [
        'fname' => 'John',
        'lname' => 'Doe',
        'username' => 'another_user',
        'sEmail' => 'another@example.com',
        'sPhone' => '2348031234573',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
        'referral' => 'nonexistent_user',
    ]);

    $response->assertStatus(422);
});
