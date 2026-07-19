<?php

namespace Esanj\PaymentClient\Contracts;

use Esanj\PaymentClient\Exceptions\PaymentAuthenticationException;

interface TokenProviderInterface
{
    /**
     * Return the "Bearer xxx" Authorization header for the Payment service.
     *
     * @throws PaymentAuthenticationException
     */
    public function authorizationHeader(): string;

    /**
     * Drop the currently cached token so the next call fetches a fresh one.
     */
    public function invalidate(): void;
}