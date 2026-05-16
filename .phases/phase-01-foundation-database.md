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

