<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;

class MessageCipher
{
    public function encrypt(array $document): string
    {
        return Crypt::encryptString(json_encode($document, JSON_THROW_ON_ERROR));
    }

    public function decrypt(?string $ciphertext): ?array
    {
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }

        $json = Crypt::decryptString($ciphertext);
        $document = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($document) ? $document : null;
    }
}
