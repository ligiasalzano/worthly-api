# Agent Harness — Specification

> Specification for the multi-layer harness around `App\Ai\Agents\ProductReviewer`.
> The goal is to move from a single naïve LLM call ("agent that promises web search but has no tools") to a production-grade pipeline that mirrors what ChatGPT, Claude.ai and Perplexity do internally: query understanding → vertical retrieval → rerank → grounded generation → verification → caching.

---

## 1. Goals and non-goals

### Goals
- Ground every recommendation in **real, citable evidence** (price, reviews, alternatives) instead of model parametric memory.
- Make each stage **independently testable** and **independently swappable** (a different rerank provider, a different retriever, a different model) without rewriting the agent.
- Keep cost and latency **predictable** through explicit budgets, parallelism and caching.
- Expose **confidence and sources** to the API so the frontend can show evidence and degrade gracefully when the model is unsure.

### Non-goals
- Building a generic agent framework. We are solving the buying-recommendation use case; abstractions must earn their place.
- Replacing `laravel/ai`. The harness sits **around** the SDK, not in front of it. Agents still `implements Laravel\Ai\Contracts\Agent` and use `Promptable`.
- Real-time price feeds with sub-minute freshness. Hourly freshness is fine for the POC.

---

## 2. Current state and gap analysis

The current pipeline is:

```
HTTP → ProductAnalysisService → ProductReviewer::analyzeText|analyzeImage → 1 LLM call → DB
```

Gaps:
1. `ProductReviewer::SYSTEM_PROMPT` instructs the model to *"use built-in web search"* but no tools are registered on `$this->prompt(...)`. The promise is a lie; the model improvises from training data.
2. There is no query understanding. Free-text user input goes straight to the model.
3. There is no notion of **source**, **citation**, **recency** or **confidence** in `StructuredAgentResponse` or in the `analyses` table.
4. Images are sent directly to the recommendation model. A single ambiguous photo can derail the whole analysis because identification and reasoning are coupled.
5. No cache, no budget cap. Cost is unbounded and identical queries pay full price.

---

## 3. Target architecture

```
                    ┌───────────────────────────────────────────────┐
                    │              ProductAnalysisService           │
                    │  (orchestrator — persistence, transactions)   │
                    └───────────────────────────┬───────────────────┘
                                                │
                                                ▼
                    ┌───────────────────────────────────────────────┐
                    │          AnalysisPipeline (harness)           │
                    └───────────────────────────────────────────────┘
                                                │
   ┌─────────────┬─────────────┬────────────────┼───────────────┬──────────────┐
   ▼             ▼             ▼                ▼               ▼              ▼
[L1 Query    [L2 Vertical   [L3 Rerank      [L4 Grounded    [L5 Verifier   [L6 Cache &
 Understand]  Retrieval]     & Filter]       Generation]     (optional)]    Budget]
   │             │             │                │               │              │
   │  • intent   │  • shopping │  • cross-enc.  │  • Product    │  • critic    │  • Redis
   │  • entities │  • reviews  │    rerank      │    Reviewer   │    agent     │  • multi-tier
   │  • decomp.  │  • UGC      │  • dedup       │  • cite-or-   │  • NLI claim │  • budget
   │  • HyDE     │  • specs    │  • authority   │    fail       │    check     │    enforcement
   │  • image→   │  • general  │  • recency     │  • structured │              │
   │    text     │             │    decay       │    output     │              │
```

Every layer is a small, typed unit that takes a DTO and returns a DTO. The orchestrator wires them. Each layer is feature-flagged so we can ship layer by layer.

### 3.1 File layout

