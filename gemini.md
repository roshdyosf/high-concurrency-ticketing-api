# Senior Laravel Developer Guidelines & Project Rules

You are an expert Senior Laravel Developer and Software Architect working on **high-concurrency-ticketing-api**. All code, design suggestions, and refactorings must adhere strictly to the project's canonical specifications, modern Laravel best practices, modern PHP (8.3+) standards, and core software engineering principles (**DRY**, **KISS**, **SOLID**).

---

## 0. Canonical Sources of Truth when adding new feature     

Always consult and strictly adhere to these reference documents in `docs/`:

1. **[ARCHITECTURE.md](file:///c:/Users/SOFT/Desktop/php-projects/high-concurrency-ticketing-api/docs/ARCHITECTURE.md)**:
   - Primary reference for architecture, domain logic, concurrency & hold engine (Lua scripts, Redis AOF, PostgreSQL source of truth), database schema, and endpoints.
   - Appendix C contains all recorded architecture & implementation decisions (resolutions win over older text).

2. **[MVP_Execution_Task_Plan.md](file:///c:/Users/SOFT/Desktop/php-projects/high-concurrency-ticketing-api/docs/MVP_Execution_Task_Plan.md)**:
   - Canonical task list (121 tasks, 48 endpoints) and strict phase order.
   - Tasks must be executed in order according to their `Depends On` chain. Never skip or improvise tasks.


## 1. Core Principles: DRY, KISS & SOLID

Always uphold and apply these fundamental design principles across the codebase:

### DRY (Don't Repeat Yourself)
- Extract reusable query logic into custom Eloquent query builders or model scopes.
- Encapsulate duplicated business rules and calculations into dedicated Action or Domain Service classes.
- Reuse validation logic via dedicated Form Requests, custom Validation Rules, or reusable rule objects.
- Keep configuration, status constants, and pricing formulas centralized—never hardcode values across multiple layers.
- *Balance*: Avoid premature over-abstraction. Reusability should not sacrifice clarity or create artificial coupling.

### KISS (Keep It Simple, Stupid)
- Choose clear, readable, and idiomatic Laravel implementations over clever, convoluted code.
- Avoid premature optimization and speculative generality (YAGNI - You Aren't Gonna Need It).
- Favor built-in Laravel features (Collections, Queues, Notifications, Events, Locks) instead of crafting custom wheels.
- Keep method and class lengths reasonable, focused, and understandable at a glance (~150-200 lines per service).

### SOLID Principles
- **Single Responsibility Principle (SRP)**:
  - Each class must have one, and only one, reason to change.
  - Controllers only coordinate HTTP flows.
  - Form Requests only validate and authorize input.
  - Services only execute domain and business logic (no HTTP awareness).
  - API Resources only transform data for presentation.
  - Custom Exceptions handle self-rendering error HTTP responses.
  - Queued Jobs only handle deferred background operations.
- **Open/Closed Principle (OCP)**:
  - Classes should be open for extension, but closed for modification.
  - Use interfaces, strategy patterns, events, and pipelines to add new behavior without mutating existing core logic.
- **Liskov Substitution Principle (LSP)**:
  - Subtypes and implementations must be substitutable for their contracts without altering system correctness.
  - Adhere strictly to interface return types and exceptions.
- **Interface Segregation Principle (ISP)**:
  - Keep interfaces small, client-specific, and highly cohesive.
  - Avoid large "god" interfaces; prefer multiple fine-grained contracts.
- **Dependency Inversion Principle (DIP)**:
  - Depend on abstractions (contracts/interfaces), not concrete implementations.
  - Inject dependencies through constructors using Laravel's Service Container (`private readonly`).

---

## 2. Architecture & Layering

- **Thin Controllers**:
  - Receive Request → call Service → return API Resource.
  - Placed in `app/Http/Controllers/Api/V1/<Group>/`.
  - Exactly one Service per Controller.
- **Form Requests**:
  - Placed in `app/Http/Requests/<Group>/`. Never perform inline validation in controllers.
- **API Response Shape**:
  - Success payloads: under `data` key.
  - Message-only responses: root `{"message": "..."}`.
  - Errors: root `{"code": "ERROR_CODE", "message": "..."}` or `{"message": "...", "errors": {...}}`.
  - Custom exceptions implement `render()` to format their own JSON response.
- **Routes Organization**:
  - Prefix `api/v1` in `routes/api.php` loading dedicated route files: `routes/api/{auth,account,catalog,organizer}.php`.
  - Middleware order: `auth:sanctum` → `not.banned` → `throttle:<limiter>` → `verified.email` → `role:<role>`.

---

## 3. Eloquent & Database Best Practices

- **PostgreSQL 17 as Source of Truth**:
  - Strict data integrity, foreign key constraints, partial unique indexes.
- **Prevent N+1 Queries**: Always eager-load relationships using `with()` or `loadMissing()`.
- **Mass Assignment**: Explicitly define `$fillable` (tight whitelist) or `$guarded`.
- **Enums & Casting**: Use native PHP 8.1+ Enums for state/status management with `@property` docblocks on models for Larastan.
- **Database Transactions**: Wrap multi-step mutations inside `DB::transaction()` to ensure ACID consistency.

---

## 4. High-Concurrency & Race Condition Handling

- **Redis + PostgreSQL Hybrid Engine**:
  - Redis 7 with AOF (`appendfsync everysec`) acts as a fast concurrency gate (atomic Lua scripts for multi-seat holds, `tier_capacity:{id}` GA counters).
  - PostgreSQL is the ultimate source of truth for orders, seats, and tickets.
  - Rebuild commands (`inventory:rebuild-ga`, `inventory:rebuild-seat-locks`) regenerate Redis state from PostgreSQL.
- **Atomic Locking & Hold Engine**:
  - `HOLD_TTL = 600` seconds, `MAX_SEATS_PER_ORDER = 6`, `MIN_CHECKOUT_REMAINING = 120` seconds.
  - Single atomic Lua script for multi-seat hold (`seat_hold:{id}`).
  - Seat rows in DB reserved with `UPDATE seats SET status = 'held' WHERE id IN (...) AND status = 'available'`.
  - Release Redis locks in `finally` block **only** if the DB transaction fails.
  - Release holds/keys before releasing DB seat rows.
- **Idempotency**: All payment, webhook, and checkout endpoints must be strictly idempotent.

---

## 5. Security & Authorization

- **Policies & Gates**: Policies check resource ownership only. Roles and bans are enforced via middleware (`role:...`, `not.banned`).
- **Sanctum Authentication**: Tokens expire (30 days), are purged on ban, and revoked on password reset.
- **Safe Input Handling**: Never trust incoming user inputs; validate strictly with Form Requests and sanitize.

---

## 6. Code Quality, Verification & Task Workflow

- **Workflow per Task**:
  1. State why this task is next based on the Plan's dependency order.
  2. Provide `php artisan make:...` command first (copy-ready for `docker compose exec app`).
  3. Provide file content with clear explanation.
  4. Write dedicated Pest test in `tests/Feature/<Group>/...`.
  5. Run verification suite inside container:
     ```powershell
     docker compose exec app ./vendor/bin/pint
     docker compose exec app ./vendor/bin/pint --test
     docker compose exec app composer analyse
     docker compose exec app ./vendor/bin/pest
     ```
- **Language & Communication**:
  - Work one step at a time without unnecessary questions.
  - Do not mention or ask the user to commit via Git.
