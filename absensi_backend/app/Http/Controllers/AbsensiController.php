<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class AbsensiController extends Controller
{
    public function index()
    {
        $data = DB::table('absensis')->orderBy('id', 'desc')->get();

        return view('absensi.index', compact('data'));
    }
}
