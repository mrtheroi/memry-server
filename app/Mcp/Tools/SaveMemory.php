<?php

namespace App\Mcp\Tools;

use App\Memory\Application\SaveObservation;
use App\Memory\Domain\Observation;
use App\Team\Domain\AccessContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Save a memory: a decision, bug fix, discovery or convention worth recalling in future sessions.')]
class SaveMemory extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, SaveObservation $saveObservation, AccessContext $context): Response
    {
        $request->validate([
            'session_id' => ['required', 'max:255'],
            'type' => ['required', 'max:255'],
            'title' => ['required', 'max:255'],
            'content' => ['required', 'max:20000'],
            'project' => ['max:255'],
            'topic_key' => ['max:255'],
        ]);

        $observation = $saveObservation(new Observation(
            userId: $request->user()->id,
            sessionId: $request->get('session_id'),
            type: $request->get('type'),
            title: $request->get('title'),
            content: $request->get('content'),
            project: $request->get('project'),
            scope: 'project',
            topicKey: $request->get('topic_key'),
            teamId: $context->teamId,
        ), $context);

        return Response::text("Memory saved with id {$observation->id}.");
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
            'type' => $schema->string()->description('Category, e.g. decision, bugfix, discovery, pattern.')->required(),
            'title' => $schema->string()->description('Short, searchable title (verb + what).')->required(),
            'content' => $schema->string()->description('What happened, why, where and what was learned.')->required(),
            'project' => $schema->string()->description('Project the memory belongs to.'),
            'topic_key' => $schema->string()->description('Stable key for an evolving topic, e.g. architecture/auth-model. Saving again with the same key updates the memory instead of duplicating it.'),
        ];
    }
}
