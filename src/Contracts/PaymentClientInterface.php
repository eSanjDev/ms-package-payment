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
     * paid → verified. Any other state is rejected with a 400.
     */
    public function verify(string $code): TransactionActionResult;

    /**
     * verified → settled. Any other state is rejected with a 400.
     */
    public function settle(string $code): TransactionActionResult;

    /**
     * verified → reverted (refund). A settled transaction cannot be reverted
     * here; use cancel() instead.
     */
    public function revert(string $code): TransactionActionResult;

    /**
     * created → canceled, or settled → reverted (refund). A transaction that is
     * pending at the gateway cannot be canceled; wait for the callback.
     */
    public function cancel(string $code): TransactionActionResult;
}
