<?php

namespace Esanj\PaymentClient\DTOs;

final class CartItemData
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $category,
        public readonly int|float $amount,
        public readonly int $count = 1,
    ) {}

    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'name'     => $this->name,
            'category' => $this->category,
            'amount'   => $this->amount,
            'count'    => $this->count,
        ];
    }
}