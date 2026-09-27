# high-concurrency-ticketing-api — MVP Reference Specification (v2)

> This document is the **primary and complete reference for the project**. Any architectural decision, business-logic detail, or database-schema/endpoint detail lives here, and any future change must be reflected here before it's implemented in code.

---

## 1. Project Scope & MVP Goal

A high-performance RESTful API engine for managing and booking event tickets, designed to handle high-concurrency bookings with zero race conditions.

**Key technical goals:**

- **Hybrid Seating Model**: booking of specific seats (seat-based, by row and seat number) or general-admission tickets with no assigned seat (tier-based).
- **Smart Adjacent Seating**: a customer can either pick specific seats manually, or request a number of adjacent seats (1 to 5, for example) and have the system suggest them automatically.
- **High Traffic Handling**: relies on Redis atomic operations and a temporary seat lock (10 minutes) to prevent double booking.
- **API-First Ticketing**: dynamically generates a QR code (Base64/SVG) for active tickets, with no PDF generation.
- **Stripe Integration & Webhooks**: a full payment cycle with automatic booking confirmation via webhooks.
- **Gatekeeper Scanner API**: a secure interface for gate staff, restricted to the event(s) they are assigned to.
- **Refund Workflow**: a refund-request system managed by the event's organizer, with automatic financial refund on approval.
- **Admin Moderation**: full oversight capabilities (banning an organizer or customer, approving organizer accounts, managing the commission rate, platform-wide reports).
- **Event-Scoped Discount Codes**: discount codes tied to a single event, not usable on any other event.

---

## 2. User Roles & Permissions

