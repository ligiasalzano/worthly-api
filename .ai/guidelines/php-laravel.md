## General code instructions

- Don't generate code comments above the methods or code blocks if they are obvious. Don't add docblock comments when defining variables, unless instructed to, like `/** @var \App\Models\User $currentUser */`. Generate comments only for something that needs extra explanation for the reasons why that code was written.
- For new features, you MUST generate Pest automated tests.
- To run tests, ALWAYS delegate to the `test-runner` subagent via Task tool. Do not run `vendor/bin/sail artisan test` directly — it pollutes your context with long output.
- For library documentation, if some library is not available in Laravel Boost 'search-docs', always use context7. Automatically use the Context7 MCP tools to resolve library id and get library docs without me having to explicitly ask.
- If you made changes to CSS/Javascript files or added new Tailwind classes in Blade, run `npm run build` after all front-end changes are finished.

## Memory instructions (mem0)

This project uses the mem0 skill for persistent memory across sessions.
ACTIVELY use mem0 — do not wait to be asked. The skill handles the API
mechanics;

---

## PHP instructions

- In PHP, use `match` operator over `switch` whenever possible
- Generate Enums always in the folder `app/Enums`, not in the main `app/` folder, unless instructed differently.
- Always use Enum value as the default in the migration if column values are from the enum. Always casts this column to the enum type in the Model.
- Don't create temporary variables like `$currentUser = auth()->user()` if that variable is used only one time.
- Always use Enum where possible instead of hardcoded string values, if Enum class exists. For example, in Blade files, and in the tests when creating data if field is casted to Enum then use that Enum instead of hardcoding the value.

---

## Laravel instructions

- Always run Laravel-related CLI commands using Sail: prefix commands with `./vendor/bin/sail`.
    - Use `./vendor/bin/sail artisan migrate` instead of `php artisan migrate`
    - Use `./vendor/bin/sail artisan test` instead of `php artisan test`
    - Use `./vendor/bin/sail artisan make:model User` instead of `php artisan make:model User`
    - Use `./vendor/bin/sail composer install` instead of `composer install`
    - Use `./vendor/bin/sail npm run build` instead of `npm run build`
- Never assume global PHP, Composer, or Node execution. Always run commands inside the Sail container.
- **Eloquent Observers** should be registered in Eloquent Models with PHP Attributes, and not in AppServiceProvider. Example: `#[ObservedBy([UserObserver::class])]` with `use Illuminate\Database\Eloquent\Attributes\ObservedBy;` on top
- Aim for "slim" Controllers/Components and put larger logic pieces in Service classes
- Use Laravel helpers instead of `use` section classes. Examples: use `auth()->id()` instead of `Auth::id()` and adding `Auth` in the `use` section. Other examples: use `redirect()->route()` instead of `Redirect::route()`, or `str()->slug()` instead of `Str::slug()`.
- Don't use `whereKey()` or `whereKeyNot()`, use specific fields like `id`. Example: instead of `->whereKeyNot($currentUser->getKey())`, use `->where('id', '!=', $currentUser->id)`.
- Don't add `::query()` when running Eloquent `create()` statements. Example: instead of `User::query()->create()`, use `User::create()`.
- When adding columns in a migration, update the model's `$fillable` array to include those new attributes.
- Never chain multiple migration-creating commands (e.g., `make:model -m`, `make:migration`) with `&&` or `;` — they may get identical timestamps. Run each command separately and wait for completion before running the next.
- Enums: If a PHP Enum exists for a domain concept, always use its cases (or their `->value`) instead of raw strings everywhere — routes, middleware, migrations, seeds, configs, and UI defaults.
- Don't create Controllers with just one method which just returns `view()`. Instead, use `Route::view()` with Blade file directly.
- Always use Laravel's @session() directive instead of @if(session()) for displaying flash messages in Blade templates.
- In Blade files always use `@selected()` and `@checked()` directives instead of `selected` and `checked` HTML attributes. Good example: @selected(old('status') === App\Enums\ProjectStatus::Pending->value). Bad example: {{ old('status') === App\Enums\ProjectStatus::Pending->value ? 'selected' : '' }}.

### Service classes

- Use Service classes to encapsulate reusable business logic, keeping Controllers and Livewire Components slim.
- Service classes MUST be created in the `app/Services/` folder.
- If a Service is used in only ONE method of a Controller or Component, inject it directly into that method via type-hinting. If it is used in MULTIPLE methods, initialize it in the Constructor (or `mount()`/`boot()` for Livewire Components).
- The same injection rule applies to both traditional Controllers and Livewire Components — use `mount()` or `boot()` to inject Services in Components when needed across multiple methods, or inject directly into the action method.
- Services MUST NOT contain presentation logic (views, redirects, flash messages). Return data or throw exceptions, and let the Controller/Component decide how to present the result.
- Services MUST be independently testable — avoid coupling with `request()`, `session()`, or `auth()` directly. Receive those values as parameters instead.

