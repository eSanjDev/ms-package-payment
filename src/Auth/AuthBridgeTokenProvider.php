<?php

namespace Esanj\PaymentClient\Auth;

use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\AuthBridge\Exceptions\TokenRequestException;
use Esanj\PaymentClient\Contracts\TokenProviderInterface;
use Esanj\PaymentClient\Exceptions\PaymentAuthenticationException;

/**
 * Obtains a Payment-service access token through the auth-bridge package's
 * client-credentials grant. Token caching (and its expiry buffer) is handled
 * inside auth-bridge, so this provider simply delegates to it.
 */
class AuthBridgeTokenProvider implements TokenProviderInterface
{
    public function __construct(
        private readonly ClientCredentialsServiceInterface $credentials,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $scope = null,
    ) {}

    public function authorizationHeader(): string
    {
        $this->guardCredentials();

        try {
            return $this->credentials
                ->getAccessToken($this->clientId, $this->clientSecret, $this->scope)
                ->getAuthorizationHeader();
        } catch (TokenRequestException $e) {
            throw new PaymentAuthenticationException(
                'Could not obtain an access token for the payment service: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function invalidate(): void
    {
        if ($this->clientId !== '') {
            $this->credentials->invalidateToken($this->clientId, $this->scope);
        }
    }

    private function guardCredentials(): void
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new PaymentAuthenticationException(
                'Payment client credentials are not configured. Set PAYMENT_CLIENT_ID and PAYMENT_CLIENT_SECRET.'
            );
        }
    }
}