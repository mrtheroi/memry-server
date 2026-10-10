<?php

use App\Mcp\Servers\MemoryServer;
use App\Mcp\Tools\GetMemory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;

uses(RefreshDatabase::class);

test('assertExactText passes only when the whole response text matches', function () {
    $user = User::factory()->create();

    MemoryServer::actingAs($user)
        ->tool(GetMemory::class, ['id' => 999999])
        ->assertExactText('Memory not found.');

    expect(fn () => MemoryServer::actingAs($user)
        ->tool(GetMemory::class, ['id' => 999999])
        ->assertExactText('Memory not'))->toThrow(AssertionFailedError::class);
});
