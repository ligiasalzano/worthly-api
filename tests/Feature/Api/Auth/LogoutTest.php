<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

it('returns 204 on successful logout with empty body', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/logout');

    $response->assertNoContent();
    expect($response->getContent())->toBe('');
});

it('invalidates the token used to logout', function () {
    $user = User::factory()->create();

    $newToken = $user->createToken('api');
    $token = $newToken->plainTextToken;
    $tokenId = $newToken->accessToken->getKey();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/logout')
        ->assertNoContent();

    expect(PersonalAccessToken::find($tokenId))->toBeNull();

    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('keeps a second token for the same user valid', function () {
    $user = User::factory()->create();

    $first = $user->createToken('first')->plainTextToken;
    $second = $user->createToken('second')->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$first)
        ->postJson('/api/logout')
        ->assertNoContent();

    app('auth')->forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$second)
        ->getJson('/api/me')
        ->assertOk();
});

it('returns 401 for unauthenticated logout requests', function () {
    $this->postJson('/api/logout')->assertStatus(401);
});
