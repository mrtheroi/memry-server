<?php

namespace App\Team\Infrastructure\Persistence;

use Database\Factories\ProjectRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['team_id', 'name'])]
class ProjectRecord extends Model
{
    /** @use HasFactory<ProjectRecordFactory> */
    use HasFactory;

    protected $table = 'projects';

    protected static function newFactory(): ProjectRecordFactory
    {
        return ProjectRecordFactory::new();
    }
}
