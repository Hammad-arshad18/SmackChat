<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\DevicePushToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\NativePushNotifier;
use App\Services\WorkspaceProvisioner;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\E2eParty;
use Tests\TestCase;

class NativePushTest extends TestCase
{
    use RefreshDatabase;

    private const VOIP_LIN = 'aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11aa11';

    private const VOIP_GONE = 'bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22bb22';

    /** @var array<int, string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_a_phone_registers_and_removes_its_push_tokens(): void
    {
        [$owner] = $this->office();
        $grace = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->putJson('/api/push/native', [
            'platform' => 'ios',
            'kind' => 'expo',
            'token' => 'ExponentPushToken[abc]',
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->putJson('/api/push/native', [
            'platform' => 'ios',
            'kind' => 'voip',
            'token' => self::VOIP_LIN,
        ])->assertOk();
        $this->actingAs($owner, 'sanctum')->putJson('/api/push/native', [
            'platform' => 'android',
            'kind' => 'fcm',
            'token' => 'fcm:token-for-android',
        ])->assertOk();

        // Registering again is idempotent.
        $this->actingAs($owner, 'sanctum')->putJson('/api/push/native', [
            'platform' => 'ios',
            'kind' => 'expo',
            'token' => 'ExponentPushToken[abc]',
        ])->assertOk();
        $this->assertSame(3, DevicePushToken::query()->where('user_id', $owner->id)->count());

        // The wrong kind for the platform, or a malformed token, is refused.
        foreach ([
            ['platform' => 'android', 'kind' => 'voip', 'token' => self::VOIP_GONE],
            ['platform' => 'ios', 'kind' => 'fcm', 'token' => 'fcm:x'],
            ['platform' => 'ios', 'kind' => 'expo', 'token' => 'not-an-expo-token'],
            ['platform' => 'ios', 'kind' => 'voip', 'token' => 'zz'],
            ['platform' => 'windows', 'kind' => 'expo', 'token' => 'ExponentPushToken[x]'],
        ] as $bad) {
            $this->actingAs($owner, 'sanctum')->putJson('/api/push/native', $bad)->assertUnprocessable();
        }

        // Another person signing in on the same phone takes the token over.
        $this->actingAs($grace, 'sanctum')->putJson('/api/push/native', [
            'platform' => 'ios',
            'kind' => 'expo',
            'token' => 'ExponentPushToken[abc]',
        ])->assertOk();
        $this->assertSame($grace->id, DevicePushToken::query()->where('token_hash', DevicePushToken::hashFor('ExponentPushToken[abc]'))->value('user_id'));

        // Only the owner of a token can delete it.
        $this->actingAs($owner, 'sanctum')->deleteJson('/api/push/native', ['token' => 'ExponentPushToken[abc]'])->assertOk();
        $this->assertDatabaseHas('device_push_tokens', ['token_hash' => DevicePushToken::hashFor('ExponentPushToken[abc]')]);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/push/native', ['token' => self::VOIP_LIN])->assertOk();
        $this->assertDatabaseMissing('device_push_tokens', ['token_hash' => DevicePushToken::hashFor(self::VOIP_LIN)]);

        $this->app['auth']->forgetGuards();
        $this->putJson('/api/push/native', ['platform' => 'ios', 'kind' => 'expo', 'token' => 'ExponentPushToken[y]'])
            ->assertUnauthorized();
    }

    public function test_a_message_push_goes_to_expo_and_never_carries_the_text(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $client = $this->fakeClient();
        $this->configureProviders();

        $this->register($owner, 'ios', 'expo', 'ExponentPushToken[owner]');
        $this->register($lin, 'ios', 'expo', 'ExponentPushToken[lin]');
        $this->register($lin, 'ios', 'voip', self::VOIP_LIN);

        $this->actingAs($owner, 'sanctum');
        E2eParty::register($this)
            ->send($this, $workspace->slug, 'general', 'The launch number is 42.')
            ->assertCreated();

        $expo = $client->to('exp.host');
        $this->assertCount(1, $expo);
        $this->assertCount(0, $client->to('apple.com'));
        $this->assertCount(0, $client->to('googleapis.com'));

        $messages = json_decode($expo[0]['body'], true);
        // Only Lin: the author is not told about their own post.
        $this->assertSame(['ExponentPushToken[lin]'], array_column($messages, 'to'));
        $this->assertSame('Ada Lovelace: New message', $messages[0]['body']);
        $this->assertSame('message', $messages[0]['data']['type']);
        $this->assertSame('messages', $messages[0]['channelId']);
        $this->assertSame($workspace->slug, $messages[0]['data']['workspace']);
        $this->assertStringContainsString('?m=v1.', $messages[0]['data']['url']);

        foreach ($client->sent as $request) {
            $this->assertStringNotContainsString('launch number', $request['body']);
        }
    }

    public function test_a_message_reaches_android_over_fcm_without_the_text(): void
    {
        [$owner, $workspace] = $this->office();
        $grace = $this->member($workspace, 'Grace');
        $client = $this->fakeClient();
        $this->configureProviders();

        $this->register($grace, 'android', 'fcm', 'fcm:grace');

        $this->actingAs($owner, 'sanctum');
        E2eParty::register($this)
            ->send($this, $workspace->slug, 'general', 'The launch number is 42.')
            ->assertCreated();

        $this->assertCount(0, $client->to('exp.host'));
        $fcm = $client->to('fcm.googleapis.com');
        $this->assertCount(1, $fcm);
        $message = json_decode($fcm[0]['body'], true)['message'];
        $this->assertSame('fcm:grace', $message['token']);
        $this->assertArrayNotHasKey('notification', $message);
        $this->assertSame('HIGH', $message['android']['priority']);
        $this->assertArrayNotHasKey('collapse_key', $message['android']);
        $this->assertContainsOnly('string', $message['data']);

        // The shape expo-notifications draws by itself on Android.
        $this->assertSame('general', $message['data']['title']);
        $this->assertSame('Ada Lovelace: New message', $message['data']['message']);
        $this->assertSame('messages', $message['data']['channelId']);
        $data = json_decode($message['data']['body'], true);
        $this->assertSame('message', $data['type']);
        $this->assertStringContainsString('?m=v1.', $data['url']);
        $this->assertSame($data['tag'], $message['data']['tag']);

        foreach ($client->sent as $request) {
            $this->assertStringNotContainsString('launch number', $request['body']);
        }
    }

    public function test_a_call_rings_ios_over_voip_and_android_over_fcm_and_the_ring_is_cancelled(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');
        $sam = $this->member($workspace, 'Sam');
        $client = $this->fakeClient();
        $keys = $this->configureProviders();

        $this->register($owner, 'ios', 'voip', 'cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33cc33');
        $this->register($lin, 'ios', 'voip', self::VOIP_LIN);
        $this->register($lin, 'ios', 'expo', 'ExponentPushToken[lin]');
        $this->register($grace, 'android', 'fcm', 'fcm:grace');
        // Sam's build has no native ring token: an Expo notification stands in.
        $this->register($sam, 'android', 'expo', 'ExponentPushToken[sam]');

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general', 'kind' => 'video'])
            ->assertCreated()
            ->json('call');

        // iOS: one VoIP push, to Lin only (the caller is not rung).
        $apple = $client->to('apple.com');
        $this->assertCount(1, $apple);
        $this->assertSame('https://api.sandbox.push.apple.com/3/device/'.self::VOIP_LIN, $apple[0]['url']);
        $this->assertSame('2.0', $apple[0]['version']);
        $this->assertSame('voip', $apple[0]['headers']['apns-push-type']);
        $this->assertSame('chat.smack.app.voip', $apple[0]['headers']['apns-topic']);
        $this->assertSame('10', $apple[0]['headers']['apns-priority']);
        $this->assertSame('call-'.$call['id'], $apple[0]['headers']['apns-collapse-id']);
        $this->assertApnsJwtIsSigned($apple[0]['headers']['authorization'], $keys['apns_public']);

        $ring = json_decode($apple[0]['body'], true);
        $this->assertSame('call', $ring['type']);
        $this->assertSame('Ada Lovelace', $ring['title']);
        $this->assertSame('Huddle in #general', $ring['body']);
        $this->assertSame($call['id'], $ring['call_id']);
        $this->assertSame($workspace->slug, $ring['workspace']);
        $this->assertSame('video', $ring['kind']);
        $this->assertSame('smack-call-'.$call['id'], $ring['tag']);
        $this->assertSame('/w/'.$workspace->slug.'/c/general?call='.$call['id'], $ring['url']);
        $this->assertSame((string) intdiv($ring['expires_at'], 1000), $apple[0]['headers']['apns-expiration']);
        $this->assertGreaterThan($ring['started_at'], $ring['expires_at']);

        // Android: an OAuth token, then one high-priority data message.
        $this->assertCount(1, $client->to('oauth2.googleapis.com'));
        $fcm = $client->to('fcm.googleapis.com');
        $this->assertCount(1, $fcm);
        $this->assertSame('https://fcm.googleapis.com/v1/projects/smack-test/messages:send', $fcm[0]['url']);
        $this->assertSame('Bearer fcm-access-token', $fcm[0]['headers']['authorization']);
        $message = json_decode($fcm[0]['body'], true)['message'];
        $this->assertSame('fcm:grace', $message['token']);
        $this->assertArrayNotHasKey('notification', $message);
        $this->assertSame('HIGH', $message['android']['priority']);
        $this->assertSame('call-'.$call['id'], $message['android']['collapse_key']);
        $this->assertSame('call', $message['data']['type']);
        $this->assertSame((string) $call['id'], $message['data']['call_id']);
        $this->assertSame('false', $message['data']['is_dm']);
        $this->assertContainsOnly('string', $message['data']);
        $this->assertSame('Ada Lovelace', $message['data']['title']);
        $this->assertSame('Huddle in #general', $message['data']['message']);
        $this->assertSame('calls', $message['data']['channelId']);
        $this->assertSame('incoming-call', $message['data']['categoryId']);
        $this->assertSame('smack-call-'.$call['id'], $message['data']['tag']);
        $this->assertSame($call['id'], json_decode($message['data']['body'], true)['call_id']);

        // Only Sam falls back to Expo; Lin's phone rings through CallKit.
        $expo = $client->to('exp.host');
        $this->assertCount(1, $expo);
        $fallback = json_decode($expo[0]['body'], true);
        $this->assertSame(['ExponentPushToken[sam]'], array_column($fallback, 'to'));
        $this->assertSame('call', $fallback[0]['data']['type']);
        $this->assertSame('calls', $fallback[0]['channelId']);
        $this->assertSame('incoming-call', $fallback[0]['categoryId']);

        // Nobody answers and the caller hangs up.
        $client->sent = [];
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/leave")
            ->assertOk();

        $apple = $client->to('apple.com');
        $this->assertCount(1, $apple);
        $ended = json_decode($apple[0]['body'], true);
        $this->assertSame('call-ended', $ended['type']);
        $this->assertSame($call['id'], $ended['call_id']);
        $this->assertFalse($ended['answered']);

        $fcm = $client->to('fcm.googleapis.com');
        $this->assertCount(1, $fcm);
        $ended = json_decode($fcm[0]['body'], true)['message']['data'];
        $this->assertSame('call-ended', $ended['type']);
        // Same tag as the ring, so "Missed call" takes its place in the tray.
        $this->assertSame('smack-call-'.$call['id'], $ended['tag']);
        $this->assertSame('Missed call', $ended['title']);
        $this->assertSame('Missed call from Ada Lovelace', $ended['message']);
        $this->assertSame('messages', $ended['channelId']);
        // The access token is reused, not fetched again.
        $this->assertCount(0, $client->to('oauth2.googleapis.com'));

        $missed = json_decode($client->to('exp.host')[0]['body'], true);
        $this->assertEqualsCanonicalizing(['ExponentPushToken[lin]', 'ExponentPushToken[sam]'], array_column($missed, 'to'));
        $this->assertSame('Missed call', $missed[0]['title']);
        $this->assertSame('Missed call from Ada Lovelace', $missed[0]['body']);
        $this->assertSame('messages', $missed[0]['channelId']);
    }

    public function test_a_call_answered_elsewhere_clears_the_android_ring_silently(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');
        $client = $this->fakeClient();
        $this->configureProviders();

        $this->register($grace, 'android', 'fcm', 'fcm:grace');

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated()
            ->json('call');
        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk();

        $client->sent = [];
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/leave")
            ->assertOk();
        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/leave")
            ->assertOk();

        $fcm = $client->to('fcm.googleapis.com');
        $this->assertCount(1, $fcm);
        $ended = json_decode($fcm[0]['body'], true)['message']['data'];
        $this->assertSame('call-ended', $ended['type']);
        $this->assertSame('true', $ended['answered']);
        // No title or text: nothing is drawn, the app takes the ring away.
        $this->assertSame('', $ended['title']);
        $this->assertSame('', $ended['message']);
        $this->assertCount(0, $client->to('exp.host'));
    }

    public function test_a_direct_call_rings_with_the_call_kind(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $client = $this->fakeClient();
        $this->configureProviders();
        $this->register($lin, 'ios', 'voip', self::VOIP_LIN);

        $dm = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/dms", ['user_id' => $lin->id])
            ->assertOk()
            ->json('slug');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => $dm, 'kind' => 'audio'])
            ->assertCreated();

        $ring = json_decode($client->to('apple.com')[0]['body'], true);
        $this->assertSame('Incoming voice call', $ring['body']);
        $this->assertTrue($ring['is_dm']);
    }

    public function test_dead_tokens_are_pruned(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');
        $client = $this->fakeClient();
        $this->configureProviders();

        $this->register($lin, 'ios', 'expo', 'ExponentPushToken[alive]');
        $this->register($lin, 'ios', 'expo', 'ExponentPushToken[gone]');
        $this->register($lin, 'ios', 'voip', self::VOIP_LIN);
        $this->register($lin, 'ios', 'voip', self::VOIP_GONE);
        $this->register($grace, 'android', 'fcm', 'fcm:alive');
        $this->register($grace, 'android', 'fcm', 'fcm:gone');

        $client->dead = ['ExponentPushToken[gone]', self::VOIP_GONE, 'fcm:gone'];

        $this->actingAs($owner, 'sanctum');
        E2eParty::register($this)->send($this, $workspace->slug, 'general', 'hello')->assertCreated();
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated();

        $left = DevicePushToken::query()->pluck('token')->all();
        $this->assertEqualsCanonicalizing(['ExponentPushToken[alive]', self::VOIP_LIN, 'fcm:alive'], $left);
    }

    public function test_missing_provider_keys_skip_native_rings_quietly(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');
        $client = $this->fakeClient();
        config([
            'services.apns' => ['key_id' => null, 'team_id' => null, 'key_path' => null, 'bundle_id' => null, 'production' => false],
            'services.fcm' => ['project_id' => null, 'credentials_path' => null],
        ]);

        $this->register($lin, 'ios', 'voip', self::VOIP_LIN);
        $this->register($grace, 'android', 'fcm', 'fcm:grace');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated();

        $this->assertSame([], $client->sent);
        $this->assertSame(2, DevicePushToken::query()->count());
    }

    // ------------------------------------------------------------- helpers

    public function test_a_test_push_reaches_the_phone_and_reports_what_happened(): void
    {
        $grace = User::factory()->create();
        $client = $this->fakeClient();
        $this->configureProviders();
        $this->register($grace, 'android', 'fcm', 'fcm:grace');
        $this->register($grace, 'android', 'fcm', 'fcm:old-phone');
        $client->dead[] = 'fcm:old-phone';

        $response = $this->actingAs($grace, 'sanctum')->postJson('/api/push/native/test')->assertOk();

        $response->assertJsonPath('fcm.configured', true);
        $devices = collect($response->json('devices'));
        $this->assertTrue($devices->contains(fn ($d) => $d['kind'] === 'fcm' && $d['ok'] === true && $d['error'] === null));
        $this->assertTrue($devices->contains(fn ($d) => $d['kind'] === 'fcm' && $d['ok'] === false && $d['error'] === 'UNREGISTERED'));

        $fcm = $client->to('fcm.googleapis.com');
        $this->assertCount(2, $fcm);
        $data = json_decode($fcm[0]['body'], true)['message']['data'];
        $this->assertSame('SmackChat', $data['title']);
        $this->assertSame('messages', $data['channelId']);
        $this->assertSame(1, DevicePushToken::query()->where('user_id', $grace->id)->count());
    }

    public function test_a_test_push_says_when_the_server_has_no_fcm_key(): void
    {
        $grace = User::factory()->create();
        $client = $this->fakeClient();
        $this->register($grace, 'android', 'fcm', 'fcm:grace');

        config(['services.fcm' => ['project_id' => 'smack-test', 'credentials_path' => 'storage/app/missing-key.json']]);
        $this->actingAs($grace, 'sanctum')->postJson('/api/push/native/test')
            ->assertOk()
            ->assertJsonPath('fcm.configured', false)
            ->assertJsonPath('fcm.problem', 'The FCM key file is missing or unreadable at FCM_CREDENTIALS_PATH.')
            ->assertJsonPath('devices.0.ok', false)
            ->assertJsonPath('devices.0.error', 'FCM is not set up on the server.');

        config(['services.fcm' => ['project_id' => null, 'credentials_path' => null]]);
        $this->actingAs($grace, 'sanctum')->postJson('/api/push/native/test')
            ->assertJsonPath('fcm.problem', 'FCM_PROJECT_ID or FCM_CREDENTIALS_PATH is not set.');

        $this->assertCount(0, $client->to('fcm.googleapis.com'));
    }

    private function fakeClient(): object
    {
        $client = new class implements ClientInterface
        {
            /** @var list<array{url: string, version: string, headers: array<string, string>, body: string}> */
            public array $sent = [];

