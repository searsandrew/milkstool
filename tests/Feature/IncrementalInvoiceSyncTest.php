<?php

use App\Actions\SyncInvoices;
use App\Models\Company;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    fakeNetSuiteConfiguration();
    $this->travelTo('2026-09-16 12:00:00');
});

it('bootstraps a checkpoint and subsequently scans only an overlapping change window', function () {
    $company = Company::factory()->create(['id' => 16]);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:10:00']]))
        ->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([])), true)]);

    app(SyncInvoices::class)->handle(16, incremental: true);
    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:58:00');
    app(SyncInvoices::class)->handle(16, incremental: true);

    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 12:08:00');
    expect($company->invoices_full_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 12:00:00');
    Http::assertSent(fn ($request) => str_contains($request['q'], ">= TO_TIMESTAMP('2026-09-16 11:53:00'")
        && str_contains($request['q'], "<= TO_TIMESTAMP('2026-09-16 12:08:00'"));
    Http::assertSentCount(11);
});

it('does not advance a checkpoint when the clock or incremental source is unreliable', function (array $clock, array $page) {
    $company = Company::factory()->create(['id' => 16, 'invoices_backfilled_at' => now()->subDay(), 'invoices_checkpoint_at' => '2026-09-16 10:00:00',
        'invoices_full_synced_at' => now(), 'invoices_synced_at' => '2026-09-16 10:02:00']);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push($clock)->push($page), true)]);

    $this->artisan('milkstool:sync-invoices', ['customer' => 16, '--incremental' => true])->assertFailed();

    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    expect($company->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:02:00');
    $this->assertDatabaseCount('transactions', 0);
})->with([
    'backwards clock' => [sourcePage([['current_time' => '2026-09-16 09:00:00']]), sourcePage([])],
    'missing clock' => [sourcePage([]), sourcePage([])],
    'out of window' => [sourcePage([['current_time' => '2026-09-16 12:00:00']]), sourcePage([sourceInvoice()])],
]);

it('repairs a reconciliation mismatch with a full scan before advancing its checkpoint', function () {
    $company = Company::factory()->create(['id' => 16, 'invoices_backfilled_at' => now()->subDay(), 'invoices_checkpoint_at' => '2026-09-16 10:00:00', 'invoices_full_synced_at' => now()->subDay()]);
    $headers = ['currency_id' => '1', 'invoice_count' => '1', 'paid_count' => '1', 'unpaid_count' => '1',
        'foreign_amount_paid' => '20', 'foreign_amount_unpaid' => '5.12345678', 'total' => '25.12345678', 'foreign_total' => '25.12345678'];
    $lines = ['currency_id' => '1', 'line_count' => '1', 'quantity_count' => '1', 'amount_count' => '1',
        'quantity' => '-2.5', 'amount' => '-25.12345678', 'detail_amount' => '-25.12345678'];
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([]))->push(sourcePage([]))->push(sourcePage([$headers]))->push(sourcePage([$lines]))
        ->push(sourcePage([sourceInvoice()]))->push(sourcePage([sourceInvoiceLine()]))->push(sourcePage([sourceInvoice()]))
        ->push(sourcePage([$headers]))->push(sourcePage([$lines])), true)]);

    $result = app(SyncInvoices::class)->handle(16, incremental: true);

    expect($result['invoices'])->toBe(1);
    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 11:58:00');
    expect($company->invoices_full_synced_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseHas('transactions', ['id' => 1347, 'foreign_amount_paid' => '20.00000000']);
    Http::assertSentCount(15);
});

it('retains missing invoices for investigation during an explicit full scan', function () {
    $company = Company::factory()->create(['id' => 16, 'invoices_backfilled_at' => now()->subDay(), 'invoices_checkpoint_at' => '2026-09-16 10:00:00',
        'invoices_full_synced_at' => now()->subWeek()]);
    $invoice = Transaction::factory()->for($company)->create(['type' => 'CustInvc']);
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage([])), true)]);

    $this->artisan('milkstool:sync-invoices', ['customer' => 16])->assertFailed();

    $this->assertModelExists($invoice);
    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    Http::assertSent(fn ($request) => str_contains($request['q'], 'AND id > 0 ORDER BY id') && ! str_contains($request['q'], 'TO_TIMESTAMP('));
});

it('keeps completed invoice batches without advancing the checkpoint when a later page fails', function () {
    $company = Company::factory()->create(['id' => 16, 'invoices_backfilled_at' => now()->subDay(), 'invoices_checkpoint_at' => '2026-09-16 10:00:00',
        'invoices_full_synced_at' => now(), 'invoices_synced_at' => '2026-09-16 10:02:00']);
    $invoices = array_map(fn (int $id): array => sourceInvoice(['id' => (string) $id, 'updated_at' => '2026-09-16 11:00:00']), range(1347, 1396));
    $lines = array_map(fn (int $id): array => sourceInvoiceLine(['transaction_id' => (string) $id]), range(1347, 1396));
    Http::fake(['https://netsuite.example/*' => fakeCompleteInvoiceReads(Http::sequence()
        ->push(sourcePage([sourceCustomer()]))->push(sourcePage([['current_time' => '2026-09-16 12:00:00']]))
        ->push(sourcePage($invoices, true))->push(sourcePage($lines))->push(sourcePage($invoices))->push([], 503), true)]);

    $this->artisan('milkstool:sync-invoices', ['customer' => 16, '--incremental' => true])->assertFailed();

    expect($company->refresh()->invoices_checkpoint_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:00:00');
    expect($company->invoices_synced_at->format('Y-m-d H:i:s'))->toBe('2026-09-16 10:02:00');
    $this->assertDatabaseCount('transactions', 50);
    $this->assertDatabaseCount('transaction_lines', 50);
    Http::assertSentCount(110);
});
