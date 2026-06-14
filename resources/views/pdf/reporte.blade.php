<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #172554; font-size: 8px; }
        h1 { margin: 0 0 5px; font-size: 18px; }
        p { margin: 0 0 14px; color: #475569; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1d4ed8; color: white; text-align: left; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <p>Gestion: {{ $gestion }} | Generado: {{ now()->format('d/m/Y H:i') }}</p>
    @forelse ($rows->chunk(30) as $pageRows)
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th>{{ ucwords(str_replace('_', ' ', $column)) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($pageRows as $row)
                    <tr>
                        @foreach ($columns as $column)
                            <td>{{ is_scalar($row[$column] ?? null) ? $row[$column] : json_encode($row[$column] ?? null) }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if (!$loop->last)
            <div style="page-break-after: always;"></div>
        @endif
    @empty
        <p>Sin datos para mostrar.</p>
    @endforelse
</body>
</html>
