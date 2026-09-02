<?php

namespace Esanj\PaymentClient\Resources;

use Esanj\PaymentClient\Enums\TransactionStatus;

final class TransactionStatusResource
{
    public function __construct(
        public readonly string $code,
        public readonly int|float $amount,
        public readonly string $currency,
        public readonly ?string $gateway,
        public readonly TransactionStatus $status,
    ) {}

    public static function fromArray(array $response): self
    {
        $item = $response['data'] ?? $response;

        return new self(
            code:     $item['code'] ?? '',
            amount:   $item['amount'] ?? 0,
            currency: $item['currency'] ?? '',
            gateway:  $item['gateway'] ?? null,
            status:   TransactionStatus::fromResponse($item['status'] ?? null),
        );
    }
}
