<?php

namespace App\Services\NetSuite;

use Generator;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use RuntimeException;

class CustomerSource
{
    public function __construct(private SuiteQlClient $client) {}

    /** @return array<string, mixed> */
    public function find(int $customerId): array
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('NetSuite IDs must be positive integers.');
        }

        $page = $this->client->query($this->selectSql()." FROM customer WHERE id = {$customerId}");

        if (count($page['items']) !== 1 || $page['hasMore']) {
            throw new RuntimeException('NetSuite customer was not found or is not accessible.');
        }

        $customer = $page['items'][0];
        $this->validate($customer);
        Validator::make($customer, ['id' => ['in:'.$customerId]])->validate();

        return $customer;
    }

    /** @return Generator<int, array<string, mixed>> */
    public function all(): Generator
    {
        $lastId = 0;

        do {
            $page = $this->client->query($this->selectSql().", stage FROM customer WHERE stage = 'CUSTOMER' AND id > {$lastId} ORDER BY id");

            foreach ($page['items'] as $customer) {
                $this->validate($customer);
                Validator::make($customer, ['stage' => ['required', 'in:CUSTOMER']])->validate();

                if ((int) $customer['id'] <= $lastId) {
                    throw new RuntimeException('NetSuite customer pagination did not advance.');
                }

                $lastId = (int) $customer['id'];
                unset($customer['stage']);
                yield $customer;
            }
        } while ($page['hasMore']);
    }

    private function selectSql(): string
    {
        return 'SELECT id, companyname AS name, custentity3 AS account_number, salesrep AS sales_rep_id, isinactive, '
            ."TO_CHAR(SYS_EXTRACT_UTC(lastmodifieddate), 'YYYY-MM-DD HH24:MI:SS') AS updated_at";
    }

    /** @param array<string, mixed> $customer */
    private function validate(array $customer): void
    {
        Validator::make($customer, [
            'id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'sales_rep_id' => ['nullable', 'integer'],
            'isinactive' => ['required', 'in:T,F'],
            'updated_at' => ['required', 'date_format:Y-m-d H:i:s'],
        ])->validate();
    }
}
