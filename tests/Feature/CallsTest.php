<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\Call;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AblyPublisher;
use App\Services\WebPushNotifier;
use App\Services\WorkspaceProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
            ->assertOk()
            ->assertJsonCount(1, 'signals')
            ->assertJsonPath('signals.0.type', 'offer')
            ->assertJsonPath('signals.0.from', $owner->id);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$workspace->slug}/calls/{$call['id']}/signals")
            ->assertOk()
            ->assertJsonCount(0, 'signals');
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
