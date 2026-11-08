<?php

declare(strict_types=1);

namespace PelicanProvisioner\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * @property int $id
 * @property int $user_id
 * @property User|null $user
 */
class QuotaBan extends Model
{
    public const string COUNT_CACHE_KEY = 'pelican-provisioner:ban-count';

    protected $table = 'provisioner_quota_bans';

    protected $fillable = [
        'user_id',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }
}
