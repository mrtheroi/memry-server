<?php

namespace App\Mcp\Tools;

use App\Memory\Application\SaveObservation;
use App\Memory\Domain\Observation;
use App\Team\Domain\AccessContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Save a summary of the current session before ending it, so the next session can resume where this one left off. Pass repo when the project spans several repositories.')]
class SessionSummary extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveObservation $saveObservation, AccessContext $context): Response
    {
        $request->validate([
            'session_id' => ['required', 'max:255'],
            'project' => ['required', 'max:255'],
            'content' => ['required', 'max:20000'],
            'repo' => ['nullable', 'string', 'max:255'],
        ]);

        $project = $request->get('project');
        $repo = trim($request->get('repo') ?? '');
        $title = $repo === '' ? "Session summary: {$project}" : "Session summary: {$project} ({$repo})";

        $saveObservation(new Observation(
            userId: $request->user()->id,
            sessionId: $request->get('session_id'),
            type: 'session_summary',
            title: Str::limit($title, 255, ''),
            content: $request->get('content'),
            project: $project,
            scope: 'project',
            topicKey: null,
            teamId: $context->teamId,
        ));

        return Response::text('Session summary saved.');
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
            'project' => $schema->string()->description('Project the session worked on.')->required(),
            'content' => $schema->string()->description('Markdown with sections: Goal, Discoveries, Accomplished, Next Steps, Relevant Files.')->required(),
            'repo' => $schema->string()->description('Optional repository the session worked in, when the project spans several repositories. Shown in the summary title.'),
        ];
    }
}
