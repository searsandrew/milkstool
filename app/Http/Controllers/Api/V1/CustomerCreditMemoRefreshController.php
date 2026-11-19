<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshRequestedCreditMemo;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerCreditMemoRefreshController extends Controller
{
    public function __invoke(Request $request, Company $customer): JsonResponse
    {
        abort_unless($customer->is_active, 409, 'This customer is inactive.');
        $data = $request->validate(['credit_memo_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);
        $creditMemoId = (int) $data['credit_memo_id'];
        $existing = Transaction::query()->where('company_id', $customer->id)->where('type', 'CustCred')->findOrFail($creditMemoId);
        $customer->forceFill(['portal_last_active_at' => now()])->save();
        RefreshRequestedCreditMemo::dispatch((int) $customer->id, $creditMemoId);

        return response()->json(['data' => ['credit_memo_id' => $creditMemoId, 'status' => 'refresh_requested']], 202);
    }
}
