# Worthly — Project Phases

This document breaks the Worthly API into ordered, numbered phases that can be referenced individually by an implementation agent (e.g. "implement Phase 4.5"). Each task lists the **automated Pest feature tests** that must accompany it as acceptance criteria.

Source documents:
- [`project-description.md`](./project-description.md) — product scope & tech stack
- [`user-stories.md`](./user-stories.md) — functional requirements (US-x.y)
- [`database-schema.md`](./database-schema.md) — DBML schema

**Legend:** `[x]` already implemented in the current codebase · `[ ]` pending.

---

## Phase 1 — Foundation & Database

Bring the database, configuration, and shared building blocks (Enums, lookup tables, base models) up to the state defined in [`database-schema.md`](./database-schema.md).

### Phase 1.1 — Project configuration

- [x] **1.1.1** Laravel 13 + Sail + PostgreSQL bootstrapped (`composer.json`, `compose.yaml`, `.env` → `DB_CONNECTION=pgsql`).
- [x] **1.1.2** Laravel Sanctum installed (`laravel/sanctum` in `composer.json`, `personal_access_tokens` migration present).
- [ ] **1.1.3** Install the Laravel AI SDK (`composer require laravel/ai`) and publish its config.
- [ ] **1.1.4** Create `config/worthly.php` with at least `llm.model` (default driven by env, e.g. `WORTHLY_LLM_MODEL=gpt-5.5`). Reference: `(string) config('worthly.llm.model')` in Agents (per CLAUDE.md).
- [ ] **1.1.5** Configure the image storage disk (`config/filesystems.php`): a private disk named `analysis_images` used by all image-based analyses. No public URL; access only through the dedicated endpoint (US-3.2, US-6.2).

