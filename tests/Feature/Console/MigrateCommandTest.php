<?php

use App\Console\Commands\MigrateMemory;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);

    $this->migrationsPath = storage_path('framework/testing/migrate-command-'.uniqid());
    File::ensureDirectoryExists($this->migrationsPath);
    app('migrator')->path($this->migrationsPath);
});

afterEach(function () {
    DB::purge('pgsql_probe');
    File::deleteDirectory($this->migrationsPath);
});

function writeMigration(string $directory, string $body): void
{
    File::put($directory.'/2099_01_01_000000_memory_migrate_probe.php', <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                {$body}
            }
        };
        PHP);
}

/** True when a different session can take the lock, i.e. nobody holds it. */
function lockIsFree(): bool
{
    $free = DB::connection('pgsql_probe')
        ->selectOne('select pg_try_advisory_lock(?) as locked', [MigrateMemory::LOCK_KEY])->locked;

    if ($free) {
        DB::connection('pgsql_probe')->select('select pg_advisory_unlock(?)', [MigrateMemory::LOCK_KEY]);
    }

    return $free;
}

it('holds the advisory lock while the migrations run', function () {
    writeMigration($this->migrationsPath, "Schema::create('memory_migrate_probe', fn (\$t) => \$t->id());");

    $freeDuringRun = null;
    Event::listen(MigrationsStarted::class, function () use (&$freeDuringRun) {
        $freeDuringRun = lockIsFree();
    });

    expect(Artisan::call('memory:migrate'))->toBe(0);

    expect($freeDuringRun)->toBeFalse();
});

it('runs the pending migrations', function () {
    writeMigration($this->migrationsPath, "Schema::create('memory_migrate_probe', fn (\$t) => \$t->id());");

    Artisan::call('memory:migrate');

    expect(Schema::hasTable('memory_migrate_probe'))->toBeTrue();
});

it('releases the lock after a successful run', function () {
    writeMigration($this->migrationsPath, "Schema::create('memory_migrate_probe', fn (\$t) => \$t->id());");

    Artisan::call('memory:migrate');

    expect(lockIsFree())->toBeTrue();
});

it('releases the lock when a migration fails', function () {
    writeMigration($this->migrationsPath, "throw new RuntimeException('boom');");

    expect(fn () => Artisan::call('memory:migrate'))->toThrow(RuntimeException::class, 'boom');

    expect(lockIsFree())->toBeTrue();
});
