<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('milkstool:dispatch-sales-order-refreshes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));

Schedule::command('milkstool:sync-customers --queue')
    ->hourly()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));

Schedule::command('milkstool:dispatch-invoice-refreshes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));

Schedule::command('milkstool:dispatch-credit-memo-refreshes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));

Schedule::command('milkstool:dispatch-balance-refreshes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));

Schedule::command('milkstool:dispatch-payment-refreshes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));

Schedule::command('milkstool:heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));
