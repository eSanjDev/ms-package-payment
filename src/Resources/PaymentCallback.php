<?php

namespace Esanj\PaymentClient\Resources;

use Esanj\PaymentClient\Enums\TransactionStatus;
use Illuminate\Http\Request;

final class PaymentCallback
{
    public function __construct(
        public readonly string $code,
        public readonly ?TransactionStatus $status,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            code:   (string) $request->query('code', ''),
            status: TransactionStatus::tryFrom((string) $request->query('status', '')),
        );
    }
}
