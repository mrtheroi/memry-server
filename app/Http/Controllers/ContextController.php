<?php

namespace App\Http\Controllers;

use App\Memory\Application\BuildProjectContext;
use App\Team\Domain\AccessContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ContextController extends Controller
{
    public function __invoke(Request $request, BuildProjectContext $buildContext, AccessContext $context): Response
    {
        $request->validate([
            'project' => ['required', 'string', 'max:255'],
        ]);

        return response($buildContext($context, $request->query('project')))
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
