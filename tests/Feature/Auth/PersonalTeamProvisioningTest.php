<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use App\Team\Application\ProvisionPersonalTeam;
use App\Team\Infrastructure\Persistence\TeamRecord;
use App\Team\Infrastructure\Persistence\TeamUserRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function loginWithCode(string $email): TestResponse
{
    test()->postJson('/api/auth/code', ['email' => $email])->assertAccepted();
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return test()->postJson('/api/auth/token', ['email' => $email, 'code' => $code]);
}

test('a new user logging in gets one personal team with an owner membership', function () {
    $response = loginWithCode('ada@example.com');

    $response->assertOk()->assertJsonStructure(['token']);
    $user = User::sole();
    $team = TeamRecord::sole();
    expect($team->owner_id)->toBe($user->id)
        ->and($team->personal_team)->toBeTrue()
        ->and(TeamUserRecord::sole()->only(['team_id', 'user_id']))->toBe(['team_id' => $team->id, 'user_id' => $user->id])
        ->and($user->tokens()->sole()->name)->toBe('memry-cli');
});

test('logging in again keeps a single personal team', function () {
    loginWithCode('ada@example.com')->assertOk();
    loginWithCode('ada@example.com')->assertOk();

    expect(TeamRecord::count())->toBe(1)->and(TeamUserRecord::count())->toBe(1);
});

test('an existing user without a team gets one on the next login', function () {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    expect(TeamRecord::count())->toBe(0);

    loginWithCode('ada@example.com')->assertOk();

    expect(TeamRecord::sole()->owner_id)->toBe($user->id)->and(TeamUserRecord::count())->toBe(1);
});

test('a provisioning failure leaves no user, team or token and answers 500', function () {
    $this->mock(ProvisionPersonalTeam::class)->shouldReceive('forUser')->andThrow(new RuntimeException('boom'));

    loginWithCode('ada@example.com')->assertStatus(500);

    expect(User::count())->toBe(0)
        ->and(TeamRecord::count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('memory:token --create provisions a personal team for a new user', function () {
    $exit = Artisan::call('memory:token', ['email' => 'new@example.com', '--create' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toMatch('/\AToken: \d+\|[A-Za-z0-9]{48}\n\z/')
        ->and(TeamRecord::sole()->owner_id)->toBe(User::sole()->id)
        ->and(TeamUserRecord::count())->toBe(1);
});

test('memory:token provisions a personal team for an existing user', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);

    $this->artisan('memory:token', ['email' => 'me@example.com'])->assertSuccessful();

    expect(TeamRecord::sole()->owner_id)->toBe($user->id);
});

test('memory:token leaves no user, team or token when provisioning fails', function () {
    $this->mock(ProvisionPersonalTeam::class)->shouldReceive('forUser')->andThrow(new RuntimeException('boom'));

    expect(fn () => Artisan::call('memory:token', ['email' => 'new@example.com', '--create' => true]))
        ->toThrow(RuntimeException::class);

    expect(User::count())->toBe(0)
        ->and(DB::table('personal_access_tokens')->count())->toBe(0);
});

test('deleting the account removes the personal team and its membership', function () {
    $response = loginWithCode('ada@example.com');
    $this->withToken($response->json('token'))->deleteJson('/api/account', ['email' => 'ada@example.com'])->assertSuccessful();

    expect(User::count())->toBe(0)->and(TeamRecord::count())->toBe(0)->and(TeamUserRecord::count())->toBe(0);
});
