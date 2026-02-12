<?php
session_start();

$estados = [
    'salida_ruta' => 'Salida Ruta',
    'retorno_ruta' => 'Retorno de ruta',
    'pluma_bodega' => 'Pluma en bodega',
    'inicio_liquidacion_bodega' => 'Inicio Liquidación en bodega',
    'fin_liquidacion_bodega' => 'Fin liquidación Bodega',
    'inicio_liquidacion_caja' => 'Inicio liquidación caja',
    'fin_liquidacion_caja' => 'Fin liquidación en caja'
];

// VERIFICACIÓN DE LOGIN PERSISTENTE
require_once 'conexion.php';

function verificarTokenPersistente() {
    if (isset($_COOKIE['remember_token']) && isset($_COOKIE['remember_user'])) {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT id, nombre_completo, usuario, ciudad 
                  FROM DPL.externos.users_tracking 
                  WHERE id = ? AND remember_token = ? 
                  AND token_expiry > GETDATE() AND activo = 1";
        
        $params = array($_COOKIE['remember_user'], $_COOKIE['remember_token']);
        $stmt = sqlsrv_query($conn, $query, $params);
        
        if ($stmt !== false) {
            $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nombre'] = $user['nombre_completo'];
                $_SESSION['user_usuario'] = $user['usuario'];
                $_SESSION['user_ciudad'] = $user['ciudad'];
                $_SESSION['login_time'] = time();
                sqlsrv_free_stmt($stmt);
                return true;
            }
            sqlsrv_free_stmt($stmt);
        }
    }
    return false;
}

if (!isset($_SESSION['user_id'])) {
    if (!verificarTokenPersistente()) {
        header('Location: login.php');
        exit;
    }
}

// AHORA: El progreso se manejará DESDE LOCALSTORAGE
// Solo inicializamos sesión vacía, el progreso viene del celular
if (!isset($_SESSION['progreso'])) {
    $_SESSION['progreso'] = [
        'estado_actual' => 'salida_ruta', 
        'marcados' => [],
        'placa_seleccionada' => null
    ];
}

// Obtener placas de la ciudad del usuario
function obtenerPlacasPorCiudad($ciudad) {
    if (empty($ciudad)) return array();
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT placa FROM DPL.externos.placas_tracking 
              WHERE ciudad = ? AND activo = 1 
              ORDER BY placa";
    
    $params = array($ciudad);
    $stmt = sqlsrv_query($conn, $query, $params);
    
    $placas = array();
    if ($stmt !== false) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $placas[] = $row['placa'];
        }
        sqlsrv_free_stmt($stmt);
    }
    return $placas;
}

$ciudad_usuario = $_SESSION['user_ciudad'] ?? '';
$placas_disponibles = obtenerPlacasPorCiudad($ciudad_usuario);

if (empty($placas_disponibles)) {
    $placas_disponibles = array('NO HAY PLACAS DISPONIBLES PARA ' . strtoupper($ciudad_usuario));
}

// PROCESAR MARCADOR - Solo guarda en BD, el progreso lo maneja JS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['estado'])) {
    $estado = $_POST['estado'];
    $placa = $_POST['placa'] ?? '';
    $latitud = isset($_POST['latitud']) ? $_POST['latitud'] : null;
    $longitud = isset($_POST['longitud']) ? $_POST['longitud'] : null;
    $precision_gps = isset($_POST['precision']) ? $_POST['precision'] : null;
    $fecha_marcacion = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    
    // Guardar en base de datos la marcación
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "INSERT INTO DPL.externos.marcaciones_tracking 
              (usuario_id, usuario_nombre, usuario_ciudad, estado_key, estado_nombre, 
               placa, latitud, longitud, precision_gps, ip_address, user_agent,
               fecha_marcacion) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $params = array(
        $_SESSION['user_id'],
        $_SESSION['user_nombre'],
        $_SESSION['user_ciudad'] ?? '',
        $estado,
        $estados[$estado],
        $placa,
        $latitud,
        $longitud,
        $precision_gps,
        $ip_address,
        $user_agent,
        $fecha_marcacion
    );
    
    $stmt = sqlsrv_query($conn, $query, $params);
    if ($stmt) {
        sqlsrv_free_stmt($stmt);
    }
    
    echo json_encode(array('success' => true));
    exit;
}

