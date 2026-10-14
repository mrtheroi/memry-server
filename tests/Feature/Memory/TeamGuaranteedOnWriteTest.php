<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\SaveMemory;
use App\Mcp\Tools\SavePrompt;
use App\Mcp\Tools\SessionSummary;
use App\Memory\Domain\Observation;
use App\Memory\Domain\UserPrompt;
use App\Memory\Infrastructure\Persistence\EloquentMemoryRepository;
use App\Memory\Infrastructure\Persistence\EloquentPromptRepository;
use App\Models\User;
use App\Team\Domain\AccessDenied;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('the memory repository refuses an observation without a team and writes nothing', function () {
    $user = User::factory()->create();

    expect(fn () => (new EloquentMemoryRepository)->save(new Observation($user->id, 's', 'decision', 'T', 'C', 'dbmcp', 'project', null)))
        ->toThrow(AccessDenied::class, 'A team is required to write.');
    expect(DB::table('observations')->count())->toBe(0);
});

test('the prompt repository refuses a prompt without a team and writes nothing', function () {
    $user = User::factory()->create();

    expect(fn () => (new EloquentPromptRepository)->save(new UserPrompt($user->id, 's', 'dbmcp', 'hello')))
        ->toThrow(AccessDenied::class, 'A team is required to write.');
    expect(DB::table('user_prompts')->count())->toBe(0);
});

test('a user without a team is refused over MCP and nothing is written or provisioned', function () {
    $user = User::factory()->withoutPersonalTeam()->create();

    MemoryServer::actingAs($user)->tool(SaveMemory::class, ['session_id' => 's', 'type' => 'decision', 'title' => 'T', 'content' => 'C', 'project' => 'p'])
        ->assertHasErrors(['A team is required to write.']);
    MemoryServer::actingAs($user)->tool(SessionSummary::class, ['session_id' => 's', 'project' => 'p', 'content' => 'done'])
        ->assertHasErrors(['A team is required to write.']);
    MemoryServer::actingAs($user)->tool(SavePrompt::class, ['session_id' => 's', 'project' => 'p', 'content' => 'hello'])
        ->assertHasErrors(['A team is required to write.']);

    expect(DB::table('observations')->count())->toBe(0)
        ->and(DB::table('user_prompts')->count())->toBe(0)
        ->and(DB::table('teams')->count())->toBe(0);
});

test('a user with a team still saves memories and prompts into it', function () {
    $user = User::factory()->create();
    $teamId = DB::table('teams')->where('owner_id', $user->id)->value('id');

    MemoryServer::actingAs($user)->tool(SaveMemory::class, ['session_id' => 's', 'type' => 'decision', 'title' => 'T', 'content' => 'C', 'project' => 'p'])->assertOk();
    MemoryServer::actingAs($user)->tool(SavePrompt::class, ['session_id' => 's', 'project' => 'p', 'content' => 'hello'])->assertOk();

    expect(DB::table('observations')->value('team_id'))->toBe($teamId)
        ->and(DB::table('user_prompts')->value('team_id'))->toBe($teamId);
});
