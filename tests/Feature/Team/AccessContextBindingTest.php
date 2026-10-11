<?php

use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
use App\Team\Domain\ProjectAccess;
use App\Team\Domain\ProjectAccessPolicy;
use App\Team\Domain\Role;
use App\Team\Infrastructure\EloquentAccessContextResolver;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\RoleProjectAccessPolicy;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Tool;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('binds the ports to their implementations', function () {
    expect(app(AccessContextResolver::class))->toBeInstanceOf(EloquentAccessContextResolver::class)
        ->and(app(ProjectAccessPolicy::class))->toBeInstanceOf(RoleProjectAccessPolicy::class);
});

it('resolves AccessContext through a replaceable resolver', function () {
    $fake = AccessContext::forTeam(7, 8, Role::Admin, ProjectAccess::only(1));
    app()->instance(AccessContextResolver::class, new class($fake) implements AccessContextResolver
    {
        public function __construct(private AccessContext $ctx) {}

        public function resolve(?Authenticatable $user): AccessContext
        {
            return $this->ctx;
        }
    });

    expect(app(AccessContext::class))->toBe($fake);
});

it('denies when nobody is authenticated', function () {
    expect(app(AccessContext::class)->isDenied())->toBeTrue();
});

it('resolves a fresh context per authenticated user, never a shared one', function () {
    [$ada, $bob] = User::factory()->count(2)->create();

    Sanctum::actingAs($ada);
    $first = app(AccessContext::class);
    Sanctum::actingAs($bob);
    $second = app(AccessContext::class);

    expect($first->userId)->toBe($ada->id)
        ->and($second->userId)->toBe($bob->id)
        ->and($first->teamId)->not->toBe($second->teamId)
        ->and($first->teamId)->toBe(TeamRecord::where('owner_id', $ada->id)->value('id'));
});

it('injects the context into a tool handle by method injection', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $tool = new class extends Tool
    {
        public function handle(AccessContext $ctx): int
        {
            return $ctx->userId;
        }
    };

    expect(Container::getInstance()->call([$tool, 'handle']))->toBe($user->id);
});
