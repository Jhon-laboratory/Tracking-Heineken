<?php
// ============================================
// ACTIVAR DEBUG
// ============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

// ============================================
// 7 ESTADOS EXACTOS
// ============================================
$estados = [
    'salida_ruta' => 'Salida Ruta',
    'retorno_ruta' => 'Retorno de ruta',
    'pluma_bodega' => 'Pluma en bodega',
    'inicio_liquidacion_bodega' => 'Inicio Liquidación en bodega',
    'fin_liquidacion_bodega' => 'Fin liquidación Bodega',
    'inicio_liquidacion_caja' => 'Inicio liquidación caja',
    'fin_liquidacion_caja' => 'Fin liquidación en caja'
];

// Array con números de estados (para la BD)
$estados_numeros = [
    'salida_ruta' => 1,
    'retorno_ruta' => 2,
    'pluma_bodega' => 3,
    'inicio_liquidacion_bodega' => 4,
    'fin_liquidacion_bodega' => 5,
    'inicio_liquidacion_caja' => 6,
    'fin_liquidacion_caja' => 7
];

// VERIFICACIÓN DE LOGIN
require_once 'conexion.php';

if (!isset($_SESSION['placa'])) {
    header('Location: login.php');
    exit;
}

$placa_conductor = $_SESSION['placa'];
$nombre_conductor = $_SESSION['nombre_conductor'] ?? 'Conductor';

// Obtener el ID de la plantilla del conductor
if (!isset($_SESSION['plantilla_conductor_id'])) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT id FROM DPL.externos.plantillas_conductores 
              WHERE placa = ? AND fecha_plantilla = ? AND activo = 1";
    $fecha_actual = date('Y-m-d');
    $stmt = sqlsrv_query($conn, $query, array($placa_conductor, $fecha_actual));
    
    if ($stmt !== false) {
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        if ($row) {
            $_SESSION['plantilla_conductor_id'] = $row['id'];
        }
        sqlsrv_free_stmt($stmt);
    }
}

$plantilla_conductor_id = $_SESSION['plantilla_conductor_id'] ?? 0;

// Función para obtener o crear viaje activo
function obtenerViajeActivo($conn, $placa, $plantilla_id, $nombre_conductor) {
    $fecha_actual = date('Y-m-d');
    
    // Buscar viaje activo (incluyendo completados para hoy)
    $query = "SELECT * FROM externos.viajes_tracking 
              WHERE placa = ? AND fecha_viaje = ? 
              ORDER BY id DESC";
    $stmt = sqlsrv_query($conn, $query, array($placa, $fecha_actual));
    
    if ($stmt !== false) {
        $viaje = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        sqlsrv_free_stmt($stmt);
        
        if ($viaje) {
            return $viaje;
        }
    }
    
    // Si no hay viaje, crear uno nuevo
    if ($plantilla_id > 0) {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $insertQuery = "INSERT INTO externos.viajes_tracking 
                        (plantilla_conductor_id, placa, nombre_conductor, fecha_viaje, 
                         fecha_inicio_viaje, estado_general, ip_address, user_agent) 
                        VALUES (?, ?, ?, ?, GETDATE(), 'en_progreso', ?, ?)";
        
        $params = array($plantilla_id, $placa, $nombre_conductor, $fecha_actual, $ip_address, $user_agent);
        $insertStmt = sqlsrv_query($conn, $insertQuery, $params);
        
        if ($insertStmt) {
            sqlsrv_free_stmt($insertStmt);
            
            // Obtener el ID insertado
            $idQuery = "SELECT SCOPE_IDENTITY() AS id";
            $idStmt = sqlsrv_query($conn, $idQuery);
            if ($idStmt !== false) {
                $idRow = sqlsrv_fetch_array($idStmt, SQLSRV_FETCH_ASSOC);
                sqlsrv_free_stmt($idStmt);
                
                // Devolver el viaje recién creado
                $selectQuery = "SELECT * FROM externos.viajes_tracking WHERE id = ?";
                $selectStmt = sqlsrv_query($conn, $selectQuery, array($idRow['id']));
                if ($selectStmt !== false) {
                    $nuevoViaje = sqlsrv_fetch_array($selectStmt, SQLSRV_FETCH_ASSOC);
                    sqlsrv_free_stmt($selectStmt);
                    return $nuevoViaje;
                }
            }
        }
    }
    
    return null;
}

