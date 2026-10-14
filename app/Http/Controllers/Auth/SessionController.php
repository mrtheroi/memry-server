<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Team\Application\RedeemEmailCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    public function store(Request $request, RedeemEmailCode $redeemEmailCode): Response|JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $redeemEmailCode->redeem(
            $request->string('email')->trim()->lower()->value(),
            $request->input('code'),
            fn (User $user) => $user,
        );

        if ($user === null) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->noContent();
    }

    public function destroy(Request $request): Response|JsonResponse
    {
        if ($request->user()->authenticatedWithToken()) {
            return response()->json(['message' => 'This request is authenticated with a token. Use DELETE /api/auth/token to revoke it.'], 422);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
