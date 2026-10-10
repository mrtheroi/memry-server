<?php

use App\Mail\LoginCodeMail;
use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function wrongCode(string $code): string
{
    return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
}

function requestLoginCode(string $email): string
{
    test()->postJson('/api/auth/code', ['email' => $email])->assertAccepted();

    $code = null;
    Mail::assertSent(LoginCodeMail::class, function (LoginCodeMail $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

test('it sends a login code to the email and answers 202', function () {
    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])
        ->assertAccepted()
        ->assertExactJson(['message' => 'If the email is valid, a login code has been sent.']);

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('ada@example.com')
        && preg_match('/^\d{6}$/', $mail->code) === 1);
});

test('it rejects an invalid email when requesting a code', function (mixed $email, string $error) {
    $this->postJson('/api/auth/code', ['email' => $email])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => $error]);

    Mail::assertNothingSent();
})->with([
    'missing' => [null, 'The email field is required.'],
    'not a string' => [['ada@example.com'], 'The email field must be a string.'],
    'not an email' => ['not-an-email', 'The email field must be a valid email address.'],
    'too long' => [str_repeat('a', 250).'@example.com', 'The email field must not be greater than 255 characters.'],
]);

test('it signs up a new user and issues a token for a valid code', function () {
    $code = requestLoginCode('ada@example.com');

    $response = $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code]);

    $response->assertOk()->assertJsonStructure(['token']);
    $user = User::where('email', 'ada@example.com')->sole();
    expect($user->name)->toBe('ada')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->tokens()->sole()->name)->toBe('memry-cli');
});

test('it issues a token to an existing user without creating another one', function () {
    $user = User::factory()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);
    $code = requestLoginCode('ada@example.com');

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])->assertOk();

    expect(User::count())->toBe(1)
        ->and($user->fresh()->name)->toBe('Ada Lovelace')
        ->and($user->tokens()->sole()->name)->toBe('memry-cli');
});

test('it rejects a wrong code', function () {
    $code = requestLoginCode('ada@example.com');

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => wrongCode($code)])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Invalid or expired code.']);

    expect(User::count())->toBe(0)
        ->and(LoginCode::sole()->attempts)->toBe(1);
});

test('it burns the code after five wrong attempts', function () {
    $code = requestLoginCode('ada@example.com');

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => wrongCode($code)])
            ->assertUnprocessable();
    }

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Invalid or expired code.']);
});

test('it accepts a code just before it expires', function () {
    $code = requestLoginCode('ada@example.com');

    $this->travel(4)->minutes();
    $this->travel(59)->seconds();

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])->assertOk();
});

test('it rejects an expired code', function () {
    $code = requestLoginCode('ada@example.com');

    $this->travel(5)->minutes();
    $this->travel(1)->second();

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Invalid or expired code.']);
});

test('it never accepts the same code twice', function () {
    $code = requestLoginCode('ada@example.com');

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])->assertOk();

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Invalid or expired code.']);
});

test('it invalidates the previous code when a new one is issued', function () {
    $first = requestLoginCode('ada@example.com');
    Mail::fake();
    $second = requestLoginCode('ada@example.com');

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $second])->assertOk();

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $first])
        ->assertUnprocessable()
        ->assertExactJson(['message' => 'Invalid or expired code.']);
});

test('it trims and lowercases the email on both endpoints', function () {
    $code = requestLoginCode(' Ada@Example.COM ');

    Mail::assertSent(LoginCodeMail::class, fn (LoginCodeMail $mail) => $mail->hasTo('ada@example.com'));

    $this->postJson('/api/auth/token', ['email' => 'ADA@example.com ', 'code' => $code])->assertOk();

    expect(User::sole()->email)->toBe('ada@example.com');
});

