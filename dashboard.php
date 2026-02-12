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

// Función para verificar token persistente
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

// Si NO hay sesión, intentar con token persistente
if (!isset($_SESSION['user_id'])) {
    if (!verificarTokenPersistente()) {
        header('Location: login.php');
        exit;
    }
}

// VERIFICAR SI ESTÁ COMPLETADO Y REINICIAR
function verificarYReiniciar() {
    if (isset($_SESSION['progreso'])) {
        $total_estados = 7;
        $marcados = count($_SESSION['progreso']['marcados'] ?? []);
        
        if ($marcados >= $total_estados) {
            $_SESSION['progreso'] = [
                'estado_actual' => 'salida_ruta', 
                'marcados' => [],
                'placa_seleccionada' => null
            ];
            return true;
        }
    }
    return false;
}

// Inicializar progreso si no existe
if (!isset($_SESSION['progreso'])) {
    $_SESSION['progreso'] = [
        'estado_actual' => 'salida_ruta', 
        'marcados' => [],
        'placa_seleccionada' => null
    ];
}

// Verificar si hay que reiniciar
$reiniciado = verificarYReiniciar();

// Obtener placas SOLO de la ciudad del usuario
function obtenerPlacasPorCiudad($ciudad) {
    if (empty($ciudad)) return [];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT placa FROM DPL.externos.placas_tracking 
              WHERE ciudad = ? AND activo = 1 
              ORDER BY placa";
    
    $params = array($ciudad);
    $stmt = sqlsrv_query($conn, $query, $params);
    
    $placas = [];
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
    $placas_disponibles = ['NO HAY PLACAS DISPONIBLES PARA ' . strtoupper($ciudad_usuario)];
}

// PROCESAR MARCADOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['estado'])) {
    $estado = $_POST['estado'];
    $placa = $_POST['placa'] ?? '';
    $latitud = $_POST['latitud'] ?? null;
    $longitud = $_POST['longitud'] ?? null;
    $precision_gps = $_POST['precision'] ?? null;
    
    $p = $_SESSION['progreso'];
    $keys = array_keys($estados);
    $i = array_search($p['estado_actual'], $keys);

    if ($estado === $keys[$i]) {
        // Actualizar sesión
        $p['marcados'][$estado] = date('Y-m-d H:i:s');
        $p['estado_actual'] = $keys[$i+1] ?? $estado;
        
        if ($estado === 'salida_ruta' || $estado === 'retorno_ruta') {
            if (!empty($placa) && !str_contains($placa, 'NO HAY PLACAS')) {
                $p['placa_seleccionada'] = $placa;
            }
        }
        
        $_SESSION['progreso'] = $p;
        
        // Guardar en base de datos la marcación (CON GPS si está disponible)
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "INSERT INTO DPL.externos.marcaciones_tracking 
                  (usuario_id, usuario_nombre, usuario_ciudad, estado_key, estado_nombre, 
                   placa, latitud, longitud, precision_gps, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
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
            $user_agent
        );
        
        $stmt = sqlsrv_query($conn, $query, $params);
        if ($stmt) {
            sqlsrv_free_stmt($stmt);
        }
    }

    echo json_encode(['success' => true, 'progreso' => $p]);
    exit;
}

$p = $_SESSION['progreso'];
$total = count($estados);
$done = count($p['marcados']);
$percent = round(($done / $total) * 100);

$mostrar_selector_placa = ($p['estado_actual'] === 'salida_ruta' || 
                          $p['estado_actual'] === 'retorno_ruta' || 
                          empty($p['placa_seleccionada']));
$placa_actual = $p['placa_seleccionada'] ?? '';
$es_ultimo_estado = $p['estado_actual'] === 'fin_liquidacion_caja';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
<title>RANSA - Rastreo <?= $reiniciado ? '(Reiniciado)' : '' ?></title>

<style>
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

/* Selector de placa con buscador */
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

