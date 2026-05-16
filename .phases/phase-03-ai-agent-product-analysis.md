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

