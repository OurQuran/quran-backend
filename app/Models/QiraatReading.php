<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Ayah
 *
 * @property int $id
 * @property int $number
 * @property string $text
 * @property int $number_in_surah
 * @property int $page
 * @property int $surah_id
 * @property int $hizb_id
 * @property int $juz_id
 * @property int $sajda
 * @property string|null $ayah_template
 *
 * @property Surah $surah
 * @property Collection|Tag[] $tags
 * @property Collection|Word[] $words
 * @property Collection|Edition[] $editions
 *
 * @package App\Models
 */
class QiraatReading extends Model
{
	protected $table = 'qiraat_readings';

    public $timestamps = true;

	protected $casts = [
        'id' => 'int',
		'imam' => 'string',
		'riwaya' => 'string',
		'rawi' => 'string',
		'name' => 'string',
	];

	protected $fillable = [
        'imam',
        'riwaya',
        'rawi',
        'name',
	];

	public function editions()
	{
		return $this->belongsToMany(Edition::class)
					->withPivot('id', 'data', 'is_audio')
					->withTimestamps();
	}

}
