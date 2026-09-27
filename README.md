# high-concurrency-ticketing-api

A high-performance RESTful API for event ticket booking, built to handle high-concurrency bookings with **zero race conditions**.

Supports a hybrid seating model (specific seats _or_ general admission), adjacent-seat auto-suggestion, Redis-based atomic seat locking, Stripe payments with webhook-driven confirmation, dynamic QR-code ticket generation, an organizer-managed refund workflow, event-scoped gatekeepers, and full admin moderation.

📄 **Full technical reference:** see [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the complete database schema, API endpoints, concurrency design, and business rules.

---

## Key Features

- **Hybrid Seating Model** — book specific seats (row + seat number) or general-admission tickets
- **Smart Adjacent Seating** — request N adjacent seats and let the system find them
- **High Concurrency Handling** — Redis atomic operations + a 10-minute seat hold to prevent double booking
- **API-First Ticketing** — dynamic QR codes (Base64/SVG), no PDFs, no ticket emails
- **Stripe Integration** — full payment cycle with webhook-driven confirmation and automatic refunds
- **Event-Scoped Gatekeepers** — gate staff access is scoped to their assigned event and auto-revoked when it ends
- **Organizer-Managed Refunds** — customers request, organizers approve/reject, refunds process automatically
- **Admin Moderation** — organizer approval, user bans, commission settings, platform-wide reports
- **Event-Scoped Discount Codes** — codes valid only within the event they belong to

## Tech Stack

| Component       | Version                      |
| --------------- | ---------------------------- |
| Framework       | Laravel 13                   |
| Language        | PHP 8.5                      |
| Database        | PostgreSQL 17                |
| Cache / Locking | Redis 7.x                    |
| Auth            | Laravel Sanctum              |
| Payments        | Stripe API                   |
| Media Storage   | Cloudinary                   |
| Email           | Laravel Mail + Mailpit (dev) |
| API Docs        | OpenAPI 3.0 via Scramble     |

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full reasoning behind each choice.

## Getting Started

```bash
git clone https://github.com/roshdyosf/high-concurrency-ticketing-api.git
cd high-concurrency-ticketing-api

composer install
cp .env.example .env
php artisan key:generate

# start the stack (App + PostgreSQL + Redis + Mailpit)
docker-compose up -d

php artisan migrate --seed
php artisan serve
```

> Update `.env` with your PostgreSQL, Redis, Stripe, and Cloudinary credentials before running migrations.

## API Documentation

Interactive OpenAPI docs are generated via Scramble and served at:

```
/docs/api
```

## Testing

```bash
php artisan test
```

Feature tests cover concurrency edge cases: simultaneous booking attempts on the same seat, general-admission capacity exhaustion under load, and hold-expiry cleanup.

## Documentation

- [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) — full reference: roles & permissions, concurrency engine, refund/gatekeeper workflows, complete database schema, and the full API endpoints directory.

## License

Specify your license here (e.g. MIT).
