<?php

namespace Tests\Unit;

use App\Support\OfficeNetwork;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfficeNetworkTest extends TestCase
{
    #[Test]
    public function it_normalizes_and_matches_office_ips(): void
    {
        $ranges = OfficeNetwork::normalize([
            ' 203.0.113.10 ',
            '203.0.113.0/24',
            'not-an-ip',
            '203.0.113.10',
        ]);

        $this->assertSame(['203.0.113.10', '203.0.113.0/24'], $ranges);
        $this->assertTrue(OfficeNetwork::allows('203.0.113.10', $ranges));
        $this->assertTrue(OfficeNetwork::allows('203.0.113.55', $ranges));
        $this->assertFalse(OfficeNetwork::allows('198.51.100.10', $ranges));
        $this->assertFalse(OfficeNetwork::allows(null, $ranges));
    }

    #[Test]
    public function it_validates_ip_and_cidr_entries(): void
    {
        $this->assertTrue(OfficeNetwork::isValidEntry('203.0.113.10'));
        $this->assertTrue(OfficeNetwork::isValidEntry('203.0.113.0/24'));
        $this->assertTrue(OfficeNetwork::isValidEntry('2001:db8::1'));
        $this->assertFalse(OfficeNetwork::isValidEntry('203.0.113.0/99'));
        $this->assertFalse(OfficeNetwork::isValidEntry('office-wifi'));
        $this->assertFalse(OfficeNetwork::isValidEntry(''));
    }
}
