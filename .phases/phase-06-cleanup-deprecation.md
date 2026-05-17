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
