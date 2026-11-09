<?php

namespace Tests\Unit;

use App\Support\OpaqueId;
use Tests\TestCase;

class OpaqueIdTest extends TestCase
{
    public function test_a_message_id_round_trips_without_appearing_in_the_token(): void
    {
        $first = OpaqueId::encode(42);
        $second = OpaqueId::encode(42);

        $this->assertSame($first, $second);
        $this->assertSame(42, OpaqueId::decode($first));
        $this->assertDoesNotMatchRegularExpression('/^\d+$/', $first);
        $this->assertNull(OpaqueId::decode('42'));
        $this->assertNull(OpaqueId::decode(substr($first, 0, -1).($first[-1] === 'a' ? 'b' : 'a')));
        $this->assertNotSame($first, OpaqueId::encode(43));
    }
}
