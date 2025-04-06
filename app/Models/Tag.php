<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Tag
 *
 * @property int $id
 * @property string $tag_category
 * @property string $tag_sub_category
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property User|null $user
 * @property Collection|Ayah[] $ayahs
 *
 * @package App\Models
 */
class Tag extends Model
{
	protected $table = 'tags';

	protected $casts = [
		'created_by' => 'int',
		'updated_by' => 'int'
	];

	protected $fillable = [
        'name',
        'parent_id',
        'created_by',
        'updated_by'
	];

    // TODO: created_by shouldn't be hidden, no?
    protected $hidden = [
      // 'created_by',
      // 'updated_by',
      'created_at',
      'updated_at'
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

	public function updater()
	{
		return $this->belongsTo(User::class, 'updated_by');
	}

	public function ayahs()
	{
		return $this->belongsToMany(Ayah::class, 'ayah_tags')
					->withPivot('id', 'notes', 'created_by', 'updated_by', 'approved_by', 'approved_at');
	}

    public function children()
    {
        return $this->hasMany(Tag::class, 'parent_id', 'id');
    }

    public function allChildren()
    {
        return $this->children()->with('allChildren'); // Recursive Relationship
    }

    public function parent()
    {
        return $this->belongsTo(Tag::class, 'parent_id');
    }
}
