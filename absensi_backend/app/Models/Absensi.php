<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Absensi extends Model
{
    use HasFactory;

    // kalau tabel memang bernama 'absensis', ini tidak perlu diubah
    // protected $table = 'absensis';

    public $timestamps = false; // karena tidak pakai created_at / updated_at

    protected $fillable = [
        // legacy (opsional, kalau masih ada data lama)
        'time',

        // identitas
        'name',
        'day',

        // jam in/out
        'check_in_time',
        'check_out_time',

        // status
        'check_in_status',
        'check_out_status',
    ];

}