// Función para obtener el último estado marcado
function obtenerUltimoEstadoMarcado($viaje) {
    for ($i = 7; $i >= 1; $i--) {
        $campo_fecha = 'estado' . $i . '_fecha';
        if ($viaje && isset($viaje[$campo_fecha]) && $viaje[$campo_fecha] !== null) {
            return [
                'numero' => $i,
                'key' => $viaje['estado' . $i . '_key'] ?? null,
                'nombre' => $viaje['estado' . $i . '_nombre'] ?? null,
                'fecha' => $viaje[$campo_fecha]
            ];
        }
    }
    return null;
}

// Función para registrar una marcación
function registrarMarcacion($conn, $viaje_id, $estado_numero, $estado_key, $estado_nombre, $latitud, $longitud, $precision_gps, $kilometraje = null) {
    // Log para debug
    error_log("=== INICIO registrarMarcacion ===");
    error_log("viaje_id: " . $viaje_id);
    error_log("estado_numero: " . $estado_numero);
    
    // Mapeo de campos según el número de estado
    $campos = [
        'estado' . $estado_numero . '_key' => $estado_key,
        'estado' . $estado_numero . '_nombre' => $estado_nombre,
        'estado' . $estado_numero . '_fecha' => 'GETDATE()',
        'estado' . $estado_numero . '_latitud' => $latitud,
        'estado' . $estado_numero . '_longitud' => $longitud,
        'estado' . $estado_numero . '_precision' => $precision_gps
    ];
    
    // Si es estado 1 o 2 y hay kilometraje, agregarlo
    if ($estado_numero <= 2 && $kilometraje !== null && $kilometraje !== '') {
        $campos['kilometraje' . $estado_numero] = $kilometraje;
        error_log("Agregando kilometraje" . $estado_numero . " = " . $kilometraje);
    }
    
    // Construir query dinámica
    $setParts = [];
    $params = [];
    
    foreach ($campos as $campo => $valor) {
        if ($valor === 'GETDATE()') {
            $setParts[] = "$campo = GETDATE()";
        } else {
            $setParts[] = "$campo = ?";
            $params[] = $valor;
        }
    }
    
    $params[] = $viaje_id; // Para el WHERE
    
    $query = "UPDATE externos.viajes_tracking 
              SET " . implode(', ', $setParts) . "
              WHERE id = ?";
    
    error_log("Query: " . $query);
    
    $stmt = sqlsrv_query($conn, $query, $params);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        error_log("Error SQL: " . print_r($errors, true));
        return false;
    }
    
    sqlsrv_free_stmt($stmt);
    
    // Si es el último estado, marcar viaje como completado
    if ($estado_numero == 7) {
        error_log("Es el último estado, marcando viaje como completado");
        
        $updateQuery = "UPDATE externos.viajes_tracking 
                       SET estado_general = 'completado', fecha_fin_viaje = GETDATE() 
                       WHERE id = ?";
        $updateStmt = sqlsrv_query($conn, $updateQuery, array($viaje_id));
        if ($updateStmt) {
            sqlsrv_free_stmt($updateStmt);
            error_log("Viaje marcado como completado en BD");
        }
    }
    
    error_log("=== FIN registrarMarcacion: OK ===");
    return true;
}

// Conectar a la BD
$database = new Database();
$conn = $database->getConnection();

// Verificar conexión
if ($conn === false) {
    die("Error de conexión a la base de datos");
}

// Obtener o crear viaje activo
$viaje_activo = obtenerViajeActivo($conn, $placa_conductor, $plantilla_conductor_id, $nombre_conductor);

// ============================================
// LÓGICA CORREGIDA PARA DETERMINAR ESTADO ACTUAL
// ============================================
$estado_actual_key = 'salida_ruta'; // Por defecto
$estado_actual_numero = 1;
$estados_marcados = [];
$viaje_completado = false;

