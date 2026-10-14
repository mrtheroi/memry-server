<?php

use App\Mail\LoginCodeMail;
use App\Models\User;
use App\Team\Infrastructure\Persistence\TeamRecord;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const DASHBOARD_ORIGIN = 'https://app.memry.test';

beforeEach(function () {
    Mail::fake();
    config(['sanctum.stateful' => ['app.memry.test']]);
});

function dashboardHeaders(): array
{
    return ['Origin' => DASHBOARD_ORIGIN, 'Referer' => DASHBOARD_ORIGIN.'/'];
}

function sessionCookieName(): string
{
    return config('session.cookie');
}

function dashboardLogin(string $email = 'ada@example.com'): TestResponse
{
    test()->postJson('/api/auth/code', ['email' => $email])->assertAccepted();

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return test()->withHeaders(dashboardHeaders())->postJson('/api/session', ['email' => $email, 'code' => $code]);
}

function sessionCookieFrom(TestResponse $response): string
{
    return $response->getCookie(sessionCookieName())->getValue();
}

function asDashboard(string $sessionCookie): TestCase
{
    // The app instance is reused across requests in one test: drop the guard's cached user and the session store's
    // in-memory attributes so the cookie (and the server-side session it points to) alone decides.
    app('auth')->forgetGuards();
    app('session.store')->flush();

    return test()->withCredentials()->withHeaders(dashboardHeaders())->withCookie(sessionCookieName(), $sessionCookie);
}

test('a valid emailed code opens a session that GET /api/me accepts', function () {
    $response = dashboardLogin();

    $response->assertNoContent();

    $user = User::where('email', 'ada@example.com')->firstOrFail();
    asDashboard(sessionCookieFrom($response))->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'ada@example.com');
});

test('a wrong code answers the generic 422 and signs nobody in', function () {
    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();

    $response = $this->withHeaders(dashboardHeaders())
        ->postJson('/api/session', ['email' => 'ada@example.com', 'code' => '000000']);

    $response->assertStatus(422)->assertExactJson(['message' => 'Invalid or expired code.']);
    $this->assertGuest('web');
    expect(User::where('email', 'ada@example.com')->exists())->toBeFalse();
});

test('an expired code answers the same 422 and signs nobody in', function () {
    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });
    $this->travel(2)->hours();

    $this->withHeaders(dashboardHeaders())
        ->postJson('/api/session', ['email' => 'ada@example.com', 'code' => $code])
        ->assertStatus(422)
        ->assertExactJson(['message' => 'Invalid or expired code.']);

    $this->assertGuest('web');
});

test('the session id is regenerated on login so a planted id is useless', function () {
    $planted = str_repeat('a', 40);
    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $response = $this->withCredentials()->withHeaders(dashboardHeaders())->withCookie(sessionCookieName(), $planted)
        ->postJson('/api/session', ['email' => 'ada@example.com', 'code' => $code]);

    $response->assertNoContent();
    expect(sessionCookieFrom($response))->not->toBe($planted);
    asDashboard($planted)->getJson('/api/me')->assertUnauthorized();
});

test('logout ends the session so GET /api/me answers 401', function () {
    $cookie = sessionCookieFrom(dashboardLogin());

    asDashboard($cookie)->deleteJson('/api/session')->assertNoContent();

    asDashboard($cookie)->getJson('/api/me')->assertUnauthorized();
});

test('DELETE /api/session under a bearer token refuses and keeps the token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('memry-cli');

    $this->withToken($token->plainTextToken)->deleteJson('/api/session')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'This request is authenticated with a token. Use DELETE /api/auth/token to revoke it.']);

    expect($user->tokens()->count())->toBe(1);
});

test('GET /api/me under a bearer token returns the user and the token team', function () {
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $team = TeamRecord::where('owner_id', $user->id)->where('personal_team', true)->firstOrFail();
    $token = $user->createTeamToken('memry-cli', $team->id)->plainTextToken;

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertExactJson(['data' => [
            'id' => $user->id,
            'email' => 'ada@example.com',
            'name' => 'Ada',
            'team' => ['id' => $team->id, 'slug' => $team->slug, 'personal_team' => true],
        ]]);
});

test('GET /api/me without credentials answers 401', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

test('GET /api/auth/tokens under a session marks no token as current', function () {
    $cookie = sessionCookieFrom(dashboardLogin());
    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $user->createToken('memry-cli');
    $user->createToken('laptop');

    $data = asDashboard($cookie)->getJson('/api/auth/tokens')->assertOk()->json('data');

    expect($data)->toHaveCount(2)
        ->and(array_column($data, 'current'))->toBe([false, false]);
});

test('DELETE /api/auth/token under a session points to DELETE /api/session', function () {
    $cookie = sessionCookieFrom(dashboardLogin());
    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $user->createToken('memry-cli');

    asDashboard($cookie)->deleteJson('/api/auth/token')
        ->assertStatus(422)
        ->assertExactJson(['message' => 'This request is authenticated with a session, not a token. Use DELETE /api/session to sign out.']);

    expect($user->tokens()->count())->toBe(1);
});

test('DELETE /api/auth/tokens/{id} under a session revokes that token', function () {
    $cookie = sessionCookieFrom(dashboardLogin());
    $user = User::where('email', 'ada@example.com')->firstOrFail();
    $token = $user->createToken('laptop')->accessToken;

    asDashboard($cookie)->deleteJson("/api/auth/tokens/{$token->id}")->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

test('bearer clients without a stateful origin behave as before: no session, no cookie, current token marked', function () {
    $user = User::factory()->create();
    $token = $user->createToken('memry-cli');

    $list = $this->withToken($token->plainTextToken)->getJson('/api/auth/tokens');

    $list->assertOk()->assertJsonPath('data.0.current', true);
    expect($list->headers->getCookies())->toBe([]);

    $this->withToken($token->plainTextToken)->getJson('/api/context?project=demo')->assertOk();

    $this->withToken($token->plainTextToken)->deleteJson('/api/auth/token')->assertNoContent();
    expect($user->tokens()->count())->toBe(0);
});

test('a code login through POST /api/auth/token still issues a token and sets no cookie', function () {
    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    $response = $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code]);

    $response->assertOk()->assertJsonStructure(['token']);
    expect($response->headers->getCookies())->toBe([]);
});

/**
 * Laravel skips CSRF verification when running unit tests. This is the real middleware with only that bypass off.
 */
class EnforcingCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

test('a state-changing dashboard request without the CSRF token is refused with 419 and the cookie flow fixes it', function () {
    config(['sanctum.middleware.validate_csrf_token' => EnforcingCsrfToken::class]);
    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });
    $body = ['email' => 'ada@example.com', 'code' => $code];

    $this->withHeaders(dashboardHeaders())->postJson('/api/session', $body)->assertStatus(419);
    $this->assertGuest('web');

    $csrf = $this->withHeaders(dashboardHeaders())->get('/sanctum/csrf-cookie');
    $csrf->assertNoContent();
    // What the SPA does: echo the (encrypted) XSRF-TOKEN cookie back in the X-XSRF-TOKEN header.
    $xsrf = urldecode($csrf->getCookie('XSRF-TOKEN', false)->getValue());

    asDashboard($csrf->getCookie(sessionCookieName())->getValue())->withHeader('X-XSRF-TOKEN', $xsrf)
        ->postJson('/api/session', $body)->assertNoContent();
});
