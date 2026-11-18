<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AblyPublisher;
use App\Services\MessageWriter;
use App\Services\WorkspaceProvisioner;
use App\Support\RichText;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_forwarded_for_header_cannot_spoof_the_office_ip(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;

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
        ])->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.10', 'X-Real-IP' => '203.0.113.10'])
            ->postJson("/api/workspaces/{$workspace->slug}/attendance/punch", [
                'action' => 'check_in',
                'latitude' => 25.2048,
                'longitude' => 55.2708,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['network']);

        Carbon::setTestNow();
    }

    public function test_browser_hardening_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(self), display-capture=(self), geolocation=(self), payment=()');
    }

    public function test_login_locks_a_bad_password_after_five_tries(): void
    {
        $this->openOffice();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', [
                'email' => 'ada@northwind.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        $this->postJson('/api/login', [
            'email' => 'ada@northwind.test',
            'password' => 'password',
        ])->assertStatus(429);
    }

    public function test_ably_token_only_covers_channels_the_person_can_read(): void
    {
        $owner = $this->openOffice();
        $member = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $member, 'member');

        $private = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/channels", [
                'name' => 'Board room',
                'private' => true,
                'member_ids' => [],
            ])->assertCreated()->json('slug');

        $generalId = Channel::query()->where('workspace_id', $workspace->id)->where('slug', 'general')->value('id');
        $privateId = Channel::query()->where('workspace_id', $workspace->id)->where('slug', $private)->value('id');

        $capability = app(AblyPublisher::class)->capability($member);

        $this->assertArrayHasKey("smackchat:ws:{$workspace->id}:ch:{$generalId}", $capability);
        $this->assertArrayNotHasKey("smackchat:ws:{$workspace->id}:ch:{$privateId}", $capability);
        $this->assertArrayNotHasKey("smackchat:ws:{$workspace->id}:ch:*", $capability);
        $this->assertArrayNotHasKey("smackchat:ws:{$workspace->id}:typing:*", $capability);
    }

    public function test_private_channel_members_cannot_evict_each_other(): void
    {
        $owner = $this->openOffice();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $first, 'member');
        app(WorkspaceProvisioner::class)->admit($workspace, $second, 'member');

        $slug = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$workspace->slug}/channels", [
                'name' => 'Design room',
                'private' => true,
                'member_ids' => [$first->id, $second->id],
            ])->assertCreated()->json('slug');

        $this->actingAs($first, 'sanctum')
            ->putJson("/api/workspaces/{$workspace->slug}/channels/{$slug}/members", [
                'member_ids' => [$first->id],
            ])
            ->assertForbidden();

        $this->actingAs($owner, 'sanctum')
            ->putJson("/api/workspaces/{$workspace->slug}/channels/{$slug}/members", [
                'member_ids' => [$owner->id, $first->id],
            ])
            ->assertOk();
    }

    public function test_managers_can_read_the_audit_trail_and_members_cannot(): void
    {
        $owner = $this->openOffice();
        $member = User::factory()->create();
        $plain = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $member, 'member');
        app(WorkspaceProvisioner::class)->admit($workspace, $plain, 'member');

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/workspaces/{$workspace->slug}/members/{$member->id}", ['role' => 'admin'])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/audit")
            ->assertOk()
            ->assertJsonFragment(['action' => 'member.role_changed']);

        $this->actingAs($plain, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/audit")
            ->assertForbidden();
    }

    public function test_a_person_can_export_their_own_data(): void
    {
        $owner = $this->openOffice();
        $workspace = $owner->currentWorkspace;
        $channel = Channel::query()->where('workspace_id', $workspace->id)->where('slug', 'general')->firstOrFail();
        app(MessageWriter::class)->post($channel, $owner, RichText::paragraph('Export me please'));

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/me/export')
            ->assertOk()
            ->assertJsonPath('profile.email', 'ada@northwind.test')
            ->assertJsonPath('messages.0.channel', 'general')
            ->assertJsonPath('messages.0.cipher', 'server');

        $this->assertNotEmpty(
            $this->actingAs($owner, 'sanctum')->getJson('/api/me/export')->json('messages.0.ciphertext')
        );
    }

    public function test_account_deletion_requires_the_password_and_hands_workspaces_over(): void
    {
        $owner = $this->openOffice();
        $member = User::factory()->create();
        $workspace = $owner->currentWorkspace;
        app(WorkspaceProvisioner::class)->admit($workspace, $member, 'member');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/me', ['password' => 'password'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Hand the workspace to another owner before deleting your account.');

        $this->actingAs($owner, 'sanctum')
            ->delete("/api/workspaces/{$workspace->slug}/members/{$member->id}", [], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/me', ['password' => 'nope'])
            ->assertStatus(422);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/me', ['password' => 'password'])
            ->assertOk();

        $this->assertNull(User::query()->find($owner->id));
        $this->assertNull(Workspace::query()->find($workspace->id));
    }

    public function test_password_reset_issues_a_token_and_changes_the_password(): void
    {
        Notification::fake();
        $user = $this->openOffice();

        $this->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        Notification::assertSentTo($user, ResetPassword::class);

        $this->postJson('/api/forgot-password', ['email' => 'nobody@nowhere.test'])->assertOk();
        Notification::assertSentToTimes($user, ResetPassword::class, 1);

        $token = PasswordBroker::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));

        $this->postJson('/api/reset-password', [
            'token' => 'not-a-real-token',
            'email' => $user->email,
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
        ])->assertStatus(422);
    }

    public function test_health_check_and_backup_command(): void
    {
        $this->get('/up')->assertOk();

        $this->openOffice();
        $directory = storage_path('app/backups');
        $before = glob($directory.'/smackchat-*.sql.gz') ?: [];

        $this->artisan('smackchat:backup', ['--skip-files' => true, '--keep' => 2])->assertSuccessful();

        $created = array_values(array_diff(glob($directory.'/smackchat-*.sql.gz') ?: [], $before));
        $this->assertNotEmpty($created);

        foreach ($created as $file) {
            @unlink($file);
        }
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

        return User::query()->where('email', 'ada@northwind.test')->firstOrFail();
    }
}
