# High-Concurrency Ticketing API - MVP Reference Specification v3.2

**high-concurrency-ticketing-api**
**MVP Reference Specification - Version 3.2 (v3.1 + implementation decisions)**

This document is the primary and complete reference for the project. Any architectural decision, business-logic detail, or database-schema/endpoint detail lives here, and any future change must be reflected here before it is implemented in code.

> **[v3.2] Version note.** v3.2 = v3.1 + the implementation decisions taken during the Auth / Access / Rate-limiting phases (tagged **[impl #N]**, full list in Appendix C; N is the number in `PROJECT_DECISIONS_LOG_v1`).
>
> **Version note (v3.1).** v3.1 = v2.1 + the agreed fixes (global ban middleware, order/discount/currency snapshot, checkout idempotency, refund execution outside transactions, atomic seat locking, GA counter recovery, organizer rejection, secure ticket codes, Cloudinary validation, operational policies) + the architect's resolutions of the issues that remained after those fixes. Unlike the draft v3.0, this version is self-contained: nothing is described as 'changes only', and every section of v2.1 (including contiguous-seat suggestion, ticket sharing, open decisions) is carried forward. Every changed or added passage is tagged **[v3.1]**.

## Contents

1. Scope
2. Roles & Permissions
3. Tech Stack
4. Concurrency & Hold Engine
5. Refund Workflow
6. Gatekeeper
7. Admin Moderation & Ban Policy
8. Discount Codes
9. Tickets
10. Database Schema
11. API Endpoints
12. Rate Limiting
13. Security Controls
14. Quality & DevOps
15. Open Decisions

- Appendix A - Change Log
- Appendix B - Architect Decisions

---

## 1. Project Scope & MVP Goal

A high-performance RESTful API engine for managing and booking event tickets, designed to handle high-concurrency bookings with zero race conditions.

- **Hybrid Seating Model:** specific seats (seat-based, by row and seat number) or general-admission tickets with no assigned seat (tier-based).
- **Smart Adjacent Seating:** the customer picks seats manually, or requests N adjacent seats (1 to 6) and the system suggests contiguous runs.
- **High Traffic Handling:** Redis atomic operations (Lua scripts) and a temporary 10-minute hold prevent double booking.
- **API-First Ticketing:** dynamically generated QR code (Base64/SVG). No PDF generation.
- **Stripe Integration & Webhooks:** full payment cycle with automatic, idempotent booking confirmation.
- **Gatekeeper Scanner API:** restricted to the events a user is assigned to; authorization cached in Redis.
- **Refund Workflow:** organizer-managed requests with automatic Stripe refund on approval.
- **Admin Moderation:** banning (with token revocation), organizer approval/rejection, commission rate, reports.
- **Event-Scoped Discount Codes:** a code is valid for exactly one event.

## 2. User Roles & Permissions

| Role           | Scope of responsibility                                                                                                                                                                                                                                                                                                                                      |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Admin**      | Full platform management: approving and rejecting organizer requests, banning/unbanning any user, setting the commission rate, platform-wide reports, hiding policy-violating events. Admin accounts are created by a seeder only. **[v3.1]** Admins cannot purchase tickets directly (`hold-seats` returns 403); they must use a separate customer account. |
| **Organizer**  | A customer whose organizer request the Admin approved (one-time, account-level). Keeps every customer ability, including buying tickets for their own events **[v3.1]**. Creates and manages only their own events: tiers, seats, gatekeeper invitations, refund requests for their events.                                                                  |
| **Customer**   | The default role: every new account starts as a customer; any role sent at registration is ignored. Browses, books, pays, requests refunds, generates QR codes, may request organizer status. **[v3.1]** A verified email is required for `hold-seats` and `organizer-request`.                                                                              |
| **Gatekeeper** | Not a standalone account type. Any registered user who accepted an invitation from an event's organizer. Validates QR codes only for events where they hold an accepted, non-revoked assignment, until the event's `end_date`.                                                                                                                               |

**Permission model.** One account can be customer, organizer and gatekeeper at once, so permissions are evaluated server-side on every request from three sources: the user's role (+ `is_approved` for organizers), Laravel policies (event ownership), and the `event_gatekeepers` table. Sanctum token abilities are not used for permissions. Customer routes are available to customer and organizer users (including an organizer whose `is_approved` is still false); organizer routes require `role = organizer` AND `is_approved = true`; `scan-ticket` requires no role, only an accepted, non-revoked assignment for the ticket's event.

**[v3.1] Banned users - global middleware.** A `not.banned` middleware runs on every route except: public event browsing, login/registration, password reset, the signed email-verification link **[impl #18]**, and signed Stripe webhooks. Webhooks are exempt so in-flight payments and refunds still reconcile. Because login is exempt from the middleware, the login endpoint itself rejects banned accounts (`403 ACCOUNT_BANNED`, no token issued). On ban, all active Sanctum tokens of the user are deleted immediately (see section 7 for the full ban policy).

## 3. Tech Stack

| Component         | Adopted version              | Notes                                                                                                                             |
| ----------------- | ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------- |
| Backend framework | Laravel 13                   | Requires PHP 8.3 - 8.5                                                                                                            |
| Language runtime  | PHP 8.5                      | Latest stable release                                                                                                             |
| Database          | PostgreSQL 17                | Concurrent transactions, partial unique indexes, JSONB, full-text search                                                          |
| Cache & locking   | Redis 7.x                    | **[v3.1]** AOF persistence enabled (`appendfsync everysec`). Atomic Lua holds, GA counters, gatekeeper cache, rate limiting.      |
| Authentication    | Laravel Sanctum              | Token-based. **[v3.1]** Tokens expire (default 30 days, configurable), are purged on ban, and revoked on password reset.          |
| Payment gateway   | Stripe API (pinned)          | Set `stripe.api_version` at the time development starts (e.g. the dahlia series). Webhook signature verification is mandatory.    |
| Media storage     | Cloudinary                   | **[v3.1]** Direct unsigned upload from the frontend with a restricted preset; backend validates URL and `public_id` (section 13). |
| Email             | Laravel Mail + Mailpit (dev) | Verification and password reset only; no ticket emails.                                                                           |
| Documentation     | OpenAPI 3.0 via Scramble     | Documents all enums and state machines.                                                                                           |

## 4. Concurrency & Hold Engine

**Sources of truth.** PostgreSQL is the source of truth for orders and seat status. Redis is a fast gate (locks and GA counters) that can always be rebuilt from PostgreSQL (4.5). **[v3.1]** Constants: `HOLD_TTL = 600 s`, `MAX_SEATS_PER_ORDER = 6` (also caps GA quantity), `MIN_CHECKOUT_REMAINING = 120 s`.

### 4.1 Specific Seat Booking (Seat-Based)

- **[v3.1]** One single atomic Lua script receives the array of `seat_ids`, checks that every key `seat_hold:{seat_id}` is free, and sets them all with TTL 600 s (all-or-nothing). This replaces per-seat `SET NX` calls, which could leave a partial lock if one seat was taken. Script keys must share a hash tag if Redis Cluster is ever used.
- **[v3.1]** Database reservation: inside one transaction run a single `UPDATE seats SET status = "held" WHERE id IN (sorted ids) AND status = "available"`; the affected-row count must equal the number of requested seats, otherwise roll back and return 409. Sorting ids gives a consistent lock order and avoids deadlocks.
- **[v3.1]** Distributed lock safety: release the Redis keys inside a `finally` block only if the transaction did not commit (use a `$committed` flag). An unconditional release in `finally` would destroy a successful hold.
- **[v3.1]** Abuse prevention: a user may have one pending order per event, enforced by a partial unique index on `orders (customer_id, event_id) WHERE status = 'pending'`. A second hold on the same event returns 409 with the existing order id; the customer continues or cancels it (`DELETE /orders/{id}`). Hold requests are also rate-limited (section 12).
- **[v3.1]** Sweeper latency: the Redis TTL may expire up to one minute before the sweeper frees the DB seat rows. This delay is accepted: a competing request may receive a temporary 409 Conflict, which keeps the design simple.

### 4.2 Adjacent Seat Suggestion (Contiguous Seats Finder)

- Endpoint `GET /events/{id}/tiers/{tier_id}/seats/contiguous?count=N` (N from 1 to `MAX_SEATS_PER_ORDER`). Fetches available seats of the tier ordered by `row_label` then `seat_number` and returns the first run(s) of consecutive seat numbers (difference of 1) of length N or more within one row.
- The result is only a suggestion. The customer confirms and the chosen `seat_ids` go to `hold-seats` through the atomic path in 4.1. **[v3.1]** (Restored: this section was missing from the draft v3.0.)

### 4.3 General Admission Booking

- A `general_admission` tier has no seats. **[v3.1]** `ticket_tiers.total_capacity` is always the immutable total capacity (never a running balance); availability lives in the Redis counter `tier_capacity:{tier_id}`.
- Booking uses a Lua script performing `DECRBY` that refuses to go below zero. The pending order (with `expires_at`) is the hold record.
- **[v3.1]** Idempotent release: the sweeper (every minute) runs `UPDATE orders SET status = "expired" WHERE id = ? AND status = "pending"` and calls `INCRBY` only when the affected-row count is 1, so a counter is never restored twice.

### 4.4 Booking & Payment Lifecycle

1. **Hold:** successful Redis lock and DB reservation create an order (`pending`, `expires_at = now + 10 min`) with `order_items` (one per seat, or one with quantity for GA). All items must belong to the same `event_id`.
2. **Booking window:** **[v3.1]** holds are accepted only for published events and only while current time is before `event_date` (event start).
3. **Checkout** (`POST /orders/{id}/checkout`): **[v3.1]** rejected with `409 ORDER_EXPIRING` when less than 2 minutes of the hold remain. Idempotent: if the order already has an active PaymentIntent, the same intent is retrieved and returned. New intents are created with a Stripe idempotency key `checkout-{order_id}` and an amount equal to `total_amount` converted to the currency's smallest unit. Creating the intent locks the discount permanently.
4. **Free orders:** **[v3.1]** if `total_amount` is 0 (100 percent discount), no PaymentIntent is created; checkout confirms the order directly (`paid`, tickets generated).
5. **Payment success** (webhook `payment_intent.succeeded`): **[v3.1]** handled idempotently via the `stripe_webhook_events` table (unique `stripe_event_id`). In one transaction: seats to `booked`, order to `paid`, tickets generated (one per seat/unit), Redis keys removed. Seat/allocation already booked by someone else returns 409.
6. **Late webhook** (order already expired): **[v3.1]** the system does not re-book. It issues an automatic Stripe refund (idempotency key `refund-{order_id}`, outside any DB transaction) and sets the order to `failed` with `failure_reason = late_payment` and the refund id stored. This is deterministic and avoids a second inventory race.
7. **Sweeper** (every minute): **[v3.1]** for each expired pending order: if it has a PaymentIntent, retrieve its status from Stripe first. `succeeded` means process as a normal payment; `processing` means skip until resolved; otherwise cancel the intent (outside the transaction). Then mark the order `expired`, release seats (to `available`) or restore GA counters, and release the discount usage (section 8).
8. **Payment failure** (`payment_intent.payment_failed`): the order stays `pending`; the customer may retry on the same intent until expiry.
9. **Cancel:** **[v3.1]** `DELETE /orders/{id}` lets a customer cancel their own pending order (cancels the intent, releases inventory).

### 4.5 Redis Persistence & Recovery

- **[v3.1]** AOF is mandatory. Two Artisan commands rebuild Redis from PostgreSQL and run on deploy and after any Redis restart:
  - `inventory:rebuild-ga`: `available = total_capacity - SUM(quantity)` over `order_items` whose order is `paid`, or `pending` with `expires_at` in the future. Expired, failed and refunded orders do not count.
  - `inventory:rebuild-seat-locks`: recreates `seat_hold` keys for seats of unexpired pending orders, with TTL equal to the remaining time.
- AOF with `everysec` can lose up to about one second of writes, so this reconciliation is required, not optional.

## 5. Refund Workflow

- A customer may request a refund when: the order is `paid`, its tickets are `active` (not used), and more than 24 hours remain before the event's `event_date`.
- **[v3.1]** MVP refunds are whole-order. The status `partially_refunded` is reserved for post-MVP. Only one open refund request per order (partial unique index).
- The Organizer who owns the event (not the Admin) approves or rejects. Rejection leaves tickets active.
- **[v3.1]** Execution: on approval the request moves to `processing_refund` and that transaction commits. The Stripe refund is then executed outside any DB transaction with idempotency key `refund-{order_id}`. Only after a successful API response (or the `charge.refunded` webhook, whichever arrives first; handled idempotently) does the system move the request to `approved`, the order to `refunded`, tickets to `refunded`, and release inventory (seats to `available`, GA counter `INCRBY`).
- **[v3.1]** If Stripe fails, the request moves to `refund_failed` with a failure message; the organizer can retry (`POST /organizer/refund-requests/{id}/retry`), reusing the same idempotency key.
- **[v3.1]** Refund amount = `orders.total_amount` (net charged) in the order's currency snapshot. Free orders (total 0) are refunded without calling Stripe.

**States:** `pending` -> `rejected` | `processing_refund` -> `approved` | `refund_failed` -> `processing_refund` (retry).

## 6. Gatekeeper - Invitation, Assignment and Time-Bound Access

- There is no standalone gatekeeper account. The Organizer invites a registered user by email; the invitation is an `event_gatekeepers` row with `status = pending`. Invitations cannot be created for completed, cancelled or suspended events.
- **[v3.1]** If the email is not registered the endpoint returns 404 Not Found. Accepted trade-off: this reveals whether an email is registered, but only to authenticated, approved organizers; mitigated by a dedicated invitation rate limit (section 12).
- The invited user sees invitations via `GET /gatekeeper-invitations` (polling) and accepts or rejects. At most one active row (pending or accepted, `revoked_at` null) per `(event_id, user_id)`; rejected invitations can be re-issued.
- The Organizer can cancel a pending invitation or remove an accepted gatekeeper at any time; both set `revoked_at` and the row is kept as history.
- **[v3.1]** Time-bound access: a new `events.end_date` defines when access ends. The scheduled job moves an event to `completed`, and sets `revoked_at` on all its invitations/assignments (pending included), when `end_date` passes (not `event_date`). This lets late-comers be scanned while the event is running. Accepting a revoked invitation or one for a completed event is refused.
- **[v3.1]** Redis cache: an accepted assignment is cached as `gk:{event_id}:{user_id}` with TTL = `end_date` minus now; it is deleted on revoke. Scanning authorizes against Redis and falls back to PostgreSQL on a miss. The `not.banned` middleware still runs first.
- **[impl #36, #37]** Authorization check lives in `GatekeeperAccessService::canScan(userId, eventId)` (a service, not a Policy). It requires an accepted, non-revoked assignment AND the event not `completed` AND `end_date > now()`. `cancelled`/`suspended` events are rejected by a separate check in the scan flow (I4). The Redis cache (I7) is added inside this service.
- **[v3.1]** Scan (`POST /gatekeeper/scan-ticket`): the ticket is located by `ticket_code`; its event must match an authorized assignment; the event must not be cancelled or suspended. The state change is atomic: `UPDATE tickets SET status = "used", scanned_at = now(), scanned_by = ? WHERE id = ? AND status = "active"`; zero affected rows means 409 (already used or not active). Codes: **200** valid, **403** not assigned or access ended, **404** unknown code, **409** already used/not active, **422** malformed payload.

## 7. Admin Moderation & Ban Policy

- **Organizer requests:** a customer asks via `POST /account/organizer-request` (409 if already organizer/pending). This sets `role = organizer`, `is_approved = false`. Admin approves once (`is_approved = true`).
- **[v3.1] Rejection:** `POST /admin/organizers/{id}/reject` works only on pending requests (409 otherwise, so an approved organizer's events cannot be orphaned). It sets `role = customer`, `is_approved = true`, stores `organizer_rejection_reason` and `organizer_reviewed_at`, enabling re-application (re-applying clears the reason). A customer with a non-null rejection reason is therefore distinguishable from one who never applied.
- **[v3.1] Ban (any user):** sets `is_banned`, `ban_reason`, `banned_at`; deletes all Sanctum tokens; login is rejected while banned; pending orders of the user are expired and released.
- **Banned organizer:** cannot create events; existing events move to `suspended` (hidden from listings, no new bookings). Already-paid orders on those events are handled manually by the Admin.
- **[v3.1] Banned customer:** cannot log in or book. Existing tickets stay valid in the database (the Admin decides case by case whether to refund), but the QR endpoints are unreachable while the account is banned; unbanning restores access. Webhooks keep processing.
- Platform commission is informational only in the MVP (`platform_settings`); no split payment (Stripe Connect is post-MVP). Reports: total sales, active events, approved organizers, etc.

## 8. Discount Codes

- Each code belongs to one event (`event_id`); it can only be applied to orders of that event. Fields: `type` (`percentage` or `fixed_amount`), `max_uses`, validity window, `is_active`. Codes are stored upper-case; matching is case-insensitive.
- **[v3.1]** Snapshot on the order: applying a code sets `discount_code_id`, `subtotal`, `discount_amount`; `total_amount = subtotal - discount_amount` (net sum charged). `discount_amount` is capped at `subtotal`, so the total never goes negative.
- **[v3.1]** Lifecycle: applying a new code replaces the previous one; once a PaymentIntent is created the discount is locked permanently for that order. Codes that would leave a non-zero total below Stripe's minimum chargeable amount are rejected.
- **[v3.1]** Usage counting: validity is checked at apply time and again at checkout. `used_count` is incremented at checkout with `UPDATE discount_codes SET used_count = used_count + 1 WHERE id = ? AND (max_uses IS NULL OR used_count < max_uses)`; zero affected rows means the code is exhausted (409). The increment is reverted (once, guarded by order status) when the order expires, fails or is cancelled before payment.

## 9. Tickets - Generation & Sharing

- **[v3.1]** `ticket_code = bin2hex(random_bytes(32))`: a 64-character unguessable hex string with a unique database index (not derived from order data). One ticket is generated per seat or GA unit when the order becomes `paid`.
- The QR payload is the `ticket_code` only (opaque, no personal data). The account owner can request it any time through `GET /tickets/{id}/qr` (Base64/SVG).
- There is no formal ownership transfer: the customer may hand the QR to anyone, at their own responsibility. The first successful scan sets the ticket to `used` regardless of who holds the phone. (Restored from v2.1; it was empty in the draft v3.0.)

## 10. Database Schema

### 10.1 users

| Field                                    | Type / notes                                                                                                                                       |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                     | PK, BigInt                                                                                                                                         |
| `name`                                   | String                                                                                                                                             |
| `email`                                  | String, unique                                                                                                                                     |
| `password`                               | String                                                                                                                                             |
| `role`                                   | Enum: `admin`, `organizer`, `customer`, `gatekeeper`. New accounts are always `customer`. `gatekeeper` is reserved/unused. `admin` is seeded only. |
| `is_approved`                            | Boolean, default true. False while an organizer request is pending.                                                                                |
| `organizer_rejection_reason` **[v3.1]**  | Text, nullable. Set on rejection, cleared on re-application.                                                                                       |
| `organizer_reviewed_at` **[v3.1]**       | DateTime, nullable                                                                                                                                 |
| `is_banned` / `ban_reason` / `banned_at` | Boolean default false / Text nullable / DateTime nullable                                                                                          |
| `email_verified_at`                      | DateTime, nullable                                                                                                                                 |
| `created_at`, `updated_at`               | Timestamps                                                                                                                                         |

### 10.2 events

| Field                                            | Type / notes                                                                                                                                         |
| ------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`                                             | PK                                                                                                                                                   |
| `organizer_id`                                   | FK `users.id`                                                                                                                                        |
| `title`, `description`, `venue_name`, `location` | String / Text (`location` used for search)                                                                                                           |
| `event_date`                                     | DateTime (event start; bookings close here; refund window is measured against it)                                                                    |
| `end_date` **[v3.1]**                            | DateTime, NOT NULL, `CHECK end_date > event_date`. Defines completion and gatekeeper access end. Backfill existing rows with `event_date + 6 hours`. |
| `image_url` / `image_public_id`                  | String nullable (validated Cloudinary values, section 13)                                                                                            |
| `status`                                         | Enum: `draft`, `published`, `completed`, `cancelled`, `suspended`                                                                                    |
| `created_at`, `updated_at`                       | Timestamps                                                                                                                                           |

### 10.3 ticket_tiers

| Field                       | Type / notes                                                                                              |
| --------------------------- | --------------------------------------------------------------------------------------------------------- |
| `id`                        | PK                                                                                                        |
| `event_id`                  | FK `events.id`                                                                                            |
| `name`                      | String (VIP, Gold, Regular...)                                                                            |
| `type`                      | Enum: `seated`, `general_admission`                                                                       |
| `price`                     | Decimal(10,2)                                                                                             |
| `total_capacity` **[v3.1]** | Integer. Always the immutable total (number of generated seats, or GA capacity). Never a running balance. |
| `created_at`, `updated_at`  | Timestamps                                                                                                |

### 10.4 seats

| Field                      | Type / notes                        |
| -------------------------- | ----------------------------------- |
| `id`                       | PK                                  |
| `ticket_tier_id`           | FK `ticket_tiers.id`                |
| `row_label`                | String                              |
| `seat_number`              | Integer                             |
| `status`                   | Enum: `available`, `held`, `booked` |
| `created_at`, `updated_at` | Timestamps                          |

Unique index: `(ticket_tier_id, row_label, seat_number)`.

### 10.5 orders

| Field                               | Type / notes                                                                                             |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `id`                                | PK                                                                                                       |
| `customer_id`                       | FK `users.id`                                                                                            |
| `event_id`                          | FK `events.id` (whole order belongs to one event)                                                        |
| `currency` **[v3.1]**               | CHAR(3). Snapshot of the configured system currency at order creation; used for reporting and refunds.   |
| `subtotal` **[v3.1]**               | Decimal(10,2): sum of `order_items` before discount                                                      |
| `discount_amount` **[v3.1]**        | Decimal(10,2), default 0                                                                                 |
| `total_amount`                      | Decimal(10,2): net amount charged = `subtotal - discount_amount`                                         |
| `discount_code_id` **[v3.1]**       | FK `discount_codes.id`, nullable                                                                         |
| `status`                            | Enum: `pending`, `paid`, `expired`, `failed`, `refunded`, `partially_refunded` (reserved, unused in MVP) |
| `failure_reason` **[v3.1]**         | String, nullable (e.g. `late_payment`)                                                                   |
| `stripe_payment_intent_id`          | String, nullable, unique                                                                                 |
| `stripe_refund_id` **[v3.1]**       | String, nullable                                                                                         |
| `expires_at` / `paid_at` **[v3.1]** | DateTime / DateTime nullable                                                                             |
| `created_at`, `updated_at`          | Timestamps                                                                                               |

Indexes: partial unique `(customer_id, event_id) WHERE status = 'pending'`; `(status, expires_at)` for the sweeper.

### 10.6 order_items

| Field                      | Type / notes                                           |
| -------------------------- | ------------------------------------------------------ |
| `id`                       | PK                                                     |
| `order_id`                 | FK `orders.id`                                         |
| `ticket_tier_id`           | FK `ticket_tiers.id`                                   |
| `seat_id`                  | FK `seats.id`, nullable (null for `general_admission`) |
| `quantity`                 | Integer default 1 (GA only)                            |
| `unit_price`               | Decimal(10,2) snapshot                                 |
| `created_at`, `updated_at` | Timestamps                                             |

### 10.7 tickets

| Field                      | Type / notes                                         |
| -------------------------- | ---------------------------------------------------- |
| `id`                       | PK                                                   |
| `order_item_id`            | FK `order_items.id`                                  |
| `ticket_code` **[v3.1]**   | CHAR(64), unique index. `bin2hex(random_bytes(32))`. |
| `status`                   | Enum: `active`, `used`, `cancelled`, `refunded`      |
| `scanned_at`               | DateTime nullable                                    |
| `scanned_by`               | FK `users.id` nullable                               |
| `created_at`, `updated_at` | Timestamps                                           |

### 10.8 refund_requests

| Field                         | Type / notes                                                                  |
| ----------------------------- | ----------------------------------------------------------------------------- |
| `id`                          | PK                                                                            |
| `order_id`                    | FK `orders.id`                                                                |
| `customer_id`                 | FK `users.id`                                                                 |
| `status` **[v3.1]**           | Enum: `pending`, `rejected`, `processing_refund`, `approved`, `refund_failed` |
| `reason`                      | Text nullable                                                                 |
| `reviewed_by` / `reviewed_at` | FK `users.id` (the Organizer) nullable / DateTime nullable                    |
| `stripe_refund_id`            | String nullable                                                               |
| `failure_message` **[v3.1]**  | Text nullable                                                                 |
| `created_at`, `updated_at`    | Timestamps                                                                    |

Index: partial unique `(order_id) WHERE status IN ('pending', 'processing_refund', 'refund_failed')`.

### 10.9 discount_codes

| Field                        | Type / notes                         |
| ---------------------------- | ------------------------------------ |
| `id`                         | PK                                   |
| `event_id`                   | FK `events.id`                       |
| `code`                       | String (stored upper-case)           |
| `discount_type`              | Enum: `percentage`, `fixed_amount`   |
| `value`                      | Decimal(10,2)                        |
| `max_uses` / `used_count`    | Integer nullable / Integer default 0 |
| `valid_from` / `valid_until` | DateTime nullable                    |
| `is_active`                  | Boolean default true                 |
| `created_at`, `updated_at`   | Timestamps                           |

Unique index: `(event_id, code)`.

### 10.10 event_gatekeepers

| Field                      | Type / notes                                                                           |
| -------------------------- | -------------------------------------------------------------------------------------- |
| `id`                       | PK                                                                                     |
| `event_id`                 | FK `events.id`                                                                         |
| `user_id`                  | FK `users.id` (invited user)                                                           |
| `assigned_by`              | FK `users.id` (the Organizer)                                                          |
| `assigned_at`              | DateTime (set at invitation)                                                           |
| `status`                   | Enum: `pending`, `accepted`, `rejected`. Default `pending`.                            |
| `revoked_at`               | DateTime nullable (organizer cancels/removes, or automatically when `end_date` passes) |
| `created_at`, `updated_at` | Timestamps                                                                             |

Partial unique index `(event_id, user_id) WHERE revoked_at IS NULL AND status IN ('pending', 'accepted')`.

### 10.11 platform_settings

| Field        | Type / notes                                  |
| ------------ | --------------------------------------------- |
| `id`         | PK                                            |
| `key`        | String, unique (e.g. `commission_percentage`) |
| `value`      | String                                        |
| `updated_at` | Timestamp                                     |

### 10.12 stripe_webhook_events **[v3.1]**

| Field             | Type / notes                                           |
| ----------------- | ------------------------------------------------------ |
| `id`              | PK                                                     |
| `stripe_event_id` | String, unique (idempotency guard for webhook retries) |
| `type`            | String                                                 |
| `processed_at`    | DateTime nullable                                      |
| `created_at`      | Timestamp                                              |

## 11. API Endpoints Directory

### Authentication

| Method | Endpoint                                       | Description                                                                                                                                                                                                                                                                                       |
| ------ | ---------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/api/v1/auth/register`                        | Register; always created as customer, role in body ignored                                                                                                                                                                                                                                        |
| POST   | `/api/v1/auth/login`                           | Log in, return bearer token. **[v3.1]** Rejects banned accounts (403).                                                                                                                                                                                                                            |
| POST   | `/api/v1/auth/logout`                          | Revoke current token                                                                                                                                                                                                                                                                              |
| GET    | `/api/v1/auth/me`                              | Authenticated user's data                                                                                                                                                                                                                                                                         |
| POST   | `/api/v1/auth/email/verification-notification` | Resend verification link                                                                                                                                                                                                                                                                          |
| GET    | `/api/v1/auth/email/verify/{id}/{hash}`        | Verify email. **[impl #18, #19, #20, #51]** Public (no `auth:sanctum`), protected by `signed` + `throttle:public`; idempotent (`200` if already verified). The link is also sent automatically at registration **[impl #17]**; resend on a verified account returns `409 EMAIL_ALREADY_VERIFIED`. |
| POST   | `/api/v1/auth/forgot-password`                 | Send reset link                                                                                                                                                                                                                                                                                   |
| POST   | `/api/v1/auth/reset-password`                  | Reset password. **[v3.1]** Revokes existing tokens.                                                                                                                                                                                                                                               |

### Account & Invitations (any authenticated, non-banned user)

| Method | Endpoint                                     | Description                                                                                                                                                                                                                                                                                                                                                                 |
| ------ | -------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| POST   | `/api/v1/account/organizer-request`          | Ask to become organizer (customers only; 409 otherwise). **[v3.1]** Requires verified email. **[impl #42, #43]** Success: `200` + `UserResource`; any non-customer role (organizer approved/pending, admin) gets `409 ORGANIZER_REQUEST_NOT_ALLOWED`. Re-applying after rejection clears `organizer_rejection_reason` only; `organizer_reviewed_at` is kept **[impl #44]**. |
| GET    | `/api/v1/gatekeeper-invitations`             | Own pending invitations (revoked excluded)                                                                                                                                                                                                                                                                                                                                  |
| POST   | `/api/v1/gatekeeper-invitations/{id}/accept` | Accept (invited user only; refused if revoked or event completed)                                                                                                                                                                                                                                                                                                           |
| POST   | `/api/v1/gatekeeper-invitations/{id}/reject` | Reject (invited user only)                                                                                                                                                                                                                                                                                                                                                  |

### Admin

| Method    | Endpoint                                | Description                                                                     |
| --------- | --------------------------------------- | ------------------------------------------------------------------------------- |
| GET       | `/api/v1/admin/organizers`              | List organizers incl. pending requests (filter by approval status)              |
| POST      | `/api/v1/admin/organizers/{id}/approve` | Approve a pending request                                                       |
| POST      | `/api/v1/admin/organizers/{id}/reject`  | **[v3.1]** Reject a pending request; role reverts to customer; accepts a reason |
| POST      | `/api/v1/admin/users/{id}/ban`          | Ban a user (revokes tokens)                                                     |
| POST      | `/api/v1/admin/users/{id}/unban`        | Lift a ban                                                                      |
| GET       | `/api/v1/admin/events`                  | All events                                                                      |
| POST      | `/api/v1/admin/events/{id}/suspend`     | Hide a policy-violating event                                                   |
| GET / PUT | `/api/v1/admin/settings`                | View / update commission rate                                                   |
| GET       | `/api/v1/admin/reports/overview`        | Platform report                                                                 |

### Organizer (role = organizer AND is_approved = true)

| Method | Endpoint                                                    | Description                                                |
| ------ | ----------------------------------------------------------- | ---------------------------------------------------------- |
| POST   | `/api/v1/organizer/events`                                  | Create event (`end_date` required, after `event_date`)     |
| PUT    | `/api/v1/organizer/events/{event_id}`                       | Update event (Cloudinary image validated)                  |
| POST   | `/api/v1/organizer/events/{event_id}/publish`               | Publish                                                    |
| POST   | `/api/v1/organizer/events/{event_id}/tiers`                 | Add ticket tier                                            |
| POST   | `/api/v1/organizer/tiers/{tier_id}/seats/bulk`              | Bulk-generate seats (rows x seats per row)                 |
| GET    | `/api/v1/organizer/events/{event_id}/analytics`             | Sales and occupancy                                        |
| GET    | `/api/v1/organizer/events/{event_id}/refund-requests`       | Refund requests of this event                              |
| POST   | `/api/v1/organizer/refund-requests/{id}/approve`            | Approve (moves to `processing_refund`, then Stripe refund) |
| POST   | `/api/v1/organizer/refund-requests/{id}/reject`             | Reject                                                     |
| POST   | `/api/v1/organizer/refund-requests/{id}/retry`              | **[v3.1]** Retry a `refund_failed` request                 |
| POST   | `/api/v1/organizer/events/{event_id}/gatekeepers`           | Invite by email; 404 if the email is not registered        |
| DELETE | `/api/v1/organizer/events/{event_id}/gatekeepers/{user_id}` | Cancel invitation / remove gatekeeper (sets `revoked_at`)  |

### Public & Customer (customer and organizer users)

| Method | Endpoint                                                       | Description                                                      |
| ------ | -------------------------------------------------------------- | ---------------------------------------------------------------- |
| GET    | `/api/v1/events`                                               | List + search/filter (name, location, organizer); published only |
| GET    | `/api/v1/events/{id}`                                          | Event details with tiers                                         |
| GET    | `/api/v1/events/{id}/seats`                                    | Seat map and statuses                                            |
| GET    | `/api/v1/events/{id}/tiers/{tier_id}/seats/contiguous?count=N` | Suggest N adjacent seats                                         |

### Booking & Orders

| Method | Endpoint                                   | Description                                                                                          |
| ------ | ------------------------------------------ | ---------------------------------------------------------------------------------------------------- |
| POST   | `/api/v1/orders/hold-seats`                | Hold seats (`seat_ids[]`) or GA (`tier_id` + `quantity`). Verified email required; admins forbidden. |
| POST   | `/api/v1/orders/{order_id}/apply-discount` | Apply/replace code before checkout                                                                   |
| POST   | `/api/v1/orders/{order_id}/checkout`       | Idempotent: create or return the active PaymentIntent (free orders confirm directly)                 |
| DELETE | `/api/v1/orders/{order_id}`                | **[v3.1]** Cancel own pending order and release the hold                                             |
| GET    | `/api/v1/orders/my-orders`                 | User's orders                                                                                        |
| POST   | `/api/v1/orders/{order_id}/refund-request` | Request a refund                                                                                     |

### Tickets, Gatekeeper, Webhooks

| Method | Endpoint                         | Description                                                                             |
| ------ | -------------------------------- | --------------------------------------------------------------------------------------- |
| GET    | `/api/v1/tickets/my-tickets`     | Customer's tickets                                                                      |
| GET    | `/api/v1/tickets/{ticket_id}/qr` | QR code (Base64/SVG)                                                                    |
| POST   | `/api/v1/gatekeeper/scan-ticket` | Atomic scan; authorized by assignment, not role                                         |
| POST   | `/api/v1/webhooks/stripe`        | Signed Stripe events (payment, failure, refund); idempotent via `stripe_webhook_events` |

## 12. Rate Limiting Policies (Redis + Laravel Throttle)

**[v3.1]** Trusted proxies must be configured before deployment, otherwise IP-based limits see the proxy address instead of the client.

| Scope                                  | Limit                                                            | Reason                                                                                                                                                                                                                                                                      |
| -------------------------------------- | ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Public GET (listing/details)           | 120 req/min per IP                                               | Public data, heavier traffic                                                                                                                                                                                                                                                |
| Login / Register                       | 5 req/min per IP; **[v3.1]** plus 5 login attempts/min per email | Brute force; shared-IP (NAT) users are not locked out by one another's attempts. **[impl #46]** Register and login share one `auth` bucket per IP; `login-email` uses the lowercased, trimmed email (normalised inside the limiter because it runs before the FormRequest). |
| **[impl #50]** Forgot / reset password | 5 req/min per IP (one shared `password` bucket)                  | Mail flooding and token guessing; separate from `auth` so a failed login does not block a reset                                                                                                                                                                             |
| **[impl #51]** Email verification link | 120 req/min per IP (`public`)                                    | Public route outside `auth:sanctum`                                                                                                                                                                                                                                         |
| Booking (`hold-seats`)                 | 10 req/min per user                                              | Bots, without blocking human retries                                                                                                                                                                                                                                        |
| **[v3.1]** Apply discount              | 10 req/min per user                                              | Prevents guessing discount codes                                                                                                                                                                                                                                            |
| **[v3.1]** Gatekeeper invitations      | 20 req/hour per organizer                                        | Limits email enumeration through the 404 response                                                                                                                                                                                                                           |
| Gatekeeper scan                        | 30 req/min per user                                              | Fast scanning at the gate                                                                                                                                                                                                                                                   |
| Remaining authenticated API            | 60 req/min per user                                              | General purpose. **[impl #31]** Applied to the `auth:sanctum` group after `not.banned`, keyed `user:<id>` (IP fallback).                                                                                                                                                    |

**[impl #47, #48]** Limiters are defined in `App\Providers\RateLimitServiceProvider` and stored in the default cache store (Redis), without `throttleWithRedis()`. **Pending (decide at L3/L4/L6/L7):** `hold-seats`, `scan-ticket`, apply-discount and gatekeeper-invitation routes must not be counted a second time under `throttle:api` (likely `withoutMiddleware('throttle:api')`).

## 13. Security Controls

- **[v3.1] Authorization and ban:** global `not.banned` middleware (section 2); login rejects banned users; token purge on ban and on password reset; token expiry configured.
- **[v3.1] Email verification:** `hold-seats` and `organizer-request` require `email_verified_at` (enforced by the custom `verified.email` middleware on those routes, returning `403 EMAIL_NOT_VERIFIED` **[impl #40]**, not Laravel's built-in `verified`). **[impl #31]** Mandatory middleware order: `auth:sanctum` -> `not.banned` -> `verified.email` -> `role:...`; `throttle:api` comes right after `not.banned`.
- **[v3.1] Cloudinary:** the backend accepts `image_url` only if it matches `https://res.cloudinary.com/{cloud_name}/` (exact host and cloud name) and `image_public_id` starts with the designated folder prefix and matches the URL's path. Both fields are validated together, so a user cannot submit another user's `public_id` and later delete that asset on update. The unsigned upload preset on Cloudinary is restricted to allowed formats, a maximum file size and the target folder.
- **[v3.1] Stripe webhooks:** verify the signature on the raw body, reject unsigned requests, process each `stripe_event_id` once.
- **[v3.1] Ticket codes:** random, 64 hex characters, unique-indexed; the QR carries nothing else.
- **Admin purchases:** `hold-seats` rejects admin accounts with 403. Organizer self-purchase is allowed.
- **Money handling:** external Stripe calls (create intent, cancel intent, refund) never run inside a DB transaction; every create/refund call carries an idempotency key.

## 14. Quality Standards & DevOps

- **Automated testing (Pest PHP):** concurrent holds on the same seat(s); overlapping multi-seat requests (deadlock check); GA exhaustion; the one-pending-order-per-event rule; duplicate webhook delivery; late webhook after expiry; sweeper vs webhook race; refund failure and retry; double scan; banned-user login and token revocation; gatekeeper access at and after `end_date`; Cloudinary URL/`public_id` tampering.
- **[v3.1] Recovery drills:** a test that flushes Redis, runs both rebuild commands, and asserts counters and locks match PostgreSQL.
- **Docker:** `docker-compose.yml` with Laravel 13, PostgreSQL 17, Redis 7 (AOF on), Mailpit.
- **[impl #38]** **Static analysis:** Larastan v3, level 6, `composer analyse`; part of the Definition of Done together with Pint and Pest.
- **Code style:** PSR-12 via Laravel Pint. **API docs:** OpenAPI 3.0 via Scramble, documenting all enums and state machines (order, ticket, refund, invitation).

## 15. Open Decisions & Notes (Post-MVP)

- Stripe Connect for a real platform/organizer payment split.
- Automatic policy for a banned customer's prior orders (e.g. refund all active orders).
- In-app/push notifications; gatekeeper invitations stay on polling for now.
- Formal ticket-ownership transfer (a `transferred_to` field).
- **[v3.1]** Partial (per-ticket) refunds, which would also require per-refund idempotency keys instead of `refund-{order_id}`.
- **[v3.1]** Automatic refunds when an event is cancelled or suspended with paid orders.

## Appendix A - Change Log (v2.1 to v3.1)

| #   | Change                                                                                                                                                      | Sections              |
| --- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------- |
| 1   | Global `not.banned` middleware with the agreed exempt list; login itself rejects banned users; token purge on ban                                           | 2, 7, 13              |
| 2   | `orders`: currency snapshot, `subtotal`, `discount_amount`, `discount_code_id`; discount lock at PaymentIntent creation; usage counting                     | 8, 10.5               |
| 3   | Idempotent checkout; free-order path; 2-minute checkout cutoff; sweeper verifies/cancels PaymentIntent                                                      | 4.4                   |
| 4   | Stripe refunds outside DB transactions with idempotency key; new refund states `processing_refund` and `refund_failed`; retry endpoint                      | 5, 10.8, 11           |
| 5   | Single atomic Lua multi-seat lock; `UPDATE ... WHERE status = 'available'` with affected-row check; lock release only on failure                            | 4.1                   |
| 6   | One pending order per user per event (partial unique index); order cancel endpoint; `MAX_SEATS_PER_ORDER = 6`                                               | 4.1, 11               |
| 7   | GA: immutable `total_capacity`; idempotent release; AOF plus rebuild commands for counters and seat locks                                                   | 4.3, 4.5, 10.3        |
| 8   | Late webhook: automatic refund, order `failed` (no re-booking); webhook idempotency table                                                                   | 4.4, 10.12            |
| 9   | Organizer rejection endpoint (pending only) with reason columns                                                                                             | 7, 10.1, 11           |
| 10  | `ticket_code` via `random_bytes(32)`, unique 64-char hex                                                                                                    | 9, 10.7               |
| 11  | Cloudinary URL and `public_id` validation; restricted unsigned preset                                                                                       | 13                    |
| 12  | Booking window ends at `event_date`; new `events.end_date` drives completion and gatekeeper access; gatekeeper Redis cache with TTL; atomic scan            | 4.4, 6, 10.2          |
| 13  | Verified-email requirement; admin purchase block; organizer self-purchase; trusted proxies; 404 for unregistered invite email                               | 2, 6, 12, 13          |
| 14  | Restored from v2.1 / dropped in draft v3.0: contiguous-seat finder, ticket sharing policy, open decisions, full tech stack, full schema and endpoint tables | 3, 4.2, 9, 10, 11, 15 |
| 15  | Rate limits: per-email login limit, apply-discount, gatekeeper-invitation limits                                                                            | 12                    |

## Appendix B - Architect Decisions Beyond the Agreed List

These choices were made to resolve problems that remained after applying the agreed fixes. Each can be changed, but the listed dependency must be updated with it.

| Decision                                                                            | Why                                                                                         | If you change it                                                        |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Lock release in `finally` only when not committed                                   | Literal 'release in `finally`' would free a successful hold                                 | Hold flow breaks; keep the flag                                         |
| `total_capacity` is the immutable total; GA formula counts paid + unexpired pending | Avoids double-subtracting and counting expired/refunded orders                              | Rebuild command must change accordingly                                 |
| Late webhook always refunds (no re-booking)                                         | Re-booking needs a second atomic inventory path and extra checks (event state, GA counter)  | Add Lua re-claim and event checks                                       |
| Events get `end_date`; completion job and gatekeeper access use it                  | `event_date`-based revocation would cut access when the event starts                        | Revert job to `event_date` only if late entry is not wanted             |
| Booking closes at `event_date` (agreed) while gatekeeper access runs to `end_date`  | Matches your booking-window rule and supports late-comer entry                              | -                                                                       |
| Whole-order refunds; `partially_refunded` reserved                                  | Per-ticket refunds conflict with order-level idempotency key and discount math              | Needs per-refund keys and proportional amounts                          |
| Banned customer: tickets valid but QR unreachable until unban                       | Middleware blocks all authenticated routes; stated explicitly instead of left contradictory | Exempt `/tickets` routes if you want banned users to still enter events |
| Free-order path (total 0)                                                           | Stripe rejects zero-amount PaymentIntents                                                   | Disallow 100 percent discounts instead                                  |
| One pending order per event per user instead of a global limit of 2                 | Matches your earlier index-based decision and is enforced atomically by the database        | A global limit needs an atomic counter                                  |
| Dedicated rate limits for discount codes and invitations                            | Prevent code guessing and email enumeration                                                 | -                                                                       |

## Appendix C - Implementation Decisions (post-v3.1) **[v3.2]**

Numbers match the former `PROJECT_DECISIONS_LOG_v1`, which this appendix replaces as the decision register. **Next number: 56.** Where a decision deviates from v3.1 or the Execution Plan, the original is stated.

### C.1 Deviations from v3.1 / Plan

| #          | Decision                                                                                                      | Original                                  |
| ---------- | ------------------------------------------------------------------------------------------------------------- | ----------------------------------------- |
| 8          | `scheduler` is a separate docker-compose service (`schedule:work`)                                            | Plan A2: process inside the app container |
| 11         | `TRUSTED_PROXIES` read from env through `config/trustedproxy.php` (`env()` only inside `config/`)             | Plan G1: `bootstrap/app.php`              |
| 18         | Email-verification route is public + `signed` (no `auth:sanctum`) and is added to the `not.banned` exemptions | Section 2 exemption list                  |
| 37         | `canScan` also requires event not `completed` and `end_date > now()`                                          | Plan C12                                  |
| 38         | Larastan level 6 added as a development tool                                                                  | Not mentioned                             |
| 47, 50, 51 | `RateLimitServiceProvider`; `password` limiter; `throttle:public` on verify                                   | Section 12 / Plan G15                     |

### C.2 Auth & access behaviour

| #          | Decision                                                                                                                                                                                                                                                                  |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 12         | Register returns `201` without a token; email lowercased; password min 8, no confirmation.                                                                                                                                                                                |
| 14         | Wrong credentials: `422` on `email` with one fixed message. Ban is checked after the password check.                                                                                                                                                                      |
| 15         | Logout: `204`, no body, deletes the current token only.                                                                                                                                                                                                                   |
| 16         | Every `api/*` request renders JSON; no token = `401` (no redirects).                                                                                                                                                                                                      |
| 17         | Verification email is sent automatically at registration.                                                                                                                                                                                                                 |
| 19, 20     | Resend on a verified account = `409 EMAIL_ALREADY_VERIFIED`; verifying twice = `200`.                                                                                                                                                                                     |
| 21         | `forgot-password` always returns `200` with a fixed message.                                                                                                                                                                                                              |
| 22         | Reset link points to `FRONTEND_URL/reset-password?token=...&email=...`.                                                                                                                                                                                                   |
| 23, 24     | Any reset failure = `422` on `email`, one message; new password min 8, no confirmation.                                                                                                                                                                                   |
| 25, 26, 27 | One `role` middleware taking the group name; `organizer` also checks `is_approved` (role first). Codes: `403 FORBIDDEN_ROLE`, `403 ORGANIZER_NOT_APPROVED`.                                                                                                               |
| 30         | "Global" `not.banned` = the whole `auth:sanctum` group; public routes stay outside it.                                                                                                                                                                                    |
| 32         | C10 approval gate is implemented inside `EnsureUserHasRole`.                                                                                                                                                                                                              |
| 55         | Admin account comes from `AdminSeeder` using `ADMIN_EMAIL`/`ADMIN_PASSWORD` via `config/ticketing.php`; idempotent (`firstOrNew` on the lowercased email); skips with a warning when either value is missing; the account is verified, approved and unbanned.             |
| 33, 34, 35 | `EventPolicy::manage` = ownership only (no admin exception). Tiers and refund requests have no policy of their own: the Service loads the parent event and calls `Gate::authorize('manage', $event)`. A missing event is `404` (route binding) before the policy's `403`. |

### C.3 Architecture & conventions

| #          | Decision                                                                                                                                                                                                                                                                                                                                                                                                                                                      |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 13         | Thin Controller; one Service per Controller; custom exceptions that render themselves.                                                                                                                                                                                                                                                                                                                                                                        |
| 41         | Success responses carry data under `data`; message-only responses use root `message`; errors use `code` + `message` or `message` + `errors`.                                                                                                                                                                                                                                                                                                                  |
| 52         | Controllers in `app/Http/Controllers/Api/V1/<Group>/`, FormRequests in `app/Http/Requests/<Group>/`; Services, Exceptions, Middleware, Resources flat; Enums in `app/Enums`.                                                                                                                                                                                                                                                                                  |
| 53         | The `/api/v1` prefix comes from `Route::prefix('v1')` in `routes/api.php`, not from controller location.                                                                                                                                                                                                                                                                                                                                                      |
| 1-7, 9, 10 | Status defaults, `unsignedInteger` columns, `commission_percentage = 10`, no `onDelete` on FKs, no `remember_token`, `hasMany` on `event_gatekeepers`, USD with `0.50` minimum charge, webhook returns `200` for unknown event types, `stripe_webhook_events.created_at` via `useCurrent()` with no `updated_at`.                                                                                                                                             |
| 39         | `HasFactory` removed from the 9 models without a factory until N1.                                                                                                                                                                                                                                                                                                                                                                                            |
| 57         | N1 is split: a partial N1 (factories for Event, TicketTier, Seat, an organizer() state on UserFactory, HasFactory restored on those three models) is pulled forward before D2, because D2 is the first task whose tests need events, tiers and seats. The default Redis DB is flushed in the global Feature beforeEach (tests/Pest.php) behind a guard that aborts unless it is DB 10. The old per-file test helpers are left untouched until the rest of N1. |

|58 | D2 lives in App\Services\SeatHoldService::hold(int $userId, array $seatIds, Closure $reserve). The $reserve closure runs inside the single DB transaction right after the conditional UPDATE and receives the seats (tier loaded); G2 passes the pending-order creation there. The Redis holder token is user:{customer_id} (not random) so D8/D9 can derive it from an order's customer. Errors: 409 SEATS_UNAVAILABLE (Redis lock refused or UPDATE affected fewer rows) and 422 INVALID_SEAT_SELECTION (empty list, more than max_seats_per_order, unknown id, seats of different events). Duplicate ids are removed. To revisit at D7/D8: release the Redis keys before freeing the DB seats so a stale release cannot delete a newer hold of the same user. |

|59 | D3 lives in App\Services\ContiguousSeatFinder::find(int $tierId, int $count): array. It returns at most ticketing.contiguous_max_groups (3, resolves the S4 result-count item) groups; each group is the first N seats (id, row_label, seat_number) of a run of consecutive available seat numbers in one row. Rows are ordered by label length then label (A..Z, AA, AB) instead of plain string order. A count outside 1..max_seats_per_order throws 422 INVALID_SEAT_SELECTION; a tier without seats (general admission) yields no groups. |

### C.4 Testing conventions

| #      | Decision                                                                                                                      |
| ------ | ----------------------------------------------------------------------------------------------------------------------------- |
| 28     | Middleware tests register their own temporary routes inside the test.                                                         |
| 29     | `DatabaseTruncation` for `Feature`; `phpunit.xml` forces `ticketing_test`, Redis DB 10/11, plus a guard test in `tests/Unit`. |
| 48, 49 | Limiters use the default cache store; `Feature` tests run `Cache::flush()` in `beforeEach`.                                   |
| 54     | Call `app('auth')->forgetGuards()` when a test switches user between requests (Sanctum caches the user in-process).           |

### C.5 Open items carried forward (S1-S8)

S1 QR library (proposed `bacon/bacon-qr-code`, SVG) before I3; S2 discount-code CRUD endpoints before K1; S3 customer cancel of a pending order (`expired` + `failure_reason = customer_cancelled`); S4 row labels, bulk limit, contiguous finder result count; S5 unsuspend after unban (manual in MVP); S7 reject `event_date`/`end_date` changes (422) when a paid order exists; S8 enum values without transitions stay in the schema. C15 (admin purchase block) ships with G1; W1 (webhook) lives in the public group and is covered by a test proving `not.banned` does not apply.
