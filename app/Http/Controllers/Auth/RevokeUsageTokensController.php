<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Usage\Application\RevokeUsageTokens;
use App\Usage\Domain\UnknownUsageToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class RevokeUsageTokensController extends Controller
{
    public function __invoke(Request $request, RevokeUsageTokens $revoke): Response
    {
        $request->validate(['keep' => ['sometimes', 'integer']]);

        try {
            $revoke($request->user(), $request->has('keep') ? $request->integer('keep') : null);
        } catch (UnknownUsageToken $e) {
            throw ValidationException::withMessages(['keep' => $e->getMessage()]);
        }

        return response()->noContent();
    }
}
