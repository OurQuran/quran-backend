<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $ayah_template
 *
 * @property Surah $surah
 * @property Collection|Tag[] $tags
 * @property Collection|Word[] $words
 * @property Collection|Edition[] $editions
 *
 * @package App\Models
 */
class Ayah extends Model
{
	protected $table = 'ayahs';

	protected $casts = [
        'id' => 'int',
		'number' => 'int',
		'number_in_surah' => 'int',
		'page' => 'int',
		'surah_id' => 'int',
		'hizb_id' => 'int',
		'juz_id' => 'int',
		'sajda' => 'int'
	];

    protected $hidden = [
        'created_at',
        'updated_at'
    ];

	protected $fillable = [
		'ayah_template'
	];

	public function surah()
	{
		return $this->belongsTo(Surah::class);
	}

	public function tags()
	{
		return $this->belongsToMany(Tag::class, 'ayah_tags')
					->withPivot('id', 'notes', 'created_by', 'updated_by', 'approved_by', 'approved_at')
					->withTimestamps();
	}

	public function words()
	{
		return $this->hasMany(Word::class);
	}

	public function editions()
	{
		return $this->belongsToMany(Edition::class)
					->withPivot('id', 'data', 'is_audio')
					->withTimestamps();
	}
}
