<?php

namespace Esanj\PaymentClient\Resources;

/**
 * Result of POST /api/v1/payment/init-transaction.
 */
final class InitTransactionResult
{
    public function __construct(
        public readonly string $paymentToken,
        public readonly string $paymentPageUrl,
    ) {}

    public static function fromArray(array $response): self
    {
        return new self(
            paymentToken:   $response['payment_token'] ?? '',
            paymentPageUrl: $response['payment_page_url'] ?? '',
        );
    }
}