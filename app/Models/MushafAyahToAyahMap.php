<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MushafAyahToAyahMap extends Model
{
    protected $table = 'mushaf_ayah_to_ayah_map';

    protected $fillable = [
        'mushaf_ayah_id',
        'ayah_id',
        'map_type',
        'part_no',
        'parts_total',
        'ayah_order',
    ];

    public function mushafAyah(): BelongsTo
    {
        return $this->belongsTo(MushafAyah::class);
    }

    public function ayah(): BelongsTo
    {
        return $this->belongsTo(Ayah::class);
    }
}
