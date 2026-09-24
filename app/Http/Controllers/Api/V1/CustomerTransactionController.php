<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerSyncResource;
use App\Http\Resources\TransactionResource;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class CustomerTransactionController extends Controller
{
    public function index(Request $request, Company $customer): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'type' => ['sometimes', 'in:SalesOrd,CustInvc,CustCred,CustPymt'],
            'outstanding' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', ...($request->has('from') ? ['after_or_equal:from'] : [])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'sort_by' => ['sometimes', 'in:number,date,status,amount'],
            'sort_direction' => ['sometimes', 'in:asc,desc'],
            'search' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        if ($request->boolean('outstanding') && isset($filters['type']) && $filters['type'] !== 'CustInvc') {
            throw ValidationException::withMessages(['outstanding' => 'Outstanding amounts are available for invoices only.']);
        }
        $query = $customer->transactions()->whereIn('type', ['SalesOrd', 'CustInvc', 'CustCred', 'CustPymt']);
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if ($request->boolean('outstanding')) {
            $query->where('type', 'CustInvc')->where('foreign_amount_unpaid', '>', 0);
        }
        if (isset($filters['from'])) {
            $query->where('transaction_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('transaction_date', '<=', $filters['to']);
        }

        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($query) use ($pattern) {
                $query->whereRaw("number LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("purchase_order_number LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $sortColumns = ['number' => 'number', 'date' => 'transaction_date', 'status' => 'status_name', 'amount' => 'foreign_total'];
        $sortColumn = $sortColumns[$filters['sort_by'] ?? 'date'];
        $direction = $filters['sort_direction'] ?? 'desc';

        return TransactionResource::collection($query->orderBy($sortColumn, $direction)->orderBy('id', $direction)
            ->paginate($filters['per_page'] ?? 25)->withQueryString())
            ->additional(['sync' => new CustomerSyncResource($customer)]);
    }

    public function show(Company $customer, string $transaction): TransactionResource
    {
        $document = $customer->transactions()->where('id', $transaction)
            ->whereIn('type', ['SalesOrd', 'CustInvc', 'CustCred', 'CustPymt'])
            ->with(['creditMemoApplications' => fn ($query) => $query->where('target_customer_id', $customer->id)
                ->orderBy('credit_line_id')->orderBy('target_netsuite_id')->orderBy('target_line_id'), 'paymentApplications' => fn ($query) => $query->where('target_customer_id', $customer->id)
                ->orderBy('payment_line_id')->orderBy('target_netsuite_id')->orderBy('target_line_id'), 'lines' => fn ($query) => $query->orderBy('netsuite_line_id')])->firstOrFail();

        if ($document->type === 'CustInvc') {
            $document->setRelation('salesOrders', $customer->transactions()
                ->where('type', 'SalesOrd')
                ->whereIn('id', $document->lines->pluck('source_transaction_id')->filter()->unique())
                ->orderBy('id')->get(['id', 'number']));
            foreach (['appliedPayments' => 'CustPymt', 'appliedCredits' => 'CustCred'] as $relation => $type) {
                $document->load([$relation => fn ($query) => $query
                    ->where('target_customer_id', $customer->id)
                    ->where('target_type', 'CustInvc')
                    ->whereHas('transaction', fn ($source) => $source->where('company_id', $customer->id)->where('type', $type))
                    ->with('transaction:id,type,number,transaction_date,currency_id,synced_at')
                    ->orderBy('transaction_id')->orderBy('target_line_id')->orderBy('id')]);
            }
        }

        return (new TransactionResource($document))->additional(['sync' => new CustomerSyncResource($customer)]);
    }
}
