# Pixelforge Studio

A Laravel + Vue + Inertia app where signed-in users generate images through
**Pixelforge**, an asynchronous, per-token-priced (and occasionally unreliable)
image-generation API. Users are charged Pixelforge's cost plus a **25% markup**,
and every generation is recorded at exactly the right cost.

> Work in progress. This README describes the target design; the
> [Status](#status) section says what exists today.

## Stack

- PHP 8.5, Laravel 13, Postgres 18
- Vue 3 + Inertia (Breeze starter), Tailwind, Vite
- Pest for tests
- Laravel Sail for local development (with Xdebug), database queue driver

## Getting started

Requirements: Docker. PHP and Composer are not needed on the host.

```bash
cp .env.example .env
```

Install dependencies without PHP on the host:

```bash
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/opt -w /opt laravelsail/php84-composer:latest composer install --ignore-platform-reqs
```

Start the stack (app, Postgres, queue worker, scheduler), then prepare the app:

```bash
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail npm install
./vendor/bin/sail npm run dev
```

The app is served at http://localhost.

### Pixelforge credentials

Create a Pixelforge account and one API key per environment, then set in `.env`:

```
PIXELFORGE_API_KEY=...
PIXELFORGE_WEBHOOK_SECRET=whsec_...
```

Each key has its own webhook URL and signing secret. For local development,
Pixelforge rejects private addresses, so expose the app through a tunnel
(Expose, ngrok or Cloudflare Tunnel) and register
`https://<tunnel-host>/webhooks/pixelforge` in the Pixelforge dashboard.

### Tests

```bash
./vendor/bin/sail test
```

### Xdebug

Step debugging is enabled through `SAIL_XDEBUG_MODE=develop,debug` in `.env`.
Start the debug listener in your IDE (port 9003) and map the project root to
`/var/www/html`. Set `SAIL_XDEBUG_MODE=off` to silence connection warnings when
no IDE is listening.

## Architecture

A normal Laravel app with a small hexagonal core, sized for the scope of the
project:

```
app/Domain/          Framework-free value objects and rules (no Illuminate imports)
app/Models/          Eloquent persistence models (thin: columns, casts, relations)
app/Application/     Use cases (submit a generation, apply a provider event, ...)   [planned]
app/Infrastructure/  Adapters: Pixelforge HTTP client, repositories                  [planned]
app/Http/            Thin controllers and form requests (Laravel as delivery layer)
```

The domain layer is plain PHP so pricing and state-transition rules unit-test
in milliseconds without booting the framework. Everything else uses Laravel
idiomatically (Eloquent, queued jobs, form requests, scheduler, HTTP client).

What lives in `app/Domain` today:

| Class | Responsibility |
| --- | --- |
| `Money` | Exact integer nanodollars; parsing, formatting, overflow-safe maths, markup |
| `Pricing\Rates` | Pixelforge's per-token prices, frozen per generation |
| `Pricing\CostCalculator` | Provider cost = tokens x rates |
| `Pricing\EstimateCalculator`, `CostEstimate` | Min..max cost shown before submitting |
| `Pricing\MarkupPolicy`, `Biller`, `Bill` | The 25% markup; provider cost and user charge together |
| `Pricing\TokenSchedule` | Pixelforge's token tables (output, reasoning ranges, text, images) |
| `Generation\GenerationStatus` | Forward-only status transitions |
| `Generation\Generation` | The aggregate: status and billing, idempotent by construction |

## Money

All money is stored as **integer nanodollars** (1 USD = 1,000,000,000). Pixelforge
reports costs with nine decimal places, so this represents every provider
amount exactly and avoids floating point entirely (see `App\Domain\Money`).

- Provider cost is recorded exactly and reconciles with Pixelforge's usage report.
- The user is charged `ceil(provider_cost * 1.25)` in nanodollars. Rounding up
  to the next nanodollar means the charge is never below the exact marked-up
  price, and the rounding error is at most one billionth of a dollar.
- Failed generations cost nothing.

### How cost is computed

Pixelforge never states a price; it reports token usage and publishes rates
(USD per 1M tokens). Rates are stored as whole nanodollars **per token**
($5.00 per 1M tokens is exactly 5,000 nano per token), so
`cost = sum(tokens * rate)` is pure integer arithmetic. A rate that would need
rounding is rejected instead of being silently rounded (`Pricing\Rates`).

Each generation keeps the rates Pixelforge froze when it was created, so a later
price change never reprices history.

### The estimate shown before submitting

Part of the cost is unknown until a generation finishes: reasoning tokens (a
published range per quality) and a few percent of the input text tokens
(`ceil(characters / 4)`, actual within 15%). The UI therefore shows a **range**,
`min..max`, computed from the same code as the real cost. The text interval is
widened outward so the actual cost can never fall outside it, which is covered
by a property test over every quality and size.

## Generation lifecycle and idempotency

`pending` (ours, not yet accepted by Pixelforge) -> `queued` -> `processing` ->
`succeeded` | `failed`.

Pixelforge delivers webhooks at least once and possibly out of order, so the
`Generation` aggregate is built to be replayed safely:

- Statuses only move forward, and `succeeded` / `failed` are final. A late
  `processing` event cannot undo `succeeded`, and a failure never becomes a
  billable success (or the reverse).
- Cost and charge are computed only on the single transition into `succeeded`.
  A duplicate event returns `false` and changes nothing, even if its payload
  differs.
- Failed generations are recorded with zero cost and zero charge.

### Database safety nets

The `generations` table refuses rows that break these rules even if application
code ever did: amounts exist only on finished generations, a `failed` row must be
charged exactly 0, money cannot be negative, and `status` is limited to the five
valid values. `provider_id` is unique, and `webhook_events.event_id` is unique so
a redelivered event cannot be recorded twice.

## Status

Done:

- [x] Sail environment (Postgres, Xdebug, queue worker, scheduler), Breeze auth, Pest
- [x] Money, rates, cost and estimate calculation, markup (unit-tested against the
      worked example in the Pixelforge docs)
- [x] Generation status rules and the billing aggregate
- [x] `generations` and `webhook_events` migrations, Eloquent `Generation` model and factory

Next: repository adapter, Pixelforge HTTP client, submission flow, signed
webhooks, UI, deployment.
