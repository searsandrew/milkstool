<?php

use App\Http\Controllers\Api\V1\CustomerInvoiceSummaryController;
use App\Http\Controllers\Api\V1\CustomerTransactionController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Middleware\AuthorizeCustomerRead;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('auth:sanctum')->group(function (): void {
    Route::get('status', StatusController::class)
        ->middleware('abilities:status:read')
        ->name('status');
});

Route::prefix('v1/customers/{customer:netsuite_id}')->name('api.v1.customers.')
    ->whereNumber('customer')
    ->middleware(['auth:sanctum', 'abilities:transactions:read', AuthorizeCustomerRead::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::get('transactions', [CustomerTransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{transaction}', [CustomerTransactionController::class, 'show'])
            ->whereNumber('transaction')->name('transactions.show');
        Route::get('invoice-summary', CustomerInvoiceSummaryController::class)->name('invoice-summary');
    });
