<?php

namespace App\Mcp\Tools;

use App\Memory\Domain\MemoryRepository;
use App\Team\Domain\AccessContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Get the full content of one memory by its id. Use it when get-context or search-memory shows a memory you need to read in full.')]
class GetMemory extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, MemoryRepository $memories, AccessContext $context): Response
    {
        $request->validate([
            'id' => ['required', 'integer', 'min:1'],
        ]);

        $observation = $memories->find($context, (int) $request->get('id'));

        if ($observation === null) {
            return Response::text('Memory not found.');
        }

        return Response::text("#{$observation->id} [{$observation->type}] {$observation->title}\n{$observation->content}");
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id of the memory, as shown by search-memory or get-context (e.g. 12 for #12).')->required(),
        ];
    }
}
