<?php

namespace App\Usage\Application;

use App\Models\User;
use App\Usage\Domain\UnknownUsageToken;

/**
 * Revokes the user's usage tokens (`memry-usage`, `usage:write` only), optionally all but $keep.
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

        $usageTokens->when($keep !== null, fn ($query) => $query->whereKeyNot($keep))->delete();
    }
}
