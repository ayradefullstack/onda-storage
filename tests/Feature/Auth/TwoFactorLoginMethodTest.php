<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\Auth\LoginAlertNotification;
use App\Services\Auth\LoginMethod;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Which proof a two-factor login used is read from Fortify's own outcome (the
 * recovery code actually consumed), not from the presence of a request field.
 * Real HTTP, real RecoveryCodeReplaced event; only notifications are faked.
 */
const TFM_IP = '203.0.113.88';
const TFM_RECOVERY = ['tfm-recovery-aaaa', 'tfm-recovery-bbbb'];

/** @return array{0: User, 1: string} */
function tfmUser(): array
{
    $secret = app(Google2FA::class)->generateSecretKey();

    $user = User::factory()->withRole('author')->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(TFM_RECOVERY)),
        'two_factor_confirmed_at' => now(),
    ]);

    return [$user, $secret];
}

/** Password step, then the challenge with the given fields. */
function tfmChallenge(mixed $test, User $user, array $fields): void
{
    $email = $user->email;
    RateLimiter::clear(md5('login'.Str::transliterate(Str::lower(trim($email)).'|'.TFM_IP)));
    RateLimiter::clear(md5('login'.'ip:'.TFM_IP));

    $client = $test->withServerVariables(['REMOTE_ADDR' => TFM_IP]);
    $client->post(route('login.store'), ['email' => $email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    $client->post(route('two-factor.login'), $fields);
}

/** @return list<string> */
function tfmCodes(User $user): array
{
    return json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true);
}

function tfmAssertSingleAlert(User $user, LoginMethod $method, string ...$secrets): void
{
    $sent = Notification::sent($user, LoginAlertNotification::class);
    expect($sent)->toHaveCount(1);

    Notification::assertSentTo(
        $user,
        LoginAlertNotification::class,
        fn ($n) => $n->toArray($user)['method'] === $method->value,
    );

    foreach ($sent as $notification) {
        $serialised = serialize($notification);
        foreach ($secrets as $secret) {
            expect($serialised)->not->toContain($secret);
        }
    }
}

beforeEach(fn () => Notification::fake());

test('a valid TOTP alone alerts with the two_factor method', function () {
    [$user, $secret] = tfmUser();
    $totp = app(Google2FA::class)->getCurrentOtp($secret);

    tfmChallenge($this, $user, ['code' => $totp]);

    $this->assertAuthenticatedAs($user);
    tfmAssertSingleAlert($user, LoginMethod::TwoFactor, $totp, ...TFM_RECOVERY);
    expect(tfmCodes($user))->toBe(TFM_RECOVERY);
});

test('a valid recovery code alone alerts with the recovery_code method and is consumed', function () {
    [$user] = tfmUser();

    tfmChallenge($this, $user, ['recovery_code' => TFM_RECOVERY[0]]);

    $this->assertAuthenticatedAs($user);
    tfmAssertSingleAlert($user, LoginMethod::RecoveryCode, ...TFM_RECOVERY);
    expect(tfmCodes($user))->toHaveCount(2)->not->toContain(TFM_RECOVERY[0])->toContain(TFM_RECOVERY[1]);
});

test('a valid TOTP with a junk recovery_code is a two_factor login and consumes nothing', function () {
    [$user, $secret] = tfmUser();
    $totp = app(Google2FA::class)->getCurrentOtp($secret);

    tfmChallenge($this, $user, ['code' => $totp, 'recovery_code' => 'tfm-junk-code']);

    $this->assertAuthenticatedAs($user);
    tfmAssertSingleAlert($user, LoginMethod::TwoFactor, $totp, 'tfm-junk-code', ...TFM_RECOVERY);
    expect(tfmCodes($user))->toBe(TFM_RECOVERY);
});

test('a junk TOTP with a valid recovery code is a recovery_code login', function () {
    [$user, $secret] = tfmUser();
    $valid = app(Google2FA::class)->getCurrentOtp($secret);
    $junk = $valid === '000000' ? '111111' : '000000';

    tfmChallenge($this, $user, ['code' => $junk, 'recovery_code' => TFM_RECOVERY[1]]);

    $this->assertAuthenticatedAs($user);
    tfmAssertSingleAlert($user, LoginMethod::RecoveryCode, $junk, ...TFM_RECOVERY);
    expect(tfmCodes($user))->toHaveCount(2)->not->toContain(TFM_RECOVERY[1]);
});

test('both valid: the recovery code wins and is consumed', function () {
    [$user, $secret] = tfmUser();
    $totp = app(Google2FA::class)->getCurrentOtp($secret);

    tfmChallenge($this, $user, ['code' => $totp, 'recovery_code' => TFM_RECOVERY[0]]);

    $this->assertAuthenticatedAs($user);
    tfmAssertSingleAlert($user, LoginMethod::RecoveryCode, $totp, ...TFM_RECOVERY);
    expect(tfmCodes($user))->toHaveCount(2)->not->toContain(TFM_RECOVERY[0]);
});

test('both invalid: no login and nothing is sent', function () {
    [$user, $secret] = tfmUser();
    $valid = app(Google2FA::class)->getCurrentOtp($secret);
    $junk = $valid === '000000' ? '111111' : '000000';

    tfmChallenge($this, $user, ['code' => $junk, 'recovery_code' => 'tfm-junk-code']);

    $this->assertGuest();
    Notification::assertNothingSent();
    expect(tfmCodes($user))->toBe(TFM_RECOVERY);
});
