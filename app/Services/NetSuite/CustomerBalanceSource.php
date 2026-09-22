<?php

namespace App\Services\NetSuite;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Searsandrew\BriarRose\BriarRoseManager;
use Throwable;

class CustomerBalanceSource
{
    private const array AMOUNTS = [
        'balance' => 'balance', 'overdueBalance' => 'overdue_balance',
        'unbilledOrders' => 'unbilled_orders', 'depositBalance' => 'deposit_balance',
        'consolBalance' => 'consolidated_balance', 'consolOverdueBalance' => 'consolidated_overdue_balance',
        'consolUnbilledOrders' => 'consolidated_unbilled_orders', 'consolDepositBalance' => 'consolidated_deposit_balance',
    ];

    public function __construct(private BriarRoseManager $briarRose) {}

    /** @return array<string, mixed> */
    public function find(int $customerId): array
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
        }
        $record = retry(3, fn (): mixed => $this->briarRose->rest()->record('customer')
            ->getFields($customerId, ['id', 'currency', 'subsidiary', ...array_keys(self::AMOUNTS)])
            ->throw()->json(), 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException);
        $rules = [
            'id' => ['required', 'integer', 'in:'.$customerId],
            'currency' => ['nullable', 'array'], 'currency.id' => ['required_with:currency', 'integer', 'min:1'],
            'subsidiary' => ['nullable', 'array'], 'subsidiary.id' => ['required_with:subsidiary', 'integer', 'min:1'],
        ];
        foreach (self::AMOUNTS as $source => $target) {
            $rules[$source] = [in_array($source, ['balance', 'overdueBalance', 'unbilledOrders'], true) ? 'required' : 'nullable', 'numeric'];
        }
        Validator::make((array) $record, $rules)->validate();
        $snapshot = [
            'currency_id' => isset($record['currency']['id']) ? (int) $record['currency']['id'] : null,
            'subsidiary_id' => isset($record['subsidiary']['id']) ? (int) $record['subsidiary']['id'] : null,
        ];
        foreach (self::AMOUNTS as $source => $target) {
            $snapshot[$target] = isset($record[$source])
                ? (string) BigDecimal::of((string) $record[$source])->toScale(8, RoundingMode::Unnecessary) : null;
        }

        return $snapshot;
    }
}
