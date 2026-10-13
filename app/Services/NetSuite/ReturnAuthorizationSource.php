<?php

namespace App\Services\NetSuite;

use App\Exceptions\ReceivableSyncInterrupted;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class ReturnAuthorizationSource
{
    public function __construct(private SuiteQlClient $queries, private NetSuiteRestClient $records) {}

    /** @return Generator<int, array<string, mixed>> */
    public function headers(int $customerId): Generator
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('Customer ID must be positive.');
        }
        $lastId = 0;
        do {
            $page = $this->queries->query('SELECT id, entity AS customer_id, type, tranid AS number, externalid AS external_id, '
                ."TO_CHAR(trandate, 'YYYY-MM-DD') AS transaction_date, status, BUILTIN.DF(status) AS status_name, "
                .'currency AS currency_id, total, foreigntotal AS foreign_total, memo, '
                ."TO_CHAR(SYS_EXTRACT_UTC(lastmodifieddate), 'YYYY-MM-DD HH24:MI:SS') AS updated_at "
                ."FROM transaction WHERE entity = {$customerId} AND type = 'RtnAuth' AND id > {$lastId} ORDER BY id");
            foreach ($page['items'] as $header) {
                Validator::make($header, [
                    'id' => ['required', 'integer', 'gt:'.$lastId], 'customer_id' => ['required', 'integer', 'in:'.$customerId],
                    'type' => ['required', 'in:RtnAuth'], 'number' => ['required', 'string', 'max:255'],
                    'external_id' => ['nullable', 'string', 'max:255'], 'transaction_date' => ['required', 'date_format:Y-m-d'],
                    'status' => ['required', 'string', 'max:255'], 'status_name' => ['nullable', 'string', 'max:255'],
                    'currency_id' => ['required', 'integer', 'min:1'], 'total' => ['required', 'numeric'],
                    'foreign_total' => ['required', 'numeric'], 'memo' => ['nullable', 'string'],
                    'updated_at' => ['required', 'date_format:Y-m-d H:i:s'],
                ])->validate();
                $lastId = (int) $header['id'];
                ksort($header);
                yield $header;
            }
        } while ($page['hasMore']);
    }

    /** @param array<string, mixed> $header
     * @return list<array<string, mixed>>
     */
    public function lines(int $customerId, array $header): array
    {
        $first = $this->record($customerId, $header);
        if ($first !== $this->record($customerId, $header)) {
            throw new ReceivableSyncInterrupted('An RMA changed during retrieval. Retry the sync.');
        }

        return $first['lines'];
    }

    /** @param array<string, mixed> $header
     * @return array{modified: string, lines: list<array<string, mixed>>}
     */
    private function record(int $customerId, array $header): array
    {
        $record = $this->records->record('returnAuthorization')->get((int) $header['id'], ['expandSubResources' => 'true'])->throw()->json();
        Validator::make((array) $record, [
            'id' => ['required', 'integer', 'in:'.$header['id']], 'entity.id' => ['required', 'integer', 'in:'.$customerId],
            'currency.id' => ['required', 'integer', 'in:'.$header['currency_id']],
            'lastModifiedDate' => ['required', 'date'], 'total' => ['required', 'numeric'],
            'item.items' => ['required', 'array', 'list', 'min:1'],
            'item.hasMore' => ['sometimes', 'boolean'], 'item.totalResults' => ['sometimes', 'integer', 'min:0'],
            'item.items.*.line' => ['required', 'integer', 'min:0', 'distinct'],
            'item.items.*.item.id' => ['required', 'integer'], 'item.items.*.item.refName' => ['nullable', 'string'],
            'item.items.*.orderDoc.id' => ['nullable', 'integer', 'min:1'], 'item.items.*.orderLine' => ['nullable', 'integer', 'min:0'],
            'item.items.*.description' => ['nullable', 'string'], 'item.items.*.quantity' => ['nullable', 'numeric'],
            'item.items.*.rate' => ['nullable', 'numeric'], 'item.items.*.amount' => ['nullable', 'numeric'],
            'item.items.*.quantityReceived' => ['nullable', 'numeric'], 'item.items.*.quantityBilled' => ['nullable', 'numeric'],
            'item.items.*.isClosed' => ['nullable', 'boolean'],
        ])->validate();
        if (($record['item']['hasMore'] ?? false)
            || (isset($record['item']['totalResults']) && (int) $record['item']['totalResults'] !== count($record['item']['items']))) {
            throw new RuntimeException('NetSuite returned incomplete RMA lines. Existing lines were retained.');
        }
        $modified = CarbonImmutable::parse($record['lastModifiedDate'])->utc();
        if (! $modified->startOfMinute()->equalTo(CarbonImmutable::parse($header['updated_at'], 'UTC')->startOfMinute())
            || ! BigDecimal::of((string) $record['total'])->isEqualTo((string) $header['foreign_total'])) {
            throw new ReceivableSyncInterrupted('RMA record and listing describe different source versions. Retry the sync.');
        }
        $lines = [];
        foreach ($record['item']['items'] as $line) {
            if (isset($line['orderDoc']['id']) !== isset($line['orderLine'])) {
                throw new RuntimeException('An RMA line has an incomplete source transaction reference.');
            }
            $row = ['line_id' => (int) $line['line'], 'item_id' => (int) $line['item']['id'],
                'item_number' => $line['item']['refName'] ?? null, 'memo' => $line['description'] ?? null,
                'source_transaction_id' => isset($line['orderDoc']['id']) ? (int) $line['orderDoc']['id'] : null,
                'source_transaction_line_id' => isset($line['orderLine']) ? (int) $line['orderLine'] : null,
                'is_closed' => $line['isClosed'] ?? null];
            foreach (['quantity' => 'quantity', 'rate' => 'rate', 'amount' => 'amount',
                'quantityReceived' => 'quantity_received', 'quantityBilled' => 'quantity_billed'] as $source => $target) {
                $row[$target] = isset($line[$source])
                    ? (string) BigDecimal::of((string) $line[$source])->toScale(8, RoundingMode::Unnecessary) : null;
            }
            $lines[] = $row;
        }
        usort($lines, fn (array $a, array $b): int => $a['line_id'] <=> $b['line_id']);

        return ['modified' => $modified->toIso8601String(), 'lines' => $lines];
    }
}
