<?php

namespace App\Providers;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\ProjectRepository;
use App\Memory\Domain\PromptRepository;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Memory\Infrastructure\Persistence\QueryProjectRepository;
use App\Models\PersonalAccessToken;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
use App\Team\Domain\ProjectAccessPolicy;
use App\Team\Domain\TeamSlugs;
use App\Team\Infrastructure\EloquentAccessContextResolver;
use App\Team\Infrastructure\RandomTeamSlugs;
use App\Team\Infrastructure\RoleProjectAccessPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

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
        $this->app->bind(AccessContextResolver::class, EloquentAccessContextResolver::class);
        $this->app->bind(ProjectAccessPolicy::class, RoleProjectAccessPolicy::class);
        // bind, never scoped/singleton: the context must follow the authenticated user
        // of each resolution (queue workers and Octane reuse the container).
        $this->app->bind(AccessContext::class, fn ($app) => $app->make(AccessContextResolver::class)->resolve($app['auth']->user()));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

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
