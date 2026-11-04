<?php

namespace App\Console\Commands;

use App\Models\GatewayLog;
use Illuminate\Console\Command;

class PruneGatewayLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'passwire:prune-logs
                            {--days= : Retention threshold in days (overrides GATEWAY_LOG_RETENTION_DAYS)}
                            {--chunk= : Chunk size for batch deletion (overrides GATEWAY_LOG_PRUNE_CHUNK_SIZE)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune historical gateway request logs older than the configured retention period.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('gateway.log_retention_days', 30);

        if ($days <= 0) {
            $this->warn('Log retention is disabled (days <= 0). No logs were pruned.');
            return self::SUCCESS;
        }

        $chunkSize = $this->option('chunk') !== null
            ? (int) $this->option('chunk')
            : (int) config('gateway.log_prune_chunk_size', 1000);

        $cutoff = now()->subDays($days)->toDateTimeString();

        $this->info("Pruning gateway logs older than {$days} days (before {$cutoff}) in chunks of {$chunkSize}...");

        $totalDeleted = GatewayLog::pruneOlderThan($days, $chunkSize);

        $this->info("Successfully pruned {$totalDeleted} old gateway log record(s).");

        return self::SUCCESS;
    }
}

