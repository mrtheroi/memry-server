<?php

namespace App\Memory\Application;

use App\Memory\Domain\MemoryRepository;
use App\Memory\Domain\Observation;
use App\Team\Domain\AccessContext;

final class SaveObservation
{
    public function __construct(private MemoryRepository $memories, private ?AuthorizeProjectWrite $authorize = null) {}

    public function __invoke(Observation $observation, ?AccessContext $context = null): Observation
    {
        $context ??= AccessContext::denied($observation->userId);
        ($this->authorize ?? app(AuthorizeProjectWrite::class))($context, $observation->project);
        $observation = $observation->withTeamId($context->teamId);

        if ($observation->topicKey !== null) {
            $existing = $this->memories->findByTopicKey(
                $observation->userId,
                $observation->project,
                $observation->scope,
                $observation->topicKey,
                $context->teamId,
            );

            if ($existing !== null) {
                return $this->memories->save($observation->withId($existing->id));
            }
        }

        return $this->memories->save($observation);
    }
}
