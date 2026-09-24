<?php

use App\Http\Controllers\Api\V1\CustomerActivityController;
use App\Http\Controllers\Api\V1\CustomerBalanceController;
use App\Http\Controllers\Api\V1\CustomerInvoiceSummaryController;
use App\Http\Controllers\Api\V1\CustomerOrderRefreshController;
use App\Http\Controllers\Api\V1\CustomerTransactionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StatusController;
use App\Http\Middleware\AuthorizeCustomerRead;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->middleware('auth:sanctum')->group(function (): void {
    Route::get('health', HealthController::class)
        ->middleware(['abilities:status:read', 'throttle:60,1'])->name('health');
    Route::get('status', StatusController::class)
        ->middleware('abilities:status:read')
        ->name('status');
});

Route::prefix('v1/customers/{customer:id}')->name('api.v1.customers.')
    ->whereNumber('customer')
    ->middleware(['auth:sanctum', 'abilities:transactions:read', AuthorizeCustomerRead::class, 'throttle:120,1'])
    ->group(function (): void {
        Route::get('transactions', [CustomerTransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{transaction}', [CustomerTransactionController::class, 'show'])
            ->whereNumber('transaction')->name('transactions.show');
        Route::get('balance', CustomerBalanceController::class)->name('balance');
        Route::get('invoice-summary', CustomerInvoiceSummaryController::class)->name('invoice-summary');
    });

Route::post('v1/customers/{customer:id}/activity', CustomerActivityController::class)
    ->whereNumber('customer')
    ->middleware(['auth:sanctum', 'abilities:activity:write', AuthorizeCustomerRead::class, 'throttle:30,1'])
    ->name('api.v1.customers.activity');

Route::post('v1/customers/{customer:id}/order-refreshes', CustomerOrderRefreshController::class)
    ->whereNumber('customer')
    ->middleware(['auth:sanctum', 'abilities:orders:refresh', AuthorizeCustomerRead::class, 'throttle:10,1'])
    ->name('api.v1.customers.order-refreshes');
