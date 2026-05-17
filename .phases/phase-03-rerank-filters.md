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

