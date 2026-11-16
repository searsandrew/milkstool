<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\SuiteQlClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class FlagMissingSourceTransactions
{
    public function __construct(private SuiteQlClient $source) {}

    /**
     * Call only after a complete source scan, while holding the customer/type sync lock.
     *
     * @param  list<int>  $sourceIds
     */
    public function handle(Company $company, string $type, array $sourceIds): int
    {
        Validator::make(['type' => $type, 'ids' => $sourceIds], [
            'type' => ['required', 'in:SalesOrd,CustPymt'], 'ids' => ['present', 'array', 'list'],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ])->validate();
        $candidates = $company->transactions()->where('type', $type)->whereNotIn('id', $sourceIds)->get();
        $found = [];
        foreach (array_chunk($candidates->modelKeys(), 50) as $batch) {
            $ids = implode(',', array_map(intval(...), $batch));
            $page = $this->source->query('SELECT id, entity AS customer_id, type FROM transaction WHERE id IN ('.$ids.')');
            if ($page['hasMore']) {
                throw new RuntimeException('Source presence verification was incomplete; no missing records were flagged.');
            }
            Validator::make($page, ['items.*.id' => ['required', 'integer', 'distinct', 'in:'.$ids],
                'items.*.customer_id' => ['required', 'integer'], 'items.*.type' => ['required', 'string']])->validate();
            foreach ($page['items'] as $row) {
                if ((int) $row['customer_id'] === (int) $company->id && $row['type'] === $type) {
                    throw new RuntimeException('A record omitted by the scan still exists in NetSuite; retry the complete scan.');
                }
                $found[(int) $row['id']] = $row;
            }
        }

        return DB::transaction(function () use ($candidates, $company, $type, $found): int {
            $count = 0;
            foreach ($candidates as $candidate) {
                $record = Transaction::query()->whereKey($candidate->id)->where('company_id', $company->id)->where('type', $type)->lockForUpdate()->first();
                if ($record === null) {
                    continue;
                }
                $now = now();
                $record->source_missing_at = $now;
                $record->source_review_required_at ??= $now;
                $record->source_review_reason = isset($found[$record->id]) ? 'source_identity_changed' : 'missing_from_source';
                $record->source_review_context = [...($record->source_review_context ?? []),
                    'last_detected_at' => $now->utc()->toIso8601String(), 'detection' => 'complete_scan_and_id_lookup',
                    'last_synced_at' => $record->synced_at?->utc()->toIso8601String(),
                    'source_identity' => $found[$record->id] ?? null];
                $record->save();
                $count++;
            }

            return $count;
        });
    }
}
