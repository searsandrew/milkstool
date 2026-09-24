<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshSubmittedOrder;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerOrderRefreshController extends Controller
{
    public function __invoke(Request $request, Company $customer): JsonResponse
    {
        abort_unless($customer->is_active, 409, 'This customer is inactive.');
        $data = $request->validate(['sales_order_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);
        $orderId = (int) $data['sales_order_id'];
        $existing = Transaction::query()->where('id', $orderId)->first(['company_id', 'type']);
        abort_if($existing !== null && ($existing->company_id !== $customer->id || $existing->type !== 'SalesOrd'), 404);
        $customer->forceFill(['portal_last_active_at' => now()])->save();
        RefreshSubmittedOrder::dispatch((int) $customer->id, $orderId);

        return response()->json(['data' => ['sales_order_id' => $orderId, 'status' => 'refresh_requested']], 202);
    }
}
