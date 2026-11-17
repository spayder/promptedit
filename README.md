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
app/Application/     Use cases (submit a generation, apply a provider event, ...)
app/Infrastructure/  Adapters: Pixelforge HTTP client, Eloquent repositories
app/Http/            Thin controllers and form requests (Laravel as delivery layer)
```

The domain layer is plain PHP so pricing and state-transition rules unit-test
in milliseconds without booting the framework. Everything else uses Laravel
idiomatically (Eloquent, queued jobs, form requests, scheduler, HTTP client).

## Money

All money is stored as **integer nanodollars** (1 USD = 1,000,000,000). Pixelforge
reports costs with nine decimal places, so this represents every provider
amount exactly and avoids floating point entirely (see `App\Domain\Money`).

- Provider cost is recorded exactly and reconciles with Pixelforge's usage report.
- The user is charged `ceil(provider_cost * 1.25)` in nanodollars. Rounding up
  to the next nanodollar means the charge is never below the exact marked-up
  price, and the rounding error is at most one billionth of a dollar.
- Failed generations cost nothing.

## Status

Done:

- [x] Sail environment (Postgres, Xdebug, queue worker, scheduler), Breeze auth, Pest
- [x] `Money` value object

Next: pricing and cost calculation, generation state rules, Pixelforge client,
submission flow, signed webhooks, UI, deployment.
