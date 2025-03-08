<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class AyahEdition
 * 
 * @property int $id
 * @property int $ayah_id
 * @property int $edition_id
 * @property string $data
 * @property int $is_audio
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * 
 * @property Ayah $ayah
 * @property Edition $edition
 *
 * @package App\Models
 */
class AyahEdition extends Model
{
	protected $table = 'ayah_edition';
	public $incrementing = false;

	protected $casts = [
		'id' => 'int',
		'ayah_id' => 'int',
		'edition_id' => 'int',
		'is_audio' => 'int'
	];

	protected $fillable = [
		'id',
		'ayah_id',
		'edition_id',
		'data',
		'is_audio'
	];

	public function ayah()
	{
		return $this->belongsTo(Ayah::class);
	}

	public function edition()
	{
		return $this->belongsTo(Edition::class);
	}
}
