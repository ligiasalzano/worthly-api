<?php

it('exposes every harness configuration key with non-null defaults', function () {
    $keys = [
        'worthly.harness.enabled',
        'worthly.harness.cheap_model',

        'worthly.harness.query_enricher.sub_query_count',
        'worthly.harness.query_enricher.use_hyde',

        'worthly.harness.retrievers.shopping.enabled',
        'worthly.harness.retrievers.shopping.providers',
        'worthly.harness.retrievers.shopping.timeout_ms',
        'worthly.harness.retrievers.reviews.enabled',
        'worthly.harness.retrievers.reviews.provider',
        'worthly.harness.retrievers.reviews.timeout_ms',
        'worthly.harness.retrievers.reviews.include_domains',
        'worthly.harness.retrievers.general.enabled',
        'worthly.harness.retrievers.general.provider',
        'worthly.harness.retrievers.general.timeout_ms',

        'worthly.harness.rerank.provider',
        'worthly.harness.rerank.model',
        'worthly.harness.rerank.top_k',

        'worthly.harness.verifier.enabled',
        'worthly.harness.verifier.max_revisions',

        'worthly.harness.budget.max_llm_calls',
        'worthly.harness.budget.max_retrieval_calls',
        'worthly.harness.budget.max_tokens_total',
        'worthly.harness.budget.max_latency_ms',

        'worthly.harness.cache.retrieval_ttl.shopping',
        'worthly.harness.cache.retrieval_ttl.reviews',
        'worthly.harness.cache.retrieval_ttl.general',
        'worthly.harness.cache.embedding_ttl',
        'worthly.harness.cache.response_ttl',

        'worthly.harness.authority',
    ];

    foreach ($keys as $key) {
        expect(config($key))->not->toBeNull("Expected config key {$key} to have a non-null default");
    }
});

it('always treats worthly.harness.enabled as on regardless of any env override', function () {
    $source = file_get_contents(config_path('worthly.php'));

    expect($source)->not->toContain("env('WORTHLY_HARNESS_ENABLED'");

    $reloaded = require config_path('worthly.php');

    expect($reloaded['harness']['enabled'])->toBeTrue();
    expect(config('worthly.harness.enabled'))->toBeTrue();
});
