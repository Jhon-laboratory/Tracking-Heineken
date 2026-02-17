<?php
session_start();

// ============================================
// PÁGINA DE MONITOREO - VER ESTADO DE PLACAS POR FECHA
// ============================================

require_once 'conexion.php';

$fecha_seleccionada = isset($_GET['fecha']) ? $_GET['fecha'] : date('Y-m-d');
$buscar_placa = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$resultados = [];
$resumen_estados = [];

if ($fecha_seleccionada) {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener todas las plantillas de conductores para la fecha seleccionada
    $query_plantillas = "SELECT id, nombre, placa 
                         FROM DPL.externos.plantillas_conductores 
                         WHERE fecha_plantilla = ? AND activo = 1
                         ORDER BY nombre";
    
    $stmt = sqlsrv_query($conn, $query_plantillas, array($fecha_seleccionada));
    
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $resultados[$row['placa']] = [
                'plantilla_id' => $row['id'],
                'nombre' => $row['nombre'],
                'placa' => $row['placa'],
                'estados' => [],
                'ultimo_estado' => null,
                'progreso' => 0,
                'estado_general' => 'pendiente'
            ];
        }
        sqlsrv_free_stmt($stmt);
    }
    
    // Obtener todos los viajes para esa fecha
    if (!empty($resultados)) {
        $placas = array_keys($resultados);
        $placeholders = implode(',', array_fill(0, count($placas), '?'));
        
        $query_viajes = "SELECT * FROM externos.viajes_tracking 
                         WHERE placa IN ($placeholders) AND fecha_viaje = ?
                         ORDER BY placa";
        
        $params = array_merge($placas, array($fecha_seleccionada));
        $stmt_viajes = sqlsrv_query($conn, $query_viajes, $params);
        
        if ($stmt_viajes !== false) {
            while ($viaje = sqlsrv_fetch_array($stmt_viajes, SQLSRV_FETCH_ASSOC)) {
                $placa = $viaje['placa'];
                
                if (isset($resultados[$placa])) {
                    // Procesar estados del viaje
                    $ultimo_estado_numero = 0;
                    $estados_marcados = [];
                    
                    for ($i = 1; $i <= 7; $i++) {
                        $campo_fecha = 'estado' . $i . '_fecha';
                        if (!empty($viaje[$campo_fecha])) {
                            $ultimo_estado_numero = $i;
                            $estados_marcados[] = $viaje['estado' . $i . '_nombre'];
                        }
                    }
                    
                    $resultados[$placa]['viaje_id'] = $viaje['id'];
                    $resultados[$placa]['estados'] = $estados_marcados;
                    $resultados[$placa]['ultimo_estado'] = $ultimo_estado_numero;
                    $resultados[$placa]['progreso'] = round(($ultimo_estado_numero / 7) * 100);
                    $resultados[$placa]['estado_general'] = $viaje['estado_general'];
                    $resultados[$placa]['fecha_inicio'] = $viaje['fecha_inicio_viaje'];
                    $resultados[$placa]['fecha_fin'] = $viaje['fecha_fin_viaje'];
                }
            }
            sqlsrv_free_stmt($stmt_viajes);
        }
    }
    
    // Calcular resumen
    $resumen_estados = [
        'no_iniciado' => 0,
        'en_progreso' => 0,
        'completado' => 0,
        'total' => count($resultados)
    ];
    
    foreach ($resultados as $placa => $data) {
        if (empty($data['estados'])) {
            $resumen_estados['no_iniciado']++;
        } elseif ($data['estado_general'] === 'completado') {
            $resumen_estados['completado']++;
        } else {
            $resumen_estados['en_progreso']++;
        }
    }
    
    // Filtrar por búsqueda si hay término
    if (!empty($buscar_placa)) {
        $resultados_filtrados = [];
        foreach ($resultados as $placa => $data) {
            if (stripos($placa, $buscar_placa) !== false || stripos($data['nombre'], $buscar_placa) !== false) {
                $resultados_filtrados[$placa] = $data;
            }
        }
        $resultados = $resultados_filtrados;
    }
}

// Función para obtener clase de estado
function getEstadoClass($estado_general, $progreso) {
    if ($estado_general === 'completado') return 'estado-completado';
    if ($progreso > 0) return 'estado-progreso';
    return 'estado-pendiente';
}

