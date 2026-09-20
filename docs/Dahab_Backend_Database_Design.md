# Dahab — Backend Database Design

## 1. Overview

Dahab is modeled as a gold marketplace and transaction platform, not as a traditional e-commerce/cart system.

The core business flow is:

```text
Buyer
  │
  ▼
Buy Request
  │
  ├── Seller Accept / Reject
  │
  ├── Price Lock
  │
  ├── IGI Inspection
  │
  ├── Wallet Hold
  │
  └── Settlement
       ├── Seller Payment
       └── Dahab Commission
```

The backend is API-first and consumed independently by:

```text
dahab-mobile
dahab-dashboard
```

---

# 2. Recommended Stack

```text
Laravel 12+
PHP 8.3+
MySQL 8+
Redis
Laravel Sanctum
Laravel Queue
Laravel Horizon
Laravel Notifications
OpenAPI / Swagger
Docker
```

---

# 3. Database Conventions

- Primary keys: `BIGINT UNSIGNED`
- Foreign keys: `BIGINT UNSIGNED`
- Monetary values: `DECIMAL(15,2)`
- Gold weight: `DECIMAL(12,3)` in grams
- Percentages/rates: `DECIMAL(8,4)`
- Timestamps: Laravel `timestamps()`
- Flexible provider/integration data: `JSON`
- Never use floating-point types for money or gold weight.
- Use UTC timestamps in the database.
- Add indexes to all frequently filtered foreign keys/status/date columns.
- Use soft deletes only where business history must remain while hiding the record from normal queries.

---

# 4. Entity Groups

```text
Authentication
├── users
├── roles
├── permissions
└── otp_codes

Profiles
├── buyer_profiles
└── seller_profiles

Gold
├── gold_items
├── gold_item_images
├── carats
└── stone_types

Buy Requests
├── buy_requests
├── buy_request_status_histories
└── buy_request_prices

Inspection
├── inspections
└── inspection_attachments

Wallet
├── wallets
├── wallet_transactions
└── wallet_holds

Payments
├── payments
└── payment_webhooks

Settlement
├── settlements
└── settlement_transactions

Commission
├── commission_rules
└── commissions

System
├── notifications
├── audit_logs
└── system_settings
```

---

# 5. Users

## `users`

Main application user.

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| name | VARCHAR(150) | NOT NULL |
| email | VARCHAR(255) | UNIQUE, NULL if optional |
| phone | VARCHAR(30) | UNIQUE |
| password | VARCHAR(255) | NOT NULL |
| status | ENUM | active/inactive/blocked/pending |
| email_verified_at | TIMESTAMP | NULL |
| phone_verified_at | TIMESTAMP | NULL |
| last_login_at | TIMESTAMP | NULL |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

### Notes

- Authentication is based on the User entity.
- Buyer/Seller-specific information belongs in profile tables.
- Do not duplicate buyer/seller data inside `users`.

---

# 6. Roles & Permissions

If Spatie Laravel Permission is used:

```text
roles
permissions
model_has_roles
model_has_permissions
role_has_permissions
```

Initial roles:

```text
admin
buyer
seller
inspector
finance
support
```

The system should use permissions for sensitive operations instead of checking role names throughout the codebase.

---

# 7. Buyer Profile

## `buyer_profiles`

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| user_id | BIGINT UNSIGNED | FK, UNIQUE |
| national_id | VARCHAR(100) | NULL |
| date_of_birth | DATE | NULL |
| address | TEXT | NULL |
| city | VARCHAR(100) | NULL |
| country | VARCHAR(100) | NULL |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Relationship:

```text
users 1 ─── 1 buyer_profiles
```

---

# 8. Seller Profile

## `seller_profiles`

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| user_id | BIGINT UNSIGNED | FK, UNIQUE |
| business_name | VARCHAR(255) | NOT NULL |
| commercial_registration | VARCHAR(100) | NULL |
| tax_number | VARCHAR(100) | NULL |
| address | TEXT | NULL |
| city | VARCHAR(100) | NULL |
| country | VARCHAR(100) | NULL |
| verification_status | ENUM | |
| verified_at | TIMESTAMP | NULL |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Verification status:

```text
PENDING
VERIFIED
REJECTED
SUSPENDED
```

Relationship:

