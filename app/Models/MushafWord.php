<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MushafWord extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mushaf_ayah_id',
        'word',
        'word_template',
        'position',
        'pure_word',
    ];

    public function mushafAyah(): BelongsTo
    {
        return $this->belongsTo(MushafAyah::class);
    }
}
