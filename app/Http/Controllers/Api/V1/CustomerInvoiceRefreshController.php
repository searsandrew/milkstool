<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\RefreshRequestedInvoice;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerInvoiceRefreshController extends Controller
{
    public function __invoke(Request $request, Company $customer): JsonResponse
    {
        abort_unless($customer->is_active, 409, 'This customer is inactive.');
        $data = $request->validate(['invoice_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX]]);
        $invoiceId = (int) $data['invoice_id'];
        $existing = Transaction::query()->where('company_id', $customer->id)->where('type', 'CustInvc')->findOrFail($invoiceId);
        $customer->forceFill(['portal_last_active_at' => now()])->save();
        RefreshRequestedInvoice::dispatch((int) $customer->id, $invoiceId);

        return response()->json(['data' => ['invoice_id' => $invoiceId, 'status' => 'refresh_requested']], 202);
    }
}