if ($viaje_activo) {
    // Construir array de estados marcados
    for ($i = 1; $i <= 7; $i++) {
        $campo_fecha = 'estado' . $i . '_fecha';
        if (!empty($viaje_activo[$campo_fecha]) && $viaje_activo[$campo_fecha] !== null) {
            // Buscar la key correspondiente a este número
            $key = null;
            foreach ($estados_numeros as $k => $num) {
                if ($num == $i) {
                    $key = $k;
                    break;
                }
            }
            
            if ($key) {
                $estados_marcados[$key] = [
                    'numero' => $i,
                    'fecha' => $viaje_activo[$campo_fecha],
                    'latitud' => $viaje_activo['estado' . $i . '_latitud'] ?? null,
                    'longitud' => $viaje_activo['estado' . $i . '_longitud'] ?? null
                ];
            }
        }
    }
    
    // ===== LÓGICA PRINCIPAL CORREGIDA =====
    // Verificar PRIMERO si el viaje ya está completado por estado_general
    if ($viaje_activo['estado_general'] === 'completado') {
        $viaje_completado = true;
        $estado_actual_key = 'completado';
        $estado_actual_numero = 8;
        error_log("VIAJE COMPLETADO por estado_general");
    }
    // Verificar si el último estado (7) está marcado
    elseif (isset($estados_marcados['fin_liquidacion_caja'])) {
        $viaje_completado = true;
        $estado_actual_key = 'completado';
        $estado_actual_numero = 8;
        error_log("VIAJE COMPLETADO - Último estado marcado: fin_liquidacion_caja");
        
        // Asegurar que estado_general esté actualizado en BD
        if ($viaje_activo['estado_general'] !== 'completado') {
            $updateQuery = "UPDATE externos.viajes_tracking 
                           SET estado_general = 'completado', fecha_fin_viaje = GETDATE() 
                           WHERE id = ? AND estado_general != 'completado'";
            $updateStmt = sqlsrv_query($conn, $updateQuery, array($viaje_activo['id']));
            if ($updateStmt) {
                sqlsrv_free_stmt($updateStmt);
                error_log("Actualizado estado_general a completado");
            }
        }
    }
    else {
        // Buscar el primer estado no marcado (en orden)
        foreach ($estados_numeros as $key => $numero) {
            if (!isset($estados_marcados[$key])) {
                $estado_actual_key = $key;
                $estado_actual_numero = $numero;
                error_log("Estado actual encontrado: $key (número $numero)");
                break;
            }
        }
    }
    
    error_log("Estados marcados: " . count($estados_marcados) . "/7");
    error_log("Estado actual key: " . $estado_actual_key);
}

// PROCESAR MARCADOR (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['estado']) && $viaje_activo) {
    // HEADER para respuesta JSON
    header('Content-Type: application/json');
    
    try {
        error_log("=== POST RECIBIDO ===");
        error_log("POST data: " . print_r($_POST, true));
        
        $estado = $_POST['estado'];
        $estado_numero = $estados_numeros[$estado] ?? 0;
        
        if ($estado_numero > 0) {
            $latitud = isset($_POST['latitud']) ? $_POST['latitud'] : null;
            $longitud = isset($_POST['longitud']) ? $_POST['longitud'] : null;
            $precision_gps = isset($_POST['precision']) ? $_POST['precision'] : null;
            $kilometraje = isset($_POST['kilometraje']) ? $_POST['kilometraje'] : null;
            
            // Validar kilometraje para estados 1 y 2
            if ($estado_numero <= 2) {
                if (empty($kilometraje)) {
                    echo json_encode(array(
                        'success' => false, 
                        'error' => 'El kilometraje es requerido'
                    ));
                    exit;
                }
                if (!is_numeric($kilometraje)) {
                    echo json_encode(array(
                        'success' => false, 
                        'error' => 'El kilometraje debe ser un número'
                    ));
                    exit;
                }
                if ($kilometraje < 0) {
                    echo json_encode(array(
                        'success' => false, 
                        'error' => 'El kilometraje no puede ser negativo'
                    ));
                    exit;
                }
            }
            
            // Registrar marcación
            $resultado = registrarMarcacion(
                $conn,
                $viaje_activo['id'],
                $estado_numero,
                $estado,
                $estados[$estado],
                $latitud,
                $longitud,
                $precision_gps,
                $kilometraje
            );
            
            if ($resultado) {
                echo json_encode(array(
                    'success' => true,
                    'estado_marcado' => $estado_numero,
                    'viaje_completado' => ($estado_numero == 7)
                ));
            } else {
                echo json_encode(array(
                    'success' => false, 
                    'error' => 'Error en la base de datos'
                ));
            }
            exit;
        } else {
            echo json_encode(array(
                'success' => false, 
                'error' => 'Estado no válido'
            ));
            exit;
        }
    } catch (Exception $e) {
        error_log("EXCEPCIÓN: " . $e->getMessage());
        echo json_encode(array(
            'success' => false, 
            'error' => 'Error: ' . $e->getMessage()
        ));
        exit;
    }
}