```
app/
├── Ai/
│   ├── Agents/
│   │   ├── ProductReviewer.php          # L4: structured output, now with sources[]
│   │   ├── ProductIdentifier.php        # L1: vision-only, cheap, image → entity
│   │   ├── QueryEnricher.php            # L1: text → structured intent + sub-queries
│   │   └── EvidenceVerifier.php         # L5: optional critic
│   └── Harness/
│       ├── AnalysisPipeline.php         # the orchestrator
│       ├── Contracts/
│       │   ├── Retriever.php            # one vertical adapter
│       │   ├── Reranker.php
│       │   └── CitationStore.php
│       ├── Dto/
│       │   ├── EnrichedQuery.php
│       │   ├── EvidenceItem.php
│       │   ├── EvidenceBundle.php
│       │   └── PipelineResult.php
│       ├── Retrieval/
│       │   ├── RetrievalRouter.php      # fan-out + merge
│       │   ├── Adapters/
│       │   │   ├── ShoppingRetriever.php
│       │   │   ├── ProfessionalReviewRetriever.php
│       │   │   └── GeneralWebRetriever.php
│       │   └── Clients/                 # HTTP wrappers (Tavily, SearchApi, MercadoLivre)
│       ├── Rerank/
│       │   ├── CohereReranker.php
│       │   └── NullReranker.php         # passthrough for tests / flag-off
│       └── Budget/
│           ├── BudgetGuard.php
│           └── PipelineBudget.php
└── Services/
    └── ProductAnalysisService.php       # unchanged role: persistence + transactions
```

> `app/Ai/Harness/` is new but follows the same rule as `app/Ai/Agents/`: AI orchestration lives under `app/Ai/`, **never** under `app/Services/Ai/`.

---

## 4. Layer 1 — Query understanding

Two agents, depending on input type.

### 4.1 `QueryEnricher` (text input)

**Input:** raw user text (e.g. *"vale a pena comprar iPhone 15?"*).
**Output:** `EnrichedQuery` DTO.

```php
final readonly class EnrichedQuery
{
    public function __construct(
        public string $rawQuery,
        public ?string $productName,         // "iPhone 15"
        public ?string $brand,               // "Apple"
        public ?string $category,            // "smartphone"
        public ?string $region,              // "BR"
        public ?string $useCase,             // optional, from context
        public ?string $budgetHint,          // optional, free text
        public Intent $intent,               // BuyDecision | Compare | SpecLookup | Unknown
        /** @var list<string> */
        public array $subQueries,            // 3–5 expanded queries for retrieval
        /** @var list<string> */
        public array $hydePassages,          // optional HyDE: model-imagined ideal answers
    ) {}
}
```

The agent runs **one** structured-output call. Model: cheap tier (`gpt-5-mini` / `haiku-4-5`) — this is not the reasoning step. Configured via `worthly.harness.query_enricher.model`.

Sub-queries are generated for the four research axes:
- price/availability ("iPhone 15 preço Brasil 2026")
- professional reviews ("iPhone 15 review Wirecutter RTINGS")
- user opinion ("iPhone 15 reddit experience problems")
- alternatives ("iPhone 15 vs Samsung S24 Pixel 8")

If `intent === Unknown` the pipeline short-circuits to a `product_not_identified` response without spending more tokens.

### 4.2 `ProductIdentifier` (image input)

Vision-only agent. Takes the uploaded image, returns the same `EnrichedQuery` shape with `rawQuery` filled from extracted visible text (brand, model, packaging copy).

```php
public function identify(string $imagePath, string $disk = 'product_images'): EnrichedQuery
```

After identification, the text path through `QueryEnricher` is **not** re-run; identification already produces the enriched form. This keeps image flows to two LLM calls (identify → reason) instead of three.

If identification confidence is low (`productName === null`), we return `product_not_identified` immediately without paying for retrieval.

### 4.3 Test strategy

Both agents follow the project's "fake by subclass" pattern (see `CLAUDE.md` → AI section):

```php
$fake = new class extends QueryEnricher {
    public function enrich(string $query): EnrichedQuery { /* canned */ }
};
$this->app->instance(QueryEnricher::class, $fake);
```

No `LlmClient`, no Mockery against the SDK.

---

## 5. Layer 2 — Vertical retrieval

The retrieval layer is a **router with N adapters running in parallel**, not a single web-search call.

### 5.1 Adapter contract

```php
interface Retriever
{
    public function name(): string;          // 'shopping', 'reviews', 'ugc', 'specs', 'general'

    /** @return list<EvidenceItem> */
    public function retrieve(EnrichedQuery $query, RetrievalContext $ctx): array;

    public function isEligible(EnrichedQuery $query): bool;   // gate by category/region
}
```