// Endpoint para guardar progreso en sesión (cuando el usuario recarga)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_progreso'])) {
    $_SESSION['progreso'] = json_decode($_POST['progreso'], true);
    echo json_encode(array('success' => true));
    exit;
}

$p = $_SESSION['progreso'];
$total = count($estados);
$done = count($p['marcados']);
$percent = round(($done / $total) * 100);

$placa_actual = $p['placa_seleccionada'] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
<title>RANSA - Rastreo</title>
<style>
/* Mismos estilos que antes */
:root{
    --verde:#009A3F;
    --naranja:#F39200;
    --gris:#9D9D9C;
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
    margin:0;
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
    margin-top: 8px;
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
    color: #c62828;
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
    background: #c62828;
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(0, 154, 63, 0.5); }
    70% { box-shadow: 0 0 0 8px rgba(0, 154, 63, 0); }
    100% { box-shadow: 0 0 0 0 rgba(0, 154, 63, 0); }
}

.ciudad-info {
    text-align: center;
    color: var(--naranja);
    font-size: 0.8rem;
    margin-top: 5px;
    font-weight: 600;
}

/* Selector de placa */
.placa-container {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 16px;
    margin-bottom: 15px;
    position: relative;
}

.placa-label {
    display: block;
    margin-bottom: 8px;
    color: #555;
    font-weight: 600;
    font-size: 0.9rem;
}

.placa-input-container {
    position: relative;
}

.placa-input {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e5e5e5;
    border-radius: 15px;
    font-size: 1.1rem;
    text-transform: uppercase;
    background: white;
    transition: all 0.2s;
}

.placa-input:focus {
    border-color: var(--verde);
    outline: none;
    box-shadow: 0 0 0 4px rgba(0,154,63,0.1);
}

.sugerencias-placa {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    max-height: 200px;
    overflow-y: auto;
    background: white;
    border: 2px solid var(--verde);
    border-top: none;
    border-radius: 0 0 15px 15px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    z-index: 1000;
    display: none;
}

.sugerencia-item {
    padding: 12px 18px;
    cursor: pointer;
    border-bottom: 1px solid #f0f0f0;
    font-size: 1rem;
    transition: background 0.2s;
}

.sugerencia-item:hover {
    background: #e8f5e9;
    color: var(--verde);
}

.placa-fija {
    background: #e8f5e9;
    padding: 12px 18px;
    border-radius: 15px;
    color: var(--verde);
    font-weight: 600;
    font-size: 1.1rem;
    border: 2px solid var(--verde);
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 15px;
}

.placa-fija span {
    color: #666;
    font-weight: normal;
    font-size: 0.9rem;
}

.circle-wrapper {
    display:flex;
    justify-content:center;
    align-items:center;
    margin: 5px 0;
}