```text
users 1 ─── 1 seller_profiles
```

---

# 9. Gold Catalog

## `gold_items`

Represents the seller's current gold item/listing.

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| seller_id | BIGINT UNSIGNED | FK |
| title | VARCHAR(255) | NOT NULL |
| description | TEXT | NULL |
| weight | DECIMAL(12,3) | NOT NULL |
| carat | SMALLINT UNSIGNED | NOT NULL |
| stone_type | VARCHAR(50) | NOT NULL |
| manufacturing_fee | DECIMAL(15,2) | NOT NULL |
| status | ENUM | |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Status:

```text
DRAFT
AVAILABLE
RESERVED
SOLD
INACTIVE
```

Relationship:

```text
seller_profiles 1 ─── N gold_items
```

---

# 10. Gold Item Images

## `gold_item_images`

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| gold_item_id | BIGINT UNSIGNED | FK |
| path | VARCHAR(500) | NOT NULL |
| sort_order | INT UNSIGNED | DEFAULT 0 |
| is_primary | BOOLEAN | DEFAULT false |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Relationship:

```text
gold_items 1 ─── N gold_item_images
```

---

# 11. Carats

## `carats`

Use a reference table if carat values need to be managed through the admin dashboard.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| code | VARCHAR(20) UNIQUE |
| name | VARCHAR(100) |
| is_active | BOOLEAN |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Initial values:

```text
18
21
22
24
```

---

# 12. Stone Types

## `stone_types`

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| code | VARCHAR(50) UNIQUE |
| name | VARCHAR(100) |
| is_active | BOOLEAN |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Initial values:

```text
NONE
DIAMOND
RUBY
EMERALD
OTHER
```

---

# 13. Buy Requests

## `buy_requests`

This is the central business entity.

A Buy Request represents a specific transaction between a buyer and seller.

| Column | Type | Constraints |
|---|---|---|
| id | BIGINT UNSIGNED | PK |
| buyer_id | BIGINT UNSIGNED | FK |
| seller_id | BIGINT UNSIGNED | FK |
| gold_item_id | BIGINT UNSIGNED | FK |
| weight | DECIMAL(12,3) | Snapshot |
| carat | SMALLINT UNSIGNED | Snapshot |
| stone_type | VARCHAR(50) | Snapshot |
| seller_price | DECIMAL(15,2) | Snapshot |
| commission_rate | DECIMAL(8,4) | Snapshot |
| commission_amount | DECIMAL(15,2) | Snapshot |
| buyer_price | DECIMAL(15,2) | Snapshot |
| status | VARCHAR(50) | |
| accepted_at | TIMESTAMP | NULL |
| rejected_at | TIMESTAMP | NULL |
| price_locked_at | TIMESTAMP | NULL |
| price_lock_expires_at | TIMESTAMP | NULL |
| cancelled_at | TIMESTAMP | NULL |
| completed_at | TIMESTAMP | NULL |
| created_at | TIMESTAMP | |
| updated_at | TIMESTAMP | |

Recommended indexes:

```text
INDEX buyer_id
INDEX seller_id
INDEX gold_item_id
INDEX status
INDEX created_at
INDEX price_lock_expires_at
```

### Important Snapshot Rule

`buy_requests` stores transaction-time values.

For example:

```text
gold_items.weight = 10.000
```

At the time of the request:

```text
buy_requests.weight = 10.000
```

If the seller later changes the listing to:

```text
9.500
```

the existing transaction remains:

```text
10.000
```

The same rule applies to:

```text
seller_price
commission_rate
commission_amount
buyer_price
carat
stone_type
weight
```

---

# 14. Buy Request Status

Recommended status values:

```text
PENDING
ACCEPTED
REJECTED
PRICE_LOCKED
INSPECTION_PENDING
INSPECTION_PASSED
INSPECTION_FAILED
SETTLEMENT_PENDING
COMPLETED
CANCELLED
EXPIRED
```

Recommended lifecycle:

```text
PENDING
   │
   ├── REJECTED
   │
   └── ACCEPTED
          │
          ▼
     PRICE_LOCKED
          │
          ▼
 INSPECTION_PENDING
       │       │
       │       └── INSPECTION_FAILED
       │
       ▼
 INSPECTION_PASSED
          │
          ▼
 SETTLEMENT_PENDING
          │
          ▼
      COMPLETED
```