// Contar cuántos estados están marcados
$marcados_count = count($estados_marcados);
$porcentaje = $marcados_count > 0 ? round(($marcados_count / 7) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
<title>RANSA - Rastreo</title>
<style>
:root{
    --verde:#009A3F;
    --naranja:#F39200;
    --gris:#9D9D9C;
    --rojo:#c62828;
    --fondo:#f6f7f8;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

html, body {
    height: 100%;
    width: 100%;
}

body{
    font-family: 'Segoe UI', sans-serif;
    background: var(--fondo);
    display:flex;
    justify-content:center;
    align-items:center;
    color:#333;
    height: 100vh;
    height: 100dvh;
    width: 100vw;
    width: 100dvw;
}

.container{
    width:100%;
    max-width:480px;
    height: 100vh;
    height: 100dvh;
    padding: 16px 18px;
    display:flex;
    flex-direction:column;
    gap: 14px;
}

.card{
    background:white;
    border-radius:28px;
    padding: 24px 24px;
    box-shadow:0 15px 30px rgba(0,0,0,.06);
    display:flex;
    flex-direction:column;
}

.card:first-child {
    flex: 1 1 auto;
    justify-content: space-evenly;
    padding: 20px 24px;
}

.card:last-child {
    flex-shrink:0;
    padding: 18px 24px;
}

h1{
    text-align:center;
    color:var(--verde);
    margin:0 0 5px 0;
    font-size:2rem;
    letter-spacing:1.5px;
    font-weight:700;
}

/* Indicador GPS */
.gps-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: 5px;
    margin-bottom: 5px;
    padding: 8px 12px;
    background: #f8f9fa;
    border-radius: 30px;
    font-size: 0.85rem;
}

.gps-active {
    color: var(--verde);
    font-weight: 600;
}

.gps-inactive {
    color: var(--rojo);
    font-weight: 600;
}

.gps-pulse {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}

.gps-pulse-active {
    background: var(--verde);
    box-shadow: 0 0 0 0 rgba(0, 154, 63, 0.5);
    animation: pulse 1.5s infinite;
}

.gps-pulse-inactive {
    background: var(--rojo);
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(0, 154, 63, 0.5); }
    70% { box-shadow: 0 0 0 8px rgba(0, 154, 63, 0); }
    100% { box-shadow: 0 0 0 0 rgba(0, 154, 63, 0); }
}

/* Info del conductor */
.conductor-info {
    text-align: center;
    background: #f0f8ff;
    padding: 12px;
    border-radius: 50px;
    margin: 8px 0;
    border: 1px solid #d4edda;
}

.placa-badge {
    background: var(--verde);
    color: white;
    padding: 8px 16px;
    border-radius: 50px;
    font-weight: bold;
    font-size: 1.3rem;
    letter-spacing: 2px;
    display: inline-block;
    margin-top: 5px;
    box-shadow: 0 4px 0 #006e2c;
}

.nombre-conductor {
    color: #666;
    font-size: 0.9rem;
    margin-bottom: 5px;
}

.viaje-id {
    font-size: 0.7rem;
    color: #999;
    margin-top: 5px;
}

/* Círculo de progreso */
.circle-wrapper {
    display:flex;
    justify-content:center;
    align-items:center;
    margin: 15px 0;
}

