<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MushafAyah extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'qiraat_reading_id',
        'text',
        'number_in_surah',
        'page',
        'surah_id',
        'hizb_id',
        'juz_id',
        'sajda',
        'ayah_template',
        'pure_text',
    ];

    public function qiraatReading(): BelongsTo
    {
        return $this->belongsTo(QiraatReading::class);
    }

    public function surah(): BelongsTo
    {
        return $this->belongsTo(Surah::class);
    }

    protected function casts(): array
    {
        return [
            'sajda' => 'boolean',
        ];
    }
}
