<?php

declare(strict_types=1);

namespace PelicanProvisioner\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property User|null $user
 */
class QuotaBan extends Model
{
    protected $table = 'provisioner_quota_bans';

    protected $fillable = [
        'user_id',
    ];

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
