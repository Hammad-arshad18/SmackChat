<?php

namespace App\Services;

use Ably\PubSub\Server;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Throwable;

class AblyPublisher
{
    /** @var array<int, string|null> */
    private array $keys = [];

    /**
     * The workspace can bring its own Ably app. Fall back to the server key so
     * an office without its own account still gets live messages.
     */
    public function enabledFor(int|string $workspaceId): bool
    {
        return filled($this->keyFor($workspaceId));
    }

    public function publish(int $workspaceId, int $channelId, array $payload): void
    {
        $this->send($workspaceId, $this->messageChannel($workspaceId, $channelId), 'message', $payload);
    }

    public function publishCall(int $workspaceId, int $channelId, array $payload): void
    {
        $this->send($workspaceId, $this->messageChannel($workspaceId, $channelId), 'call', $payload);
    }

    public function publishShift(int $workspaceId, int $userId, array $day): void
    {
        $this->send($workspaceId, $this->shiftChannel($workspaceId, $userId), 'shift', ['day' => $day]);
    }

    public function publishWorkspace(int|string $workspaceId, string $event, array $payload = []): void
    {
        $this->send($workspaceId, $this->workspaceEventsChannel($workspaceId), $event, $payload);
    }

    public function token(User $user, ?Workspace $workspace = null): array
    {
        $workspace ??= $user->currentWorkspace ?: $user->workspaces()->first();

        if (! $workspace) {
            return ['enabled' => false];
        }

        $key = $this->keyFor($workspace->id);

        if (! filled($key)) {
            return ['enabled' => false];
        }

        $capability = $this->capability($user, $workspace);

        try {
            $client = Server::createHttpClient($key);
            $token = $client->auth->requestToken([
                'clientId' => (string) $user->id,
                'capability' => json_encode($capability, JSON_THROW_ON_ERROR),
            ]);

            return [
                'enabled' => true,
                'token' => $token->token,
                'expires' => $token->expires,
                'clientId' => (string) $user->id,
                'source' => $workspace->usesOwnAblyKey() ? 'workspace' : 'server',
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['enabled' => false];
        }
    }

    /**
     * Ably permissions for one person: only the channels they can actually
     * read, never a workspace-wide wildcard. With a workspace the token is
     * scoped to that office, which is what the browser asks for.
     */
    public function capability(User $user, ?Workspace $workspace = null): array
    {
        $capability = [];
        $workspaces = $workspace
            ? collect([$workspace])
            : $user->workspaces()->get();

        foreach ($workspaces as $office) {
            $visible = Channel::query()
                ->where('workspace_id', $office->id)
                ->where(function ($query) use ($user) {
                    $query->where('type', Channel::PUBLIC)
                        ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
                })
                ->pluck('id');

            foreach ($visible as $channelId) {
                $capability[$this->messageChannel($office->id, $channelId)] = ['subscribe'];
                $capability["smackchat:ws:{$office->id}:typing:{$channelId}"] = ['subscribe', 'publish'];
            }

            $capability[$this->shiftChannel($office->id, $user->id)] = ['subscribe'];
            $capability[$this->workspaceEventsChannel($office->id)] = ['subscribe'];
        }

        return $capability;
    }

    public function messageChannel(int|string $workspaceId, int|string $channelId): string
    {
        return "smackchat:ws:{$workspaceId}:ch:{$channelId}";
    }

    public function shiftChannel(int|string $workspaceId, int|string $userId): string
    {
        return "smackchat:ws:{$workspaceId}:shift:{$userId}";
    }

    public function workspaceEventsChannel(int|string $workspaceId): string
    {
        return "smackchat:ws:{$workspaceId}:events";
    }

    public function typingChannel(Workspace $workspace, int $channelId): string
    {
        return "smackchat:ws:{$workspace->id}:typing:{$channelId}";
    }

    /**
     * What the workspace settings screen should say about live delivery.
     */
    public function status(Workspace $workspace): array
    {
        if ($workspace->usesOwnAblyKey()) {
            return [
                'mode' => 'workspace',
                'custom' => true,
                'masked' => $workspace->maskedAblyKey(),
            ];
        }

        return [
            'mode' => filled(config('services.ably.key')) ? 'server' : 'polling',
            'custom' => false,
            'masked' => null,
        ];
    }

    private function send(int|string $workspaceId, string $channel, string $event, array $payload): void
    {
        $key = $this->keyFor($workspaceId);

        if (! filled($key)) {
            return;
        }

        try {
            $client = Server::createHttpClient($key);
            $client->channels->get($channel)->publish($event, $payload);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function keyFor(int|string $workspaceId): ?string
    {
        $id = (int) $workspaceId;

        if (! array_key_exists($id, $this->keys)) {
            $custom = Workspace::query()->find($id)?->ablyKey();
            $this->keys[$id] = filled($custom) ? $custom : (config('services.ably.key') ?: null);
        }

        return $this->keys[$id];
    }
}
