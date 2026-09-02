<?php

namespace Esanj\PaymentClient\Http;

use Esanj\PaymentClient\Contracts\TokenProviderInterface;
use Esanj\PaymentClient\Exceptions\PaymentApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;

class ApiClient
{
    public function __construct(
        private readonly Client                 $httpClient,
        private readonly TokenProviderInterface $tokenProvider,
        private readonly LoggerInterface        $logger,
        private readonly string                 $baseUrl,
        private readonly int                    $retryAttempts,
        private readonly int                    $retrySleepMs,
    )
    {
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, array_filter(['query' => $query ?: null]), idempotent: true);
    }

    public function post(string $path, array $body = [], bool $idempotent = false): array
    {
        return $this->request('POST', $path, ['json' => $body], $idempotent);
    }

    private function request(string $method, string $path, array $options, bool $idempotent): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request($method, $url, array_merge($options, [
                    'headers' => [
                        'Authorization' => $this->tokenProvider->authorizationHeader(),
                        'Accept' => 'application/json',
                    ],
                ]));

                return $this->decode((string)$response->getBody());

            } catch (ClientException $e) {
                $exception = $this->makeApiException($e);

                if ($exception->isUnauthorized()) {
                    $this->tokenProvider->invalidate();
                }

                $this->retryOrThrow($exception, $exception->isUnauthorized(), $attempt, 'Token rejected', $url);

            } catch (ServerException $e) {
                $this->retryOrThrow($this->makeApiException($e), $idempotent, $attempt, 'Server error', $url);

            } catch (ConnectException $e) {
                $exception = new PaymentApiException('Connection error: ' . $e->getMessage(), statusCode: 0, previous: $e);

                $this->retryOrThrow($exception, $idempotent, $attempt, 'Connection error', $url);

            } catch (GuzzleException $e) {
                $this->logger->error('[PaymentClient] Unexpected HTTP error.', ['url' => $url, 'error' => $e->getMessage()]);

                throw new PaymentApiException('Unexpected error: ' . $e->getMessage(), statusCode: 0, previous: $e);
            }
        }

        throw new PaymentApiException('Request failed after all retry attempts.', statusCode: 0);
    }

    private function retryOrThrow(PaymentApiException $exception, bool $retryable, int $attempt, string $reason, string $url): void
    {
        if (!$retryable || $attempt >= $this->retryAttempts) {
            if ($retryable) {
                $this->logger->error("[PaymentClient] {$reason}, giving up after {$attempt} attempts.", [
                    'status' => $exception->statusCode,
                    'url' => $url,
                ]);
            }

            throw $exception;
        }

        $this->logger->warning("[PaymentClient] {$reason}, retrying.", [
            'status' => $exception->statusCode,
            'attempt' => $attempt,
            'url' => $url,
        ]);

        $this->sleep();
    }

    private function makeApiException(BadResponseException $e): PaymentApiException
    {
        $response = $e->getResponse();
        $status = $response->getStatusCode();
        $body = $this->decode((string)$response->getBody());
        $retryAfter = $response->getHeaderLine('Retry-After');

        return new PaymentApiException(
            message: $body['message'] ?? "HTTP {$status} error.",
            statusCode: $status,
            responseBody: $body,
            previous: $e,
            retryAfter: is_numeric($retryAfter) ? (int)$retryAfter : null,
        );
    }

    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private function sleep(): void
    {
        if ($this->retrySleepMs > 0) {
            usleep($this->retrySleepMs * 1_000);
        }
    }
}
