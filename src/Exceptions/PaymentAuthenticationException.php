<?php

namespace Esanj\PaymentClient\Exceptions;

use Throwable;

class PaymentAuthenticationException extends PaymentException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 401, $previous);
    }
}