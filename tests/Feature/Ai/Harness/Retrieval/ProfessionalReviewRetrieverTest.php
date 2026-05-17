<?php

use App\Ai\Harness\Dto\EnrichedQuery;
use App\Ai\Harness\Dto\RetrievalContext;
use App\Ai\Harness\Retrieval\Adapters\ProfessionalReviewRetriever;
use App\Ai\Harness\Retrieval\Clients\TavilyClient;
use App\Enums\Intent;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->previousTavilyToken = Env::getRepository()->get('TAVILY_TOKEN');
    Env::getRepository()->set('TAVILY_TOKEN', 'tvly-fake-token');

    config()->set('worthly.harness.retrievers.reviews.include_domains', [
        'rtings.com',
        'wirecutter.com',
    ]);
    config()->set('worthly.harness.authority', [
        'rtings.com' => 0.95,
        'wirecutter.com' => 0.92,
    ]);
});

afterEach(function () {
    if ($this->previousTavilyToken === false) {
        Env::getRepository()->clear('TAVILY_TOKEN');
    } else {
        Env::getRepository()->set('TAVILY_TOKEN', $this->previousTavilyToken);
    }
});

function reviewsEnriched(): EnrichedQuery
{
    return new EnrichedQuery(
        rawQuery: 'iPhone 15 worth buying?',
        productName: 'iPhone 15',
        brand: 'Apple',
        category: 'smartphone',
        region: 'BR',
        useCase: null,
        budgetHint: null,
        intent: Intent::BuyDecision,
        subQueries: ['iPhone 15 review'],
        hydePassages: [],
    );
}

it('keeps only whitelisted domains and fills authority from config', function () {
    Http::fake([
        TavilyClient::BASE_URL.'/search' => Http::response([
            'results' => [
                ['url' => 'https://rtings.com/iphone-15', 'title' => 'iPhone 15 RTINGS Review', 'content' => 'great', 'score' => 0.9, 'published_date' => '2024-01-10'],
                ['url' => 'https://wirecutter.com/iphone-15', 'title' => 'iPhone 15 Wirecutter', 'content' => 'pick', 'score' => 0.85, 'published_date' => '2024-02-15'],
                ['url' => 'https://randomblog.example/post', 'title' => 'Random blog', 'content' => 'noise', 'score' => 0.5],
            ],
        ], 200),
    ]);

    $items = (new ProfessionalReviewRetriever(app(TavilyClient::class)))
        ->retrieve(reviewsEnriched(), new RetrievalContext);

    expect($items)->toHaveCount(2);
    expect($items[0]->sourceChannel)->toBe('reviews');
    expect($items[0]->authorityScore)->toBe(0.95);
    expect($items[1]->authorityScore)->toBe(0.92);
});

it('returns items older than typical cap (recency filtering belongs to L3)', function () {
    Http::fake([
        TavilyClient::BASE_URL.'/search' => Http::response([
            'results' => [
                ['url' => 'https://rtings.com/old', 'title' => 'Old review', 'content' => 'old', 'score' => 0.9, 'published_date' => '2010-01-01'],
            ],
        ], 200),
    ]);

    $items = (new ProfessionalReviewRetriever(app(TavilyClient::class)))
        ->retrieve(reviewsEnriched(), new RetrievalContext);

    expect($items)->toHaveCount(1);
    expect($items[0]->title)->toBe('Old review');
});

it('isEligible returns true for any non-Unknown intent', function () {
    $retriever = new ProfessionalReviewRetriever(app(TavilyClient::class));

    expect($retriever->isEligible(reviewsEnriched()))->toBeTrue();

    foreach ([Intent::Compare, Intent::SpecLookup] as $intent) {
        $query = new EnrichedQuery(
            rawQuery: 'q', productName: 'X', brand: null, category: null, region: null,
            useCase: null, budgetHint: null, intent: $intent,
        );
        expect($retriever->isEligible($query))->toBeTrue();
    }

    $unknown = new EnrichedQuery(
        rawQuery: 'q', productName: null, brand: null, category: null, region: null,
        useCase: null, budgetHint: null, intent: Intent::Unknown,
    );
    expect($retriever->isEligible($unknown))->toBeFalse();
});
