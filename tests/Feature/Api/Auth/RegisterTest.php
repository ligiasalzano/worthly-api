<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('returns 201 with a fresh bearer token on successful registration', function () {
    $payload = [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ];

    $response = $this->postJson('/api/register', $payload);

    $response->assertCreated()
        ->assertJsonStructure(['token', 'token_type'])
        ->assertJsonPath('token_type', 'Bearer');

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('persists the user with a hashed password', function () {
    $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ])->assertCreated();

    $user = User::firstWhere('email', 'jane@example.com');

    expect($user)->not->toBeNull();
    expect($user->password)->not->toBe('super-secret');
    expect(Hash::check('super-secret', $user->password))->toBeTrue();
});

it('rejects duplicate email with 422 and errors.email', function () {
    User::factory()->create(['email' => 'jane@example.com']);

    $response = $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects when password_confirmation is missing', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'super-secret',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects passwords shorter than 8 characters', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('never returns password or remember_token in the response', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
        'password' => 'super-secret',
        'password_confirmation' => 'super-secret',
    ]);

    $response->assertCreated();

    $body = $response->json();
    $flat = json_encode($body);

    expect($flat)->not->toContain('"password"');
    expect($flat)->not->toContain('remember_token');
});