The backend must enforce valid transitions.

Do not allow the frontend to arbitrarily set a status.

---

# 15. Buy Request Status History

## `buy_request_status_histories`

Stores every status transition.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| buy_request_id | BIGINT UNSIGNED FK |
| from_status | VARCHAR(50) NULL |
| to_status | VARCHAR(50) |
| changed_by | BIGINT UNSIGNED NULL |
| reason | TEXT NULL |
| metadata | JSON NULL |
| created_at | TIMESTAMP |

Example:

```text
PENDING
  ↓
ACCEPTED
  ↓
PRICE_LOCKED
  ↓
INSPECTION_PENDING
```

This provides a complete transaction history.

---

# 16. Buy Request Price History

## `buy_request_prices`

Use this table if the request can have multiple price changes before the final lock.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| buy_request_id | BIGINT UNSIGNED FK |
| seller_price | DECIMAL(15,2) |
| commission_rate | DECIMAL(8,4) |
| commission_amount | DECIMAL(15,2) |
| buyer_price | DECIMAL(15,2) |
| effective_from | TIMESTAMP |
| effective_until | TIMESTAMP NULL |
| created_by | BIGINT UNSIGNED NULL |
| created_at | TIMESTAMP |

The final locked price must remain immutable after locking unless an explicit business process allows a correction.

---

# 17. Inspection

## `inspections`

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| buy_request_id | BIGINT UNSIGNED FK |
| inspector_id | BIGINT UNSIGNED FK |
| status | ENUM |
| certificate_number | VARCHAR(150) NULL |
| result | TEXT NULL |
| notes | TEXT NULL |
| requested_at | TIMESTAMP |
| started_at | TIMESTAMP NULL |
| completed_at | TIMESTAMP NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Status:

```text
PENDING
IN_PROGRESS
PASSED
FAILED
CANCELLED
```

Relationship:

```text
buy_requests 1 ─── N inspections
```

If the business guarantees only one inspection per request, add:

```text
UNIQUE(buy_request_id)
```

Otherwise, keep it one-to-many to support re-inspection.

---

# 18. Inspection Attachments

## `inspection_attachments`

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| inspection_id | BIGINT UNSIGNED FK |
| file_path | VARCHAR(500) |
| file_type | VARCHAR(100) |
| original_name | VARCHAR(255) |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Examples:

```text
IGI Certificate
Inspection Report
Inspection Image
```

---

# 19. Wallets

## `wallets`

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED FK UNIQUE |
| currency | VARCHAR(10) |
| balance | DECIMAL(15,2) |
| status | ENUM |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Status:

```text
ACTIVE
FROZEN
CLOSED
```

### Important

`balance` is a cached/current balance.

The authoritative financial history is `wallet_transactions`.

---

# 20. Wallet Transactions

## `wallet_transactions`

The wallet ledger.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| wallet_id | BIGINT UNSIGNED FK |
| type | VARCHAR(50) |
| status | VARCHAR(50) |
| amount | DECIMAL(15,2) |
| balance_before | DECIMAL(15,2) |
| balance_after | DECIMAL(15,2) |
| reference_type | VARCHAR(100) NULL |
| reference_id | BIGINT UNSIGNED NULL |
| description | TEXT NULL |
| metadata | JSON NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Types:

```text
DEPOSIT
WITHDRAWAL
HOLD
RELEASE
PAYMENT
REFUND
COMMISSION
ADJUSTMENT
```

Every financial operation must create a ledger entry.

---

# 21. Wallet Holds

## `wallet_holds`

Represents money reserved for a specific Buy Request.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| wallet_id | BIGINT UNSIGNED FK |
| buy_request_id | BIGINT UNSIGNED FK |
| amount | DECIMAL(15,2) |
| status | ENUM |
| expires_at | TIMESTAMP NULL |
| released_at | TIMESTAMP NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Status:

```text
ACTIVE
RELEASED
CAPTURED
EXPIRED
CANCELLED
```

Example:

```text
Buyer Wallet
Balance = 10,000

Buy Request = 1,050

       ↓

Wallet Hold = 1,050

Available = 8,950
```

---

# 22. Payments

## `payments`

