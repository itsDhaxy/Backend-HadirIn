<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use App\Models\Absensi;

class FaceController extends Controller
{
    /**
     * JALUR LAMA
     * Flutter -> (upload foto) -> Laravel -> FastAPI -> simpan DB
     */
    public function verifyFace(Request $request)
    {
        if (!$request->hasFile('image')) {
            return response()->json(['success' => false, 'status' => 'failed', 'message' => 'No image'], 400);
        }
        $file = $request->file('image');

        // Jam kerja
        $workStart = config('attendance.work_start', env('WORK_START', '10:00'));
        $workEnd   = config('attendance.work_end',   env('WORK_END',   '16:00'));
        $grace     = (int) config('attendance.grace_minutes', env('GRACE_MINUTES', 0));

        $now = now();
        $startCut = (clone $now)->setTimeFromTimeString($workStart)->addMinutes($grace);
        $endCut   = (clone $now)->setTimeFromTimeString($workEnd)->subMinutes($grace);

        try {
            $fastapiUrl = rtrim(env('FASTAPI_URL', ''), '/') . '/verify-face';

            // Kirim pakai stream + timeout lebih longgar
            $stream = fopen($file->getRealPath(), 'r');
            $resp = Http::connectTimeout(5)
                ->timeout(45)
                ->attach('image', $stream, $file->getClientOriginalName(), [
                    'Content-Type' => $file->getMimeType() ?: 'image/jpeg'
                ])
                ->post($fastapiUrl);
            if (is_resource($stream)) fclose($stream);

            $json = $resp->json();

            if (!$resp->successful() || !($json['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'status'  => 'failed',
                    'reason'  => $json['message'] ?? 'Wajah tidak dikenali',
                    'fastapi_status' => $resp->status(),
                    'fastapi_raw'    => $resp->body(),
                ], 400);
            }

            // === GUARD: block Unknown/kosong ===
            $name = trim((string) ($json['user'] ?? ''));
            if ($name === '' || strcasecmp($name, 'unknown') === 0) {
                return response()->json([
                    'success' => false,
                    'status'  => 'failed',
                    'message' => 'Wajah belum dikenali (Unknown). Tidak disimpan.'
                ], 422);
            }
            // ===================================

            $day  = $now->toDateString();

            // Satu baris per (name, day)
            $row = Absensi::firstOrCreate(
                ['name' => $name, 'day' => $day],
                ['time' => now()]
            );

            $type  = strtoupper($request->input('type', '')); // IN / OUT
            $phase = null;

            if ($type === 'IN') {
                if (!is_null($row->check_in_time)) {
                    return response()->json([
                        'success' => true,
                        'status'  => 'already',
                        'message' => 'Sudah check-in',
                        'data'    => $row
                    ], 200);
                }
                $row->check_in_time = $now->format('H:i:s');

                // hanya isi status kalau kosong (jangan timpa admin override)
                if (empty($row->check_in_status)) {
                    $row->check_in_status = $now->lessThanOrEqualTo($startCut) ? 'On Time' : 'Late';
                }
                $phase = 'IN';

            } elseif ($type === 'OUT') {
                if (is_null($row->check_in_time)) {
                    return response()->json([
                        'success' => false,
                        'status'  => 'failed',
                        'message' => 'Belum check-in. Silakan check-in terlebih dahulu.'
                    ], 400);
                }
                if (!is_null($row->check_out_time)) {
                    return response()->json([
                        'success' => true,
                        'status'  => 'already',
                        'message' => 'Sudah check-out',
                        'data'    => $row
                    ], 200);
                }
                $row->check_out_time = $now->format('H:i:s');

                if (empty($row->check_out_status)) {
                    $row->check_out_status = $now->greaterThanOrEqualTo($endCut) ? 'On Time' : 'Early';
                }
                $phase = 'OUT';

            } else {
                // fallback otomatis
                if (is_null($row->check_in_time)) {
                    $row->check_in_time = $now->format('H:i:s');
                    if (empty($row->check_in_status)) {
                        $row->check_in_status = $now->lessThanOrEqualTo($startCut) ? 'On Time' : 'Late';
                    }
                    $phase = 'IN';
                } elseif (is_null($row->check_out_time)) {
                    $row->check_out_time = $now->format('H:i:s');
                    if (empty($row->check_out_status)) {
                        $row->check_out_status = $now->greaterThanOrEqualTo($endCut) ? 'On Time' : 'Early';
                    }
                    $phase = 'OUT';
                } else {
                    return response()->json([
                        'success' => true,
                        'status'  => 'already',
                        'message' => 'Absen hari ini sudah lengkap (IN & OUT).',
                        'data'    => $row
                    ], 200);
                }
            }

            if (isset($json['distance'])) $row->distance = (float) $json['distance'];
            if (isset($json['gap']))      $row->gap      = (float) $json['gap'];

            $row->save();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'phase'   => $phase,
                'data'    => $row,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * JALUR BARU
     * Flutter -> FastAPI -> Flutter -> Laravel JSON -> DB
     */
    public function storeFromFastapiJson(Request $r)
    {
        try {
            // === GUARD: block Unknown/kosong ===
            $name = trim((string) $r->input('name', ''));
            if ($name === '' || strcasecmp($name, 'unknown') === 0) {
                return response()->json([
                    'success' => false,
                    'status'  => 'failed',
                    'message' => 'Wajah belum dikenali (Unknown). Tidak disimpan.'
                ], 422);
            }
            // ===================================

            $type = strtoupper((string) $r->input('type', ''));
            if (!in_array($type, ['IN', 'OUT'], true)) {
                return response()->json(['success'=>false, 'message'=>'type harus IN/OUT'], 422);
            }

            $distance = $r->input('distance');
            $gap      = $r->input('gap');

            // Jam kerja
            $workStart = config('attendance.work_start', env('WORK_START', '10:00'));
            $workEnd   = config('attendance.work_end',   env('WORK_END',   '16:00'));
            $grace     = (int) config('attendance.grace_minutes', env('GRACE_MINUTES', 0));

            $now = now();
            $day = $now->toDateString();
            $startCut = (clone $now)->setTimeFromTimeString($workStart)->addMinutes($grace);
            $endCut   = (clone $now)->setTimeFromTimeString($workEnd)->subMinutes($grace);

            $row = Absensi::firstOrCreate(
                ['name' => $name, 'day' => $day],
                ['time' => now()]
            );

            if ($type === 'IN') {
                if ($row->check_in_time) {
                    return response()->json(['success'=>true, 'status'=>'already', 'message'=>'Sudah check-in', 'data'=>$row], 200);
                }
                $row->check_in_time = $now->format('H:i:s');

                if (empty($row->check_in_status)) {
                    $row->check_in_status = $now->lessThanOrEqualTo($startCut) ? 'On Time' : 'Late';
                }

            } else { // OUT
                if (!$row->check_in_time) {
                    return response()->json(['success'=>false, 'status'=>'failed', 'message'=>'Belum check-in'], 400);
                }
                if ($row->check_out_time) {
                    return response()->json(['success'=>true, 'status'=>'already', 'message'=>'Sudah check-out', 'data'=>$row], 200);
                }
                $row->check_out_time = $now->format('H:i:s');

                if (empty($row->check_out_status)) {
                    $row->check_out_status = $now->greaterThanOrEqualTo($endCut) ? 'On Time' : 'Early';
                }
            }

            if ($distance !== null) $row->distance = (float) $distance;
            if ($gap !== null)      $row->gap      = (float) $gap;

            $row->save();

            return response()->json([
                'success' => true,
                'status'  => 'success',
                'phase'   => $type,
                'data'    => $row,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
