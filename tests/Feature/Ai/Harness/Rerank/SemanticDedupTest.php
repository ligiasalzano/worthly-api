<?php

use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\EmbeddingClient;
use App\Ai\Harness\Rerank\Filters\SemanticDedupFilter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('cache.default', 'array');
    config()->set('worthly.harness.cache.embedding_ttl', 3600);
    Cache::store('array')->flush();
});

function dedupItem(string $title, string $snippet, float $authority, string $channel = 'reviews'): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: $snippet,
        publishedAt: null,
        authorityScore: $authority,
        rawRelevance: 0.5,
    );
}

it('drops the lower-authority near-duplicate snippet and caches each embedding', function () {
    $high = dedupItem('high', 'The iPhone 15 is great with strong reviews and competitive prices.', 0.92);
    $low = dedupItem('low', 'The iPhone 15 is great with strong reviews and competitive pricing.', 0.40);
    $other = dedupItem('other', 'Completely different topic about kitchen appliances and home cooking.', 0.80);

    $vectorHigh = [1.0, 0.0, 0.0];
    $vectorLow = [0.99, 0.0, 0.14];
    $vectorOther = [0.0, 1.0, 0.0];

    $sequence = Http::sequence()
        ->push(['data' => [['embedding' => $vectorHigh]]], 200)
        ->push(['data' => [['embedding' => $vectorLow]]], 200)
        ->push(['data' => [['embedding' => $vectorOther]]], 200);

    Http::fake([
        EmbeddingClient::ENDPOINT => $sequence,
    ]);

    $filter = new SemanticDedupFilter(app(EmbeddingClient::class));
    $kept = $filter->apply([$high, $low, $other]);

    expect($kept)->toHaveCount(2);
    expect(array_map(fn ($i) => $i->title, $kept))->toBe(['high', 'other']);

    $embeddings = app(EmbeddingClient::class);

    expect(Cache::store('array')->has($embeddings->cacheKey($high->snippet)))->toBeTrue();
    expect(Cache::store('array')->has($embeddings->cacheKey($low->snippet)))->toBeTrue();
    expect(Cache::store('array')->has($embeddings->cacheKey($other->snippet)))->toBeTrue();
});

it('keeps the higher-authority duplicate when it appears second', function () {
    $low = dedupItem('low-first', 'Identical text about a product worth buying.', 0.40);
    $high = dedupItem('high-second', 'Identical text about a product worth buying.', 0.95);

    Http::fake([
        EmbeddingClient::ENDPOINT => Http::sequence()
            ->push(['data' => [['embedding' => [1.0, 0.0]]]], 200)
            ->push(['data' => [['embedding' => [1.0, 0.0]]]], 200),
    ]);

    $filter = new SemanticDedupFilter(app(EmbeddingClient::class));
    $kept = $filter->apply([$low, $high]);

    expect(array_map(fn ($i) => $i->title, $kept))->toBe(['high-second']);
});

it('returns items untouched when below the cosine threshold', function () {
    $a = dedupItem('a', 'Apples are red fruits used in pies.', 0.8);
    $b = dedupItem('b', 'Smartphones include high-end processors and cameras.', 0.7);

    Http::fake([
        EmbeddingClient::ENDPOINT => Http::sequence()
            ->push(['data' => [['embedding' => [1.0, 0.0]]]], 200)
            ->push(['data' => [['embedding' => [0.0, 1.0]]]], 200),
    ]);

    $filter = new SemanticDedupFilter(app(EmbeddingClient::class));
    $kept = $filter->apply([$a, $b]);

    expect($kept)->toHaveCount(2);
});
