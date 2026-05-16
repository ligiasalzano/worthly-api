# Overview

This document contains user stories for **Worthly**, an authenticated API that powers a mobile application helping users decide whether a product is worth buying. The API receives a text description or product image, analyzes it through the Laravel AI SDK with web search, and returns a structured buying recommendation tied to the authenticated user.

**User Types:**
- **Visitor** — Unauthenticated client of the API (mobile app on the sign-up / login screens)
- **Authenticated User** — Registered user with a valid Bearer token, able to call protected analysis endpoints

**Scope note:** The MVP exposes only the API layer. There is no admin role, no usage quota, and no email verification in this release.

---

## 1. Authentication & Account Management

### US-1.1: Register Account
**As a** Visitor
**I want to** create an account through the API
**So that** I can authenticate and use the product analysis features

**Acceptance Criteria:**
- [ ] `POST /api/register` accepts: `name`, `email`, `password`, `password_confirmation`
- [ ] Email must be unique and valid
- [ ] Password must meet minimum security requirements (8+ characters)
- [ ] Password is hashed before persistence
- [ ] Response returns a Bearer token ready for immediate use
- [ ] No email verification is required in the MVP
- [ ] Validation errors are returned as a 422 with field-level messages

**Expected Result:** A new user account is created and the mobile app receives a usable authentication token.

---

### US-1.2: Login and Receive Token
**As a** Visitor with an existing account
**I want to** authenticate with email and password
**So that** I can receive a Bearer token to access protected endpoints

**Acceptance Criteria:**
- [ ] `POST /api/login` accepts `email` and `password`
- [ ] On success, response includes `token` and `token_type: "Bearer"`
- [ ] On invalid credentials, returns 401 with a generic error message (no enumeration of which field is wrong)
- [ ] Token is issued via Laravel Sanctum
- [ ] Token does not expose any sensitive user data

**Expected Result:** User receives a Bearer token usable in the `Authorization` header.

---

### US-1.3: Logout (Revoke Token)
**As an** Authenticated User
**I want to** log out
**So that** my current token is invalidated and can no longer be used

**Acceptance Criteria:**
- [ ] `POST /api/logout` requires a valid Bearer token
- [ ] The current token is revoked / deleted
- [ ] Subsequent requests using the same token return 401
- [ ] Other active tokens for the same user remain valid

**Expected Result:** The user's current session token is invalidated.

---

### US-1.4: Access Protected Endpoints
**As an** Authenticated User
**I want** all product analysis endpoints to require a valid token
**So that** my data and history stay private and scoped to my account

**Acceptance Criteria:**
- [ ] All product analysis and history routes are protected by the `auth:sanctum` middleware
- [ ] Requests without an `Authorization` header return 401
- [ ] Requests with an invalid or revoked token return 401
- [ ] Every successful analysis request is associated with `auth()->id()` server-side (never trusted from the request body)

**Expected Result:** Protected endpoints are accessible only to the authenticated owner.

---

### US-1.5: Retrieve Authenticated User Profile
**As an** Authenticated User
**I want to** fetch my own profile
**So that** the mobile app can display my account information

**Acceptance Criteria:**
- [ ] `GET /api/me` returns the authenticated user's basic data (`id`, `name`, `email`, `created_at`)
- [ ] Sensitive fields (password hash, tokens) are never returned
- [ ] Requires a valid Bearer token

**Expected Result:** Mobile app can display the current user's profile without storing it locally.

---

## 2. Text-Based Product Analysis

### US-2.1: Submit Text Query for Analysis
**As an** Authenticated User
**I want to** submit a textual product description or question
**So that** the API analyzes the product and returns a buying recommendation

**Acceptance Criteria:**
- [ ] `POST /api/analyses` accepts `input_type: "text"` and a non-empty `query` string
- [ ] `query` has a maximum length (e.g. 1000 characters) and is required
- [ ] Request is rejected with 422 if `input_type` is missing or invalid
- [ ] The API forwards the query to the Laravel AI SDK Agent configured for product analysis
- [ ] The Agent uses the model's built-in web search to gather fresh product information
- [ ] Analysis is persisted and linked to the authenticated user
- [ ] Response includes the full structured analysis (see US-5.1)

**Expected Result:** Authenticated user receives a buying recommendation for the described product.

---

### US-2.2: Handle Failed Text Analysis
**As an** Authenticated User
**I want** clear errors when an analysis cannot be completed
**So that** the mobile app can react gracefully

