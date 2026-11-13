<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\AttendanceDay;
use App\Models\Message;
use App\Models\User;
use App\Services\AblyPublisher;
use App\Services\WebPushNotifier;
use App\Services\WorkspaceProvisioner;
use App\Support\E2eSealer;
use App\Support\OpaqueId;
use Base64Url\Base64Url;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\E2eParty;
use Tests\TestCase;

class OfficeChatTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, E2eParty> */
    private array $parties = [];

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

        $this->party($other);
        $sent = $this->party($owner)->send($this, $workspace->slug, $dm, 'The launch number is 42.')
            ->assertCreated();
        $this->assertStringNotContainsString('The launch number is 42.', $sent->getContent());
        $this->assertSame('', $sent->json('message.text'));
        $this->assertNotEmpty($sent->json('message.sealed'));

        $stored = Message::query()->latest('id')->first();
        $this->assertSame('e2e', $stored->cipher);
        $this->assertStringNotContainsString('The launch number is 42.', $stored->ciphertext);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/{$dm}/messages")
            ->assertForbidden();

        $received = $this->actingAs($other, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/{$dm}/messages")
            ->assertOk();
        $this->assertStringNotContainsString('The launch number is 42.', $received->getContent());
        $this->assertSame(
            'The launch number is 42.',
            $this->party($other)->plain($this, $workspace->slug, $dm, $received->json('messages.0'))
        );
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

        $response = $this->party($owner)->send($this, $workspace->slug, 'general', 'Here is the mockup.', [
            'attachment_ids' => [$upload['id']],
        ])->assertCreated();
        $this->assertStringNotContainsString('Here is the mockup.', $response->getContent());
        $message = $response->json('message');

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

        $messageId = $this->party($member)->send($this, $workspace->slug, 'general', 'Please leave this alone.')
            ->assertCreated()
            ->json('message.id');

        $stranger = User::factory()->create();
        app(WorkspaceProvisioner::class)->admit($workspace, $stranger, 'member');

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/messages/{$messageId}")
            ->assertForbidden();

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('message.deleted', true);

        $adminMessageId = $this->party($member)->send($this, $workspace->slug, 'general', 'Admin can clear this.')
            ->assertCreated()
            ->json('message.id');

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
            'location_allowed_ips' => ['203.0.113.10'],
        ])->assertOk()->assertJsonPath('schedule.location_restricted', true);

        $workspace->refresh();
        $this->assertTrue((bool) $workspace->location_restricted);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])
            ->assertStatus(422);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 24.4539,
                'longitude' => 54.3773,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['network']);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertOk()
            ->assertJsonPath('day.location_verified', true)
            ->assertJsonPath('day.network_verified', true)
            ->assertJsonPath('day.presence_verified', true)
            ->assertJsonPath('day.check_in_ip', '203.0.113.10');

        Carbon::setTestNow();
    }

    public function test_location_restriction_requires_office_ips_before_enabling(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $this->actingAs($owner, 'sanctum')->putJson("/api/workspaces/{$workspace->slug}/schedule", [
            'timezone' => 'UTC',
            'work_start' => '09:00',
            'work_end' => '17:00',
            'work_days' => [1, 2, 3, 4, 5],
            'location_restricted' => true,
            'location_latitude' => 25.2048,
            'location_longitude' => 55.2708,
            'location_radius_m' => 200,
            'location_allowed_ips' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['location_allowed_ips']);
    }

    public function test_location_restriction_rejects_incomplete_workspace_coordinates(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        $workspace->forceFill([
            'location_restricted' => true,
            'location_latitude' => null,
            'location_longitude' => null,
            'location_radius_m' => 200,
            'location_allowed_ips' => ['203.0.113.10'],
        ])->save();

        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00:00', 'UTC'));

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        Carbon::setTestNow();
    }

    public function test_presence_guard_covers_full_shift_and_blocks_bad_checkout(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        $this->actingAs($owner, 'sanctum')->putJson("/api/workspaces/{$workspace->slug}/schedule", [
            'timezone' => 'UTC',
            'work_start' => '09:00',
            'work_end' => '17:00',
            'work_days' => [1, 2, 3, 4, 5],
            'location_restricted' => true,
            'location_latitude' => 25.2048,
            'location_longitude' => 55.2708,
            'location_radius_m' => 200,
            'location_allowed_ips' => ['203.0.113.0/24'],
        ])->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-04 09:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertOk()
            ->assertJsonPath('day.status', 'open')
            ->assertJsonPath('day.check_in_ip', '203.0.113.44');

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_in'])
            ->assertOk()
            ->assertJsonPath('day.status', 'on_break');

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:30:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_out'])
            ->assertOk()
            ->assertJsonPath('day.status', 'open');

        Carbon::setTestNow(Carbon::parse('2026-11-04 17:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_out',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['network']);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_out',
                'latitude' => 24.4539,
                'longitude' => 54.3773,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_out'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['location']);

        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.44'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_out',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertOk()
            ->assertJsonPath('day.status', 'completed')
            ->assertJsonPath('day.work_minutes', 450)
            ->assertJsonPath('day.break_minutes', 30)
            ->assertJsonPath('day.check_out_ip', '203.0.113.44');

        Carbon::setTestNow();
    }

    public function test_unrestricted_attendance_still_checks_in_and_out_without_presence(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        $this->assertFalse((bool) $workspace->location_restricted);

        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])
            ->assertOk()
            ->assertJsonPath('day.status', 'open')
            ->assertJsonPath('day.check_in_ip', '198.51.100.7')
            ->assertJsonPath('day.presence_verified', false);

        Carbon::setTestNow(Carbon::parse('2026-11-05 17:00:00', 'UTC'));
        $this->actingAs($worker, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_out'])
            ->assertOk()
            ->assertJsonPath('day.status', 'completed')
            ->assertJsonPath('day.check_out_ip', '198.51.100.7')
            ->assertJsonPath('day.work_minutes', 480);

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

    public function test_a_break_is_published_so_other_sessions_can_pause_the_clock(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;
        $bag = new \stdClass;
        $bag->shifts = [];
        $this->app->instance(AblyPublisher::class, new class($bag) extends AblyPublisher
        {
            public function __construct(private object $bag) {}

            public function publishShift(int $workspaceId, int $userId, array $day): void
            {
                $this->bag->shifts[] = [
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'day' => $day,
                ];
            }
        });

        Carbon::setTestNow(Carbon::parse('2026-11-03 09:00:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])
            ->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-11-03 12:00:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_in'])
            ->assertOk()
            ->assertJsonPath('day.status', 'on_break');

        Carbon::setTestNow();

        $this->assertCount(2, $bag->shifts);
        $this->assertSame($workspace->id, $bag->shifts[1]['workspace_id']);
        $this->assertSame($owner->id, $bag->shifts[1]['user_id']);
        $this->assertSame('on_break', $bag->shifts[1]['day']['status']);
        $this->assertSame('break_in', $bag->shifts[1]['day']['events'][1]['type']);
    }

    public function test_history_drops_after_ninety_days_until_the_archive_is_paid(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $this->party($owner)->send($this, $workspace->slug, 'general', 'Keep this decision.')->assertCreated();

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

        $restored = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/general/messages")
            ->assertOk();
        $this->assertStringNotContainsString('Keep this decision.', $restored->getContent());
        $kept = collect($restored->json('messages'))
            ->first(fn (array $message) => $message['cipher'] === 'e2e' && $message['sealed']);
        $this->assertSame(
            'Keep this decision.',
            $this->party($owner)->plain($this, $workspace->slug, 'general', $kept)
        );
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

        $this->party($other);
        $secret = $this->party($owner)->send($this, $workspace->slug, $dm, 'The confidential launch code is ORBIT.')
            ->assertCreated()
            ->json('message');

        $found = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/search")
            ->assertOk();
        $this->assertStringNotContainsString('ORBIT', $found->getContent());
        $match = collect($found->json('results'))->first(fn (array $row) => $row['message']['id'] === $secret['id']);
        $this->assertNotNull($match);
        $this->assertSame(
            'The confidential launch code is ORBIT.',
            $this->party($owner)->plain($this, $workspace->slug, $dm, $match['message'])
        );

        $hidden = $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/search")
            ->assertOk();
        $this->assertStringNotContainsString('ORBIT', $hidden->getContent());
        $this->assertFalse(collect($hidden->json('results'))->contains(
            fn (array $row) => $row['message']['id'] === $secret['id']
        ));

        Message::query()->where('channel_id', '!=', $workspace->channels()->where('type', 'attendance')->value('id'))->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $aged = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/search")
            ->assertOk();
        $this->assertFalse(collect($aged->json('results'))->contains(
            fn (array $row) => $row['message']['id'] === $secret['id']
        ));
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

        $this->party($worker);
        $parent = $this->party($owner)->send($this, $workspace->slug, 'general', 'The laptop will not boot.')
            ->assertCreated()
            ->json('message.id');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/channels/general/read")
            ->assertOk();

        Carbon::setTestNow(now()->addMinute());

        $this->party($worker)->send($this, $workspace->slug, 'general', 'The UI of this app is totally broken.', [
            'parent_id' => $parent,
        ])->assertCreated();

        $general = collect($this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels")
            ->assertOk()
            ->json('channels'))
            ->firstWhere('slug', 'general');

        $this->assertSame(1, $general['unread']);
        $this->assertStringNotContainsString('totally broken', json_encode($general));
        $this->assertStringContainsString(
            'totally broken',
            $this->party($owner)->plain($this, $workspace->slug, 'general', [
                'channel_id' => $general['id'],
                'sealed' => $general['latest_sealed'],
            ])
        );

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

    public function test_a_notification_target_opens_the_message_and_its_thread(): void
    {
        $owner = $this->openOffice();
        $worker = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $worker, 'member');

        $this->party($worker);
        $parent = $this->party($owner)->send($this, $workspace->slug, 'general', 'Please look at the lobby.')
            ->assertCreated()
            ->json('message.id');

        $reply = $this->party($worker)->send($this, $workspace->slug, 'general', 'On my way.', [
            'parent_id' => $parent,
        ])->assertCreated()->json('message.id');

        $replyRef = OpaqueId::encode($reply);

        $ignored = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels/general/messages?focus={$reply}")
            ->assertOk()
            ->json();
        $this->assertArrayNotHasKey('focus_id', $ignored);

        $focused = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/workspaces/'.$workspace->slug.'/channels/general/messages?focus='.urlencode($replyRef))
            ->assertOk()
            ->json();

        $this->assertSame($parent, $focused['focus_id']);
        $this->assertTrue(collect($focused['messages'])->pluck('id')->contains($parent));
        $this->assertSame(OpaqueId::encode($parent), collect($focused['messages'])->firstWhere('id', $parent)['ref'] ?? null);

        $channel = collect($this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/channels")
            ->assertOk()
            ->json('channels'))
            ->firstWhere('slug', 'general');

        $this->assertSame(0, $channel['unread']);
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

        $sent = $this->party($worker)->send($this, $workspace->slug, 'general', 'Can you see this on your phone?')
            ->assertCreated();
        $this->assertStringNotContainsString('Can you see this on your phone?', $sent->getContent());

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

    public function test_a_profile_photo_is_visible_to_teammates_only(): void
    {
        Storage::fake('local');
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;
        $stranger = User::factory()->create();
        $teammate = User::factory()->create();
        app(WorkspaceProvisioner::class)->admit($workspace, $teammate, 'member');

        $uploaded = $this->actingAs($owner, 'sanctum')
            ->post('/api/me/avatar', [
                'avatar' => UploadedFile::fake()->image('ada.jpg', 80, 80),
            ])
            ->assertOk();

        $this->assertStringContainsString('/api/avatars/'.$owner->id, (string) $uploaded->json('user.avatar_url'));
        Storage::disk('local')->assertExists($owner->fresh()->avatar_path);

        $this->actingAs($owner, 'sanctum')->get('/api/avatars/'.$owner->id)->assertOk();
        $this->actingAs($teammate, 'sanctum')->get('/api/avatars/'.$owner->id)->assertOk();
        $this->actingAs($stranger, 'sanctum')->get('/api/avatars/'.$owner->id)->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/me/avatar')
            ->assertOk()
            ->assertJsonPath('user.avatar_url', null);
    }

    public function test_hours_past_eight_are_overtime_after_breaks(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:00:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_in'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-05 12:30:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_out'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:15:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_out'])
            ->assertOk()
            ->assertJsonPath('day.work_minutes', 525)
            ->assertJsonPath('day.regular_minutes', 480)
            ->assertJsonPath('day.overtime_minutes', 45)
            ->assertJsonPath('day.break_minutes', 30);

        Carbon::setTestNow();

        $export = $this->actingAs($owner, 'sanctum')
            ->get("/api/workspaces/{$workspace->slug}/attendance/export?month=2026-11")
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Overtime', $export);
        $this->assertStringContainsString('0.75', $export);
    }

    public function test_break_allowance_sets_the_expected_work_day(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $this->actingAs($owner, 'sanctum')->putJson("/api/workspaces/{$workspace->slug}/schedule", [
            'timezone' => 'Asia/Dubai',
            'work_start' => '09:00',
            'work_end' => '18:00',
            'break_allowance_minutes' => 60,
            'work_days' => [1, 2, 3, 4, 5],
        ])
            ->assertOk()
            ->assertJsonPath('schedule.break_allowance_minutes', 60)
            ->assertJsonPath('schedule.window_minutes', 540)
            ->assertJsonPath('schedule.standard_day_minutes', 480);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}")
            ->assertOk()
            ->assertJsonPath('workspace.break_allowance_minutes', 60)
            ->assertJsonPath('workspace.standard_day_minutes', 480);
    }

    public function test_completed_days_are_recalculated_from_the_event_timeline(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        Carbon::setTestNow(Carbon::parse('2026-11-09 09:00:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_in'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-09 13:07:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_in'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-09 14:05:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'break_out'])->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-11-09 18:05:00', 'UTC'));
        $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", ['action' => 'check_out'])
            ->assertOk()
            ->assertJsonPath('day.work_minutes', 487)
            ->assertJsonPath('day.break_minutes', 58)
            ->assertJsonPath('day.regular_minutes', 480)
            ->assertJsonPath('day.overtime_minutes', 7);

        $day = AttendanceDay::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $day->forceFill(['work_minutes' => 521, 'break_minutes' => 58])->saveQuietly();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/attendance?month=2026-11")
            ->assertOk()
            ->assertJsonPath('rows.0.work_minutes', 487)
            ->assertJsonPath('rows.0.break_minutes', 58)
            ->assertJsonPath('rows.0.overtime_minutes', 7);

        $this->assertSame(487, $day->fresh()->work_minutes);

        Carbon::setTestNow();
    }

    public function test_reactions_are_broadcast_to_the_conversation(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;
        $message = $this->party($owner)->send($this, $workspace->slug, 'general', 'Ship the homepage.')
            ->assertCreated()
            ->json('message');

        $bag = new \stdClass;
        $bag->events = [];
        $this->app->instance(AblyPublisher::class, new class($bag) extends AblyPublisher
        {
            public function __construct(private object $bag) {}

            public function publish(int $workspaceId, int $channelId, array $payload): void
            {
                $this->bag->events[] = $payload;
            }
        });

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/messages/{$message['id']}/reactions", [
                'emoji' => '👍',
            ])
            ->assertOk()
            ->assertJsonPath('event', 'message.reacted')
            ->assertJsonPath('actor_id', $owner->id)
            ->assertJsonPath('message.reactions.0.emoji', '👍')
            ->assertJsonPath('message.reactions.0.count', 1);

        $this->assertSame('message.reacted', $bag->events[0]['event'] ?? null);
        $this->assertSame($message['id'], $bag->events[0]['message']['id'] ?? null);
        $this->assertStringNotContainsString('Ship the homepage.', json_encode($bag->events[0]));
    }

    public function test_workspace_logo_can_be_uploaded_viewed_and_removed(): void
    {
        Storage::fake('local');
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

        $file = UploadedFile::fake()->image('logo.png', 200, 200);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/logo", [
                'logo' => $file,
            ])
            ->assertOk();

        $this->assertNotNull($response->json('workspace.logo_url'));

        $this->actingAs($owner, 'sanctum')
            ->get("/api/workspaces/{$workspace->slug}/logo")
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/workspaces/{$workspace->slug}/logo")
            ->assertOk()
            ->assertJsonPath('workspace.logo_url', null);
    }

    public function test_a_second_device_can_receive_conversation_keys_from_an_existing_device(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;
        $first = $this->party($owner);
        $first->send($this, $workspace->slug, 'general', 'Keep this for the laptop.')
            ->assertCreated();

        $this->actingAs($owner, 'sanctum');
        $second = E2eParty::register($this);
        $bundle = $this->getJson("/api/workspaces/{$workspace->slug}/channels/general/keys")
            ->assertOk()
            ->json();
        $this->assertNull(collect($bundle['wraps'])->firstWhere('device_id', $second->deviceId()));

        $this->actingAs($owner, 'sanctum');
        $first->shareMissing($this, $workspace->slug, 'general');

        $this->actingAs($owner, 'sanctum');
        $message = collect($this->getJson("/api/workspaces/{$workspace->slug}/channels/general/messages")
            ->assertOk()
            ->json('messages'))
            ->first(fn (array $row) => $row['cipher'] === 'e2e' && $row['sealed']);
        $this->assertSame(
            'Keep this for the laptop.',
            $second->plain($this, $workspace->slug, 'general', $message)
        );
        $second->send($this, $workspace->slug, 'general', 'Sent from the new browser.')
            ->assertCreated();
    }

    public function test_a_new_device_can_restore_conversation_keys_from_the_password_vault(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;
        $first = $this->party($owner);
        $first->installVault($this, 'password');
        $first->send($this, $workspace->slug, 'general', 'Vault this for later.')
            ->assertCreated();
        $first->backupChannel($this, $workspace->slug, 'general');

        $this->actingAs($owner, 'sanctum');
        $second = E2eParty::register($this);
        $bundle = $this->getJson("/api/workspaces/{$workspace->slug}/channels/general/keys")
            ->assertOk()
            ->json();
        $this->assertNull(collect($bundle['wraps'])->firstWhere('device_id', $second->deviceId()));

        $second->unlockVault($this, 'password');
        $second->claimFromVault($this, $workspace->slug, 'general');
        $message = collect($this->getJson("/api/workspaces/{$workspace->slug}/channels/general/messages")
            ->assertOk()
            ->json('messages'))
            ->first(fn (array $row) => $row['cipher'] === 'e2e' && $row['sealed']);
        $this->assertSame(
            'Vault this for later.',
            $second->plain($this, $workspace->slug, 'general', $message)
        );
        $second->send($this, $workspace->slug, 'general', 'Recovered and sending.')
            ->assertCreated();
    }

    public function test_an_existing_encryption_vault_is_not_replaced(): void
    {
        $owner = $this->openOffice();
        $this->actingAs($owner, 'sanctum');
        $first = E2eSealer::wrapMaster(random_bytes(32), 'password');
        $this->putJson('/api/e2e/vault', $first)->assertOk()
            ->assertJsonPath('vault.salt', $first['salt'])
            ->assertJsonPath('vault.wrapped_master', $first['wrapped_master']);

        $second = E2eSealer::wrapMaster(random_bytes(32), 'password');
        $this->putJson('/api/e2e/vault', $second)->assertOk()
            ->assertJsonPath('vault.salt', $first['salt'])
            ->assertJsonPath('vault.wrapped_master', $first['wrapped_master']);
    }

    public function test_a_device_without_the_key_or_vault_cannot_overwrite_conversation_wraps(): void
    {
        $owner = $this->openOffice();
        $member = User::factory()->create(['email' => 'grace@northwind.test']);
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $member, 'member');

        $this->party($owner)->send($this, $workspace->slug, 'general', 'Owner started the key.')
            ->assertCreated();

        $this->actingAs($member, 'sanctum');
        $intruder = E2eParty::register($this);
        $bundle = $this->getJson("/api/workspaces/{$workspace->slug}/channels/general/keys")->assertOk()->json();
        $device = collect($bundle['devices'])->firstWhere('id', $intruder->deviceId());
        $fake = random_bytes(32);

        $this->putJson("/api/workspaces/{$workspace->slug}/channels/general/keys", [
            'wraps' => [[
                'device_id' => $intruder->deviceId(),
                'wrapped' => E2eSealer::wrap($fake, base64_decode($device['public_key']), $intruder->deviceId()),
            ]],
        ])->assertStatus(409);
    }

    private function party(User $user): E2eParty
    {
        if (! isset($this->parties[$user->id])) {
            $this->actingAs($user, 'sanctum');
            $this->parties[$user->id] = E2eParty::register($this);
        }

        $this->actingAs($user, 'sanctum');

        return $this->parties[$user->id];
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
            'public' => Base64Url::encode(
                "\x04"
                .str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)
                .str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)
            ),
            'auth' => Base64Url::encode(random_bytes(16)),
        ];
    }
}
