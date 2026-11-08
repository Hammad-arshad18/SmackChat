<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\Message;
use App\Models\User;
use App\Services\WebPushNotifier;
use App\Services\WorkspaceProvisioner;
use Base64Url\Base64Url;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

class OfficeChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_are_encrypted_and_direct_messages_stay_private(): void
    {
        $owner = $this->openOffice();
        $other = User::factory()->create(['email' => 'grace@northwind.test']);
        $outsider = User::factory()->create(['email' => 'outsider@northwind.test']);
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $other, 'member');
        app(WorkspaceProvisioner::class)->admit($workspace, $outsider, 'member');

        $dm = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/dms", [
            'user_id' => $other->id,
        ])->assertOk()->json('slug');

        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/{$dm}/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'The launch number is 42.',
                    ]],
                ]],
            ],
        ])->assertCreated()->assertJsonPath('message.text', 'The launch number is 42.');

        $stored = Message::query()->latest('id')->first();
        $this->assertStringNotContainsString('The launch number is 42.', $stored->ciphertext);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/{$dm}/messages")
            ->assertForbidden();

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/{$dm}/messages")
            ->assertOk()
            ->assertJsonPath('messages.0.text', 'The launch number is 42.');
    }

    public function test_unauthenticated_attachment_access_returns_unauthorized(): void
    {
        $this->get('/api/attachments/1')->assertUnauthorized();
        $this->getJson('/api/attachments/1')->assertUnauthorized();
    }

    public function test_messages_can_include_uploaded_media(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $upload = $this->actingAs($owner, 'sanctum')
            ->post("/api/workspaces/{$workspace->slug}/channels/general/attachments", [
                'file' => UploadedFile::fake()->image('screenshot.png', 1200, 800),
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('attachment');

        $this->assertSame('image', $upload['kind']);
        $this->assertNotEmpty($upload['url']);

        $message = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Here is the mockup.']],
                ]],
            ],
            'attachment_ids' => [$upload['id']],
        ])->assertCreated()->json('message');

        $this->assertCount(1, $message['attachments']);
        $this->assertSame('screenshot.png', $message['attachments'][0]['name']);

        $this->actingAs($owner, 'sanctum')
            ->get($message['attachments'][0]['url'])
            ->assertOk();
    }

    public function test_only_sender_or_admin_can_delete_a_message(): void
    {
        $owner = $this->openOffice();
        $member = User::factory()->create(['email' => 'grace@northwind.test']);
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $member, 'member');

        $messageId = $this->actingAs($member, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Please leave this alone.']],
                ]],
            ],
        ])->assertCreated()->json('message.id');

        $stranger = User::factory()->create();
        app(WorkspaceProvisioner::class)->admit($workspace, $stranger, 'member');

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/messages/{$messageId}")
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('message.deleted', true);

        $adminMessageId = $this->actingAs($member, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Admin can clear this.']],
                ]],
            ],
        ])->assertCreated()->json('message.id');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/messages/{$adminMessageId}")
            ->assertOk()
            ->assertJsonPath('message.deleted', true);
    }

    public function test_location_restriction_blocks_remote_check_in_when_enabled(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00:00', 'UTC'));

        $this->actingAs($owner, 'sanctum')->putJson("/api/workspaces/{$workspace->slug}/schedule", [
            'timezone' => 'UTC',
            'work_start' => '09:00',
            'work_end' => '17:00',
            'work_days' => [1, 2, 3, 4, 5],
            'location_restricted' => true,
            'location_latitude' => 25.2048,
            'location_longitude' => 55.2708,
            'location_radius_m' => 200,
        ])->assertOk()->assertJsonPath('schedule.location_restricted', true);

        $workspace->refresh();
        $this->assertTrue((bool) $workspace->location_restricted);

        $this->actingAs($worker, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])
            ->assertStatus(422);

        $this->actingAs($worker, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 24.4539,
                'longitude' => 54.3773,
            ])
            ->assertStatus(422);

        $this->actingAs($worker, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertOk()
            ->assertJsonPath('day.location_verified', true);

        Carbon::setTestNow();
    }

    public function test_checkout_calculates_the_day_and_posts_it_for_the_owner(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        Carbon::setTestNow(Carbon::parse('2026-11-03 09:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_in'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-03 12:30:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_out'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-03 17:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_out'])
            ->assertOk()
            ->assertJsonPath('day.work_minutes', 450)
            ->assertJsonPath('day.break_minutes', 30);

        Carbon::setTestNow();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/timesheets/messages")
            ->assertOk()
            ->assertJsonPath('messages.0.system_type', 'attendance');

        $this->actingAs($worker, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/timesheets/messages")
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->get("/api/workspaces/{$workspace->slug}/attendance/export?month=2026-11")
            ->assertOk();
    }

    public function test_history_drops_after_ninety_days_until_the_archive_is_paid(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Keep this decision.']],
                ]],
            ],
        ])->assertCreated();

        Message::query()->whereNull('system_type')->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/general/messages")
            ->assertOk()
            ->assertJsonMissing(['text' => 'Keep this decision.']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/billing/archive")
            ->assertOk()
            ->assertJsonPath('monthly_cents', 700);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/general/messages")
            ->assertOk()
            ->assertJsonFragment(['text' => 'Keep this decision.']);
    }

    public function test_an_invited_teammate_can_join(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $url = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'email' => 'new@northwind.test',
            'role' => 'member',
        ])->assertCreated()->json('invitation.url');

        $token = basename(parse_url($url, PHP_URL_PATH));

        $this->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/invitations/{$token}/accept", [
            'name' => 'New Person',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertOk()->assertJsonPath('workspace_slug', $workspace->slug);

        $this->assertDatabaseHas('workspace_members', [
            'workspace_id' => $workspace->id,
            'user_id' => User::query()->where('email', 'new@northwind.test')->value('id'),
            'role' => 'member',
        ]);
    }

    public function test_search_respects_private_conversations_and_the_history_window(): void
    {
        $owner = $this->openOffice();
        $other = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $other, 'member');
        app(WorkspaceProvisioner::class)->admit($workspace, $outsider, 'member');

        $dm = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/dms", [
            'user_id' => $other->id,
        ])->assertOk()->json('slug');

        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/{$dm}/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'The confidential launch code is ORBIT.']],
                ]],
            ],
        ])->assertCreated();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/search?q=ORBIT")
            ->assertOk()
            ->assertJsonPath('results.0.message.text', 'The confidential launch code is ORBIT.');

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/search?q=ORBIT")
            ->assertOk()
            ->assertJsonCount(0, 'results');

        Message::query()->where('channel_id', '!=', $workspace->channels()->where('type', 'attendance')->value('id'))->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/search?q=ORBIT")
            ->assertOk()
            ->assertJsonCount(0, 'results');
    }

    public function test_owner_manages_roles_and_invitations_and_floor_view_is_private(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $outsider = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        $provisioner = app(WorkspaceProvisioner::class);
        $provisioner->admit($workspace, $worker, 'member');
        $provisioner->admit($workspace, $outsider, 'member');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->slug}/members/{$worker->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('member.role', 'admin');

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->slug}/members/{$worker->id}", ['role' => 'member'])
            ->assertForbidden();

        $invitation = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/invitations", [
            'email' => 'revoke@northwind.test',
            'role' => 'member',
        ])->assertCreated()->json('invitation');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/invitations/{$invitation['id']}")
            ->assertOk();

        $this->assertDatabaseMissing('invitations', ['email' => 'revoke@northwind.test']);

        Carbon::setTestNow(Carbon::parse('2026-11-03 09:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/attendance/floor")
            ->assertOk()
            ->assertJsonPath('rows.0.user_id', $worker->id);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/attendance/floor")
            ->assertOk()
            ->assertJsonCount(0, 'rows');

        Carbon::setTestNow();
    }

    public function test_thread_replies_count_as_unread_and_push_subscriptions_can_be_saved(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        $parent = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'The laptop will not boot.']],
                ]],
            ],
        ])->assertCreated()->json('message.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/channels/general/read")
            ->assertOk();

        Carbon::setTestNow(now()->addMinute());

        $this->actingAs($worker, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'parent_id' => $parent,
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'The UI of this app is totally broken.']],
                ]],
            ],
        ])->assertCreated();

        $general = collect($this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels")
            ->assertOk()
            ->json('channels'))
            ->firstWhere('slug', 'general');

        $this->assertSame(1, $general['unread']);
        $this->assertStringContainsString('totally broken', $general['excerpt']);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/push/key')
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->actingAs($owner, 'sanctum')->putJson('/api/push/subscription', [
            'endpoint' => 'https://push.example.test/subscription/1',
            'public_key' => str_repeat('A', 87),
            'auth_token' => str_repeat('B', 22),
            'content_encoding' => 'aes128gcm',
        ])->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $owner->id,
            'endpoint' => 'https://push.example.test/subscription/1',
        ]);

        $this->actingAs($owner, 'sanctum')->deleteJson('/api/push/subscription', [
            'endpoint' => 'https://push.example.test/subscription/1',
        ])->assertOk();

        $this->assertDatabaseMissing('push_subscriptions', [
            'endpoint' => 'https://push.example.test/subscription/1',
        ]);

        Carbon::setTestNow();
    }

    public function test_a_message_is_pushed_to_everyone_except_the_author(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        $vapid = VAPID::createVapidKeys();
        config([
            'webpush.subject' => 'mailto:notifications@smack.chat',
            'webpush.public_key' => $vapid['publicKey'],
            'webpush.private_key' => $vapid['privateKey'],
        ]);

        $client = new class implements ClientInterface
        {
            /** @var list<string> */
            public array $urls = [];

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->urls[] = (string) $request->getUri();

                return new Response(201);
            }
        };

        $push = new WebPushNotifier;
        $push->useClient($client);
        $this->app->instance(WebPushNotifier::class, $push);

        foreach ([$owner, $worker] as $user) {
            $keys = $this->browserPushKeys();
            $this->actingAs($user, 'sanctum')->putJson('/api/push/subscription', [
                'endpoint' => 'https://push.example.test/user/'.$user->id,
                'public_key' => $keys['public'],
                'auth_token' => $keys['auth'],
                'content_encoding' => 'aes128gcm',
            ])->assertOk();
        }

        $this->actingAs($worker, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/channels/general/messages", [
            'document' => [
                'type' => 'doc',
                'content' => [[
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'Can you see this on your phone?']],
                ]],
            ],
        ])->assertCreated();

        $this->assertSame(['https://push.example.test/user/'.$owner->id], $client->urls);

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/push/test')
            ->assertOk()
            ->assertJsonPath('subscriptions', 1)
            ->assertJsonPath('sent', 1);

        $this->assertSame('https://push.example.test/user/'.$owner->id, $client->urls[1]);
    }

    public function test_the_profile_can_be_updated_with_a_status(): void
    {
        $owner = $this->openOffice();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/me', [
                'name' => 'Ada L.',
                'title' => 'Founder',
                'status_emoji' => '🎯',
                'status_text' => 'Heads down',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Ada L.')
            ->assertJsonPath('user.status_text', 'Heads down');
    }

    private function openOffice(): User
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

        return User::query()->where('email', 'ada@northwind.test')->firstOrFail();
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
            'public' => Base64Url::encode("\x04".$details['ec']['x'].$details['ec']['y']),
            'auth' => Base64Url::encode(random_bytes(16)),
        ];
    }
}
