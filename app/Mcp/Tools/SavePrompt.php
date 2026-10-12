<?php

namespace App\Mcp\Tools;

use App\Memory\Application\RecordPrompt;
use App\Memory\Domain\UserPrompt;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessDenied;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Record what the user asked for in this session, so future sessions know the original intent behind the work.')]
class SavePrompt extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, RecordPrompt $recordPrompt, AccessContext $context): Response
    {
        $request->validate([
            'session_id' => ['required', 'max:255'],
            'content' => ['required', 'max:20000'],
            'project' => ['max:255'],
        ]);

        try {
            $recordPrompt(new UserPrompt(
                userId: $request->user()->id,
                sessionId: $request->get('session_id'),
                project: $request->get('project'),
                content: $request->get('content'),
                teamId: $context->teamId,
            ), $context);

            return Response::text('Prompt saved.');
        } catch (AccessDenied $denied) {
            return Response::error($denied->getMessage());
        }
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'session_id' => $schema->string()->description('Current session identifier.')->required(),
            'project' => $schema->string()->description('Project the prompt belongs to.'),
            'content' => $schema->string()->description('The user prompt, verbatim.')->required(),
        ];
    }
}
