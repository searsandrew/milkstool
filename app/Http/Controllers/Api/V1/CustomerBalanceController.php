<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CustomerBalanceController extends Controller
{
    public function __invoke(Company $customer): JsonResponse
    {
        $success = $customer->balance_synced_at;
        $attempt = $customer->balance_sync_started_at;
        $due = $customer->refreshDueAt('balance')?->lte(now()) ?? true;

        return response()->json([
            'data' => $customer->account_balance_snapshot,
            'source' => 'netsuite_customer_record',
            'sync' => [
                'last_success_at' => $success?->utc()->toIso8601String(),
                'last_attempt_at' => $attempt?->utc()->toIso8601String(),
                'status' => match (true) {
                    $customer->balance_sync_error !== null => 'failed',
                    $attempt !== null && ($success === null || $attempt->gt($success)) => 'unfinished_attempt',
                    $success === null => 'never_synced',
                    $due => 'stale',
                    default => 'current',
                },
            ],
        ]);
    }
}
