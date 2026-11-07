<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attachment extends Model
{
    protected $fillable = [
        'channel_id',
        'message_id',
        'user_id',
        'disk',
        'path',
        'original_name',
        'extension',
        'mime_type',
        'size',
        'width',
        'height',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kind(): string
    {
        if (str_starts_with($this->mime_type, 'image/')) {
            return 'image';
        }

        if (str_starts_with($this->mime_type, 'video/')) {
            return 'video';
        }

        return 'file';
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime' => $this->mime_type,
            'extension' => $this->extension,
            'size' => (int) $this->size,
            'width' => $this->width ? (int) $this->width : null,
            'height' => $this->height ? (int) $this->height : null,
            'kind' => $this->kind(),
            'url' => url('/api/attachments/'.$this->id),
        ];
    }
}
