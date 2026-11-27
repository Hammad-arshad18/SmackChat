<?php

namespace App\Services;

/**
 * The RTCIceServer list a client connects a call with. STUN servers help peers
 * find a path; a TURN server relays media when no direct path exists (mobile
 * data, strict office firewalls) and needs a username and credential.
 *
 * Sent with the workspace and again with each call start and join, so a phone
 * that opened the app hours ago still gets fresh short-lived credentials.
 */
class CallIceServers
{
    public function __construct(private CloudflareTurn $cloudflare) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $servers = array_map(
            fn (string $url) => ['urls' => $url],
            config('smackchat.call_ice_servers', []),
        );

        foreach (config('smackchat.call_turn_urls', []) as $turn) {
            $servers[] = array_filter([
                'urls' => (string) $turn,
                'username' => config('smackchat.call_turn_username'),
                'credential' => config('smackchat.call_turn_credential'),
            ], fn ($value) => filled($value));
        }

        return [...$servers, ...$this->cloudflare->iceServers()];
    }
}
