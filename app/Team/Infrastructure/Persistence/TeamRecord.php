<?php

namespace App\Team\Infrastructure\Persistence;

use Database\Factories\TeamRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['owner_id', 'slug', 'personal_team', 'plan', 'seats'])]
class TeamRecord extends Model
{
    /** @use HasFactory<TeamRecordFactory> */
    use HasFactory;

    protected $table = 'teams';

    protected static function newFactory(): TeamRecordFactory
    {
        return TeamRecordFactory::new();
    }

    protected function casts(): array
    {
        return ['personal_team' => 'boolean'];
    }
}
