<?php

declare(strict_types=1);

namespace Keel\Queue\Transport;

use Psr\Http\Message\RequestInterface;

/** AWS Signature Version 4, so Keel talks to AWS APIs without the SDK. */
final readonly class SigV4
{
    public function __construct(
        private string $accessKey,
        private string $secretKey,
        private string $region,
        private string $service,
        private ?string $sessionToken = null,
    ) {
    }

    public function sign(RequestInterface $request, \DateTimeInterface $now): RequestInterface
    {
        $date = \DateTimeImmutable::createFromInterface($now)->setTimezone(new \DateTimeZone('UTC'));
        $amzDate = $date->format('Ymd\THis\Z');
        $day = $date->format('Ymd');
        $body = (string) $request->getBody();
        $request = $request->withHeader('X-Amz-Date', $amzDate)->withHeader('Host', $request->getUri()->getAuthority());
        if ($this->sessionToken !== null && $this->sessionToken !== '') {
            $request = $request->withHeader('X-Amz-Security-Token', $this->sessionToken);
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $lower = strtolower((string) $name);
            if ($lower === 'host' || $lower === 'content-type' || str_starts_with($lower, 'x-amz-')) {
                $headers[$lower] = preg_replace('/\s+/', ' ', trim(implode(',', $values)));
            }
        }
        ksort($headers);
        $signed = implode(';', array_keys($headers));
        $query = $request->getUri()->getQuery();
        parse_str($query, $params);
        ksort($params);
        $canonicalQuery = str_replace('+', '%20', http_build_query($params, '', '&', \PHP_QUERY_RFC3986));
        $path = $request->getUri()->getPath();
        $canonical = implode("\n", [
            $request->getMethod(),
            $path === '' ? '/' : implode('/', array_map(rawurlencode(...), array_map(rawurldecode(...), explode('/', $path)))),
            $canonicalQuery,
            implode('', array_map(static fn (string $k, mixed $v): string => "$k:$v\n", array_keys($headers), $headers)),
            $signed,
            hash('sha256', $body),
        ]);
        $scope = "$day/{$this->region}/{$this->service}/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $this->service, hash_hmac('sha256', $this->region, hash_hmac('sha256', $day, 'AWS4' . $this->secretKey, true), true), true), true);

        return $request->withHeader('Authorization', "AWS4-HMAC-SHA256 Credential={$this->accessKey}/$scope, SignedHeaders=$signed, Signature=" . hash_hmac('sha256', $toSign, $key));
    }
}
