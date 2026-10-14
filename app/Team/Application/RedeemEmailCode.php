<?php

namespace App\Team\Application;

use App\Models\LoginCode;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Redeems an emailed login code. Shared by every way of signing in with one (CLI token, dashboard session)
 * so the rules cannot drift: latest usable code, burn after too many attempts, one use, and the user and
 * personal team are created on first sign-in.
 */
class RedeemEmailCode
{
    public function __construct(private readonly ProvisionPersonalTeam $provisionPersonalTeam) {}

    /**
     * Returns whatever $issue returns, or null when the code is invalid, expired, burned or already used.
     * $issue runs in the same transaction as the user and team provisioning, so a failure leaves nothing behind.
     *
     * @template T
     *
     * @param  Closure(User, int): T  $issue  receives the user and its personal team id
     * @return T|null
     */
    public function redeem(string $email, string $code, Closure $issue): mixed
    {
        $loginCode = LoginCode::latestUsableFor($email);

        if ($loginCode === null || $loginCode->isBurned()) {
            return null;
        }

        if (! $loginCode->matches($code)) {
            $loginCode->increment('attempts');

            return null;
        }

        if (! $loginCode->consume()) {
            return null;
        }

        return DB::transaction(function () use ($email, $issue) {
            $user = User::firstOrNew(['email' => $email]);

            if (! $user->exists) {
                $user->forceFill([
                    'name' => Str::before($email, '@'),
                    'password' => Str::random(40),
                    'email_verified_at' => now(),
                ])->save();
            }

            return $issue($user, $this->provisionPersonalTeam->forUser($user->id));
        });
    }
}
