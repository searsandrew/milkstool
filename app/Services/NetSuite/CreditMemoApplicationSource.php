<?php

namespace App\Services\NetSuite;

class CreditMemoApplicationSource
{
    public function __construct(private TransactionApplicationSource $source) {}

    /** @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    public function forCreditMemos(int $customerId, array $ids): array
    {
        $documents = $this->source->forTransactions($customerId, $ids, 'CustCred');
        foreach ($documents as &$rows) {
            foreach ($rows as &$row) {
                $row['credit_memo_id'] = $row['source_id'];
                $row['credit_line_id'] = $row['source_line_id'];
                unset($row['source_id'], $row['source_line_id']);
            }
            unset($row);
        }
        unset($rows);

        return $documents;
    }

    /** @return list<array<string, mixed>> */
    public function controlTotals(int $customerId): array
    {
        return $this->source->controlTotals($customerId, 'CustCred');
    }
}