            /** @var list<string> */
            public array $dead = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $url = (string) $request->getUri();
                $body = (string) $request->getBody();
                $headers = [];

                foreach (array_keys($request->getHeaders()) as $name) {
                    $headers[strtolower($name)] = $request->getHeaderLine($name);
                }

                $this->sent[] = ['url' => $url, 'version' => $request->getProtocolVersion(), 'headers' => $headers, 'body' => $body];

                if (str_contains($url, 'exp.host')) {
                    $tickets = array_map(fn (array $message) => in_array($message['to'], $this->dead, true)
                        ? ['status' => 'error', 'message' => 'not registered', 'details' => ['error' => 'DeviceNotRegistered']]
                        : ['status' => 'ok', 'id' => uniqid()], json_decode($body, true));

                    return new Response(200, [], json_encode(['data' => $tickets]));
                }

                if (str_contains($url, 'oauth2.googleapis.com')) {
                    return new Response(200, [], json_encode(['access_token' => 'fcm-access-token', 'expires_in' => 3599]));
                }

                if (str_contains($url, 'apple.com')) {
                    $token = basename(parse_url($url, PHP_URL_PATH));

                    return in_array($token, $this->dead, true)
                        ? new Response(410, [], json_encode(['reason' => 'Unregistered']))
                        : new Response(200);
                }

