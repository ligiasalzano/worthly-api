<?php

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\EvidenceItem;
use App\Ai\Harness\Rerank\CohereReranker;
use App\Enums\Intent;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->previousCohereKey = Env::getRepository()->get('COHERE_API_KEY');
    Env::getRepository()->set('COHERE_API_KEY', 'co-fake-token');

    config()->set('worthly.harness.rerank.model', 'rerank-v3.5');
});

afterEach(function () {
    if ($this->previousCohereKey === false) {
        Env::getRepository()->clear('COHERE_API_KEY');
    } else {
        Env::getRepository()->set('COHERE_API_KEY', $this->previousCohereKey);
    }
});

function cohereQuery(): EnrichedQuery
{
    return new EnrichedQuery(
        rawQuery: 'iPhone 15 vale a pena',
        productName: 'iPhone 15',
        brand: 'Apple',
        category: 'smartphone',
        region: 'BR',
        useCase: null,
        budgetHint: null,
        intent: Intent::BuyDecision,
    );
}

function cohereItem(string $title, string $snippet, string $channel = 'reviews'): EvidenceItem
{
    return new EvidenceItem(
        sourceChannel: $channel,
        url: 'https://example.test/'.urlencode($title),
        title: $title,
        snippet: $snippet,
        publishedAt: null,
        authorityScore: 0.7,
        rawRelevance: 0.5,
    );
}

it('issues a single POST to Cohere with all candidate snippets and reorders by response indices', function () {
    $items = [
        cohereItem('A', 'snippet A'),
        cohereItem('B', 'snippet B'),
        cohereItem('C', 'snippet C'),
        cohereItem('D', 'snippet D'),
    ];

    Http::fake([
        CohereReranker::ENDPOINT => Http::response([
            'results' => [
                ['index' => 2, 'relevance_score' => 0.95],
                ['index' => 0, 'relevance_score' => 0.81],
                ['index' => 3, 'relevance_score' => 0.65],
                ['index' => 1, 'relevance_score' => 0.40],
            ],
        ], 200),
    ]);

    $reranked = (new CohereReranker)->rerank(cohereQuery(), $items, 4);

    Http::assertSentCount(1);

    Http::assertSent(function ($request) {
        if ($request->method() !== 'POST') {
            return false;
        }
        if ($request->url() !== CohereReranker::ENDPOINT) {
            return false;
        }

        $body = $request->data();

        return ($body['model'] ?? null) === 'rerank-v3.5'
            && ($body['query'] ?? null) === 'iPhone 15'
            && ($body['documents'] ?? null) === ['snippet A', 'snippet B', 'snippet C', 'snippet D']
            && ($body['top_n'] ?? null) === 4;
    });

    expect(array_map(fn ($i) => $i->title, $reranked))->toBe(['C', 'A', 'D', 'B']);
    expect($reranked[0]->rerankScore)->toBe(0.95);
    expect($reranked[1]->rerankScore)->toBe(0.81);
});

it('breaks ties stably by original index ascending', function () {
    $items = [
        cohereItem('A', 'snippet A'),
        cohereItem('B', 'snippet B'),
        cohereItem('C', 'snippet C'),
    ];

    Http::fake([
        CohereReranker::ENDPOINT => Http::response([
            'results' => [
                ['index' => 1, 'relevance_score' => 0.70],
                ['index' => 0, 'relevance_score' => 0.70],
                ['index' => 2, 'relevance_score' => 0.70],
            ],
        ], 200),
    ]);

    $reranked = (new CohereReranker)->rerank(cohereQuery(), $items, 3);

    expect(array_map(fn ($i) => $i->title, $reranked))->toBe(['A', 'B', 'C']);
});

it('truncates the reranked list to topK', function () {
    $items = [
        cohereItem('A', 'snippet A'),
        cohereItem('B', 'snippet B'),
        cohereItem('C', 'snippet C'),
        cohereItem('D', 'snippet D'),
        cohereItem('E', 'snippet E'),
    ];

    Http::fake([
        CohereReranker::ENDPOINT => Http::response([
            'results' => [
                ['index' => 4, 'relevance_score' => 0.99],
                ['index' => 0, 'relevance_score' => 0.80],
                ['index' => 1, 'relevance_score' => 0.50],
                ['index' => 2, 'relevance_score' => 0.20],
                ['index' => 3, 'relevance_score' => 0.10],
            ],
        ], 200),
    ]);

    $reranked = (new CohereReranker)->rerank(cohereQuery(), $items, 2);

    expect(array_map(fn ($i) => $i->title, $reranked))->toBe(['E', 'A']);
});
