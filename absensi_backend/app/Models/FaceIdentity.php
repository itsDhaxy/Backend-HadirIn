<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class FaceIdentity extends Model {
  protected $fillable = ['person_slug','name','pegawai_id'];
}
