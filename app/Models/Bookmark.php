<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bookmark extends Model
{
    protected $table = 'bookmarks';

    protected $casts = [
      'ayah_id' => 'int',
      'user_id' => 'int'
    ];

    protected $fillable = [
        'ayah_id',
        'user_id'
    ];

    public $timestamps = false; // Disable timestamps

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }

    public function ayah(){
        return $this->belongsTo(Ayah::class, 'ayah_id');
    }
}
