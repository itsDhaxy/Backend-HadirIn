<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Absensi extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'time',
    ];

    public $timestamps = false; // jika tidak pakai created_at / updated_at
}
