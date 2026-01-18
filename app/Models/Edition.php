<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Edition
 *
 * @property int $id
 * @property string $identifier
 * @property string $language
 * @property string $name
 * @property string $english_name
 * @property string $format
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Collection|Ayah[] $ayahs
 *
 * @package App\Models
 */
class Edition extends Model
{
	protected $table = 'editions';

	protected $fillable = [
		'identifier',
		'language',
		'name',
		'english_name',
		'format',
		'type'
	];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];

	public function ayahs()
	{
		return $this->belongsToMany(Ayah::class)
					->withPivot('id', 'data', 'is_audio')
					->withTimestamps();
	}
}
