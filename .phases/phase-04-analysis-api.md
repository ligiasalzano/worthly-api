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

