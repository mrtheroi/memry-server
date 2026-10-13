<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum token that can be bound to a team (NULL for legacy tokens).
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'team_id'];

    /**
     * When the token stops authenticating for lack of use.
     */
    public function idleExpiresAt(): CarbonInterface
    {
        return ($this->last_used_at ?? $this->created_at)->copy()->addDays(config('memry.tokens.idle_days'));
    }

    public function isIdle(): bool
    {
        return $this->idleExpiresAt()->isPast();
    }

    protected function casts(): array
    {
        return ['team_id' => 'integer'];
    }
}