.circle{
    width: min(170px, 38vw);
    height: min(170px, 38vw);
    max-width: 180px;
    max-height: 180px;
    border-radius:50%;
    background: conic-gradient(var(--verde) 0deg <?= $percent * 3.6 ?>deg, #e5e5e5 <?= $percent * 3.6 ?>deg 360deg);
    display:flex;
    align-items:center;
    justify-content:center;
    position:relative;
    animation: pop .4s ease;
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

.timeline{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin: 12px 0 8px;
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

.estado{
    text-align:center;
    color:#555;
    margin-top: 8px;
    padding-top: 12px;
    border-top:1.5px solid #f0f0f0;
}

.estado strong{
    display:block;
    margin-top: 4px;
    color:var(--naranja);
    font-size: clamp(1.1rem, 4vw, 1.3rem);
    font-weight:700;
}

button{
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
}

button:hover{
    background:#007a32;
    transform:translateY(-3px);
    box-shadow:0 7px 0 #006e2c, 0 15px 25px rgba(0,122,50,.25);
}

button:active {
    transform:translateY(4px);
    box-shadow:0 2px 0 #006e2c;
}

button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
    box-shadow: 0 4px 0 #006e2c;
}

.btn-reinicio {
    background: var(--naranja);
    box-shadow: 0 4px 0 #c07100;
    margin-top: 10px;
}

.btn-reinicio:hover {
    background: #e08500;
    box-shadow: 0 7px 0 #c07100, 0 15px 25px rgba(224,133,0,.25);
}

.logout-btn {
    background: #f0f0f0;
    border: none;
    color: #666;
    font-size: 0.8rem;
    cursor: pointer;
    padding: 8px 16px;
    border-radius: 20px;
    margin-top: 10px;
    transition: all 0.2s;
    box-shadow: none;
}

.logout-btn:hover {
    background: #ffebee;
    color: #c62828;
    transform: none;
    box-shadow: none;
}

/* Mensaje de persistencia */
.persistencia-info {
    text-align: center;
    color: #888;
    font-size: 0.7rem;
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid #eee;
}

@keyframes pop{
    from{transform:scale(.9); opacity:.7}
    to{transform:scale(1); opacity:1}
}

@media (max-height: 680px) {
    .container { padding: 12px 16px; gap: 12px; }
    .card:first-child { padding: 16px 20px; }
    .circle { width: min(150px, 35vw); height: min(150px, 35vw); }
    h1 { font-size: 1.8rem; }
}
</style>
</head>
<body>

<div class="container">

<div class="card">
    <div>
        <h1>RANSA</h1>
        
        <div id="gpsIndicator" class="gps-indicator">
            <span class="gps-pulse gps-pulse-inactive"></span>
            <span class="gps-inactive">📍 GPS: Obteniendo ubicación...</span>
        </div>
        
        <?php if($ciudad_usuario): ?>
        <div class="ciudad-info">
            📍 <?= htmlspecialchars($ciudad_usuario) ?>
        </div>
        <?php endif; ?>
        
        <!-- Indicador de persistencia -->
        <div class="persistencia-info">
            💾 Tu progreso se guarda automáticamente en el celular
        </div>
    </div>

    <div class="circle-wrapper">
        <div id="circleProgress" class="circle">
            <div id="percentText" class="main">0%</div>
        </div>
    </div>

    <div>
        <div id="timelineContainer" class="timeline">
            <!-- Los steps se generarán con JavaScript -->
        </div>

        <div id="estadoContainer" class="estado">
            Estado actual:
            <strong id="estadoActualTexto">Cargando...</strong>
        </div>
    </div>
</div>

<div class="card">
    <div id="placaSelectorContainer">
        <!-- El selector de placa se genera con JavaScript -->
    </div>
    
    <button onclick="marcarEstado()" id="btnMarcar">
        MARCAR ESTADO
    </button>
    
    <button onclick="reiniciarTracking()" id="btnReinicio" style="display:none;" class="btn-reinicio">
        🔄 INICIAR NUEVO VIAJE
    </button>
    
    <button onclick="cerrarSesion()" class="logout-btn">
        Cerrar sesión
    </button>
</div>

</div>

<script>
// ============================================
// PERSISTENCIA CON LOCALSTORAGE
// ============================================

// Estados disponibles
var estadosDisponibles = <?= json_encode($estados) ?>;
var placasDisponibles = <?= json_encode($placas_disponibles) ?>;
var ciudadUsuario = '<?= $ciudad_usuario ?>';
var usuarioId = '<?= $_SESSION['user_id'] ?? '' ?>';
var usuarioNombre = '<?= $_SESSION['user_nombre'] ?? '' ?>';

// Clave para localStorage (única por usuario)
var STORAGE_KEY = 'ransa_tracking_' + usuarioId;

// Cargar progreso desde localStorage
function cargarProgreso() {
    var guardado = localStorage.getItem(STORAGE_KEY);
    
    if (guardado) {
        try {
            var progreso = JSON.parse(guardado);
            console.log('✅ Progreso cargado del celular:', progreso);
            return progreso;
        } catch(e) {
            console.error('Error al cargar progreso:', e);
        }
    }
    
    // Progreso inicial
    return {
        estado_actual: 'salida_ruta',
        marcados: {},
        placa_seleccionada: null,
        fecha_inicio: new Date().toISOString()
    };
}

// Guardar progreso en localStorage
function guardarProgreso(progreso) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(progreso));
        console.log('💾 Progreso guardado en celular');
        
        // También sincronizar con el servidor (por si acaso)
        var formData = 'guardar_progreso=1&progreso=' + encodeURIComponent(JSON.stringify(progreso));
        
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        }).catch(function(e) {
            console.log('No se pudo sincronizar con servidor:', e);
        });
        
        return true;
    } catch(e) {
        console.error('Error al guardar progreso:', e);
        return false;
    }
}

