<?php

namespace App\Services\NetSuite;

use App\Exceptions\ItemSourceUnavailable;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItemQueryClient extends NetSuiteRestClient
{
    private ?float $deadline = null;

    /** @return list<array<string, mixed>> */
    public function all(string $sql): array
    {
        $items = [];
        $offset = 0;
        do {
            $page = $this->page($sql, $offset);
            array_push($items, ...$page['items']);
            $offset += 1000;
            if ($page['hasMore'] && $offset >= 100000) {
                throw new ItemSourceUnavailable('NetSuite item query exceeded the supported result window.');
            }
        } while ($page['hasMore']);

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function unitsType(int $id): ?array
    {
        $url = $this->buildUrl('/services/rest/record/v1/unitsType/'.$id);
        $query = ['expandSubResources' => 'true'];
        try {
            $remaining = $this->remainingTime();
            $response = $this->http()->timeout($remaining)->connectTimeout(min(3, $remaining))
                ->withHeaders([
                    'Authorization' => $this->getAuthHeader('GET', $url, $query),
                    'Accept' => 'application/json',
                ])->send('GET', $url, ['query' => $query]);
            if (in_array($response->status(), [403, 404], true)) {
                return null;
            }
            $record = $response->throw()->json();
            Validator::make(is_array($record) ? $record : [], [
                'id' => ['required', 'integer', 'in:'.$id],
                'uom.items' => ['present', 'array', 'list'],
                'uom.hasMore' => ['sometimes', 'boolean', 'declined'],
            ])->validate();
        } catch (ConnectionException|ConnectException|RequestException|ValidationException $exception) {
            throw new ItemSourceUnavailable('NetSuite inventory units query failed.', 0, $exception);
        }

        return $record;
    }

    private function remainingTime(): float
    {
        $this->deadline ??= microtime(true) + 10;
        $remaining = $this->deadline - microtime(true);
        if ($remaining <= 0) {
            throw new ItemSourceUnavailable('The live item lookup exceeded its request budget.');
        }

        return $remaining;
    }

    /** @return array{items: list<array<string, mixed>>, hasMore: bool} */
    private function page(string $sql, int $offset): array
    {
        $remaining = $this->remainingTime();
        $url = $this->buildUrl('/services/rest/query/v1/suiteql');
        $query = ['limit' => 1000, 'offset' => $offset];

        try {
            $page = $this->http()->timeout($remaining)->connectTimeout(min(3, $remaining))
                ->withHeaders([
                    'Authorization' => $this->getAuthHeader('POST', $url, $query),
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'Prefer' => 'transient',
                ])->send('POST', $url, ['query' => $query, 'json' => ['q' => $sql]])->throw()->json();
            Validator::make(is_array($page) ? $page : [], [
                'items' => ['present', 'array', 'list'],
                'items.*' => ['required', 'array'],
                'hasMore' => ['required', 'boolean'],
            ])->validate();
        } catch (ConnectionException|ConnectException|RequestException|ValidationException $exception) {
            throw new ItemSourceUnavailable('NetSuite item query failed.', 0, $exception);
        }
        if ($page['hasMore'] && count($page['items']) !== 1000) {
            throw new ItemSourceUnavailable('NetSuite returned an incomplete item query page.');
        }

        return ['items' => $page['items'], 'hasMore' => (bool) $page['hasMore']];
    }
}
