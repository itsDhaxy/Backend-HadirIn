<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
            ->get(['id', 'name', 'check_in_time', 'check_in_status', 'check_out_time', 'check_out_status', 'meta']);

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
            $employeeId = $r->meta['employee_id'] ?? null;
            if (! $employeeId) {
                $employeeId = $this->resolveEmployeeIdByName($r->name);
            }

            // Jika admin isi reason_name, tampilkan Absent
            $reason = $r->meta['reason_name'] ?? '';

            if ($reason) {
                $items[] = [
                    'employee_id' => $employeeId,
                    'name' => $n,
                    'time' => '-',
                    'status' => 'Absent',
                    'reason' => $reason,
                    'check_in_time' => $r->check_in_time,
                    'check_out_time' => $r->check_out_time,
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

            $items[] = [
                'employee_id' => $employeeId,
                'name' => $n,
                'time' => $time,
                'status' => $status,
                'reason' => '',
                'check_in_time' => $r->check_in_time,
                'check_out_time' => $r->check_out_time,
            ];
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
     * Body: { name?: string, employee_id?: int, status: 'On Time'|'Late'|'Absent', reason?: string, time?: 'HH:MM', phase?: 'IN'|'OUT', auto?: bool }
     * - On Time/Late -> set check_in_time/check_in_status; clear meta.reason_name
     * - Absent       -> simpan meta.reason_name dan kosongkan jam
     */
    public function updateToday(Request $r)
    {
        $name = trim((string) $r->input('name', ''));
        $employeeId = $r->input('employee_id');
        $status = trim((string) $r->input('status', '')); // 'On Time'|'Late'|'Absent' (boleh kosong jika auto)
        $reason = trim((string) $r->input('reason', ''));
        $time = trim((string) $r->input('time', ''));   // 'HH:MM' atau 'HH:MM:SS'
        $phase = strtoupper(trim((string) $r->input('phase', 'IN'))); // 'IN'|'OUT'
        $auto = filter_var($r->input('auto', false), FILTER_VALIDATE_BOOLEAN); // NEW: auto derive status

        $employeeId = is_numeric($employeeId) ? (int) $employeeId : null;

        if ($name === '' && ! $employeeId) {
            return response()->json(['success' => false, 'message' => 'name atau employee_id wajib diisi'], 422);
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
        $meta = $this->normalizeMeta($row->meta ?? null);
        if (! $employeeId && ! empty($meta['employee_id'])) {
            $employeeId = (int) $meta['employee_id'];
        }
        if (! $employeeId && $name !== '') {
            $employeeId = $this->resolveEmployeeIdByName($name);
        }
        if ($employeeId) {
            $meta['employee_id'] = $employeeId;
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

        $row = DB::table('absensis')->where(['name' => $name, 'day' => $today])->first();
        if ($row) {
            $this->syncAttendanceRecordFromAbsensi($row, $meta, $employeeId);
        }

        return response()->json(['success' => true]);
    }

    private function normalizeMeta($meta): array
    {
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($meta)) {
            return $meta;
        }
        if (is_object($meta)) {
            return (array) $meta;
        }

        return [];
    }

    private function resolveEmployeeIdByName(string $name): ?int
    {
        if (! Schema::hasTable('employees')) {
            return null;
        }

        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $lower = mb_strtolower($name);
        $normalized = str_replace('_', ' ', $lower);

        $employeeId = DB::table('employees')
            ->whereRaw('LOWER(full_name) = ?', [$lower])
            ->orWhereRaw('LOWER(full_name) = ?', [$normalized])
            ->value('id');

        if ($employeeId) {
            return (int) $employeeId;
        }

        $slug = $this->slugName($name);
        $candidates = DB::table('employees')->get(['id', 'full_name']);
        foreach ($candidates as $candidate) {
            if ($this->slugName($candidate->full_name) === $slug) {
                return (int) $candidate->id;
            }
        }

        return null;
    }

    private function slugName(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9\\-_. ]+/', '', $slug);
        $slug = preg_replace('/\\s+/', '_', $slug);

        return $slug !== '' ? $slug : 'user_' . time();
    }

    private function normalizeTime($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $time = trim((string) $value);
        if ($time === '') {
            return null;
        }

        return $time;
    }

    private function mapReasonNameToStatus(string $reasonName): array
    {
        $lower = mb_strtolower(trim($reasonName));
        if ($lower === 'sakit') {
            return ['sick', 'sakit', null];
        }
        if ($lower === 'izin') {
            return ['leave', 'other', 'Izin'];
        }
        if ($lower === 'tanpa keterangan') {
            return ['absent', 'alpa', null];
        }

        return ['absent', 'alpa', $reasonName];
    }

    private function isLateCheckIn(string $checkInTime): bool
    {
        $workStart = config('attendance.work_start', env('WORK_START', '10:00'));
        $grace = (int) config('attendance.grace_minutes', env('GRACE_MINUTES', 0));
        $format = strlen($checkInTime) === 5 ? 'H:i' : 'H:i:s';

        try {
            $checkIn = Carbon::createFromFormat($format, $checkInTime);
        } catch (\Throwable $e) {
            return false;
        }

        $startCut = Carbon::now()->copy()->setTimeFromTimeString($workStart)->addMinutes($grace);

        return $checkIn->greaterThan($startCut);
    }

    private function calculateLateMinutes(?string $checkInTime, string $status): int
    {
        if ($checkInTime === null || $status !== 'late') {
            return 0;
        }

        $workStart = config('attendance.work_start', env('WORK_START', '10:00'));
        $grace = (int) config('attendance.grace_minutes', env('GRACE_MINUTES', 0));
        $format = strlen($checkInTime) === 5 ? 'H:i' : 'H:i:s';

        try {
            $checkIn = Carbon::createFromFormat($format, $checkInTime);
        } catch (\Throwable $e) {
            return 0;
        }

        $startCut = Carbon::now()->copy()->setTimeFromTimeString($workStart)->addMinutes($grace);
        if ($checkIn->lessThanOrEqualTo($startCut)) {
            return 0;
        }

        return $checkIn->diffInMinutes($startCut);
    }

    private function syncAttendanceRecordFromAbsensi(object $row, array $meta, ?int $employeeId): void
    {
        if (! $employeeId || ! Schema::hasTable('attendance_records')) {
            return;
        }

        $day = $row->day ?? null;
        if (! $day) {
            return;
        }

        $checkInTime = $this->normalizeTime($row->check_in_time ?? null);
        $checkOutTime = $this->normalizeTime($row->check_out_time ?? null);
        $reasonName = trim((string) ($meta['reason_name'] ?? ''));

        $status = 'present';
        $leaveReason = null;
        $notes = null;

        if ($reasonName !== '') {
            [$status, $leaveReason, $notes] = $this->mapReasonNameToStatus($reasonName);
            $checkInTime = null;
            $checkOutTime = null;
        } else {
            if (($row->check_in_status ?? '') === 'Late') {
                $status = 'late';
            } elseif ($checkInTime !== null) {
                $status = $this->isLateCheckIn($checkInTime) ? 'late' : 'present';
            }
        }

        $payload = [
            'status' => $status,
            'check_in_time' => $checkInTime,
            'check_out_time' => $checkOutTime,
            'late_minutes' => $this->calculateLateMinutes($checkInTime, $status),
        ];

        if (Schema::hasColumn('attendance_records', 'leave_reason')) {
            $payload['leave_reason'] = $leaveReason;
        }
        if (Schema::hasColumn('attendance_records', 'notes')) {
            $payload['notes'] = $notes;
        }
        if (Schema::hasColumn('attendance_records', 'status_id') &&
            Schema::hasTable('attendance_statuses')) {
            $payload['status_id'] = DB::table('attendance_statuses')
                ->where('code', $status)
                ->value('id');
        }
        if ($leaveReason &&
            Schema::hasColumn('attendance_records', 'reason_id') &&
            Schema::hasTable('attendance_reasons')) {
            $payload['reason_id'] = DB::table('attendance_reasons')
                ->where('code', $leaveReason)
                ->value('id');
        }

        $now = now();
        if (Schema::hasColumn('attendance_records', 'updated_at')) {
            $payload['updated_at'] = $now;
        }

        $record = DB::table('attendance_records')
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', $day)
            ->first();

        if ($record) {
            DB::table('attendance_records')->where('id', $record->id)->update($payload);
            return;
        }

        $payload['employee_id'] = $employeeId;
        $payload['attendance_date'] = $day;
        if (Schema::hasColumn('attendance_records', 'created_at')) {
            $payload['created_at'] = $now;
        }

        DB::table('attendance_records')->insert($payload);
    }
}








