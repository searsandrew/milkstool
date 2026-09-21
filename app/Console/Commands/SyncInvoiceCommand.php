<?php

namespace App\Console\Commands;

use App\Actions\SyncInvoice;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Searsandrew\BriarRose\Exceptions\BriarRoseConfigurationException;

class SyncInvoiceCommand extends Command
{
    protected $signature = 'milkstool:sync-invoice {customer : NetSuite customer internal ID} {invoice : NetSuite invoice internal ID}';

    protected $description = 'Import one complete invoice and its lines (read-only in NetSuite)';

    public function handle(SyncInvoice $sync): int
    {
        $options = ['options' => ['min_range' => 1]];
        $customer = filter_var($this->argument('customer'), FILTER_VALIDATE_INT, $options);
        $invoiceId = filter_var($this->argument('invoice'), FILTER_VALIDATE_INT, $options);

        if ($customer === false || $invoiceId === false) {
            $this->error('Customer and invoice must be positive NetSuite internal IDs.');

            return self::FAILURE;
        }

        try {
            $invoice = $sync->handle($customer, $invoiceId);
        } catch (RequestException $exception) {
            $this->error('NetSuite returned HTTP '.$exception->response->status().'. Invoice import did not complete.');

            return self::FAILURE;
        } catch (ConnectionException) {
            $this->error('Could not connect to NetSuite. Invoice import did not complete.');

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('NetSuite returned invalid invoice data: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (BriarRoseConfigurationException|RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Invoice '.$invoice->netsuite_id.' imported with '.$invoice->lines()->count().' lines.');
        $this->line('Invoice currency ID: '.$invoice->currency_id.'; total: '.$invoice->foreign_total
            .'; paid: '.($invoice->foreign_amount_paid ?? 'unknown').'; unpaid: '.($invoice->foreign_amount_unpaid ?? 'unknown').'.');
        $this->line('Single-invoice snapshot only; customer-wide invoice freshness and account balance are not established.');

        return self::SUCCESS;
    }
}
