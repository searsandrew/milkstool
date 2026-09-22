<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshCreditMemos;
use App\Jobs\RefreshCustomerBalance;
use App\Jobs\RefreshInvoices;
use App\Jobs\RefreshPayments;
use App\Jobs\RefreshSalesOrders;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CustomerActivityController extends Controller
{
    public function __invoke(Company $customer): JsonResponse
    {
        abort_unless($customer->is_active, 409, 'This customer is inactive.');
        $customer->forceFill(['portal_last_active_at' => now()])->save();
        $requested = [];
        foreach (['balance' => RefreshCustomerBalance::class, 'sales_orders' => RefreshSalesOrders::class,
            'invoices' => RefreshInvoices::class, 'credit_memos' => RefreshCreditMemos::class, 'payments' => RefreshPayments::class] as $prefix => $job) {
            if (Company::query()->whereKey($customer->id)->dueForRefresh($prefix)->exists()) {
                $job::dispatch((int) $customer->netsuite_id);
                $requested[] = $prefix;
            }
        }

        return response()->json(['data' => [
            'active_until' => $customer->portal_last_active_at->addDay()->utc()->toIso8601String(),
            'refreshes_requested' => $requested,
        ]], 202);
    }
}
