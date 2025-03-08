<?php

/**
 * Created by Reliese Model.
 */

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Dictionary
 *
 * @property int $id
 * @property int $word_id
 * @property string $meaning
 * @property string $lang
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Word $word
 * @property User|null $user
 *
 * @package App\Models
 */
class Dictionary extends Model
{
	protected $table = 'dictionary';

	protected $casts = [
		'word_id' => 'int',
		'created_by' => 'int',
		'updated_by' => 'int'
	];

	protected $fillable = [
		'word_id',
		'meaning',
		'lang',
		'created_by',
		'updated_by'
	];

	public function word()
	{
		return $this->belongsTo(Word::class);
	}

    public function createdByUser()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedByUser()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
