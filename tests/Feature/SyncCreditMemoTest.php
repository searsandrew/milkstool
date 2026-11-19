<?php

use App\Actions\SyncCreditMemo;
use App\Exceptions\ReceivableSyncInterrupted;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\NetSuite\CreditMemoApplicationSource;
use App\Services\NetSuite\CreditMemoSource;
use App\Services\NetSuite\CreditMemoSummarySource;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->company = Company::factory()->create(['id' => 16, 'credit_memos_synced_at' => '2026-01-01 00:00:00']);
    $this->memo = Transaction::factory()->for($this->company)->create(['id' => 1347, 'type' => 'CustCred']);
    $this->header = ['id' => 1347, 'type' => 'CustCred', 'number' => 'CM-TARGET', 'currency_id' => 1,
        'transaction_date' => '2026-11-19', 'updated_at' => '2026-11-19 00:00:00', 'total' => '-10.00000000', 'foreign_total' => '-10.00000000'];
});

it('updates only the requested memo without advancing bulk sync checkpoints', function () {
    $other = Transaction::factory()->for($this->company)->create(['type' => 'CustCred']);
    $before = $other->fresh()->getAttributes();
    $source = $this->mock(CreditMemoSource::class);
    $source->shouldReceive('creditMemo')->twice()->with(16, 1347)->andReturn($this->header);
    $source->shouldReceive('linesForCreditMemos')->once()->with(16, [1347])->andReturn([1347 => [
        ['line_id' => 1, 'quantity' => '2.00000000', 'amount' => '10.00000000', 'mainline' => 'F', 'taxline' => 'F', 'discount_line' => 'F'],
    ]]);
    $this->mock(CreditMemoSummarySource::class)->shouldReceive('forCreditMemo')->twice()->with(16, $this->header)
        ->andReturn(['credit_applied' => '0.00000000', 'credit_remaining' => '10.00000000']);
    $this->mock(CreditMemoApplicationSource::class)->shouldReceive('forCreditMemos')->twice()->with(16, [1347])->andReturn([1347 => []]);
    $memo = app(SyncCreditMemo::class)->handle(16, 1347);
    expect($memo->number)->toBe('CM-TARGET')->and($memo->lines()->sole()->quantity)->toBe('2.00000000')
        ->and($other->fresh()->getAttributes())->toBe($before)
        ->and($this->company->fresh()->credit_memos_synced_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00');
    expect(Cache::lock('netsuite-credit-memos:16', 600)->get())->toBeTrue();
});

it('retains the existing memo when the source changes during retrieval', function () {
    $before = $this->memo->fresh()->getAttributes();
    $source = $this->mock(CreditMemoSource::class);
    $source->shouldReceive('creditMemo')->twice()->with(16, 1347)->andReturn($this->header, [...$this->header, 'number' => 'CHANGED']);
    $source->shouldReceive('linesForCreditMemos')->once()->andReturn([1347 => []]);
    $this->mock(CreditMemoSummarySource::class)->shouldReceive('forCreditMemo')->once()->andReturn([]);
    $this->mock(CreditMemoApplicationSource::class)->shouldReceive('forCreditMemos')->twice()->andReturn([1347 => []]);
    expect(fn () => app(SyncCreditMemo::class)->handle(16, 1347))->toThrow(ReceivableSyncInterrupted::class);
    expect($this->memo->fresh()->getAttributes())->toBe($before);
    expect(Cache::lock('netsuite-credit-memos:16', 600)->get())->toBeTrue();
});

it('defers to an existing sync for the same customer', function () {
    Cache::lock('netsuite-credit-memos:16', 600)->get();
    $this->mock(CreditMemoSource::class)->shouldNotReceive('creditMemo');
    expect(fn () => app(SyncCreditMemo::class)->handle(16, 1347))->toThrow(ReceivableSyncInterrupted::class);
});
