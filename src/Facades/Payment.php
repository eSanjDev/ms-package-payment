<?php

namespace Esanj\PaymentClient\Facades;

use Esanj\PaymentClient\Contracts\PaymentClientInterface;
use Esanj\PaymentClient\DTOs\InitTransactionData;
use Esanj\PaymentClient\Resources\GatewayResource;
use Esanj\PaymentClient\Resources\InitTransactionResult;
use Esanj\PaymentClient\Resources\TransactionActionResult;
use Esanj\PaymentClient\Resources\TransactionStatusResource;
use Illuminate\Support\Facades\Facade;

/**
 * @method static GatewayResource[] listGateways()
 * @method static InitTransactionResult initTransaction(InitTransactionData $data)
 * @method static TransactionStatusResource status(string $code)
 * @method static TransactionActionResult verify(string $code)
 * @method static TransactionActionResult settle(string $code)
 * @method static TransactionActionResult revert(string $code)
 * @method static TransactionActionResult cancel(string $code)
 *
 * @see \Esanj\PaymentClient\PaymentClient
 */
class Payment extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PaymentClientInterface::class;
    }
}