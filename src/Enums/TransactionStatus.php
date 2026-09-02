<?php

namespace Esanj\PaymentClient\Enums;

use Esanj\PaymentClient\Exceptions\PaymentException;

enum TransactionStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Paid = 'paid';
    case Verified = 'verified';
    case Settled = 'settled';
    case Reverted = 'reverted';
    case Refund = 'refund';
    case Canceled = 'canceled';
    case Failed = 'failed';

    public static function fromResponse(mixed $value): self
    {
        $value = is_scalar($value) ? (string)$value : '';

        return self::tryFrom($value)
            ?? throw new PaymentException("Unknown transaction status returned by the payment service: '{$value}'.");
    }

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }

    public function isVerified(): bool
    {
        return $this === self::Verified;
    }

    public function isSettled(): bool
    {
        return $this === self::Settled;
    }

    public function isPending(): bool
    {
        return in_array($this, [self::Created, self::Pending], true);
    }

    public function isReverted(): bool
    {
        return in_array($this, [self::Reverted, self::Refund], true);
    }

    public function isCanceled(): bool
    {
        return $this === self::Canceled;
    }

    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Settled, self::Reverted, self::Refund, self::Canceled, self::Failed], true);
    }
}
