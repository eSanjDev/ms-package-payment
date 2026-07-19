<?php

namespace Esanj\PaymentClient\Contracts;

use Esanj\PaymentClient\DTOs\InitTransactionData;
use Esanj\PaymentClient\Resources\GatewayResource;
use Esanj\PaymentClient\Resources\InitTransactionResult;
use Esanj\PaymentClient\Resources\TransactionActionResult;
use Esanj\PaymentClient\Resources\TransactionStatusResource;

interface PaymentClientInterface
{
    /**
     * List the gateways enabled for the authenticated merchant.
     *
     * @return GatewayResource[]
     */
    public function listGateways(): array;

    /**
     * Create a new transaction and receive its payment token and page URL.
     */
    public function initTransaction(InitTransactionData $data): InitTransactionResult;

    /**
     * Fetch the current status of a transaction by its code.
     */
    public function status(string $code): TransactionStatusResource;

    /**
     * Verify a paid transaction.
     */
    public function verify(string $code): TransactionActionResult;

    /**
     * Settle a verified transaction.
     */
    public function settle(string $code): TransactionActionResult;

    /**
     * Revert (refund) a verified transaction.
     */
    public function revert(string $code): TransactionActionResult;

    /**
     * Cancel a transaction.
     */
    public function cancel(string $code): TransactionActionResult;
}