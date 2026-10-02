<?php

namespace App\Jobs;

trait WaitsForNetSuite
{
    public int $tries = 0;

    public int $maxExceptions = 3;

    /** @return list<NetSuiteCooldown> */
    public function middleware(): array
    {
        return [new NetSuiteCooldown];
    }
}
