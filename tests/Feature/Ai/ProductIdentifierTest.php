<?php

use App\Ai\Agents\ProductIdentifier;
use App\Ai\Harness\Dto\EnrichedQuery;
use App\Enums\Intent;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

function makeProductIdentifierFake(array $structured): ProductIdentifier
{
    return new class($structured) extends ProductIdentifier
    {
        public function __construct(private array $structured) {}

        protected function callModel(string $imagePath, string $disk): StructuredAgentResponse
        {
            return new StructuredAgentResponse(
                invocationId: (string) Str::uuid7(),
                structured: $this->structured,
                text: 'fake',
                usage: new Usage(0, 0, 0, 0, 0),
                meta: new Meta,
            );
        }
    };
}

it('hydrates EnrichedQuery with extracted text and non-Unknown intent when product is identified', function () {
    Storage::fake('analysis_images');

    $fake = makeProductIdentifierFake([
        'extracted_text' => "iPhone 15 Pro Max\nApple\nMade in California",
        'product_name' => 'iPhone 15 Pro Max',
        'brand' => 'Apple',
        'category' => 'smartphone',
        'region' => 'BR',
        'use_case' => null,
        'budget_hint' => null,
        'intent' => 'buy_decision',
        'sub_queries' => [
            'iPhone 15 Pro Max preço',
            'iPhone 15 Pro Max review',
            'iPhone 15 Pro Max opinion',
            'iPhone 15 Pro Max alternatives',
        ],
    ]);

    $result = $fake->identify('analyses/iphone.jpg');

    expect($result)->toBeInstanceOf(EnrichedQuery::class);
    expect($result->rawQuery)->toBe("iPhone 15 Pro Max\nApple\nMade in California");
    expect($result->productName)->toBe('iPhone 15 Pro Max');
    expect($result->brand)->toBe('Apple');
    expect($result->intent)->not->toBe(Intent::Unknown);
    expect($result->intent)->toBe(Intent::BuyDecision);
    expect($result->subQueries)->toHaveCount(4);
});

it('forces intent to Unknown when productName is null', function () {
    Storage::fake('analysis_images');

    $fake = makeProductIdentifierFake([
        'extracted_text' => null,
        'product_name' => null,
        'brand' => null,
        'category' => null,
        'region' => null,
        'use_case' => null,
        'budget_hint' => null,
        'intent' => 'buy_decision',
        'sub_queries' => [],
    ]);

    $result = $fake->identify('analyses/blurry.jpg');

    expect($result->productName)->toBeNull();
    expect($result->intent)->toBe(Intent::Unknown);
    expect($result->rawQuery)->toBe('');
});
