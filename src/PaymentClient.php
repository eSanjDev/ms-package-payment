<?php

namespace Esanj\PaymentClient;

use Esanj\PaymentClient\Contracts\PaymentClientInterface;
use Esanj\PaymentClient\DTOs\InitTransactionData;
use Esanj\PaymentClient\Http\ApiClient;
use Esanj\PaymentClient\Resources\GatewayResource;
use Esanj\PaymentClient\Resources\InitTransactionResult;
use Esanj\PaymentClient\Resources\TransactionActionResult;
use Esanj\PaymentClient\Resources\TransactionStatusResource;

class PaymentClient implements PaymentClientInterface
{
    public function __construct(protected ApiClient $apiClient)
    {
    }

    public function listGateways(): array
    {
        $response = $this->apiClient->get('api/v1/merchant/gateways');

        return array_map(GatewayResource::fromArray(...), $response['data'] ?? $response);
    }

    public function initTransaction(InitTransactionData $data): InitTransactionResult
    {
        $response = $this->apiClient->post('api/v1/payment/init-transaction', $data->toArray());

        return InitTransactionResult::fromArray($response);
    }

    public function status(string $code): TransactionStatusResource
    {
        $response = $this->apiClient->post('api/v1/payment/status', ['code' => $code], idempotent: true);

        return TransactionStatusResource::fromArray($response);
    }

    public function verify(string $code): TransactionActionResult
    {
        $response = $this->apiClient->post('api/v1/payment/verify', ['code' => $code]);

        return TransactionActionResult::fromArray($response);
    }

    public function settle(string $code): TransactionActionResult
    {
        $response = $this->apiClient->post('api/v1/payment/settle', ['code' => $code]);

        return TransactionActionResult::fromArray($response);
    }

    public function revert(string $code): TransactionActionResult
    {
        $response = $this->apiClient->post('api/v1/payment/revert', ['code' => $code]);

        return TransactionActionResult::fromArray($response);
    }

    public function cancel(string $code): TransactionActionResult
    {
        $response = $this->apiClient->post('api/v1/payment/cancel', ['code' => $code]);

        return TransactionActionResult::fromArray($response);
    }
}
