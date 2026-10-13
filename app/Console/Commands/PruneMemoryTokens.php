<?php

namespace App\Console\Commands;

use App\Models\PersonalAccessToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('memory:prune-tokens')]
#[Description('Delete tokens idle beyond the idle window or past the absolute lifetime')]
class PruneMemoryTokens extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $idleBefore = now()->subDays(config('memry.tokens.idle_days'));
        $lifetime = config('sanctum.expiration');

        $pruned = PersonalAccessToken::query()
            ->where(function ($query) use ($idleBefore, $lifetime) {
                $query->whereRaw('coalesce(last_used_at, created_at) <= ?', [$idleBefore])
                    ->orWhere('expires_at', '<=', now());

                if ($lifetime) {
                    $query->orWhere('created_at', '<=', now()->subMinutes($lifetime));
                }
            })
            ->delete();

        $this->line("Pruned {$pruned} tokens.");

        return self::SUCCESS;
    }
}
