<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAttendanceController extends Controller
{
    /**
     * GET /api/admin/attendance/today
     * Kembalikan rekap hari ini untuk dashboard.
     * Catatan: kalau kolom meta ada 'reason_name', kita tampilkan sebagai Absent.
     */
    public function today()
    {
        $today = now()->toDateString();

        // Ambil SEMUA baris absensi HARI INI saja
        $rows = DB::table('absensis')
            ->where('day', $today)
            ->get(['name', 'check_in_time', 'check_in_status', 'check_out_time', 'check_out_status', 'meta']);

        // Decode meta & map name -> row (ambil yang terakhir kalau ada ganda)
        $map = [];
        foreach ($rows as $r) {
            if (is_string($r->meta)) {
                try {
                    $r->meta = json_decode($r->meta, true) ?: [];
                } catch (\Throwable $e) {
                    $r->meta = [];
                }
            } elseif (!is_array($r->meta)) {
                $r->meta = (array) $r->meta;
            }
            $map[$r->name] = $r;
        }

        // Daftar nama HANYA dari HARI INI
        $allNames = collect(array_keys($map));

        $items = [];
        $count = ['on_time' => 0, 'late' => 0, 'absent' => 0];

        foreach ($allNames as $n) {
            $r = $map[$n];

            // Jika admin isi reason_name → tampilkan Absent
            $reason = $r->meta['reason_name'] ?? '';

            if ($reason) {
                $items[] = [
                    'name' => $n,
                    'time' => '-',
                    'status' => 'Absent',
                    'reason' => $reason,
                ];
                $count['absent']++;
                continue;
            }

            // Normal: pakai status & jam check-in
            $status = $r->check_in_status ?? 'On Time';
            $time = $r->check_in_time ?? '-';

            if ($status === 'On Time')
                $count['on_time']++;
            elseif ($status === 'Late')
                $count['late']++;
            else
                $count['on_time']++; // fallback

            $items[] = ['name' => $n, 'time' => $time, 'status' => $status, 'reason' => ''];
        }

        return response()->json([
            'success' => true,
            'date' => $today,
            'counts' => $count,
            'items' => $items,
        ]);
    }

    /**
     * POST /api/admin/attendance/update
     * Body: { name: string, status: 'On Time'|'Late'|'Absent', reason?: string, time?: 'HH:MM' }
     * - On Time/Late → set check_in_time/check_in_status; clear meta.reason_name
     * - Absent       → simpan meta.reason_name dan kosongkan jam
     */
    public function updateToday(Request $r)
    {
        $name = trim((string) $r->input('name', ''));
        $status = trim((string) $r->input('status', '')); // 'On Time'|'Late'|'Absent' (boleh kosong jika auto)
        $reason = trim((string) $r->input('reason', ''));
        $time = trim((string) $r->input('time', ''));   // 'HH:MM' atau 'HH:MM:SS'
        $phase = strtoupper(trim((string) $r->input('phase', 'IN'))); // 'IN'|'OUT'
        $auto = filter_var($r->input('auto', false), FILTER_VALIDATE_BOOLEAN); // NEW: auto derive status

        if ($name === '') {
            return response()->json(['success' => false, 'message' => 'name wajib diisi'], 422);
        }
        if (!in_array($phase, ['IN', 'OUT'], true)) {
            return response()->json(['success' => false, 'message' => 'phase invalid'], 422);
        }
        if (!$auto && !in_array($status, ['On Time', 'Late', 'Absent'], true)) {
            // jika tidak auto, status wajib valid
            return response()->json(['success' => false, 'message' => 'status invalid'], 422);
        }

        // Jam kerja dari config/env
        $workStart = config('attendance.work_start', env('WORK_START', '10:00'));
        $workEnd = config('attendance.work_end', env('WORK_END', '16:00'));
        $grace = (int) config('attendance.grace_minutes', env('GRACE_MINUTES', 0));

        $today = now()->toDateString();

        // normalisasi HH:MM -> HH:MM:SS
        $norm = function (string $hhmm): string {
            if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $hhmm)) {
                return strlen($hhmm) === 5 ? $hhmm . ':00' : $hhmm;
            }
            return now()->format('H:i:s');
        };

        // cari/buat baris hari ini
        $row = DB::table('absensis')->where(['name' => $name, 'day' => $today])->first();
        if (!$row) {
            DB::table('absensis')->insert([
                'name' => $name,
                'day' => $today,
                'time' => now(),
            ]);
            $row = DB::table('absensis')->where(['name' => $name, 'day' => $today])->first();
        }

        // parse meta existing
        $meta = [];
        if (is_string($row->meta)) {
            try {
                $meta = json_decode($row->meta, true) ?: [];
            } catch (\Throwable $e) {
            }
        } elseif (is_array($row->meta)) {
            $meta = $row->meta;
        }

        // hitung cut times (pakai tanggal hari ini)
        $startCut = now()->copy()->setTimeFromTimeString($workStart)->addMinutes($grace);
        $endCut = now()->copy()->setTimeFromTimeString($workEnd)->subMinutes($grace);

        // helper untuk derive status dari jam & phase
        $deriveStatus = function (string $phase, string $hhmmss) use ($startCut, $endCut) {
            // buat Carbon dari HH:MM:SS pada tanggal hari ini
            $t = now()->copy()->setTimeFromTimeString($hhmmss);
            if ($phase === 'IN') {
                return $t->lessThanOrEqualTo($startCut) ? 'On Time' : 'Late';
            } else { // OUT
                return $t->greaterThanOrEqualTo($endCut) ? 'On Time' : 'Early';
            }
        };

        $update = [];

        if (!$auto && $status === 'Absent') {
            // tandai Absent dan kosongkan jam IN/OUT
            $meta['reason_name'] = $reason ?: 'Tanpa Keterangan';
            $update['check_in_time'] = null;
            $update['check_in_status'] = null;
            $update['check_out_time'] = null;
            $update['check_out_status'] = null;
            $update['meta'] = json_encode($meta);

        } else {
            // clear reason Absent jika ada
            if (isset($meta['reason_name']))
                unset($meta['reason_name']);

            // waktu target (pakai input jika ada, kalau kosong pakai yang sudah ada, fallback now)
            if ($phase === 'IN') {
                $setTime = $time !== '' ? $norm($time) : ($row->check_in_time ?? now()->format('H:i:s'));
                $update['check_in_time'] = $setTime;
                $update['check_in_status'] = $auto
                    ? $deriveStatus('IN', $setTime)
                    : ($status ?: 'On Time');

            } else { // OUT
                if (empty($row->check_in_time)) {
                    return response()->json(['success' => false, 'message' => 'Belum check-in. Tidak bisa set OUT.'], 409);
                }
                $setTime = $time !== '' ? $norm($time) : ($row->check_out_time ?? now()->format('H:i:s'));
                $update['check_out_time'] = $setTime;
                $update['check_out_status'] = $auto
                    ? $deriveStatus('OUT', $setTime)
                    : ($status ?: 'On Time');
            }

            $update['meta'] = json_encode($meta);
        }

        DB::table('absensis')->where(['name' => $name, 'day' => $today])->update($update);

        return response()->json(['success' => true]);
    }
}