                if (str_contains($url, 'fcm.googleapis.com')) {
                    $token = json_decode($body, true)['message']['token'];

                    return in_array($token, $this->dead, true)
                        ? new Response(404, [], json_encode(['error' => [
                            'code' => 404,
                            'status' => 'NOT_FOUND',
                            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
                        ]]))
                        : new Response(200, [], json_encode(['name' => 'projects/smack-test/messages/1']));
                }

                return new Response(500);
            }

            /**
             * @return list<array{url: string, version: string, headers: array<string, string>, body: string}>
             */
            public function to(string $host): array
            {
                return array_values(array_filter($this->sent, fn (array $request) => str_contains(parse_url($request['url'], PHP_URL_HOST), $host)));
            }
        };

        $push = new NativePushNotifier;
        $push->useClient($client);
        $this->app->instance(NativePushNotifier::class, $push);

        return $client;
    }

    /**
     * Throwaway APNs and FCM keys on disk.
     *
     * @return array{apns_public: string}
     */
    private function configureProviders(): array
    {
        $ec = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($ec, $ecPem);
        $apnsPath = $this->tempFile($ecPem);

        $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($rsa, $rsaPem);
        $fcmPath = $this->tempFile(json_encode([
            'type' => 'service_account',
            'project_id' => 'smack-test',
            'client_email' => 'push@smack-test.iam.gserviceaccount.com',
            'private_key' => $rsaPem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config([
            'services.apns' => [
                'key_id' => 'ABC123DEFG',
                'team_id' => 'TEAM123456',
                'key_path' => $apnsPath,
                'bundle_id' => 'chat.smack.app',
                'production' => false,
            ],
            'services.fcm' => [
                'project_id' => 'smack-test',
                'credentials_path' => $fcmPath,
            ],
        ]);

        return ['apns_public' => openssl_pkey_get_details($ec)['key']];
    }

    private function assertApnsJwtIsSigned(string $authorization, string $publicKey): void
    {
        $this->assertStringStartsWith('bearer ', $authorization);
        [$header, $claims, $signature] = explode('.', substr($authorization, 7));
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(['alg' => 'ES256', 'kid' => 'ABC123DEFG'], json_decode($decode($header), true));
        $this->assertSame('TEAM123456', json_decode($decode($claims), true)['iss']);

        $raw = $decode($signature);
        $this->assertSame(64, strlen($raw));
        $integer = function (string $part): string {
            $part = ltrim($part, "\x00");

            if (ord($part[0]) > 0x7F) {
                $part = "\x00".$part;
            }

            return "\x02".chr(strlen($part)).$part;
        };
        $sequence = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32));
        $der = "\x30".chr(strlen($sequence)).$sequence;

        $this->assertSame(1, openssl_verify($header.'.'.$claims, $der, $publicKey, OPENSSL_ALGO_SHA256));
    }

    private function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'smack-push-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    private function register(User $user, string $platform, string $kind, string $token): void
    {
        $this->actingAs($user, 'sanctum')->putJson('/api/push/native', [
            'platform' => $platform,
            'kind' => $kind,
            'token' => $token,
        ])->assertOk();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function office(): array
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

        $this->postJson('/api/register/verify', [
            'email' => 'ada@northwind.test',
            'code' => $code,
        ])->assertCreated();

        $owner = User::query()->where('email', 'ada@northwind.test')->firstOrFail();

        return [$owner, $owner->currentWorkspace];
    }

    private function member(Workspace $workspace, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(WorkspaceProvisioner::class)->admit($workspace, $user, 'member');

        return $user;
    }
}
