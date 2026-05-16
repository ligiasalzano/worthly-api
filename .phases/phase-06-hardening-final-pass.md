## Phase 6 — Hardening & Final Pass

- [ ] **6.1** Run `vendor/bin/sail bin pint --dirty --format agent` on the whole codebase (CLAUDE.md requirement).
- [ ] **6.2** Run the full Pest suite via the `test-runner` subagent and ensure all tests in Phases 1–5 pass.
- [ ] **6.3** Manual smoke check via `vendor/bin/sail artisan route:list --except-vendor` to confirm only documented routes are exposed.
- [ ] **6.4** Verify CLAUDE.md compliance with a quick audit:
    - No `app/Services/Ai/` folder exists.
    - No `LlmClient` interface or `LlmResponse` DTO exists.
    - `ProductReviewer` lives in `app/Ai/Agents/`.
    - All Artisan / Composer / npm commands in scripts use the `vendor/bin/sail` prefix.
    - No raw JSON-Schema arrays in any Agent — only the typed builder.

---

## Phase Dependency Graph

```
Phase 1.1   ──┐
Phase 1.2   ──┤
Phase 1.3   ──┼──► Phase 1.4 ──► Phase 1.5 ──► Phase 1.6
              │
              └──► Phase 2 (Auth) ──► Phase 4 (depends on auth + analyses tables)
                                       ▲
Phase 3 (AI) ──────────────────────────┘
                       │
Phase 4 ──► Phase 5 ──► Phase 6
```

- **Phase 1** must finish before any feature work (tables/lookups/Enums are prerequisites).
- **Phase 2** and **Phase 3** are independent of each other and can run in parallel after Phase 1.
- **Phase 4** requires Phases 1, 2, and 3.
- **Phase 5** requires Phase 4 to define the final API surface to document.
- **Phase 6** is the final hardening sweep.
