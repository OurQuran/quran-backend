<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Surah
 *
 * @property int $id
 * @property int $number
 * @property string $name_ar
 * @property string $name_en
 * @property string $name_en_translation
 * @property string $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $surah_template
 *
 * @property Collection|Ayah[] $ayahs
 *
 * @package App\Models
 */
class Surah extends Model
{
	protected $table = 'surahs';

	protected $casts = [
		'number' => 'int'
	];

	protected $fillable = [
		'surah_template'
	];

	public function ayahs()
	{
		return $this->hasMany(Ayah::class);
	}

    public function mushafAyahs()
    {
        return $this->hasMany(MushafAyah::class);
    }
}
