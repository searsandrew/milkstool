<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerSyncResource;
use App\Models\Company;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;

class CustomerInvoiceSummaryController extends Controller
{
    public function __invoke(Company $customer): JsonResponse
    {
        $currencies = $customer->transactions()->where('type', 'CustInvc')
            ->selectRaw('currency_id, COUNT(*) AS invoice_count, COUNT(foreign_amount_unpaid) AS known_count,
                SUM(CASE WHEN foreign_amount_unpaid > 0 THEN foreign_amount_unpaid ELSE 0 END) AS outstanding,
                SUM(CASE WHEN foreign_amount_unpaid > 0 THEN 1 ELSE 0 END) AS outstanding_count')
            ->groupBy('currency_id')->orderBy('currency_id')->toBase()->get()
            ->map(fn (object $row): array => [
                'currency_id' => (int) $row->currency_id,
                'invoice_count' => (int) $row->invoice_count,
                'outstanding_invoice_count' => (int) $row->outstanding_count,
                'unknown_unpaid_count' => (int) $row->invoice_count - (int) $row->known_count,
                'known_outstanding_amount' => (string) BigDecimal::of((string) $row->outstanding)->toScale(8, RoundingMode::HalfUp),
            ])->all();

        return response()->json([
            'data' => ['scope' => 'outstanding_invoices', 'currencies' => $currencies],
            'sync' => new CustomerSyncResource($customer),
        ]);
    }
}
