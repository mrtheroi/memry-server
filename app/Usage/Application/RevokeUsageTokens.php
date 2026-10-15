<?php

namespace App\Usage\Application;

use App\Models\User;

/**
 * Revokes every usage token of the user (`memry-usage`, `usage:write` only): the emergency path for a
 * leaked token. Clients revoke a single token by its exact id instead. Full-access tokens and other
 * users' tokens are never touched.
 */
final class RevokeUsageTokens
{
    public function __invoke(User $user): void
    {
        // abilities is stored as JSON text, so an exact match pins it to `usage:write` only.
        $user->tokens()
            ->where('name', 'memry-usage')
            ->where('abilities', json_encode(['usage:write']))
            ->delete();
    }
}
