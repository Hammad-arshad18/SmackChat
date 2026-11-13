<?php

namespace Tests\Support;

use App\Support\E2eSealer;
use App\Support\RichText;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use RuntimeException;
use Tests\TestCase;

class E2eParty
{
    /** @var array<int, string> */
    private array $keys = [];

    private ?string $master = null;

    public function __construct(
        private OpenSSLAsymmetricKey $private,
        private int $deviceId,
    ) {}

    public static function register(TestCase $test): self
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $response = $test->postJson('/api/e2e/devices', [
            'public_key' => base64_encode(E2eSealer::rawPublic($key)),
        ])->assertOk();

        return new self($key, (int) $response->json('device.id'));
    }

    public function deviceId(): int
    {
        return $this->deviceId;
    }

    public function send(TestCase $test, string $workspace, string $channel, string $text, array $extra = []): TestResponse
    {
        $channelId = $this->channelId($test, $workspace, $channel);
        $key = $this->channelKey($test, $workspace, $channel, $channelId);
        $sealed = E2eSealer::sealMessage($key, $channelId, [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
            ]],
        ]);

        return $test->postJson("/api/workspaces/{$workspace}/channels/{$channel}/messages", array_merge([
            'sealed' => $sealed,
        ], $extra));
    }

    public function plain(TestCase $test, string $workspace, string $channel, array $message): string
    {
        $channelId = (int) $message['channel_id'];
        $key = $this->channelKey($test, $workspace, $channel, $channelId);
        $document = E2eSealer::openMessage($key, $channelId, $message['sealed']);

        return trim(RichText::plain($document));
    }

    public function installVault(TestCase $test, string $password): void
    {
        $this->master = random_bytes(32);
        $vault = E2eSealer::wrapMaster($this->master, $password);
        $test->putJson('/api/e2e/vault', $vault)->assertOk();
    }

    public function unlockVault(TestCase $test, string $password): void
    {
        $vault = $test->getJson('/api/e2e/vault')->assertOk()->json('vault');
        if (! $vault) {
            throw new RuntimeException('No encryption vault is stored for this account.');
        }

        $this->master = E2eSealer::openMaster($vault['wrapped_master'], $password, $vault['salt']);
    }

    public function backupChannel(TestCase $test, string $workspace, string $channel): void
    {
        if (! is_string($this->master)) {
            throw new RuntimeException('Unlock the vault before backing up channel keys.');
        }

        $channelId = $this->channelId($test, $workspace, $channel);
        $raw = $this->channelKey($test, $workspace, $channel, $channelId);
        $test->putJson('/api/e2e/vault/channels', [
            'channels' => [[
                'channel_id' => $channelId,
                'wrapped' => E2eSealer::sealBackup($this->master, $channelId, $raw),
            ]],
        ])->assertOk();
    }

    public function claimFromVault(TestCase $test, string $workspace, string $channel): string
    {
        if (! is_string($this->master)) {
            throw new RuntimeException('Unlock the vault before claiming channel keys.');
        }

        $channelId = $this->channelId($test, $workspace, $channel);
        $backup = $test->getJson("/api/e2e/vault/channels/{$channelId}")->assertOk()->json('backup');
        if (! $backup) {
            throw new RuntimeException('No channel backup is stored for this account.');
        }

        $raw = E2eSealer::openBackup($this->master, $channelId, $backup['wrapped']);
        $bundle = $test->getJson("/api/workspaces/{$workspace}/channels/{$channel}/keys")->assertOk()->json();
        $mine = collect($bundle['devices'])->firstWhere('id', $this->deviceId);
        if (! $mine) {
            throw new RuntimeException('This device is not listed on the conversation.');
        }

        $test->putJson("/api/workspaces/{$workspace}/channels/{$channel}/keys", [
            'wraps' => [[
                'device_id' => $this->deviceId,
                'wrapped' => E2eSealer::wrap($raw, base64_decode($mine['public_key']), $this->deviceId),
            ]],
        ])->assertOk();

        $this->keys[$channelId] = $raw;

        return $raw;
    }

    public function shareMissing(TestCase $test, string $workspace, string $channel): void
    {
        $channelId = $this->channelId($test, $workspace, $channel);
        $raw = $this->channelKey($test, $workspace, $channel, $channelId);
        $bundle = $test->getJson("/api/workspaces/{$workspace}/channels/{$channel}/keys")->assertOk()->json();
        $missing = collect($bundle['devices'])->reject(
            fn (array $device) => collect($bundle['wraps'])->contains('device_id', $device['id'])
        );

        if ($missing->isEmpty()) {
            return;
        }

        $wraps = $missing->map(fn (array $device) => [
            'device_id' => $device['id'],
            'wrapped' => E2eSealer::wrap($raw, base64_decode($device['public_key']), (int) $device['id']),
        ])->values()->all();

        $test->putJson("/api/workspaces/{$workspace}/channels/{$channel}/keys", [
            'wraps' => $wraps,
        ])->assertOk();
    }

    private function channelId(TestCase $test, string $workspace, string $channel): int
    {
        $row = collect($test->getJson("/api/workspaces/{$workspace}/channels")->assertOk()->json('channels'))
            ->firstWhere('slug', $channel);

        if (! $row) {
            throw new RuntimeException("Missing channel {$channel}.");
        }

        return (int) $row['id'];
    }

    private function channelKey(TestCase $test, string $workspace, string $channel, int $channelId): string
    {
        if (isset($this->keys[$channelId])) {
            return $this->keys[$channelId];
        }

        $bundle = $test->getJson("/api/workspaces/{$workspace}/channels/{$channel}/keys")->assertOk()->json();
        $wrap = collect($bundle['wraps'])->firstWhere('device_id', $this->deviceId);

        if (! $wrap) {
            if (count($bundle['wraps']) > 0) {
                throw new RuntimeException('This device does not have the conversation key.');
            }

            $raw = random_bytes(32);
            $wraps = [];

            foreach ($bundle['devices'] as $device) {
                $wraps[] = [
                    'device_id' => $device['id'],
                    'wrapped' => E2eSealer::wrap($raw, base64_decode($device['public_key']), (int) $device['id']),
                ];
            }

            $test->putJson("/api/workspaces/{$workspace}/channels/{$channel}/keys", [
                'wraps' => $wraps,
            ])->assertOk();
            $this->keys[$channelId] = $raw;

            return $raw;
        }

        $raw = E2eSealer::unwrap($wrap['wrapped'], $this->private, $this->deviceId);
        $this->keys[$channelId] = $raw;

        return $raw;
    }
}