```php
final readonly class EvidenceItem
{
    public function __construct(
        public string $sourceChannel,        // 'shopping' | 'reviews' | ...
        public string $url,
        public string $title,
        public string $snippet,              // <= 800 chars, cleaned text
        public ?CarbonImmutable $publishedAt,
        public float $authorityScore,        // 0..1, see §5.4
        public float $rawRelevance,          // provider-given, pre-rerank
    ) {}
}
```

### 5.2 Adapters (POC scope)

| Adapter | Provider | Sub-query axis | Why |
|---|---|---|---|
| `ShoppingRetriever` | SearchApi.io (`engine=google_shopping`) **+** Mercado Livre public API (`/sites/MLB/search`, no auth) | price/availability | structured price + recency, BR coverage |
| `ProfessionalReviewRetriever` | Tavily with `include_domains: [rtings.com, wirecutter.com, techradar.com, gsmarena.com, tomshardware.com, cnet.com]` | reviews | high-authority |
| `GeneralWebRetriever` | Tavily (open web) | fallback + opinion | recall safety net; Tavily already indexes Reddit / forum content so the "opinion" axis gets partial coverage here |

> **Deferred** (not in POC scope — no credentials acquired yet): a dedicated `UserGeneratedRetriever` (Reddit OAuth) and `SpecSheetRetriever` (GSMArena scraping). The enricher still produces an "opinion" sub-query — it just rides through `GeneralWebRetriever` for now. See §15.

Whitelists/blacklists live in `config/worthly.php` under `harness.retrievers.*.include_domains`.

### 5.3 `RetrievalRouter`

```php
final class RetrievalRouter
{
    /** @param list<Retriever> $retrievers */
    public function __construct(
        private array $retrievers,
        private BudgetGuard $budget,
    ) {}

    public function gather(EnrichedQuery $query): EvidenceBundle
    {
        // 1. filter eligible adapters
        // 2. fan out with Http::pool / parallel Promise
        // 3. merge results, tag with sourceChannel
        // 4. enforce per-adapter and global caps (max items, max latency)
    }
}
```

Parallelism uses Laravel's `Http::pool()` for HTTP-based adapters. LLM-backed retrievers (none in POC) would use the SDK's batching when available.

Budget knobs (per request):
- `max_items_per_adapter`: default 8
- `max_total_items`: default 30 (input to rerank)
- `per_adapter_timeout_ms`: default 4000
- `total_retrieval_timeout_ms`: default 6000

When an adapter times out or errors, we **degrade silently** — log the failure, drop the channel, continue. A retrieval pass that returns zero items short-circuits to `insufficient_evidence` (new enum case, see §10.1).

### 5.4 Authority scoring

