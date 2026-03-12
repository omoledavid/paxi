<?php

use App\Models\User;

test('check username availability returns available for new username', function () {
    $response = $this->getJson('/api/check-username/fresh_user');

    $response->assertStatus(200);
    $response->assertJsonPath('data.available', true);
});

test('check username availability returns unavailable for taken username', function () {
    User::factory()->create(['username' => 'taken_name']);

    $response = $this->getJson('/api/check-username/taken_name');

    $response->assertStatus(200);
    $response->assertJsonPath('data.available', false);
});

test('check username rejects invalid format', function () {
    $response = $this->getJson('/api/check-username/ab');

    $response->assertStatus(422);
});

test('authenticated user can set username when none exists', function () {
    $user = User::factory()->create(['username' => null]);

    $response = $this->actingAs($user)->postJson('/api/users/set-username', [
        'username' => 'my_new_name',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('subscribers', [
        'sId' => $user->sId,
        'username' => 'my_new_name',
    ]);
});

test('authenticated user cannot change existing username', function () {
    $user = User::factory()->create(['username' => 'existing_name']);

    $response = $this->actingAs($user)->postJson('/api/users/set-username', [
        'username' => 'new_name',
    ]);

    $response->assertStatus(422);
    $this->assertDatabaseHas('subscribers', [
        'sId' => $user->sId,
        'username' => 'existing_name',
    ]);
});

test('set username rejects duplicate username', function () {
    User::factory()->create(['username' => 'already_taken']);
    $user = User::factory()->create(['username' => null]);

    $response = $this->actingAs($user)->postJson('/api/users/set-username', [
        'username' => 'already_taken',
    ]);

    $response->assertStatus(422);
});

test('set username rejects invalid format', function () {
    $user = User::factory()->create(['username' => null]);

    $response = $this->actingAs($user)->postJson('/api/users/set-username', [
        'username' => 'ab',
    ]);

    $response->assertStatus(422);
});
