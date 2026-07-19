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

In the root `composer.json`:

```jsonc
"require": {
    "esanj/payment-client": "dev-main"
},
"repositories": [
    { "type": "path", "url": "packages/esanj/ms-package-payment", "options": { "symlink": true } }
]
```

```bash
composer update esanj/payment-client
```

`esanj/auth-bridge` is a dependency and is already installed in this project.

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

## 7. Creating a transaction

```php
use Esanj\PaymentClient\DTOs\InitTransactionData;
use Esanj\PaymentClient\DTOs\CartData;
use Esanj\PaymentClient\DTOs\CartItemData;

$data = new InitTransactionData(
    amount: 9_500_000,
    currency: 'IRR',
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

return redirect()->away($result->paymentPageUrl);
```

**Server-side validation rules to keep in mind** (enforced by the Payment service):

- `amount` — required, numeric, `1 .. 500000000000`, up to 2 decimals.
- `currency` — required, must be one of the service's configured currencies (e.g. `IRR`, `IRT`).
- `mobile` **or** `email` — at least one is required.
- `gateway_key` — must exist and belong to the merchant (omit to use the default).
- `return_url` — must be on one of the merchant's trusted domains.
- each cart requires `cart_id`, at least one item, and the shipment/tax/total fields.

## 8. The transaction lifecycle

```
created / pending ──(customer pays)──▶ paid ──verify()──▶ verified ──settle()──▶ settled
                                                              │
                                                          revert()  ──▶ reverted (refund)
cancel(): created | pending | settled ──▶ canceled
```

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
Payment::cancel($code);   // created | pending | settled → canceled
```

The `TransactionStatus` enum offers `isPaid()`, `isVerified()`, `isSettled()`, `isPending()`,
`isReverted()`, `isCanceled()`, `isFailed()`, and `isFinal()`.

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
    } elseif ($e->isNotFound()) {
        // 404 — unknown transaction code
    } else {
        report($e);                          // $e->statusCode, $e->responseBody
    }
} catch (PaymentAuthenticationException $e) {
    // Missing/invalid credentials, or the OAuth server rejected the token request.
    report($e);
}
```

- `PaymentException` is the base class — catch it to handle everything at once.
- Transient failures (HTTP 5xx, connection errors, and rejected tokens) are **retried
  automatically** before an exception is thrown. On a rejected token the client invalidates the
  cached token and fetches a fresh one on the next attempt.

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
| `isNoGatewayAvailable()` (503) | The merchant has no active gateway for the requested currency. |
| `isValidationError()` (422) | Inspect `getErrors()` — usually `amount`, `currency`, `return_url`, or `cart_list`. |