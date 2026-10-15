<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Usage\Application\RevokeUsageTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevokeUsageTokensController extends Controller
{
    public function __invoke(Request $request, RevokeUsageTokens $revoke): Response
    {
        $revoke($request->user());

        return response()->noContent();
    }
}
