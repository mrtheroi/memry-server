<?php

namespace App\Mcp\Tools;

use App\Memory\Application\BuildProjectContext;
use App\Team\Domain\AccessContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Load recent memories and session summaries of a project. Call it at the start of a session to resume previous work.')]
class GetContext extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, BuildProjectContext $buildContext, AccessContext $context): Response
    {
        $request->validate([
            'project' => ['required', 'max:255'],
        ]);

        return Response::text($buildContext($context, $request->get('project')));
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()->description('Project to load context for.')->required(),
        ];
    }
}
