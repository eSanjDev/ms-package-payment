# 📚 Esanj Payment Client — Complete Guide

A step-by-step guide to accepting and managing payments through the Esanj Payment
microservice from any Laravel application.

## Table of Contents

1. [What this package does](#1-what-this-package-does)
2. [How it works (the big picture)](#2-how-it-works-the-big-picture)
3. [Installation](#3-installation)
4. [Configuration & `.env`](#4-configuration--env)
5. [Two ways to call the client](#5-two-ways-to-call-the-client)
6. [Listing gateways](#6-listing-gateways)
7. [Creating a transaction](#7-creating-a-transaction)
8. [The transaction lifecycle](#8-the-transaction-lifecycle)
9. [Handling errors safely](#9-handling-errors-safely)
10. [Configuration reference](#10-configuration-reference)
11. [API endpoints used](#11-api-endpoints-used)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. What this package does

It is a thin, typed HTTP client for the Payment microservice. You call PHP methods with
DTOs; it authenticates, sends the request, and hands you back typed response objects instead
of raw arrays. You never build URLs, attach bearer tokens, or `json_decode` responses yourself.

## 2. How it works (the big picture)

```
Your app ──InitTransactionData──▶ PaymentClient
                                      │
                     ┌────────────────┴───────────────────┐
                     ▼                                     ▼
             AuthBridgeTokenProvider                    ApiClient (Guzzle)
             (esanj/auth-bridge)                        + Authorization: Bearer <jwt>
                     │                                     │
    client_credentials grant                     POST /api/v1/payment/...
                     ▼                                     ▼
        OAuth server (ACCOUNTING_BRIDGE_BASE_URL)     Payment microservice
        issues a signed JWT  ───────────────────▶  validates the JWT (RS256) and responds
```

1. **Token** — `AuthBridgeTokenProvider` asks `esanj/auth-bridge` for an access token using the
   `client_credentials` grant with your `PAYMENT_CLIENT_ID` / `PAYMENT_CLIENT_SECRET`. auth-bridge
   caches the token (with an expiry buffer) so you are not issuing a token on every request.
2. **Call** — `ApiClient` sends the request to the Payment service with that token as a
   `Bearer` header. The Payment service validates the JWT signature and serves the request.
3. **Response** — the raw JSON is mapped into a typed Resource object.

## 3. Installation

```bash
# First installation
composer require esanj/payment-client

# Existing applications: resolve the corrected dependency metadata as well.
composer clear-cache
composer update esanj/payment-client esanj/auth-bridge firebase/php-jwt --with-all-dependencies
```

The corrected `auth-bridge` release is still tagged `1.0.0` (tested reference:
`6453f598583669c0f8d2c9cf6081aa2785b5582a`). This client also requires
`firebase/php-jwt ^7.0`: the old auth-bridge manifest requiring JWT `^6.0`
cannot satisfy that constraint. There is no dependency on an unpublished `1.0.1`.
The corrected bridge stores token arrays and reconstructs `TokenData`, including
when `cache.serializable_classes=false` with the file cache.

Check `composer show esanj/auth-bridge` and the reference in `composer.lock` after
updating. A consumer with a stale lock/cache needs to resolve and reinstall the
corrected dependency; publishing this client alone does not update existing apps.
Run `composer audit` in the consumer environment with registry access. Do not disable
security blocking to install the old JWT dependency.


`esanj/auth-bridge` is installed as a dependency. Configure its OAuth server URL below.

## 4. Configuration & `.env`

```dotenv
PAYMENT_SERVICE_URL=https://payments.esanj.io
PAYMENT_CLIENT_ID=your-merchant-client-id
PAYMENT_CLIENT_SECRET=your-merchant-client-secret

# Optional
PAYMENT_SCOPE=*
PAYMENT_TIMEOUT=30
PAYMENT_RETRY_ATTEMPTS=3
PAYMENT_RETRY_SLEEP_MS=1000
PAYMENT_LOG_CHANNEL=

# auth-bridge (the OAuth server that issues the token)
ACCOUNTING_BRIDGE_BASE_URL=https://accounting.esanj.io
```

Publish the config file if you want to edit defaults directly:

```bash
php artisan vendor:publish --tag=payment-config   # → config/esanj/payment.php
```

## 5. Two ways to call the client

**Injection (recommended):**

```php
use Esanj\PaymentClient\Contracts\PaymentClientInterface;

public function __construct(private PaymentClientInterface $payment) {}
```

**Facade:**

```php
use Esanj\PaymentClient\Facades\Payment;

Payment::listGateways();
```

Both resolve the same singleton.

## 6. Listing gateways

```php
foreach (Payment::listGateways() as $gateway) {
    // Esanj\PaymentClient\Resources\GatewayResource
    $gateway->key;                       // 'zibal-1'
    $gateway->name;                      // 'zibal'
    $gateway->title;                     // display title
    $gateway->currencies;                // ['IRR', ...]
    $gateway->isActive;
    $gateway->isSandbox;
    $gateway->supportsCurrency('IRR');   // bool
}
```

`currencies` lists every currency the gateway can take a payment in, including ones the service
converts on the way in (e.g. `IRT` on a rial-only gateway). The converted amount is what goes to
the gateway; `status()` and the action results report the amount in the currency you requested.

## 7. Creating a transaction

```php
use Esanj\PaymentClient\DTOs\InitTransactionData;
use Esanj\PaymentClient\DTOs\CartData;
use Esanj\PaymentClient\DTOs\CartItemData;
use Esanj\PaymentClient\Facades\Payment;
use Illuminate\Support\Facades\DB;

$data = new InitTransactionData(
    amount: (float) $order->expected_amount, // authorized, server-priced order
    currency: $order->expected_currency,
    gatewayKey: 'zibal-1',   // optional; null uses the merchant's default gateway with failover
    mobile: '09121234567',   // at least one of mobile / email is required
    email: 'buyer@example.com',
    userId: 42,
    returnUrl: 'https://shop.example.com/payment/callback',  // must be a trusted domain of the merchant
    description: 'Order #1234',
    discountAmount: 50_000,
    externalSourceAmount: 0,
    cartList: [
        new CartData(
            cartId: 581,
            items: [
                new CartItemData(id: 193, name: 'Laptop', category: 'Electronics', amount: 9_000_000, count: 1),
            ],
            totalAmount: 9_000_000,
            isShipmentIncluded: false,
            isTaxIncluded: false,
            shippingAmount: 0,
            taxAmount: 0,
        ),
    ],
);

$result = Payment::initTransaction($data);

$result->paymentToken;     // '019f73fe-...'  (the transaction code)
$result->paymentPageUrl;   // 'https://payments.esanj.io/api/v1/payment/019f73fe-...'

// $order is an authorized, server-priced row from your application's order table.
// Adapt the order fields described in the guide to your application tables.
// Save the code BEFORE exposing the redirect. Never overwrite another active payment code.
$bound = DB::table('payment_orders')
    ->where('id', $order->id)
    ->whereNull('payment_code')
    ->whereNull('paid_at')
    ->where('expected_amount', $data->amount)
    ->where('expected_currency', $data->currency)
    ->update(['payment_code' => $result->paymentToken]);
abort_unless($bound === 1, 409, 'Checkout changed; reconcile this payment before retrying.');

return redirect()->away($result->paymentPageUrl);
```

**Server-side validation rules to keep in mind** (enforced by the Payment service):

- `amount` — required, numeric, `1 .. 500000000000`, up to 2 decimals. For `IRR` / `IRT` it must
  resolve to a whole number of rials: `IRT` amounts are multiplied by 10, so `1250.5` toman
  is accepted (12505 rials), while `1250.55` is rejected. Send rial amounts as integers.
- `currency` — required, one of the service's configured currencies: `IRR`, `IRT`, `USD`, `CAD`.
- `mobile` **or** `email` — at least one is required.
- `gateway_key` — must exist and belong to the merchant (omit to use the default).
- `return_url` — must be on one of the merchant's trusted domains.
- each cart requires `cart_id`, at least one item, and the shipment/tax/total fields.

## 8. The transaction lifecycle

```
created ──(redirect)──▶ pending ──(customer pays)──▶ paid ──verify()──▶ verified ──settle()──▶ settled
   │                                                                        │                    │
cancel()                                                                 revert()             cancel()
   ▼                                                                        ▼                    ▼
canceled                                                                 reverted             reverted
```

- `pending` is the customer's time at the gateway: nothing can cancel or revert it; poll status and handle the callback.
- `revert()` only accepts `verified`. Once settled, the refund goes through `cancel()` — and the
  resulting status can be `reverted` (refund requested) or `refund` (completed), not `canceled`.

```php
// Read current status
$status = Payment::status($code);          // TransactionStatusResource
$status->code;
$status->amount;
$status->currency;
$status->gateway;                          // gateway name
$status->status;                           // Esanj\PaymentClient\Enums\TransactionStatus
$status->status->isPaid();                 // typed checks

// Actions — each returns a TransactionActionResult { message, code, amount, currency, status }
Payment::verify($code);   // paid → verified   (must be paid, else PaymentApiException::isInvalidStatus())
Payment::settle($code);   // verified → settled
Payment::revert($code);   // verified → reverted (refund; issued to the gateway if it supports refunds)
Payment::cancel($code);   // created → canceled  |  settled → reverted (refund)
```

The `TransactionStatus` enum offers `isPaid()`, `isVerified()`, `isSettled()`, `isPending()`,
`isReverted()`, `isCanceled()`, `isFailed()`, and `isFinal()`.

### Handling the return from the gateway

The service redirects to `return_url` with `?status=<status>&code=<uuid>`. Browser
parameters cannot prove payment. Use the order binding persisted before redirecting.
The example below assumes application tables with a unique `payment_code`,
an immutable `expected_amount` (DECIMAL 14,2)
and `expected_currency`, plus `paid_at` and a fulfilment outbox with unique `order_id`.
No schema is installed automatically by this package.

```php
use App\Payments\ConfirmOrderPayment; // implement the confirmation helper shown in the guide
use Esanj\PaymentClient\Contracts\PaymentClientInterface;
use Esanj\PaymentClient\Resources\PaymentCallback;
use Illuminate\Support\Facades\DB;

$request->validate(['code' => ['required', 'string', 'max:255']]);
$callback = PaymentCallback::fromRequest($request);
$handler = new ConfirmOrderPayment(app(PaymentClientInterface::class), DB::connection());

// The helper selects the order by its persisted payment_code, checks code/amount/currency,
// verifies with the service, then locks the order and records one fulfilment intent.
$orderId = $handler->confirm($callback->code);

return response()->json(['payment' => $orderId === null ? 'pending' : 'confirmed']);
```

Do not pass an order id from the session or callback into this flow. The stored payment
code selects the order. The helper compares the response code, amount and currency with
that order's immutable expected values, both before verification and before committing.
Amounts from `status()` and action results are in the originally requested currency;
do not convert them again using the gateway's current configuration.

`paid_at` and a fulfilment outbox row are saved in one database transaction with a row
lock and a unique `order_id`. Repeated/interleaved callbacks therefore create one
fulfilment intent. A durable worker must process that outbox and deduplicate actual
delivery using the order id; dispatching a job alone is not an exactly-once guarantee.
Keep payment code, amount and currency immutable during an active checkout. Authorize
access to any order details separately; the public callback does not grant access.

If verify times out, returns 5xx or loses a race with another callback (400), the helper
reads `status()` again. Only authoritative `verified` or `settled`, with the same order
binding and amount/currency, can confirm delivery. `paid`/`pending` remains pending;
poll again later. Network failures, 409, unknown codes and mismatches never authorize
delivery. Route them to your application's retry/reconciliation or error handling.

#### Complete confirmation helper

Place the following `ConfirmOrderPayment` class in your application under `app/Payments`
and adapt the table names to your application. This example schedules delivery;
implement an idempotent outbox worker for the actual business action.

```php

namespace App\Payments;

use DomainException;
use Esanj\PaymentClient\Contracts\PaymentClientInterface;
use Esanj\PaymentClient\Enums\TransactionStatus;
use Esanj\PaymentClient\Exceptions\PaymentApiException;
use Esanj\PaymentClient\Resources\TransactionActionResult;
use Esanj\PaymentClient\Resources\TransactionStatusResource;
use Illuminate\Database\ConnectionInterface;

/**
 * Copy into your application and adapt the example tables to your order schema.
 * A callback and a scheduled reconciliation job must use this same flow.
 */
final class ConfirmOrderPayment
{
    public function __construct(
        private PaymentClientInterface $payment,
        private ConnectionInterface $database,
    ) {}

    /** Returns the bound order id, or null while payment is still pending. */
    public function confirm(string $code): ?int
    {
        $order = $this->database->table('payment_orders')->where('payment_code', $code)->first();
        if ($order === null) {
            throw new DomainException('No order is bound to this payment code.');
        }

        // Never select an order using the current browser session or a query order_id.
        $transaction = $this->payment->status($code);
        $this->assertMatches($order, $transaction);

        if ($transaction->status === TransactionStatus::Paid) {
            try {
                $transaction = $this->payment->verify($code);
            } catch (PaymentApiException $exception) {
                if ($exception->statusCode !== 0 && ! $exception->isInvalidStatus() && $exception->statusCode < 500) {
                    throw $exception;
                }
                // A lost response or concurrent callback may mean verify already succeeded.
                // Read the authoritative status instead of blindly repeating a mutation.
                $transaction = $this->payment->status($code);
            }
            $this->assertMatches($order, $transaction);
        }

        if ($transaction->status->isPending() || $transaction->status === TransactionStatus::Paid) {
            return null; // Retry status polling later; never fulfil from a browser status.
        }
        if (! in_array($transaction->status, [TransactionStatus::Verified, TransactionStatus::Settled], true)) {
            throw new DomainException('This payment does not authorize order fulfilment.');
        }

        // Network calls finish before taking the database lock.
        return $this->database->transaction(function () use ($order, $code, $transaction) {
            $locked = $this->database->table('payment_orders')->where('id', $order->id)->lockForUpdate()->first();
            if ($locked === null || $locked->payment_code !== $code) {
                throw new DomainException('The order payment binding changed.');
            }
            $this->assertMatches($locked, $transaction);
            if ($locked->paid_at !== null) {
                return (int) $locked->id;
            }

            $this->database->table('payment_orders')->where('id', $locked->id)->update(['paid_at' => now()]);
            // Same DB transaction, UNIQUE order_id: concurrent callbacks create one intent.
            $this->database->table('payment_fulfilment_outbox')->insert([
                'order_id' => $locked->id,
                'payment_code' => $code,
                'created_at' => now(),
            ]);

            return (int) $locked->id;
        });
    }

    private function assertMatches(object $order, TransactionStatusResource|TransactionActionResult $transaction): void
    {
        if ($transaction->code !== $order->payment_code
            || $transaction->currency !== $order->expected_currency
            || $this->decimal($transaction->amount) !== $this->decimal($order->expected_amount)) {
            throw new DomainException('Payment code, amount or currency does not match this order.');
        }
    }

    private function decimal(int|float|string $amount): string
    {
        if (! is_numeric($amount) || ! is_finite((float) $amount) || (float) $amount <= 0 || (float) $amount > 500000000000) {
            throw new DomainException('Invalid payment amount.');
        }

        // Service contract: at most two decimal places, in the originally requested currency.
        return number_format((float) $amount, 2, '.', '');
    }
}
```

### Verification deadline and missed callbacks

Verify within **20 minutes of the service's `paid_at`**. The scheduled sweep reverts
payments left in `paid` beyond that window and requests a refund. A recovered missing
callback starts the window when the pending sweep first confirms payment, not at creation.
The status endpoint currently does not expose `paid_at`; do not invent a client deadline
from the order's creation timestamp. Verify immediately whenever polling observes `paid`.

Run a scheduled reconciliation job for locally open payment codes, using the same
confirmation helper as the browser callback. Poll well inside the 20-minute window
with bounded retries/backoff and merchant rate limits. Do not depend on the buyer
returning to your site. After a verify timeout, an authoritative `verified`/`settled`
state allows safe recovery; a terminal refund/cancel/failure state does not.

Automatic refunds depend on gateway support and provider success; manual gateways
need operator settlement. `reverted` means refund requested, while `refund` means it
completed. Follow `status()` for the final outcome, including after `revert()`/`cancel()`.
For historical transactions with unresolved settlement currency the service returns
409; stop fulfilment and ask the service operator to reconcile the historical data.

## 9. Handling errors safely

```php
use Esanj\PaymentClient\Exceptions\PaymentApiException;
use Esanj\PaymentClient\Exceptions\PaymentAuthenticationException;
use Esanj\PaymentClient\Exceptions\PaymentException;

try {
    $result = Payment::initTransaction($data);
} catch (PaymentApiException $e) {
    if ($e->isValidationError()) {
        // 422 — field errors from the service
        $errors = $e->getErrors();          // ['amount' => ['...'], ...]
    } elseif ($e->isNoGatewayAvailable()) {
        // 503 — no gateway could serve this transaction
    } elseif ($e->isInvalidStatus()) {
        // 400 — action not allowed for the current transaction status
    } elseif ($e->isForbidden()) {
        // 403 — unknown transaction code, or it belongs to another merchant
    } elseif ($e->isConflict()) {
        // 409 — stop fulfilment and reconcile the historical settlement currency.
    } elseif ($e->isRateLimited()) {
        // 429 — back off for $e->retryAfter seconds
    } else {
        report($e);                          // $e->statusCode, $e->responseBody
    }
} catch (PaymentAuthenticationException $e) {
    // Missing/invalid credentials, or the OAuth server rejected the token request.
    report($e);
}
```

- `PaymentException` is the base class — catch it to handle everything at once. A response the
  package cannot map (e.g. a status value it does not know) also surfaces as a `PaymentException`.
- The service answers **403, not 404**, for an unknown transaction code — it does not reveal
  whether a code exists. `isNotFound()` is only ever true for routing-level misses.
- A rejected token (401) is invalidated and fetched afresh before a retry. Server (5xx) and
  connection failures are retried **only for idempotent calls** — `listGateways()` and `status()`.
  `initTransaction()`, `verify()`, `settle()`, `revert()` and `cancel()` are not retried after
  those failures. A 401 may cause a retry with a new token. Catch ambiguous failures and
  check `status()` before resending. Initialization timeouts may leave an unknown payment;
  reconcile those with the service instead of blindly initializing again.
- `429` is never retried; read `$e->retryAfter` and back off.

## 10. Configuration reference

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `base_url` | `PAYMENT_SERVICE_URL` | `http://localhost` | Payment service base URL. |
| `client_id` | `PAYMENT_CLIENT_ID` | — | Merchant OAuth client id. |
| `client_secret` | `PAYMENT_CLIENT_SECRET` | — | Merchant OAuth client secret. |
| `scope` | `PAYMENT_SCOPE` | `*` | OAuth scope requested. |
| `timeout` | `PAYMENT_TIMEOUT` | `30` | HTTP timeout (seconds). |
| `retry.attempts` | `PAYMENT_RETRY_ATTEMPTS` | `3` | Total attempts (1 = no retry). |
| `retry.sleep_ms` | `PAYMENT_RETRY_SLEEP_MS` | `1000` | Delay between retries (ms). |
| `logging.channel` | `PAYMENT_LOG_CHANNEL` | app default | Log channel for the client. |

The OAuth server URL itself comes from the auth-bridge config (`ACCOUNTING_BRIDGE_BASE_URL`).

## 11. API endpoints used

| Client method | HTTP | Path | Body |
| --- | --- | --- | --- |
| `listGateways()` | GET | `/api/v1/merchant/gateways` | — |
| `initTransaction()` | POST | `/api/v1/payment/init-transaction` | full transaction payload |
| `status()` | POST | `/api/v1/payment/status` | `{ "code": "<uuid>" }` |
| `verify()` | POST | `/api/v1/payment/verify` | `{ "code": "<uuid>" }` |
| `settle()` | POST | `/api/v1/payment/settle` | `{ "code": "<uuid>" }` |
| `revert()` | POST | `/api/v1/payment/revert` | `{ "code": "<uuid>" }` |
| `cancel()` | POST | `/api/v1/payment/cancel` | `{ "code": "<uuid>" }` |

All endpoints require a valid `Bearer` token and are protected by the merchant's JWT, status,
and allowed-source checks on the service side.

## 12. Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| `PaymentAuthenticationException: credentials are not configured` | `PAYMENT_CLIENT_ID` / `PAYMENT_CLIENT_SECRET` not set. |
| `PaymentAuthenticationException` on every call | Wrong credentials, or `ACCOUNTING_BRIDGE_BASE_URL` points to the wrong OAuth server. |
| `PaymentApiException` with 401 after retries | The JWT is rejected by the Payment service (clock skew, wrong signing key, wrong audience). |
| `isForbidden()` (403) on every call | The merchant is inactive, or the caller's IP is outside the merchant's allowlist. |
| `isForbidden()` (403) on one transaction | The code is unknown or was created by another merchant. |
| `isRateLimited()` (429) | More than 120 calls/minute for this merchant; wait `$e->retryAfter` seconds. |
| `isNoGatewayAvailable()` (503) | The merchant has no active gateway for the requested currency. |
| `isValidationError()` (422) | Inspect `getErrors()` — usually `amount`, `currency`, `return_url`, or `cart_list`. |
| `isConflict()` (409) | Historical settlement currency needs service-side reconciliation; do not fulfil or repeatedly retry. |
