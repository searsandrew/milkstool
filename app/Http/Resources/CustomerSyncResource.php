<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $result = [];
        foreach (['sales_orders', 'invoices', 'credit_memos', 'payments'] as $prefix) {
            $success = $this->resource->{$prefix.'_synced_at'};
            $attempt = $this->resource->{$prefix.'_sync_started_at'};
            $backfilled = $this->resource->{$prefix.'_backfilled_at'} !== null;
            $failed = $this->resource->{$prefix.'_sync_error'} !== null;
            $unfinished = $attempt !== null && ($success === null || $attempt->gt($success));
            $due = $this->resource->refreshDueAt($prefix)?->lte(now()) ?? true;
            $result[$prefix] = [
                'last_success_at' => $success?->utc()->toIso8601String(),
                'last_attempt_at' => $attempt?->utc()->toIso8601String(),
                'history_backfilled' => $backfilled,
                'status' => match (true) {
                    $failed => 'failed',
                    $unfinished => 'unfinished_attempt',
                    $success === null => 'never_synced',
                    ! $backfilled || $due => 'stale',
                    default => 'current',
                },
            ];
        }

        return $result;
    }
}
