<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();

Schedule::command('milkstool:dispatch-sales-order-refreshes')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->when(fn (): bool => config('netsuite-sync.scheduled'));
