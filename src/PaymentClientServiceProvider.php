<?php

namespace Esanj\PaymentClient;

use Esanj\AuthBridge\Contracts\ClientCredentialsServiceInterface;
use Esanj\PaymentClient\Auth\AuthBridgeTokenProvider;
use Esanj\PaymentClient\Contracts\PaymentClientInterface;
use Esanj\PaymentClient\Contracts\TokenProviderInterface;
use Esanj\PaymentClient\Http\ApiClient;
use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

class PaymentClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payment.php', 'esanj.payment');

        $this->app->singleton(TokenProviderInterface::class, function ($app) {
            $config = $app['config']['esanj']['payment'];

            return new AuthBridgeTokenProvider(
                credentials:  $app->make(ClientCredentialsServiceInterface::class),
                clientId:     (string) $config['client_id'],
                clientSecret: (string) $config['client_secret'],
                scope:        $config['scope'] ?? null,
            );
        });

        $this->app->singleton(PaymentClientInterface::class, function ($app) {
            $config = $app['config']['esanj']['payment'];

            $logChannel = $config['logging']['channel'] ?? null;
            $logger = $logChannel
                ? $app['log']->channel($logChannel)
                : $app[LoggerInterface::class];

            $apiClient = new ApiClient(
                httpClient:    new Client(['timeout' => $config['timeout'], 'connect_timeout' => 10]),
                tokenProvider: $app[TokenProviderInterface::class],
                logger:        $logger,
                baseUrl:       $config['base_url'],
                retryAttempts: max(1, (int) $config['retry']['attempts']),
                retrySleepMs:  (int) $config['retry']['sleep_ms'],
            );

            return new PaymentClient($apiClient);
        });

        $this->app->alias(PaymentClientInterface::class, PaymentClient::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/payment.php' => config_path('esanj/payment.php'),
            ], 'payment-config');
        }
    }
}