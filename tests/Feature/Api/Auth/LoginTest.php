<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('returns 200 with a bearer token for valid credentials', function () {
    User::factory()->create([
        'email' => 'jane@example.com',
        'password' => Hash::make('super-secret'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'jane@example.com',
        'password' => 'super-secret',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['token', 'token_type'])
        ->assertJsonPath('token_type', 'Bearer');

    expect($response->json('token'))->toBeString()->not->toBeEmpty();
});

it('returns 401 with a generic message when password is wrong', function () {
    User::factory()->create([
        'email' => 'jane@example.com',
        'password' => Hash::make('super-secret'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'jane@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(401);

    $message = strtolower((string) $response->json('message'));
    expect($message)
        ->not->toContain('password')
        ->not->toContain('email');
});

it('returns 401 with the same generic message for unknown email (no enumeration)', function () {
    User::factory()->create([
        'email' => 'jane@example.com',
        'password' => Hash::make('super-secret'),
    ]);

    $unknown = $this->postJson('/api/login', [
        'email' => 'ghost@example.com',
        'password' => 'super-secret',
    ]);

    $wrongPassword = $this->postJson('/api/login', [
        'email' => 'jane@example.com',
        'password' => 'wrong-password',
    ]);

    $unknown->assertStatus(401);
    $wrongPassword->assertStatus(401);

    expect($unknown->json('message'))->toBe($wrongPassword->json('message'));
});

it('does not leak user fields beyond id/name/email in the response', function () {
    User::factory()->create([
        'email' => 'jane@example.com',
        'password' => Hash::make('super-secret'),
    ]);

    $response = $this->postJson('/api/login', [
        'email' => 'jane@example.com',
        'password' => 'super-secret',
    ]);

    $response->assertOk();

    $body = $response->json();
    $flat = json_encode($body);

    expect($flat)->not->toContain('"password"');
    expect($flat)->not->toContain('remember_token');
    expect($flat)->not->toContain('email_verified_at');

    if (isset($body['user'])) {
        expect(array_keys($body['user']))->toEqualCanonicalizing(['id', 'name', 'email']);
    }
});