.sugerencia-item:last-child {
    border-bottom: none;
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

@media (max-height: 580px) {
    .container { padding: 10px 16px; gap: 10px; }
    .card:first-child { padding: 12px 18px; }
    .circle { width: min(130px, 32vw); height: min(130px, 32vw); }
    h1 { font-size: 1.6rem; }
}

@media (max-height: 480px) {
    .container { padding: 8px 16px; gap: 8px; }
    .card:first-child { padding: 10px 16px; }
    .circle { width: min(110px, 28vw); height: min(110px, 28vw); }
    h1 { font-size: 1.4rem; }
    button { padding: 12px; font-size: 1rem; }
}
</style>
</head>
<body>

<div class="container">

<div class="card">
    <div>
        <h1>RANSA</h1>
        
        <!-- INDICADOR GPS - SOLO VISUAL -->
        <div id="gpsIndicator" class="gps-indicator">
            <span class="gps-pulse gps-pulse-inactive"></span>
            <span class="gps-inactive">📍 GPS: Obteniendo ubicación...</span>
        </div>
        
        <?php if($ciudad_usuario): ?>
        <div class="ciudad-info">
            📍 <?= htmlspecialchars($ciudad_usuario) ?>
        </div>
        <?php endif; ?>
        
        <?php if($reiniciado): ?>
        <div style="text-align:center; color:var(--verde); font-size:0.8rem; margin-top:5px;">
            ✅ Tracking completado - Nuevo viaje iniciado
        </div>
        <?php endif; ?>
    </div>

    <div class="circle-wrapper">
        <div class="circle">
            <div class="main"><?= $percent ?>%</div>
        </div>
    </div>

    <div>
        <div class="timeline">
            <?php $i=0; foreach($estados as $k=>$v):
                $isDone = isset($p['marcados'][$k]);
                $isActive = $k === $p['estado_actual'];
            ?>
                <div class="step <?= $isDone?'done':'' ?> <?= $isActive?'active':'' ?>"></div>
                <?php if($i<$total-1): ?>
                    <div class="line <?= $isDone?'done':'' ?>"></div>
                <?php endif; $i++; endforeach; ?>
        </div>

        <div class="estado">
            Estado actual:
            <strong><?= $estados[$p['estado_actual']] ?></strong>
        </div>
    </div>
</div>

<div class="card">
    <?php if ($mostrar_selector_placa): ?>
        <div class="placa-container">
            <label class="placa-label">🚛 Busca o selecciona la placa del vehículo</label>
            <div class="placa-input-container">
                <input type="text" 
                       id="placaInput" 
                       class="placa-input" 
                       placeholder="Escribe para buscar..." 
                       value="<?= htmlspecialchars($placa_actual) ?>"
                       autocomplete="off"
                       <?= empty($placas_disponibles) ? 'disabled' : '' ?>>
                <div id="sugerencias" class="sugerencias-placa"></div>
            </div>
            <?php if(empty($placas_disponibles)): ?>
                <div style="color:#c62828; margin-top:10px; font-size:0.9rem;">
                    ⚠️ No hay placas registradas para <?= htmlspecialchars($ciudad_usuario) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="placa-fija">
            <span>🚛 Placa asignada</span>
            <strong><?= htmlspecialchars($placa_actual) ?></strong>
        </div>
    <?php endif; ?>
    
    <button onclick="marcarEstadoConGPS('<?= $p['estado_actual'] ?>')" id="btnMarcar" <?= $es_ultimo_estado ? 'disabled' : '' ?>>
        <?= $es_ultimo_estado ? '✅ TRACKING COMPLETADO' : 'MARCAR ESTADO' ?>
    </button>
    
    <?php if($es_ultimo_estado): ?>
    <button onclick="reiniciarTracking()" class="btn-reinicio">
        🔄 INICIAR NUEVO VIAJE
    </button>
    <?php endif; ?>
    
    <button onclick="cerrarSesion()" class="logout-btn">
        Cerrar sesión
    </button>
</div>

</div>

<script>
// Variables GPS
let gpsActivo = false;
let latitud = null;
let longitud = null;
let precision = null;
let gpsIntentos = 0;

// Lista de placas disponibles
const placasDisponibles = <?= json_encode($placas_disponibles) ?>;
let placaSeleccionada = '<?= $placa_actual ?>';

// Función para obtener ubicación GPS (siempre intenta, no es restrictivo)
function obtenerGPS() {
    const indicator = document.getElementById('gpsIndicator');
    
    if (!navigator.geolocation) {
        indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">📍 GPS: No soportado</span>';
        return;
    }
    
    indicator.innerHTML = '<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">📍 GPS: Obteniendo ubicación...</span>';
    
    navigator.geolocation.getCurrentPosition(
        // Éxito
        function(position) {
            latitud = position.coords.latitude;
            longitud = position.coords.longitude;
            precision = position.coords.accuracy;
            gpsActivo = true;
            
            indicator.innerHTML = '<span class="gps-pulse gps-pulse-active"></span><span class="gps-active">📍 GPS ACTIVO</span>';
            console.log('GPS activo - Coordenadas obtenidas');
        },
        // Error
        function(error) {
            gpsActivo = false;
            latitud = null;
            longitud = null;
            precision = null;
            
            let mensaje = '';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    mensaje = '📍 GPS: Permiso denegado';
                    break;
                case error.POSITION_UNAVAILABLE:
                    mensaje = '📍 GPS: No disponible';
                    break;
                case error.TIMEOUT:
                    mensaje = '📍 GPS: Tiempo agotado';
                    break;
                default:
                    mensaje = '📍 GPS: Error';
            }
            
            indicator.innerHTML = `<span class="gps-pulse gps-pulse-inactive"></span><span class="gps-inactive">${mensaje}</span>`;
            
            // Reintentar hasta 3 veces
            if (gpsIntentos < 3) {
                gpsIntentos++;
                setTimeout(obtenerGPS, 5000);
            }
        },
        {
            enableHighAccuracy: true,
            timeout: 10000,
            maximumAge: 0
        }
    );
}

