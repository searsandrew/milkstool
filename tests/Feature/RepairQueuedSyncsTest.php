<?php

use App\Jobs\RefreshInvoices;
use App\Jobs\RefreshSubmittedOrder;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('repairs old waiting invoice payloads while preserving cursors and reserved jobs', function () {
    fakeNetSuiteConfiguration();
    config()->set('netsuite-sync.invoice_history_queue', 'invoice-history');
    $company = Company::factory()->create(['id' => 16, 'invoices_import_state' => ['cursor' => 27924, 'invoices' => 200]]);
    Company::factory()->create(['id' => 17]);
    RefreshInvoices::dispatch(16);
    RefreshInvoices::dispatch(17);
    RefreshSubmittedOrder::dispatch(16, 101);
    $row = DB::table('jobs')->where('queue', 'invoice-history')->first();
    $payload = json_decode($row->payload, true);
    $job = unserialize($payload['data']['command']);
    $job->tries = 3;
    $job->onQueue('invoices');
    $payload['data']['command'] = serialize($job);
    $payload['maxTries'] = 3;
    $payload['retryUntil'] = now()->subHour()->timestamp;
    DB::table('jobs')->where('id', $row->id)->update(['queue' => 'invoices', 'payload' => json_encode($payload), 'attempts' => 2]);
    DB::table('jobs')->where('queue', 'invoice-history')->update(['reserved_at' => now()->timestamp]);
    $before = DB::table('jobs')->orderBy('id')->get();

    $this->artisan('milkstool:repair-queued-syncs', ['--dry-run' => true])->expectsOutput('1 waiting jobs would be updated.')->assertSuccessful();
    expect(DB::table('jobs')->orderBy('id')->get())->toEqual($before);
    $this->artisan('milkstool:repair-queued-syncs')->expectsOutput('1 waiting jobs updated.')->assertSuccessful();
    $this->artisan('milkstool:repair-queued-syncs')->expectsOutput('0 waiting jobs updated.')->assertSuccessful();

    $repaired = DB::table('jobs')->where('id', $row->id)->first();
    expect($repaired->queue)->toBe('invoice-history');
    expect($repaired->attempts)->toBe(2);
    expect(json_decode($repaired->payload, true)['retryUntil'])->toBeNull();
    expect(unserialize(json_decode($repaired->payload, true)['data']['command'])->tries)->toBe(0);
    expect($company->refresh()->invoices_import_state)->toBe(['cursor' => 27924, 'invoices' => 200]);
    expect(DB::table('jobs')->where('id', '!=', $row->id)->orderBy('id')->get())->toEqual($before->where('id', '!=', $row->id)->values());
    Http::assertNothingSent();
});