**Acceptance Criteria:**
- [ ] If the LLM call fails (timeout, provider error, invalid response), the API returns a domain-specific error (e.g. `LlmProviderException`) mapped to a stable HTTP status (e.g. 502 or 503)
- [ ] No partial / unusable analysis records are persisted on failure
- [ ] Error response includes a human-readable message and a stable error code
- [ ] Failures are logged for observability

**Expected Result:** Failures are surfaced clearly without polluting the user's history.

---

## 3. Image-Based Product Analysis

### US-3.1: Submit Product Image for Analysis
**As an** Authenticated User
**I want to** upload a product photo
**So that** the API identifies the product and returns a buying recommendation

**Acceptance Criteria:**
- [ ] `POST /api/analyses` accepts `input_type: "image"` and an `image` file via `multipart/form-data`
- [ ] Accepted MIME types: `image/jpeg`, `image/png`, `image/webp`
- [ ] Maximum file size enforced (e.g. 8 MB)
- [ ] Image is stored on the configured disk and associated with the analysis record
- [ ] Image is passed to the Agent via the SDK using `Laravel\Ai\Files\Image::fromStorage(...)`
- [ ] Analysis is persisted and linked to the authenticated user
- [ ] Response includes the full structured analysis (see US-5.1)

**Expected Result:** The image is analyzed, stored for later reference, and the user receives a recommendation.

---

### US-3.2: Validate Image Uploads
**As the** Platform
**I want to** reject invalid or unsafe image uploads
**So that** storage and the LLM are not abused

**Acceptance Criteria:**
- [ ] Reject files exceeding the size limit with 422
- [ ] Reject unsupported MIME types with 422
- [ ] Reject requests where the file field is missing for `input_type: "image"`
- [ ] Stored filenames are normalized (no user-controlled paths)
- [ ] Stored images are only accessible to their owning user (no public URLs)

**Expected Result:** Only valid, owner-scoped images are accepted and stored.

---

## 4. Product Insights (Within an Analysis)

### US-4.1: Receive Similar Product Suggestions
**As an** Authenticated User
**I want to** see similar products with their pros and cons
**So that** I can compare alternatives before deciding

**Acceptance Criteria:**
- [ ] Analysis response includes a `similar_products` array
- [ ] Each entry includes: `name`, `reason`, and (when available) price reference
- [ ] At least 1 and at most N (e.g. 5) similar products returned
- [ ] Similar products are sourced from the LLM's web search step

**Expected Result:** User sees comparable options alongside the analyzed product.

---

### US-4.2: Receive Review and Reputation Summary
**As an** Authenticated User
**I want to** see a summary of public reviews for the product
**So that** I understand the common pros, cons, and complaints

**Acceptance Criteria:**
- [ ] Analysis response includes a `summary` field summarizing public opinion / reputation
- [ ] Summary is concise (1–3 paragraphs) and written in natural language
- [ ] When no review data is found, the field explicitly states so rather than being empty

**Expected Result:** User gets a reputation overview without manually searching reviews.

---

### US-4.3: Receive Offer and Price Evaluation
**As an** Authenticated User
**I want to** know whether the current price is attractive
**So that** I can decide if I should buy now or wait for a better offer

**Acceptance Criteria:**
- [ ] Analysis response includes `product.estimated_price_range`
- [ ] Analysis response includes `cost_benefit_analysis` text explaining the price-vs-value trade-off
- [ ] When price data is unavailable, the field communicates that explicitly

**Expected Result:** User understands whether the product's price is fair vs alternatives.

---

## 5. Buying Recommendation

### US-5.1: Receive Structured Buying Recommendation
**As an** Authenticated User
**I want** the API to return a structured recommendation
**So that** the mobile app can render a clear "should I buy?" verdict

**Acceptance Criteria:**
- [ ] Response is validated against a JSON Schema defined in the Agent (`schema()` method using the typed builder)
- [ ] Response includes:
    - `product` (`name`, `category`, `estimated_price_range`)
    - `summary`
    - `similar_products[]` (`name`, `reason`)
    - `cost_benefit_analysis`
    - `recommendation.decision` — one of a fixed enum, e.g. `buy`, `buy_if_price_is_good`, `consider_alternatives`, `wait`, `do_not_buy`
    - `recommendation.reason`
- [ ] `recommendation.decision` is backed by a PHP Enum and cast on the model
- [ ] Response includes the persisted analysis `id` so the mobile app can deep-link to it later

**Expected Result:** Mobile app receives a predictable, schema-validated recommendation it can render directly.

---

## 6. Analysis History

### US-6.1: List My Analyses (Paginated)
**As an** Authenticated User
**I want to** list my past analyses
**So that** I can revisit previous buying decisions

