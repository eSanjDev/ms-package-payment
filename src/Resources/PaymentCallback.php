<?php

namespace Esanj\PaymentClient\Resources;

use Esanj\PaymentClient\Enums\TransactionStatus;
use Esanj\PaymentClient\Exceptions\PaymentException;
use Illuminate\Http\Request;

final class PaymentCallback
{
    public function __construct(
        public readonly string $code,
        public readonly ?TransactionStatus $status,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $code = $request->query('code', '');
        $status = $request->query('status', '');
        if (! is_string($code) || trim($code) === '') {
            throw new PaymentException('The payment callback must contain a transaction code.');
        }

        return new self(
            code: $code,
            status: is_string($status) ? TransactionStatus::tryFrom($status) : null,
        );
    }
}
