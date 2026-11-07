<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegistrationChallenge extends Model
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'workspace_name',
        'timezone',
        'code_hash',
        'attempts',
        'expires_at',
        'verified_at',
    ];

    protected $hidden = ['password', 'code_hash'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
