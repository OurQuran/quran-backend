<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
/**
 * Class User
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $role
 *
 * @property Collection|Tag[] $tags
 * @property Collection|AyahTag[] $ayah_tags
 * @property Collection|Dictionary[] $dictionaries
 * @property Collection|Session[] $sessions
 *
 * @package App\Models
 */
class User extends Authenticatable
{
    use HasApiTokens, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'username',
        'password',
        'role',
        'remember_token'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // 'created_at',
        // 'updated_at'
    ];

    public function tags()
	{
		return $this->hasMany(Tag::class, 'updated_by');
	}

    public function ayah_tags()
	{
		return $this->hasMany(AyahTag::class, 'approved_by');
	}

	public function dictionaries()
	{
		return $this->hasMany(Dictionary::class, 'updated_by');
	}

    public function hasRoles(...$roles): bool
    {
        return in_array($this->role, $roles);
    }

}