test('it rejects an invalid token request', function (array $payload, string $field, string $error) {
    $this->postJson('/api/auth/token', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $error]);

    expect(User::count())->toBe(0);
})->with([
    'missing email' => [['code' => '123456'], 'email', 'The email field is required.'],
    'email not a string' => [['email' => ['ada@example.com'], 'code' => '123456'], 'email', 'The email field must be a string.'],
    'not an email' => [['email' => 'not-an-email', 'code' => '123456'], 'email', 'The email field must be a valid email address.'],
    'email too long' => [['email' => str_repeat('a', 250).'@example.com', 'code' => '123456'], 'email', 'The email field must not be greater than 255 characters.'],
    'missing code' => [['email' => 'ada@example.com'], 'code', 'The code field is required.'],
    'code not a string' => [['email' => 'ada@example.com', 'code' => 123456], 'code', 'The code field must be a string.'],
    'code too short' => [['email' => 'ada@example.com', 'code' => '12345'], 'code', 'The code field must be 6 digits.'],
    'code not digits' => [['email' => 'ada@example.com', 'code' => '12345a'], 'code', 'The code field must be 6 digits.'],
]);

test('the issued token authenticates against the context endpoint', function () {
    $code = requestLoginCode('ada@example.com');
    $token = $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])->json('token');

    $this->withToken($token)->get('/api/context?project=dbmcp')->assertOk();
});

test('it stores only a hash of the code', function () {
    $code = requestLoginCode('ada@example.com');

    $stored = LoginCode::sole();
    expect($stored->code_hash)->not->toBe($code)
        ->and($stored->code_hash)->toBe(hash_hmac('sha256', $code, config('app.key')));
});

test('it limits each email to 3 code requests per 10 minutes', function () {
    foreach (range(1, 3) as $request) {
        $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
    }

    $this->postJson('/api/auth/code', ['email' => 'ADA@example.com'])->assertTooManyRequests();
    $this->postJson('/api/auth/code', ['email' => 'grace@example.com'])->assertAccepted();

    $this->travel(10)->minutes();

    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
});

test('it limits each IP to 10 code requests per hour', function () {
    foreach (range(1, 10) as $request) {
        $this->postJson('/api/auth/code', ['email' => "user{$request}@example.com"])->assertAccepted();
    }

    $this->postJson('/api/auth/code', ['email' => 'user11@example.com'])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/auth/code', ['email' => 'user11@example.com'])->assertAccepted();
});

test('it limits each IP to 20 token requests per minute', function () {
    foreach (range(1, 20) as $request) {
        $this->postJson('/api/auth/token', ['email' => "user{$request}@example.com", 'code' => '123456'])
            ->assertUnprocessable();
    }

    $this->postJson('/api/auth/token', ['email' => 'user21@example.com', 'code' => '123456'])->assertTooManyRequests();
});

test('it answers the exact validation json for an invalid email', function () {
    $this->postJson('/api/auth/code', ['email' => 'not-an-email'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The email field must be a valid email address.',
            'errors' => ['email' => ['The email field must be a valid email address.']],
        ]);
});

test('it answers the exact validation json when the token request is empty', function () {
    $this->postJson('/api/auth/token', [])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The email field is required. (and 1 more error)',
            'errors' => [
                'email' => ['The email field is required.'],
                'code' => ['The code field is required.'],
            ],
        ]);
});

test('it answers the exact token json, a sanctum plain text token, for a valid code', function () {
    $code = requestLoginCode('ada@example.com');

    $response = $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => $code])->assertOk();

    expect(array_keys($response->json()))->toBe(['token'])
        ->and($response->json('token'))->toMatch('/^\d+\|[A-Za-z0-9]{48}$/');
});

test('it answers 429 with the throttle message and a retry-after header', function () {
    foreach (range(1, 3) as $request) {
        $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])->assertAccepted();
    }

    $this->postJson('/api/auth/code', ['email' => 'ada@example.com'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too Many Attempts.')
        ->assertHeader('Retry-After');

    Mail::assertSentCount(3);
});

test('it keeps the token throttle bucket per IP regardless of the email', function () {
    foreach (range(1, 20) as $request) {
        $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => '123456'])->assertUnprocessable();
    }

    $this->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => '123456'])
        ->assertTooManyRequests()
        ->assertJsonPath('message', 'Too Many Attempts.');
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/auth/token', ['email' => 'ada@example.com', 'code' => '123456'])->assertUnprocessable();
});
