## Phase 5 — API Quality, Errors & Documentation

### Phase 5.1 — Consistent error responses — US-7.2

- [ ] **5.1.1** Configure the JSON exception handler so all responses for `Accept: application/json` return:
    - `422`: `{ message, errors: { field: [string] } }` (Laravel default — verify).
    - `401`: `{ message: "Unauthenticated." }` (Laravel default — verify).
    - `404`: `{ message: "Not Found." }` (no DB or model name leakage).
    - `5xx`: `{ error_code, message }` with no stack trace, no SDK type names.
- [ ] **5.1.2** Force JSON responses on all `/api/*` routes (middleware accepting/expects JSON).

**Feature tests** — `tests/Feature/Api/ErrorShapeTest.php`:
- Validation error shape for an obviously invalid request matches the contract.
- 401 shape on protected route without token matches.
- 404 shape for a non-existing analysis route matches.
- 5xx (force a `LlmProviderException` via the fake Agent) returns `{ error_code, message }` with no `trace`/`exception` keys.

### Phase 5.2 — OpenAPI specification — US-7.1

- [ ] **5.2.1** Author `docs/openapi.yaml` (or `docs/openapi.json`) describing:
    - All endpoints from Phases 2 & 4.
    - Auth scheme: `bearerAuth` (HTTP Bearer / Sanctum).
    - Request schemas (`RegisterRequest`, `LoginRequest`, `StoreAnalysisRequest` — both text and image variants via `oneOf`).
    - Response schemas mirroring `AnalysisResource` / `AnalysisListResource` / `UserResource`.
    - Error response schemas (`422`, `401`, `404`, `502`) per Phase 5.1.
- [ ] **5.2.2** Add a smoke endpoint or route docs entry so the spec can be discovered (e.g. `GET /api/openapi.yaml`).

**Feature tests** — `tests/Feature/Docs/OpenApiSpecTest.php`:
- File exists and is valid YAML/JSON (parses without errors).
- Spec contains every endpoint registered in `routes/api.php` (introspect `Route::getRoutes()` and assert each path/method is present in the spec).
- `securitySchemes.bearerAuth.scheme` is `bearer`.
- The `AnalysisResource` schema mentions every key returned by Phase 4.1.

---

