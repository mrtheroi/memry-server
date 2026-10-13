<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('memory:migrate')]
#[Description('Run the database migrations under a PostgreSQL advisory lock, so concurrent containers do not race')]
class MigrateMemory extends Command
{
    /** Arbitrary fixed key shared by every container ("memry" as ASCII bytes). */
    public const LOCK_KEY = 0x6D656D7279;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        DB::select('select pg_advisory_lock(?)', [self::LOCK_KEY]);

        try {
            return $this->call('migrate', ['--force' => true]);
        } finally {
            DB::select('select pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }
    }
}
