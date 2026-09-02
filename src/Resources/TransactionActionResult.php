<?php

namespace Esanj\PaymentClient\Resources;

use Esanj\PaymentClient\Enums\TransactionStatus;

/**
 * Result of the verify / settle / revert / cancel actions, which return a
 * confirmation message alongside the updated transaction snapshot.
 */
final class TransactionActionResult
{
    public function __construct(
        public readonly string $message,
        public readonly string $code,
        public readonly int|float $amount,
        public readonly string $currency,
        public readonly TransactionStatus $status,
    ) {}

    public static function fromArray(array $response): self
    {
        $data = $response['data'] ?? [];

        return new self(
            message:  $response['message'] ?? '',
            code:     $data['code'] ?? '',
            amount:   $data['amount'] ?? 0,
            currency: $data['currency'] ?? '',
            status:   TransactionStatus::fromResponse($data['status'] ?? null),
        );
    }
}
