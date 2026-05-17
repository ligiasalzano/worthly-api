# Agent Harness — Project Phases & Task List

> Derived from `docs/agent-harness.md`. Each task has an explicit list of **automated feature tests** that act as acceptance criteria. Numbering (e.g. `Phase 2.3`) is stable so individual tasks can be referenced by ID when delegating to an implementation agent.
>
> Legend: `[x]` done, `[ ]` pending. "Test:" rows are Pest tests to be created under `tests/Feature/...` unless otherwise noted.
>
> **Naming note:** the current orchestrator is `app/Services/AnalyzeProductService.php`. The spec calls it `ProductAnalysisService`. Phase 1 keeps the existing name to avoid an unrelated rename; references in the spec map 1:1 to `AnalyzeProductService`.

---

## Phase 0 — Preconditions (already in place)

- [x] **0.1** — Env keys present: `TAVILY_TOKEN`, `SEARCH_TOKEN`, `COHERE_API_KEY`, `WORTHLY_HARNESS_ENABLED`.
- [x] **0.2** — Baseline `ProductReviewer` agent + `AnalyzeProductService` + `RecommendationDecision` enum + analyses/similar_products tables + auth API (Phases 1–6 of the original delivery).
- [x] **0.3** — Feature test coverage of the legacy single-call pipeline (`tests/Feature/Services/AnalyzeProductServiceTest.php`, `tests/Feature/Ai/ProductReviewerSchemaTest.php`).

---

## Phase 1 — Foundations (no quality wins yet; unlocks every later phase)

Goal: ship the orchestrator skeleton, DTOs, contracts, budget guard, telemetry, schema changes, and the rewritten `ProductReviewer` prompt — behind `worthly.harness.enabled`. The pipeline runs with `NullReranker` and a single passthrough `GeneralWebRetriever` (or no retriever at all if the flag is half-off), so end-to-end it does not yet produce better answers, but every contract and migration is in place.

### Phase 1.1 — Configuration scaffolding
- [ ] **1.1.1** — Extend `config/worthly.php` with the full `harness.*` tree from spec §11 (`enabled`, `cheap_model`, `query_enricher`, `retrievers`, `rerank`, `verifier`, `budget`, `cache`, `authority`).
  - Test (`tests/Feature/Config/WorthlyHarnessConfigTest.php`): asserts every key documented in §11 resolves to a non-null default and that `WORTHLY_HARNESS_ENABLED=false` flips `config('worthly.harness.enabled')` to `false`.

### Phase 1.2 — DTOs and contracts
- [ ] **1.2.1** — Create `app/Ai/Harness/Dto/{EnrichedQuery,EvidenceItem,EvidenceBundle,PipelineResult,RetrievalContext,VerificationReport}.php` and the `Intent` enum in `app/Enums/Intent.php`.
  - Test (`tests/Unit/Ai/Harness/Dto/DtoShapeTest.php`): unit asserts each DTO is `final readonly`, constructor parameter types match spec §4/§5, `EvidenceBundle` exposes stable integer IDs (`S1`, `S2`, …) via `idFor(EvidenceItem $i): string`.
- [ ] **1.2.2** — Create contracts `app/Ai/Harness/Contracts/{Retriever,Reranker,CitationStore}.php`.
  - Test (`tests/Unit/Ai/Harness/Contracts/ContractsExistTest.php`): arch test (`pest --filter=arch`) asserts each contract is an interface in `App\Ai\Harness\Contracts`, and that no class outside `App\Ai\Harness` implements `Retriever`/`Reranker`.

### Phase 1.3 — Budget guard
- [ ] **1.3.1** — Implement `app/Ai/Harness/Budget/PipelineBudget.php` (value object) and `BudgetGuard.php` (mutable counters + thresholds).
  - Test (`tests/Unit/Ai/Harness/Budget/BudgetGuardTest.php`): exhausts each axis (llm_calls, retrieval_calls, tokens, latency) independently; asserts `consume*()` returns the remaining budget; asserts `shouldDegrade()` flips to true exactly once and is idempotent.
