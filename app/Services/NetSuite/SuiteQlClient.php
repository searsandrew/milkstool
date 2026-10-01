<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Validator;
use RuntimeException;

class SuiteQlClient
{
    public function __construct(private NetSuiteRestClient $client) {}

    /** @return array{items: list<array<string, mixed>>, hasMore: bool} */
    public function query(string $sql): array
    {
        $page = $this->client->suiteql()->query($sql, ['limit' => 1000])->throw()->json();
        Validator::make((array) $page, [
            'items' => ['present', 'array', 'list'],
            'items.*' => ['required', 'array'],
            'hasMore' => ['required', 'boolean'],
        ])->validate();

        if ($page['hasMore'] && $page['items'] === []) {
            throw new RuntimeException('NetSuite returned an empty page with more results pending.');
        }

        return ['items' => $page['items'], 'hasMore' => (bool) $page['hasMore']];
    }
}
