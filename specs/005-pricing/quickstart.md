# Quickstart: Pricing

Run artisan as the `dahab` DB user (`DB_USERNAME=dahab`). See `postman/README.md` for the local staff accounts.

## Setup
```bash
php artisan migrate
php artisan db:seed --class=DashboardRolesAndPermissionsSeeder   # adds the five pricing permissions
```
Leave `GOLD_FEED_*` empty locally: the feed counts as "not configured", so manual prices work.

## Scenarios
1. **Calculator**: `composer test -- --filter=PriceCalculator` — the Part 3 §3.3/§3.4 examples (restated for bid/ask) match to the piastre, and so do the added percentage, diamond, gold-with-diamond, minimum and waiver cases.
2. **First manual price** (Finance): `POST /dashboard/gold-prices/manual {bid_24k, ask_24k, reason}` → 201; `GET /gold-prices/current` shows every karat's sellers-get / buyers-pay.
3. **Large jump**: enter a price 15% higher → 202 pending. The same user confirming it → 403 `confirmer_must_differ`; the CEO confirming it → 200 and the price is current. Turn `manualprice.confirmer_must_differ` off (Finance, with a reason) and repeat with the same user → 200.
4. **Adjustments**: `PUT /karats/21/adjustments` with buy `percent −1.5` → the 21K sellers-get price changes; the history shows old → new, who and why. The COO gets 403.
5. **Settings**: Finance changes `commission.gold_pct`; the COO changes `deadline.seller_reply_hours`; each is refused for the other.
6. **Feed**: `composer test -- --filter=PriceFeed`. A fake provider's bid/ask becomes current; a failure writes nothing; a manual entry is refused while the feed is healthy.
7. **Staging check** (with the product owner's go-ahead only): put the staging credentials in `.env` and run `php artisan pricing:pull-feed` once. Check that the bid/ask are EGP per gram of 24K, then record the result in Technical Spec Part 4.
8. **Dashboard**: Gold pricing (adjustments, manual price with preview, confirm, history), Commission rates (rates and deadlines), and Karats (price column).
