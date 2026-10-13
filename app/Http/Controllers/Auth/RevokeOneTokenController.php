<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevokeOneTokenController extends Controller
{
    public function __invoke(Request $request, int $id): Response
    {
        $token = $request->user()->tokens()->find($id);

        abort_if($token === null, 404);

        $token->delete();

        return response()->noContent();
    }
}
