# Esanj Payment Client

A Laravel client package for the **Esanj Payment Microservice**. It authenticates with client credentials through the [`esanj/auth-bridge`](../ms-package-accounting-bridge) package, then lets you list merchant gateways and create, inspect and manage transactions — with typed DTOs, typed responses and structured error handling.

## Installation

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


The service provider and the `Payment` facade are auto-discovered.

## Configuration

Publish the config (optional — it is merged automatically):

```bash
php artisan vendor:publish --tag=payment-config
```

Set the environment variables:

```dotenv
# Payment microservice
PAYMENT_SERVICE_URL=https://payments.esanj.io
PAYMENT_CLIENT_ID=your-merchant-client-id
PAYMENT_CLIENT_SECRET=your-merchant-client-secret

# Optional
PAYMENT_SCOPE=*
PAYMENT_TIMEOUT=30
PAYMENT_RETRY_ATTEMPTS=3
PAYMENT_RETRY_SLEEP_MS=1000
PAYMENT_LOG_CHANNEL=

# Required by esanj/auth-bridge — the OAuth server that issues the token
ACCOUNTING_BRIDGE_BASE_URL=https://accounting.esanj.io
```

> The access token is issued by the OAuth server configured for `esanj/auth-bridge`
> (`ACCOUNTING_BRIDGE_BASE_URL`) using the merchant's `PAYMENT_CLIENT_ID` / `PAYMENT_CLIENT_SECRET`,
> then sent as a `Bearer` token to the Payment service. Tokens are cached and refreshed
> automatically by auth-bridge.

## Usage

### Dependency injection (recommended)

```php
use Esanj\PaymentClient\Contracts\PaymentClientInterface;

class CheckoutController
{
    public function __construct(private PaymentClientInterface $payment) {}
}
```

### Facade

```php
use Esanj\PaymentClient\Facades\Payment;

$gateways = Payment::listGateways();
```

### Create a transaction

```php
use Esanj\PaymentClient\Facades\Payment;
use Esanj\PaymentClient\DTOs\InitTransactionData;
use Esanj\PaymentClient\DTOs\CartData;
use Esanj\PaymentClient\DTOs\CartItemData;
use Illuminate\Support\Facades\DB;

$data = new InitTransactionData(
    amount: (float) $order->expected_amount, // authorized, server-priced order
    currency: $order->expected_currency,
    gatewayKey: 'zibal-1',            // null → merchant default gateway (with failover)
    mobile: '09121234567',
    email: 'buyer@example.com',
    userId: 42,
    returnUrl: 'https://shop.example.com/payment/callback',
    description: 'Order #1234',
    discountAmount: 50_000,
    externalSourceAmount: 0,
    cartList: [
        new CartData(
            cartId: 581,
            items: [new CartItemData(id: 193, name: 'Laptop', category: 'Electronics', amount: 9_000_000, count: 1)],
            totalAmount: 9_000_000,
        ),
    ],
);
$result = Payment::initTransaction($data);

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

### Inspect & manage a transaction

```php
$status = Payment::status($code);       // TransactionStatusResource
$status->status->isPaid();              // typed TransactionStatus enum

Payment::verify($code);   // paid     → verified
Payment::settle($code);   // verified → settled
Payment::revert($code);   // verified → reverted (refund)
Payment::cancel($code);   // created  → canceled  |  settled → reverted (refund)
```

A transaction that is `pending` (the customer is at the gateway) can be neither canceled nor
reverted. Poll status as well as handling the callback. `cancel()` on a settled transaction
requests a refund: its response can be `reverted` or `refund` after provider success.

### Handle the return from the gateway

The service redirects to `return_url` with `?status=<status>&code=<uuid>`.
Treat browser values as untrusted. Persist the payment token as shown above, then use
the [confirmation helper in the guide](docs/GUIDE.md#complete-confirmation-helper) in your app:

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

## Error Handling

Every failure throws a subclass of `Esanj\PaymentClient\Exceptions\PaymentException`:

| Exception | When |
| --- | --- |
| `PaymentAuthenticationException` | Client credentials missing/invalid; the OAuth server rejected the token request. |
| `PaymentApiException` | The Payment service returned an error response. Carries `statusCode`, `responseBody`, `getErrors()`. |

```php
use Esanj\PaymentClient\Exceptions\PaymentApiException;
use Esanj\PaymentClient\Exceptions\PaymentAuthenticationException;

try {
    $result = Payment::verify($code);
} catch (PaymentApiException $e) {
    if ($e->isInvalidStatus())      { /* transaction not in a verifiable state */ }
    elseif ($e->isForbidden())      { /* unknown code, or it belongs to another merchant */ }
    elseif ($e->isConflict())      { /* historical currency may need operator reconciliation */ }
    elseif ($e->isRateLimited())    { /* back off for $e->retryAfter seconds */ }
    elseif ($e->isValidationError()){ $errors = $e->getErrors(); }
    else                            { report($e); }
} catch (PaymentAuthenticationException $e) {
    // credentials / OAuth problem
}
```

The service answers `403` — not `404` — for an unknown transaction code, so `isForbidden()` is
the branch to handle it.

A rejected token (401) is invalidated and re-fetched before a retry. Server (5xx) and connection
failures are retried only for idempotent calls (`listGateways()`, `status()`); the actions that
create or move money are not automatically retried after those failures. A 401 can trigger
a new attempt with a fresh token. After an ambiguous timeout, reconcile status before
resending. An initialization timeout may leave an unknown transaction on the service;
do not blindly create another checkout.

## API surface

| Method | Endpoint |
| --- | --- |
| `listGateways()` | `GET  /api/v1/merchant/gateways` |
| `initTransaction(InitTransactionData)` | `POST /api/v1/payment/init-transaction` |
| `status(string $code)` | `POST /api/v1/payment/status` |
| `verify(string $code)` | `POST /api/v1/payment/verify` |
| `settle(string $code)` | `POST /api/v1/payment/settle` |
| `revert(string $code)` | `POST /api/v1/payment/revert` |
| `cancel(string $code)` | `POST /api/v1/payment/cancel` |

See [`docs/GUIDE.md`](docs/GUIDE.md) for a full walkthrough.

## License

MIT
