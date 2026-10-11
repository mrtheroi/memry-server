<?php

namespace App\Providers;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\ProjectRepository;
use App\Memory\Domain\PromptRepository;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Memory\Infrastructure\Persistence\QueryProjectRepository;
use App\Team\Domain\TeamSlugs;
use App\Team\Infrastructure\RandomTeamSlugs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MemoryRepository::class, EloquentMemoryRepository::class);
        $this->app->bind(PromptRepository::class, EloquentPromptRepository::class);
        $this->app->bind(ProjectRepository::class, QueryProjectRepository::class);
        $this->app->bind(TeamSlugs::class, RandomTeamSlugs::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(60)->by($request->user()->id));
        RateLimiter::for('auth-code', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perMinutes(10, 3)->by('email:'.(is_string($email) ? Str::lower(trim($email)) : '')),
                Limit::perHour(10)->by('ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('auth-token', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
    }
}
