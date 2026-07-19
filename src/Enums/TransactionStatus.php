<?php

namespace Esanj\PaymentClient\Enums;

/**
 * Mirrors the transaction states of the Esanj Payment service.
 */
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

    /**
     * The customer has paid but the merchant has not verified yet.
     */
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

    /**
     * No further action can move the transaction forward.
     */
    public function isFinal(): bool
    {
        return in_array($this, [self::Settled, self::Reverted, self::Refund, self::Canceled, self::Failed], true);
    }
}