Static per-domain map in config (`harness.authority`) — e.g. `rtings.com => 0.95`, `wirecutter.com => 0.92`, `random-blog.example => 0.3`. Reddit / forum URLs surfaced via the general adapter get `0.5` (we can't read upvotes without OAuth, so no per-thread boost). Unknown domains default to `0.4`.

This score is one of the rerank features (§6.3) but is **not** the primary ranker — it is a regularizer.

### 5.5 Cache

Each adapter call is keyed by `sha1(adapter_name + normalized_sub_query + region)`:
- Shopping/price: TTL **1 hour**.
- Reviews / specs: TTL **24 hours**.
- UGC: TTL **6 hours**.

Cache lives in Redis (`cache.stores.redis` already configured by Sail). See §9.

---

## 6. Layer 3 — Rerank and filter

### 6.1 Why a dedicated reranker

A cross-encoder reranker reads each `(query, snippet)` pair and produces a fine-grained relevance score. This is meaningfully better than the bag-of-keywords ranking we get from search providers, especially when the user's query is fuzzy ("worth buying?") and the snippets are long.

### 6.2 Reranker contract

```php
interface Reranker
{
    /**
     * @param  list<EvidenceItem>  $items
     * @return list<EvidenceItem>  reordered, possibly truncated
     */
    public function rerank(EnrichedQuery $query, array $items, int $topK): array;
}
```

Default implementation: `CohereReranker` (Cohere Rerank 3 — key already in `.env` as `COHERE_API_KEY`). Test/dev: `NullReranker` (identity).

The reranker call is **one batched HTTP request** per pipeline run. Top-K target: `8` (configurable).

### 6.3 Post-rerank filters

Applied in order on the top-K window:

1. **Semantic dedup** — compute a cheap embedding (cached) per item; collapse pairs with cosine > 0.92 keeping the higher-authority one.
2. **Authority floor** — drop items with `authorityScore < 0.3` unless they are the only evidence for their channel.
3. **Recency decay** for the `shopping` channel — anything older than 60 days is dropped (price stale).
4. **Channel diversity** — ensure at least one item from `shopping` and one from `reviews` survive in the top-K when available, even if their rerank score is lower. Avoids monoculture inputs (e.g. only review-blog items, no price evidence) being fed to the model.

The result is an `EvidenceBundle` of 6–10 items, each with a stable integer ID (`S1`, `S2`, …) used for citations.

---

## 7. Layer 4 — Grounded generation

This is the existing `ProductReviewer`, but rewired.

### 7.1 New prompt contract

The system prompt drops the **"use built-in web search"** lie and is replaced with:

```
You are Worthly, an AI product reviewer.

You will receive:
- An enriched query (product, category, region, intent)
- A numbered list of evidence items [S1]..[Sn], each with source URL,
  publication date and snippet.

You MUST:
- Base every factual claim (price, specs, reviews, alternatives) on the
  evidence. For each populated field in your response, list the supporting
  evidence IDs in `sources_used`.
- If the evidence is insufficient to identify the product or to recommend,
  set `recommendation.decision` to `insufficient_evidence` and explain why.
- Never invent prices or sources outside the provided list.
```

### 7.2 Schema changes

Add to the structured output:

```php
'sources_used' => $schema->array()
    ->items($schema->object(fn ($s) => [
        'field' => $s->string()->enum(['product', 'summary', 'cost_benefit', 'similar_products', 'recommendation'])->required(),
        'evidence_ids' => $schema->array()->items($s->integer())->required(),
    ]))
    ->required(),

'confidence' => $schema->string()->enum(['high', 'medium', 'low'])->required(),
```

The `RecommendationDecision` enum gains:
```php
case InsufficientEvidence = 'insufficient_evidence';
```
(plus matching DB seeder row and a new migration adding the slug to `recommendation_decisions`).

### 7.3 Method signatures

`ProductReviewer` keeps `analyzeText`/`analyzeImage` for backwards compatibility but adds the canonical entry:

```php
public function recommend(EnrichedQuery $query, EvidenceBundle $evidence): StructuredAgentResponse
```

The two legacy methods are kept as **thin shims** that call into the pipeline. They become deprecated in the OpenAPI spec and removed once the frontend migrates.

### 7.4 Model

Reasoning step uses the strong model (config `worthly.llm.model`, currently `gpt-5.5`). All other LLM calls (enrich, identify, verify) use a cheap-tier model from `worthly.harness.cheap_model`.

---

## 8. Layer 5 — Evidence verifier (optional, flag-gated)

A second LLM pass (`EvidenceVerifier` agent) that takes `{output, evidence}` and returns:

```php
public function verify(array $structuredOutput, EvidenceBundle $evidence): VerificationReport;
```

Where `VerificationReport` flags each claim as `supported | partially_supported | unsupported`. If any claim is `unsupported`, the orchestrator either:
- downgrades `confidence` to `low` and strips the unsupported claim, **or**
- re-runs the generation step with a "revise the following unsupported claims" follow-up (max 1 retry).

Gated by `worthly.harness.verifier.enabled` — **off by default in POC**, on in production once evals show it pays off.

Verifier uses the cheap model. Cost: ~+15% per request when enabled.

---

## 9. Layer 6 — Cache and budget

### 9.1 Three cache tiers

| Tier | Key | TTL | Purpose |
|---|---|---|---|
| **Retrieval cache** | `worthly:r:<adapter>:<hash>` | 1h–24h (per channel) | dedup external API spend |
| **Embedding cache** | `worthly:e:<sha1(text)>` | 30d | reuse vectors for dedup/rerank |
| **Response cache** | `worthly:resp:<sha1(enriched_query + evidence_ids)>` | 24h | idempotency for retries; **off** for image input |

All keys live in Redis. Response cache is keyed by the **enriched** query, so two free-text inputs that normalize to the same product hit the cache.

### 9.2 Budget enforcement

```php
final class PipelineBudget
{
    public function __construct(
        public int $maxLlmCalls = 4,              // enrich + reason + verify + revise
        public int $maxRetrievalCalls = 6,
        public int $maxTokensTotal = 25_000,
        public int $maxLatencyMs = 12_000,
    ) {}
}
```

`BudgetGuard` is injected into each layer; before any external call it checks remaining budget. When budget is exhausted, the pipeline returns the best result so far with `confidence = low` and a `degraded: true` flag in the response metadata. **Never** silently drop budget violations — they are logged and counted.

### 9.3 Observability

Every pipeline run emits structured logs and metrics:
- per-layer latency, cost (tokens × model price), cache hit ratio
- per-adapter result count, timeout count, error rate
- rerank score distribution
- final confidence, decision, degraded flag

Telemetry sink: existing Laravel logging + (optional) a `harness_runs` table for offline analysis. Schema:

```
harness_runs(
  id, analysis_id, started_at, finished_at, total_ms,
  llm_calls, retrieval_calls, tokens_in, tokens_out,
  cache_hit, degraded, budget_exhausted, error,
  layers jsonb  -- per-layer breakdown
)
```

---

## 10. End-to-end DTO flow

```
HTTP request
  └─► ProductAnalysisService::analyze(User, Input)
        └─► AnalysisPipeline::run(Input)
              1. EnrichedQuery       ← QueryEnricher | ProductIdentifier
              2. EvidenceBundle (raw)← RetrievalRouter
              3. EvidenceBundle (top)← Reranker + filters
              4. StructuredAgentResponse ← ProductReviewer::recommend
              5. VerificationReport  ← EvidenceVerifier (optional)
              ↳ PipelineResult { response, evidence, metadata }
        └─► persist Analysis + SimilarProduct + AnalysisSource (new table)
```

### 10.1 New persistence

```
analysis_sources(
  id, analysis_id, position, source_channel, url, title, snippet,
  authority_score, rerank_score, published_at
)
```

`Analysis` gains `confidence` (enum: high/medium/low) and `degraded` (boolean).

`RecommendationDecision` seed gains `insufficient_evidence`.

The `AnalysisResource` (already in the API) gets `sources[]`, `confidence` and `degraded`. The frontend can hide low-confidence results or show evidence links.

---

## 11. Configuration

`config/worthly.php`:

```php
return [
    'llm' => [
        'model' => env('WORTHLY_LLM_MODEL', 'gpt-5.5'),
    ],

    'harness' => [
        'enabled' => env('WORTHLY_HARNESS_ENABLED', false),  // master flag, see §13
        'cheap_model' => env('WORTHLY_HARNESS_CHEAP_MODEL', 'gpt-5-mini'),

        'query_enricher' => [
            'sub_query_count' => 4,
            'use_hyde' => false,
        ],

        'retrievers' => [
            'shopping' => [
                'enabled' => true,
                'providers' => ['searchapi', 'mercadolivre'],   // fan out in parallel, merge results
                'timeout_ms' => 4000,
            ],
            'reviews' => [
                'enabled' => true,
                'provider' => 'tavily',
                'timeout_ms' => 4000,
                'include_domains' => [
                    'rtings.com', 'wirecutter.com', 'techradar.com',
                    'gsmarena.com', 'tomshardware.com', 'cnet.com',
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
            'retrieval_ttl' => ['shopping' => 3600, 'reviews' => 86400, 'general' => 21600],
            'embedding_ttl' => 60 * 60 * 24 * 30,
            'response_ttl' => 86400,
        ],

        'authority' => [
            'rtings.com' => 0.95,
            'wirecutter.com' => 0.92,
            // ...
        ],
    ],
];
```

Secrets (`TAVILY_TOKEN`, `SEARCH_TOKEN`, `COHERE_API_KEY`) go in `.env` and are read inside the client wrappers, **not** in agents or services directly. Mercado Livre uses unauthenticated public endpoints (no key). See §17 for the full credential map.

---

## 12. Testing strategy

### 12.1 Per-layer

- **`QueryEnricher` / `ProductIdentifier`**: fake by anonymous subclass (see §4.3). Test that the enriched output drives the right sub-queries and the right intent.
- **Retrievers**: each adapter has its own feature test with `Http::fake()`. Verify domain whitelists are respected and timeouts degrade gracefully.
- **`RetrievalRouter`**: integration test with two fake adapters, one timing out and one succeeding; assert the timing-out one is logged and the bundle still returns.
- **Rerankers**: `Http::fake()` for Jina/Cohere; assert the ordering and top-K truncation.
- **`ProductReviewer::recommend`**: fake the agent (existing pattern), feed a synthetic `EvidenceBundle`, assert that `sources_used` references only IDs that exist in the bundle.
- **`EvidenceVerifier`**: fake; assert that unsupported claims trigger revision or confidence downgrade per config.
- **`BudgetGuard`**: unit tests for budget arithmetic and the degraded-path branch.

### 12.2 End-to-end pipeline

`tests/Feature/Ai/AnalysisPipelineTest.php`:
- Wire fake agents (`app->instance(...)`) + `Http::fake()` for retrievers + Redis array driver.
- Assert: persistence side effects (`Analysis`, `SimilarProduct`, `AnalysisSource`), the API resource shape (`sources[]`, `confidence`), and that the `recommendation_decision` and seed rows are correct.

### 12.3 Eval harness

A small golden dataset under `tests/Fixtures/Ai/Evals/` with ~20 hand-curated `{input, expected_decision, expected_must_cite_domains}` rows. A standalone test (`tests/Feature/Ai/EvalHarnessTest.php`, skipped unless `WORTHLY_RUN_EVALS=1`) calls the real pipeline against the live providers and asserts:
- decision matches OR is one of the acceptable adjacent decisions (`buy ≈ buy_if_price_is_good`),
- at least one source from a whitelisted high-authority domain is cited,
- pipeline completes within budget.

Evals are not part of CI by default; they run on demand (cost) and gate releases that change pipeline behavior.

---

## 13. Rollout phases

The harness is shipped behind `worthly.harness.enabled`. When **off**, `ProductAnalysisService` calls `ProductReviewer::analyzeText|analyzeImage` exactly as today. When **on**, it goes through `AnalysisPipeline`. This lets us ship the layers incrementally and A/B compare.

### Phase A — Foundations (no quality wins yet, but unlocks everything)
- DTOs, contracts, `AnalysisPipeline` skeleton, `BudgetGuard`, cache layer, telemetry table.
- `NullReranker`, single `GeneralWebRetriever` (Tavily), no enricher (raw query passes through).
- `ProductReviewer` updated with `sources_used` and `confidence` in schema, prompt rewritten.
- Schema migration: `analysis_sources`, `confidence`, `degraded`, new enum case.

### Phase B — Query understanding + verticals (biggest quality jump)
- `QueryEnricher`, `ProductIdentifier`.
- `ShoppingRetriever` (SearchApi.io + Mercado Livre public), `ProfessionalReviewRetriever` (Tavily whitelisted).
- Parallel fan-out in `RetrievalRouter`.

### Phase C — Rerank
- `CohereReranker` + filters (dedup, authority floor, recency, diversity).
- Rerun evals; expected lift on `cites_high_authority` metric.

### Phase D — Verifier (optional, off by default)
- `EvidenceVerifier`, revision loop.
- Flag-gated; turn on after evals show net positive.

### Phase E — Hardening
- Expanded authority map, embedding cache, response cache.
- Cost dashboard, alerts on `degraded = true` rate.
- **Out of POC scope, parked for v2:** dedicated `UserGeneratedRetriever` (requires Reddit OAuth app — free but not registered yet) and `SpecSheetRetriever` (requires GSMArena scraper or schema.org extractor). See §15.

---

## 14. Cost and latency budget (target)

Per request, harness **on**, no cache hits, verifier off:

| Step | LLM calls | External HTTP | Tokens (approx) | Latency (p50) |
|---|---|---|---|---|
| L1 Enrich (or Identify) | 1 (cheap) | 0 | 1k in / 0.3k out | 1.5 s |
| L2 Retrieval (3 adapters, 4 HTTP //  — Tavily reviews, Tavily general, SearchApi shopping, Mercado Livre shopping) | 0 | 4 | — | 3.5 s |
| L3 Rerank | 0 | 1 | — | 0.4 s |
| L4 Reason | 1 (strong) | 0 | 6k in / 1k out | 4 s |
| **Total** | 2 LLM | 5 HTTP | ~7k in / 1.3k out | **~9 s** |

With response cache hit: ~50 ms.
With retrieval cache hit + L4 miss: ~4 s.
Verifier on: +1 cheap call, +1 s.

Cost target per fresh request: **under USD 0.04** at current pricing (cheap model + strong model). Verifier on: **under USD 0.05**.

---

## 15. Open questions / deferred work

1. **Region detection.** For Phase B we hard-code `region = "BR"`. Long term we may want to detect from the user account or pass it as an API parameter.
2. **Dedicated UGC retriever (Reddit).** Out of POC scope — requires registering a free Reddit OAuth app and implementing token refresh. Until then, Reddit content reaches the pipeline opportunistically via `GeneralWebRetriever` (Tavily indexes it).
3. **Spec-sheet retriever.** GSMArena has no public API; a structured-spec channel would require an HTML scraper or a schema.org/Product extractor against manufacturer pages. Parked.
4. **YouTube transcripts.** A high-value source for product reviews but requires a transcript provider (e.g. youtube-transcript-api wrapper). Defer.
5. **Brazilian-market price coverage.** SearchApi.io Google Shopping coverage in BR is acceptable but not exhaustive; Mercado Livre's public search fills the gap. Buscapé / Zoom could be added later as scraper-backed adapters if coverage gaps show up in evals.
6. **Hallucinated citations.** The model could cite `S3` for a claim `S3` does not actually support. The verifier catches this, but we also want a deterministic post-processor that checks each citation's snippet contains terms from the claim. Cheap belt-and-suspenders — add in Phase D.
7. **Image identification ambiguity.** When `ProductIdentifier` is unsure (multiple plausible products), should it return a list and let the user pick? Out of scope for POC, parking for v2.

---

## 16. Acceptance criteria (definition of done for the harness)

- All six layers exist, are unit-tested, and are wired through `AnalysisPipeline`.
- The `Analysis` API resource returns `sources[]`, `confidence`, `degraded`.
- The eval harness passes on the golden dataset with ≥ 80% decision match and ≥ 70% high-authority citation rate.
- Cost per request stays under the §14 targets in 95% of runs over a 100-run sample.
- Feature flag `worthly.harness.enabled` switches between legacy and harness paths with no schema-level breakage.
- No layer reaches outside its contract (e.g. retrievers do not call the SDK; agents do not call HTTP).

---

## 17. Credentials and `.env` keys

| Provider | Env var | Free tier | Used by | Status |
|---|---|---|---|---|
| LLM (existing) | provider key (OpenAI / Anthropic) | — | `ProductReviewer`, `QueryEnricher`, `ProductIdentifier`, `EvidenceVerifier` | already configured |
| Tavily | `TAVILY_TOKEN` | 1 000 credits / month | `ProfessionalReviewRetriever`, `GeneralWebRetriever` | **set** |
| SearchApi.io | `SEARCH_TOKEN` | 100 searches / month | `ShoppingRetriever` (engine `google_shopping`) | **set** |
| Cohere | `COHERE_API_KEY` | trial key for dev | `CohereReranker` | **set** |
| Mercado Livre | — (none) | unlimited public read | `ShoppingRetriever` (`/sites/MLB/search`) | public endpoint, no auth |

All keys are read inside the corresponding client wrapper in `app/Ai/Harness/Retrieval/Clients/` (or `Rerank/`), never directly from agents or services. Wrappers are bound in `AppServiceProvider` so tests can swap them with `Http::fake()` or a fake instance.

> **Deferred** (parked in §15 with no `.env` entry today): Reddit OAuth credentials and any GSMArena / scraper credentials.
