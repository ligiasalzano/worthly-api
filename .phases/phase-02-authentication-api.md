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