**Acceptance Criteria:**
- [ ] `GET /api/analyses` returns only the authenticated user's analyses
- [ ] Results are paginated (default page size, e.g. 15) with standard Laravel pagination metadata
- [ ] Each item includes: `id`, `product_name`, `input_type`, `recommendation` (decision), `created_at`
- [ ] Results are ordered by `created_at` descending
- [ ] Returns 401 if unauthenticated
- [ ] Never leaks another user's analyses

**Expected Result:** User sees a chronological list of their previous analyses.

---

### US-6.2: Retrieve a Specific Analysis
**As an** Authenticated User
**I want to** retrieve the full data of a past analysis
**So that** I can review the complete recommendation and similar products again

**Acceptance Criteria:**
- [ ] `GET /api/analyses/{id}` returns the full structured analysis payload (same shape as US-5.1)
- [ ] Returns 404 if the analysis does not exist
- [ ] Returns 404 (not 403) if the analysis exists but belongs to another user — to avoid leaking existence
- [ ] If `input_type` is `image`, the response includes a way to access the stored image owned by the user (e.g. a signed URL or dedicated endpoint)

**Expected Result:** User can fully replay a previous analysis result.

---

### US-6.3: Delete an Analysis
**As an** Authenticated User
**I want to** delete a past analysis
**So that** I can remove records I no longer want to keep

**Acceptance Criteria:**
- [ ] `DELETE /api/analyses/{id}` removes the analysis from the user's history
- [ ] Only the owner can delete; non-owners receive 404
- [ ] Associated stored image (when `input_type = image`) is also removed from disk
- [ ] Returns 204 on success
- [ ] Deletion is reflected immediately in subsequent list calls (US-6.1)

**Expected Result:** Analysis and its associated artifacts are permanently removed from the user's history.

---

## 7. API Quality & Documentation

### US-7.1: OpenAPI Documentation
**As a** Mobile Developer integrating with Worthly
**I want** an up-to-date OpenAPI specification
**So that** I can generate clients and understand all endpoints, payloads, and error shapes

**Acceptance Criteria:**
- [ ] An OpenAPI spec describes all authentication and analysis endpoints
- [ ] Request and response schemas mirror the actual API (including the structured recommendation in US-5.1)
- [ ] Auth scheme (Bearer / Sanctum) is documented
- [ ] Error response shapes (422, 401, 404, 502) are documented

**Expected Result:** Mobile team can integrate against a clear, accurate contract.

---

### US-7.2: Consistent Error Responses
**As a** Mobile Developer
**I want** all error responses to follow a consistent shape
**So that** the client can handle errors uniformly

**Acceptance Criteria:**
- [ ] Validation errors (422) follow Laravel's standard `{ message, errors }` shape
- [ ] Authentication errors (401) use a consistent payload
- [ ] Provider / LLM errors (5xx) include a stable `error_code` and human-readable `message`
- [ ] No stack traces or SDK-internal types are leaked to API responses

**Expected Result:** All errors are predictable and mappable on the mobile side.

---

## Appendix: User Story Status

| ID    | Story                                  | Priority | Status  |
|-------|----------------------------------------|----------|---------|
| US-1.1 | Register Account                      | High     | Pending |
| US-1.2 | Login and Receive Token               | High     | Pending |
| US-1.3 | Logout (Revoke Token)                 | Medium   | Pending |
| US-1.4 | Access Protected Endpoints            | High     | Pending |
| US-1.5 | Retrieve Authenticated User Profile   | Low      | Pending |
| US-2.1 | Submit Text Query for Analysis        | High     | Pending |
| US-2.2 | Handle Failed Text Analysis           | Medium   | Pending |
| US-3.1 | Submit Product Image for Analysis     | High     | Pending |
| US-3.2 | Validate Image Uploads                | High     | Pending |
| US-4.1 | Receive Similar Product Suggestions   | High     | Pending |
| US-4.2 | Receive Review and Reputation Summary | Medium   | Pending |
| US-4.3 | Receive Offer and Price Evaluation    | Medium   | Pending |
| US-5.1 | Structured Buying Recommendation      | High     | Pending |
| US-6.1 | List My Analyses (Paginated)          | High     | Pending |
| US-6.2 | Retrieve a Specific Analysis          | High     | Pending |
| US-6.3 | Delete an Analysis                    | Medium   | Pending |
| US-7.1 | OpenAPI Documentation                 | Medium   | Pending |
| US-7.2 | Consistent Error Responses            | Medium   | Pending |
