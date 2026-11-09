<?php

namespace App\Services;

use Ably\PubSub\Server;
use App\Models\User;
use App\Models\Workspace;
use Throwable;

class AblyPublisher
{
    public function enabled(): bool
    {
        return filled(config('services.ably.key'));
    }

    public function publish(int $workspaceId, int $channelId, array $payload): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $client = Server::createHttpClient(config('services.ably.key'));
            $client->channels
                ->get($this->messageChannel($workspaceId, $channelId))
                ->publish('message', $payload);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function publishShift(int $workspaceId, int $userId, array $day): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $client = Server::createHttpClient(config('services.ably.key'));
            $client->channels
                ->get($this->shiftChannel($workspaceId, $userId))
                ->publish('shift', ['day' => $day]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function token(User $user): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false];
        }

        $capability = [];

        foreach ($user->workspaces()->pluck('workspaces.id') as $workspaceId) {
            $capability[$this->messageChannel($workspaceId, '*')] = ['subscribe'];
            $capability["smackchat:ws:{$workspaceId}:typing:*"] = ['subscribe', 'publish'];
            $capability[$this->shiftChannel($workspaceId, $user->id)] = ['subscribe'];
        }

        try {
            $client = Server::createHttpClient(config('services.ably.key'));
            $token = $client->auth->requestToken([
                'clientId' => (string) $user->id,
                'capability' => json_encode($capability, JSON_THROW_ON_ERROR),
            ]);

            return [
                'enabled' => true,
                'token' => $token->token,
                'expires' => $token->expires,
                'clientId' => (string) $user->id,
            ];
        } catch (Throwable $exception) {
            report($exception);

            return ['enabled' => false];
        }
    }

    public function messageChannel(int|string $workspaceId, int|string $channelId): string
    {
        return "smackchat:ws:{$workspaceId}:ch:{$channelId}";
    }

    public function shiftChannel(int|string $workspaceId, int|string $userId): string
    {
        return "smackchat:ws:{$workspaceId}:shift:{$userId}";
    }

    public function typingChannel(Workspace $workspace, int $channelId): string
    {
        return "smackchat:ws:{$workspace->id}:typing:{$channelId}";
    }
}
