# Esanj Payment Client

A Laravel client package for the **Esanj Payment Microservice**. It authenticates with client credentials through the [`esanj/auth-bridge`](../ms-package-accounting-bridge) package, then lets you list merchant gateways and create, inspect and manage transactions — with typed DTOs, typed responses and structured error handling.

## Installation

```bash
composer update esanj/payment-client
```

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

$result = Payment::initTransaction(new InitTransactionData(
    amount: 9_500_000,
    currency: 'IRR',
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
));

// Redirect the customer to the gateway:
return redirect()->away($result->paymentPageUrl);   // $result->paymentToken holds the code
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
reverted — wait for the callback. Note that `cancel()` on a settled transaction refunds it and
returns `status = reverted`, not `canceled`.

### Handle the return from the gateway

The service sends the customer back to your `return_url` with `?status=<status>&code=<uuid>`.
Both values pass through the customer's browser, so read them as a hint and confirm with the service:

```php
use Esanj\PaymentClient\Resources\PaymentCallback;

$callback = PaymentCallback::fromRequest($request);   // ->code, ->status (nullable)

$status = Payment::status($callback->code)->status;   // the authoritative state
```

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
create or move money are sent once, so a timeout never turns into a duplicate transaction.

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