Represents money entering/leaving through a payment provider.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED FK |
| wallet_id | BIGINT UNSIGNED FK |
| provider | VARCHAR(50) |
| payment_method | VARCHAR(50) |
| amount | DECIMAL(15,2) |
| currency | VARCHAR(10) |
| status | VARCHAR(50) |
| external_id | VARCHAR(255) NULL |
| idempotency_key | VARCHAR(255) NULL |
| paid_at | TIMESTAMP NULL |
| metadata | JSON NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Recommended unique constraint:

```text
UNIQUE(provider, external_id)
```

when `external_id` exists.

---

# 23. Payment Webhooks

## `payment_webhooks`

Stores incoming provider webhook events.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| payment_id | BIGINT UNSIGNED NULL |
| provider | VARCHAR(50) |
| event_type | VARCHAR(100) |
| external_event_id | VARCHAR(255) |
| payload | JSON |
| signature | TEXT NULL |
| status | VARCHAR(50) |
| processed_at | TIMESTAMP NULL |
| error_message | TEXT NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Recommended:

```text
UNIQUE(provider, external_event_id)
```

This protects against duplicate webhook processing.

---

# 24. Settlement

## `settlements`

Settlement is the final financial distribution of a completed transaction.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| buy_request_id | BIGINT UNSIGNED FK |
| buyer_id | BIGINT UNSIGNED FK |
| seller_id | BIGINT UNSIGNED FK |
| gross_amount | DECIMAL(15,2) |
| commission_amount | DECIMAL(15,2) |
| seller_amount | DECIMAL(15,2) |
| status | VARCHAR(50) |
| processed_at | TIMESTAMP NULL |
| completed_at | TIMESTAMP NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Example:

```text
Buyer Price       1,050
Commission           50
Seller Amount     1,000
```

Settlement must verify that:

```text
gross_amount = seller_amount + commission_amount
```

---

# 25. Settlement Transactions

## `settlement_transactions`

Provides detailed financial entries for a settlement.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| settlement_id | BIGINT UNSIGNED FK |
| type | VARCHAR(50) |
| wallet_id | BIGINT UNSIGNED FK |
| amount | DECIMAL(15,2) |
| status | VARCHAR(50) |
| reference | VARCHAR(255) NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Types:

```text
BUYER_DEBIT
SELLER_CREDIT
COMMISSION
REFUND
```

---

# 26. Commission Rules

## `commission_rules`

Defines active commission policies.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| name | VARCHAR(150) |
| rate | DECIMAL(8,4) |
| min_amount | DECIMAL(15,2) NULL |
| max_amount | DECIMAL(15,2) NULL |
| is_active | BOOLEAN |
| effective_from | TIMESTAMP |
| effective_until | TIMESTAMP NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

---

# 27. Commissions

## `commissions`

Stores the actual commission generated by a transaction.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| buy_request_id | BIGINT UNSIGNED FK |
| settlement_id | BIGINT UNSIGNED FK NULL |
| rate | DECIMAL(8,4) |
| amount | DECIMAL(15,2) |
| status | VARCHAR(50) |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

The commission is a transaction snapshot.

If the commission rule changes later, existing transactions do not change.

---

# 28. OTP

## `otp_codes`

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED NULL |
| channel | VARCHAR(20) |
| destination | VARCHAR(255) |
| code_hash | VARCHAR(255) |
| purpose | VARCHAR(50) |
| expires_at | TIMESTAMP |
| verified_at | TIMESTAMP NULL |
| attempts | UNSIGNED INT |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Purposes:

```text
REGISTRATION
LOGIN
PHONE_VERIFICATION
EMAIL_VERIFICATION
PASSWORD_RESET
```

Never store the OTP itself in plaintext.

---

# 29. Audit Logs

## `audit_logs`

Tracks sensitive business actions.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| user_id | BIGINT UNSIGNED NULL |
| action | VARCHAR(100) |
| auditable_type | VARCHAR(255) |
| auditable_id | BIGINT UNSIGNED |
| old_values | JSON NULL |
| new_values | JSON NULL |
| ip_address | VARCHAR(45) NULL |
| user_agent | TEXT NULL |
| created_at | TIMESTAMP |

Examples:

