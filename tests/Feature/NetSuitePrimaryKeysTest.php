<?php

use App\Models\Company;
use App\Models\Transaction;
use App\Models\TransactionLine;
use Illuminate\Support\Facades\Schema;

it('uses nonsequential source IDs as primary keys and relationship foreign keys', function () {
    $company = Company::factory()->create(['id' => 90016]);
    $transaction = Transaction::factory()->for($company)->create(['id' => 731347]);
    $line = TransactionLine::factory()->for($transaction)->create();
    $other = Company::factory()->create(['id' => 16]);

    expect($transaction->fresh()->company_id)->toBe(90016)
        ->and($line->fresh()->transaction_id)->toBe(731347)
        ->and($transaction->company->getKey())->toBe(90016)
        ->and($company->transactions()->sole()->getKey())->toBe(731347)
        ->and($other->getKey())->toBe(16)
        ->and(Schema::hasColumn('companies', 'netsuite_id'))->toBeFalse()
        ->and(Schema::hasColumn('transactions', 'netsuite_id'))->toBeFalse();

    foreach (['companies', 'transactions'] as $table) {
        $primary = collect(Schema::getColumns($table))->firstWhere('name', 'id');
        expect($primary['auto_increment'])->toBeFalse();
    }
});