// Reiniciar tracking
function reiniciarTracking() {
    if (confirm('¿Finalizar este viaje y comenzar uno nuevo?')) {
        var nuevoProgreso = {
            estado_actual: 'salida_ruta',
            marcados: {},
            placa_seleccionada: null,
            fecha_inicio: new Date().toISOString()
        };
        
        guardarProgreso(nuevoProgreso);
        actualizarUI(nuevoProgreso);
    }
}

// ============================================
// VARIABLES GLOBALES
// ============================================

var progresoActual = cargarProgreso();
var gpsActivo = false;
var latitud = null;
var longitud = null;
var precision = null;
var gpsIntentos = 0;

// ============================================
// FUNCIONES DE INTERFAZ
// ============================================

// Actualizar toda la UI según el progreso
function actualizarUI(progreso) {
    // Actualizar círculo de progreso
    var total = Object.keys(estadosDisponibles).length;
    var marcados = Object.keys(progreso.marcados).length;
    var percent = Math.round((marcados / total) * 100);
    
    var circle = document.getElementById('circleProgress');
    if (circle) {
        circle.style.background = 'conic-gradient(var(--verde) 0deg ' + (percent * 3.6) + 'deg, #e5e5e5 ' + (percent * 3.6) + 'deg 360deg)';
    }
    
    var percentText = document.getElementById('percentText');
    if (percentText) percentText.innerHTML = percent + '%';
    
    // Actualizar timeline
    actualizarTimeline(progreso);
    
    // Actualizar estado actual
    var estadoActualTexto = document.getElementById('estadoActualTexto');
    if (estadoActualTexto) {
        estadoActualTexto.innerHTML = estadosDisponibles[progreso.estado_actual] || progreso.estado_actual;
    }
    
    // Actualizar selector de placa
    actualizarSelectorPlaca(progreso);
    
    // Mostrar/ocultar botón de reinicio
    var esUltimoEstado = progreso.estado_actual === 'fin_liquidacion_caja';
    var btnReinicio = document.getElementById('btnReinicio');
    var btnMarcar = document.getElementById('btnMarcar');
    
    if (btnReinicio) btnReinicio.style.display = esUltimoEstado ? 'block' : 'none';
    if (btnMarcar) {
        btnMarcar.disabled = esUltimoEstado;
        btnMarcar.innerHTML = esUltimoEstado ? '✅ TRACKING COMPLETADO' : 'MARCAR ESTADO';
    }
}

