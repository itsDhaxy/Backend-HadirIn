<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Absensi;

class FaceController extends Controller
{
    public function verifyFace(Request $request)
    {
        // ✅ Pastikan ada file upload dengan key 'image'
        if (!$request->hasFile('image')) {
            return response()->json([
                'error' => 'No image uploaded',
                'all_keys' => $request->all(),
                'all_files' => $request->allFiles(),
            ], 400);
        }

        \Log::info('Flutter masuk ke Laravel', [
            'hasFile' => $request->hasFile('image'),
            'filename' => $request->file('image')->getClientOriginalName() ?? null,
        ]);


        // Ambil file
        $file = $request->file('image');

        try {
            // Kirim ke FastAPI
            $response = Http::attach(
                'image', // HARUS sama dengan FastAPI
                file_get_contents($file->getRealPath()),
                $file->getClientOriginalName()
            )->post('http://192.168.1.36:8001/verify-face');

            // Ambil response FastAPI
            $raw = $response->body();
            $json = null;
            try {
                $json = $response->json();
            } catch (\Exception $e) {
                // JSON parsing gagal → tampilkan raw response
            }

            if ($response->successful() && $json && isset($json['success']) && $json['success'] === true) {
                // Simpan ke DB absensi
                $absen = Absensi::create([
                    'name' => $json['user'] ?? 'Unknown',
                    'time' => now(),
                ]);

                return response()->json([
                    'status' => 'success',
                    'data' => $absen,
                    'fastapi_response' => $json,
                ]);
            }

            // Kalau FastAPI gagal
            return response()->json([
                'status' => 'failed',
                'reason' => $json['message'] ?? 'Face not recognized',
                'fastapi_raw' => $raw,
            ], 400);

        } catch (\Exception $e) {
            // Tangani error koneksi ke FastAPI
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to connect to FastAPI',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
