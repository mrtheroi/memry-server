<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginCode;
use App\Models\User;
use App\Team\Application\ProvisionPersonalTeam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TokenController extends Controller
{
    public function __invoke(Request $request, ProvisionPersonalTeam $provisionPersonalTeam): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $email = $request->string('email')->trim()->lower()->value();
        $loginCode = LoginCode::latestUsableFor($email);

        if ($loginCode === null || $loginCode->isBurned()) {
            return $this->invalidCode();
        }

        if (! $loginCode->matches($request->input('code'))) {
            $loginCode->increment('attempts');

            return $this->invalidCode();
        }

        if (! $loginCode->consume()) {
            return $this->invalidCode();
        }

        $token = DB::transaction(function () use ($email, $provisionPersonalTeam) {
            $user = User::firstOrNew(['email' => $email]);

            if (! $user->exists) {
                $user->forceFill([
                    'name' => Str::before($email, '@'),
                    'password' => Str::random(40),
                    'email_verified_at' => now(),
                ])->save();
            }

            $provisionPersonalTeam->forUser($user->id);

            return $user->createToken('memry-cli')->plainTextToken;
        });

        return response()->json(['token' => $token]);
    }

    private function invalidCode(): JsonResponse
    {
        return response()->json(['message' => 'Invalid or expired code.'], 422);
    }
}