.circle{
    width: min(170px, 38vw);
    height: min(170px, 38vw);
    max-width: 180px;
    max-height: 180px;
    border-radius:50%;
    background: conic-gradient(var(--verde) 0deg <?= $porcentaje * 3.6 ?>deg, #e5e5e5 <?= $porcentaje * 3.6 ?>deg 360deg);
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    transition: background 0.3s ease;
}

.circle::before {
    content: "";
    position: absolute;
    width: 66%;
    height: 66%;
    border-radius: 50%;
    background: white;
}

.circle .main{
    position:relative;
    font-size: clamp(1.8rem, 7vw, 2.2rem);
    font-weight:700;
    color:#666;
    line-height:1;
    z-index:2;
}

/* Timeline */
.timeline{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin: 15px 0 10px;
}

.step{
    width: clamp(14px, 3.5vw, 18px);
    height: clamp(14px, 3.5vw, 18px);
    border-radius:50%;
    background:var(--gris);
    flex-shrink:0;
    transition: all 0.2s;
}

.step.active{
    background:var(--naranja);
    box-shadow:0 0 0 clamp(5px, 1.5vw, 8px) rgba(243,146,0,.18);
    transform:scale(1.15);
}

.step.done{
    background:var(--verde);
}

.line{
    flex:1;
    height: clamp(4px, 1.2vw, 6px);
    background:var(--gris);
    margin:0 3px;
    border-radius:4px;
}

.line.done{
    background:var(--verde);
}

/* Estado actual */
.estado{
    text-align:center;
    color:#555;
    margin-top: 10px;
    padding-top: 15px;
    border-top:1.5px solid #f0f0f0;
}

.estado strong{
    display:block;
    margin-top: 4px;
    color:var(--naranja);
    font-size: clamp(1.1rem, 4vw, 1.3rem);
    font-weight:700;
}

/* Modal para kilometraje */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
}

.modal-content {
    background-color: white;
    margin: auto;
    padding: 25px;
    border-radius: 28px;
    width: 90%;
    max-width: 380px;
    box-shadow: 0 20px 40px rgba(0,0,0,0.2);
    animation: slideUp 0.3s ease;
}

@keyframes slideUp {
    from {
        transform: translateY(50px);
        opacity: 0;
    }
    to {
        transform: translateY(0);
        opacity: 1;
    }
}

.modal h3 {
    color: var(--verde);
    margin-bottom: 20px;
    font-size: 1.5rem;
    text-align: center;
}

.modal p {
    text-align: center;
    margin-bottom: 20px;
    color: #666;
    font-size: 1.1rem;
}

.modal input {
    width: 100%;
    padding: 15px;
    border: 2px solid #ddd;
    border-radius: 15px;
    font-size: 1.2rem;
    margin-bottom: 20px;
    text-align: center;
    font-weight: bold;
}

.modal input:focus {
    outline: none;
    border-color: var(--verde);
}

.modal-buttons {
    display: flex;
    gap: 10px;
}

.modal-btn {
    flex: 1;
    padding: 15px;
    border: none;
    border-radius: 15px;
    font-size: 1.1rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-btn-primary {
    background: var(--verde);
    color: white;
}

.modal-btn-primary:hover {
    background: #007a32;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(0,122,50,0.3);
}

.modal-btn-secondary {
    background: #f0f0f0;
    color: #666;
}

.modal-btn-secondary:hover {
    background: #e0e0e0;
}

/* Botones */
.btn-marcar {
    width:100%;
    padding: clamp(14px, 3vh, 20px);
    border:none;
    border-radius:22px;
    font-size: clamp(1.1rem, 4vw, 1.3rem);
    font-weight:800;
    background: var(--verde);
    color:white;
    cursor:pointer;
    transition:.2s;
    letter-spacing:1px;
    box-shadow:0 4px 0 #006e2c;
    border:1px solid rgba(255,255,255,0.2);
    margin-bottom: 10px;
}

.btn-marcar:hover:not(:disabled){
    background:#007a32;
    transform:translateY(-3px);
    box-shadow:0 7px 0 #006e2c, 0 15px 25px rgba(0,122,50,.25);
}

.btn-marcar:active:not(:disabled) {
    transform:translateY(4px);
    box-shadow:0 2px 0 #006e2c;
}

.btn-marcar:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
    box-shadow: 0 4px 0 #006e2c;
    background: #ccc;
}

