<?php

namespace App\Team\Infrastructure\Persistence;

use App\Team\Domain\Role;
use Database\Factories\TeamUserRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['team_id', 'user_id', 'role'])]
class TeamUserRecord extends Model
{
    /** @use HasFactory<TeamUserRecordFactory> */
    use HasFactory;

    protected $table = 'team_user';

    protected static function newFactory(): TeamUserRecordFactory
    {
        return TeamUserRecordFactory::new();
    }

    protected function casts(): array
    {
        return ['role' => Role::class];
    }
}
