<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Team\Application\RedeemEmailCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    public function __invoke(Request $request, RedeemEmailCode $redeemEmailCode): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $token = $redeemEmailCode->redeem(
            $request->string('email')->trim()->lower()->value(),
            $request->input('code'),
            fn (User $user, int $teamId) => $user->createTeamToken('memry-cli', $teamId)->plainTextToken,
        );

        if ($token === null) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        return response()->json(['token' => $token]);
    }
}