| Role | Scope of Responsibility |
|---|---|
| **Admin** | Full platform management: approving organizer accounts, banning/unbanning any user (organizer or customer), setting the commission rate, viewing platform-wide reports, hiding any policy-violating event. |
| **Organizer** | Creates and manages only their own events (cannot control another organizer's events): sets up tiers and seats, assigns/removes gatekeepers for their events, reviews and approves/rejects refund requests for their own events. |
| **Customer** | Browses events, books specific seats, adjacent seats, or general-admission tickets, pays via Stripe, requests refunds, and generates a QR code for their tickets whenever they want. |
| **Gatekeeper** | Validates the QR code and transitions a ticket's status to `used` — **only for the event(s) they've been assigned to** by the organizer, with access automatically revoked once the event's date has passed. |

> **Important architectural note**: both the Organizer and Gatekeeper roles are constrained by an **ownership/assignment scope** — any policy/middleware in Laravel must verify that the `event_id` being acted on actually belongs to the authenticated organizer, or that the user is specifically assigned as a gatekeeper for that particular event.

---

## 3. Tech Stack (Updated to Latest Versions)

| Component | Adopted Version | Notes |
|---|---|---|
| Backend Framework | **Laravel 13** | Requires PHP 8.3 – 8.5 |
| Language Runtime | **PHP 8.5** | Latest stable release |
| Database | **PostgreSQL 17** | As chosen — supports concurrent transactions, JSONB, and full-text search for event search |
| In-Memory Cache & Locking | **Redis 7.x** | Handles atomic holds and rate limiting |
| Authentication | **Laravel Sanctum** (latest version compatible with Laravel 13) | Token-based auth with abilities |
| Payment Gateway | **Stripe API** (latest pinned version at actual implementation time, e.g. the `dahlia` release series) | Set the exact version in `stripe.api_version` at the time development actually starts, since Stripe ships a new dated version roughly every couple of months |
| Media Storage | **Cloudinary** | For event images/banners — unsigned upload directly from the frontend to reduce backend load |
| Email | **Laravel Mail + Mailpit** (dev) | Used only for email verification and password reset (no emails are sent for tickets) |
| Documentation | **OpenAPI 3.0 / Swagger via Scramble** | Better compatibility with Laravel 13 than L5-Swagger |

---

## 4. Concurrency & Hold Engine

### 4.1 Specific Seat Booking (Seat-Based)

- Every seat has its own independent `id` and is locked individually in Redis: `seat_hold:{seat_id}`, via a Lua script that performs `SET NX` with a `TTL` of 600 seconds.
- A customer can send `seat_ids: [12, 13, 14]` directly if they picked the seats manually from the seat map.

### 4.2 Adjacent Seat Suggestion (Contiguous Seats Finder)

- Dedicated endpoint: `GET /events/{id}/tiers/{tier_id}/seats/contiguous?count=N`
- Logic: fetches all `available` seats within the same tier, orders them by `row_label` then `seat_number`, and finds the first (or several) consecutive run(s) — where seat numbers differ by 1 — of length ≥ N within the same row.
- The result is returned to the customer as a suggestion (one or more groups of adjacent seats); the customer confirms their choice, and the chosen `seat_ids` are then sent to `hold-seats` using the same individual-lock logic described in 4.1 (each seat is locked separately, but the request is a single call).

### 4.3 General Admission Booking

- A tier of type `general_admission` has no rows/seats, only a `total_capacity`.
- Booking is done via a Redis atomic `DECRBY` (via a Lua script that ensures the balance never goes below zero) on the key `tier_capacity:{tier_id}`.
- A hold record is stored with an `expires_at` 10 minutes out; if time runs out without payment, the balance is restored via `INCRBY` (a scheduled job runs every minute to sweep expired holds).

### 4.4 Booking & Payment Lifecycle (same logic as the original document, applied to both cases 4.1 and 4.3)

1. Successful Redis lock → an `order` is created with status `pending`, along with `order_items` (one per seat, or one with a `quantity` for general-admission).
2. A Stripe Payment Intent is returned to the customer.
3. Seat/allocation already booked → `409 Conflict`.
4. Successful payment via webhook → seats are updated to `booked`, the order to `paid`, `tickets` are generated (one ticket per seat/unit), and the Redis keys are removed.
5. Timeout without payment → the Redis lock is released, the order → `expired`, seats revert to `available`.

> **Important constraint**: all `order_items` within a single order must belong to the same `event_id` (an order spanning seats from two different events is not supported).

---

## 5. Refund Workflow

- Any customer can request a refund as long as:
  - The ticket status is `active` (not yet scanned/used).
  - There is more than one day remaining before the event's `event_date`.
- The **Organizer** (the event owner) — not the Admin — approves or rejects the request.
- On approval → an **automatic** financial refund via the Stripe Refund API, and the ticket/order status is updated to `refunded`.
- On rejection → the ticket remains as it was (`active`).

**States**: `pending` → `approved` (auto-refund) | `rejected`

---

## 6. Gatekeeper — Assignment and Time-Bound Access

- The **Organizer** assigns gatekeepers to their events (not the Admin).
- Each assignment is tied to a specific event (`event_gatekeepers` table), so a gatekeeper cannot scan tickets for an event they aren't assigned to.
- Once the event's `event_date` has passed, the event automatically transitions (via a scheduled job) to `completed` status, and all gatekeepers assigned to it automatically have their access revoked (`revoked_at` is set).
- Any scan attempt after that returns `403 Forbidden`.

---

## 7. Admin Moderation

Admin permissions are expanded to include:

- Approving/rejecting new organizer accounts (`is_approved`).
- Fully banning/blacklisting any **Organizer** — preventing them from creating new events, with their existing events moved to `suspended` status (hidden from public listing, no new bookings possible).
- Banning any **Customer** — preventing them from logging in or making new bookings (existing tickets remain valid; handling of their prior orders is decided manually by the admin on a case-by-case basis).
- Setting the platform-wide commission rate (`platform_settings`).
- Comprehensive reports: total sales, number of active events, number of approved organizers, etc.

> **Note**: in the MVP, commission is calculated and shown in reports only (informational) — there is no actual payment split between the platform and the organizer via Stripe Connect; that's a post-MVP roadmap item.

---

## 8. Discount Codes

- Each discount code is tied to a single event (`event_id`) and cannot be applied to any other event.
- Fields include: discount type (percentage or fixed amount), maximum number of uses, validity period.
- When a code is applied to an order, the system verifies that all `order_items` in that order belong to the same `event_id` as the code.

---

## 9. Ticket Sharing

- A ticket is simply a QR code, and the account owner can request/view it at any time.
- There is no formal "ownership transfer" system — the customer is free to hand the QR code to anyone they choose (at their own full responsibility). The first successful scan of a ticket transitions its status to `used`, regardless of who is physically holding the phone at that moment.

---

## 10. Full Database Schema

### 10.1 `users`
| Field | Type |
|---|---|
| id | PK, BigInt |
| name | String |
| email | String, Unique |
| password | String |
| role | Enum: admin, organizer, customer, gatekeeper |
| is_approved | Boolean (default: true for customer/admin, false for organizer until approved by admin) |
| is_banned | Boolean, default false |
| ban_reason | Text, Nullable |
| banned_at | DateTime, Nullable |
| email_verified_at | DateTime, Nullable |
| created_at, updated_at | Timestamps |

### 10.2 `events`
| Field | Type |
|---|---|
| id | PK, BigInt |
| organizer_id | FK → users.id |
| title | String |
| description | Text |
| venue_name | String |
| location | String (for search/filtering) |
| event_date | DateTime |
| image_url | String, Nullable (Cloudinary secure_url) |
| image_public_id | String, Nullable (to delete the image from Cloudinary on update) |
| status | Enum: draft, published, completed, cancelled, suspended |
| created_at, updated_at | Timestamps |

### 10.3 `ticket_tiers`
| Field | Type |
|---|---|
| id | PK, BigInt |
| event_id | FK → events.id |
| name | String (VIP, Gold, Regular...) |
| type | Enum: seated, general_admission |
| price | Decimal(10,2) |
| total_capacity | Integer (represents the available balance if `general_admission`, or the number of generated seats if `seated`) |
| created_at, updated_at | Timestamps |

### 10.4 `seats`
| Field | Type |
|---|---|
| id | PK, BigInt |
| ticket_tier_id | FK → ticket_tiers.id |
| row_label | String (e.g. "A") |
| seat_number | Integer (e.g. 1, 2, 3...) |
| status | Enum: available, held, booked |
| created_at, updated_at | Timestamps |

Unique index: (`ticket_tier_id`, `row_label`, `seat_number`)

### 10.5 `orders`
| Field | Type |
|---|---|
| id | PK, BigInt |
| customer_id | FK → users.id |
| event_id | FK → events.id (ensures the whole order belongs to a single event) |
| total_amount | Decimal(10,2) |
| status | Enum: pending, paid, expired, failed, refunded, partially_refunded |
| stripe_payment_intent_id | String, Nullable |
| expires_at | DateTime |
| created_at, updated_at | Timestamps |

### 10.6 `order_items`
| Field | Type |
|---|---|
| id | PK, BigInt |
| order_id | FK → orders.id |
| ticket_tier_id | FK → ticket_tiers.id |
| seat_id | FK → seats.id, Nullable (null when the tier is general_admission) |
| quantity | Integer, default 1 (used only for general_admission) |
| unit_price | Decimal(10,2) |
| created_at, updated_at | Timestamps |

### 10.7 `tickets`
| Field | Type |
|---|---|
| id | PK, BigInt |
| order_item_id | FK → order_items.id |
| ticket_code | String, Unique Hash |
| status | Enum: active, used, cancelled, refunded |
| scanned_at | DateTime, Nullable |
| scanned_by | FK → users.id, Nullable |
| created_at, updated_at | Timestamps |

### 10.8 `refund_requests`
| Field | Type |
|---|---|
| id | PK, BigInt |
| order_id | FK → orders.id |
| customer_id | FK → users.id |
| status | Enum: pending, approved, rejected |
| reason | Text, Nullable |
| reviewed_by | FK → users.id, Nullable (the Organizer) |
| reviewed_at | DateTime, Nullable |
| stripe_refund_id | String, Nullable |
| created_at, updated_at | Timestamps |

### 10.9 `discount_codes`
| Field | Type |
|---|---|
| id | PK, BigInt |
| event_id | FK → events.id |
| code | String |
| discount_type | Enum: percentage, fixed_amount |
| value | Decimal(10,2) |
| max_uses | Integer, Nullable |
| used_count | Integer, default 0 |
| valid_from | DateTime, Nullable |
| valid_until | DateTime, Nullable |
| is_active | Boolean, default true |
| created_at, updated_at | Timestamps |

Unique index: (`event_id`, `code`)

### 10.10 `event_gatekeepers`
| Field | Type |
|---|---|
| id | PK, BigInt |
| event_id | FK → events.id |
| user_id | FK → users.id |
| assigned_by | FK → users.id (the Organizer) |
| assigned_at | DateTime |
| revoked_at | DateTime, Nullable (set automatically once the event ends) |
| created_at, updated_at | Timestamps |

### 10.11 `platform_settings`
| Field | Type |
|---|---|
| id | PK, BigInt |
| key | String, Unique (e.g. `commission_percentage`) |
| value | String |
| updated_at | Timestamp |

---

## 11. Full API Endpoints Directory

### Authentication
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/auth/register` | Register a new account |
| POST | `/api/v1/auth/login` | Log in and return a bearer token |
| POST | `/api/v1/auth/logout` | Revoke the current token |
| GET | `/api/v1/auth/me` | Fetch the authenticated user's data |
| POST | `/api/v1/auth/email/verification-notification` | Resend the email-verification link |
| GET | `/api/v1/auth/email/verify/{id}/{hash}` | Verify the email |
| POST | `/api/v1/auth/forgot-password` | Send a password-reset link |
| POST | `/api/v1/auth/reset-password` | Perform the password reset |

### Admin
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/admin/organizers` | List organizers (filterable by approval status) |
| POST | `/api/v1/admin/organizers/{id}/approve` | Approve an organizer account |
| POST | `/api/v1/admin/users/{id}/ban` | Ban a user (organizer/customer) |
| POST | `/api/v1/admin/users/{id}/unban` | Lift a ban |
| GET | `/api/v1/admin/events` | View all events on the platform |
| POST | `/api/v1/admin/events/{id}/suspend` | Hide a policy-violating event |
| GET | `/api/v1/admin/settings` | View platform settings (commission rate) |
| PUT | `/api/v1/admin/settings` | Update the commission rate |
| GET | `/api/v1/admin/reports/overview` | Comprehensive platform report |

### Organizer
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/organizer/events` | Create a new event |
| PUT | `/api/v1/organizer/events/{event_id}` | Update an event (including uploading a Cloudinary image) |
| POST | `/api/v1/organizer/events/{event_id}/publish` | Publish the event |
| POST | `/api/v1/organizer/events/{event_id}/tiers` | Add a ticket tier (seated/general_admission) |
| POST | `/api/v1/organizer/tiers/{tier_id}/seats/bulk` | Bulk-generate seats (input: number of rows + seats per row) |
| GET | `/api/v1/organizer/events/{event_id}/analytics` | Sales and occupancy report |
| GET | `/api/v1/organizer/events/{event_id}/refund-requests` | Refund requests for this event |
| POST | `/api/v1/organizer/refund-requests/{id}/approve` | Approve a refund request (automatic refund) |
| POST | `/api/v1/organizer/refund-requests/{id}/reject` | Reject a refund request |
| POST | `/api/v1/organizer/events/{event_id}/gatekeepers` | Assign a gatekeeper to the event |
| DELETE | `/api/v1/organizer/events/{event_id}/gatekeepers/{user_id}` | Remove a gatekeeper assignment |

### Public & Customer
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/events` | List events + search/filter (name, location, organizer) |
| GET | `/api/v1/events/{id}` | Event details with ticket tiers |
| GET | `/api/v1/events/{id}/seats` | Seat map and statuses |
| GET | `/api/v1/events/{id}/tiers/{tier_id}/seats/contiguous?count=N` | Suggest N adjacent seats |

### Booking & Orders
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/orders/hold-seats` | Temporary hold (specific seats via `seat_ids[]`, or GA via `tier_id` + `quantity`) |
| POST | `/api/v1/orders/{order_id}/apply-discount` | Apply a discount code to the order |
| POST | `/api/v1/orders/{order_id}/checkout` | Create a Stripe Payment Intent |
| GET | `/api/v1/orders/my-orders` | The user's orders |
| POST | `/api/v1/orders/{order_id}/refund-request` | Request a refund |

### Tickets & QR
| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/tickets/my-tickets` | The customer's list of tickets |
| GET | `/api/v1/tickets/{ticket_id}/qr` | Generate the QR code (Base64/SVG) |

### Gatekeeper
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/gatekeeper/scan-ticket` | Decode and confirm the QR code, transitioning the ticket to `used` (restricted to the assigned event only) |

### Webhooks
| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/v1/webhooks/stripe` | Handle Stripe events (payment + refund) |

---

## 12. Rate Limiting Policies (via Redis + Laravel Throttle)

| Scope | Limit | Reason |
|---|---|---|
| Public GET (event listing/details) | 120 requests/minute per IP | Public data, naturally subject to heavier traffic |
| Login / Register | 5 requests/minute per IP | Prevents brute-force attacks |
| Booking (`hold-seats`) | 10 requests/minute per authenticated user | Protects against bots during high-demand booking moments without inconveniencing a real user retrying 2–3 times |
| Gatekeeper (`scan-ticket`) | 30 requests/minute per user | Allows fast scanning at the gate during busy periods |
| Remaining authenticated API | 60 requests/minute per user | A reasonable general-purpose limit |

---

## 13. Quality Standards & DevOps

- **Automated Testing**: Feature and unit tests with Pest PHP to test race conditions (booking the same seat from multiple concurrent requests, exhausting GA capacity, etc.).
- **Docker Support**: a `docker-compose.yml` linking the Laravel 13 app + PostgreSQL 17 + Redis 7 + Mailpit (for emails in the development environment).
- **Code Style**: PSR-12 + Laravel Pint.
- **API Documentation**: OpenAPI 3.0 via Scramble, documenting all enums and state machines (order/ticket/refund states).

---

## 14. Open Decisions & Notes (For the Post-MVP Phase)

- **Stripe Connect**: to enable an actual payment split between the platform and the organizer, rather than the commission being purely informational.
- **Banning a Customer and its effect on their prior orders**: currently handled manually by the admin case by case; this could later become an automatic policy (e.g., automatically refunding all active orders upon a ban).
- **In-app / Push Notifications**: not part of the current MVP (email is limited to verification + password reset only).
- **Formal Ticket-Ownership Transfer**: if you later need to track who actually used the ticket (not just the account owner), a `transferred_to` field could be added, but it is not required for the MVP.
