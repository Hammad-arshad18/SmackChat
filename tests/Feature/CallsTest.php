<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\Call;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AblyPublisher;
use App\Services\WebPushNotifier;
use App\Services\WorkspaceProvisioner;
use Base64Url\Base64Url;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class CallsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_free_workspace_can_huddle_one_to_one_but_not_three(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated()
            ->assertJsonPath('call.participants.0.user_id', $owner->id)
            ->assertJsonPath('call.max_participants', 2)
            ->json('call');

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk()
            ->assertJsonPath('call.max_participants', 2);

        $this->actingAs($grace, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertStatus(409)
            ->assertJsonPath('message', fn ($message) => str_contains($message, 'one-to-one'));

        $this->actingAs($grace, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
            ->assertForbidden();

        $this->actingAs($grace, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signal", [
                'to' => $owner->id,
                'type' => 'offer',
                'payload' => ['sdp' => 'v=0'],
            ])
            ->assertForbidden();
    }

    public function test_the_archive_plan_unlocks_group_huddles(): void
    {
        [$owner, $workspace] = $this->office();
        $workspace->update(['plan' => 'archive']);
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general', 'kind' => 'video'])
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('call.group_enabled', true)
            ->json('call');

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk();

        $this->actingAs($grace, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated()
            ->assertJsonPath('created', false)
            ->assertJsonCount(3, 'call.participants');
    }

    public function test_signals_are_private_to_the_addressee(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signal", [
                'to' => $lin->id,
                'type' => 'offer',
                'payload' => ['sdp' => 'v=0 secret-handshake'],
            ])
            ->assertOk();

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signal", [
                'to' => $owner->id,
                'type' => 'refresh',
                'payload' => ['ask' => 1],
            ])
            ->assertOk();

        $this->actingAs($lin, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
            ->assertOk()
            ->assertJsonCount(1, 'signals')
            ->assertJsonPath('signals.0.type', 'offer')
            ->assertJsonPath('signals.0.from', $owner->id);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
            ->assertOk()
            ->assertJsonCount(1, 'signals')
            ->assertJsonPath('signals.0.type', 'refresh')
            ->assertJsonPath('signals.0.from', $lin->id);

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signal", [
                'to' => $owner->id,
                'type' => 'status',
                'payload' => ['sharing' => true],
            ])
            ->assertOk();
    }

    public function test_outsiders_cannot_start_or_read_a_private_huddle(): void
    {
        [$owner, $workspace] = $this->office();
        $outsider = User::factory()->create();

        $slug = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/channels", [
                'name' => 'Board room',
                'private' => true,
                'member_ids' => [],
            ])->assertCreated()->json('slug');

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => $slug])
            ->assertForbidden();

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => $slug])
            ->assertCreated()
            ->json('call');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}")
            ->assertForbidden();
    }

    public function test_a_new_call_rings_closed_tabs_but_joining_does_not(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');

        $bag = new \stdClass;
        $bag->rings = [];
        $this->app->instance(WebPushNotifier::class, new class($bag) extends WebPushNotifier
        {
            public function __construct(private object $bag) {}

            public function ring(Call $call, User $caller): void
            {
                $this->bag->rings[] = ['call' => $call->id, 'caller' => $caller->id];
            }
        });

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated()
            ->json('call');

        $this->assertCount(1, $bag->rings);
        $this->assertSame($call['id'], $bag->rings[0]['call']);

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->assertCreated();

        $this->assertCount(1, $bag->rings);
    }

    public function test_a_direct_call_is_labelled_with_the_other_person(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');

        $slug = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/dms", ['user_id' => $lin->id])
            ->assertOk()
            ->json('slug');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => $slug])
            ->assertCreated()
            ->assertJsonPath('call.channel_label', 'Lin')
            ->assertJsonPath('call.channel_type', 'dm');
    }

    public function test_a_huddle_ends_when_the_last_person_leaves(): void
    {
        [$owner, $workspace] = $this->office();

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/leave")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/active")
            ->assertOk()
            ->assertJsonCount(0, 'calls');
    }

    public function test_the_workspace_exposes_stun_and_credentialed_turn_servers(): void
    {
        [$owner, $workspace] = $this->office();

        config()->set('smackchat.call_ice_servers', ['stun:stun.l.google.com:19302']);
        config()->set('smackchat.call_turn_urls', [
            'turn:relay.example.com:80',
            'turns:relay.example.com:443?transport=tcp',
        ]);
        config()->set('smackchat.call_turn_username', 'smackchat');
        config()->set('smackchat.call_turn_credential', 'relay-secret');

        $ice = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}")
            ->assertOk()
            ->json('workspace.calls.ice_servers');

        $this->assertContains(['urls' => 'stun:stun.l.google.com:19302'], $ice);
        $this->assertContains([
            'urls' => 'turn:relay.example.com:80',
            'username' => 'smackchat',
            'credential' => 'relay-secret',
        ], $ice);
        $this->assertContains([
            'urls' => 'turns:relay.example.com:443?transport=tcp',
            'username' => 'smackchat',
            'credential' => 'relay-secret',
        ], $ice);
    }

    public function test_the_owner_can_bring_a_workspace_ably_key_and_it_stays_secret(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $key = 'northwind.primary:super-secret-value';

        $this->actingAs($lin, 'sanctum')
            ->putJson("/api/workspaces/{$workspace->slug}/realtime", ['ably_key' => $key])
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/workspaces/{$workspace->slug}/realtime", ['ably_key' => 'not-a-key'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ably_key']);

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/workspaces/{$workspace->slug}/realtime", ['ably_key' => $key])
            ->assertOk()
            ->assertJsonPath('realtime.mode', 'workspace')
            ->assertJsonPath('realtime.custom', true);

        $this->assertNotSame($key, DB::table('workspaces')->where('id', $workspace->id)->value('ably_key'));
        $this->assertSame($key, $workspace->fresh()->ablyKey());

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}")
            ->assertOk()
            ->assertJsonPath('workspace.realtime.mode', 'workspace')
            ->assertJsonMissing(['ably_key' => $key]);

        $capability = app(AblyPublisher::class)->capability($owner, $workspace->fresh());
        $this->assertNotEmpty($capability);
        $this->assertArrayNotHasKey("smackchat:ws:{$workspace->id}:ch:*", $capability);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/realtime")
            ->assertOk()
            ->assertJsonPath('realtime.custom', false);

        $this->assertNull($workspace->fresh()->ablyKey());
    }

    public function test_rejoining_clears_old_signals_and_tells_the_others(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $nudges = $this->captureNudges();

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signal", [
                'to' => $lin->id,
                'type' => 'offer',
                'payload' => ['sdp' => 'v=0 for the old tab'],
            ])
            ->assertOk();

        // Lin reloads the tab and joins again while still seated.
        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk();

        $this->actingAs($lin, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
            ->assertOk()
            ->assertJsonCount(0, 'signals');

        $joined = array_values(array_filter($nudges->events, fn ($event) => $event['event'] === 'call.joined' && $event['user_id'] === $lin->id));
        $this->assertCount(2, $joined);
        $this->assertFalse($joined[0]['rejoin']);
        $this->assertTrue($joined[1]['rejoin']);
    }

    public function test_declining_a_direct_call_ends_it_but_a_huddle_keeps_going(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');
        $grace = $this->member($workspace, 'Grace');
        $nudges = $this->captureNudges();

        $slug = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/dms", ['user_id' => $lin->id])
            ->assertSuccessful()
            ->json('slug');

        $dm = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => $slug])
            ->json('call');

        $this->actingAs($grace, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$dm['id']}/decline")
            ->assertForbidden();

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$dm['id']}/decline")
            ->assertOk();

        $this->assertSame(Call::ENDED, Call::findOrFail($dm['id'])->status);
        $this->assertContains('call.declined', array_column($nudges->events, 'event'));

        $huddle = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$huddle['id']}/decline")
            ->assertOk();

        $this->assertSame(Call::LIVE, Call::findOrFail($huddle['id'])->status);
    }

    public function test_an_unanswered_call_is_swept_and_its_ring_cancelled(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');

        $bag = new \stdClass;
        $bag->cancelled = [];
        $this->app->instance(WebPushNotifier::class, new class($bag) extends WebPushNotifier
        {
            public function __construct(private object $bag) {}

            public function ring(Call $call, User $caller): void {}

            public function cancelRing(Call $call): void
            {
                $this->bag->cancelled[] = $call->id;
            }
        });

        $unanswered = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        $this->artisan('smackchat:sweep-calls')->assertSuccessful();
        $this->assertSame(Call::LIVE, Call::findOrFail($unanswered['id'])->status);

        $this->travel(80)->seconds();
        // Keep the caller's seat fresh: only the ring timeout should apply.
        DB::table('call_participants')->update(['last_seen_at' => now()]);

        $this->artisan('smackchat:sweep-calls')->assertSuccessful();

        $this->assertSame(Call::ENDED, Call::findOrFail($unanswered['id'])->status);
        $this->assertSame([$unanswered['id']], $bag->cancelled);

        // An answered call is not cut off by the ring timeout.
        $answered = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');
        $this->actingAs($lin, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$answered['id']}/join")
            ->assertOk();

        $this->travel(80)->seconds();
        DB::table('call_participants')->whereNull('left_at')->update(['last_seen_at' => now()]);
        $this->artisan('smackchat:sweep-calls')->assertSuccessful();

        $this->assertSame(Call::LIVE, Call::findOrFail($answered['id'])->status);
    }

    public function test_a_call_push_is_short_lived_and_replaceable(): void
    {
        [$owner, $workspace] = $this->office();
        $lin = $this->member($workspace, 'Lin');

        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.subject' => 'mailto:notifications@smack.chat',
            'webpush.public_key' => $vapid['publicKey'],
            'webpush.private_key' => $vapid['privateKey'],
        ]);

        $client = new class implements ClientInterface
        {
            /** @var list<array{url: string, ttl: string, topic: string, urgency: string}> */
            public array $sent = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = [
                    'url' => (string) $request->getUri(),
                    'ttl' => $request->getHeaderLine('TTL'),
                    'topic' => $request->getHeaderLine('Topic'),
                    'urgency' => $request->getHeaderLine('Urgency'),
                ];

                return new Response(201);
            }
        };

        $push = new WebPushNotifier;
        $push->useClient($client);
        $this->app->instance(WebPushNotifier::class, $push);

        $keys = $this->browserPushKeys();
        $this->actingAs($lin, 'sanctum')->putJson('/api/push/subscription', [
            'endpoint' => 'https://push.example.test/lin',
            'public_key' => $keys['public'],
            'auth_token' => $keys['auth'],
            'content_encoding' => 'aes128gcm',
        ])->assertOk();

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        $this->assertCount(1, $client->sent);
        $this->assertSame('https://push.example.test/lin', $client->sent[0]['url']);
        $this->assertSame('call-'.$call['id'], $client->sent[0]['topic']);
        $this->assertSame('high', $client->sent[0]['urgency']);
        $this->assertLessThanOrEqual(75, (int) $client->sent[0]['ttl']);

        // The caller hangs up before Lin answers: Lin's phone gets the
        // replacement push on the same topic.
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/leave")
            ->assertOk();

        $this->assertCount(2, $client->sent);
        $this->assertSame('call-'.$call['id'], $client->sent[1]['topic']);
    }

    public function test_signal_polling_has_its_own_rate_limit(): void
    {
        [$owner, $workspace] = $this->office();

        $call = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls", ['channel' => 'general'])
            ->json('call');

        // More than the start/join limit, well under the signal limit.
        for ($i = 0; $i < 70; $i++) {
            $this->actingAs($owner, 'sanctum')
                ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
                ->assertOk();
        }

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/join")
            ->assertOk();
    }

    private function captureNudges(): object
    {
        $bag = new \stdClass;
        $bag->events = [];
        $this->app->instance(AblyPublisher::class, new class($bag) extends AblyPublisher
        {
            public function __construct(private object $bag) {}

            public function publishCall(int $workspaceId, int $channelId, array $payload): void
            {
                $this->bag->events[] = $payload;
            }
        });

        return $bag;
    }

    /**
     * @return array{public: string, auth: string}
     */
    private function browserPushKeys(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $details = openssl_pkey_get_details($key);

        return [
            'public' => Base64Url::encode(
                "\x04"
                .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)
            ),
            'auth' => Base64Url::encode(random_bytes(16)),
        ];
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
        ])->assertStatus(202)->assertJsonPath('pending', true);

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