```text
BUY_REQUEST_ACCEPTED
PRICE_LOCKED
INSPECTION_UPDATED
WALLET_ADJUSTED
SETTLEMENT_COMPLETED
SELLER_SUSPENDED
```

---

# 30. System Settings

## `system_settings`

For configurable business settings.

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| key | VARCHAR(150) UNIQUE |
| value | TEXT NULL |
| type | VARCHAR(30) |
| description | TEXT NULL |
| created_at | TIMESTAMP |
| updated_at | TIMESTAMP |

Examples:

```text
price_lock_duration
otp_expiration_minutes
maximum_otp_attempts
default_commission_rate
```

Do not hard-code business configuration that administrators need to change.

---

# 31. Laravel Notifications

Use Laravel's standard:

```text
notifications
```

Channels can include:

```text
database
mail
sms
push
```

Examples:

```text
Buy Request Accepted
Buy Request Rejected
Price Locked
Inspection Passed
Inspection Failed
Wallet Deposit Completed
Settlement Completed
```

---

# 32. Main Relationships

```text
users
 │
 ├────────────── buyer_profiles
 │
 ├────────────── seller_profiles
 │                    │
 │                    └──────── gold_items
 │                              │
 │                              └── gold_item_images
 │
 ├────────────── wallets
 │                    │
 │                    ├── wallet_transactions
 │                    └── wallet_holds
 │
 └────────────── payments
                       │
                       └── payment_webhooks


buyer_profiles
      │
      └──────── buy_requests ─────── seller_profiles
                       │
                       ├── gold_items
                       │
                       ├── buy_request_status_histories
                       │
                       ├── buy_request_prices
                       │
                       ├── inspections
                       │
                       └── settlements
                                  │
                                  ├── settlement_transactions
                                  └── commissions
```

---

# 33. Core Transaction Flow

## Step 1 — Buyer creates request

```text
Buyer
 ↓
POST /api/v1/buy-requests
 ↓
Validate Gold Item
 ↓
Validate Seller
 ↓
Snapshot Gold Data
 ↓
Calculate Commission
 ↓
Create Buy Request
 ↓
Create Status History
```

---

## Step 2 — Seller accepts

```text
PENDING
   ↓
Validate transition
   ↓
ACCEPTED
   ↓
Status History
   ↓
Notification
```

---

## Step 3 — Price Lock

```text
ACCEPTED
   ↓
Calculate/confirm price
   ↓
PRICE_LOCKED
   ↓
price_locked_at
price_lock_expires_at
```

The price becomes immutable after locking unless a controlled business process changes it.

---

# 34. Wallet Flow

```text
Buyer
 ↓
Payment Gateway
 ↓
Payment
 ↓
Webhook
 ↓
Verify Signature
 ↓
Check Idempotency
 ↓
Update Payment
 ↓
DB Transaction
      ├── Update Wallet Balance
      └── Create Wallet Transaction
```

---

# 35. Buy Request Financial Flow

```text
Buyer Wallet
      │
      │ HOLD
      ▼
Wallet Hold
      │
      │ Inspection Passed
      ▼
Settlement
      │
      ├───────────────┐
      ▼               ▼
Seller Wallet     Dahab Commission
```

---

# 36. Financial Safety Rules

Every financial operation must:

1. Use `DB::transaction()`.
2. Lock the affected wallet row using `SELECT ... FOR UPDATE` / Laravel `lockForUpdate()`.
3. Validate the current wallet status.
4. Validate the expected amount.
5. Create a wallet ledger entry.
6. Update the cached balance atomically.
7. Create the appropriate reference to the business transaction.
8. Be idempotent where external systems are involved.
9. Never trust monetary values sent by the frontend.
10. Never allow the frontend to directly set wallet balances.

Example:

```php
DB::transaction(function () use ($wallet, $amount) {

    $wallet = Wallet::query()
        ->lockForUpdate()
        ->findOrFail($wallet->id);

    $before = $wallet->balance;
    $after = $before + $amount;

    $wallet->update([
        'balance' => $after,
    ]);

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::DEPOSIT,
        'amount' => $amount,
        'balance_before' => $before,
        'balance_after' => $after,
    ]);
});
```

---

# 37. Idempotency

External operations must be idempotent.

Examples:

```text
Payment Gateway
Webhook
Settlement
Wallet Deposit
```

