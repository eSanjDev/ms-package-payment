<?php

namespace Esanj\PaymentClient\Exceptions;

use Throwable;

/**
 * Thrown when the Payment service returns an error response.
 */
class PaymentApiException extends PaymentException
{
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $responseBody = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Field-level validation errors, when present (HTTP 422).
     *
     * @return array<string, string[]>
     */
    public function getErrors(): array
    {
        return $this->responseBody['errors'] ?? [];
    }

    public function isUnauthorized(): bool
    {
        return $this->statusCode === 401;
    }

    public function isForbidden(): bool
    {
        return $this->statusCode === 403;
    }

    public function isNotFound(): bool
    {
        return $this->statusCode === 404;
    }

    public function isValidationError(): bool
    {
        return $this->statusCode === 422;
    }

    /**
     * The transaction is not in a state that allows the requested action
     * (e.g. verifying a transaction that was never paid).
     */
    public function isInvalidStatus(): bool
    {
        return $this->statusCode === 400;
    }

    /**
     * No gateway was available to serve the transaction.
     */
    public function isNoGatewayAvailable(): bool
    {
        return $this->statusCode === 503;
    }
}