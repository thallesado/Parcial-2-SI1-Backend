<!doctype html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body>
    <table>
        <tr><th colspan="{{ max(count($columns), 1) }}">{{ $titulo }}</th></tr>
        <tr><td colspan="{{ max(count($columns), 1) }}">Gestion: {{ $gestion }}</td></tr>
        <tr>
            @foreach ($columns as $column)
                <th>{{ ucwords(str_replace('_', ' ', $column)) }}</th>
            @endforeach
        </tr>
        @foreach ($rows as $row)
            <tr>
                @foreach ($columns as $column)
                    <td>{{ is_scalar($row[$column] ?? null) ? $row[$column] : json_encode($row[$column] ?? null) }}</td>
                @endforeach
            </tr>
        @endforeach
    </table>
</body>
</html>