A duplicate webhook must not create a duplicate financial transaction.

Recommended unique identifiers:

```text
provider + external_id
provider + external_event_id
idempotency_key
```

---

# 38. Important Indexes

At minimum:

```text
users.email
users.phone

buyer_profiles.user_id
seller_profiles.user_id

gold_items.seller_id
gold_items.status

buy_requests.buyer_id
buy_requests.seller_id
buy_requests.gold_item_id
buy_requests.status
buy_requests.created_at
buy_requests.price_lock_expires_at

inspections.buy_request_id
inspections.status

wallets.user_id

wallet_transactions.wallet_id
wallet_transactions.reference_type
wallet_transactions.reference_id
wallet_transactions.created_at

wallet_holds.wallet_id
wallet_holds.buy_request_id
wallet_holds.status

payments.user_id
payments.wallet_id
payments.external_id
payments.idempotency_key

payment_webhooks.external_event_id

settlements.buy_request_id
settlements.status

commissions.buy_request_id
commissions.settlement_id

audit_logs.user_id
audit_logs.auditable_type
audit_logs.auditable_id
audit_logs.created_at
```

---

# 39. Foreign Key Strategy

Use foreign keys for core relationships.

Examples:

```text
buyer_profiles.user_id → users.id
seller_profiles.user_id → users.id

gold_items.seller_id → users.id

buy_requests.buyer_id → users.id
buy_requests.seller_id → users.id
buy_requests.gold_item_id → gold_items.id

inspections.buy_request_id → buy_requests.id

wallets.user_id → users.id

wallet_transactions.wallet_id → wallets.id

wallet_holds.wallet_id → wallets.id
wallet_holds.buy_request_id → buy_requests.id

payments.user_id → users.id
payments.wallet_id → wallets.id

settlements.buy_request_id → buy_requests.id
settlements.buyer_id → users.id
settlements.seller_id → users.id
```

For historical/financial data, avoid cascading deletes that could destroy transaction history.

Prefer:

```text
RESTRICT
```

or nullable foreign keys where business rules require preserving the historical record.

---

# 40. ERD — Simplified

```text
┌──────────────┐
│    users     │
└──────┬───────┘
       │
   ┌───┴───────────────┐
   │                   │
   ▼                   ▼
buyer_profiles    seller_profiles
                       │
                       ▼
                  gold_items
                       │
                       │
                       ▼
                  buy_requests
                  │    │    │
                  │    │    └──────────┐
                  │    │               │
                  │    ▼               ▼
                  │ inspections    status_history
                  │
                  ▼
              settlements
                  │
             ┌────┴────┐
             ▼         ▼
       settlement   commissions
       transactions
```

Financial side:

```text
users
  │
  ▼
wallets
  │
  ├── wallet_transactions
  │
  └── wallet_holds
          │
          ▼
      buy_requests
```

Payment side:

```text
payment_gateway
      │
      ▼
   payments
      │
      ▼
payment_webhooks
      │
      ▼
wallet_transactions
```

---

# 41. What Should NOT Be Stored Directly

Do not allow the frontend to determine:

```text
wallet.balance
commission_amount
buyer_price
seller_price after locking
settlement amounts
buy_request.status
```

The backend calculates and validates these values.

---

# 42. Recommended Laravel Enums

Create PHP Enums for business states:

```text
UserStatus
SellerVerificationStatus
GoldItemStatus
BuyRequestStatus
InspectionStatus
WalletStatus
WalletTransactionType
WalletTransactionStatus
WalletHoldStatus
PaymentStatus
SettlementStatus
CommissionStatus
```

This avoids scattered magic strings.

---

# 43. Recommended Domain Services

Core services/actions:

```text
CreateBuyRequestAction
AcceptBuyRequestAction
RejectBuyRequestAction
LockBuyRequestPriceAction
ExpireBuyRequestAction

CreateInspectionAction
CompleteInspectionAction

DepositToWalletAction
CreateWalletHoldAction
ReleaseWalletHoldAction
CaptureWalletHoldAction

CreatePaymentAction
ProcessPaymentWebhookAction

CreateSettlementAction
CompleteSettlementAction

CalculateCommissionAction
```

Controllers should call these actions/services rather than implementing the business rules themselves.

