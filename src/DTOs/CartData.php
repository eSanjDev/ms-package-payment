<?php

namespace Esanj\PaymentClient\DTOs;

final class CartData
{
    /**
     * @param CartItemData[] $items
     */
    public function __construct(
        public readonly int $cartId,
        public readonly array $items,
        public readonly int|float $totalAmount,
        public readonly bool $isShipmentIncluded = false,
        public readonly bool $isTaxIncluded = false,
        public readonly int|float $shippingAmount = 0,
        public readonly int|float $taxAmount = 0,
    ) {}

    public function toArray(): array
    {
        return [
            'cart_id'              => $this->cartId,
            'cart_items'           => array_map(fn (CartItemData $item) => $item->toArray(), $this->items),
            'is_shipment_included' => $this->isShipmentIncluded,
            'is_tax_included'      => $this->isTaxIncluded,
            'shipping_amount'      => $this->shippingAmount,
            'tax_amount'           => $this->taxAmount,
            'total_amount'         => $this->totalAmount,
        ];
    }
}