# Worthly — Database Schema

This document defines the database schema for the **Worthly** API in [DBML](https://dbml.dbdiagram.io/docs) format. It follows the requirements in [`project-description.md`](./project-description.md) and [`user-stories.md`](./user-stories.md), and the project conventions defined in `CLAUDE.md`.

## Conventions

- **PostgreSQL** is the target database (see `project-description.md` → Tech Stack).
- **No enum DB columns**: every categorical/enumerable field is modeled as a lookup table with `id` + `slug` + `name` and referenced by a foreign key (e.g. `input_type_id`, `recommendation_decision_id`).
- **File uploads** are stored as `_path` string columns (e.g. `image_path`) — the actual binary lives on the configured storage disk; only the relative path is persisted.
- **Timestamps**: all domain tables use Laravel's standard `created_at` / `updated_at` (`timestamps()`).
- **Soft deletes** are not used in the MVP — `DELETE /api/analyses/{id}` performs a hard delete and removes the associated stored image from disk (US-6.3).
- **Authentication** uses Laravel Sanctum's `personal_access_tokens` table (provided by the package migration). It is included in the schema for completeness.
- **Ownership scoping**: every user-owned record has a `user_id` foreign key with `ON DELETE CASCADE`, so deleting a user cleans up their history.
- **Indexes**: foreign keys are indexed; `(user_id, created_at)` is indexed on `analyses` to support the paginated history list ordered by `created_at desc` (US-6.1).

---

## DBML Schema

```dbml
Project worthly {
  database_type: 'PostgreSQL'
  Note: '''
    Worthly — authenticated API for product buy/no-buy recommendations
    powered by the Laravel AI SDK with built-in web search.
  '''
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

Table users {
  id                bigint        [pk, increment]
  name              varchar(255)  [not null]
  email             varchar(255)  [not null, unique]
  email_verified_at timestamp     [null, note: 'Unused in MVP — kept for Laravel default compatibility']
  password          varchar(255)  [not null, note: 'Bcrypt/Argon hash — never returned by the API']
  remember_token    varchar(100)  [null]
  created_at        timestamp     [null]
  updated_at        timestamp     [null]

  Note: 'Application users authenticated via Sanctum Bearer tokens.'
}

// Provided by Laravel Sanctum's published migration — included here for completeness.
Table personal_access_tokens {
  id              bigint        [pk, increment]
  tokenable_type  varchar(255)  [not null]
  tokenable_id    bigint        [not null]
  name            varchar(255)  [not null]
  token           varchar(64)   [not null, unique]
  abilities       text          [null]
  last_used_at    timestamp     [null]
  expires_at      timestamp     [null]
  created_at      timestamp     [null]
  updated_at      timestamp     [null]

  indexes {
    (tokenable_type, tokenable_id) [name: 'pat_tokenable_type_tokenable_id_index']
  }

  Note: 'Sanctum Bearer tokens. Polymorphic — tokenable is always a User in the MVP.'
}

// ---------------------------------------------------------------------------
// Lookup tables (replace string/enum columns)
// ---------------------------------------------------------------------------

Table input_types {
  id          bigint        [pk, increment]
  slug        varchar(50)   [not null, unique, note: 'Stable machine key: text | image']
  name        varchar(100)  [not null, note: 'Human-readable label']
  description varchar(255)  [null]
  is_active   boolean       [not null, default: true]
  created_at  timestamp     [null]
  updated_at  timestamp     [null]

  Note: '''
    Defines how the user supplied the product input.
    Seeded values (slug → name):
      - text  → "Text query"
      - image → "Product image"
  '''
}

Table recommendation_decisions {
  id          bigint        [pk, increment]
  slug        varchar(50)   [not null, unique, note: 'Stable machine key returned in API responses']
  name        varchar(100)  [not null, note: 'Human-readable label']
  description varchar(255)  [null]
  sort_order  smallint      [not null, default: 0, note: 'Ordering for UI display']
  is_active   boolean       [not null, default: true]
  created_at  timestamp     [null]
  updated_at  timestamp     [null]

  Note: '''
    Final buying verdict enumeration (US-5.1).
    Seeded values (slug):
      - buy
      - buy_if_price_is_good
      - consider_alternatives
      - wait
      - do_not_buy
  '''
}

// ---------------------------------------------------------------------------
// Domain: product analyses
// ---------------------------------------------------------------------------

Table analyses {
  id                          bigint        [pk, increment]
  user_id                     bigint        [not null, ref: > users.id, note: 'Owner — set server-side from auth()->id()']
  input_type_id               bigint        [not null, ref: > input_types.id]
  recommendation_decision_id  bigint        [not null, ref: > recommendation_decisions.id]

  // Input
  query                       text          [null, note: 'Original text query (required when input_type = text)']
  image_path                  varchar(2048) [null, note: 'Relative path on the configured storage disk (required when input_type = image)']

  // Structured product output (US-5.1)
  product_name                varchar(255)  [not null]
  product_category            varchar(255)  [null]
  estimated_price_range       varchar(255)  [null, note: 'Free-form range string returned by the LLM, e.g. "$80 - $110"']

  // Narrative output
  summary                     text          [null, note: 'Reputation/reviews summary — US-4.2']
  cost_benefit_analysis       text          [null, note: 'Price-vs-value analysis — US-4.3']
  recommendation_reason       text          [null, note: 'Reason backing recommendation_decision']

  // Raw response (useful for debugging / re-rendering without another LLM call)
  raw_response                jsonb         [null, note: 'Full structured response returned by the Agent']

  created_at                  timestamp     [null]
  updated_at                  timestamp     [null]

  indexes {
    user_id                       [name: 'analyses_user_id_index']
    (user_id, created_at)         [name: 'analyses_user_id_created_at_index', note: 'Powers paginated history ordered desc (US-6.1)']
    input_type_id                 [name: 'analyses_input_type_id_index']
    recommendation_decision_id    [name: 'analyses_recommendation_decision_id_index']
  }

  Note: '''
    One row per product analysis request.
    - On user delete: cascade.
    - On input_types / recommendation_decisions delete: restrict (these are seeded lookup tables).
    - The application layer guarantees either `query` or `image_path` is present,
      according to the chosen input_type.
  '''
}

Table similar_products {
  id              bigint        [pk, increment]
  analysis_id     bigint        [not null, ref: > analyses.id, note: 'Parent analysis — cascade on delete']
  name            varchar(255)  [not null]
  reason          text          [not null, note: 'Why this product is being suggested as an alternative']
  price_reference varchar(255)  [null, note: 'Optional price hint when surfaced by the LLM']
  sort_order      smallint      [not null, default: 0, note: 'Preserves the order returned by the LLM']
  created_at      timestamp     [null]
  updated_at      timestamp     [null]

  indexes {
    analysis_id                       [name: 'similar_products_analysis_id_index']
    (analysis_id, sort_order)         [name: 'similar_products_analysis_id_sort_order_index']
  }

  Note: 'Similar/alternative products attached to an analysis (US-4.1). Between 1 and N rows per analysis.'
}

// ---------------------------------------------------------------------------
// Relationships summary (declarative)
// ---------------------------------------------------------------------------

Ref: analyses.user_id                    > users.id                       [delete: cascade]
Ref: analyses.input_type_id              > input_types.id                 [delete: restrict]
Ref: analyses.recommendation_decision_id > recommendation_decisions.id    [delete: restrict]
Ref: similar_products.analysis_id        > analyses.id                    [delete: cascade]
```

---

## Mapping to User Stories

| Table                        | Backs user stories                                            |
|------------------------------|---------------------------------------------------------------|
| `users`                      | US-1.1, US-1.2, US-1.5                                         |
| `personal_access_tokens`     | US-1.2, US-1.3, US-1.4                                         |
| `input_types`                | US-2.1, US-3.1, US-6.1                                         |
| `recommendation_decisions`   | US-5.1, US-6.1                                                 |
| `analyses`                   | US-2.1, US-2.2, US-3.1, US-4.2, US-4.3, US-5.1, US-6.1, US-6.2, US-6.3 |
| `similar_products`           | US-4.1, US-5.1, US-6.2                                         |

## Design Notes

- **Why a `raw_response` JSON column?** It keeps the full Agent payload available for `GET /api/analyses/{id}` (US-6.2) without forcing the API to re-render from normalized columns or call the LLM again. Normalized columns (`product_name`, `recommendation_decision_id`, …) exist so the history list (US-6.1) and lookups stay efficient.
- **Why no `analyses.status` column?** US-2.2 mandates that failed analyses are *not* persisted, so there is no "pending" or "failed" state to model. Only successful analyses reach the database.
- **Why store `image_path` as a string on `analyses`?** Each analysis has at most one image (US-3.1), so a related `analysis_attachments` table would be over-engineered. The `_path` suffix matches the project convention for single-file uploads.
- **Why `restrict` on lookup FKs?** Seeded lookup rows must not be deleted while any analysis references them. Schema changes (renaming a decision, retiring `wait`, etc.) should be done through new seeders, not row deletion.
- **Why `(user_id, created_at)` composite index?** The history endpoint always filters by `user_id` and orders by `created_at desc`; a composite index lets PostgreSQL serve both filter and sort from one index scan.
