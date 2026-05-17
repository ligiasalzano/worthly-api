<?php

namespace Tests\Support;

use App\Ai\Agents\ProductReviewer;
use App\Ai\Harness\AnalysisPipeline;
use Laravel\Ai\Responses\StructuredAgentResponse;

trait FakesProductReviewer
{
    protected function bindFakeProductReviewer(?StructuredAgentResponse $response = null): FakeProductReviewer
    {
        $fakeReviewer = new FakeProductReviewer($response);

        $this->app->instance(ProductReviewer::class, $fakeReviewer);
        $this->app->instance(AnalysisPipeline::class, new FakeAnalysisPipeline($fakeReviewer));

        return $fakeReviewer;
    }
}