- [ ] **1.3.2** — Wire `BudgetGuard` into the service container with per-request scoping (`scoped()` binding in `AppServiceProvider`).
  - Test (`tests/Feature/Ai/Harness/BudgetGuardScopingTest.php`): two consecutive HTTP requests to `/api/analyses` get fresh `BudgetGuard` instances; counters from request A do not leak into request B.

### Phase 1.4 — Telemetry table
- [ ] **1.4.1** — Migration `create_harness_runs_table` matching the schema in spec §9.3.
  - Test (`tests/Feature/Database/HarnessRunsSchemaTest.php`): asserts table exists with all columns, `analysis_id` is a nullable FK to `analyses`, `layers` is `jsonb` on Postgres, an index exists on `(analysis_id)`.
- [ ] **1.4.2** — `HarnessRun` Eloquent model with `$casts` for `layers => array`, `started_at`/`finished_at` as immutable datetimes, booleans cast for `cache_hit`/`degraded`/`budget_exhausted`.
  - Test (`tests/Unit/Models/HarnessRunCastsTest.php`): asserts cast types and that `layers` round-trips an associative array unchanged.

### Phase 1.5 — Schema changes for grounded output
- [ ] **1.5.1** — Migration adds `confidence` (enum: high|medium|low, nullable) and `degraded` (boolean, default false) to `analyses`.
  - Test (`tests/Feature/Database/AnalysesGroundedColumnsTest.php`): asserts `Schema::hasColumn` for both, default for `degraded` is `false`, `confidence` accepts only the three values (DB-level CHECK or app-level cast — pick one and assert it).
- [ ] **1.5.2** — Migration `create_analysis_sources_table` per spec §10.1.
  - Test (`tests/Feature/Database/AnalysisSourcesSchemaTest.php`): asserts columns, FK on `analysis_id` cascade-deletes when the parent Analysis is deleted, index on `(analysis_id, position)`.
- [ ] **1.5.3** — `AnalysisSource` model + `Analysis::sources()` HasMany inverse `belongsTo`, plus `$fillable` and casts (`published_at` => immutable_datetime, `authority_score`/`rerank_score` => float).
  - Test (`tests/Unit/Models/AnalysisSourceRelationshipsTest.php`): asserts relationship class names, `Analysis::sources()` returns ordered by `position`, factory creates a valid row.
- [ ] **1.5.4** — Add `RecommendationDecision::InsufficientEvidence = 'insufficient_evidence'` to the enum, migration/seeder row to `recommendation_decisions`.
  - Test (`tests/Feature/Database/InsufficientEvidenceSeedTest.php`): after `RecommendationDecisionSeeder` runs, a row with `slug = 'insufficient_evidence'` exists with `is_active = true`.

### Phase 1.6 — `ProductReviewer` rewrite (prompt + schema)
- [x] **1.6.0** — Existing `ProductReviewer` agent + `analyzeText/analyzeImage` methods (carried over from baseline).
- [ ] **1.6.1** — Replace `SYSTEM_PROMPT` with the grounded variant from spec §7.1 (drop the "use built-in web search" lie; instruct on `[S1]..[Sn]` citation discipline).
  - Test (`tests/Feature/Ai/ProductReviewerPromptTest.php`): asserts `ProductReviewer::SYSTEM_PROMPT` contains the strings `"evidence"` and `"sources_used"` and does **not** contain `"web search"` or `"built-in"`.
- [ ] **1.6.2** — Extend `schema()` with `sources_used` (array of `{field, evidence_ids[]}`) and `confidence` (enum high|medium|low) per spec §7.2.
  - Test (`tests/Feature/Ai/ProductReviewerSchemaTest.php`, extend existing): asserts the schema array has the two new top-level keys, both `required`, with the correct enum on `confidence` and the `field` enum on `sources_used.items`.
