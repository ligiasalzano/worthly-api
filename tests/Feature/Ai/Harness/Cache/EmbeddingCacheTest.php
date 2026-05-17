<?php

use App\Ai\Harness\Rerank\EmbeddingClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('cache.default', 'array');
    Cache::store('array')->flush();
});

it('caches embeddings by sha1 of the text and reuses them on subsequent calls', function () {
    Http::fake([
        EmbeddingClient::ENDPOINT => Http::sequence()
            ->push(['data' => [['embedding' => [0.1, 0.2, 0.3]]]], 200),
    ]);

    $client = app(EmbeddingClient::class);

    $first = $client->embed('iPhone 15 review snippet text');
    $second = $client->embed('iPhone 15 review snippet text');

    expect($first)->toBe([0.1, 0.2, 0.3]);
    expect($second)->toBe($first);

    Http::assertSentCount(1);

    $expectedKey = 'worthly:e:'.sha1('iPhone 15 review snippet text');
    expect($client->cacheKey('iPhone 15 review snippet text'))->toBe($expectedKey);
    expect(Cache::store('array')->has($expectedKey))->toBeTrue();
});

it('honors the configured embedding TTL of 30 days', function () {
    expect((int) config('worthly.harness.cache.embedding_ttl'))
        ->toBe(60 * 60 * 24 * 30);
});

it('keys distinct texts to distinct cache entries', function () {
    $client = app(EmbeddingClient::class);

    expect($client->cacheKey('one'))->not->toBe($client->cacheKey('two'));
    expect($client->cacheKey('one'))->toBe('worthly:e:'.sha1('one'));
});
