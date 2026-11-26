<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class MobileAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_on_the_phone_issues_a_bearer_token_that_reaches_the_workspace(): void
    {
        $registered = $this->registerOnPhone();

        $registered->assertCreated()
            ->assertJsonPath('user.email', 'ada@northwind.test')
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'workspaces'], 'token', 'workspace_slug']);

        $token = $registered->json('token');
        $slug = $registered->json('workspace_slug');
        $this->assertSame('mobile:Ada’s iPhone', PersonalAccessToken::findToken($token)->name);

        $this->bearer($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'ada@northwind.test');

        $this->bearer($token)->getJson("/api/workspaces/{$slug}/channels")->assertOk();

        // No cookie session was made for the phone.
        $this->guest();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_mobile_login_issues_a_token_and_needs_a_device_name(): void
    {
        $this->registerOnPhone();

        $this->postJson('/api/mobile/login', [
            'email' => 'ada@northwind.test',
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('device_name');

        $login = $this->postJson('/api/mobile/login', [
            'email' => 'ADA@northwind.test',
            'password' => 'password',
            'device_name' => 'Pixel 9',
        ])->assertOk()->assertJsonPath('user.email', 'ada@northwind.test');

        $this->assertArrayNotHasKey('workspace_slug', $login->json());
        $this->assertSame('mobile:Pixel 9', PersonalAccessToken::findToken($login->json('token'))->name);
        $this->bearer($login->json('token'))->getJson('/api/me')->assertOk();
    }

    public function test_mobile_login_shares_the_lockout_with_the_browser(): void
    {
        $this->registerOnPhone();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'ada@northwind.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/mobile/login', [
                'email' => 'ada@northwind.test',
                'password' => 'wrong-password',
                'device_name' => 'iPhone',
            ])->assertStatus(422);
        }

        $this->postJson('/api/mobile/login', [
            'email' => 'ada@northwind.test',
            'password' => 'password',
            'device_name' => 'iPhone',
        ])->assertStatus(429);

        $this->assertSame(1, PersonalAccessToken::query()->count());
    }

    public function test_mobile_logout_revokes_the_token_and_its_push_tokens(): void
    {
        $token = $this->registerOnPhone()->json('token');
        $other = $this->guest()->postJson('/api/mobile/login', [
            'email' => 'ada@northwind.test',
            'password' => 'password',
            'device_name' => 'iPad',
        ])->json('token');

        $this->bearer($token)->putJson('/api/push/native', [
            'platform' => 'ios',
            'kind' => 'expo',
            'token' => 'ExponentPushToken[phone-one]',
        ])->assertOk();
        $this->bearer($other)->putJson('/api/push/native', [
            'platform' => 'ios',
            'kind' => 'expo',
            'token' => 'ExponentPushToken[ipad]',
        ])->assertOk();

        $this->assertSame(
            PersonalAccessToken::findToken($token)->id,
            DevicePushToken::query()->where('token', 'ExponentPushToken[phone-one]')->value('personal_access_token_id'),
        );

        $this->bearer($token)->postJson('/api/mobile/logout')->assertOk()->assertJsonPath('ok', true);

        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertDatabaseMissing('device_push_tokens', ['token_hash' => DevicePushToken::hashFor('ExponentPushToken[phone-one]')]);
        $this->assertDatabaseHas('device_push_tokens', ['token_hash' => DevicePushToken::hashFor('ExponentPushToken[ipad]')]);

        $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
        $this->bearer($other)->getJson('/api/me')->assertOk();

        // The browser's logout route also revokes a bearer token it is given.
        $this->bearer($other)->postJson('/api/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($other));
        $this->bearer($other)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_password_reset_revokes_phone_tokens(): void
    {
        $token = $this->registerOnPhone()->json('token');
        $this->bearer($token)->putJson('/api/push/native', [
            'platform' => 'android',
            'kind' => 'fcm',
            'token' => 'fcm-token-for-the-pixel',
        ])->assertOk();

        $user = User::query()->where('email', 'ada@northwind.test')->firstOrFail();
        $reset = PasswordBroker::broker()->createToken($user);
        $this->guest();

        $this->postJson('/api/reset-password', [
            'token' => $reset,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertNull(PersonalAccessToken::findToken($token));
        $this->assertSame(0, DevicePushToken::query()->count());
        $this->bearer($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_an_invite_can_be_accepted_on_the_phone(): void
    {
        $ownerToken = $this->registerOnPhone()->json('token');
        $slug = User::query()->where('email', 'ada@northwind.test')->firstOrFail()->currentWorkspace->slug;

        $invite = fn (string $email) => basename(parse_url($this->bearer($ownerToken)
            ->postJson("/api/workspaces/{$slug}/invitations", ['email' => $email, 'role' => 'member'])
            ->assertCreated()
            ->json('invitation.url'), PHP_URL_PATH));

        // A new person creates their account and gets a token.
        $newcomer = $invite('new@northwind.test');
        $this->guest();
        $this->postJson("/api/mobile/invitations/{$newcomer}/accept", [
            'name' => 'New Person',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('device_name');

        $accepted = $this->postJson("/api/mobile/invitations/{$newcomer}/accept", [
            'name' => 'New Person',
            'password' => 'password',
            'password_confirmation' => 'password',
            'device_name' => 'Galaxy',
        ])->assertOk()
            ->assertJsonPath('workspace_slug', $slug)
            ->assertJsonPath('user.email', 'new@northwind.test');

        $this->bearer($accepted->json('token'))->getJson("/api/workspaces/{$slug}/channels")->assertOk();

        // Someone already signed in on the phone keeps their token.
        $grace = User::factory()->create(['email' => 'grace@northwind.test']);
        $graceToken = $grace->createToken('mobile:iPhone')->plainTextToken;
        $existing = $invite('grace@northwind.test');

        $this->bearer($graceToken)->postJson("/api/mobile/invitations/{$existing}/accept")
            ->assertOk()
            ->assertJsonPath('token', null)
            ->assertJsonPath('workspace_slug', $slug);

        $this->assertDatabaseHas('workspace_members', ['user_id' => $grace->id, 'role' => 'member']);
        $this->assertSame(1, $grace->tokens()->count());
    }

    private function registerOnPhone(): TestResponse
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@northwind.test',
            'password' => 'password',
            'password_confirmation' => 'password',
            'workspace_name' => 'Northwind Studio',
            'timezone' => 'UTC',
        ])->assertStatus(202);

        $code = null;
        Mail::assertSent(RegistrationCodeMail::class, function (RegistrationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $this->postJson('/api/mobile/register/verify', [
            'email' => 'ada@northwind.test',
            'code' => $code,
            'device_name' => 'Ada’s iPhone',
        ]);
    }

    /**
     * A request that carries only the bearer token, with no user left over
     * from an earlier request in the same test.
     */
    private function bearer(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function guest(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withoutToken();
    }
}
