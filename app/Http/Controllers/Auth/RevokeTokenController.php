<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevokeTokenController extends Controller
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        if (! $request->user()->authenticatedWithToken()) {
            return response()->json(
                ['message' => 'This request is authenticated with a session, not a token. Use DELETE /api/session to sign out.'],
                422,
            );
        }

        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
