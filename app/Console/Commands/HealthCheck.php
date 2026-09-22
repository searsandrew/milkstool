<?php

namespace App\Console\Commands;

use App\Services\ServiceHealth;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class HealthCheck extends Command
{
    protected $signature = 'milkstool:health {--deployment : Also require production environment, HTTPS, debug disabled, and a valid application key} {--json : Output machine-readable checks}';

    protected $description = 'Check local service dependencies and recent scheduler/worker heartbeats without contacting NetSuite';

    public function handle(ServiceHealth $health): int
    {
        $report = $health->report((bool) $this->option('deployment'));
        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->table(['Check', 'Result'], array_map(fn (array $check): array => [$check['name'], $check['healthy'] ? 'OK' : 'Needs attention'], $report['checks']));
            $this->line('Scheduler heartbeat must be within 3 minutes; each queue heartbeat within 25 minutes (allows a 20-minute import).');
            $this->line('Heartbeat checks show recent processing, not guaranteed future availability. NetSuite credentials are checked for presence only.');
        }

        return $report['healthy'] ? self::SUCCESS : self::FAILURE;
    }
}
