<?php

namespace Esanj\PaymentClient\Resources;

/**
 * A merchant gateway returned by GET /api/v1/merchant/gateways.
 */
final class GatewayResource
{
    /**
     * @param string[] $currencies
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $title,
        public readonly ?string $type,
        public readonly ?string $connectionType,
        public readonly array $currencies,
        public readonly bool $isActive,
        public readonly bool $isSandbox,
    ) {}

    public static function fromArray(array $item): self
    {
        return new self(
            key:            $item['key'] ?? '',
            name:           $item['name'] ?? '',
            title:          $item['title'] ?? null,
            type:           $item['type'] ?? null,
            connectionType: $item['connection_type'] ?? null,
            currencies:     $item['currencies'] ?? [],
            isActive:       (bool) ($item['is_active'] ?? false),
            isSandbox:      (bool) ($item['is_sandbox'] ?? false),
        );
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array($currency, $this->currencies, true);
    }
}