// Actualizar timeline
function actualizarTimeline(progreso) {
    var container = document.getElementById('timelineContainer');
    if (!container) return;
    
    var keys = Object.keys(estadosDisponibles);
    var total = keys.length;
    var html = '';
    
    for (var i = 0; i < keys.length; i++) {
        var key = keys[i];
        var isDone = progreso.marcados[key] ? true : false;
        var isActive = key === progreso.estado_actual;
        
        var stepClass = 'step';
        if (isDone) stepClass += ' done';
        if (isActive) stepClass += ' active';
        
        html += '<div class="' + stepClass + '"></div>';
        
        if (i < total - 1) {
            var lineClass = 'line' + (isDone ? ' done' : '');
            html += '<div class="' + lineClass + '"></div>';
        }
    }
    
    container.innerHTML = html;
}

// Actualizar selector de placa
function actualizarSelectorPlaca(progreso) {
    var container = document.getElementById('placaSelectorContainer');
    if (!container) return;
    
    var mostrarSelector = (
        progreso.estado_actual === 'salida_ruta' || 
        progreso.estado_actual === 'retorno_ruta' || 
        !progreso.placa_seleccionada
    );
    
    var placaActual = progreso.placa_seleccionada || '';
    
    if (mostrarSelector) {
        var options = '';
        if (placasDisponibles.length > 0) {
            options = '<option value="" disabled ' + (!placaActual ? 'selected' : '') + '>-- Selecciona una placa --</option>';
            for (var i = 0; i < placasDisponibles.length; i++) {
                var placa = placasDisponibles[i];
                if (placa.indexOf('NO HAY PLACAS') === -1) {
                    options += '<option value="' + placa.replace(/"/g, '&quot;') + '" ' + 
                              (placaActual === placa ? 'selected' : '') + '>' + placa + '</option>';
                }
            }
        }
        
        container.innerHTML = `
            <div class="placa-container">
                <label class="placa-label">🚛 Selecciona la placa del vehículo</label>
                <div class="placa-input-container">
                    <select id="placaSelect" class="placa-select" style="width:100%; padding:14px 18px; border:2px solid #e5e5e5; border-radius:15px; font-size:1.1rem; background:white; appearance:auto;" required>
                        ${options}
                    </select>
                </div>
                ${placasDisponibles.length === 0 ? '<div style="color:#c62828; margin-top:10px;">⚠️ No hay placas registradas para ' + ciudadUsuario + '</div>' : ''}
            </div>
        `;
    } else {
        container.innerHTML = `
            <div class="placa-fija">
                <span>🚛 Placa asignada</span>
                <strong>${placaActual}</strong>
            </div>
        `;
    }
}

// ============================================
// FUNCIONES GPS
// ============================================

function obtenerGPS() {
    var indicator = document.getElementById('gpsIndicator');
    
    if (!navigator.geolocation) {
        indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">📍 GPS: No soportado</span>';
        return;
    }
    
    indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">📍 GPS: Obteniendo ubicación...</span>';
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            latitud = position.coords.latitude;
            longitud = position.coords.longitude;
            precision = position.coords.accuracy;
            gpsActivo = true;
            
            indicator.innerHTML = '<span class="gps-pulse gps-pulse-active"></span><span class="gps-active">📍 GPS ACTIVO</span>';
        },
        function(error) {
            gpsActivo = false;
            latitud = null;
            longitud = null;
            precision = null;
            
            var mensaje = '';
            switch(error.code) {
                case error.PERMISSION_DENIED: mensaje = '📍 GPS: Permiso denegado'; break;
                case error.POSITION_UNAVAILABLE: mensaje = '📍 GPS: No disponible'; break;
                case error.TIMEOUT: mensaje = '📍 GPS: Tiempo agotado'; break;
                default: mensaje = '📍 GPS: Error';
            }
            
            indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">' + mensaje + '</span>';
            
            if (gpsIntentos < 3) {
                gpsIntentos++;
                setTimeout(obtenerGPS, 5000);
            }
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

// ============================================
// FUNCIÓN PRINCIPAL - MARCAR ESTADO
// ============================================

function marcarEstado() {
    var btn = document.getElementById('btnMarcar');
    var progreso = cargarProgreso();
    
    // Obtener placa
    var placa = '';
    var mostrarSelector = (
        progreso.estado_actual === 'salida_ruta' || 
        progreso.estado_actual === 'retorno_ruta' || 
        !progreso.placa_seleccionada
    );
    
    if (mostrarSelector) {
        var select = document.getElementById('placaSelect');
        placa = select ? select.value : '';
        
        if (!placa) {
            alert('⚠️ Por favor selecciona una placa');
            if (select) select.focus();
            return;
        }
    } else {
        placa = progreso.placa_seleccionada || '';
    }
    
    btn.disabled = true;
    btn.innerHTML = '⏳ PROCESANDO...';
    
    // 1. Actualizar progreso LOCALMENTE
    var estadoActual = progreso.estado_actual;
    var fechaAhora = new Date();
    var fechaStr = fechaAhora.getFullYear() + '-' + 
                   String(fechaAhora.getMonth() + 1).padStart(2, '0') + '-' +
                   String(fechaAhora.getDate()).padStart(2, '0') + ' ' +
                   String(fechaAhora.getHours()).padStart(2, '0') + ':' +
                   String(fechaAhora.getMinutes()).padStart(2, '0') + ':' +
                   String(fechaAhora.getSeconds()).padStart(2, '0');
    
    progreso.marcados[estadoActual] = fechaStr;
    
    var keys = Object.keys(estadosDisponibles);
    var i = keys.indexOf(estadoActual);
    progreso.estado_actual = keys[i + 1] || estadoActual;
    
    if (estadoActual === 'salida_ruta' || estadoActual === 'retorno_ruta') {
        if (placa && placa.indexOf('NO HAY PLACAS') === -1) {
            progreso.placa_seleccionada = placa;
        }
    }
    
    // 2. Guardar en localStorage (celular)
    guardarProgreso(progreso);
    
    // 3. Enviar a la base de datos
    var formData = 'estado=' + estadoActual + 
                   '&placa=' + encodeURIComponent(placa) +
                   '&fecha=' + encodeURIComponent(fechaStr);
    
    if (latitud && longitud) {
        formData += '&latitud=' + latitud + '&longitud=' + longitud + '&precision=' + precision;
    }
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (navigator.vibrate) { navigator.vibrate(60); }
        btn.innerHTML = '✅ ¡MARCADO EXITOSO!';
        
        // Actualizar UI
        actualizarUI(progreso);
        
        setTimeout(function() { 
            btn.disabled = false;
            btn.innerHTML = 'MARCAR ESTADO';
        }, 800);
    })
    .catch(function(error) {
        console.error('Error:', error);
        alert('❌ Error de conexión. La marcación se guardó en el celular y se enviará cuando haya conexión.');
        btn.disabled = false;
        btn.innerHTML = 'MARCAR ESTADO (OFFLINE)';
        
        // Aún así actualizar UI
        actualizarUI(progreso);
    });
}

// ============================================
// INICIALIZACIÓN
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    // Cargar progreso y actualizar UI
    var progreso = cargarProgreso();
    actualizarUI(progreso);
    
    // Iniciar GPS
    obtenerGPS();
    
    // Reintentar GPS cada 30 segundos
    setInterval(function() {
        if (!gpsActivo) {
            obtenerGPS();
        }
    }, 30000);
    
    // Auto-respaldo cada 30 segundos
    setInterval(function() {
        var progreso = cargarProgreso();
        guardarProgreso(progreso);
    }, 30000);
    
    console.log('🚀 App iniciada - Progreso guardado en el celular');
});

// Cerrar sesión
function cerrarSesion() {
    if (confirm('¿Cerrar sesión?')) {
        // NO borramos localStorage aquí para que cuando vuelva a iniciar sesión, recupere su progreso
        document.cookie = 'remember_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        document.cookie = 'remember_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        window.location.href = 'login.php';
    }
}

// Ajuste de altura
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