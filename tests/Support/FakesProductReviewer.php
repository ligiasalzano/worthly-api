<?php

namespace Tests\Support;

use App\Ai\Agents\ProductReviewer;
use Laravel\Ai\Responses\StructuredAgentResponse;

trait FakesProductReviewer
{
    protected function bindFakeProductReviewer(?StructuredAgentResponse $response = null): void
    {
        $this->app->instance(
            ProductReviewer::class,
            new FakeProductReviewer($response),
        );
    }
}