// Función para obtener el nombre del estado actual
function getEstadoActual($data) {
    if (empty($data['estados'])) {
        return 'No iniciado';
    }
    if ($data['estado_general'] === 'completado') {
        return 'Completado';
    }
    
    $estados_nombres = [
        1 => 'Salida Ruta',
        2 => 'Retorno de ruta',
        3 => 'Pluma en bodega',
        4 => 'Inicio Liquidación',
        5 => 'Fin Liquidación',
        6 => 'Inicio Caja',
        7 => 'Fin Caja'
    ];
    
    return $estados_nombres[$data['ultimo_estado'] + 1] ?? 'En progreso';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>RANSA - Monitor de Placas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --verde: #009A3F;
            --verde-hover: #007a32;
            --naranja: #F39200;
            --naranja-hover: #e08500;
            --gris: #9D9D9C;
            --gris-claro: #f5f5f5;
            --texto: #2c3e50;
            --texto-secundario: #7f8c8d;
            --borde: #e9ecef;
            --blanco: #ffffff;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f8f9fa;
            min-height: 100vh;
            padding: 20px;
            color: var(--texto);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Botones flotantes */
        .floating-buttons {
            position: fixed;
            top: 20px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: space-between;
            padding: 0 20px;
            z-index: 1000;
            pointer-events: none;
        }

        .floating-button {
            pointer-events: auto;
            background: var(--blanco);
            color: var(--texto);
            border: 1px solid var(--borde);
            border-radius: 8px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            text-decoration: none;
        }

        .floating-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            background: var(--gris-claro);
        }

        .floating-button i {
            font-size: 14px;
        }

        .floating-button.right {
            background: var(--verde);
            color: white;
            border: none;
        }

        .floating-button.right:hover {
            background: var(--verde-hover);
        }

        /* Header */
        .header {
            background: var(--blanco);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.03);
            border: 1px solid var(--borde);
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--texto);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .header h1 i {
            color: var(--verde);
        }

        .filtros {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }

        .fecha-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--gris-claro);
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--borde);
        }

        .fecha-selector i {
            color: var(--naranja);
        }

        input[type="date"] {
            padding: 8px 12px;
            border: 1px solid var(--borde);
            border-radius: 6px;
            font-size: 14px;
            outline: none;
            background: var(--blanco);
        }

        .buscador {
            flex: 1;
            min-width: 250px;
            display: flex;
            align-items: center;
            gap: 10px;
            background: var(--blanco);
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid var(--borde);
        }

        .buscador i {
            color: var(--gris);
        }

        .buscador input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 14px;
            background: transparent;
        }

        .btn-ver {
            background: var(--verde);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-ver:hover {
            background: var(--verde-hover);
        }

        .btn-limpiar {
            background: var(--blanco);
            color: var(--texto);
            border: 1px solid var(--borde);
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-limpiar:hover {
            background: var(--gris-claro);
        }

        /* Cards de resumen */
        .resumen-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }

        .resumen-card {
            background: var(--blanco);
            border-radius: 10px;
            padding: 20px;
            border: 1px solid var(--borde);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .resumen-card .label {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--texto-secundario);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .resumen-card .valor {
            font-size: 2.2rem;
            font-weight: 600;
            color: var(--texto);
        }

        .resumen-card.total .label i { color: #3498db; }
        .resumen-card.no-iniciado .label i { color: var(--gris); }
        .resumen-card.en-progreso .label i { color: var(--naranja); }
        .resumen-card.completado .label i { color: var(--verde); }

        /* Tabla */
        .table-container {
            background: var(--blanco);
            border-radius: 12px;
            border: 1px solid var(--borde);
            overflow: hidden;
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
            border-bottom: 2px solid var(--borde);
        }

        th {
            padding: 16px 20px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--texto-secundario);
        }

        td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--borde);
            color: var(--texto);
            font-size: 14px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #fafbfc;
        }

        /* Indicadores de estado */
        .estado-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .estado-pendiente .estado-badge {
            background: #f8f9fa;
            color: var(--gris);
            border: 1px solid var(--borde);
        }

        .estado-progreso .estado-badge {
            background: #fff4e5;
            color: var(--naranja);
            border: 1px solid #ffe0b2;
        }

        .estado-completado .estado-badge {
            background: #e8f5e9;
            color: var(--verde);
            border: 1px solid #c8e6c9;
        }

        /* Barra de progreso */
        .progress-bar {
            width: 100%;
            height: 6px;
            background: var(--gris-claro);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-fill {
            height: 100%;
            background: var(--verde);
            border-radius: 3px;
            transition: width 0.3s ease;
        }

        .progress-fill.progreso {
            background: var(--naranja);
        }

        .progress-fill.completado {
            background: var(--verde);
        }

        /* Timeline compacto */
        .timeline-compact {
            display: flex;
            gap: 4px;
        }

        .timeline-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--gris-claro);
            border: 1px solid var(--borde);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            color: var(--texto-secundario);
        }

        .timeline-dot.marcado {
            background: var(--verde);
            border-color: var(--verde);
            color: white;
        }

        .timeline-dot.actual {
            background: var(--naranja);
            border-color: var(--naranja);
            color: white;
            animation: pulse-light 2s infinite;
        }

        @keyframes pulse-light {
            0% { box-shadow: 0 0 0 0 rgba(243, 146, 0, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(243, 146, 0, 0); }
            100% { box-shadow: 0 0 0 0 rgba(243, 146, 0, 0); }
        }

        /* Horarios */
        .horario {
            font-size: 12px;
            color: var(--texto-secundario);
            margin-top: 4px;
        }

        /* No datos */
        .no-datos {
            text-align: center;
            padding: 60px 20px;
            background: var(--blanco);
            border-radius: 12px;
            border: 1px solid var(--borde);
            color: var(--texto-secundario);
        }

        .no-datos i {
            font-size: 48px;
            color: var(--borde);
            margin-bottom: 15px;
        }

        .no-datos h3 {
            font-weight: 500;
            margin-bottom: 10px;
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding: 20px;
            color: var(--texto-secundario);
            font-size: 13px;
        }

        @media (max-width: 1024px) {
            .resumen-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .floating-buttons {
                padding: 0 10px;
            }

            .floating-button {
                padding: 6px 12px;
                font-size: 12px;
            }

            .filtros {
                flex-direction: column;
                align-items: stretch;
            }

            .fecha-selector {
                width: 100%;
            }

            .buscador {
                width: 100%;
            }

            .btn-ver, .btn-limpiar {
                width: 100%;
                justify-content: center;
            }

            .table-container {
                overflow-x: auto;
            }

            table {
                min-width: 800px;
            }

            .resumen-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Botones flotantes superiores -->
    <div class="floating-buttons">
        <a href="dashboard.php" class="floating-button">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="registro_plantilla.php" class="floating-button right">
            <i class="fas fa-upload"></i> Cargar Plantilla
        </a>
    </div>

    <div class="container">
        <!-- Header con filtros -->
        <div class="header">
            <h1>
                <i class="fas fa-chart-line"></i>
                Monitor de Placas
            </h1>
            
            <form method="GET" class="filtros">
                <div class="fecha-selector">
                    <i class="fas fa-calendar-alt"></i>
                    <input type="date" name="fecha" value="<?= $fecha_seleccionada ?>" max="<?= date('Y-m-d') ?>">
                </div>
                
                <div class="buscador">
                    <i class="fas fa-search"></i>
                    <input type="text" name="buscar" placeholder="Buscar por placa o conductor..." value="<?= htmlspecialchars($buscar_placa) ?>">
                </div>
                
                <button type="submit" class="btn-ver">
                    <i class="fas fa-filter"></i> Aplicar Filtros
                </button>
                
                <?php if (!empty($buscar_placa) || $fecha_seleccionada != date('Y-m-d')): ?>
                <a href="monitor.php" class="btn-limpiar">
                    <i class="fas fa-times"></i> Limpiar
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Resumen de estados -->
        <?php if (!empty($resultados)): ?>
        <div class="resumen-grid">
            <div class="resumen-card total">
                <div class="label">
                    <i class="fas fa-truck"></i> Total Placas
                </div>
                <div class="valor"><?= $resumen_estados['total'] ?></div>
            </div>
            <div class="resumen-card no-iniciado">
                <div class="label">
                    <i class="fas fa-clock"></i> No Iniciados
                </div>
                <div class="valor"><?= $resumen_estados['no_iniciado'] ?></div>
            </div>
            <div class="resumen-card en-progreso">
                <div class="label">
                    <i class="fas fa-spinner"></i> En Progreso
                </div>
                <div class="valor"><?= $resumen_estados['en_progreso'] ?></div>
            </div>
            <div class="resumen-card completado">
                <div class="label">
                    <i class="fas fa-check-circle"></i> Completados
                </div>
                <div class="valor"><?= $resumen_estados['completado'] ?></div>
            </div>
        </div>

        <!-- Tabla de resultados -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Placa</th>
                        <th>Conductor</th>
                        <th>Progreso</th>
                        <th>Estado Actual</th>
                        <th>Timeline</th>
                        <th>Horarios</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultados as $placa => $data): 
                        $estado_class = getEstadoClass($data['estado_general'], $data['progreso']);
                    ?>
                    <tr class="<?= $estado_class ?>">
                        <td>
                            <strong><?= htmlspecialchars($placa) ?></strong>
                        </td>
                        <td><?= htmlspecialchars($data['nombre']) ?></td>
                        <td style="min-width: 120px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="font-weight: 500; min-width: 40px;"><?= $data['progreso'] ?>%</span>
                                <div class="progress-bar">
                                    <div class="progress-fill <?= $estado_class ?>" style="width: <?= $data['progreso'] ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="estado-badge">
                                <?= getEstadoActual($data) ?>
                            </span>
                        </td>
                        <td>
                            <div class="timeline-compact">
                                <?php for ($i = 1; $i <= 7; $i++): 
                                    $clase_dot = '';
                                    if ($i <= $data['ultimo_estado']) {
                                        $clase_dot = 'marcado';
                                    } elseif ($i == $data['ultimo_estado'] + 1 && $data['estado_general'] !== 'completado') {
                                        $clase_dot = 'actual';
                                    }
                                ?>
                                <div class="timeline-dot <?= $clase_dot ?>"><?= $i ?></div>
                                <?php endfor; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($data['fecha_inicio'])): ?>
                                <div><i class="far fa-clock" style="font-size: 11px;"></i> 
                                    Inicio: <?php 
                                        if ($data['fecha_inicio'] instanceof DateTime) {
                                            echo $data['fecha_inicio']->format('H:i');
                                        } else {
                                            echo substr($data['fecha_inicio'], 11, 5);
                                        }
                                    ?>
                                </div>
                                <?php if (!empty($data['fecha_fin'])): ?>
                                <div class="horario">
                                    <i class="far fa-check-circle" style="font-size: 11px;"></i> 
                                    Fin: <?php 
                                        if ($data['fecha_fin'] instanceof DateTime) {
                                            echo $data['fecha_fin']->format('H:i');
                                        } else {
                                            echo substr($data['fecha_fin'], 11, 5);
                                        }
                                    ?>
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--gris);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($fecha_seleccionada): ?>
        <!-- No hay datos -->
        <div class="no-datos">
            <i class="fas fa-calendar-times"></i>
            <h3>No hay registros para la fecha <?= date('d/m/Y', strtotime($fecha_seleccionada)) ?></h3>
            <p style="margin-bottom: 20px;">No se encontraron conductores asignados para esta fecha</p>
            <a href="registro_plantilla.php" class="btn-ver" style="display: inline-block; text-decoration: none;">
                <i class="fas fa-upload"></i> Cargar Plantilla
            </a>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p>Monitor de estado de placas RANSA © <?= date('Y') ?></p>
        </div>
    </div>

    <script>
        // Auto-refresh cada 30 segundos
        <?php if (!empty($resultados)): ?>
        setTimeout(function() {
            location.reload();
        }, 30000);
        <?php endif; ?>

        // Mantener el foco en el buscador
        document.addEventListener('keydown', function(e) {
            if (e.key === '/' && !e.ctrlKey && !e.metaKey) {
                e.preventDefault();
                document.querySelector('input[name="buscar"]').focus();
            }
        });

        // Tooltip para atajo de teclado
        const buscador = document.querySelector('input[name="buscar"]');
        if (buscador) {
            buscador.title = 'Presiona / para buscar';
        }
    </script>
</body>
</html>