### AI / LLM integration (Laravel AI SDK)

> **Regra de ouro:** Laravel AI SDK (`laravel/ai`) **já é** a camada de abstração. Não envelope o SDK atrás de interfaces/DTOs custom em nome de "testabilidade" — a testabilidade vem do container.
>
> Sempre que for tocar em código de LLM/Agent, **ative a skill `ai-sdk-development`** (`.claude/skills/ai-sdk-development/SKILL.md`).

- **Localização:** Agents moram em `app/Ai/Agents/`. **Nunca** em `app/Services/`. A pasta `app/Services/Ai/` não deve existir.
- **Contratos obrigatórios:** toda Agent class **MUST** `implements Laravel\Ai\Contracts\Agent`. Se retorna JSON estruturado, também `implements Laravel\Ai\Contracts\HasStructuredOutput`. Sempre `use Laravel\Ai\Promptable`.
- **System prompt:** uma `public const SYSTEM_PROMPT` retornada por `instructions(): string`. Não embutir o prompt inline em `$this->prompt(...)`.
- **Schema:** definido em `schema(\Illuminate\Contracts\JsonSchema\JsonSchema $schema): array` usando o **builder tipado** — `$schema->object(fn ($s) => [...])`, `$schema->string()->enum([...])->nullable()->required()`, `$schema->array()->max(N)->items(...)`. **Proibido** retornar arrays JSON-Schema crus (`['type' => 'object', 'properties' => ...]`).
- **Métodos públicos por fluxo:** ex. `analyzeText(string $query): StructuredAgentResponse`, `analyzeImage(string $imagePath): StructuredAgentResponse`. Chamam `$this->prompt(prompt: ..., attachments: ..., model: (string) config('worthly.llm.model'))` diretamente. Não crie classes `PromptBuilder` / `ProductAnalysisPrompt` separadas.
- **Anexos:** sempre via objetos do SDK — `\Laravel\Ai\Files\Image::fromStorage($path, disk: ...)`, `Files\Pdf::fromStorage(...)`, etc. Nunca passar paths como string solta dentro de arrays.
- **Proibido criar:**
    - Interface `LlmClient` (ou similar) envolvendo o SDK.
    - DTO `LlmResponse` (ou similar) reembrulhando `StructuredAgentResponse`. Use o retorno do SDK direto (`$response->toArray()`, `$response->object`).
    - Qualquer wrapper que chame `agent(...)->prompt(...)` por baixo de uma fachada custom.
- **Service ↔ Agent:** Services em `app/Services/` orquestram (transação, persistência, quota, mapeamento de exceção). A **chamada LLM mora dentro do Agent**, não no Service. O Service injeta o Agent pelo construtor.
- **Erros do provider:** capture `Throwable` da chamada do Agent no Service e re-lance como exceção de domínio (ex.: `LlmProviderException`). Não vaze tipos do SDK para fora do Service.
- **Testabilidade — esta é a única forma correta:**
  ```php
  $fake = new class extends ProductReviewer {
      public function analyzeText(string $query): StructuredAgentResponse { /* canned */ }
  };
  $this->app->instance(ProductReviewer::class, $fake);
  ```
  Não existe `LlmClient` para mockar. Não use `Mockery` no SDK. Se sentir necessidade de "abstrair para testar", **pare e releia este bloco**.
- **Configuração de modelo:** sempre `(string) config('worthly.llm.model')` (ou config equivalente do projeto) dentro do Agent. Não hardcode string de modelo.

---

### Model construction rules

- Models MUST define the `$fillable` property correctly for all mass-assignable attributes.
- When adding new columns via migration, you MUST update the corresponding Model `$fillable` array.
- Relationships MUST follow Laravel naming conventions (`user()`, `orders()`, `profile()`, etc.).
- Relationship methods MUST use correct return types (`HasMany`, `BelongsTo`, `HasOne`, etc.).
- All relationships MUST have their inverse defined when applicable.
    - If `User` hasMany `Order`, then `Order` MUST define `belongsTo(User::class)`.
    - If `User` hasOne `Profile`, then `Profile` MUST define `belongsTo(User::class)`.
- Do not assume foreign key naming. Explicitly define foreign keys if they don't follow Laravel conventions.
- If a column represents a domain concept backed by an Enum, the Model MUST cast it using `$casts`.

---

## Testing instructions

### Before Writing Tests

1. **Check database schema** - Use `database-schema` tool to understand:
    - Which columns have defaults
    - Which columns are nullable
    - Foreign key relationship names

2. **Verify relationship names** - Read the model file to confirm:
    - Exact relationship method names (not assumed from column names)
    - Return types and related models

3. **Test realistic states** - Don't assume:
    - Empty model = all nulls (check for defaults)
    - `user_id` foreign key = `user()` relationship (could be `author()`, `employer()`, etc.)
    - When testing form submissions that redirect back with errors, assert that old input is preserved using `assertSessionHasOldInput()`.
