<?php

namespace App\Console\Commands;

use App\Models\GatewayKey;
use App\Models\ProviderAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ResetDailyUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'passwire:reset-daily-usage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset daily token usage counters for provider accounts and gateway keys at midnight.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $today = now()->toDateString();

        $accountsReset = ProviderAccount::where(function ($query) use ($today) {
            $query->whereNull('usage_reset_date')
                ->orWhere('usage_reset_date', '<', $today);
        })->update([
            'tokens_used_today' => 0,
            'usage_reset_date' => $today,
        ]);

        $keysReset = GatewayKey::where(function ($query) use ($today) {
            $query->whereNull('usage_reset_date')
                ->orWhere('usage_reset_date', '<', $today);
        })->update([
            'tokens_used_today' => 0,
            'usage_reset_date' => $today,
        ]);

        // Mark the daily reset flag as done for today in cache
        Cache::put("provider_accounts_reset_done:{$today}", true, now()->endOfDay());

        $this->info("Successfully reset daily usage for {$accountsReset} provider account(s) and {$keysReset} gateway key(s) for {$today}.");

        return self::SUCCESS;
    }
}

