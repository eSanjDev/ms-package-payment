<?php

namespace Esanj\PaymentClient\Http;

use Esanj\PaymentClient\Contracts\TokenProviderInterface;
use Esanj\PaymentClient\Exceptions\PaymentApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ServerException;
use Psr\Log\LoggerInterface;

class ApiClient
{
    private const TOKEN_INVALID_STATUSES = [401, 403];

    public function __construct(
        private readonly Client $httpClient,
        private readonly TokenProviderInterface $tokenProvider,
        private readonly LoggerInterface $logger,
        private readonly string $baseUrl,
        private readonly int $retryAttempts,
        private readonly int $retrySleepMs,
    ) {}

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, array_filter(['query' => $query ?: null]));
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->retryAttempts; $attempt++) {
            try {
                $response = $this->httpClient->request($method, $url, array_merge($options, [
                    'headers' => [
                        'Authorization' => $this->tokenProvider->authorizationHeader(),
                        'Accept'        => 'application/json',
                    ],
                ]));

                return json_decode($response->getBody()->getContents(), true) ?? [];

            } catch (ClientException $e) {
                $status = $e->getResponse()->getStatusCode();
                $body   = json_decode($e->getResponse()->getBody()->getContents(), true) ?? [];

                // Only auth failures are retryable; everything else is a definitive answer.
                if (!in_array($status, self::TOKEN_INVALID_STATUSES, true) || $attempt >= $this->retryAttempts) {
                    if (in_array($status, self::TOKEN_INVALID_STATUSES, true)) {
                        $this->logger->error('[PaymentClient] Authentication failed after all retry attempts.', [
                            'status' => $status,
                            'url'    => $url,
                        ]);
                    }

                    throw $this->makeApiException($body, $status, $e);
                }

                // Token was rejected — invalidate it and retry with a fresh one.
                $this->logger->warning('[PaymentClient] Token rejected, refreshing and retrying.', [
                    'status'  => $status,
                    'attempt' => $attempt,
                    'url'     => $url,
                ]);

                $this->tokenProvider->invalidate();
                $lastException = $this->makeApiException($body, $status, $e);
                $this->sleep();

            } catch (ServerException $e) {
                $status = $e->getResponse()->getStatusCode();
                $body   = json_decode($e->getResponse()->getBody()->getContents(), true) ?? [];

                $this->logger->warning('[PaymentClient] Server error, retrying.', [
                    'status'  => $status,
                    'attempt' => $attempt,
                    'url'     => $url,
                ]);

                $lastException = $this->makeApiException($body, $status, $e, 'server error');

                if ($attempt < $this->retryAttempts) {
                    $this->sleep();
                }

            } catch (ConnectException $e) {
                $this->logger->warning('[PaymentClient] Connection error, retrying.', [
                    'attempt' => $attempt,
                    'url'     => $url,
                    'error'   => $e->getMessage(),
                ]);

                $lastException = new PaymentApiException(
                    'Connection error: ' . $e->getMessage(),
                    statusCode: 0,
                    previous: $e,
                );

                if ($attempt < $this->retryAttempts) {
                    $this->sleep();
                }

            } catch (GuzzleException $e) {
                $this->logger->error('[PaymentClient] Unexpected HTTP error.', [
                    'attempt' => $attempt,
                    'url'     => $url,
                    'error'   => $e->getMessage(),
                ]);

                throw new PaymentApiException('Unexpected error: ' . $e->getMessage(), statusCode: 0, previous: $e);
            }
        }

        $this->logger->error('[PaymentClient] All retry attempts exhausted.', [
            'url'      => $url,
            'attempts' => $this->retryAttempts,
        ]);

        throw $lastException ?? new PaymentApiException('Request failed after all retry attempts.', statusCode: 0);
    }

    private function makeApiException(array $body, int $status, GuzzleException $previous, string $kind = 'error'): PaymentApiException
    {
        return new PaymentApiException(
            message: $body['message'] ?? "HTTP {$status} {$kind}.",
            statusCode: $status,
            responseBody: $body,
            previous: $previous,
        );
    }

    private function sleep(): void
    {
        if ($this->retrySleepMs > 0) {
            usleep($this->retrySleepMs * 1_000);
        }
    }
}