---

# 44. Recommended Events

```text
BuyRequestCreated
BuyRequestAccepted
BuyRequestRejected
PriceLocked
BuyRequestExpired

InspectionRequested
InspectionPassed
InspectionFailed

WalletDeposited
WalletHoldCreated
WalletHoldReleased
WalletHoldCaptured

PaymentCompleted
PaymentFailed

SettlementCreated
SettlementCompleted
```

---

# 45. Recommended Queued Jobs

```text
SendOtpJob
SendNotificationJob
ProcessPaymentWebhookJob
ExpirePriceLocksJob
ProcessSettlementJob
```

Long-running or external operations should not block API requests unnecessarily.

---

# 46. Recommended API Modules

```text
/api/v1/customer/auth/*
/api/v1/dashboard/auth/*
/api/v1/profile/*
/api/v1/gold-items/*
/api/v1/buy-requests/*
/api/v1/inspections/*
/api/v1/wallet/*
/api/v1/payments/*
/api/v1/settlements/*
/api/v1/notifications/*
```

Admin:

```text
/api/v1/admin/users/*
/api/v1/admin/sellers/*
/api/v1/admin/gold-items/*
/api/v1/admin/buy-requests/*
/api/v1/admin/inspections/*
/api/v1/admin/wallets/*
/api/v1/admin/payments/*
/api/v1/admin/settlements/*
/api/v1/admin/commissions/*
/api/v1/admin/reports/*
```

---

# 47. Suggested Implementation Order

## Phase 1 — Foundation

```text
Laravel
Docker
MySQL
Redis
Sanctum
API v1
Exception Handling
API Response Format
Logging
Audit
OpenAPI
```

## Phase 2 — Authentication

```text
Users
Roles
Permissions
OTP
Login
Register
Profile
```

## Phase 3 — Gold

```text
Carats
Stone Types
Gold Items
Images
Seller Catalog
```

## Phase 4 — Buy Requests

```text
Create
Accept
Reject
Price
Price Lock
Status History
Expiration
```

## Phase 5 — Inspection

```text
Inspection
Inspector
Attachments
Results
```

## Phase 6 — Wallet

```text
Wallet
Ledger
Hold
Release
Capture
```

## Phase 7 — Payments

```text
Payment Gateway
Payments
Webhooks
Idempotency
Reconciliation
```

## Phase 8 — Settlement

```text
Settlement
Seller Credit
Buyer Debit
Commission
Refund
```

## Phase 9 — Notifications & Admin

```text
Notifications
Dashboard APIs
Reports
Audit
System Settings
```

---

# 48. Critical Business Decisions To Confirm Before Migrations

The following decisions should be finalized before implementation:

1. Is a `Gold Item` allowed to receive multiple active Buy Requests?
2. Is an Inspection one-time or can a request be inspected multiple times?
3. Can the Seller change the price multiple times before the final lock?
4. Exactly how long does the price lock remain valid?
5. Does the Buyer have to fund the wallet before creating a request or only before settlement?
6. Is the Wallet allowed to go negative?
7. What happens to a Wallet Hold when inspection fails?
8. Can a completed settlement be reversed/refunded?
9. Is the commission percentage global or different per seller/category?
10. Will Dahab support multiple currencies in the future?
11. Will a Seller be able to have multiple business branches?
12. What payment gateway(s) will be supported initially?
13. Is IGI an external integration or manually managed by Dahab staff?
14. What exact KYC data is required for Buyer/Seller?
15. Can one user have both Buyer and Seller capabilities?

These are business rules, not just database decisions. They should be settled before building the final migrations.

---

# 49. Recommended MVP Database

For the first implementation, the minimum production foundation is:

```text
users
roles / permissions
buyer_profiles
seller_profiles

carats
stone_types
gold_items
gold_item_images

buy_requests
buy_request_status_histories
buy_request_prices

inspections
inspection_attachments

wallets
wallet_transactions
wallet_holds

payments
payment_webhooks

settlements
settlement_transactions

commission_rules
commissions

otp_codes
notifications
audit_logs
system_settings
```

This design intentionally avoids an `orders`/`cart` architecture. The transaction lifecycle is centered around `buy_requests`, with financial operations represented independently through the wallet ledger and settlement entities.