// Función para filtrar placas
function filtrarPlacas(busqueda) {
    if (!placasDisponibles.length) return [];
    if (!busqueda) return placasDisponibles.slice(0, 5);
    
    busqueda = busqueda.toUpperCase();
    return placasDisponibles.filter(placa => 
        placa.toUpperCase().includes(busqueda)
    ).slice(0, 5);
}

// Mostrar sugerencias
function mostrarSugerencias() {
    const input = document.getElementById('placaInput');
    const sugerenciasDiv = document.getElementById('sugerencias');
    
    if (!input || !sugerenciasDiv) return;
    
    const busqueda = input.value;
    const filtradas = filtrarPlacas(busqueda);
    
    if (filtradas.length > 0 && !filtradas[0].includes('NO HAY PLACAS')) {
        sugerenciasDiv.innerHTML = filtradas.map(placa => 
            `<div class="sugerencia-item" onclick="seleccionarPlaca('${placa.replace(/'/g, "\\'")}')">${placa}</div>`
        ).join('');
        sugerenciasDiv.style.display = 'block';
    } else {
        sugerenciasDiv.style.display = 'none';
    }
}

// Seleccionar placa
function seleccionarPlaca(placa) {
    const input = document.getElementById('placaInput');
    if (input) {
        input.value = placa;
        placaSeleccionada = placa;
    }
    document.getElementById('sugerencias').style.display = 'none';
}

// Ocultar sugerencias al hacer clic fuera
document.addEventListener('click', function(event) {
    const input = document.getElementById('placaInput');
    const sugerencias = document.getElementById('sugerencias');
    if (input && sugerencias && !input.contains(event.target) && !sugerencias.contains(event.target)) {
        sugerencias.style.display = 'none';
    }
});

// Función para marcar estado con GPS
function marcarEstadoConGPS(estado) {
    const btn = document.getElementById('btnMarcar');
    let placa = '';
    
    <?php if ($mostrar_selector_placa): ?>
    const inputPlaca = document.getElementById('placaInput');
    placa = inputPlaca ? inputPlaca.value.trim() : '';
    
    if (!placa) {
        alert('⚠️ Por favor selecciona o escribe una placa');
        if (inputPlaca) inputPlaca.focus();
        return;
    }
    
    if (placasDisponibles.length > 0 && !placasDisponibles.includes(placa) && !placa.includes('NO HAY PLACAS')) {
        alert('⚠️ La placa ingresada no está registrada. Por favor selecciona de la lista.');
        return;
    }
    <?php else: ?>
    placa = '<?= $placa_actual ?>';
    <?php endif; ?>
    
    btn.disabled = true;
    btn.innerHTML = '⏳ PROCESANDO...';
    
    // Construir datos - SIEMPRE incluye GPS si está disponible
    let formData = 'estado=' + estado + '&placa=' + encodeURIComponent(placa);
    
    if (latitud && longitud) {
        formData += '&latitud=' + latitud + '&longitud=' + longitud + '&precision=' + precision;
    }
    
    fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: formData
    })
    .then(r => r.json())
    .then(d => {
        if (navigator.vibrate) { navigator.vibrate(60); }
        btn.innerHTML = '✅ ¡MARCADO EXITOSO!';
        setTimeout(() => { location.reload(); }, 800);
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al marcar estado. Intenta nuevamente.');
        btn.disabled = false;
        btn.innerHTML = 'MARCAR ESTADO';
    });
}

// Reiniciar tracking
function reiniciarTracking() {
    if (confirm('¿Finalizar este viaje y comenzar uno nuevo?')) {
        fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'reiniciar=1'
        }).then(() => {
            location.reload();
        });
    }
}

// Cerrar sesión
function cerrarSesion() {
    if (confirm('¿Cerrar sesión?')) {
        document.cookie = 'remember_token=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        document.cookie = 'remember_user=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        window.location.href = 'login.php';
    }
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    const input = document.getElementById('placaInput');
    if (input) {
        input.addEventListener('input', mostrarSugerencias);
        input.addEventListener('focus', mostrarSugerencias);
        if (placaSeleccionada) {
            input.value = placaSeleccionada;
        }
    }
    
    // Iniciar GPS al cargar la página
    obtenerGPS();
    
    // Reintentar GPS cada 30 segundos si está inactivo
    setInterval(() => {
        if (!gpsActivo) {
            obtenerGPS();
        }
    }, 30000);
});

// Ajuste de altura
function ajustarAltura() {
    const vh = window.innerHeight;
    document.documentElement.style.setProperty('--vh', `${vh}px`);
    const container = document.querySelector('.container');
    if (container) container.style.height = `${vh}px`;
}

window.addEventListener('load', ajustarAltura);
window.addEventListener('resize', ajustarAltura);
window.addEventListener('orientationchange', setTimeout.bind(null, ajustarAltura, 100));
</script>

</body>
</html>