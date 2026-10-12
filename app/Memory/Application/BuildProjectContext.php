<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Memory\Domain\ProjectName;
use App\Team\Domain\AccessContext;

final class BuildProjectContext
{
    public function __construct(private MemoryRepository $memories) {}

    /**
     * A bare user id is the legacy form: every row of that user, whatever its team. Request paths
     * pass the AccessContext, which scopes the read to the request's team and allowed projects.
     */
    public function __invoke(AccessContext|int $access, ?string $project): string
    {
        $project = ProjectName::normalize($project);
        [$context, $onlyUserId] = $access instanceof AccessContext ? [$access, null] : [AccessContext::system(), $access];

        $sessions = $this->memories->recentSessionSummaries($context, $project, 3, $onlyUserId);
        $knowledge = $this->memories->withTopicKey($context, $project, 20, $onlyUserId);
        $recent = $this->memories->recentWithoutTopicKey($context, $project, 10, $onlyUserId);

        if ($sessions === [] && $knowledge === [] && $recent === []) {
            return "No context found for project {$project}.";
        }

        $sections = [];

        if ($sessions !== []) {
            $newest = array_shift($sessions);
            $section = "## Recent sessions\n#{$newest->id} [{$newest->type}] {$newest->title}\n{$newest->content}";

            if ($sessions !== []) {
                $section .= "\n\n".implode("\n", array_map(
                    fn (Observation $observation) => "- #{$observation->id} [{$observation->type}] {$observation->title} ({$observation->updatedAt?->format('Y-m-d H:i')}): {$this->preview($observation->content)}",
                    $sessions,
                ));
            }

            $sections[] = $section;
        }

        if ($knowledge !== []) {
            $sections[] = "## Project knowledge\n".implode("\n", array_map(
                fn (Observation $observation) => "- #{$observation->id} [{$observation->type}] {$observation->title}: {$this->preview($observation->content)}",
                $knowledge,
            ));
        }

        if ($recent !== []) {
            $sections[] = "## Recent memories\n".implode("\n", array_map(
                fn (Observation $observation) => "- #{$observation->id} [{$observation->type}] {$observation->title}",
                $recent,
            ));
        }

        $sections[] = 'Use get-memory with an id to read a memory in full.';

        return implode("\n\n", $sections);
    }

    private function preview(string $content): string
    {
        $flat = preg_replace('/\s*\R\s*/u', ' ', trim($content));

        return mb_strlen($flat) > 300 ? mb_substr($flat, 0, 300).'…' : $flat;
    }
}
