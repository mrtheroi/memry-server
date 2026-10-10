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

test('assertExactText fails on an error result even when its text matches', function () {
    $user = User::factory()->create();
    $error = fn () => MemoryServer::actingAs($user)->tool(GetMemory::class, []);

    try {
        $error()->assertHasErrors();
    } catch (AssertionFailedError) {
        $this->fail('The fixture must be an error result.');
    }
    $text = (fn () => $this->errors()[0])->call($error());

    expect(fn () => $error()->assertExactText($text))->toThrow(AssertionFailedError::class);
});

test('assertExactError passes only on an error result whose whole text matches', function () {
    $user = User::factory()->create();
    $error = fn () => MemoryServer::actingAs($user)->tool(GetMemory::class, []);
    $text = (fn () => $this->errors()[0])->call($error());

    $error()->assertExactError($text);

    expect(fn () => $error()->assertExactError(substr($text, 0, 5)))->toThrow(AssertionFailedError::class);
});

test('assertExactError fails on a success result', function () {
    $user = User::factory()->create();

    expect(fn () => MemoryServer::actingAs($user)
        ->tool(GetMemory::class, ['id' => 999999])
        ->assertExactError('Memory not found.'))->toThrow(AssertionFailedError::class);
});
