<?php

namespace App\Services\NetSuite;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class PaymentApplicationSource
{
    public function __construct(private SuiteQlClient $client) {}

    /** @param list<int> $paymentIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function forPayments(int $customerId, array $paymentIds): array
    {
        if ($customerId < 1 || $paymentIds === [] || count($paymentIds) > 50 || count(array_unique($paymentIds)) !== count($paymentIds)) {
            throw new InvalidArgumentException('Request a positive customer ID and 1 to 50 distinct payment IDs.');
        }
        foreach ($paymentIds as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Payment IDs must be positive integers.');
            }
        }
        $ids = implode(',', $paymentIds);
        $result = array_fill_keys($paymentIds, []);
        $cursor = [0, -1, 0, -1];
        do {
            [$payment, $paymentLine, $target, $targetLine] = $cursor;
            $page = $this->client->query(<<<SQL
                SELECT l.nextdoc AS payment_id, l.nextline AS payment_line_id,
                    l.previousdoc AS target_netsuite_id, l.previousline AS target_line_id,
                    target.entity AS target_customer_id, target.currency AS target_currency_id,
                    target.type AS target_type, l.foreignamount AS foreign_amount
                FROM NextTransactionLineLink l
                JOIN transaction payment ON payment.id = l.nextdoc
                LEFT JOIN transaction target ON target.id = l.previousdoc
                WHERE payment.entity = {$customerId} AND payment.type = 'CustPymt'
                    AND l.nextdoc IN ({$ids}) AND l.linktype = 'Payment'
                    AND (l.nextdoc > {$payment}
                        OR (l.nextdoc = {$payment} AND l.nextline > {$paymentLine})
                        OR (l.nextdoc = {$payment} AND l.nextline = {$paymentLine} AND l.previousdoc > {$target})
                        OR (l.nextdoc = {$payment} AND l.nextline = {$paymentLine} AND l.previousdoc = {$target} AND l.previousline > {$targetLine}))
                ORDER BY l.nextdoc, l.nextline, l.previousdoc, l.previousline
                SQL);
            foreach ($page['items'] as $row) {
                Validator::make($row, [
                    'payment_id' => ['required', 'integer', 'in:'.$ids],
                    'payment_line_id' => ['required', 'integer', 'min:0'],
                    'target_netsuite_id' => ['required', 'integer', 'min:1'],
                    'target_line_id' => ['required', 'integer', 'min:0'],
                    'target_customer_id' => ['nullable', 'integer', 'min:1'],
                    'target_currency_id' => ['nullable', 'integer', 'min:1'],
                    'target_type' => ['nullable', 'string', 'max:30'],
                    'foreign_amount' => ['nullable', 'numeric'],
                ])->validate();
                $next = [(int) $row['payment_id'], (int) $row['payment_line_id'], (int) $row['target_netsuite_id'], (int) $row['target_line_id']];
                if ($next <= $cursor) {
                    throw new RuntimeException('NetSuite payment application pagination did not advance.');
                }
                $cursor = $next;
                $result[$next[0]][] = $row;
            }
        } while ($page['hasMore']);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function controlTotals(int $customerId): array
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('Customer ID must be positive.');
        }
        $page = $this->client->query(<<<SQL
            SELECT payment.currency AS currency_id, COUNT(*) AS application_count,
                COUNT(l.foreignamount) AS application_amount_count,
                TO_CHAR(NVL(SUM(l.foreignamount), 0)) AS application_amount
            FROM NextTransactionLineLink l
            JOIN transaction payment ON payment.id = l.nextdoc
            WHERE payment.entity = {$customerId} AND payment.type = 'CustPymt' AND l.linktype = 'Payment'
            GROUP BY payment.currency ORDER BY payment.currency
            SQL);
        if ($page['hasMore']) {
            throw new RuntimeException('NetSuite application control totals were truncated.');
        }
        Validator::make($page, [
            'items.*.currency_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.application_count' => ['required', 'integer', 'min:1'],
            'items.*.application_amount_count' => ['required', 'integer', 'min:0'],
            'items.*.application_amount' => ['required', 'numeric'],
        ])->validate();

        return $page['items'];
    }
}