- [ ] **1.6.3** — Add canonical entry `recommend(EnrichedQuery $q, EvidenceBundle $b): StructuredAgentResponse`; keep `analyzeText`/`analyzeImage` as thin shims that build a one-item bundle from raw input (no retrieval) so the legacy code path still works when the flag is off.
  - Test (`tests/Feature/Ai/ProductReviewerRecommendTest.php`): subclasses the agent (the project's "fake by subclass" pattern), feeds a synthetic `EvidenceBundle` with IDs `S1..S3`, asserts that the call produces a response and that the orchestrator can read `sources_used` containing only IDs from the bundle.

### Phase 1.7 — `AnalysisPipeline` skeleton
- [ ] **1.7.1** — Create `app/Ai/Harness/AnalysisPipeline.php` with `run(PipelineInput $input): PipelineResult`. In Phase 1 it: skips L1 (raw query → minimal `EnrichedQuery`), skips L2 (empty bundle), skips L3 (`NullReranker`), calls L4 `ProductReviewer::recommend`, skips L5, writes a `harness_runs` row.
  - Test (`tests/Feature/Ai/Harness/AnalysisPipelineSkeletonTest.php`): with `worthly.harness.enabled=true` and fake `ProductReviewer`, calling `AnalysisPipeline::run()` returns a `PipelineResult` whose response has `confidence = 'low'`, `degraded = true` (empty evidence), and a `harness_runs` row is persisted with non-null `total_ms`.

### Phase 1.8 — Flag-gated wiring in `AnalyzeProductService`
- [ ] **1.8.1** — `AnalyzeProductService` reads `config('worthly.harness.enabled')`; if true, delegates to `AnalysisPipeline`; if false, behaves exactly as today.
  - Test (`tests/Feature/Services/AnalyzeProductServiceFlagTest.php`): two scenarios using `config(['worthly.harness.enabled' => false])` (asserts legacy path: no `harness_runs` row, no `analysis_sources`) and `true` (asserts pipeline path: `harness_runs` row exists, Analysis has `confidence` populated).
- [ ] **1.8.2** — Persistence layer writes `confidence`, `degraded`, and any `EvidenceItem`s from the bundle into `analysis_sources` (position-ordered, with `authority_score` and `rerank_score`).
  - Test (`tests/Feature/Services/AnalyzeProductServicePersistenceTest.php`): with the flag on and a fake pipeline returning a bundle of three items, asserts three `analysis_sources` rows are created in `position` order with the channel/url/snippet pass-through.

### Phase 1.9 — API resource exposes new fields
- [ ] **1.9.1** — Extend `AnalysisResource` with `confidence`, `degraded`, and `sources[]` (each source: `position`, `source_channel`, `url`, `title`, `published_at`).
  - Test (`tests/Feature/Api/AnalysisResourceShapeTest.php`): hits `GET /api/analyses/{id}` for an Analysis with sources, asserts JSON has the three new keys; for an Analysis without sources, `sources` is `[]`.

---

## Phase 2 — Query understanding & vertical retrieval (the biggest quality jump)

### Phase 2.1 — `QueryEnricher` agent (text path)
- [ ] **2.1.1** — Create `app/Ai/Agents/QueryEnricher.php` implementing `Agent` + `HasStructuredOutput`. Uses `config('worthly.harness.cheap_model')`. Output schema matches `EnrichedQuery` DTO (raw_query, productName, brand, category, region, useCase, budgetHint, intent, subQueries[], hydePassages[]).
  - Test (`tests/Feature/Ai/QueryEnricherSchemaTest.php`): asserts `schema()` returns the typed builder shape with `intent` as an enum of the four `Intent` cases and `sub_queries` as `array(min:3, max:5).items(string)`.
- [ ] **2.1.2** — Add `enrich(string $rawQuery): EnrichedQuery` method that calls the SDK and hydrates the DTO.
  - Test (`tests/Feature/Ai/QueryEnricherEnrichTest.php`): fake by subclass returns canned structured output; assert mapping to `EnrichedQuery` covers all optional fields and that an `intent === Unknown` response is preserved (not silently corrected).
- [ ] **2.1.3** — Sub-query generator yields four axes (price, professional review, opinion, alternatives) when the model returns generic queries, by enforcing a min via prompt + schema.
  - Test (`tests/Feature/Ai/QueryEnricherAxesTest.php`): with a fake returning 4 sub-queries, assert no de-duplication collapses them; with a fake returning fewer than `config('worthly.harness.query_enricher.sub_query_count')`, assert the service raises a `LlmProviderException` (or the pipeline short-circuits to `insufficient_evidence` — pick and assert).

### Phase 2.2 — `ProductIdentifier` agent (image path)
- [ ] **2.2.1** — Create `app/Ai/Agents/ProductIdentifier.php` — vision-only, cheap model. Single method `identify(string $imagePath, string $disk = 'analysis_images'): EnrichedQuery`.
  - Test (`tests/Feature/Ai/ProductIdentifierTest.php`): fake by subclass returns canned output for a fixture image, asserts the returned `EnrichedQuery` has `rawQuery` filled with extracted visible text and `intent !== Unknown` when `productName !== null`.
- [ ] **2.2.2** — Low-confidence short-circuit: when `productName === null`, the pipeline must return a `product_not_identified` response without entering retrieval.
  - Test (`tests/Feature/Ai/Harness/AnalysisPipelineProductNotIdentifiedTest.php`): fake `ProductIdentifier` returns `productName = null`, assert no Retriever was hit (`Http::assertNothingSent()` against retriever clients), assert `Analysis.recommendation_decision === insufficient_evidence`, assert `harness_runs.retrieval_calls === 0`.

### Phase 2.3 — Retrieval HTTP client wrappers
- [ ] **2.3.1** — `app/Ai/Harness/Retrieval/Clients/TavilyClient.php` wraps Tavily's `/search` endpoint, reads `TAVILY_TOKEN` from env.
  - Test (`tests/Feature/Ai/Harness/Clients/TavilyClientTest.php`): with `Http::fake()`, asserts the outgoing request URL/host, `Authorization` header, and that the `include_domains` parameter is passed through verbatim.
- [ ] **2.3.2** — `SearchApiClient.php` wraps SearchApi.io Google Shopping engine (`engine=google_shopping`), reads `SEARCH_TOKEN`.
  - Test (`tests/Feature/Ai/Harness/Clients/SearchApiClientTest.php`): `Http::fake()` asserts URL, `api_key` query string, decodes a canned shopping JSON into a list of normalized rows (title, url, price, source).
- [ ] **2.3.3** — `MercadoLivreClient.php` wraps `/sites/MLB/search` (no auth).
  - Test (`tests/Feature/Ai/Harness/Clients/MercadoLivreClientTest.php`): `Http::fake()` asserts the URL has no `Authorization` header, the canned ML response decodes into the same row shape.
- [ ] **2.3.4** — Bind all three clients in `AppServiceProvider` as singletons so they are swappable in tests.
  - Test (`tests/Feature/Ai/Harness/Clients/ClientBindingTest.php`): asserts `app(TavilyClient::class)` is the same instance across two resolves and that `$this->app->instance(TavilyClient::class, $fake)` overrides cleanly.

### Phase 2.4 — Retriever adapters
- [ ] **2.4.1** — `ProfessionalReviewRetriever` (Tavily, whitelisted domains from config). Maps Tavily results → `EvidenceItem` with `sourceChannel = 'reviews'`, authority from `harness.authority` table.
  - Test (`tests/Feature/Ai/Harness/Retrieval/ProfessionalReviewRetrieverTest.php`): asserts only whitelisted domains survive, `authorityScore` is filled from config, items older than the cap are still returned (recency filter is L3's job), `isEligible()` returns true for any non-Unknown intent.
- [ ] **2.4.2** — `ShoppingRetriever` fans out to SearchApi + MercadoLivre in parallel via `Http::pool()` and merges by URL.
  - Test (`tests/Feature/Ai/Harness/Retrieval/ShoppingRetrieverTest.php`): `Http::fake()` for both providers, assert both are called exactly once per `retrieve()`, dedup by URL works, merged result is ordered by `rawRelevance` desc.
- [ ] **2.4.3** — `GeneralWebRetriever` (Tavily open web, no whitelist).
  - Test (`tests/Feature/Ai/Harness/Retrieval/GeneralWebRetrieverTest.php`): asserts no `include_domains` is sent, results tagged with `sourceChannel = 'general'`, unknown-domain `authorityScore` defaults to `0.4`.
- [ ] **2.4.4** — Register adapters in a config-driven array so disabling `harness.retrievers.<channel>.enabled` removes them from the router.
  - Test (`tests/Feature/Ai/Harness/Retrieval/AdapterRegistryTest.php`): toggling each channel off in config produces a router with one fewer adapter in its `$retrievers` list.

### Phase 2.5 — `RetrievalRouter`
- [ ] **2.5.1** — Implement `RetrievalRouter::gather(EnrichedQuery): EvidenceBundle` with parallel fan-out, per-adapter timeout, per-adapter and global item caps.
  - Test (`tests/Feature/Ai/Harness/Retrieval/RetrievalRouterParallelismTest.php`): two fake adapters; the slow one sleeps past its timeout, the fast one returns 3 items; assert the bundle returns the 3 items, the slow adapter's failure is logged (Laravel log fake), `harness_runs.retrieval_calls === 2`.
- [ ] **2.5.2** — Zero-evidence short-circuit: when all adapters return empty, set `recommendation.decision = insufficient_evidence` without calling L4.
  - Test (`tests/Feature/Ai/Harness/Retrieval/RetrievalRouterEmptyBundleTest.php`): all adapters faked to return `[]`, assert `ProductReviewer` is never invoked (subclass spy asserts call count), Analysis row has `insufficient_evidence`, `degraded = true`.
- [ ] **2.5.3** — Per-adapter retrieval cache (Redis, TTL from config per channel).
  - Test (`tests/Feature/Ai/Harness/Retrieval/RetrievalCacheTest.php`): with `cache.default` set to `array`, first call hits HTTP fake once; second identical call hits cache (HTTP fake assert called only once total); after `Cache::flush()`, third call hits HTTP again.

### Phase 2.6 — Pipeline wires L1 + L2
- [ ] **2.6.1** — Update `AnalysisPipeline::run()` to call `QueryEnricher`/`ProductIdentifier` then `RetrievalRouter`, then L4.
  - Test (`tests/Feature/Ai/Harness/AnalysisPipelineL1L2Test.php`): fakes for enricher, router, and agent; assert the order of calls (enricher → router → agent) and that the agent receives a non-empty `EvidenceBundle` derived from the router output.
- [ ] **2.6.2** — Image flow: `analyzeImage` uses `ProductIdentifier` only (no `QueryEnricher`), keeping image flows at 2 LLM calls.
  - Test (`tests/Feature/Ai/Harness/AnalysisPipelineImagePathTest.php`): spy on both agents; for an image input, `QueryEnricher::enrich` is **never** called, `ProductIdentifier::identify` is called exactly once.

---

## Phase 3 — Rerank & filters

### Phase 3.1 — Reranker contract + null implementation
- [x] **3.1.0** — Contract interface exists from Phase 1.2.2.
- [ ] **3.1.1** — Implement `NullReranker` (identity passthrough, truncates to `topK`).
  - Test (`tests/Unit/Ai/Harness/Rerank/NullRerankerTest.php`): asserts ordering is unchanged and length capped to `topK`.

### Phase 3.2 — `CohereReranker`
- [ ] **3.2.1** — Implement Cohere Rerank 3 HTTP client + reranker. One batched request per pipeline run; reads `COHERE_API_KEY`.
  - Test (`tests/Feature/Ai/Harness/Rerank/CohereRerankerTest.php`): `Http::fake()` asserts a single POST to Cohere, payload contains all candidate snippets, response indices are applied to reorder items, ties broken stably by original index.
- [ ] **3.2.2** — On Cohere error/timeout, fall back to `NullReranker` and mark the run `degraded = true`.
  - Test (`tests/Feature/Ai/Harness/Rerank/CohereRerankerDegradeTest.php`): `Http::fake()` returns 500; assert pipeline still completes, `harness_runs.degraded === true`, items returned in pre-rerank order.

### Phase 3.3 — Post-rerank filters (applied in order)
- [ ] **3.3.1** — **Semantic dedup** — embedding-based, collapse cosine > 0.92 (keep higher authority).
  - Test (`tests/Feature/Ai/Harness/Rerank/SemanticDedupTest.php`): synthetic items with two near-duplicate snippets; assert the lower-authority one is dropped; embedding cache populated (Redis array driver inspected).
- [ ] **3.3.2** — **Authority floor** — drop items < 0.3 unless they are the only evidence for their channel.
  - Test (`tests/Feature/Ai/Harness/Rerank/AuthorityFloorTest.php`): items with scores `[0.95, 0.25, 0.20]` from channels `[reviews, reviews, shopping]` → assert the 0.20 shopping item survives (lone channel), 0.25 reviews is dropped.
- [ ] **3.3.3** — **Recency decay** on `shopping` channel — drop items older than 60 days.
  - Test (`tests/Feature/Ai/Harness/Rerank/RecencyDecayTest.php`): two shopping items with `publishedAt` at 30 and 90 days ago; assert only the 30-day survives, reviews channel is untouched.
- [ ] **3.3.4** — **Channel diversity** — guarantee one `shopping` + one `reviews` survive in top-K when available, even if their rerank score is lower.
  - Test (`tests/Feature/Ai/Harness/Rerank/ChannelDiversityTest.php`): top-K of 4 with rerank scores producing 4 review items + 1 shopping (rank 5); assert the shopping item is promoted into the top-K replacing the lowest-ranked review.

### Phase 3.4 — Stable evidence IDs
- [ ] **3.4.1** — `EvidenceBundle` assigns `S1..Sn` IDs after rerank+filter, exposed to the agent in the prompt.
  - Test (`tests/Feature/Ai/Harness/EvidenceBundleIdsTest.php`): bundle of 8 items → IDs are `S1..S8` in final order; `idFor()` is bijective; serializing to the agent prompt includes each ID exactly once.

---

## Phase 4 — Evidence verifier (optional, flag-gated; off by default)

### Phase 4.1 — `EvidenceVerifier` agent
- [ ] **4.1.1** — Create `app/Ai/Agents/EvidenceVerifier.php`. Cheap model. Method `verify(array $structuredOutput, EvidenceBundle $b): VerificationReport`.
  - Test (`tests/Feature/Ai/EvidenceVerifierTest.php`): fake by subclass; feed a known structured output + bundle, assert each claim is mapped to `supported|partially_supported|unsupported`, and that `VerificationReport::hasUnsupported()` reflects the canned input.

### Phase 4.2 — Revision loop
- [ ] **4.2.1** — When the verifier flags claims as `unsupported`, the orchestrator runs at most one revision call to `ProductReviewer` and re-verifies; if still unsupported, downgrade `confidence` to `low` and strip the offending fields.
  - Test (`tests/Feature/Ai/Harness/VerifierRevisionLoopTest.php`): subclass spies on `ProductReviewer::recommend` → first call returns an output with 1 unsupported claim, second call (revision) returns a clean output; assert exactly two calls, final `confidence` is unchanged. Second scenario: revision still fails → assert one revision, final `confidence = 'low'` and the unsupported field is `null`.

### Phase 4.3 — Flag gating
- [ ] **4.3.1** — `worthly.harness.verifier.enabled=false` skips L5 entirely (no extra LLM call).
  - Test (`tests/Feature/Ai/Harness/VerifierFlagOffTest.php`): with the flag off, spy on `EvidenceVerifier::verify` asserts it is never called; with it on, asserts it is called exactly once on the happy path.

### Phase 4.4 — Deterministic citation post-processor (belt & suspenders, spec §15.6)
- [ ] **4.4.1** — After verifier, run a deterministic check: each `sources_used` entry must reference IDs present in the bundle and the cited snippet must contain at least one significant noun/term from the claim.
  - Test (`tests/Feature/Ai/Harness/CitationPostProcessorTest.php`): synthesize a structured output that cites `S99` (not in bundle) and one that cites `S1` whose snippet shares no term with the claim; assert both citations are stripped and `confidence` is downgraded.

---

## Phase 5 — Hardening, observability, evals

### Phase 5.1 — Cache tiers
- [ ] **5.1.1** — Embedding cache (key `worthly:e:<sha1>`), 30-day TTL.
  - Test (`tests/Feature/Ai/Harness/Cache/EmbeddingCacheTest.php`): asserts the same text hashed across two calls hits cache; TTL matches config.
- [ ] **5.1.2** — Response cache (key `worthly:resp:<sha1(enriched_query + evidence_ids)>`), 24h TTL, **disabled for image input**.
  - Test (`tests/Feature/Ai/Harness/Cache/ResponseCacheTest.php`): two identical text inputs → second hits cache (LLM agent spy asserts only one call). Image input: two identical image inputs → both call the LLM (cache bypassed).

### Phase 5.2 — Authority map expansion
- [ ] **5.2.1** — Populate `harness.authority` with the full Phase E set from spec §15 (RTINGS, Wirecutter, TechRadar, GSMArena, Tom's Hardware, CNET, Reddit baseline 0.5, default 0.4).
  - Test (`tests/Feature/Ai/Harness/AuthorityMapTest.php`): for each whitelisted domain, `AuthorityResolver::scoreFor($url)` returns the expected value; unknown domain returns `0.4`; reddit.com returns `0.5`.

### Phase 5.3 — Observability
- [ ] **5.3.1** — Per-layer structured logs: `layer`, `duration_ms`, `cache_hit`, `items_in/out`, `tokens_in/out`, `cost_usd_estimate`.
  - Test (`tests/Feature/Ai/Harness/Observability/StructuredLogsTest.php`): `Log::spy()`, run pipeline end to end with fakes, assert each layer logged once with the documented keys.
- [ ] **5.3.2** — `harness_runs.layers` jsonb breakdown matches the per-layer log entries.
  - Test (`tests/Feature/Ai/Harness/Observability/HarnessRunBreakdownTest.php`): after a successful run, decode `layers` and assert keys `l1, l2, l3, l4, l5` exist with `duration_ms` and `success` booleans.
- [ ] **5.3.3** — Alert hook (logged warning + counter) when `degraded = true` rate over the last hour > 10%.
  - Test (`tests/Feature/Ai/Harness/Observability/DegradedRateAlertTest.php`): insert 9 successful + 2 degraded runs in the last hour via the factory; trigger the watcher (artisan command or scheduled job); assert a warning log was emitted; with 9 successful + 1 degraded, no warning.

### Phase 5.4 — Eval harness (golden dataset)
- [ ] **5.4.1** — Create `tests/Fixtures/Ai/Evals/golden.json` with ~20 hand-curated rows (`input`, `expected_decision`, `expected_must_cite_domains`).
  - Test (artifact only; covered by 5.4.2).
- [ ] **5.4.2** — `tests/Feature/Ai/EvalHarnessTest.php` — runs the **real** pipeline against live providers; skipped unless `WORTHLY_RUN_EVALS=1`. Assertions per spec §12.3: decision match (or acceptable adjacent), ≥1 high-authority domain cited, completes within budget.
  - Test: itself. CI does not run it; it gates pipeline-behavior releases via the `WORTHLY_RUN_EVALS` env flag.
- [ ] **5.4.3** — Aggregate eval report stored as `storage/app/evals/<timestamp>.json` for diffing across releases.
  - Test (`tests/Feature/Ai/Harness/Evals/EvalReportArtifactTest.php`): with `WORTHLY_RUN_EVALS=1` and a synthetic 2-row dataset + fully faked pipeline, assert a report file is written with per-row pass/fail and aggregate `decision_match_rate`/`high_authority_rate` keys.

### Phase 5.5 — End-to-end harness feature test (covers the §16 acceptance criteria)
- [ ] **5.5.1** — `tests/Feature/Ai/AnalysisPipelineTest.php` — full E2E with `Http::fake()` for all retrievers and reranker, fake agents (`app->instance(...)`), Redis array driver. Asserts:
  - persistence side effects (`Analysis`, `SimilarProduct`, `AnalysisSource`, `HarnessRun`);
  - API resource shape (`sources[]`, `confidence`, `degraded` keys present);
  - `recommendation_decision` row exists and the enum case is correctly resolved (including `insufficient_evidence`);
  - No layer reaches outside its contract (arch test: retrievers do not depend on `Laravel\Ai\*`; agents do not depend on `Illuminate\Http\Client\*`).

### Phase 5.6 — Architecture guardrails
- [ ] **5.6.1** — Pest arch tests enforcing the boundaries from spec §6:
  - `App\Ai\Harness\Retrieval\*` may not use `Laravel\Ai\*`.
  - `App\Ai\Agents\*` may not use `Illuminate\Http\Client\*`.
  - `App\Services\*` may not use `Illuminate\Http\Client\*` (only via clients/agents).
  - `App\Ai\Harness\*` may not import `App\Services\*` (one-way dependency).
  - Test (`tests/Architecture/HarnessBoundariesTest.php`): one arch test per rule.

---

## Phase 6 — Cleanup & deprecation

- [ ] **6.1** — Mark legacy `ProductReviewer::analyzeText` / `analyzeImage` shims as `@deprecated` in OpenAPI.
  - Test (`tests/Feature/Docs/OpenApiDeprecationTest.php`): asserts the generated OpenAPI spec marks the legacy endpoints/methods with `deprecated: true`.
- [ ] **6.2** — Once the frontend migrates, remove the shims and the `worthly.harness.enabled=false` path.
  - Test: remove `tests/Feature/Services/AnalyzeProductServiceFlagTest.php`'s "flag off" scenario; add a regression test asserting `worthly.harness.enabled` is always treated as on.
- [ ] **6.3** — Cost dashboard: aggregate `harness_runs` daily totals (LLM cost, retrieval cost, cache hit ratio, degraded rate) into a Filament/Blade admin view or a CLI report.
  - Test (`tests/Feature/Ai/Harness/CostDashboardTest.php`): seed 50 `HarnessRun` rows across two days; assert the aggregator returns per-day totals matching factory-generated sums.

---

## Out of POC scope (parked, no tasks emitted)

Tracked in spec §15 and §13 Phase E:
- Dedicated `UserGeneratedRetriever` (Reddit OAuth app).
- `SpecSheetRetriever` (GSMArena scraper or schema.org/Product extractor).
- YouTube transcript retriever.
- Region detection (hard-coded `BR` for now).
- Image identification ambiguity → multi-candidate UX.
- Buscapé / Zoom price adapters.

---

## Reference: file layout introduced by this plan

```
app/
├── Ai/
│   ├── Agents/
│   │   ├── ProductReviewer.php          [exists, rewritten in 1.6]
│   │   ├── QueryEnricher.php            [2.1]
│   │   ├── ProductIdentifier.php        [2.2]
│   │   └── EvidenceVerifier.php         [4.1]
│   └── Harness/
│       ├── AnalysisPipeline.php         [1.7]
│       ├── Budget/{PipelineBudget,BudgetGuard}.php             [1.3]
│       ├── Contracts/{Retriever,Reranker,CitationStore}.php    [1.2.2]
│       ├── Dto/{EnrichedQuery,EvidenceItem,EvidenceBundle,
│       │        PipelineResult,RetrievalContext,
│       │        VerificationReport}.php                        [1.2.1]
│       ├── Retrieval/
│       │   ├── RetrievalRouter.php                             [2.5]
│       │   ├── Adapters/{ShoppingRetriever,
│       │   │             ProfessionalReviewRetriever,
│       │   │             GeneralWebRetriever}.php              [2.4]
│       │   └── Clients/{TavilyClient,SearchApiClient,
│       │                MercadoLivreClient}.php                [2.3]
│       └── Rerank/{CohereReranker,NullReranker}.php            [3.1, 3.2]
├── Enums/
│   ├── Intent.php                       [1.2.1]
│   └── RecommendationDecision.php       [exists; +InsufficientEvidence in 1.5.4]
└── Services/
    └── AnalyzeProductService.php        [exists; flag-gated dispatch in 1.8]

database/migrations/
├── *_add_grounded_columns_to_analyses.php       [1.5.1]
├── *_create_analysis_sources_table.php          [1.5.2]
├── *_add_insufficient_evidence_decision.php     [1.5.4]
└── *_create_harness_runs_table.php              [1.4.1]
```
