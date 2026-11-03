<?php

declare(strict_types=1);

namespace PelicanProvisioner\Models;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use PelicanProvisioner\Domain\Quota;
use Throwable;

/**
 * @property int $id
 * @property int $user_id
 * @property int $cpu
 * @property int $memory
 * @property int $disk
 * @property int|null $server_limit
 * @property User|null $user
 */
class ResourceQuota extends Model
{
    public const string COUNT_CACHE_KEY = 'pelican-provisioner:quota-count';

    protected $table = 'provisioner_quotas';

    protected $fillable = [
        'user_id',
        'cpu',
        'memory',
        'disk',
        'server_limit',
    ];

    protected static function booted(): void
    {
        $forget = static function (): void {
            try {
                Cache::forget(self::COUNT_CACHE_KEY);
            } catch (Throwable) {
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Server, $this> */
    public function ownedServers(): HasMany
    {
        return $this->hasMany(Server::class, 'owner_id', 'user_id');
    }

    public function toQuota(): Quota
    {
        return new Quota(
            cpu: (int) $this->cpu,
            memory: (int) $this->memory,
            disk: (int) $this->disk,
            serverLimit: $this->server_limit === null ? null : (int) $this->server_limit,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'cpu' => 'integer',
            'memory' => 'integer',
            'disk' => 'integer',
            'server_limit' => 'integer',
        ];
    }
}
