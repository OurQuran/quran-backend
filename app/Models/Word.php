<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Word
 *
 * @property int $id
 * @property int $ayah_id
 * @property string $word
 * @property string|null $word_template
 *
 * @property Ayah $ayah
 * @property Collection|Dictionary[] $dictionaries
 *
 * @package App\Models
 */
class Word extends Model
{
	protected $table = 'words';
	public $timestamps = false;

	protected $casts = [
		'ayah_id' => 'int'
	];

	protected $fillable = [
		'ayah_id',
		'word',
        'position',
		'word_template'
	];

	public function ayah()
	{
		return $this->belongsTo(Ayah::class);
	}

	public function dictionaries()
	{
		return $this->hasMany(Dictionary::class);
	}
}
