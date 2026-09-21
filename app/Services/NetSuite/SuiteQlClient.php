<?php

namespace App\Services\NetSuite;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Searsandrew\BriarRose\BriarRoseManager;
use Throwable;

class SuiteQlClient
{
    public function __construct(private BriarRoseManager $briarRose) {}

    /** @return array{items: list<array<string, mixed>>, hasMore: bool} */
    public function query(string $sql): array
    {
        $page = retry(3,
            fn (): mixed => $this->briarRose->rest()->suiteql()->query($sql, ['limit' => 1000])->throw()->json(),
            500,
            fn (Throwable $exception): bool => $exception instanceof ConnectionException,
        );
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
