<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListTokensController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        // A dashboard session carries no token of its own, so none of the listed tokens is "current".
        $currentId = $user->authenticatedWithToken() ? $user->currentAccessToken()->getKey() : null;

        $tokens = $user->tokens()->orderBy('id')->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'created_at' => $token->created_at,
                'last_used_at' => $token->last_used_at,
                'expires_at' => $token->idleExpiresAt(),
                'current' => $token->getKey() === $currentId,
            ]);

        return response()->json(['data' => $tokens]);
    }
}