.btn-reinicio {
    width:100%;
    padding: clamp(14px, 3vh, 20px);
    border:none;
    border-radius:22px;
    font-size: clamp(1.1rem, 4vw, 1.3rem);
    font-weight:800;
    background: var(--naranja);
    color:white;
    cursor:pointer;
    transition:.2s;
    letter-spacing:1px;
    box-shadow:0 4px 0 #c07100;
    border:1px solid rgba(255,255,255,0.2);
    margin-bottom: 10px;
}

.btn-reinicio:hover {
    background: #e08500;
    transform:translateY(-3px);
    box-shadow:0 7px 0 #c07100, 0 15px 25px rgba(224,133,0,.25);
}

.logout-btn {
    width:100%;
    background: #f0f0f0;
    border: none;
    color: #666;
    font-size: 0.9rem;
    cursor: pointer;
    padding: 12px 16px;
    border-radius: 20px;
    margin-top: 5px;
    transition: all 0.2s;
    box-shadow: none;
    font-weight: normal;
}

.logout-btn:hover {
    background: #ffebee;
    color: var(--rojo);
    transform: none;
    box-shadow: none;
}

.completado-badge {
    background: var(--verde);
    color: white;
    text-align: center;
    padding: 15px;
    border-radius: 50px;
    font-weight: bold;
    margin-bottom: 15px;
    font-size: 1.1rem;
}

/* Mensaje de completado */
.viaje-completado-mensaje {
    text-align: center;
    padding: 20px;
    background: #e8f5e9;
    border-radius: 15px;
    color: var(--verde);
    font-weight: bold;
    font-size: 1.2rem;
    margin: 10px 0;
}

/* Animaciones */
@keyframes pop{
    from{transform:scale(.9); opacity:.7}
    to{transform:scale(1); opacity:1}
}
</style>
</head>
<body>

<!-- MODAL PARA INGRESAR KILOMETRAJE -->
<div id="kilometrajeModal" class="modal">
    <div class="modal-content">
        <h3>📊 Ingresar Kilometraje</h3>
        <p id="modalEstadoTexto">Para Salida Ruta</p>
        <input type="number" id="kilometrajeInput" placeholder="Ej: 12500" min="0" step="1">
        <div class="modal-buttons">
            <button class="modal-btn modal-btn-secondary" onclick="cerrarModal()">Cancelar</button>
            <button class="modal-btn modal-btn-primary" onclick="confirmarKilometraje()">Confirmar</button>
        </div>
    </div>
</div>

<div class="container">

<div class="card">
    <div>
        <h1>RANSA</h1>
        
        <div id="gpsIndicator" class="gps-indicator">
            <span class="gps-pulse gps-pulse-inactive"></span>
            <span class="gps-inactive">📍 GPS: Obteniendo ubicación...</span>
        </div>
        
        <!-- INFO DEL CONDUCTOR Y PLACA -->
        <div class="conductor-info">
            <div class="nombre-conductor">👤 <?= htmlspecialchars($nombre_conductor) ?></div>
            <div class="placa-badge">🚛 <?= htmlspecialchars($placa_conductor) ?></div>
            <?php if ($viaje_activo): ?>
            <div class="viaje-id">Viaje #<?= $viaje_activo['id'] ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="circle-wrapper">
        <div id="circleProgress" class="circle">
            <div id="percentText" class="main"><?= $porcentaje ?>%</div>
        </div>
    </div>

    <div>
        <div id="timelineContainer" class="timeline">
            <?php
            // Timeline generado desde PHP
            for ($i = 0; $i < 7; $i++) {
                $key = array_keys($estados)[$i];
                $isDone = isset($estados_marcados[$key]);
                $isActive = ($key === $estado_actual_key && $estado_actual_key !== 'completado');
                
                $stepClass = 'step';
                if ($isDone) $stepClass .= ' done';
                if ($isActive) $stepClass .= ' active';
                
                echo '<div class="' . $stepClass . '"></div>';
                
                if ($i < 6) {
                    $lineClass = 'line';
                    if ($isDone) $lineClass .= ' done';
                    echo '<div class="' . $lineClass . '"></div>';
                }
            }
            ?>
        </div>
        <div id="estadoContainer" class="estado">
            <?php if ($viaje_completado || $estado_actual_key === 'completado'): ?>
            <div class="viaje-completado-mensaje">
                ✅ ¡VIAJE COMPLETADO!<br>
                <span style="font-size:0.9rem;">Has marcado todos los 7 estados</span>
            </div>
            <?php else: ?>
            <div class="estado">
                Estado actual:
                <strong><?= $estados[$estado_actual_key] ?? $estado_actual_key ?></strong>
                <div style="font-size:0.8rem; color:#888; margin-top:5px;">
                    <?= $marcados_count ?>/7 estados completados
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <!-- BOTONES PRINCIPALES -->
    <div id="botonesContainer">
        <?php if ($viaje_completado || $estado_actual_key === 'completado'): ?>
        <button class="btn-marcar" disabled>
            ✅ VIAJE COMPLETADO
        </button>
        <button onclick="reiniciarViaje()" class="btn-reinicio">
            🔄 NUEVO VIAJE
        </button>
        <?php else: ?>
        <button onclick="marcarEstado()" class="btn-marcar" id="btnMarcar">
            MARCAR: <?= $estados[$estado_actual_key] ?? $estado_actual_key ?>
        </button>
        <?php endif; ?>
    </div>
    
    <!-- BOTÓN DE CERRAR SESIÓN -->
    <button onclick="cerrarSesion()" class="logout-btn">Cerrar sesión</button>
