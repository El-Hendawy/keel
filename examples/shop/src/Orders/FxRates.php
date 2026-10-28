<?php

declare(strict_types=1);

namespace Shop\Orders;

use Keel\Container\Attribute\Config;
use Keel\Container\Attribute\Singleton;
use Nyholm\Psr7\Request;
use Psr\Http\Client\ClientInterface;

/** Exchange rates from an external API, through the framework's resilient client. */
#[Singleton]
final readonly class FxRates
{
    public function __construct(
        private ClientInterface $http,
        #[Config('fx.url')] private string $url,
    ) {
    }

    /** Units of $currency per one EUR. */
    public function rate(string $currency): float
    {
        if ($currency === 'EUR') {
            return 1.0;
        }
        $response = $this->http->sendRequest(new Request('GET', $this->url . '?from=EUR&to=' . rawurlencode($currency)));
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException("FX provider returned {$response->getStatusCode()}.");
        }
        /** @var array{rates?: array<string, float|int>} $body */
        $body = json_decode((string) $response->getBody(), true, 8, \JSON_THROW_ON_ERROR);

        return (float) ($body['rates'][$currency] ?? throw new \RuntimeException("No rate for $currency."));
    }
}
