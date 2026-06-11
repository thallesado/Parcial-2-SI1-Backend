<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Boleta de inscripcion</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        .header { background: #1d4ed8; color: white; padding: 22px; border-radius: 8px; }
        .title { font-size: 24px; font-weight: bold; margin: 0; }
        .subtitle { margin-top: 6px; color: #dbeafe; }
        .section { margin-top: 18px; border: 1px solid #dbeafe; border-radius: 8px; padding: 14px; }
        .section h2 { margin: 0 0 10px; font-size: 16px; color: #1e3a8a; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid td { width: 50%; padding: 7px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .label { color: #64748b; font-size: 10px; text-transform: uppercase; font-weight: bold; }
        .value { margin-top: 2px; font-weight: bold; }
        table.list { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.list th { background: #1d4ed8; color: white; text-align: left; padding: 8px; }
        table.list td { border-bottom: 1px solid #e2e8f0; padding: 8px; }
        .badge { display: inline-block; padding: 5px 8px; border-radius: 5px; background: #dcfce7; color: #166534; font-weight: bold; }
        .pending { background: #fef3c7; color: #92400e; }
        .footer { margin-top: 24px; color: #64748b; font-size: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <p class="title">Boleta de inscripcion universitaria</p>
        <p class="subtitle">Documento generado por el sistema de admision e inscripcion.</p>
    </div>

    <div class="section">
        <h2>Datos del postulante</h2>
        <table class="grid">
            <tr>
                <td><div class="label">ID Postulante</div><div class="value">{{ $detalle['postulante']->postulante_id }}</div></td>
                <td><div class="label">Gestion</div><div class="value">{{ $detalle['postulante']->gestion_id }} - {{ $detalle['postulante']->gestion }}</div></td>
            </tr>
            <tr>
                <td><div class="label">CI</div><div class="value">{{ $detalle['postulante']->ci }}</div></td>
                <td><div class="label">Nombre completo</div><div class="value">{{ $detalle['postulante']->nombres }} {{ $detalle['postulante']->apellidos }}</div></td>
            </tr>
            <tr>
                <td><div class="label">Correo</div><div class="value">{{ $detalle['postulante']->correo }}</div></td>
                <td><div class="label">Telefono</div><div class="value">{{ $detalle['postulante']->telefono }}</div></td>
            </tr>
            <tr>
                <td><div class="label">Colegio</div><div class="value">{{ $detalle['postulante']->colegio_procedencia }}</div></td>
                <td><div class="label">Titulo bachiller</div><div class="value">{{ $detalle['postulante']->titulo_bachiller_codigo }}</div></td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Carreras postuladas</h2>
        <table class="list">
            <thead><tr><th>Orden</th><th>Codigo</th><th>Carrera</th></tr></thead>
            <tbody>
                @foreach ($detalle['carreras'] as $carrera)
                    <tr>
                        <td>Opcion {{ $carrera->orden }}</td>
                        <td>{{ $carrera->codigo }}</td>
                        <td>{{ $carrera->nombre }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Grupo y materias</h2>
        @if ($detalle['estado_grupo'] === 'ASIGNADO')
            <p><span class="badge">Grupo {{ $detalle['postulante']->grupo_asignado }}</span> Turno: {{ $detalle['postulante']->turno }}</p>
        @else
            <p><span class="badge pending">Pendiente de grupo</span></p>
        @endif
        <table class="list">
            <thead><tr><th>Materia</th><th>Docente</th><th>Dia</th><th>Horario</th><th>Aula</th></tr></thead>
            <tbody>
                @foreach ($detalle['materias'] as $materia)
                    <tr>
                        <td>{{ $materia->nombre }}</td>
                        <td>{{ $materia->docente ?? 'Pendiente' }}</td>
                        <td>{{ $materia->dia ?? 'Pendiente' }}</td>
                        <td>
                            @if (isset($materia->hora_inicio))
                                {{ substr($materia->hora_inicio, 0, 5) }} - {{ substr($materia->hora_fin, 0, 5) }}
                            @else
                                Pendiente
                            @endif
                        </td>
                        <td>{{ $materia->aula ?? 'Pendiente' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Pago de inscripcion</h2>
        <table class="grid">
            <tr>
                <td><div class="label">Estado</div><div class="value">{{ $detalle['pago']->estado }}</div></td>
                <td><div class="label">Metodo</div><div class="value">{{ $detalle['pago']->metodo }}</div></td>
            </tr>
            <tr>
                <td><div class="label">Monto</div><div class="value">Bs. {{ number_format((float) $detalle['pago']->monto, 2) }}</div></td>
                <td><div class="label">Comprobante</div><div class="value">{{ $detalle['pago']->numero_comprobante }}</div></td>
            </tr>
        </table>
    </div>

    <p class="footer">Generado el {{ now()->format('d/m/Y H:i') }}. Esta boleta acredita la inscripcion al proceso de admision.</p>
</body>
</html>
