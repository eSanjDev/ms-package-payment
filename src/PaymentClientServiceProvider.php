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
            $config = $app['config'];

            return new AuthBridgeTokenProvider(
                credentials:  $app->make(ClientCredentialsServiceInterface::class),
                clientId:     (string) $config->get('esanj.payment.client_id'),
                clientSecret: (string) $config->get('esanj.payment.client_secret'),
                scope:        $config->get('esanj.payment.scope'),
            );
        });

        $this->app->singleton(PaymentClientInterface::class, function ($app) {
            $config = $app['config'];

            $logChannel = $config->get('esanj.payment.logging.channel');
            $logger = $logChannel
                ? $app['log']->channel($logChannel)
                : $app[LoggerInterface::class];

            $apiClient = new ApiClient(
                httpClient:    new Client([
                    'timeout'         => (int) $config->get('esanj.payment.timeout', 30),
                    'connect_timeout' => 10,
                ]),
                tokenProvider: $app[TokenProviderInterface::class],
                logger:        $logger,
                baseUrl:       (string) $config->get('esanj.payment.base_url', 'http://localhost'),
                retryAttempts: max(1, (int) $config->get('esanj.payment.retry.attempts', 3)),
                retrySleepMs:  (int) $config->get('esanj.payment.retry.sleep_ms', 1000),
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
