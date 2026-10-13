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

        $pid = $this->backendPid();

        try {
            $exitCode = $this->call('migrate', ['--force' => true]);

            if ($this->backendPid() !== $pid) {
                $this->error('The database session changed during the migration, so the advisory lock did not protect it. Migrations need a direct PostgreSQL connection or a session-pooled one; transaction and statement pooling are not supported. Point DB_HOST at the provider\'s direct connection.');

                return self::FAILURE;
            }
        } finally {
            $released = DB::selectOne('select pg_advisory_unlock(?) as released', [self::LOCK_KEY])->released;
        }

        if (! $released) {
            $this->error('The advisory lock was not held by this session at unlock time, which means the connection is pooled per transaction or statement. Migrations need a direct PostgreSQL connection or a session-pooled one; point DB_HOST at the provider\'s direct connection.');

            return self::FAILURE;
        }

        return $exitCode;
    }

    private function backendPid(): int
    {
        return DB::selectOne('select pg_backend_pid() as pid')->pid;
    }
}
