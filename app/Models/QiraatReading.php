<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Ayah
 *
 * @property int $id
 * @property string $imam
 * @property string $riwaya
 * @property string $name
 *
 * @property Surah $surah
 * @property Collection|MushafAyah[] $ayahs
 *
 * @package App\Models
 */
class QiraatReading extends Model
{
	protected $table = 'qiraat_readings';

    public $timestamps = false;

	protected $casts = [
        'id' => 'int',
        'imam'   => AsArrayObject::class,
        'riwaya' => AsArrayObject::class,
        'name'   => AsArrayObject::class,
	];

	protected $fillable = [
        'code',
        'imam',
        'riwaya',
        'name',
	];

    public function editions()
    {
        return $this->hasMany(Edition::class, 'qiraat_reading_id');
    }

}
