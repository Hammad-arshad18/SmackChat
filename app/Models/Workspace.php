<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'owner_id',
        'timezone',
        'work_start',
        'work_end',
        'break_allowance_minutes',
        'work_days',
        'location_restricted',
        'location_latitude',
        'location_longitude',
        'location_radius_m',
        'location_allowed_ips',
        'plan',
        'plan_seats',
        'plan_activated_at',
    ];

    protected function casts(): array
    {
        return [
            'work_days' => 'array',
            'break_allowance_minutes' => 'integer',
            'location_restricted' => 'boolean',
            'location_latitude' => 'float',
            'location_longitude' => 'float',
            'location_radius_m' => 'integer',
            'location_allowed_ips' => 'array',
            'plan_activated_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    public function retainsForever(): bool
    {
        return $this->plan === 'archive';
    }

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        $version = $this->updated_at?->getTimestamp() ?? $this->id;

        return '/api/workspaces/'.$this->slug.'/logo?v='.$version;
    }

    public function clockLabel(string $value): string
    {
        return substr((string) $value, 0, 5);
    }

    public function windowMinutes(): int
    {
        return max(0, $this->clockMinutes($this->work_end) - $this->clockMinutes($this->work_start));
    }

    public function standardDayMinutes(): int
    {
        $fallback = (int) config('smackchat.standard_day_minutes', 480);
        $window = $this->windowMinutes();

        if ($window <= 0) {
            return $fallback;
        }

        return max(0, $window - max(0, (int) ($this->break_allowance_minutes ?? 0)));
    }

    private function clockMinutes(?string $value): int
    {
        $clock = $this->clockLabel((string) ($value ?: '00:00'));
        [$hour, $minute] = array_map('intval', explode(':', $clock.':0'));

        return ($hour * 60) + $minute;
    }
}
