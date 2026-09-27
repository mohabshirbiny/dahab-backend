# Dahab — Technical Specification

## Part 4 of 4: Integrations

> **Status: partial.** Only §1 (the gold price feed) is written, by spec 005 ([`specs/005-pricing/`](../../specs/005-pricing/spec.md)). §2–§4 are placeholders until their features are specified.

Conventions: credentials for every integration live **only in the environment (`.env`)** — never in code, docs, tests, fixtures, Postman or Git. `.env.example` lists the keys with empty values.

---

## 1. Gold price feed

### 1.1 What it provides

The provider returns the Egyptian gold price as two figures:

- **`bidPrice`**: what the market pays for gold. It is the basis for what sellers get.
- **`askPrice`**: what the market sells gold for. It is the basis for what buyers pay.

Both are taken as **EGP per gram of 24K**. How they become each karat's two published prices is Part 3 §2.

### 1.2 The calls (as observed in the previous platform's code)

1. `POST {base_url}/v1/auth` with JSON `{ "username", "password" }` → JSON with `access_token`.
2. `GET {base_url}/v1/datafeed/METAL_PRICE_TYPE_EGY/xau/price` with `Authorization: Bearer <access_token>` → JSON with `bidPrice` and `askPrice`.

Nothing else is assumed about the provider's API. Any difference found in its documentation is handled as a new change, not guessed.

### 1.3 Configuration

| Environment key | Meaning |
|---|---|
| `GOLD_FEED_BASE_URL` | The provider's address (staging until production is supplied) |
| `GOLD_FEED_USERNAME` | Account name |
| `GOLD_FEED_PASSWORD` | Account password |
| `GOLD_FEED_TIMEOUT` | Seconds per HTTP call (default 10) |

When the address, username or password is empty, the feed is **not configured**. The platform then runs on manual prices only.

### 1.4 Schedule and behaviour

- The command `pricing:pull-feed` runs **every minute**, never overlapping, as the system actor.
- The access token is cached for 10 minutes. If the price call returns 401, it signs in once more and retries once.
- The reading is **refused** when:
  - a field is missing or not a number;
  - `bidPrice` ≤ 0;
  - `askPrice` < `bidPrice`.
- A good reading is written as a new `gold_price` row (`source = feed`, `recorded_by` = the system actor), but **only when the bid or ask changed**. The row takes effect at once, and any pending manual price is superseded.
- Every good reading updates `price_feed_status.last_success_at`.
- A failure writes no price. It records `last_failure_at` and a short error, which never contains a credential or a token.
- The feed counts as **down** when it is not configured, or when `last_success_at` is older than `pricefeed.stale_after_minutes` (setting, default 5). A manual price is accepted only while the feed is down. The next good reading takes over again automatically.

### 1.5 Open items

- **OI-4.1 — Provider identity.** The Technical Spec calls the provider "Evolve". The previous platform called a host under `mngm.com` (a staging address). **They are not assumed to be the same.** To be confirmed by the product owner, with the provider's API documentation.
- **OI-4.2 — Unit.** EGP per gram of 24K is inferred from the previous platform's code. It will be verified against the staging server once the product owner has put the credentials in `.env`.
- **OI-4.3 — Token lifetime.** Unknown; the 10-minute cache is a safe default.
- **OI-4.4 — Rotate the password.** The previous account's password appeared in the old code and in a working conversation. The product owner rotates it with the provider before production.

---

## 2. Rapaport matrix

Not yet specified (weekly upload, diamond suggestions; guidance only, never a price).

## 3. IGI

Not yet specified (inspection contract, certificate costs — Part 3 OI-3.2).

## 4. Egyptian Tax Authority e-invoicing

Not yet specified (automatic e-invoicing at `pay-balance`).
