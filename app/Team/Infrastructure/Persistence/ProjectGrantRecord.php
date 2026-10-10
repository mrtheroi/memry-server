<?php

namespace App\Team\Infrastructure\Persistence;

use Database\Factories\ProjectGrantRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['project_id', 'user_id'])]
class ProjectGrantRecord extends Model
{
    /** @use HasFactory<ProjectGrantRecordFactory> */
    use HasFactory;

    protected $table = 'project_grants';

    protected static function newFactory(): ProjectGrantRecordFactory
    {
        return ProjectGrantRecordFactory::new();
    }
}
