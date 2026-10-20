# MVP Execution Task Plan (v3.2)

**high-concurrency-ticketing-api** - derived strictly from MVP Reference Specification (v3.1); **[v3.2]** implementation decisions merged in (see Spec v3.2, Appendix C)

> **Scope rule.** Every task maps to text in the specification. No features, technologies or enhancements were added. Where the specification is silent, the plan does not decide for you: the item is listed in Section 6.2 with a recommended resolution so it can be recorded in the spec before the related task starts, as the spec requires.

> **Version note.** v3.1 = plan v2.1 rebuilt against spec v3.1. All 23 open items of plan v2.1 are mapped in Section 6.1: 18 are fully resolved by the spec, 3 partly, and 2 stay open; the remaining gaps are in 6.2. Every changed task is tagged **[v3.1]** (changed) or **[v3.1 new]** (new). Untagged text is unchanged from v2.1.

> **[v3.2] Version note.** v3.2 = v3.1 + status of finished work and the implementation decisions taken during Phases 1-2. Tags: ✅ = implemented and tested; **[impl #N]** = decision N from the (now retired) Decisions Log, recorded in Spec v3.2 Appendix C, which wins over any older text in this plan. The task list and counts are unchanged. The Admin seeder is covered by C2 (decision 55), not a new task.

**Totals:** 121 tasks (50 unchanged, 45 changed, 26 new) and 48 endpoints (v2.1: 95 tasks, 45 endpoints).

## Contents

- Change log
- Working rules
- 1 Phases
- 2 Master task table
- 3 Implementation guides
- 4 Traceability
- 5 Definition of done
- 6 Open items (6.1 resolution map, 6.2 remaining spec clarifications)

## Change Log - v2.1 to v3.1

| # | Change | Tasks / guides affected |
|---|---|---|
| 1 | Global `not.banned` middleware with exempt list; login rejects banned users; token purge on ban and on password reset; token expiry | C1, C3, C7, C9, J3, G4, G14 |
| 2 | Order pricing and currency snapshot; discount lifecycle, atomic usage counting; case-insensitive codes | B5, K1-K3, G2, G10 |
| 3 | Single atomic Lua multi-seat lock; compare-and-set seat UPDATE; release on failure only; one pending order per user per event; max 6 seats | D1, D2, G1, B5, G5 |
| 4 | Idempotent checkout, 2-minute cutoff, free-order path, order cancel endpoint | G4, G7, G8, guide G8 |
| 5 | Late payment: automatic refund (no re-booking); sweeper checks and cancels PaymentIntent; webhook idempotency table | D7, W1-W5, B13 |
| 6 | Refunds: whole order, five-state request, outside-transaction Stripe call, retry, finalization service | B8, H1-H6, W3 |
| 7 | GA: immutable `total_capacity`, idempotent release, Redis AOF, rebuild commands for counters and seat locks | B3, D4, D5, D9, A2, G20 |
| 8 | `events.end_date`: gatekeeper access and completion job use it; Redis authorization cache with TTL; atomic scan | B2, F1, F2, I4, I5, I7 |
| 9 | Organizer rejection endpoint; rejection reason columns | B1, J2, J9, C13 |
| 10 | Secure ticket codes (`random_bytes` 32); Cloudinary URL and `public_id` validation; verified email; admin purchase block; trusted proxies; 404 on unknown invitee email | I1, B7, P2, P3, C14, C15, A6, F7 |
| 11 | Rate limits: per-email login, apply-discount, gatekeeper invitations | L2, L6, L7 |
| 12 | Tests extended (N6-N13); docs extended (48 endpoints, five refund states) | N5-N13, O1, O2 |
| 13 | **[v3.2]** Phase 1-2 status and implementation decisions merged (scheduler service, trusted-proxy config, verify route public, gatekeeper `end_date` check, rate-limiter layout, Larastan, response shape, project structure, Admin seeder) | A2, A4, A6, C2-C15, L1-L7, F1-F11, G1, I4, W1, DoD, G1, G3, G4, G15 |

## Working Rules **[v3.2]**

1. **New files (#45):** for every new file give the `php artisan make:...` command first, copy-ready to run inside the container, then the file content, then `pint`. Examples: `make:controller Api/V1/<Group>/<Name>Controller`, `make:request <Group>/<Name>Request`, `make:resource`, `make:exception`, `make:middleware`, `make:policy --model=`, `make:factory --model=`, `make:command`, `make:test <Group>/<Name>Test --pest`, `make:provider`, `make:class Services/<Name>`, `make:enum Enums/<Name>` (if the last two are unavailable, create the file by hand).
2. **After every task (#38):** run `pint`, `pint --test`, `composer analyse` (Larastan level 6) and `pest`; all four must pass before the task is closed.
3. **Structure and conventions (#13, #41, #52, #53):** thin Controller, one Service per Controller, self-rendering custom exceptions; success data under `data`, message-only replies use root `message`, errors use `code` + `message` or `message` + `errors`; Controllers in `Api/V1/<Group>/`, FormRequests in `Requests/<Group>/`, Services/Exceptions/Middleware/Resources flat, Enums in `app/Enums`; the `/api/v1` prefix comes from `routes/api.php`.
4. **Test conventions (#28, #29, #49, #54):** see Spec Appendix C.4 (own temporary routes in middleware tests, `DatabaseTruncation`, `Cache::flush()` in `beforeEach`, `forgetGuards()` when switching users).
5. **Decisions:** any decision outside the spec is recorded in Spec Appendix C with the next number (**next: 56**) and tagged "my decision - record in the spec" before dependent code is written.

## 1. Categories and Order of Execution

| Phase | Categories | Why in this position |
|---|---|---|
| Phase 1 | A Foundation (A1-A6), B Database (B1-B13) | Nothing else starts without containers, Redis AOF and schema. |
| Phase 2 | C Auth and Access (C1-C15), L1, L2, L5 | Every endpoint depends on authentication, the global ban middleware and role scope. |
| Phase 3 | D Concurrency (D1-D9), E Catalog, F Organizer, P Cloudinary, L7 | Events, tiers and seats must exist before booking; D9 recovery commands arrive with the engine. |
| Phase 4 | G Booking, W Webhooks, K Discounts, L3, L6 | Order lifecycle and payment, including late-payment and idempotency handling. |
| Phase 5 | H Refunds, I Tickets/QR/Gatekeeper, J Admin, L4 | Post-payment features and moderation. |
| Phase 6 | N Testing (N1-N13), O Documentation, hardening | Tests are also written alongside phases 3-5; N9 recovery drill and N7 race tests gate release. |

**[v3.2] Status.** Phase 1 complete. Phase 2 complete except **C15**, which ships with G1 (hold-seats). Admin seeder done (#55). Next: Phase 3, starting with F1, then F4/F5, D1/D2/D4, E1-E4 (order driven by Depends On). Test suite at the last checkpoint: 138 passing.

There is no separate frontend or deployment work beyond Docker (A2), trusted proxies (A6) and the frontend's direct Cloudinary upload (P1). The MVP is an API-only deliverable.

## 2. Master Task Table (121 tasks)

Spec Ref = section number of the MVP Reference Specification v3.1. Depends On = task IDs that must be complete first.

### A. Foundation & DevOps

Project skeleton, containers, tooling (Sections 3, 13).

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| A1 | Initialize a Laravel 13 project on PHP 8.5. | 3 | - |
| A2 | **[v3.1]** Write `docker-compose.yml`: Laravel 13 app, PostgreSQL 17, Redis 7, Mailpit. Start Redis with `appendonly yes` and `appendfsync everysec` on a named volume. Run the Laravel scheduler as a process of the app container so the every-minute jobs (D7, I5) execute. The app entrypoint runs the Redis rebuild commands (D9) once Redis is ready. ✅ **[impl #8]** Scheduler is a separate compose service (`schedule:work`), not a process of the app container. | 3, 4.5, 13 | A1 |
| A3 | **[v3.1]** Configure environment: PostgreSQL; Redis for cache, locks and rate limiting; Mailpit; `stripe.api_version`, secret and webhook secret; Cloudinary `cloud_name` and folder prefix. Create `config/ticketing.php`: currency (ISO code), `hold_ttl = 600`, `max_seats_per_order = 6`, `min_checkout_remaining = 120`, stripe minimum chargeable amount per currency. Set Sanctum token expiration (30 days). **[impl #7, #55]** `config/ticketing.php`: currency `USD`, minimum charge `0.50`, `admin` credentials (`ADMIN_EMAIL`, `ADMIN_PASSWORD`). | 3, 4, 13 | A2 |
| A4 | Install and configure Laravel Pint with the PSR-12 preset. ✅ **[impl #38]** Larastan v3 level 6 added (`composer analyse`). | 13 | A1 |
| A5 | Install Pest PHP; test environment uses real PostgreSQL and real Redis (separate database and Redis DB index). No mocks for race tests. | 13 | A2 |
| A6 | **[v3.1 new]** Configure trusted proxies (read from env) before deployment so IP-based rate limits see the real client IP. ✅ **[impl #11]** Read from `TRUSTED_PROXIES` through `config/trustedproxy.php` (`env()` only inside `config/`), not `bootstrap/app.php`. | 12 | A1 |

### B. Database (PostgreSQL 17)

> **[impl #1-#6, #9, #10, #39]** Status defaults (`events.draft`, `seats.available`, `orders.pending`, `tickets.active`, `refund_requests.pending`, `event_gatekeepers.pending`); `unsignedInteger` for counts; `commission_percentage = 10`; no `onDelete` on FKs; no `remember_token`; `hasMany` on `event_gatekeepers`; `stripe_webhook_events.created_at` via `useCurrent()` without `updated_at`; `HasFactory` kept only on models that have a factory until N1.

One migration per table in Section 10, plus models. Create in this order so foreign keys resolve: `users`, `events`, `ticket_tiers`, `seats`, `discount_codes`, `orders`, `order_items`, `tickets`, `refund_requests`, `event_gatekeepers`, `platform_settings`, `stripe_webhook_events`. Development data is rebuilt with `migrate:fresh`.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| B1 | **[v3.1]** `users`: role enum (admin, organizer, customer, gatekeeper); `is_approved` default true; `is_banned`; `ban_reason`; `banned_at`; `email_verified_at`; unique email. Add `organizer_rejection_reason` (text, nullable) and `organizer_reviewed_at` (nullable). `gatekeeper` role value is reserved/unused. | 10.1 | A3 |
| B2 | **[v3.1]** `events`: `organizer_id` FK; title, description, `venue_name`, location, `event_date`, `image_url`, `image_public_id`; status enum. Add `end_date` NOT NULL with CHECK (`end_date > event_date`) via `DB::statement`; document the backfill (`event_date + 6 hours`) for any existing rows. | 10.2 | B1 |
| B3 | **[v3.1]** `ticket_tiers`: `event_id` FK; name; type enum; price decimal(10,2); `total_capacity`. `total_capacity` is the immutable total (seats generated, or GA capacity); it is never decremented. | 10.3 | B2 |
| B4 | `seats`: `ticket_tier_id` FK; `row_label`; `seat_number`; status enum available/held/booked. Unique index (`ticket_tier_id`, `row_label`, `seat_number`). | 10.4 | B3 |
| B5 | **[v3.1]** `orders`: `customer_id`, `event_id` FKs; currency char(3); subtotal; `discount_amount` default 0; `total_amount`; `discount_code_id` nullable FK; status enum (pending, paid, expired, failed, refunded, partially_refunded reserved); `failure_reason`; `stripe_payment_intent_id` nullable unique; `stripe_refund_id`; `expires_at`; `paid_at`. Raw SQL: partial unique index (`customer_id`, `event_id`) WHERE status = 'pending'; index (`status`, `expires_at`). | 10.5 | B2, B9 |
| B6 | `order_items`: `order_id`, `ticket_tier_id` FKs; `seat_id` nullable FK; quantity default 1; `unit_price`. | 10.6 | B4, B5 |
| B7 | **[v3.1]** `tickets`: `order_item_id` FK; `ticket_code` char(64) with unique index; status enum active/used/cancelled/refunded; `scanned_at`; `scanned_by` FK nullable. | 10.7 | B6 |
| B8 | **[v3.1]** `refund_requests`: `order_id`, `customer_id` FKs; status enum pending, rejected, processing_refund, approved, refund_failed; reason; `reviewed_by`; `reviewed_at`; `stripe_refund_id`; `failure_message`. Raw SQL: partial unique index (`order_id`) WHERE status IN ('pending', 'processing_refund', 'refund_failed'). | 10.8 | B5 |
| B9 | `discount_codes`: `event_id` FK; code (stored upper-case); `discount_type` enum; value; `max_uses`; `used_count` default 0; `valid_from`; `valid_until`; `is_active` default true. Unique index (`event_id`, `code`). | 10.9 | B2 |
| B10 | `event_gatekeepers`: `event_id`, `user_id`, `assigned_by` FKs; `assigned_at`; status enum pending/accepted/rejected default pending; `revoked_at` nullable. Raw SQL partial unique index (`event_id`, `user_id`) WHERE `revoked_at` IS NULL AND status IN ('pending', 'accepted'). | 10.10 | B2 |
| B11 | `platform_settings`: key unique; value string; `updated_at`. Seed `commission_percentage`. | 10.11 | B1 |
| B12 | **[v3.1]** One PHP backed enum per Enum column, cast on the models, plus all Eloquent relationships. Nine enums including `GatekeeperInvitationStatus`; `RefundStatus` now has five values. | 10 | B1-B11, B13 |
| B13 | **[v3.1 new]** `stripe_webhook_events`: `stripe_event_id` unique; type; `processed_at` nullable; `created_at`. Idempotency guard for webhook retries. | 10.12 | B1 |

### C. Authentication, Roles & Access Control

> **[impl]** Done and tested (Pest) except C15. Response shape (#41), project structure (#52, #53), JSON-only `api/*` with `401` on a missing token (#16). Middleware order inside `auth:sanctum` is mandatory: `not.banned` -> `throttle:api` -> `verified.email` -> `role:...` (#31).

Sanctum token auth, email flows, role and scope enforcement (Sections 2, 3, 7, 11, 13).

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| C1 | **[v3.1]** Install and configure Sanctum (personal access tokens). Token abilities are not used for authorization (C8). Tokens expire after the configured period (A3). ✅ | 3, 13 | B1 |
| C2 | `POST /auth/register` - always creates role customer, `is_approved` true; any role field is ignored; admins come from a seeder only. ✅ **[impl #12, #55]** `201` without a token; email lowercased; password min 8, no confirmation. Admin comes from `Database\Seeders\AdminSeeder` (idempotent; reads `ADMIN_EMAIL`/`ADMIN_PASSWORD` from config; skips with a warning when missing). | 11, 10.1 | C1 |
| C3 | **[v3.1]** `POST /auth/login` - return bearer token. Banned accounts are rejected with `403 ACCOUNT_BANNED` and no token is issued (login is exempt from the global ban middleware, so the check lives here). `is_approved = false` does not block login. ✅ **[impl #14]** Wrong credentials = `422` on `email`, one fixed message; the ban check runs after the password check. Shares the `auth` limiter with register plus `login-email` (#46). | 2, 7, 11 | C1 |
| C4 | `POST /auth/logout` - revoke the current token. ✅ **[impl #15]** `204`, no body, deletes the current token only. | 11 | C1 |
| C5 | `GET /auth/me` - authenticated user. ✅ | 11 | C1 |
| C6 | Email verification: resend notification and verify (id/hash) which sets `email_verified_at`. Mail via Laravel Mail, Mailpit in dev. ✅ **[impl #17-#20, #51]** Verification mail is sent automatically at registration; the verify route is public + `signed` + `throttle:public`; resend on a verified account = `409 EMAIL_ALREADY_VERIFIED`; verifying twice = `200`. | 11, 3 | C1, A3 |
| C7 | **[v3.1]** Password reset: forgot-password and reset-password. A successful reset deletes all of the user's Sanctum tokens. ✅ **[impl #21-#24, #50]** `forgot-password` always `200` with a fixed message; link = `FRONTEND_URL/reset-password?token=...&email=...`; any reset failure = `422` on `email`; new password min 8, no confirmation; `password` limiter. | 11, 13 | C1, A3 |
| C8 | Permission middleware evaluated from the database on every request: admin group = role admin; organizer group = role organizer AND `is_approved`; customer group = role customer or organizer (including an unapproved organizer); Account & Invitations group = any authenticated non-banned user; scan-ticket has no role check and is authorized only by C12. ✅ **[impl #25-#27]** One middleware alias `role` taking the group name; the organizer group also checks `is_approved` (role first); codes `403 FORBIDDEN_ROLE` and `403 ORGANIZER_NOT_APPROVED`. | 2 | C1 |
| C9 | **[v3.1]** Global `not.banned` middleware on every route except: public event browsing, login/registration, password reset and signed Stripe webhooks. Reads `is_banned` from the database user on each request. Replaces the per-route banned checks of v2.1. ✅ **[impl #16, #18, #30, #31]** "Global" = the whole `auth:sanctum` group. The exempt list also includes the signed email-verification link. Order: `auth:sanctum` -> `not.banned` -> `throttle:api`. | 2, 7, 13 | C1, C8 |
| C10 | Organizer approval gate: role organizer with `is_approved = false` gets 403 on every `/organizer` route; customer routes and login are unaffected. ✅ **[impl #32]** Implemented inside `EnsureUserHasRole`; the real `/organizer` routes (F1) replace the temporary test routes. | 7, 10.1 | C8 |
| C11 | Ownership policy (`EventPolicy`): every organizer action verifies the `event_id` (directly, or via `tier_id` / refund-request id) belongs to the authenticated organizer. ✅ **[impl #33-#35]** `EventPolicy::manage` = ownership only (no admin exception). Tiers and refund requests have no own policy: the Service loads the parent event and calls `Gate::authorize('manage', $event)`. Missing event = `404` before the policy's `403`. | 2 | C8, B2 |
| C12 | Reusable gatekeeper scope check: an `event_gatekeepers` row for that event with status accepted and `revoked_at IS NULL` (the Redis cache layer is I7). ✅ **[impl #36, #37]** `GatekeeperAccessService::canScan` (a service, not a Policy); also requires the event not `completed` and `end_date > now()`. `cancelled`/`suspended` is a separate check in I4. I7 adds the cache inside this service. | 2, 6 | C8, B10 |
| C13 | **[v3.1]** `POST /account/organizer-request` - customers only (409 if organizer, pending or approved); requires verified email (C14); sets `role = organizer`, `is_approved = false` and clears `organizer_rejection_reason`. ✅ **[impl #42-#44]** `409 ORGANIZER_REQUEST_NOT_ALLOWED` for any role other than customer (including admin); success `200` + `UserResource`; re-applying clears only `organizer_rejection_reason`, `organizer_reviewed_at` is kept. | 7, 11, 10.1 | C8, B1, C14 |
| C14 | **[v3.1 new]** Verified-email middleware applied to hold-seats and organizer-request (403/409 style error with a clear code). ✅ **[impl #40]** Custom API middleware `verified.email` (not Laravel's `verified`) returning `403 EMAIL_NOT_VERIFIED`; applied to organizer-request; hold-seats gets it in G1. | 2, 13 | C6 |
| C15 | **[v3.1 new]** Admin purchase block: hold-seats returns 403 for role admin. Organizers may buy tickets for their own events (no restriction). ⏳ **[impl]** Deferred: implemented together with G1 (hold-seats), as G1 is where the route exists. | 2, 13 | C8 |

### D. Concurrency & Hold Engine (Redis 7)

The core of the project (Section 4). Detailed guides G5-G8 and G20.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| D1 | **[v3.1]** Single atomic Lua script for multi-seat holds: receives all seat keys `seat_hold:{id}`, checks that none exists, then sets all with the holder token and TTL 600 (all-or-nothing). Plus a release script that deletes a key only if its value equals the holder token. | 4.1 | A3 |
| D2 | **[v3.1]** Multi-seat hold service: validate `seat_ids[]` (size at most `max_seats_per_order`, all seats exist, one event); run D1; then ONE transaction with a single `UPDATE seats SET status = 'held' WHERE id IN (sorted ids) AND status = 'available'`, requiring affected rows = number of seats, else rollback and 409. Release the Redis keys in `finally` only when the transaction did not commit (`$committed` flag). | 4.1, 4.4 | D1, B4 |
| D3 | **[v3.1]** Contiguous seats finder: available seats of a tier ordered by `row_label` then `seat_number`; runs of consecutive seat numbers of length N or more within one row; N from 1 to `max_seats_per_order`. | 4.2 | B4 |
| D4 | **[v3.1]** GA capacity counter: initialise `tier_capacity:{tier_id}` from `total_capacity`; Lua `DECRBY` that never goes below zero. DB `total_capacity` is never changed. | 4.3 | A3, B3 |
| D5 | **[v3.1]** GA restore: `INCRBY` on `tier_capacity:{tier_id}` by the `order_item` quantity, executed only when the order's pending to expired (or refunded) compare-and-set affected exactly one row, so a counter is never restored twice. | 4.3 | D4 |
| D6 | Hold record: each hold is the pending order with `expires_at = now + 10 minutes`. | 4.3, 4.4, 10.5 | B5 |
| D7 | **[v3.1]** Sweeper, every minute, for pending orders with `expires_at` in the past: if the order has a PaymentIntent, retrieve its status from Stripe - `succeeded` means run the payment-success handler (W2); `processing` means skip this round; otherwise cancel the intent (outside any DB transaction). Then compare-and-set the order to expired and release seats (to available), GA counters (D5), the discount usage (K3) and Redis keys (D8). | 4.3, 4.4 | D5, D6, A2, K3, W2 |
| D8 | Shared release routine for Redis keys (used by D7, W2, G7, H6). | 4.4 | D1, D4 |
| D9 | **[v3.1 new]** Artisan commands `inventory:rebuild-ga` (available = `total_capacity` minus SUM(quantity) of `order_items` whose order is paid, or pending with `expires_at` in the future) and `inventory:rebuild-seat-locks` (recreate `seat_hold` keys for seats of unexpired pending orders with the remaining TTL). Run on deploy and after any Redis restart. | 4.5, 13 | D1, D4, B6 |

### E. Public & Customer Catalog

Read-only endpoints (Section 11 - Public & Customer).

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| E1 | `GET /events` - list with search/filter by name, location, organizer (PostgreSQL full-text search). Only published events; suspended are never listed. | 11, 3, 7 | B2 |
| E2 | `GET /events/{id}` - event details with ticket tiers. | 11 | B3 |
| E3 | `GET /events/{id}/seats` - seat map with statuses. | 11 | B4 |
| E4 | `GET /events/{id}/tiers/{tier_id}/seats/contiguous?count=N` - suggested groups using D3. | 4.2, 11 | D3 |

### F. Organizer Features

Only for the organizer's own events (Sections 2, 6, 11). F1-F8 require role organizer AND `is_approved`. F9-F11 are the invitee side and are open to any authenticated user.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| F1 | **[v3.1]** `POST /organizer/events` - create event; `end_date` required and after `event_date`. | 11, 10.2 | C10, C11, B2 |
| F2 | **[v3.1]** `PUT /organizer/events/{event_id}` - update event; validate Cloudinary values with P2; delete the previous image on update. If `end_date` changes, delete the gatekeeper cache keys of that event (I7). | 11, 10.2, 6 | F1, P2, I7 |
| F3 | `POST /organizer/events/{event_id}/publish` - move to published (requires `end_date` and at least one tier). | 11 | F1 |
| F4 | `POST /organizer/events/{event_id}/tiers` - add a seated or `general_admission` tier; initialise the D4 counter for GA. | 11, 10.3 | F1, D4 |
| F5 | `POST /organizer/tiers/{tier_id}/seats/bulk` - generate seats from rows x seats per row; `total_capacity` = seats generated. | 11, 10.3, 10.4 | F4 |
| F6 | `GET /organizer/events/{event_id}/analytics` - sales and occupancy report. | 11 | F1, G2 |
| F7 | **[v3.1]** `POST /organizer/events/{event_id}/gatekeepers` - invite by email of a registered user (404 if not registered); creates a pending `event_gatekeepers` row; refused for completed, cancelled or suspended events; 409 on an active duplicate (partial unique index violation). Rate limit L7. | 6, 11 | C12, C11, B10, L7 |
| F8 | **[v3.1]** `DELETE /organizer/events/{event_id}/gatekeepers/{user_id}` - cancel a pending invitation or remove an accepted gatekeeper by setting `revoked_at` (row kept); delete the Redis cache key (I7). | 6, 11 | F7, I7 |
| F9 | `GET /gatekeeper-invitations` - the user's own pending invitations (revoked excluded). | 6, 11 | F7, C8 |
| F10 | `POST /gatekeeper-invitations/{id}/accept` - invited user only; pending to accepted; refused if revoked or the event is completed. | 6, 11 | F9 |
| F11 | `POST /gatekeeper-invitations/{id}/reject` - invited user only; pending to rejected. | 6, 11 | F9 |

### G. Booking, Orders & Payment (Stripe)

Order lifecycle of Section 4.4 and endpoints of Section 11 - Booking & Orders.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| G1 | **[v3.1]** `POST /orders/hold-seats` - `seat_ids[]` or `tier_id` + `quantity`. Preconditions: verified email (C14); not an admin (C15); event published and now before `event_date`; items belong to one event; quantity at most 6; no other pending order by this user for this event (409 returning the existing order id). ⏳ **[impl]** Also delivers C15 (admin purchase block) and adds `verified.email` to this route (#31 order). | 4.1, 4.4, 11 | D2, D4, C14, C15 |
| G2 | **[v3.1]** After a successful lock, in one DB transaction create the pending order: currency snapshot from config, subtotal, `discount_amount` 0, `total_amount` = subtotal, `event_id`, `expires_at`; `order_items` (one per seat or one with quantity for GA); seats to held (D2). | 4.4, 10.5, 10.6 | G1, D6 |
| G3 | **[v3.1]** `POST /orders/{order_id}/apply-discount` - see K1. Refused (409) once a PaymentIntent exists. Rate limit L6. | 8, 11 | K1, L6 |
| G4 | **[v3.1]** `POST /orders/{order_id}/checkout` - idempotent. Order must belong to the caller and be pending. Reject with `409 ORDER_EXPIRING` when fewer than 120 seconds remain. If `stripe_payment_intent_id` exists, retrieve and return that intent; otherwise create one with idempotency key `checkout-{order_id}`, amount = `total_amount` in the currency's smallest unit; store the id; lock the discount; increment `used_count` (K3). If `total_amount` is 0, confirm the order directly (G8). | 4.4, 8, 11 | G2, K3 |
| G5 | `GET /orders/my-orders` - the authenticated user's orders. | 11 | B5 |
| G6 | Return 409 Conflict whenever a requested seat/allocation is already booked or held. | 4.4 | G1 |
| G7 | **[v3.1 new]** `DELETE /orders/{order_id}` - cancel the caller's own pending order: cancel the PaymentIntent (outside transactions), compare-and-set to expired with `failure_reason = customer_cancelled`, release seats/GA/discount usage/Redis keys. | 4.4, 11 | G2, D8, K3 |
| G8 | **[v3.1 new]** Free-order path: when `total_amount` is 0 no PaymentIntent is created; checkout runs the same confirmation transaction as W2 (order paid, seats booked, tickets generated). | 4.4 | G4, I1 |

### W. Stripe Webhooks

`POST /webhooks/stripe` (Section 11 - Webhooks). Handled events: `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| W1 | **[v3.1]** Webhook endpoint: public route (no bearer auth, exempt from C9); verify the Stripe signature on the raw body; insert `stripe_event_id` into `stripe_webhook_events` and skip events already processed; dispatch by type. **[impl]** Lives in the public group; a test must prove `not.banned` does not apply to it. | 11, 13 | A3, B13 |
| W2 | **[v3.1]** `payment_intent.succeeded` handler: in one transaction lock the order row (`SELECT ... FOR UPDATE`). If pending: seats to booked, order to paid (`paid_at`), tickets generated (I1), Redis keys removed (D8). If already paid: no-op. If expired or failed: W5. | 4.4 | W1, I1, D8 |
| W3 | **[v3.1]** `charge.refunded` handler: call the shared refund-finalization service (H6); idempotent. | 11, 5 | W1, H6 |
| W4 | **[v3.1 new]** `payment_intent.payment_failed` handler: order stays pending (customer may retry on the same intent); log the failure. | 4.4 | W1 |
| W5 | **[v3.1 new]** Late payment handler (order already expired): do not re-book. Issue an automatic Stripe refund outside the transaction with idempotency key `refund-{order_id}`; set order status failed, `failure_reason` late_payment, store `stripe_refund_id`. | 4.4 | W2 |

### H. Refund Workflow

Section 5. States: `pending` -> `rejected` | `processing_refund` -> `approved` | `refund_failed` -> `processing_refund` (retry).

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| H1 | **[v3.1]** `POST /orders/{order_id}/refund-request` - whole order only: order paid, all tickets active, more than 24 hours before `event_date`, no open request (partial unique index). | 5, 11 | B8, W2 |
| H2 | **[v3.1]** `POST /organizer/refund-requests/{id}/approve` - owning organizer only. Transaction 1: request to `processing_refund`, `reviewed_by`/`reviewed_at`. Then outside any transaction call the Stripe Refund API with idempotency key `refund-{order_id}`; on success run H6; on failure set `refund_failed` with `failure_message`. Free orders skip Stripe. | 5, 11, 10.8 | H1, C11 |
| H3 | `POST /organizer/refund-requests/{id}/reject` - request to rejected, reviewed fields set; tickets stay active. | 5, 11 | H1, C11 |
| H4 | `GET /organizer/events/{event_id}/refund-requests` - list for the organizer's event. | 11 | H1, C11 |
| H5 | **[v3.1 new]** `POST /organizer/refund-requests/{id}/retry` - for `refund_failed` only; back to `processing_refund` and repeat the Stripe call with the same key. | 5, 11 | H2 |
| H6 | **[v3.1 new]** Refund-finalization service (idempotent; used by H2, H5, W3): request approved, order refunded (`stripe_refund_id`), tickets refunded, seats back to available / GA counter `INCRBY` (D5), Redis keys cleared. | 5 | B8, D5 |

### I. Tickets, QR & Gatekeeper Scanning

Sections 4.4, 6, 9 and 11.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| I1 | **[v3.1]** Ticket generation service: one ticket per seat / per GA unit; `ticket_code = bin2hex(random_bytes(32))`, status active. | 4.4, 9, 10.7 | B7 |
| I2 | `GET /tickets/my-tickets` - the customer's tickets. | 11 | I1 |
| I3 | `GET /tickets/{ticket_id}/qr` - Base64/SVG QR on demand for the ticket owner; payload is `ticket_code` only. No PDF, no email. | 1, 9, 11 | I1 |
| I4 | **[v3.1]** `POST /gatekeeper/scan-ticket` - find the ticket by `ticket_code`; authorize the caller for the ticket's event through I7; event must not be cancelled or suspended; atomic `UPDATE tickets SET status = 'used' ... WHERE id = ? AND status = 'active'`. Responses: 200 valid, 403 not assigned or access ended, 404 unknown code, 409 already used/not active, 422 malformed payload. **[impl #37]** The access check is `GatekeeperAccessService::canScan` (C12); this task adds the `cancelled`/`suspended` event check. Scan route is excluded from `throttle:api` (see L4). | 6, 9, 11 | I1, C12, I7 |
| I5 | **[v3.1]** Scheduled job: when `end_date` has passed, set the event to completed and set `revoked_at` on all its `event_gatekeepers` rows not yet revoked (pending included); delete their cache keys. | 6 | B10, A2, I7 |
| I6 | Any scan by a revoked gatekeeper or after the event has ended returns 403. | 6 | I4, I5 |
| I7 | **[v3.1 new]** Gatekeeper authorization cache: key `gk:{event_id}:{user_id}` with TTL = `end_date` minus now; read from Redis, fall back to PostgreSQL on a miss and populate; delete on revoke, removal, completion and `end_date` change. | 6 | C12, A3 |

### J. Admin Moderation

Sections 7 and 11 - Admin.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| J1 | `GET /admin/organizers` - list organizers including pending requests; filter by approval status. | 11 | C8 |
| J2 | **[v3.1]** `POST /admin/organizers/{id}/approve` - only a user with role organizer and `is_approved` false; sets `is_approved = true` and `organizer_reviewed_at`. | 7, 11 | J1 |
| J3 | **[v3.1]** `POST /admin/users/{id}/ban` - set `is_banned`, `ban_reason`, `banned_at`; delete all Sanctum tokens; expire the user's pending orders and release their holds; organizer: move existing events to suspended; customer: existing tickets stay valid but QR endpoints are unreachable while banned. | 7, 11, 2 | C9, D8 |
| J4 | `POST /admin/users/{id}/unban` - lift the ban (suspended events are not restored automatically; see Section 6.2). | 11 | J3 |
| J5 | `GET /admin/events` - all events on the platform. | 11 | C8 |
| J6 | `POST /admin/events/{id}/suspend` - hide a policy-violating event (no new bookings). | 2, 11 | J5 |
| J7 | `GET/PUT /admin/settings` - view and update the commission rate. | 7, 11 | B11 |
| J8 | **[v3.1]** `GET /admin/reports/overview` - total sales, active events, approved organizers; commission informational only. Uses currency and net totals. | 7, 11 | G2, J7 |
| J9 | **[v3.1 new]** `POST /admin/organizers/{id}/reject` - pending requests only (409 otherwise); sets `role = customer`, `is_approved = true`, stores `organizer_rejection_reason` and `organizer_reviewed_at`; the user can re-apply. | 7, 11 | J1 |

### K. Event-Scoped Discount Codes

Section 8.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| K1 | **[v3.1]** Discount application: code belongs to the order's event; type percentage or `fixed_amount`; check `is_active`, validity window, `max_uses`; applying a code replaces any previous one; `discount_amount` capped at subtotal; `total_amount = subtotal - discount_amount`; reject codes leaving a non-zero total below the Stripe minimum; refuse once a PaymentIntent exists. | 8 | B9, G2 |
| K2 | **[v3.1]** Enforce (`event_id`, `code`) uniqueness; store codes upper-case and match case-insensitively. | 8, 10.9 | B9 |
| K3 | **[v3.1 new]** Usage counting: at checkout run `UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ? AND (max_uses IS NULL OR used_count < max_uses)`; zero rows means 409 (exhausted). Revert once (guarded by the order compare-and-set) when the order expires, fails or is cancelled before payment. | 8 | K1 |

### L. Rate Limiting (Redis + Laravel Throttle)

> **[impl #46-#51]** Limiters live in `App\Providers\RateLimitServiceProvider`; the store is the default cache store (Redis) without `throttleWithRedis()`. Extra limiters beyond the original list: `password` (forgot + reset, 5/min per IP) and `public` on the email-verify link.

Section 12 - named limiters backed by Redis.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| L1 | Public GET: 120 requests/minute per IP. ✅ | 12 | A3 |
| L2 | **[v3.1]** Login / Register: 5 requests/minute per IP, plus 5 login attempts/minute per email. ✅ **[impl #46]** Register and login share one `auth` bucket (5/min per IP); `login-email` (5/min per email, lowercased and trimmed inside the limiter because it runs before the FormRequest). | 12 | C2, C3 |
| L3 | hold-seats: 10 requests/minute per authenticated user. ⏳ **[impl]** Pending decision at this task: exclude hold-seats from `throttle:api` (probably `->withoutMiddleware('throttle:api')`) so it is not counted twice; record as decision 56+. | 12 | G1 |
| L4 | scan-ticket: 30 requests/minute per user. ⏳ **[impl]** Pending decision at this task: exclude scan-ticket from `throttle:api` (probably `->withoutMiddleware('throttle:api')`) so it is not counted twice; record as decision 56+. | 12 | I4 |
| L5 | All remaining authenticated routes: 60 requests/minute per user (includes Account & Invitations). ✅ **[impl #31]** Applied to the `auth:sanctum` group right after `not.banned`; key = user id. | 12 | C1 |
| L6 | **[v3.1 new]** apply-discount: 10 requests/minute per user. ⏳ **[impl]** Pending decision at this task: exclude apply-discount from `throttle:api` (probably `->withoutMiddleware('throttle:api')`) so it is not counted twice; record as decision 56+. | 12 | G3 |
| L7 | **[v3.1 new]** Gatekeeper invitations: 20 requests/hour per organizer. ⏳ **[impl]** Pending decision at this task: exclude gatekeeper invitations from `throttle:api` (probably `->withoutMiddleware('throttle:api')`) so it is not counted twice; record as decision 56+. | 12 | F7 |

### P. Cloudinary

Section 3, 10.2, 13.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| P1 | Frontend-facing contract: unsigned upload directly to Cloudinary, then send `secure_url` and `public_id` to F1/F2. | 3, 10.2 | F1 |
| P2 | **[v3.1 new]** Backend validation: `image_url` must match `https://res.cloudinary.com/{cloud_name}/...` (exact host and cloud name); `public_id` must start with the designated folder prefix and be the one contained in the URL path; reject a `public_id` already used by another event. | 13 | F1 |
| P3 | **[v3.1 new]** Ops task: restrict the unsigned upload preset on Cloudinary (allowed formats, maximum file size, target folder). | 3, 13 | P1 |

### N. Automated Testing (Pest PHP)

Section 14. Write tests alongside phases 3-5.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| N1 | Model factories for every table used by tests (including `event_gatekeepers`, `discount_codes`, `orders`). | 14 | B12 |
| N2 | Race: many concurrent requests for the same seat - exactly one succeeds, the rest 409. | 14, 4.1 | D2 |
| N3 | Race: exhaust GA capacity concurrently - balance never below zero, no overselling. | 14, 4.3 | D4 |
| N4 | Lifecycle: pending to paid / expired, sweeper, seat and GA release. | 14, 4 | D3, D7, W2 |
| N5 | **[v3.1]** Feature tests: refund eligibility, gatekeeper scope, ownership policies, register ignores role, organizer-request rules, unapproved organizer blocked from `/organizer` yet can book, invitation flow (invite, accept, reject, re-invite, duplicate refused, revoke, accept after revoke/completion refused, only invitee responds), organizer rejection. | 14, 5-8 | H3, I6, C11, C13, F11, J9 |
| N6 | **[v3.1 new]** Overlapping multi-seat requests in opposite order (deadlock check); one-pending-order-per-event rule; max 6 seats; admin blocked; unverified email blocked. | 14, 4.1 | D2, G1 |
| N7 | **[v3.1 new]** Webhook: duplicate delivery processed once; late payment after expiry refunds and sets failed; sweeper versus webhook race on the same order; sweeper finds a succeeded intent. | 14, 4.4 | W1, W5, D7 |
| N8 | **[v3.1 new]** Refunds: whole-order rule, `processing_refund` then approved, Stripe failure then `refund_failed` then retry, inventory released, free-order refund without Stripe. | 14, 5 | H2, H5, H6 |
| N9 | **[v3.1 new]** Recovery drill: flush Redis, run D9 commands, assert GA counters and seat locks equal PostgreSQL. | 14, 4.5 | D9 |
| N10 | **[v3.1 new]** Ban: banned login rejected, tokens deleted, middleware blocks protected routes, exempt routes still work, webhooks still processed, pending orders released, QR unreachable until unban. | 14, 2, 7 | J3, C9 |
| N11 | **[v3.1 new]** Gatekeeper: access until `end_date` and 403 after, cache TTL and invalidation, double scan (one 200, rest 409), scan blocked for cancelled/suspended event. | 14, 6 | I4, I5, I7 |
| N12 | **[v3.1 new]** Cloudinary tampering: foreign cloud name, foreign folder, reused `public_id` all rejected. | 14, 13 | P2 |
| N13 | **[v3.1 new]** Discounts: replace code, cap at subtotal, 100 percent path, concurrent last use of `max_uses`, usage released on expiry/cancel, locked after PaymentIntent. | 14, 8 | K1, K3, G8 |

### O. API Documentation (Scramble)

Sections 3 and 14.

| ID | Task | Spec Ref | Depends On |
|---|---|---|---|
| O1 | **[v3.1]** Install Scramble; generate OpenAPI 3.0 for every endpoint in Section 11 (48 endpoints). | 3, 14 | all endpoints |
| O2 | **[v3.1]** Document all enums and state machines: order, ticket, refund (five states), gatekeeper invitation. | 14 | O1, B12 |

## 3. Implementation Guides

Each guide states the task IDs it covers. Code samples illustrate the approach using only the stack named in the spec (Laravel 13, PHP 8.5, PostgreSQL 17, Redis 7 Lua). Guides without a tag are unchanged from v2.1.

### G1. Environment, Docker and Tooling

**Covers tasks:** A1-A6

1. Create the project with Laravel 13 on PHP 8.5.
2. **[v3.1]** `docker-compose.yml`: app, postgres (image `postgres:17`, named volume), redis (image `redis:7`) and mailpit. Start Redis with the command `redis-server --appendonly yes --appendfsync everysec` and mount a named volume on `/data`.
3. **[impl #8]** Run the scheduler as its own compose service (`scheduler`, `php artisan schedule:work`) next to the web service so the every-minute jobs (D7, I5) execute.
4. **[v3.1]** Create `config/ticketing.php` with currency, `hold_ttl`, `max_seats_per_order`, `min_checkout_remaining` and a per-currency Stripe minimum map; read Cloudinary `cloud_name` and folder from `config/services.php`; set `sanctum.expiration` (minutes) to 30 days.
5. **[v3.1]** **[impl #11]** Configure trusted proxies from `TRUSTED_PROXIES` through `config/trustedproxy.php` before deployment (A6). Verify with a request through the proxy that the throttle key is the client IP.
6. Install Pint (PSR-12) and Pest; the test environment uses its own database and Redis DB index.

### G2. Migrations and Models

**Covers tasks:** B1-B13

1. **[v3.1]** Create migrations in this order: `users`, `events`, `ticket_tiers`, `seats`, `discount_codes`, `orders`, `order_items`, `tickets`, `refund_requests`, `event_gatekeepers`, `platform_settings`, `stripe_webhook_events`. `discount_codes` precedes `orders` because `orders.discount_code_id` references it.
2. Use native enum-like columns (string + CHECK, or Laravel enum column) and a matching PHP backed enum per Enum column (nine enums).
3. **[v3.1]** Partial unique indexes via `DB::statement` right after `Schema::create`:

   ```sql
   -- orders: one pending order per user per event
   CREATE UNIQUE INDEX orders_one_pending_per_event
     ON orders (customer_id, event_id) WHERE status = 'pending';
   -- refund_requests: one open request per order
   CREATE UNIQUE INDEX refund_one_open_per_order ON refund_requests (order_id)
     WHERE status IN ('pending', 'processing_refund', 'refund_failed');
   -- event_gatekeepers: one active invitation/assignment per user per event
   CREATE UNIQUE INDEX gk_one_active ON event_gatekeepers (event_id, user_id)
     WHERE revoked_at IS NULL AND status IN ('pending', 'accepted');
   -- events: end_date after event_date
   ALTER TABLE events ADD CONSTRAINT events_end_after_start CHECK (end_date > event_date);
   ```

4. Other unique indexes: `seats (ticket_tier_id, row_label, seat_number)`, `discount_codes (event_id, code)`, `users.email`, `tickets.ticket_code`, `platform_settings.key`, `orders.stripe_payment_intent_id`, `stripe_webhook_events.stripe_event_id`.
5. Seed `platform_settings` with `commission_percentage` (initial value: Section 6.2, item S4). Define relationships as in the schema.

### G3. Sanctum Authentication and Email Flows

**Covers tasks:** C1-C7, C14

1. **[v3.1]** Install Sanctum; on login create a plain token with the configured expiration. Do not encode role in abilities.
2. Register: validate name, email, password; always create role customer and `is_approved` true; role is not fillable and not validated.
3. **[v3.1]** Login: verify credentials, then if `is_banned` return `403 ACCOUNT_BANNED` without issuing a token. **[impl #14, #46]** Wrong credentials = `422` on `email` (one message); the ban check comes after the password check. Throttle per IP (5/min, shared with register) and per email (5/min).
4. Logout deletes the current token; me returns the user.
5. **[v3.1]** Password reset: after a successful reset delete all of the user's tokens (`$user->tokens()->delete()`).
6. **[v3.1]** Verified-email middleware (C14) on hold-seats and organizer-request returns 403 with code `EMAIL_NOT_VERIFIED` **[impl #40]** (custom `verified.email` middleware).

### G4. Middleware, Ownership and Assignment Scope

**Covers tasks:** C8-C12, C15

1. **[v3.1]** Route layout: the public group (no ban check) holds public event browsing, register, login, forgot/reset password, **[impl #18]** the signed email-verification link (`signed` + `throttle:public`) and `webhooks/stripe`. Everything else sits in `auth:sanctum` -> `not.banned` -> `throttle:api` -> `verified.email` -> `role:...` **[impl #30, #31]**; inside it, nested groups apply the role middleware.
2. **[v3.1]** `not.banned` reads `is_banned` from the freshly loaded user on every request and answers `403 ACCOUNT_BANNED`. It replaces the per-route banned checks of v2.1.
3. Permission groups: `/admin` (role admin); `/organizer` (role organizer AND `is_approved`); customer routes `/orders` and `/tickets` (customer or organizer); `/account/organizer-request` and `/gatekeeper-invitations` (any authenticated non-banned user); `/gatekeeper/scan-ticket` (no role middleware, only I7/C12).
4. `EventPolicy::manage(User, Event)` = `event.organizer_id` equals `user.id`; for `tier_id` and refund-request routes resolve to the parent event first **[impl #33-#35]** (no admin exception, no separate policies, 404 before 403).
5. **[v3.1]** Admin purchase block: in the hold-seats form request, `abort(403)` when the user's role is admin. No rule blocks an organizer from their own events.
6. Return 403 for scope violations and 404 for missing resources.

### G5. Seat-Based Hold (Redis Lua)

**Covers tasks:** D1, D2, D6, G1, G2, G6

1. **[v3.1]** One script locks all seats or none:

   ```lua
   -- KEYS = seat_hold:{id} ... ; ARGV[1] = holder token ; ARGV[2] = TTL (600)
   for i = 1, #KEYS do
     if redis.call('EXISTS', KEYS[i]) == 1 then return 0 end
   end
   for i = 1, #KEYS do
     redis.call('SET', KEYS[i], ARGV[1], 'EX', ARGV[2])
   end
   return 1
   ```

2. Release script: for each key delete it only if GET equals the holder token, so one request never frees another customer's lock.
3. **[v3.1]** Hold service. Sort the ids, run the lock script (0 means 409), then one DB transaction with a single conditional UPDATE and an affected-rows check. Release the keys in `finally` only when the transaction did not commit:

   ```php
   sort($ids);
   if (! $this->lockSeats($keys, $token, config('ticketing.hold_ttl'))) {
       throw new SeatsUnavailable(); // 409
   }
   $committed = false;
   try {
       $order = DB::transaction(function () use ($ids, $user, $event) {
           $n = DB::table('seats')->whereIn('id', $ids)
               ->where('status', 'available')->update(['status' => 'held']);
           if ($n !== count($ids)) { throw new SeatsUnavailable(); } // rollback
           return $this->createPendingOrder($user, $event, $ids); // G2
       });
       $committed = true;
   } finally {
       if (! $committed) { $this->releaseSeats($keys, $token); }
   }
   ```

4. **[v3.1]** G1 preconditions run first: verified email, not admin, event published and now before `event_date`, at most 6 seats, items of one event. The partial unique index on orders turns a second pending order for the same event into a unique violation: catch it and return 409 with the existing order id.
5. **[v3.1]** G2 builds the order inside the same transaction: currency from config, subtotal = sum of unit prices, `discount_amount` 0, `total_amount` = subtotal, `expires_at = now + 10 minutes`.
6. A seat whose Redis key expired but whose DB row is still held (up to one minute until the sweeper) yields a temporary 409; this is accepted by the spec.

### G6. Contiguous Seats Finder

**Covers tasks:** D3, E4

1. Validate count between 1 and **[v3.1]** `max_seats_per_order` (6).
2. Query available seats of the tier ordered by `row_label` then `seat_number`; group by row; keep a run only while `seat_number` equals previous + 1.
3. Emit the first N seats of each qualifying run (several windows allowed). Return as a suggestion only; nothing is locked here.

```php
$seats = Seat::where('ticket_tier_id', $tierId)->where('status', 'available')
    ->orderBy('row_label')->orderBy('seat_number')->get()->groupBy('row_label');
foreach ($seats as $row => $rowSeats) {
    $run = [];
    foreach ($rowSeats as $seat) {
        if ($run && $seat->seat_number !== end($run)->seat_number + 1) { $run = []; }
        $run[] = $seat;
        if (count($run) === $n) { $groups[] = $run; break; }
    }
}
```

### G7. General-Admission Capacity (Redis Lua)

**Covers tasks:** D4, D5, F4, G1

1. When a GA tier is created (F4) set `tier_capacity:{tier_id}` to `total_capacity`. `total_capacity` in the DB stays the immutable total.
2. Hold script, never below zero:

   ```lua
   -- KEYS[1] = tier_capacity:{tier_id} ; ARGV[1] = quantity
   local cur = tonumber(redis.call('GET', KEYS[1]) or '0')
   local qty = tonumber(ARGV[1])
   if cur < qty then return -1 end
   return redis.call('DECRBY', KEYS[1], qty)
   ```

3. -1 means 409. Otherwise create the pending order with one `order_item` (`seat_id` null, quantity N). Quantity is capped at 6 and the one-pending-order-per-event rule applies.
4. **[v3.1]** Release is idempotent: `Order::where('id',$id)->where('status','pending')->update(['status'=>'expired']);` call `INCRBY` only when the result is 1. The same pattern applies to refunds (order moving to refunded).
5. **[v3.1]** Redis loss is handled by G20, not by the sweeper.

### G8. Checkout and Order Lifecycle

**Covers tasks:** G4, G7, G8, K3

1. **[v3.1]** Load the order: it must belong to the caller and be pending. If remaining time is under `min_checkout_remaining` (120 s) return `409 ORDER_EXPIRING`.
2. **[v3.1]** Idempotency: if `stripe_payment_intent_id` is set, retrieve that intent from Stripe and return its client secret. Otherwise create it with the idempotency key and a stable amount:

   ```php
   $intent = $stripe->paymentIntents->create(
       ['amount' => $this->toMinorUnits($order->total_amount, $order->currency),
        'currency' => strtolower($order->currency),
        'metadata' => ['order_id' => $order->id]],
       ['idempotency_key' => "checkout-{$order->id}"]);
   $order->update(['stripe_payment_intent_id' => $intent->id]);
   ```

3. **[v3.1]** Before creating the intent, run the K3 conditional increment of `used_count` (409 if exhausted). Creating the intent locks the discount; apply-discount is refused afterwards.
4. **[v3.1]** `total_amount = 0`: skip Stripe; confirm the order in one transaction exactly as W2 does (paid, seats booked, tickets generated).
5. **[v3.1]** Cancel (`DELETE /orders/{id}`): cancel the intent outside transactions, compare-and-set pending to expired with `failure_reason` `customer_cancelled`, then release seats, GA counter, discount usage and Redis keys.
6. Payment success arrives only through the webhook. Never mark an order paid from the client.

### G9. Stripe Webhook and Expiry Sweeper

**Covers tasks:** W1-W5, D7, D8

1. Route `POST /webhooks/stripe` sits in the public group. Read the raw body, verify `Stripe-Signature`, reject invalid signatures.
2. **[v3.1]** Idempotency: insert the stripe event id into `stripe_webhook_events` (unique). A duplicate-key error means the event was already received: return 200 and skip. Set `processed_at` when handling completes.
3. **[v3.1]** Dispatch map: `payment_intent.succeeded` -> W2; `payment_intent.payment_failed` -> W4 (order stays pending); `charge.refunded` -> W3 (H6).
4. **[v3.1]** W2 inside one transaction:

   ```php
   DB::transaction(function () use ($orderId, $intent) {
       $order = Order::whereKey($orderId)->lockForUpdate()->first();
       match ($order->status) {
           OrderStatus::Pending => $this->confirmPaid($order), // seats booked, paid, tickets
           OrderStatus::Paid => null,                          // no-op
           default => $this->lateRefund($order, $intent),      // W5 (outside the txn)
       };
   });
   ```

5. **[v3.1]** W5 does not re-book. Create the Stripe refund (idempotency key `refund-{order_id}`) after the transaction, then store `failed` / `late_payment` / `stripe_refund_id`.
6. **[v3.1]** Sweeper every minute: for each pending order with `expires_at` in the past and a PaymentIntent, retrieve the intent: `succeeded` -> run W2 logic; `processing` -> skip; else cancel it (outside any transaction). Then compare-and-set the order to expired and release seats, GA, discount usage and Redis keys. A failure leaves the order pending so the next run retries.

### G10. Discount Codes

**Covers tasks:** K1-K3, G3

1. apply-discount looks the code up by (`order.event_id`, upper-cased code); a code of another event is simply not found.
2. Verify `is_active`, `valid_from` and `valid_until`, `used_count` below `max_uses` (informational check; the binding check is K3), and that no PaymentIntent exists yet.
3. **[v3.1]** Compute `discount_amount` (percentage of subtotal, or fixed value), cap it at subtotal; set `discount_code_id`, `discount_amount` and `total_amount = subtotal - discount_amount`. A new code replaces the previous one.
4. **[v3.1]** Reject a code that would leave 0 < total < the Stripe minimum for the order's currency. A result of exactly 0 is allowed (free-order path).
5. **[v3.1]** K3 at checkout (atomic): `UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ? AND (max_uses IS NULL OR used_count < max_uses)`. Revert once, behind the order compare-and-set, on expiry, failure or cancel.
6. Code creation endpoints are not in the spec: see Section 6.2, item S2.

### G11. Tickets, QR and Gatekeeper Scan

**Covers tasks:** I1-I4, I6, I7

1. **[v3.1]** Ticket generation (inside W2 / G8): one ticket per seat, or per unit for GA; `ticket_code = bin2hex(random_bytes(32))`; status active. The unique index is the final guard.
2. QR endpoint: verify the ticket belongs to the caller; QR payload is `ticket_code` only (library: Section 6.2, item S1). Nothing stored; no PDF or email.
3. **[v3.1]** Authorization: check Redis key `gk:{event}:{user}`; on a miss read `event_gatekeepers` (accepted, `revoked_at` null, event not completed), then set the key with TTL = `end_date` minus now. Remove the key on revoke, removal, completion and `end_date` change.
4. **[v3.1]** Scan flow: find the ticket by code (404), load its event, authorize (403), reject cancelled/suspended events, then the atomic transition:

   ```php
   $updated = Ticket::where('id', $ticket->id)->where('status', 'active')
       ->update(['status' => 'used', 'scanned_at' => now(), 'scanned_by' => $user->id]);
   // 1 -> 200 valid ; 0 -> 409 already used / not active
   ```

5. Redis serves authorization only; the status change always hits PostgreSQL.

### G12. Refund Workflow

**Covers tasks:** H1-H6, W3

1. **[v3.1]** Request: order belongs to caller, status paid, every ticket active, `event_date` more than 24 hours away, no open request (unique index). Whole order only.
2. **[v3.1]** Approve (owning organizer): transaction one sets `processing_refund` with reviewed fields and commits. Then, outside any transaction, call the Stripe Refund API with idempotency key `refund-{order_id}`.
3. **[v3.1]** Success (API response or `charge.refunded`, whichever arrives first) calls the idempotent H6 service: request approved, order refunded, tickets refunded, seats to available or GA `INCRBY`. Failure sets `refund_failed` and `failure_message`.
4. **[v3.1]** Retry endpoint (H5) accepts `refund_failed` only and repeats the call with the same key.
5. **[v3.1]** Amount = `orders.total_amount`. Free orders skip Stripe and finalize immediately.
6. Reject: request rejected, reviewed fields set, tickets stay active.

### G13. Scheduled Event Completion and Gatekeeper Revocation

**Covers tasks:** I5, I6, I7

1. Schedule a command every minute.
2. **[v3.1]** Select events whose `end_date` has passed and whose status is not completed; set status completed.
3. For those events set `revoked_at = now` on every `event_gatekeepers` row where `revoked_at` is null (pending invitations included, so they cannot be accepted later).
4. **[v3.1]** Delete the matching `gk:{event}:{user}` cache keys; their TTL would expire at `end_date` anyway.
5. Scans after this return 403.

### G14. Admin Moderation and Ban Policy

**Covers tasks:** J1-J9, C9

1. Approve organizer: only role organizer with `is_approved` false; set `is_approved` true and `organizer_reviewed_at`.
2. **[v3.1]** Reject organizer (J9): pending requests only, otherwise 409; set role customer, `is_approved` true, `organizer_rejection_reason`, `organizer_reviewed_at`. Re-applying (C13) clears the reason.
3. **[v3.1]** Ban: in one transaction set `is_banned`, `ban_reason`, `banned_at`; delete all personal access tokens; expire the user's pending orders and release their holds; for an organizer set all their events to suspended. Paid orders are handled manually by the admin.
4. **[v3.1]** A banned customer's tickets stay valid in the database but the QR endpoints are unreachable while banned; unban restores access.
5. Suspend event: status suspended; excluded from listings and from hold-seats.
6. Overview report: paid totals (net, per currency), active events, approved organizers, commission = sales x rate (informational).

### G15. Rate Limiters

**Covers tasks:** L1-L7

1. **[impl #47, #48]** Define named limiters with `RateLimiter::for()` in `App\Providers\RateLimitServiceProvider`; the store is the default cache store (Redis), no `throttleWithRedis()`.
2. public 120/min by IP; auth 5/min by IP (register, login) **[v3.1]** plus login 5/min by email; holds 10/min by user; scan 30/min by user; api 60/min by user; **[v3.1]** discount 10/min by user; **[v3.1]** invites 20/hour by organizer.
3. Attach throttle middleware to the matching routes; api covers the remaining authenticated routes including Account & Invitations.
4. **[impl #46, #50, #51]** Register and login share `auth`; `login-email` normalises the email inside the limiter; forgot and reset share a separate `password` limiter (5/min per IP); the email-verify link uses `public`.
5. **[impl, pending]** At L3/L4/L6/L7 decide how these routes avoid being counted again by `throttle:api`.

### G16. Cloudinary Image Handling

**Covers tasks:** P1-P3, F2

1. Frontend uploads directly to Cloudinary (unsigned preset) and sends `secure_url` and `public_id`.
2. **[v3.1]** Backend validation (form request):

   ```php
   $pattern = '#^https://res\.cloudinary\.com/' . preg_quote($cloud, '#')
       . '/image/upload/(?:v\d+/)?' . preg_quote($folder, '#')
       . '/[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)$#';
   abort_unless(preg_match($pattern, $url), 422);
   abort_unless(str_starts_with($publicId, $folder.'/') && str_contains($url, $publicId), 422);
   abort_if(Event::where('image_public_id', $publicId)->where('id', '!=', $event->id)->exists(), 422);
   ```

3. On update with a new image delete the old asset by the stored (validated) `public_id`, then save the new values.
4. **[v3.1]** P3: configure the Cloudinary preset (allowed formats, size limit, target folder); record the preset name in the project README.

### G17. Pest Tests

**Covers tasks:** N1-N13

1. Factories for users, events, tiers, seats, orders, discount codes, `event_gatekeepers`.
2. Race tests (N2, N3, N6) use concurrent processes (e.g. Laravel's Concurrency facade) against real PostgreSQL and Redis.
3. **[v3.1]** Money-path tests (N7, N8, N13) use Stripe's test mode client or an HTTP fake for the Stripe API only; databases and Redis are real. Include duplicate webhook deliveries and the sweeper-versus-webhook race.
4. **[v3.1]** Recovery drill (N9): flush Redis, run both D9 commands, compare counters and locks with PostgreSQL.
5. **[v3.1]** Security tests (N10-N12): banned flows, gatekeeper `end_date` and cache, double scan, Cloudinary tampering.
6. Policy tests: another organizer's event returns 403; unassigned or post-event scan returns 403; refund rules; discount cannot cross events.

### G18. Scramble Documentation

**Covers tasks:** O1, O2

1. Install Scramble; generate OpenAPI 3.0 from routes, form requests and resources (48 endpoints).
2. Use typed resources and backed enums so every enum value appears.
3. **[v3.1]** State machines: order (pending -> paid | expired | failed; paid -> refunded; `partially_refunded` reserved); ticket (active -> used | refunded; cancelled unused); refund request (pending -> rejected | `processing_refund` -> approved | `refund_failed` -> `processing_refund`); gatekeeper invitation (pending -> accepted | rejected; `revoked_at` independent).

### G19. Organizer Request and Gatekeeper Invitations

**Covers tasks:** C13, C14, F7-F11, J9, L7

1. **[v3.1]** Organizer request (C13): requires verified email; lock the user row; if role is not customer return 409; set role organizer, `is_approved` false, clear `organizer_rejection_reason`.
2. **[v3.1]** Invite (F7): authorize with `EventPolicy`; find the user by email (404 if none); refuse if the event is completed, cancelled or suspended; insert a pending row; catch the unique violation and return 409. Rate limit 20/hour per organizer.
3. **[v3.1]** Cancel / remove (F8): set `revoked_at` on the active row (404 if none), never delete; delete the Redis cache key.
4. List (F9): rows where `user_id` = caller, status pending, `revoked_at` null.
5. Accept (F10): caller's row, pending, not revoked, event not completed, then accepted. Reject (F11): same checks, then rejected. 403 for another user's row, 409 for a wrong state.
6. Scan access then works from the row alone, through the I7 cache.

### G20. Redis Persistence and Recovery

**[v3.1 new]** **Covers tasks:** A2, D9, N9

1. AOF `everysec` can lose about one second of writes and Redis can be flushed or evicted; PostgreSQL is the source of truth, so Redis is rebuilt from it.
2. `inventory:rebuild-ga`: for each GA tier SET `tier_capacity:{id}` to `total_capacity` minus SUM(quantity) over `order_items` of orders that are paid, or pending with `expires_at` in the future. Expired, failed and refunded orders do not count.
3. `inventory:rebuild-seat-locks`: for seats of unexpired pending orders recreate `seat_hold` keys with the remaining TTL and the order's holder token (store the token on the order or derive it from the order id).
4. Run both commands from the app entrypoint after Redis is ready, and after any Redis incident. Take care not to run them while hold traffic is mid-flight on a flushed instance; schedule a short maintenance window.

## 4. Requirement Traceability Matrix

Every requirement of the specification and the tasks that implement it.

| Requirement | Spec | Task IDs |
|---|---|---|
| Hybrid seating (seat-based + GA) | 1, 4.1, 4.3 | B3, B4, D1-D5, G1 |
| Smart adjacent seating (1 to 6) | 1, 4.2 | D3, E4 |
| Atomic all-or-nothing multi-seat hold; 10-minute TTL | 4.1 | D1, D2, D6 |
| Abuse limits: 6 seats per order, one pending order per event | 4.1 | D2, G1, B5, N6 |
| GA counter, immutable `total_capacity`, idempotent release | 4.3, 10.3 | B3, D4, D5 |
| Redis AOF and rebuild commands | 4.5, 13 | A2, D9, N9 |
| Booking window: published, before `event_date` | 4.4 | G1 |
| Idempotent checkout, 2-minute cutoff, free-order path | 4.4 | G4, G8 |
| Webhook idempotency, payment success, failure | 4.4, 13 | W1, W2, W4, B13 |
| Late payment: automatic refund; sweeper cancels intent | 4.4 | W5, D7, N7 |
| Order cancel by customer | 4.4, 11 | G7 |
| Refund workflow (whole order, five states, retry) | 5 | H1-H6, W3, B8 |
| Stripe calls outside DB transactions with idempotency keys | 4.4, 5, 13 | G4, H2, W5 |
| Gatekeeper invitation flow; 404 on unknown email | 6, 10.10 | B10, F7-F11, L7 |
| Gatekeeper access until `end_date`; cache; atomic scan | 6, 10.2 | B2, I4, I5, I6, I7 |
| Admin moderation, organizer approval and rejection | 7 | J1-J9, B1 |
| Ban: middleware, login rejection, token purge, order release | 2, 7, 13 | C3, C9, J3, N10 |
| Event-scoped discount codes; snapshot; usage counting | 8, 10.5 | B5, B9, K1-K3 |
| Secure ticket code; QR payload; first scan wins | 9 | I1, I3, I4, B7 |
| Roles: admin seeded, register always customer | 2, 10.1 | C2, B1 |
| Verified email on hold-seats and organizer-request | 2, 13 | C14 |
| Admins cannot purchase; organizers may buy own events | 2, 13 | C15, G1 |
| Permission model (role, policies, assignments) | 2 | C8, C10, C11, C12, C13 |
| Currency snapshot on orders | 10.5 | A3, B5, G2 |
| Cloudinary URL / `public_id` validation; restricted preset | 13 | P2, P3, N12 |
| Trusted proxies; rate limits (7 policies) | 12 | A6, L1-L7 |
| Sanctum expiry, purge on ban and reset | 3, 13 | C1, C7, J3 |
| Tech stack | 3 | A1, A3, C1, O1, P1 |
| Database schema | 10.1-10.12 | B1-B13 |
| Authentication endpoints (8) | 11 | C2-C7 |
| Account & Invitations endpoints (4) | 11 | C13, F9, F10, F11 |
| Admin endpoints (10) | 11 | J1-J9 (J7 covers GET and PUT) |
| Organizer endpoints (12) | 11 | F1-F8, H2, H3, H4, H5 |
| Public & customer endpoints (4) | 11 | E1-E4 |
| Booking & orders endpoints (6) | 11 | G1, G3, G4, G5, G7, H1 |
| Tickets & QR (2), gatekeeper (1), webhook (1) | 11 | I2, I3, I4, W1 |
| Pest tests, Docker, Pint, Scramble | 14 | N1-N13, A2, A4, O1, O2 |

**Total endpoints in Section 11:** 48 (8 + 4 + 10 + 12 + 4 + 6 + 2 + 1 + 1).

## 5. Definition of Done

- Every endpoint in Section 11 (48) exists, is documented in OpenAPI (Scramble) and is covered by a rate-limit policy of Section 12.
- Race-condition tests pass: same seat concurrently, overlapping multi-seat requests (no deadlock), exhausted GA capacity, double scan, sweeper-versus-webhook.
- Money-path tests pass: webhook idempotency, late payment refund, refund failure and retry, discount usage under concurrency.
- The Redis recovery drill passes (N9): after a flush the rebuild commands restore counters and locks exactly.
- Banned-user suite passes: login rejected, tokens deleted, protected routes blocked, exempt routes and webhooks still work.
- Code passes Laravel Pint (PSR-12) and Larastan level 6 (`composer analyse`) **[impl #38]**; `docker-compose.yml` starts the app, scheduler, PostgreSQL 17, Redis 7 (AOF on) and Mailpit.
- Trusted proxies configured and verified in the deployed environment.
- Any change discovered during implementation is written into the specification before it is coded (Spec v3.2 Appendix C is the register).
- **[impl #41]** Response shape: data under `data`; message-only replies use root `message`; errors use `code` + `message` or `message` + `errors`.

## 6. Open Items

### 6.1 Resolution map of the v2.1 open items

| # | Topic | Status | Where resolved | Tasks |
|---|---|---|---|---|
| 1 | Spec heading typo | Resolved (v2.1) | Spec 2 | - |
| 2 | Register roles / gatekeeper accounts | Resolved (v2.1) | Spec 2, 6 | C2, F7-F11 |
| 3 | Unapproved organizers | Resolved (v2.1) | Spec 2, 7 | C10 |
| 4 | Discount management / `used_count` timing | Partly: timing resolved; code CRUD still open | Spec 8; see S2 | K3 |
| 5 | QR library | Open | See S1 | I3 |
| 6 | Refund granularity | Resolved: whole order, seats released | Spec 5 | H1, H6 |
| 7 | GA balance bookkeeping | Resolved: immutable total, rebuild formula | Spec 4.3, 4.5, 10.3 | D4, D9 |
| 8 | Hold record storage | Resolved: pending order | Spec 4.3, 4.4 | D6 |
| 9 | Minor values | Partly: contiguous max = 6; others open | Spec 4.2; see S4 | D3 |
| 10 | Stripe event mapping | Resolved in plan (G9) | Spec 4.4, 5 | W1-W5 |
| 11 | Enum values with no trigger | Partly: failed now used; others unused | Spec 4.4; see S8 | W5 |
| 12 | Unban side effects | Open | See S5 | J4 |
| 13 | Payment after expiry | Resolved | Spec 4.4 | D7, W2, W5, G4 |
| 14 | Gate closes at `event_date` | Resolved: `end_date` | Spec 6, 10.2 | B2, I4, I5 |
| 15 | Seat hoarding | Resolved | Spec 4.1 | D2, G1, B5 |
| 16 | Tokens of banned users | Resolved | Spec 2, 7, 13 | C3, C9, J3 |
| 17 | Pricing breakdown and currency | Resolved | Spec 8, 10.5 | B5, K1, G2 |
| 18 | Stripe call discipline | Resolved | Spec 4.4, 5, 13 | G4, H2, W1 |
| 19 | Hold consistency | Resolved; (d) tolerated | Spec 4.1, 4.5 | D2, D9 |
| 20 | Organizer rejection | Resolved | Spec 7, 11 | J9 |
| 21 | `ticket_code` generation | Resolved | Spec 9 | I1 |
| 22 | Cloudinary validation | Resolved | Spec 13 | P2, P3 |
| 23 | Eligibility and operations | Resolved | Spec 2, 4.4, 6, 12, 13 | C14, C15, G1, A6, L7 |

### 6.2 Remaining spec clarifications (record in the spec before the related task starts)

These are gaps in spec v3.1, not additions to scope. Each has a recommended resolution so nothing blocks the start of development.

| # | Topic | Gap | Recommended resolution | Blocks |
|---|---|---|---|---|
| S1 | QR library | Spec requires Base64/SVG but names no library. | `bacon/bacon-qr-code` (SVG renderer, no GD/Imagick). Record in spec Section 3. | I3 |
| S2 | Discount code management | Spec Section 11 has no endpoint to create or edit codes. | Add organizer endpoints `POST/GET/PUT /organizer/events/{id}/discount-codes` (owner only), then add task K4 and update counts. Alternative: seed codes for the MVP. | K1 testing |
| S3 | Customer cancel of a pending order | Spec has no status for it (G7). | Use status `expired` with `failure_reason = customer_cancelled`; note it in spec 4.4 and 10.5. | G7 |
| S4 | Unspecified defaults | `commission_percentage` initial value; `row_label` scheme; bulk-seat limits. | Commission 10 (✅ #3); row labels A-Z then AA, AB...; at most 100 rows x 100 seats per call; contiguous finder returns up to 3 groups. | B11, F5, D3 |
| S5 | Restoring suspended events | No unsuspend endpoint; unban does not restore events. | Post-MVP endpoint; during the MVP restore manually. Record in spec 7 and 15. | J4 |
| S6 | Stripe amounts | Minor-unit conversion and minimum chargeable amount depend on the currency. | Keep both in `config/ticketing.php` per currency (partly done: USD and `0.50`, #7); record the chosen currency in spec 8. | G4, K1 |
| S7 | Event reschedule | Spec is silent on editing `event_date` / `end_date` after sales. | Reject changes to `event_date` or `end_date` (422) once any paid order exists; if `end_date` changes otherwise, clear gatekeeper cache (already in F2). | F2 |
| S8 | Unused enum values | `events.cancelled`, `tickets.cancelled`, `orders.partially_refunded` have no transition in the MVP. | Keep in schema and enums; no transition tasks. Refunds for cancelled events are post-MVP (spec 15). | - |
