<?php
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

// VERIFICACIÓN DE LOGIN
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

// PROCESAR MARCADOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['estado'])) {
    $estado = $_POST['estado'];
    $placa = $_POST['placa'] ?? '';
    $latitud = isset($_POST['latitud']) ? $_POST['latitud'] : null;
    $longitud = isset($_POST['longitud']) ? $_POST['longitud'] : null;
    $precision_gps = isset($_POST['precision']) ? $_POST['precision'] : null;
    $fecha_marcacion = $_POST['fecha'] ?? date('Y-m-d H:i:s');
    
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

.ciudad-info {
    text-align: center;
    color: var(--naranja);
    font-size: 0.8rem;
    margin-top: 2px;
    font-weight: 600;
}

/* Selector de placa */
.placa-container {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 16px;
    margin-bottom: 15px;
}

.placa-label {
    display: block;
    margin-bottom: 8px;
    color: #555;
    font-weight: 600;
    font-size: 0.9rem;
}

.placa-select {
    width: 100%;
    padding: 14px 18px;
    border: 2px solid #e5e5e5;
    border-radius: 15px;
    font-size: 1.1rem;
    background: white;
    transition: all 0.2s;
    cursor: pointer;
}

.placa-select:focus {
    border-color: var(--verde);
    outline: none;
    box-shadow: 0 0 0 4px rgba(0,154,63,0.1);
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

/* Círculo de progreso */
.circle-wrapper {
    display:flex;
    justify-content:center;
    align-items:center;
    margin: 10px 0;
}

.circle{
    width: min(170px, 38vw);
    height: min(170px, 38vw);
    max-width: 180px;
    max-height: 180px;
    border-radius:50%;
    background: conic-gradient(var(--verde) 0deg 0deg, #e5e5e5 0deg 360deg);
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

.persistencia-info {
    text-align: center;
    color: #888;
    font-size: 0.7rem;
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px solid #eee;
}

@keyframes pop{
    from{transform:scale(.9); opacity:.7}
    to{transform:scale(1); opacity:1}
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
        
        <div class="persistencia-info">
            💾 Tu progreso se guarda automáticamente
        </div>
    </div>

    <div class="circle-wrapper">
        <div id="circleProgress" class="circle">
            <div id="percentText" class="main">0%</div>
        </div>
    </div>

    <div>
        <div id="timelineContainer" class="timeline"></div>
        <div id="estadoContainer" class="estado"></div>
    </div>
</div>

<div class="card">
    <!-- PLACA SELECTOR -->
    <div id="placaSelectorContainer"></div>
    
    <!-- BOTONES PRINCIPALES - AHORA SÍ VISIBLES -->
    <div id="botonesContainer">
        <!-- Los botones se generan aquí con JavaScript -->
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
var keysEstados = Object.keys(estadosDisponibles); // 7 estados
var TOTAL_ESTADOS = keysEstados.length;

var placasDisponibles = <?= json_encode($placas_disponibles) ?>;
var ciudadUsuario = '<?= $ciudad_usuario ?>';
var usuarioId = '<?= $_SESSION['user_id'] ?? '' ?>';

// Clave única para localStorage
var STORAGE_KEY = 'ransa_tracking_' + usuarioId;

// ============================================
// PROGRESO - LOCALSTORAGE
// ============================================

function cargarProgreso() {
    var guardado = localStorage.getItem(STORAGE_KEY);
    
    if (guardado) {
        try {
            var progreso = JSON.parse(guardado);
            // Validar que tenga la estructura correcta
            if (progreso && progreso.estado_actual && progreso.marcados) {
                console.log('✅ Progreso cargado:', progreso);
                return progreso;
            }
        } catch(e) {
            console.error('Error al cargar:', e);
        }
    }
    
    // PROGRESO INICIAL - PRIMER ESTADO
    return {
        estado_actual: 'salida_ruta',
        marcados: {},
        placa_seleccionada: null,
        fecha_inicio: new Date().toISOString()
    };
}

function guardarProgreso(progreso) {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(progreso));
        console.log('💾 Progreso guardado');
        return true;
    } catch(e) {
        console.error('Error al guardar:', e);
        return false;
    }
}

// ============================================
// VERIFICAR ESTADO COMPLETADO
// ============================================

function esTrackingCompletado(progreso) {
    var ultimoEstado = keysEstados[TOTAL_ESTADOS - 1]; // fin_liquidacion_caja
    
    // CASO 1: Ya marcó el último estado
    if (progreso.marcados[ultimoEstado]) {
        return true;
    }
    
    // CASO 2: Ya marcó 7 estados
    var marcadosCount = Object.keys(progreso.marcados).length;
    if (marcadosCount >= TOTAL_ESTADOS) {
        return true;
    }
    
    return false;
}

function obtenerSiguienteEstado(estadoActual) {
    var index = keysEstados.indexOf(estadoActual);
    if (index === TOTAL_ESTADOS - 1) return null;
    return keysEstados[index + 1];
}

// ============================================
// ACTUALIZAR INTERFAZ
// ============================================

function actualizarUI(progreso) {
    // 1. Calcular porcentaje
    var marcadosCount = Object.keys(progreso.marcados).length;
    var percent = Math.round((marcadosCount / TOTAL_ESTADOS) * 100);
    
    // 2. Actualizar círculo
    var circle = document.getElementById('circleProgress');
    if (circle) {
        circle.style.background = 'conic-gradient(var(--verde) 0deg ' + (percent * 3.6) + 'deg, #e5e5e5 ' + (percent * 3.6) + 'deg 360deg)';
    }
    
    var percentText = document.getElementById('percentText');
    if (percentText) percentText.innerHTML = percent + '%';
    
    // 3. Timeline
    actualizarTimeline(progreso);
    
    // 4. Estado actual
    actualizarEstadoActual(progreso, marcadosCount);
    
    // 5. Selector de placa
    actualizarSelectorPlaca(progreso);
    
    // 6. BOTONES - ¡LO MÁS IMPORTANTE!
    actualizarBotones(progreso);
}

function actualizarTimeline(progreso) {
    var container = document.getElementById('timelineContainer');
    if (!container) return;
    
    var html = '';
    for (var i = 0; i < keysEstados.length; i++) {
        var key = keysEstados[i];
        var isDone = progreso.marcados[key] ? true : false;
        var isActive = key === progreso.estado_actual;
        
        var stepClass = 'step';
        if (isDone) stepClass += ' done';
        if (isActive) stepClass += ' active';
        
        html += '<div class="' + stepClass + '"></div>';
        
        if (i < keysEstados.length - 1) {
            var lineClass = 'line';
            if (isDone) lineClass += ' done';
            html += '<div class="' + lineClass + '"></div>';
        }
    }
    container.innerHTML = html;
}

function actualizarEstadoActual(progreso, marcadosCount) {
    var container = document.getElementById('estadoContainer');
    if (!container) return;
    
    var esCompletado = esTrackingCompletado(progreso);
    
    if (esCompletado) {
        container.innerHTML = `
            <div style="text-align:center; padding:15px; background:#e8f5e9; border-radius:15px; color:var(--verde); font-weight:bold;">
                ✅ ¡VIAJE COMPLETADO!<br>
                <span style="font-size:0.9rem;">7/7 estados</span>
            </div>
        `;
    } else {
        var estadoTexto = estadosDisponibles[progreso.estado_actual] || progreso.estado_actual;
        container.innerHTML = `
            <div class="estado">
                Estado actual:
                <strong>${estadoTexto}</strong>
                <div style="font-size:0.8rem; color:#888; margin-top:5px;">
                    ${marcadosCount}/7 estados completados
                </div>
            </div>
        `;
    }
}

function actualizarSelectorPlaca(progreso) {
    var container = document.getElementById('placaSelectorContainer');
    if (!container) return;
    
    var esCompletado = esTrackingCompletado(progreso);
    var placaActual = progreso.placa_seleccionada || '';
    
    // Si el viaje está completado, no mostrar selector de placa
    if (esCompletado) {
        container.innerHTML = `
            <div class="completado-badge">
                ✅ VIAJE COMPLETADO
            </div>
        `;
        return;
    }
    
    // Mostrar selector SOLO en primeros 2 estados o si no hay placa
    var mostrarSelector = (
        progreso.estado_actual === 'salida_ruta' || 
        progreso.estado_actual === 'retorno_ruta' || 
        !progreso.placa_seleccionada
    );
    
    if (mostrarSelector) {
        // Generar opciones del select
        var options = '<option value="" disabled ' + (!placaActual ? 'selected' : '') + '>-- Selecciona una placa --</option>';
        
        for (var i = 0; i < placasDisponibles.length; i++) {
            var placa = placasDisponibles[i];
            if (placa.indexOf('NO HAY PLACAS') === -1) {
                var selected = (placaActual === placa) ? 'selected' : '';
                options += '<option value="' + placa.replace(/"/g, '&quot;') + '" ' + selected + '>' + placa + '</option>';
            }
        }
        
        container.innerHTML = `
            <div class="placa-container">
                <label class="placa-label">🚛 Selecciona la placa del vehículo</label>
                <select id="placaSelect" class="placa-select" required>
                    ${options}
                </select>
                ${placasDisponibles.length === 0 || placasDisponibles[0].indexOf('NO HAY PLACAS') !== -1 ? 
                    '<div style="color:#c62828; margin-top:10px;">⚠️ No hay placas registradas para ' + ciudadUsuario + '</div>' : ''}
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
// ACTUALIZAR BOTONES - ¡AHORA SÍ VISIBLES!
// ============================================

function actualizarBotones(progreso) {
    var container = document.getElementById('botonesContainer');
    if (!container) return;
    
    var esCompletado = esTrackingCompletado(progreso);
    
    if (esCompletado) {
        // CASO: VIAJE COMPLETADO - Botón marcar deshabilitado + botón reinicio
        container.innerHTML = `
            <button class="btn-marcar" disabled>
                ✅ TRACKING COMPLETADO
            </button>
            <button onclick="reiniciarTracking()" class="btn-reinicio">
                🔄 INICIAR NUEVO VIAJE
            </button>
        `;
    } else {
        // CASO: VIAJE EN PROGRESO - Botón marcar habilitado
        container.innerHTML = `
            <button onclick="marcarEstado()" class="btn-marcar" id="btnMarcar">
                MARCAR ESTADO
            </button>
        `;
    }
}

// ============================================
// MARCAR ESTADO
// ============================================

function marcarEstado() {
    var progreso = cargarProgreso();
    
    // Verificar si ya está completado
    if (esTrackingCompletado(progreso)) {
        alert('✅ Este viaje ya está completado. Inicia uno nuevo.');
        actualizarBotones(progreso);
        return;
    }
    
    var btn = document.getElementById('btnMarcar');
    if (btn) btn.disabled = true;
    
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
            alert('⚠️ Selecciona una placa');
            if (select) select.focus();
            if (btn) btn.disabled = false;
            return;
        }
    } else {
        placa = progreso.placa_seleccionada || '';
    }
    
    // Estado a marcar = ESTADO ACTUAL
    var estadoAMarcar = progreso.estado_actual;
    
    // Fecha actual
    var ahora = new Date();
    var fechaStr = ahora.getFullYear() + '-' + 
                   String(ahora.getMonth() + 1).padStart(2, '0') + '-' +
                   String(ahora.getDate()).padStart(2, '0') + ' ' +
                   String(ahora.getHours()).padStart(2, '0') + ':' +
                   String(ahora.getMinutes()).padStart(2, '0') + ':' +
                   String(ahora.getSeconds()).padStart(2, '0');
    
    // 1. MARCAR ESTADO ACTUAL
    progreso.marcados[estadoAMarcar] = fechaStr;
    
    // 2. AVANZAR AL SIGUIENTE ESTADO
    var siguienteEstado = obtenerSiguienteEstado(estadoAMarcar);
    if (siguienteEstado) {
        progreso.estado_actual = siguienteEstado;
    }
    
    // 3. Guardar placa si es necesario
    if (estadoAMarcar === 'salida_ruta' || estadoAMarcar === 'retorno_ruta') {
        if (placa && placa.indexOf('NO HAY PLACAS') === -1) {
            progreso.placa_seleccionada = placa;
        }
    }
    
    // 4. Guardar en localStorage
    guardarProgreso(progreso);
    
    // 5. ACTUALIZAR UI INMEDIATAMENTE
    actualizarUI(progreso);
    
    // 6. Enviar a BD
    var formData = 'estado=' + encodeURIComponent(estadoAMarcar) + 
                   '&placa=' + encodeURIComponent(placa) +
                   '&fecha=' + encodeURIComponent(fechaStr);
    
    if (typeof latitud !== 'undefined' && latitud && longitud) {
        formData += '&latitud=' + latitud + '&longitud=' + longitud + '&precision=' + precision;
    }
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (navigator.vibrate) navigator.vibrate(60);
        console.log('✅ Marcación guardada en BD');
    })
    .catch(function(error) {
        console.log('⚠️ Modo offline - Marcación guardada en celular');
    });
}

// ============================================
// REINICIAR TRACKING
// ============================================

function reiniciarTracking() {
    if (confirm('✅ ¿Iniciar un nuevo viaje?')) {
        var nuevoProgreso = {
            estado_actual: 'salida_ruta',
            marcados: {},
            placa_seleccionada: null,
            fecha_inicio: new Date().toISOString(),
            fecha_fin: new Date().toISOString()
        };
        
        guardarProgreso(nuevoProgreso);
        actualizarUI(nuevoProgreso);
    }
}

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
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}

// ============================================
// CERRAR SESIÓN
// ============================================

function cerrarSesion() {
    if (confirm('¿Cerrar sesión?')) {
        document.cookie = 'remember_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        document.cookie = 'remember_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        window.location.href = 'login.php';
    }
}

// ============================================
// INICIALIZACIÓN
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Iniciando dashboard...');
    
    // 1. Cargar progreso
    var progreso = cargarProgreso();
    
    // 2. Validar estado actual
    if (!progreso.estado_actual || keysEstados.indexOf(progreso.estado_actual) === -1) {
        progreso.estado_actual = 'salida_ruta';
    }
    
    // 3. ACTUALIZAR UI - ESTO MUESTRA LOS BOTONES
    actualizarUI(progreso);
    
    // 4. Iniciar GPS
    obtenerGPS();
    
    // 5. Auto-respaldo
    setInterval(function() {
        var p = cargarProgreso();
        guardarProgreso(p);
    }, 30000);
    
    // 6. Reintentar GPS
    setInterval(function() {
        if (!gpsActivo) obtenerGPS();
    }, 30000);
});

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