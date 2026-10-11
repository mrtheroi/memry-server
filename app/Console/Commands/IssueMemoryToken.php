<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Team\Application\ProvisionPersonalTeam;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('memory:token {email : Email of the user who owns the memories} {--create : Create the user without asking if it does not exist}')]
#[Description('Issue a Sanctum token to connect an agent to the memory MCP server')]
class IssueMemoryToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProvisionPersonalTeam $provisionPersonalTeam): int
    {
        $email = Str::lower(trim($this->argument('email')));
        $user = User::where('email', $email)->first();
        $create = $user === null && ($this->option('create') || $this->confirm("User {$email} does not exist. Create it?"));

        if ($user === null && ! $create) {
            $this->error('No token issued.');

            return self::FAILURE;
        }

        $token = DB::transaction(function () use ($user, $email, $provisionPersonalTeam) {
            $user ??= User::create([
                'name' => $email,
                'email' => $email,
                'password' => Str::random(40),
            ]);

            $provisionPersonalTeam->forUser($user->id);

            return $user->createToken('mcp')->plainTextToken;
        });

        $this->line("Token: {$token}");

        return self::SUCCESS;
    }
}
