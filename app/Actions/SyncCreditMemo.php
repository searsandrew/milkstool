<?php

namespace App\Actions;

use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\CreditMemoApplicationSource;
use App\Services\NetSuite\CreditMemoSource;
use App\Services\NetSuite\CreditMemoSummarySource;
use Illuminate\Support\Facades\Cache;

class SyncCreditMemo
{
    public function __construct(private CreditMemoSource $source, private CreditMemoSummarySource $summaries,
        private CreditMemoApplicationSource $applications, private StoreCreditMemo $store) {}

    public function handle(int $customerId, int $creditMemoId): Transaction
    {
        $company = Company::query()->where('is_active', true)->findOrFail($customerId);
        $lock = Cache::lock('netsuite-credit-memos:'.$customerId, 600);
        if (! $lock->get()) {
            throw new ReceivableSyncInterrupted('A credit memo sync is already running for this customer.');
        }
        try {
            $header = $this->source->creditMemo($customerId, $creditMemoId);
            $lines = $this->source->linesForCreditMemos($customerId, [$creditMemoId]);
            $summary = $this->summaries->forCreditMemo($customerId, $header);
            $applications = $this->applications->forCreditMemos($customerId, [$creditMemoId]);
            if ($applications != $this->applications->forCreditMemos($customerId, [$creditMemoId])
                || $header != $this->source->creditMemo($customerId, $creditMemoId)
                || $summary !== $this->summaries->forCreditMemo($customerId, $header)) {
                throw new ReceivableSyncInterrupted('The credit memo changed during retrieval. Retry the refresh.');
            }
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ReceivableSyncInterrupted('The credit memo sync lock expired. Retry the refresh.');
            }

            return $this->store->handle($company, [...$header, ...$summary], $lines[$creditMemoId], $applications[$creditMemoId]);
        } finally {
            $lock->release();
        }
    }
}
