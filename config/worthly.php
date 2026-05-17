<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LLM Configuration
    |--------------------------------------------------------------------------
    |
    | Defines which model the Laravel AI SDK Agents will use when running
    | product analysis prompts. Override via the WORTHLY_LLM_MODEL env var.
    |
    */

    'llm' => [
        'model' => env('WORTHLY_LLM_MODEL', 'gpt-5.5'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent Harness
    |--------------------------------------------------------------------------
    |
    | Configuration tree for the multi-layer analysis pipeline that wraps
    | the ProductReviewer agent. See docs/agent-harness.md §11 for the full
    | specification.
    |
    */

    'harness' => [
        'enabled' => env('WORTHLY_HARNESS_ENABLED', false),
        'cheap_model' => env('WORTHLY_HARNESS_CHEAP_MODEL', 'gpt-5-mini'),

        'query_enricher' => [
            'sub_query_count' => 4,
            'use_hyde' => false,
        ],

        'retrievers' => [
            'shopping' => [
                'enabled' => true,
                'providers' => ['searchapi', 'mercadolivre'],
                'timeout_ms' => 4000,
            ],
            'reviews' => [
                'enabled' => true,
                'provider' => 'tavily',
                'timeout_ms' => 4000,
                'include_domains' => [
                    'rtings.com',
                    'wirecutter.com',
                    'techradar.com',
                    'gsmarena.com',
                    'tomshardware.com',
                    'cnet.com',
                ],
            ],
            'general' => [
                'enabled' => true,
                'provider' => 'tavily',
                'timeout_ms' => 3000,
            ],
        ],

        'rerank' => [
            'provider' => env('WORTHLY_RERANK_PROVIDER', 'cohere'),
            'model' => env('WORTHLY_RERANK_MODEL', 'rerank-v3.5'),
            'top_k' => 8,
        ],

        'verifier' => [
            'enabled' => env('WORTHLY_VERIFIER_ENABLED', false),
            'max_revisions' => 1,
        ],

        'budget' => [
            'max_llm_calls' => 4,
            'max_retrieval_calls' => 6,
            'max_tokens_total' => 25_000,
            'max_latency_ms' => 12_000,
        ],

        'cache' => [
            'retrieval_ttl' => [
                'shopping' => 3600,
                'reviews' => 86400,
                'general' => 21600,
            ],
            'embedding_ttl' => 60 * 60 * 24 * 30,
            'response_ttl' => 86400,
        ],

        'authority' => [
            'rtings.com' => 0.95,
            'wirecutter.com' => 0.92,
            'techradar.com' => 0.85,
            'gsmarena.com' => 0.85,
            'tomshardware.com' => 0.88,
            'cnet.com' => 0.82,
            'reddit.com' => 0.5,
        ],
    ],

];
