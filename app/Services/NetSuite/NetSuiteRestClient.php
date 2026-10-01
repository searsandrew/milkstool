<?php

namespace App\Services\NetSuite;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Searsandrew\BriarRose\Clients\RestClient;
use Throwable;

class NetSuiteRestClient extends RestClient
{
    public function __construct()
    {
        parent::__construct(
            account: (string) config('briar-rose.account'),
            consumerKey: (string) config('briar-rose.consumer_key'),
            consumerSecret: (string) config('briar-rose.consumer_secret'),
            tokenId: (string) config('briar-rose.token_id'),
            tokenSecret: (string) config('briar-rose.token_secret'),
            restBaseUrl: config('briar-rose.rest_base_url'),
            timeout: (int) config('briar-rose.timeout', 30),
            connectTimeout: (int) config('briar-rose.connect_timeout', 10),
            restOptions: (array) config('briar-rose.rest', []),
        );
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $path, array $options = []): Response
    {
        $safeToRetry = in_array(strtoupper($method), ['GET', 'HEAD'], true)
            || (strtoupper($method) === 'POST' && $path === '/services/rest/query/v1/suiteql');

        return retry($safeToRetry ? 3 : 1,
            fn (): Response => $this->sendOnce($method, $path, $options),
            250,
            fn (Throwable $exception): bool => $exception instanceof ConnectionException,
        );
    }

    /** @param array<string, mixed> $options */
    private function sendOnce(string $method, string $path, array $options): Response
    {
        $method = strtoupper($method);
        $url = $this->buildUrl($path);
        $query = $options['query'] ?? [];
        $headers = array_merge($options['headers'] ?? [], [
            'Authorization' => $this->getAuthHeader($method, $url, $query),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        try {
            return $this->http()->withHeaders($headers)->send($method, $url, array_intersect_key($options, array_flip(['query', 'json'])));
        } catch (ConnectException $exception) {
            throw new ConnectionException('Could not connect to NetSuite.', 0, $exception);
        }
    }
}
