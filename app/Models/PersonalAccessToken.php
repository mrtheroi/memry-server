<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token that can be bound to a team (NULL for legacy tokens).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'team_id'];

    protected function casts(): array
    {
        return ['team_id' => 'integer'];
    }
}
