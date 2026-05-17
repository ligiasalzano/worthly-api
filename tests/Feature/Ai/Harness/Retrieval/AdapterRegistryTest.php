<?php

use App\Ai\Harness\Retrieval\AdapterRegistry;
use App\Ai\Harness\Retrieval\Adapters\GeneralWebRetriever;
use App\Ai\Harness\Retrieval\Adapters\ProfessionalReviewRetriever;
use App\Ai\Harness\Retrieval\Adapters\ShoppingRetriever;
use App\Ai\Harness\Retrieval\RetrievalRouter;

beforeEach(function () {
    config()->set('worthly.harness.retrievers.shopping.enabled', true);
    config()->set('worthly.harness.retrievers.reviews.enabled', true);
    config()->set('worthly.harness.retrievers.general.enabled', true);
});

it('enables all three adapters when every channel is enabled', function () {
    $registry = app(AdapterRegistry::class);
    $router = new RetrievalRouter($registry->enabled());

    expect($router->retrievers())->toHaveCount(3);
});

it('removes the shopping adapter when the shopping channel is disabled', function () {
    config()->set('worthly.harness.retrievers.shopping.enabled', false);

    $router = new RetrievalRouter(app(AdapterRegistry::class)->enabled());

    expect($router->retrievers())->toHaveCount(2);
    foreach ($router->retrievers() as $r) {
        expect($r)->not->toBeInstanceOf(ShoppingRetriever::class);
    }
});

it('removes the reviews adapter when the reviews channel is disabled', function () {
    config()->set('worthly.harness.retrievers.reviews.enabled', false);

    $router = new RetrievalRouter(app(AdapterRegistry::class)->enabled());

    expect($router->retrievers())->toHaveCount(2);
    foreach ($router->retrievers() as $r) {
        expect($r)->not->toBeInstanceOf(ProfessionalReviewRetriever::class);
    }
});

it('removes the general adapter when the general channel is disabled', function () {
    config()->set('worthly.harness.retrievers.general.enabled', false);

    $router = new RetrievalRouter(app(AdapterRegistry::class)->enabled());

    expect($router->retrievers())->toHaveCount(2);
    foreach ($router->retrievers() as $r) {
        expect($r)->not->toBeInstanceOf(GeneralWebRetriever::class);
    }
});
