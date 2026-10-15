<?php

namespace App\Usage\Application;

use App\Models\User;
use App\Usage\Domain\UnknownUsageToken;

/**
 * Revokes the user's usage tokens (`memry-usage`, `usage:write` only), or, with $keep, only those issued
 * before it (ids are monotonic), so a client never revokes a newer token another client just stored.
 * Full-access tokens and other users' tokens are never touched.
 */
final class RevokeUsageTokens
{
    /**
     * @throws UnknownUsageToken when $keep is not one of the user's usage tokens (nothing is revoked)
     */
    public function __invoke(User $user, ?int $keep = null): void
    {
        // abilities is stored as JSON text, so an exact match pins it to `usage:write` only.
        $usageTokens = $user->tokens()
            ->where('name', 'memry-usage')
            ->where('abilities', json_encode(['usage:write']));

        if ($keep !== null && ! (clone $usageTokens)->whereKey($keep)->exists()) {
            throw UnknownUsageToken::toKeep();
        }

        $usageTokens->when($keep !== null, fn ($query) => $query->where('id', '<', $keep))->delete();
    }
}
