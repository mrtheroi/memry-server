<?php

namespace App\Console\Commands;

use App\Memory\Application\MergeProjects;
use App\Memory\Domain\ProjectName;
use App\Models\User;
use App\Team\Domain\AccessContext;
use App\Team\Domain\AccessContextResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('memory:merge-projects {from : Project whose memories are moved} {to : Project that receives them} {--email= : Only move the rows of this user}')]
#[Description('Move the observations and prompts of one project into another')]
class MergeMemoryProjects extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MergeProjects $mergeProjects, AccessContextResolver $contexts): int
    {
        $from = ProjectName::normalize($this->argument('from'));
        $to = ProjectName::normalize($this->argument('to'));

        if ($from === null || $to === null) {
            $this->error('Project names must not be blank.');

            return self::FAILURE;
        }

        if ($from === $to) {
            $this->error("Cannot merge project {$from} into itself.");

            return self::FAILURE;
        }

        $email = $this->option('email');
        $context = AccessContext::system();

        if ($email !== null) {
            $email = Str::lower(trim($email));
            $user = User::where('email', $email)->first();

            if ($user === null) {
                $this->error("User {$email} not found.");

                return self::FAILURE;
            }

            // The command has no token, so the resolver yields the personal-team context.
            $context = $contexts->resolve($user);
        }

        $moved = DB::transaction(fn () => $mergeProjects($from, $to, $context));

        $this->line("Moved {$moved['observations']} observations and {$moved['prompts']} prompts from {$from} to {$to}.");
        $this->line("{$moved['collisions']} topic_key collisions (same user and topic_key now twice in {$to}) left to resolve.");

        return self::SUCCESS;
    }
}