</div>

</div>

<script>
// ============================================
// CONFIGURACIÓN INICIAL
// ============================================
var estadosDisponibles = <?= json_encode($estados) ?>;
var keysEstados = Object.keys(estadosDisponibles);
var TOTAL_ESTADOS = keysEstados.length;

var placaConductor = '<?= $placa_conductor ?>';
var nombreConductor = '<?= $nombre_conductor ?>';
var viajeId = <?= $viaje_activo ? $viaje_activo['id'] : 'null' ?>;
var estadoActualKey = '<?= $estado_actual_key ?>';
var viajeCompletado = <?= ($viaje_completado ? 'true' : 'false') ?>;

// Estados marcados desde PHP
var estadosMarcados = <?= json_encode($estados_marcados) ?>;

// Variable para controlar si estamos esperando kilometraje
var esperandoKilometraje = false;
var estadoPendiente = null;

// ============================================
// GPS
// ============================================
var gpsActivo = false;
var latitud = null;
var longitud = null;
var precision = null;

function obtenerGPS() {
    var indicator = document.getElementById('gpsIndicator');
    if (!indicator) return;
    
    if (!navigator.geolocation) {
        indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">📍 GPS: No soportado</span>';
        return;
    }
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            latitud = position.coords.latitude;
            longitud = position.coords.longitude;
            precision = position.coords.accuracy;
            gpsActivo = true;
            indicator.innerHTML = '<span class="gps-pulse gps-pulse-active"></span><span class="gps-active">📍 GPS ACTIVO</span>';
            console.log('GPS activo:', latitud, longitud);
        },
        function(error) {
            gpsActivo = false;
            var mensaje = '📍 GPS: ';
            switch(error.code) {
                case error.PERMISSION_DENIED: mensaje += 'Permiso denegado'; break;
                case error.POSITION_UNAVAILABLE: mensaje += 'No disponible'; break;
                case error.TIMEOUT: mensaje += 'Tiempo agotado'; break;
                default: mensaje += 'Error';
            }
            indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">' + mensaje + '</span>';
            console.error('GPS error:', error);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

// ============================================
// MODAL PARA KILOMETRAJE
// ============================================
function abrirModal(estado) {
    console.log('abrirModal() llamado con estado:', estado);
    estadoPendiente = estado;
    
    var modal = document.getElementById('kilometrajeModal');
    var texto = document.getElementById('modalEstadoTexto');
    var input = document.getElementById('kilometrajeInput');
    
    texto.innerHTML = 'Para: <strong>' + estadosDisponibles[estado] + '</strong>';
    input.value = '';
    input.focus();
    
    modal.style.display = 'flex';
    esperandoKilometraje = true;
}

function cerrarModal() {
    console.log('cerrarModal() llamado');
    var modal = document.getElementById('kilometrajeModal');
    modal.style.display = 'none';
    esperandoKilometraje = false;
    
    // Habilitar botón nuevamente
    var btn = document.getElementById('btnMarcar');
    if (btn) btn.disabled = false;
}

function confirmarKilometraje() {
    console.log('confirmarKilometraje() llamado');
    
    var input = document.getElementById('kilometrajeInput');
    var kilometraje = input.value.trim();
    
    if (!kilometraje || isNaN(kilometraje) || kilometraje < 0) {
        alert('Por favor ingresa un kilometraje válido');
        return;
    }
    
    var estadoActual = estadoPendiente;
    console.log('Estado a marcar:', estadoActual);
    
    cerrarModal();
    enviarMarcacion(estadoActual, kilometraje);
}

// ============================================
// MARCAR ESTADO
// ============================================
function marcarEstado() {
    console.log('marcarEstado() llamado');
    console.log('estadoActualKey:', estadoActualKey);
    
    if (viajeCompletado || estadoActualKey === 'completado') {
        alert('✅ Este viaje ya está completado');
        return;
    }
    
    var btn = document.getElementById('btnMarcar');
    btn.disabled = true;
    
    // Verificar si es estado 1 o 2 (requieren kilometraje)
    if (estadoActualKey === 'salida_ruta' || estadoActualKey === 'retorno_ruta') {
        abrirModal(estadoActualKey);
        return;
    }
    
    enviarMarcacion(estadoActualKey, null);
}

function enviarMarcacion(estado, kilometraje) {
    console.log('enviarMarcacion() llamado');
    console.log('estado:', estado);
    console.log('kilometraje:', kilometraje);
    
    if (!estado) {
        console.error('Error: estado es null');
        alert('Error: No se pudo determinar el estado');
        var btn = document.getElementById('btnMarcar');
        if (btn) btn.disabled = false;
        return;
    }
    
    var formData = new URLSearchParams();
    formData.append('estado', estado);
    
    if (latitud && longitud) {
        formData.append('latitud', latitud);
        formData.append('longitud', longitud);
        formData.append('precision', precision || '');
    }
    
    if (kilometraje) {
        formData.append('kilometraje', kilometraje);
    }
    
    fetch('', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData.toString()
    })
    .then(response => response.json())
    .then(data => {
        console.log('Respuesta:', data);
        
        if (data.success) {
            if (navigator.vibrate) navigator.vibrate(60);
            
            if (data.viaje_completado) {
                alert('✅ ¡Viaje completado exitosamente!');
                // Recargar para mostrar estado completado
                location.reload();
            } else {
                // Recargar para actualizar la interfaz
                location.reload();
            }
        } else {
            alert('Error: ' + (data.error || 'Error desconocido'));
            var btn = document.getElementById('btnMarcar');
            if (btn) btn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error de conexión');
        var btn = document.getElementById('btnMarcar');
        if (btn) btn.disabled = false;
    });
}

// ============================================
// REINICIAR VIAJE
// ============================================
function reiniciarViaje() {
    if (confirm('¿Iniciar un nuevo viaje?')) {
        window.location.href = 'dashboard.php?nuevo=1';
    }
}

// ============================================
// CERRAR SESIÓN
// ============================================
function cerrarSesion() {
    if (confirm('¿Cerrar sesión?')) {
        window.location.href = 'logout.php';
    }
}

// ============================================
// INICIALIZACIÓN
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Iniciando dashboard');
    console.log('Viaje completado:', viajeCompletado);
    console.log('Estado actual:', estadoActualKey);
    
    obtenerGPS();
    
    setInterval(function() {
        if (!gpsActivo) obtenerGPS();
    }, 30000);
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && esperandoKilometraje) {
            cerrarModal();
        }
    });
    
    // Si hay parámetro nuevo, redirigir
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('nuevo')) {
        window.location.href = 'dashboard.php';
    }
});

// Ajuste de altura para móviles
function ajustarAltura() {
    var vh = window.innerHeight;
    document.documentElement.style.setProperty('--vh', vh + 'px');
    var container = document.querySelector('.container');
    if (container) container.style.height = vh + 'px';
}

window.addEventListener('load', ajustarAltura);
window.addEventListener('resize', ajustarAltura);
window.addEventListener('orientationchange', function() { setTimeout(ajustarAltura, 100); });
</script>

</body>
</html>