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

