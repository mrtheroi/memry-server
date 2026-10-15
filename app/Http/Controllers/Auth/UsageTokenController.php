<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UsageTokenController extends Controller
{
    public function __invoke(Request $request, AccessContext $context): JsonResponse
    {
        if ($context->isDenied()) {
            return response()->json(['message' => 'A team is required to issue a usage token.'], 403);
        }

        $user = $request->user();

        // Rotation: delete and create in one transaction so a user never holds two usage tokens.
        $token = DB::transaction(function () use ($user, $context) {
            // Lock order: the user row first, so two rotations for the same user serialize.
            User::whereKey($user->id)->lockForUpdate()->first();
            $user->tokens()->where('name', 'memry-usage')->delete();

            return $user->createTeamToken('memry-usage', $context->teamId, ['usage:write']);
        });

        return response()->json(['token' => $token->plainTextToken], 201);
    }
}
