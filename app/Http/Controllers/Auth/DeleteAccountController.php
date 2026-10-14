<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteAccountController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->validate([
            'email' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($request->string('email')->trim()->lower()->value() !== $user->email) {
            throw ValidationException::withMessages(['email' => 'The email does not match your account.']);
        }

        DB::transaction(function () use ($user) {
            // Lock order: the user row first, then the user's other rows.
            User::whereKey($user->id)->lockForUpdate()->first();
            LoginCode::where('email', $user->email)->delete();
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        // A dashboard session outlives its user otherwise; end it like DELETE /api/session does.
        if (! $user->authenticatedWithToken()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
