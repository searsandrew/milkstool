<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');

require_once __DIR__.'/Fixtures/sales_orders.php';

require_once __DIR__.'/Fixtures/invoices.php';
