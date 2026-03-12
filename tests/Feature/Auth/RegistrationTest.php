<?php

test('new users can register', function () {
    $response = $this->postJson('/api/register', [
        'fname' => 'Test',
        'lname' => 'User',
        'username' => 'testuser123',
        'sEmail' => 'test@example.com',
        'sPhone' => '2348031234567',
        'password' => 'Password1!',
        'password_confirmation' => 'Password1!',
        'state' => 'Lagos',
        'pin' => '1234',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('subscribers', [
        'username' => 'testuser123',
        'sEmail' => 'test@example.com',
    ]);
});
