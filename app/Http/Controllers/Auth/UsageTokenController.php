<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageTokenController extends Controller
{
    public function __invoke(Request $request, AccessContext $context): JsonResponse
    {
        if ($context->isDenied()) {
            return response()->json(['message' => 'A team is required to issue a usage token.'], 403);
        }

        // Issue only: the previous usage token stays valid until the client revokes it with
        // DELETE /api/auth/usage-tokens (or it idles out), so a failed local write never strands the user.
        $token = $request->user()->createTeamToken('memry-usage', $context->teamId, ['usage:write']);

        return response()->json(['id' => $token->accessToken->getKey(), 'token' => $token->plainTextToken], 201);
    }
}
