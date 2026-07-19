<?php

namespace Esanj\PaymentClient\DTOs;

/**
 * Payload for POST /api/v1/payment/init-transaction.
 *
 * The service requires at least one of $email / $mobile, and validates
 * $currency against its configured currency list. When $gatewayKey is null
 * the merchant's default gateway (with failover) is used.
 */
final class InitTransactionData
{
    /**
     * @param CartData[] $cartList
     */
    public function __construct(
        public readonly int|float $amount,
        public readonly string $currency = 'IRR',
        public readonly ?string $gatewayKey = null,
        public readonly ?string $email = null,
        public readonly ?string $mobile = null,
        public readonly int|string|null $userId = null,
        public readonly ?string $returnUrl = null,
        public readonly ?string $description = null,
        public readonly int|float|null $discountAmount = null,
        public readonly int|float|null $externalSourceAmount = null,
        public readonly array $cartList = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'amount'                 => $this->amount,
            'currency'               => $this->currency,
            'gateway_key'            => $this->gatewayKey,
            'email'                  => $this->email,
            'mobile'                 => $this->mobile,
            'user_id'                => $this->userId,
            'return_url'             => $this->returnUrl,
            'description'            => $this->description,
            'discount_amount'        => $this->discountAmount,
            'external_source_amount' => $this->externalSourceAmount,
            'cart_list'              => $this->cartList
                ? array_map(fn (CartData $cart) => $cart->toArray(), $this->cartList)
                : null,
        ], fn ($value) => $value !== null);
    }
}