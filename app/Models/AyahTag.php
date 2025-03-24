<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AyahTag
 *
 * @property int $id
 * @property int $tag_id
 * @property int $ayah_id
 * @property string|null $notes
 * @property int $created_by
 * @property int|null $updated_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Tag $tag
 * @property Ayah $ayah
 * @property User|null $user
 *
 * @package App\Models
 */
class AyahTag extends Model
{
	protected $table = 'ayah_tags';

	protected $casts = [
		'tag_id' => 'int',
		'ayah_id' => 'int',
		'created_by' => 'int',
		'updated_by' => 'int',
		'approved_by' => 'int',
	];

	protected $fillable = [
		'tag_id',
		'ayah_id',
		'notes',
		'created_by',
		'updated_by',
		'approved_by',
		'approved_at'
	];

	public function tag()
	{
		return $this->belongsTo(Tag::class);
	}

	public function ayah()
	{
		return $this->belongsTo(Ayah::class);
	}

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }


    public function approvedByUser()
	{
		return $this->belongsTo(User::class, 'approved_by');
	}
}
