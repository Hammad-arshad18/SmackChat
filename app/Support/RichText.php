<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class RichText
{
    public static function document(array $doc, bool $allowEmpty = false): array
    {
        if (($doc['type'] ?? null) !== 'doc' || ! isset($doc['content']) || ! is_array($doc['content'])) {
            throw ValidationException::withMessages([
                'document' => 'Message format is invalid.',
            ]);
        }

        $text = trim(self::plain($doc));

        if ($text === '' && ! $allowEmpty) {
            throw ValidationException::withMessages([
                'document' => 'Write a message before sending.',
            ]);
        }

        if (mb_strlen($text) > 8000) {
            throw ValidationException::withMessages([
                'document' => 'Messages are limited to 8,000 characters.',
            ]);
        }

        return $doc;
    }

    public static function paragraph(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
            ]],
        ];
    }

    public static function plain(array $node): string
    {
        $type = $node['type'] ?? '';

        if ($type === 'text') {
            return (string) ($node['text'] ?? '');
        }

        if ($type === 'mention') {
            return '@'.($node['attrs']['label'] ?? 'someone');
        }

        if ($type === 'hardBreak') {
            return "\n";
        }

        $text = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $text .= self::plain($child);
            }
        }

        if (in_array($type, ['paragraph', 'heading', 'listItem', 'blockquote', 'codeBlock'], true)) {
            $text .= "\n";
        }

        return $text;
    }
}
