<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Team\Application\WhoAmI;
use App\Team\Domain\AccessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request, WhoAmI $whoAmI, AccessContext $context): JsonResponse
    {
        return response()->json(['data' => $whoAmI($request->user(), $context)]);
    }
}
