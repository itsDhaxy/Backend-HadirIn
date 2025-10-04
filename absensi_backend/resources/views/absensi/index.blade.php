<!DOCTYPE html>
<html>
<head>
    <title>Data Absensi</title>
    <style>
        table {
            border-collapse: collapse;
            width: 80%;
            margin: 20px auto;
        }
        th, td {
            border: 1px solid #aaa;
            padding: 8px;
            text-align: center;
        }
        th {
            background: #f2f2f2;
        }
    </style>
</head>
<body>
    <h2 style="text-align:center;">Data Absensi</h2>

    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nama</th>
                <th>Waktu</th>
                <th>Dibuat</th>
            </tr>
        </thead>
        <tbody>
            @forelse($data as $absen)
                <tr>
                    <td>{{ $absen->id }}</td>
                    <td>{{ $absen->name }}</td>
                    <td>{{ $absen->time }}</td>
                    <td>{{ $absen->created_at }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">Belum ada data</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