**Feature tests:**
- `tests/Feature/Config/WorthlyConfigTest.php` — asserts `config('worthly.llm.model')` returns a non-empty string and is overridable via env.
- `tests/Feature/Config/AnalysisImagesDiskTest.php` — asserts the `analysis_images` disk is configured as **private** (not in the `public` driver's visibility).

### Phase 1.2 — User model & auth migrations (verification)

- [x] **1.2.1** `users` table migration with `name`, unique `email`, `password`, `remember_token`, timestamps.
- [x] **1.2.2** `personal_access_tokens` Sanctum migration.
- [x] **1.2.3** `User` model with `#[Fillable(['name', 'email', 'password'])]`, `#[Hidden(['password', 'remember_token'])]`, `password => 'hashed'` cast.
- [ ] **1.2.4** Add the `HasApiTokens` trait to `User` (required for `$user->createToken()` in auth endpoints).
- [ ] **1.2.5** Define the `User::analyses()` `HasMany` relationship (added once Phase 1.5 lands; FK is `analyses.user_id`).

**Feature tests:**
- `tests/Feature/Models/UserTest.php`:
    - Email is unique at the DB level (assert duplicate insert throws).
    - `password` attribute is hashed on assignment (`Hash::check`).
    - `password` and `remember_token` are not serialized (`$user->toArray()` keys).
    - `$user->tokens()` relationship works (Sanctum trait wired).

### Phase 1.3 — Lookup tables (replace enums)

> The DBML schema uses lookup tables instead of DB enum columns. Each lookup gets a migration, a Model, a Seeder, and a **PHP Enum** whose cases mirror the seeded slugs (so the API + validation layer can refer to typed cases, while the DB stores FKs).

#### Phase 1.3.1 — `input_types`

- [ ] **1.3.1.a** Migration `create_input_types_table` (columns per DBML: `id`, `slug` unique, `name`, `description` nullable, `is_active` default true, timestamps).
- [ ] **1.3.1.b** `App\Models\InputType` model (`$fillable = ['slug', 'name', 'description', 'is_active']`, `is_active` cast to `bool`, `analyses()` HasMany).
- [ ] **1.3.1.c** `InputTypeSeeder` seeding `text` and `image` slugs.
- [ ] **1.3.1.d** `App\Enums\InputType` PHP backed Enum (`case Text = 'text'; case Image = 'image';`).
- [ ] **1.3.1.e** Wire `InputTypeSeeder` into `DatabaseSeeder` and ensure it runs before the analyses factory in tests.

**Feature tests:**
- `tests/Feature/Models/InputTypeTest.php`:
    - Seeder creates exactly the slugs `text` and `image`.
    - `slug` is unique.
    - `InputType::firstWhere('slug', \App\Enums\InputType::Text->value)` returns the row.

#### Phase 1.3.2 — `recommendation_decisions`

- [ ] **1.3.2.a** Migration `create_recommendation_decisions_table` (per DBML).
- [ ] **1.3.2.b** `App\Models\RecommendationDecision` model with the relationships and casts (`is_active` → bool, `sort_order` → int).
- [ ] **1.3.2.c** `RecommendationDecisionSeeder` with the 5 slugs: `buy`, `buy_if_price_is_good`, `consider_alternatives`, `wait`, `do_not_buy`.
- [ ] **1.3.2.d** `App\Enums\RecommendationDecision` PHP Enum (cases mirror seeded slugs).

**Feature tests:**
- `tests/Feature/Models/RecommendationDecisionTest.php`:
    - Seeder creates exactly the 5 expected slugs in `sort_order` order.
    - Every case in `\App\Enums\RecommendationDecision` has a matching DB row (one-to-one).

### Phase 1.4 — Domain factories

- [x] **1.4.1** `UserFactory` already exists.
- [ ] **1.4.2** `InputTypeFactory` and `RecommendationDecisionFactory` (mainly useful for tests creating arbitrary lookup rows; respect the unique `slug`).
- [ ] **1.4.3** `AnalysisFactory` (depends on Phase 1.5) — defaults: random `User`, `InputType::Text`, `RecommendationDecision::BuyIfPriceIsGood`, fake `product_name`, optional state `->image()` flipping `input_type_id` and setting `image_path`.
- [ ] **1.4.4** `SimilarProductFactory` (depends on Phase 1.6) — defaults parent `Analysis` and faker for `name`/`reason`.

**Feature tests:** none on their own — factories are exercised by the test suites below.

### Phase 1.5 — `analyses` table & model

- [ ] **1.5.1** Migration `create_analyses_table` matching the DBML (FKs with `cascadeOnDelete` for `user_id`, `restrictOnDelete` for lookups; composite index `(user_id, created_at)`; `raw_response` as `jsonb`).
- [ ] **1.5.2** `App\Models\Analysis` model:
    - `$fillable` lists every mass-assignable column.
    - `$casts`: `raw_response => 'array'`.
    - Relationships: `user()`, `inputType()`, `recommendationDecision()`, `similarProducts()` HasMany.
    - Accessors / helpers for `inputTypeEnum(): InputType` and `recommendationDecisionEnum(): RecommendationDecision` derived from the loaded relationship's `slug` (US-5.1 calls for an Enum-backed decision).
- [ ] **1.5.3** Inverse `User::analyses()` HasMany (closes Phase 1.2.5).

**Feature tests:**
- `tests/Feature/Models/AnalysisTest.php`:
    - Mass-assignment for every fillable column.
    - `user`, `inputType`, `recommendationDecision`, `similarProducts` relationships return correct types.
    - Deleting the owner cascades to their analyses.
    - Deleting an `InputType` with attached analyses fails (FK restrict).
    - `raw_response` round-trips as an array via JSON cast.
    - `(user_id, created_at)` index exists (introspection test via Laravel Boost `database-schema` or `Schema::hasIndex`).

### Phase 1.6 — `similar_products` table & model

- [ ] **1.6.1** Migration `create_similar_products_table` matching the DBML.
- [ ] **1.6.2** `App\Models\SimilarProduct` model with `analysis()` BelongsTo and proper `$fillable`.

**Feature tests:**
- `tests/Feature/Models/SimilarProductTest.php`:
    - Belongs to `Analysis`; deleting the parent cascades.
    - `sort_order` preserves order in `$analysis->similarProducts` (default ordering or explicit `orderBy`).

---

## Phase 2 — Authentication API

All endpoints under `/api`. Returns JSON. Tokens issued via Sanctum.

### Phase 2.1 — Register (`POST /api/register`) — US-1.1

- [ ] **2.1.1** `App\Http\Controllers\Api\Auth\RegisterController` (invokable) returning a fresh Bearer token + minimal user payload.
- [ ] **2.1.2** `App\Http\Requests\Api\Auth\RegisterRequest` validating `name` (required), `email` (required, email, unique), `password` (required, confirmed, min 8).
- [ ] **2.1.3** Route registered in `routes/api.php` (no `auth:sanctum`).

**Feature tests** — `tests/Feature/Api/Auth/RegisterTest.php`:
- Successful registration returns `201` with `{ token, token_type: "Bearer" }`.
- Persists the user with a hashed password (never plaintext).
- Duplicate email returns `422` with `errors.email`.
- Missing `password_confirmation` returns `422`.
- Password shorter than 8 chars returns `422`.
- Response **never** contains `password` or `remember_token`.

### Phase 2.2 — Login (`POST /api/login`) — US-1.2

- [ ] **2.2.1** `LoginController` (invokable) using `Auth::attempt` then `createToken()`.
- [ ] **2.2.2** `LoginRequest` validating `email`, `password` (both required).
- [ ] **2.2.3** On failed credentials return `401` with a generic message (no field-level enumeration).

**Feature tests** — `tests/Feature/Api/Auth/LoginTest.php`:
- Valid credentials return `200` with `{ token, token_type: "Bearer" }`.
- Wrong password returns `401` (and the message does not say "password is wrong").
- Unknown email returns `401` with the same generic message (no enumeration).
- Response does not leak user data beyond `id`/`name`/`email` if returned at all.

### Phase 2.3 — Logout (`POST /api/logout`) — US-1.3

- [ ] **2.3.1** `LogoutController` revoking `$request->user()->currentAccessToken()->delete()`.
- [ ] **2.3.2** Route protected by `auth:sanctum`.

**Feature tests** — `tests/Feature/Api/Auth/LogoutTest.php`:
- Returns `204` (or `200` + empty body — fix one in spec) on success.
- Subsequent request reusing the revoked token returns `401`.
- A second token belonging to the same user remains valid.
- Unauthenticated request returns `401`.

### Phase 2.4 — Protected route middleware — US-1.4

- [ ] **2.4.1** Move all `analyses` + `me` routes into a `Route::middleware('auth:sanctum')->group(...)`.
- [ ] **2.4.2** Ensure controllers never accept `user_id` from the request body — owner is always `auth()->id()`.

**Feature tests** — `tests/Feature/Api/Auth/ProtectedRoutesTest.php`:
- All of `GET /api/me`, `POST /api/analyses`, `GET /api/analyses`, `GET /api/analyses/{id}`, `DELETE /api/analyses/{id}` return `401` without a token.
- Same routes return `401` with an invalid token.
- Same routes return `401` with a previously-revoked token.
- A request body that includes `user_id: <other user>` is **ignored**: the created analysis is owned by the authenticated user.

### Phase 2.5 — Authenticated user profile (`GET /api/me`) — US-1.5

- [ ] **2.5.1** Route registered as `Route::get('/me', ...)` returning a `UserResource` with `id`, `name`, `email`, `created_at`.
- [ ] **2.5.2** Remove the placeholder `GET /user` route (`routes/api.php`) once `/me` replaces it.

**Feature tests** — `tests/Feature/Api/Auth/MeTest.php`:
- Returns `200` with `{ id, name, email, created_at }` and **no** `password` / `remember_token` / tokens.
- Returns `401` without a token.
- Always returns the authenticated user — never another user — even when the request body suggests otherwise.

---

## Phase 3 — AI Agent (Product Analysis)

Implements the LLM-facing component using the Laravel AI SDK. Per CLAUDE.md, **the Agent lives in `app/Ai/Agents/`**, never in `app/Services/`. The Service orchestrates persistence; the Agent owns the LLM call.

### Phase 3.1 — Domain exception

- [ ] **3.1.1** `App\Exceptions\LlmProviderException` (extends `RuntimeException`). Renderable to JSON `{ error_code, message }` mapped to HTTP `502` (US-2.2, US-7.2).
- [ ] **3.1.2** Register the renderer in `bootstrap/app.php` (`->withExceptions(...)`).

**Feature tests** — `tests/Feature/Exceptions/LlmProviderExceptionTest.php`:
- Renders as `502` with `{ error_code, message }` shape.
- Does not leak stack traces or SDK-internal types.

### Phase 3.2 — `ProductReviewer` Agent

- [ ] **3.2.1** `App\Ai\Agents\ProductReviewer`:
    - `implements \Laravel\Ai\Contracts\Agent, \Laravel\Ai\Contracts\HasStructuredOutput`
    - `use \Laravel\Ai\Promptable;`
    - `public const SYSTEM_PROMPT` + `instructions(): string` returning it.
    - `schema(JsonSchema $schema): array` using the **typed builder** (`$schema->object(...)`, `$schema->string()->enum([...])->required()`, `$schema->array()->max(5)->items(...)`) — **never raw JSON-Schema arrays**.
    - `analyzeText(string $query): StructuredAgentResponse` — calls `$this->prompt(prompt: $query, model: (string) config('worthly.llm.model'))`.
    - `analyzeImage(string $imagePath, ?string $query = null): StructuredAgentResponse` — uses `\Laravel\Ai\Files\Image::fromStorage($imagePath, disk: 'analysis_images')` as attachment.
- [ ] **3.2.2** Schema enforces the structure required by US-5.1: `product.{name, category, estimated_price_range}`, `summary`, `similar_products[].{name, reason, price_reference?}` (1..5 items), `cost_benefit_analysis`, `recommendation.{decision (enum), reason}` where `decision` is the union of `\App\Enums\RecommendationDecision` cases.

**Feature tests** — `tests/Feature/Ai/ProductReviewerSchemaTest.php`:
- Schema array contains every required key listed above.
- Schema `recommendation.decision` enum lists exactly the cases of `\App\Enums\RecommendationDecision` (asserts mirror).
- `similar_products` has `maxItems: 5`.

> **No mocking of the SDK directly.** Real LLM calls are never executed in tests — we use the fake-class-binding pattern from CLAUDE.md (see Phase 3.3).

### Phase 3.3 — Test fake binding helper

- [ ] **3.3.1** `tests/Support/FakeProductReviewer.php` — extends `ProductReviewer` and overrides `analyzeText` / `analyzeImage` to return a canned `StructuredAgentResponse`-shaped fixture.
- [ ] **3.3.2** `tests/Support/FakesProductReviewer` trait (or helper function) doing `$this->app->instance(ProductReviewer::class, new FakeProductReviewer(...));`. Phase 4 tests reuse this — **no `Mockery` on the SDK**, no `LlmClient` interface.

**Feature tests:** none on their own; exercised by Phase 4.

### Phase 3.4 — `AnalyzeProductService`

- [ ] **3.4.1** `App\Services\AnalyzeProductService` with constructor injection of `ProductReviewer`.
- [ ] **3.4.2** Methods `analyzeText(User $user, string $query): Analysis` and `analyzeImage(User $user, string $imagePath): Analysis`.
- [ ] **3.4.3** Wraps the Agent call in a try/catch and rethrows provider failures as `LlmProviderException`.
- [ ] **3.4.4** Wraps DB writes in a `DB::transaction` so a failed `similar_products` insert rolls back the parent `Analysis` (US-2.2 — no partial records).
- [ ] **3.4.5** Maps the structured response → `Analysis` columns + `SimilarProduct` rows; resolves `recommendation_decision_id` from the returned slug via `\App\Enums\RecommendationDecision`.

**Feature tests** — `tests/Feature/Services/AnalyzeProductServiceTest.php`:
- Persists one `Analysis` row + N `SimilarProduct` rows on success, with the right FKs.
- `raw_response` is stored as JSON.
- `recommendation_decision_id` resolves correctly from the slug returned by the (fake) Agent.
- On Agent throwing, raises `LlmProviderException` and **persists nothing** (assert table counts unchanged).
- Service does not call `auth()` / `request()` / `session()` directly — it must accept `User` as a parameter (asserted by passing a manually built user, never logging in).

---

## Phase 4 — Analysis API

All routes inside the `auth:sanctum` group from Phase 2.4.

### Phase 4.1 — `App\Http\Resources\AnalysisResource`

- [ ] **4.1.1** Resource mirroring the US-5.1 structure:
    ```json
    {
      "id": …,
      "product": { "name", "category", "estimated_price_range" },
      "summary": …,
      "similar_products": [ { "name", "reason", "price_reference" } ],
      "cost_benefit_analysis": …,
      "recommendation": { "decision", "reason" },
      "input_type": "text|image",
      "image_url": "…|null",
      "created_at": …
    }
    ```
    `decision` returns the **slug**, not the FK id. `image_url` points to the route from Phase 4.7.
- [ ] **4.1.2** `App\Http\Resources\AnalysisListResource` (a slimmer version for the paginated list — US-6.1).

**Feature tests** — `tests/Feature/Http/AnalysisResourceTest.php`:
- Full resource shape matches the JSON contract (snapshot or per-key asserts).
- List resource includes exactly `id`, `product_name`, `input_type`, `recommendation`, `created_at`.
- `decision` is always one of the 5 known slugs.
- `image_url` is `null` for text analyses.

### Phase 4.2 — Submit text analysis (`POST /api/analyses`, text) — US-2.1, US-2.2

- [ ] **4.2.1** `App\Http\Controllers\Api\AnalysisController@store`.
- [ ] **4.2.2** `App\Http\Requests\Api\StoreAnalysisRequest` validating:
    - `input_type` required, `in:text,image` (mapped to the seeded slugs).
    - When `input_type=text`: `query` required, string, max 1000.
    - When `input_type=image`: `image` required, file, image, mimes `jpeg,png,webp`, max ~8192 KB.
- [ ] **4.2.3** Delegates to `AnalyzeProductService::analyzeText()`.

**Feature tests** — `tests/Feature/Api/Analyses/StoreTextAnalysisTest.php`:
- Authenticated `text` request returns `201` with the full `AnalysisResource`.
- Resource `id` matches a persisted row owned by `auth()->id()`.
- Missing `query` returns `422`.
- `query` longer than 1000 chars returns `422`.
- Invalid `input_type` returns `422`.
- When the fake `ProductReviewer` throws, response is `502` with `{ error_code, message }` and the DB row count is unchanged.

### Phase 4.3 — Submit image analysis (`POST /api/analyses`, image) — US-3.1, US-3.2

- [ ] **4.3.1** Same controller method handles `input_type=image`.
- [ ] **4.3.2** Stores the upload via `$file->storeAs('analyses', "{$uuid}.{$ext}", 'analysis_images')` (no user-controlled paths).
- [ ] **4.3.3** Calls `AnalyzeProductService::analyzeImage($user, $storedPath)`.
- [ ] **4.3.4** Persists the chosen `image_path` onto the resulting `Analysis`.

**Feature tests** — `tests/Feature/Api/Analyses/StoreImageAnalysisTest.php` (use `Storage::fake('analysis_images')`):
- Valid jpeg/png/webp returns `201`; the file lands on disk under `analyses/`.
- Stored filename does **not** contain any character from the original uploaded filename (assert UUID/hash form).
- Returned resource includes `image_url` pointing to the Phase 4.7 route.
- Missing `image` returns `422`.
- File > size limit returns `422` and nothing is persisted.
- Unsupported MIME (e.g. `image/svg+xml`, `text/plain`) returns `422`.
- The persisted file is on a **private** disk (not in `public/storage`).

### Phase 4.4 — List analyses (`GET /api/analyses`) — US-6.1

- [ ] **4.4.1** `AnalysisController@index`, paginated (15 per page), ordered by `created_at desc`, scoped to `auth()->id()`.
- [ ] **4.4.2** Returns `AnalysisListResource::collection($paginator)`.

**Feature tests** — `tests/Feature/Api/Analyses/ListAnalysesTest.php`:
- Returns only the authenticated user's analyses (seed a second user with rows and assert they are absent).
- Includes Laravel standard pagination metadata (`current_page`, `per_page`, etc.).
- Items are ordered `created_at desc`.
- Each item exposes `id`, `product_name`, `input_type`, `recommendation`, `created_at` — nothing else sensitive.
- `401` when unauthenticated.

### Phase 4.5 — Retrieve a single analysis (`GET /api/analyses/{id}`) — US-6.2

- [ ] **4.5.1** `AnalysisController@show` with route-model binding.
- [ ] **4.5.2** Authorization: returns `404` if the analysis belongs to another user (never `403`, to avoid existence leak).
- [ ] **4.5.3** Eager loads `similarProducts`, `inputType`, `recommendationDecision`.

**Feature tests** — `tests/Feature/Api/Analyses/ShowAnalysisTest.php`:
- Owner gets `200` with the full `AnalysisResource`.
- Non-owner gets `404` (not `403`).
- Non-existing id gets `404`.
- `401` when unauthenticated.
- When `input_type=image`, the response includes `image_url`.

### Phase 4.6 — Delete an analysis (`DELETE /api/analyses/{id}`) — US-6.3

- [ ] **4.6.1** `AnalysisController@destroy` returning `204`.
- [ ] **4.6.2** Deletes the stored image (if any) **before** the DB row, or inside a transaction with a `Storage::delete()` call after commit.
- [ ] **4.6.3** Cascade removes `similar_products` via FK.

**Feature tests** — `tests/Feature/Api/Analyses/DeleteAnalysisTest.php`:
- Owner gets `204`; row + similar_products are gone.
- Non-owner gets `404` and the row is **not** deleted.
- Image file is removed from the fake disk for image analyses (and not removed for text analyses — assert disk state).
- Subsequent `GET /api/analyses` no longer lists the deleted analysis.

### Phase 4.7 — Authenticated image access endpoint — US-3.2, US-6.2

- [ ] **4.7.1** Route `GET /api/analyses/{analysis}/image` returning the stored file via `Storage::disk('analysis_images')->download($path)` (or signed URL if preferred).
- [ ] **4.7.2** Authorization identical to Phase 4.5 (`404` for non-owner).

**Feature tests** — `tests/Feature/Api/Analyses/DownloadAnalysisImageTest.php`:
- Owner downloads the exact bytes that were uploaded (assert via `Storage::fake`).
- Non-owner gets `404`.
- Text-only analysis returns `404` for this route.
- `401` when unauthenticated.
- Response headers do not expose the internal storage path.

---

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
