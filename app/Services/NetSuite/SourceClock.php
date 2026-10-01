<?php

namespace App\Services\NetSuite;

use App\Exceptions\ReceivableSyncInterrupted;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SourceClock
{
    public function __construct(private SuiteQlClient $client) {}

    public function currentTime(): CarbonImmutable
    {
        try {
            $page = $this->client->query("SELECT TO_CHAR(SYS_EXTRACT_UTC(CURRENT_TIMESTAMP), 'YYYY-MM-DD HH24:MI:SS') AS current_time FROM DUAL");
        } catch (ValidationException|RuntimeException $exception) {
            throw new ReceivableSyncInterrupted('NetSuite did not return a reliable source clock.', 0, $exception);
        }

        if (count($page['items']) !== 1 || $page['hasMore']) {
            throw new ReceivableSyncInterrupted('NetSuite did not return a reliable source clock.');
        }

        if (Validator::make($page['items'][0], [
            'current_time' => ['required', 'date_format:Y-m-d H:i:s'],
        ])->fails()) {
            throw new ReceivableSyncInterrupted('NetSuite did not return a reliable source clock.');
        }

        return CarbonImmutable::parse($page['items'][0]['current_time'], 'UTC');
    }
}
