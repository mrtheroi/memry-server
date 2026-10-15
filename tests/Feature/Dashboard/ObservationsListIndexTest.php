<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('observations has an index serving the dashboard order: team, updated_at desc, id desc', function () {
    $definitions = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'observations'"))->pluck('indexdef');

    expect($definitions->contains(fn (string $def) => str_contains($def, '(team_id, updated_at DESC, id DESC)')))->toBeTrue();
});

test('observations has an index serving the stats range: team, created_at', function () {
    $definitions = collect(DB::select("SELECT indexdef FROM pg_indexes WHERE tablename = 'observations'"))->pluck('indexdef');

    expect($definitions->contains(fn (string $def) => str_contains($def, '(team_id, created_at)')))->toBeTrue();
});
