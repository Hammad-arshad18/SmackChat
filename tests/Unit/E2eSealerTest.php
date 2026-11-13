<?php

namespace Tests\Unit;

use App\Support\E2eSealer;
use PHPUnit\Framework\TestCase;

class E2eSealerTest extends TestCase
{
    public function test_a_wrapped_channel_key_opens_a_sealed_message(): void
    {
        $recipient = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $channelKey = random_bytes(32);
        $wrapped = E2eSealer::wrap($channelKey, E2eSealer::rawPublic($recipient), 7);
        $opened = E2eSealer::unwrap($wrapped, $recipient, 7);
        $document = ['type' => 'doc', 'content' => [['type' => 'text', 'text' => 'The launch number is 42.']]];
        $sealed = E2eSealer::sealMessage($opened, 15, $document);

        $this->assertSame($channelKey, $opened);
        $this->assertStringNotContainsString('The launch number is 42.', $sealed);
        $this->assertSame($document, E2eSealer::openMessage($opened, 15, $sealed));
    }

    public function test_a_password_vault_opens_a_channel_backup(): void
    {
        $master = random_bytes(32);
        $channelKey = random_bytes(32);
        $vault = E2eSealer::wrapMaster($master, 'correct horse battery');
        $openedMaster = E2eSealer::openMaster($vault['wrapped_master'], 'correct horse battery', $vault['salt']);
        $backup = E2eSealer::sealBackup($openedMaster, 9, $channelKey);

        $this->assertSame($master, $openedMaster);
        $this->assertSame($channelKey, E2eSealer::openBackup($openedMaster, 9, $backup));
    }
}
