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

