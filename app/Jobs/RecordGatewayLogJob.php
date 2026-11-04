<?php

namespace App\Jobs;

use App\Models\GatewayLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordGatewayLogJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     *
     * @param array<string, mixed> $logData
     */
    public function __construct(
        public array $logData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        GatewayLog::create($this->logData);
    }
}

