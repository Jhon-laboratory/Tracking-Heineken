<?php
// ============================================
// REGISTRO DE PLANTILLAS - VERSIÓN CON PHPSPREADSHEET
// PLACAS SE GUARDAN SIN GUIONES NI ESPACIOS (ABC1234)
// CON BOTONES PARA ELIMINAR REGISTROS ANTES DE CARGAR
// Y BOTONES FLOTANTES SIEMPRE VISIBLES EN DERECHA
// ============================================
session_start();

// Configuración PHP
ini_set('memory_limit', '256M');
ini_set('upload_max_filesize', '10M');
ini_set('post_max_size', '10M');
set_time_limit(300);

// Cargar autoload de Composer para PhpSpreadsheet
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// Variables
$error = '';
$success = '';
$datos_procesados = array();
$duplicados_encontrados = array();

// ============================================
// FUNCIONES PARA VALIDAR PLACAS ECUATORIANAS
// ============================================

/**
 * Valida si una placa corresponde al formato ecuatoriano
 * Acepta múltiples formatos: ABC-1234, ABC1234, ABC 1234, etc.
 */
function validarPlacaEcuador($placa) {
    // Limpiar la placa para validación
    $placa = strtoupper(trim($placa));
    
    // Eliminar guiones y espacios para validar el formato base
    $placa_limpia = str_replace(array('-', ' '), '', $placa);
    
    // Patrones para placas ecuatorianas (sin guiones)
    $patron1 = '/^[A-Z]{3}\d{4}$/';  // AAA1234 (autos)
    $patron2 = '/^[A-Z]{3}\d{3}$/';   // AAA123 (motos)
    $patron3 = '/^[A-Z]{3}\d{3}[A-Z]$/'; // AAA123A (gobierno)
    
    return preg_match($patron1, $placa_limpia) || 
           preg_match($patron2, $placa_limpia) || 
           preg_match($patron3, $placa_limpia);
}

/**
 * Estandariza el formato de placa a AAA1234 (SIN GUIONES NI ESPACIOS)
 */
function estandarizarPlaca($placa) {
    // 1. Limpiar espacios al inicio y final
    $placa = trim($placa);
    
    // 2. Convertir a mayúsculas
    $placa = strtoupper($placa);
    
    // 3. Eliminar guiones
    $placa = str_replace('-', '', $placa);
    
    // 4. Eliminar espacios
    $placa = str_replace(' ', '', $placa);
    
    // 5. Eliminar puntos (si alguien pone ABC.1234)
    $placa = str_replace('.', '', $placa);
    
    // 6. Eliminar cualquier otro carácter que no sea letra o número
    $placa = preg_replace('/[^A-Z0-9]/', '', $placa);
    
    // 7. Extraer solo los primeros 3 caracteres (letras) y siguientes 3-4 números
    if (preg_match('/^([A-Z]{3})(\d{3,4}[A-Z]?)/', $placa, $matches)) {
        $letras = $matches[1];
        $numeros = $matches[2];
        return $letras . $numeros;
    }
    
    return $placa;
}

// ============================================
// PROCESAR CARGA DE ARCHIVO
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo_excel'])) {
    
    require_once 'conexion.php';
    
    $fecha_plantilla = $_POST['fecha_plantilla'] ?? date('Y-m-d');
    
    // Validar fecha
    if (!strtotime($fecha_plantilla)) {
        $fecha_plantilla = date('Y-m-d');
    }
    
    $archivo = $_FILES['archivo_excel'];
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    
    // Validar extensión
    if (!in_array($extension, array('xlsx', 'xls', 'csv'))) {
        $error = 'Solo se permiten archivos Excel (.xlsx, .xls) o CSV';
    } elseif ($archivo['error'] !== UPLOAD_ERR_OK) {
        $error = 'Error al subir el archivo. Código: ' . $archivo['error'];
    } else {
        // Procesar según extensión
        try {
            $datos_leidos = array();
            
            if ($extension == 'csv') {
                // ============================================
                // PROCESAR ARCHIVO CSV
                // ============================================
                if (($handle = fopen($archivo['tmp_name'], "r")) !== FALSE) {
                    $primera_linea = true;
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        if ($primera_linea) {
                            $primera_linea = false;
                            continue; // Saltar encabezados
                        }
                        if (count($data) >= 2) {
                            $datos_leidos[] = array(
                                'nombre' => trim($data[0]),
                                'placa' => trim($data[1])
                            );
                        }
                    }
                    fclose($handle);
                } else {
                    throw new Exception("No se pudo abrir el archivo CSV");
                }
            } else {
                // ============================================
                // PROCESAR ARCHIVO EXCEL CON PHPSPREADSHEET
                // ============================================
                
                // Cargar el archivo Excel
                $spreadsheet = IOFactory::load($archivo['tmp_name']);
                $sheet = $spreadsheet->getActiveSheet();
                $highestRow = $sheet->getHighestRow();
                
                // Leer datos desde la fila 2 (asumiendo fila 1 como encabezados)
                for ($row = 2; $row <= $highestRow; $row++) {
                    $nombre = trim($sheet->getCell('A' . $row)->getValue());
                    $placa = trim($sheet->getCell('B' . $row)->getValue());
                    
                    if (!empty($nombre) && !empty($placa)) {
                        $datos_leidos[] = array(
                            'nombre' => $nombre,
                            'placa' => $placa
                        );
                    }
                }
            }
            
            // ============================================
            // VALIDAR DATOS LEÍDOS
            // ============================================
            if (empty($datos_leidos)) {
                $error = 'No se encontraron datos en el archivo';
            } else {
                // Normalizar y validar datos
                $datos_validos = array();
                $duplicados_temp = array();
                $errores_validacion = array();
                
                foreach ($datos_leidos as $index => $dato) {
                    $nombre = trim($dato['nombre']);
                    $placa = trim($dato['placa']);
                    
                    // Validar nombre
                    if (empty($nombre)) {
                        $errores_validacion[] = "Fila " . ($index + 2) . ": Nombre vacío";
                        continue;
                    }
                    
                    // Validar placa ecuatoriana
                    if (!validarPlacaEcuador($placa)) {
                        $errores_validacion[] = "Fila " . ($index + 2) . ": Placa inválida '{$placa}'";
                        continue;
                    }
                    
                    // ESTANDARIZAR PLACA: Convertir a formato SIN GUIONES (ABC1234)
                    $placa_estandar = estandarizarPlaca($placa);
                    
                    // Crear clave única para detectar duplicados
                    $clave = $nombre . '|' . $placa_estandar;
                    
                    if (isset($duplicados_temp[$clave])) {
                        $duplicados_encontrados[] = array(
                            'nombre' => $nombre,
                            'placa' => $placa_estandar,
                            'fila_original' => $duplicados_temp[$clave],
                            'fila_duplicada' => $index + 2
                        );
                    } else {
                        $duplicados_temp[$clave] = $index + 2;
                        $datos_validos[] = array(
                            'nombre' => $nombre,
                            'placa' => $placa_estandar  // ← GUARDADA SIN GUIONES
                        );
                    }
                }
                
                // Mostrar errores de validación
                if (!empty($errores_validacion)) {
                    $error = implode('<br>', array_slice($errores_validacion, 0, 10));
                    if (count($errores_validacion) > 10) {
                        $error .= '<br>... y ' . (count($errores_validacion) - 10) . ' errores más';
                    }
                } else {
                    // Guardar en sesión para mostrar y luego guardar
                    $_SESSION['datos_plantilla_temp'] = array(
                        'datos' => $datos_validos,
                        'fecha' => $fecha_plantilla,
                        'archivo' => $archivo['name'],
                        'duplicados' => $duplicados_encontrados
                    );
                    
                    $datos_procesados = $datos_validos;
                }
            }
            
        } catch (Exception $e) {
            $error = 'Error al procesar archivo: ' . $e->getMessage();
        }
    }
}

// ============================================
// ELIMINAR REGISTRO DE LA VISTA PREVIA
// ============================================

if (isset($_POST['eliminar_registro']) && isset($_POST['indice'])) {
    $indice = intval($_POST['indice']);
    
    if (isset($_SESSION['datos_plantilla_temp']['datos'][$indice])) {
        // Eliminar el registro del array en sesión
        array_splice($_SESSION['datos_plantilla_temp']['datos'], $indice, 1);
        
        // Actualizar la variable local
        $datos_procesados = $_SESSION['datos_plantilla_temp']['datos'];
        
        // Si no quedan datos, limpiar sesión
        if (empty($datos_procesados)) {
            unset($_SESSION['datos_plantilla_temp']);
            $success = 'Se eliminaron todos los registros. Carga otro archivo.';
        } else {
            $success = 'Registro eliminado correctamente';
        }
    }
}

// ============================================
// ACTUALIZAR FECHA EN LA VISTA PREVIA
// ============================================

if (isset($_POST['actualizar_fecha']) && isset($_POST['nueva_fecha'])) {
    $nueva_fecha = $_POST['nueva_fecha'];
    
    if (isset($_SESSION['datos_plantilla_temp']) && strtotime($nueva_fecha)) {
        $_SESSION['datos_plantilla_temp']['fecha'] = $nueva_fecha;
        $success = 'Fecha actualizada correctamente';
    }
}

// ============================================
// GUARDAR EN BASE DE DATOS
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_guardado'])) {
    
    require_once 'conexion.php';
    
    if (isset($_SESSION['datos_plantilla_temp'])) {
        $temp = $_SESSION['datos_plantilla_temp'];
        $datos = $temp['datos'];
        $fecha = $temp['fecha'];
        $archivo_nombre = $temp['archivo'];
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Iniciar transacción
        sqlsrv_begin_transaction($conn);
        
        try {
            // Primero, desactivar registros anteriores para esta fecha
            $query_desactivar = "UPDATE DPL.externos.plantillas_conductores 
                                 SET activo = 0 
                                 WHERE fecha_plantilla = ? AND activo = 1";
            $params_desactivar = array($fecha);
            $stmt_desactivar = sqlsrv_query($conn, $query_desactivar, $params_desactivar);
            
            if ($stmt_desactivar === false) {
                throw new Exception("Error al desactivar registros anteriores");
            }
            sqlsrv_free_stmt($stmt_desactivar);
            
            // Insertar nuevos datos
            $query_insert = "INSERT INTO DPL.externos.plantillas_conductores 
                            (nombre, placa, fecha_plantilla, archivo_original) 
                            VALUES (?, ?, ?, ?)";
            
            $insertados = 0;
            foreach ($datos as $dato) {
                $params_insert = array(
                    $dato['nombre'],
                    $dato['placa'],  // ← YA ESTÁ SIN GUIONES
                    $fecha,
                    $archivo_nombre
                );
                
                $stmt_insert = sqlsrv_query($conn, $query_insert, $params_insert);
                if ($stmt_insert === false) {
                    throw new Exception("Error al insertar registro: " . print_r(sqlsrv_errors(), true));
                }
                sqlsrv_free_stmt($stmt_insert);
                $insertados++;
            }
            
            sqlsrv_commit($conn);
            
            $success = "✅ Se guardaron $insertados registros correctamente para la fecha " . date('d/m/Y', strtotime($fecha));
            
            // Limpiar sesión temporal
            unset($_SESSION['datos_plantilla_temp']);
            $datos_procesados = array();
            $duplicados_encontrados = array();
            
        } catch (Exception $e) {
            sqlsrv_rollback($conn);
            $error = 'Error al guardar en base de datos: ' . $e->getMessage();
        }
    }
}

// ============================================
// CANCELAR Y LIMPIAR
// ============================================

if (isset($_POST['cancelar'])) {
    unset($_SESSION['datos_plantilla_temp']);
    $datos_procesados = array();
    $duplicados_encontrados = array();
}

// ============================================
// OBTENER DATOS DE SESIÓN PARA MOSTRAR
// ============================================

if (isset($_SESSION['datos_plantilla_temp']) && empty($datos_procesados) && !isset($_POST['eliminar_registro']) && !isset($_POST['actualizar_fecha'])) {
    $datos_procesados = $_SESSION['datos_plantilla_temp']['datos'];
    $duplicados_encontrados = $_SESSION['datos_plantilla_temp']['duplicados'];
    $fecha_temp = $_SESSION['datos_plantilla_temp']['fecha'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>RANSA - Carga de Plantillas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7f9 0%, #e3e8ef 100%);
            min-height: 100vh;
            padding: 20px;
            color: #2c3e50;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Botones flotantes en las esquinas */
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
            background: linear-gradient(135deg, #009A3F, #007a32);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 15px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            box-shadow: 0 3px 8px rgba(0, 154, 63, 0.2);
            text-decoration: none;
        }

        .floating-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(0, 154, 63, 0.3);
        }

        .floating-button.left {
            position: absolute;
            left: 20px;
        }

        .floating-button.right {
            position: absolute;
            right: 20px;
            background: linear-gradient(135deg, #F39200, #e08500);
        }

        .floating-button.right:hover {
            box-shadow: 0 5px 12px rgba(243, 146, 0, 0.3);
        }

        /* Botones flotantes verticales - SIEMPRE VISIBLES EN DERECHA */
        .floating-actions-right {
            position: fixed;
            bottom: 30px;
            right: 30px;
            display: flex;
            flex-direction: column;
            gap: 20px;
            z-index: 1000;
            pointer-events: none;
            transition: all 0.3s ease;
        }

        .action-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            pointer-events: auto;
        }

        .action-button {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
            font-size: 22px;
            color: white;
            margin-bottom: 5px;
        }

        .action-button:hover {
            transform: translateY(-3px) scale(1.05);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }

        .action-button.primary {
            background: linear-gradient(135deg, #009A3F, #007a32);
        }

        .action-button.secondary {
            background: linear-gradient(135deg, #F39200, #e08500);
        }

        .action-button.tertiary {
            background: linear-gradient(135deg, #7f8c8d, #95a5a6);
        }

        .action-button.blue {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .action-button.purple {
            background: linear-gradient(135deg, #F39200, #e08500);
        }

        .action-label {
            font-size: 11px;
            font-weight: 600;
            text-align: center;
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 2px;
            color: white;
            text-shadow: 0 1px 2px rgba(0,0,0,0.2);
            min-width: 70px;
            letter-spacing: 0.3px;
        }

        .action-label.primary {
            background-color: #009A3F;
        }

        .action-label.secondary {
            background-color: #F39200;
        }

        .action-label.tertiary {
            background-color: #7f8c8d;
        }

        .action-label.blue {
            background-color: #3498db;
        }

        .action-label.purple {
            background-color: #F39200;
        }

        /* Tooltip mejorado */
        .action-button .tooltip {
            position: absolute;
            right: 75px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(0, 0, 0, 0.85);
            color: white;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
            pointer-events: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }

        .action-button .tooltip::after {
            content: '';
            position: absolute;
            top: 50%;
            right: -5px;
            transform: translateY(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: transparent transparent transparent rgba(0, 0, 0, 0.85);
        }

        .action-button:hover .tooltip {
            opacity: 1;
            visibility: visible;
            right: 80px;
        }

        /* Header con diseño mejorado */
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            border-top: 4px solid #009A3F;
            flex-wrap: wrap;
            gap: 20px;
            position: relative;
            overflow: hidden;
            margin-top: 5px;
        }

        .header-top::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #009A3F, #F39200);
        }

        .logo-container {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo {
            height: 50px;
            object-fit: contain;
            flex-shrink: 0;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }

        .title-section {
            flex: 1;
            min-width: 300px;
            text-align: center;
        }

        .title {
            font-size: 2.2rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
            animation: subtleGlow 3s ease-in-out infinite alternate;
            background: linear-gradient(135deg, #009A3F, #2c3e50);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 5px;
        }

        @keyframes subtleGlow {
            0% {
                text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
                transform: translateY(0);
            }
            100% {
                text-shadow: 2px 2px 8px rgba(0, 154, 63, 0.3);
                transform: translateY(-2px);
            }
        }

        .title::after {
            content: '';
            display: block;
            width: 80px;
            height: 4px;
            background: linear-gradient(90deg, #009A3F, #F39200);
            margin: 10px auto 0;
            border-radius: 2px;
            animation: lineGrow 1.5s ease-out;
        }

        @keyframes lineGrow {
            from {
                width: 0;
                opacity: 0;
            }
            to {
                width: 80px;
                opacity: 1;
            }
        }

        .subtitle {
            font-size: 0.95rem;
            color: #7f8c8d;
            font-weight: 500;
            margin-top: 5px;
        }

        /* Mensajes */
        .message-container {
            margin-bottom: 20px;
            min-height: 5px;
        }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
            border-left: 4px solid;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            margin-bottom: 15px;
        }

        .message.error {
            background-color: #ffecec;
            color: #e74c3c;
            border-left-color: #e74c3c;
        }

        .message.warning {
            background-color: #fff8e1;
            color: #f39c12;
            border-left-color: #f39c12;
        }

        .message.success {
            background-color: #e8f6ef;
            color: #009A3F;
            border-left-color: #009A3F;
        }

        .message.info {
            background-color: #e3f2fd;
            color: #1976d2;
            border-left-color: #1976d2;
        }

        /* Tarjetas */
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #009A3F, #F39200);
        }

        .card h2 {
            color: #2c3e50;
            font-size: 1.8rem;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card h2 i {
            color: #009A3F;
        }

        .card h3 {
            color: #34495e;
            font-size: 1.4rem;
            margin-bottom: 15px;
        }

        /* Formularios */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 0.95rem;
        }

        input[type="file"],
        input[type="date"] {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        input[type="file"]:focus,
        input[type="date"]:focus {
            outline: none;
            border-color: #009A3F;
            box-shadow: 0 0 0 3px rgba(0, 154, 63, 0.1);
            background: white;
        }

        .row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .col {
            flex: 1;
            min-width: 250px;
        }

        .text-right {
            text-align: right;
        }

        /* Botones */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #009A3F, #007a32);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(0, 154, 63, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #F39200, #e08500);
            color: white;
        }

        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(243, 146, 0, 0.3);
        }

        .btn-danger {
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            color: white;
        }

        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 12px rgba(231, 76, 60, 0.3);
        }

        .btn-outline {
            background: #f8f9fa;
            color: #2c3e50;
            border: 1px solid #ddd;
        }

        .btn-outline:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.9rem;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        /* Tabla */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            margin: 20px 0;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            max-height: 500px;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: linear-gradient(135deg, #009A3F, #007a32);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th {
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }

        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
            color: #2c3e50;
        }

        tr:hover {
            background-color: #f8f9fa;
        }

        tr.duplicado-row {
            background-color: #fff8e1;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #e8f6ef;
            color: #009A3F;
        }

        .badge-warning {
            background: #fff8e1;
            color: #F39200;
        }

        .btn-eliminar {
            background: none;
            border: none;
            color: #e74c3c;
            cursor: pointer;
            font-size: 18px;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .btn-eliminar:hover {
            background: #ffebee;
            transform: scale(1.1);
        }

        .resumen-carga {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            border-left: 4px solid #009A3F;
        }

        .fecha-editable {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 5px;
        }

        .fecha-editable input[type="date"] {
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            width: auto;
            flex: 1;
        }

        .btn-actualizar-fecha {
            background: #F39200;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-actualizar-fecha:hover {
            background: #e08500;
            transform: translateY(-2px);
        }

        .duplicado-warning {
            background: #fff8e1;
            border-left: 4px solid #F39200;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }

        .duplicado-item {
            padding: 8px 12px;
            margin: 5px 0;
            background: #ffffff;
            border-radius: 4px;
            border-left: 3px solid #F39200;
        }

        .footer {
            text-align: center;
            margin-top: 40px;
            padding: 20px;
            color: #7f8c8d;
            font-size: 14px;
            border-top: 1px solid #e9ecef;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        /* Modal de confirmación */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            position: relative;
            animation: modalAppear 0.3s ease;
        }

        @keyframes modalAppear {
            from {
                opacity: 0;
                transform: scale(0.9);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .modal-content::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #009A3F, #F39200);
            border-radius: 15px 15px 0 0;
        }

        .modal-icon {
            font-size: 48px;
            color: #009A3F;
            text-align: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
            text-align: center;
        }

        .modal-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }

        .modal-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .modal-info-item:last-child {
            border-bottom: none;
        }

        .modal-info-label {
            font-weight: 600;
            color: #7f8c8d;
        }

        .modal-info-value {
            font-size: 18px;
            font-weight: 700;
            color: #2c3e50;
        }

        .modal-info-value.fecha {
            color: #F39200;
        }

        .modal-buttons {
            display: flex;
            gap: 15px;
            margin-top: 25px;
        }

        .modal-buttons .btn {
            flex: 1;
            padding: 12px;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            body {
                padding: 10px;
            }

            .header-top {
                flex-direction: column;
                text-align: center;
                margin-top: 20px;
            }

            .title {
                font-size: 1.8rem;
            }

            .floating-actions-right {
                bottom: 20px;
                right: 15px;
                gap: 15px;
            }

            .action-button {
                width: 55px;
                height: 55px;
                font-size: 20px;
            }

            .action-label {
                font-size: 10px;
                min-width: 60px;
            }

            .action-button .tooltip {
                display: none;
            }

            .row {
                flex-direction: column;
            }

            .fecha-editable {
                flex-direction: column;
                align-items: stretch;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Botones flotantes en las esquinas -->
    <div class="floating-buttons">
        <div class="floating-button left" onclick="window.location.href='dashboard.php'">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </div>
        <div class="floating-button right" onclick="window.location.href='monitor.php'">
            <i class="fas fa-chart-line"></i> Monitor
        </div>
    </div>

    <!-- Modal de confirmación -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-content">
            <div class="modal-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="modal-title">Confirmar Guardado</div>
            
            <div class="modal-info">
                <div class="modal-info-item">
                    <span class="modal-info-label">📅 Fecha de uso de placas:</span>
                    <span class="modal-info-value fecha" id="modalFecha"></span>
                </div>
                <div class="modal-info-item">
                    <span class="modal-info-label">🚛 Cantidad de placas:</span>
                    <span class="modal-info-value" id="modalCantidad"></span>
                </div>
                <div class="modal-info-item">
                    <span class="modal-info-label">📎 Archivo:</span>
                    <span class="modal-info-value" id="modalArchivo"></span>
                </div>
            </div>
            
            <div style="text-align: center; margin-bottom: 20px; color: #7f8c8d; font-size: 14px;">
                <i class="fas fa-info-circle"></i> Las placas se guardarán sin guiones (formato: ABC1234)
            </div>
            
            <div class="modal-buttons">
                <button class="btn btn-outline" onclick="cerrarModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
                <button class="btn btn-primary" onclick="confirmarGuardado()">
                    <i class="fas fa-check"></i> Confirmar
                </button>
            </div>
        </div>
    </div>

    <!-- Botones flotantes verticales - SIEMPRE VISIBLES EN DERECHA -->
    <div class="floating-actions-right">
        <!-- Botón Descargar Plantilla -->
        <div class="action-item">
            <a href="descargar_plantilla.php" class="action-button blue" style="text-decoration: none;">
                <i class="fas fa-download"></i>
                <div class="tooltip">Descargar plantilla Excel con formato</div>
            </a>
            <div class="action-label blue">Descargar</div>
        </div>
        
        <!-- Botón Procesar Archivo -->
        <div class="action-item">
            <button class="action-button purple" onclick="document.getElementById('uploadForm').submit();">
                <i class="fas fa-upload"></i>
                <div class="tooltip">Procesar archivo seleccionado</div>
            </button>
            <div class="action-label purple">Procesar</div>
        </div>
        
        <!-- Botón Guardar (solo visible en vista previa) -->
        <div class="action-item" id="btnGuardarContainer" style="<?= !empty($datos_procesados) ? 'display: flex;' : 'display: none;' ?>">
            <button class="action-button primary" onclick="mostrarModalConfirmacion()">
                <i class="fas fa-save"></i>
                <div class="tooltip">Guardar en base de datos</div>
            </button>
            <div class="action-label primary">Guardar</div>
        </div>
        
        <!-- Botón Cancelar (solo visible en vista previa) -->
        <div class="action-item" id="btnCancelarContainer" style="<?= !empty($datos_procesados) ? 'display: flex;' : 'display: none;' ?>">
            <button class="action-button tertiary" onclick="document.getElementById('cancelarForm').submit();">
                <i class="fas fa-times"></i>
                <div class="tooltip">Cancelar y volver</div>
            </button>
            <div class="action-label tertiary">Cancelar</div>
        </div>
        
        <!-- Botón Monitor (siempre visible) -->
        <div class="action-item">
            <a href="monitor.php" class="action-button secondary" style="text-decoration: none;">
                <i class="fas fa-chart-line"></i>
                <div class="tooltip">Ver monitor de placas</div>
            </a>
            <div class="action-label secondary">Monitor</div>
        </div>
    </div>

    <div class="container">
        <!-- Header -->
        <div class="header-top">
            <div class="logo-container">
                <img src="https://tse2.mm.bing.net/th/id/OIP.Ckl7mNDKlUqm6056On3FIwAAAA?w=380&h=125&rs=1&pid=ImgDetMain&o=7&rm=3" 
                     class="logo" 
                     alt="Logo"
                     loading="lazy">
            </div>
            
            <div class="title-section">
                <h1 class="title">RANSA - Carga de Plantillas</h1>
                <p class="subtitle">Sistema de carga de conductores y placas</p>
            </div>
        </div>

        <!-- Mensajes -->
        <div class="message-container">
            <?php if ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= $error ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= $success ?></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Contenido principal -->
        <?php if (empty($datos_procesados)): ?>
            <!-- FORMULARIO DE CARGA -->
            <div class="card">
                <h2><i class="fas fa-upload"></i> Cargar Archivo de Conductores</h2>
                
                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <div class="col">
                            <div class="form-group">
                                <label><i class="fas fa-calendar"></i> Fecha de la plantilla:</label>
                                <input type="date" name="fecha_plantilla" value="<?= date('Y-m-d') ?>" required>
                            </div>
                        </div>
                        <div class="col">
                            <div class="form-group">
                                <label><i class="fas fa-file-excel"></i> Seleccionar archivo:</label>
                                <input type="file" name="archivo_excel" id="archivoInput" accept=".xlsx,.xls,.csv" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Botón de procesar oculto (se usa el flotante) -->
                    <div style="display: none;">
                        <button type="submit" class="btn btn-primary" id="submitBtn">
                            <i class="fas fa-upload"></i> Procesar Archivo
                        </button>
                    </div>
                </form>
                
                
            </div>

            <!-- Últimas cargas -->
            <?php
            require_once 'conexion.php';
            $database = new Database();
            $conn = $database->getConnection();
            
            if ($conn) {
                $query_ultimas = "SELECT TOP 5 fecha_plantilla, COUNT(*) as total, MAX(fecha_carga) as ultima_carga
                                  FROM DPL.externos.plantillas_conductores
                                  WHERE activo = 1
                                  GROUP BY fecha_plantilla
                                  ORDER BY ultima_carga DESC";
                $stmt_ultimas = sqlsrv_query($conn, $query_ultimas);
                
                if ($stmt_ultimas !== false && sqlsrv_has_rows($stmt_ultimas)):
            ?>
            <div class="card">
                <h3><i class="fas fa-history"></i> Últimas cargas realizadas</h3>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Fecha Plantilla</th>
                                <th>Registros</th>
                                <th>Última carga</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = sqlsrv_fetch_array($stmt_ultimas, SQLSRV_FETCH_ASSOC)): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($row['fecha_plantilla'])) ?></td>
                                <td><?= $row['total'] ?> conductores</td>
                                <td>
                                    <?php 
                                    if ($row['ultima_carga'] instanceof DateTime) {
                                        echo $row['ultima_carga']->format('d/m/Y H:i');
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php 
                    endif;
                    if ($stmt_ultimas !== false) {
                        sqlsrv_free_stmt($stmt_ultimas);
                    }
                }
            ?>

        <?php else: ?>
            <!-- VISTA PREVIA DE DATOS CON OPCIÓN DE ELIMINAR -->
            <div class="card">
                <h2><i class="fas fa-eye"></i> Vista Previa de Datos</h2>
                
                <div class="resumen-carga">
                    <div class="row">
                        <div class="col">
                            <strong><i class="fas fa-calendar"></i> Fecha de uso de placas:</strong><br>
                            <div class="fecha-editable">
                                <input type="date" id="fechaInput" value="<?= $fecha_temp ?? $_SESSION['datos_plantilla_temp']['fecha'] ?>" style="width: auto;">
                                
                            </div>
                        </div>
                        <div class="col">
                            <strong><i class="fas fa-chart-bar"></i> Total registros válidos:</strong><br>
                            <span class="badge badge-success"><?= count($datos_procesados) ?></span>
                        </div>
                        <div class="col">
                            <strong><i class="fas fa-file"></i> Archivo:</strong><br>
                            <?= $_SESSION['datos_plantilla_temp']['archivo'] ?? 'N/A' ?>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($duplicados_encontrados)): ?>
                <div class="duplicado-warning">
                    <strong><i class="fas fa-exclamation-triangle"></i> Se encontraron registros duplicados que fueron omitidos:</strong>
                    <?php foreach ($duplicados_encontrados as $dup): ?>
                    <div class="duplicado-item">
                        <i class="fas fa-info-circle"></i>
                        <strong><?= htmlspecialchars($dup['nombre']) ?></strong> - <?= htmlspecialchars($dup['placa']) ?> 
                        (filas <?= $dup['fila_original'] ?> y <?= $dup['fila_duplicada'] ?>)
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Tabla con botones de eliminar -->
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Nombre Completo</th>
                                <th>Placa (formato guardado)</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($datos_procesados as $index => $dato): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($dato['nombre']) ?></td>
                                <td><span class="badge badge-success"><?= htmlspecialchars($dato['placa']) ?></span></td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="eliminar_registro" value="1">
                                        <input type="hidden" name="indice" value="<?= $index ?>">
                                        <button type="submit" class="btn-eliminar" title="Eliminar registro">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Botones de acción principales (ocultos, se usan flotantes) -->
                <div style="display: none;">
                    <div class="row" style="margin-top: 20px;">
                        <div class="col">
                            <button class="btn btn-primary" style="width: 100%;" onclick="mostrarModalConfirmacion()">
                                <i class="fas fa-check-circle"></i> CONFIRMAR Y GUARDAR EN BD
                            </button>
                        </div>
                        <div class="col">
                            <form id="cancelarForm" method="POST">
                                <input type="hidden" name="cancelar" value="1">
                                <button type="submit" class="btn btn-danger" style="width: 100%;">
                                    <i class="fas fa-times-circle"></i> CANCELAR Y VOLVER
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <p style="text-align: center; margin-top: 15px; color: #7f8c8d; font-size: 13px;">
                    <i class="fas fa-info-circle"></i>
                    Use los botones flotantes para guardar o cancelar
                </p>
            </div>
        <?php endif; ?>

        <div class="footer">
            <p>Sistema de carga de plantillas RANSA &copy; 2024</p>
            <p style="font-size: 12px; margin-top: 5px; color: #95a5a6;">
                <i class="fas fa-shield-alt"></i> Las placas se guardan sin guiones (formato: ABC1234)
            </p>
        </div>
    </div>

    <form id="actualizarFechaForm" method="POST" style="display: none;">
        <input type="hidden" name="actualizar_fecha" value="1">
        <input type="hidden" name="nueva_fecha" id="nuevaFechaInput">
    </form>

    <form id="confirmarGuardadoForm" method="POST" style="display: none;">
        <input type="hidden" name="confirmar_guardado" value="1">
    </form>

    <script>
        // Función para actualizar fecha
        function actualizarFecha() {
            const fechaInput = document.getElementById('fechaInput');
            const nuevaFecha = fechaInput.value;
            
            if (!nuevaFecha) {
                alert('Por favor selecciona una fecha');
                return;
            }
            
            document.getElementById('nuevaFechaInput').value = nuevaFecha;
            document.getElementById('actualizarFechaForm').submit();
        }

        // Funciones para el modal de confirmación
        function mostrarModalConfirmacion() {
            const modal = document.getElementById('confirmModal');
            const fecha = document.getElementById('fechaInput')?.value || '<?= $fecha_temp ?? $_SESSION['datos_plantilla_temp']['fecha'] ?>';
            const cantidad = '<?= count($datos_procesados) ?>';
            const archivo = '<?= $_SESSION['datos_plantilla_temp']['archivo'] ?? 'N/A' ?>';
            
            // Formatear fecha para mostrar
            const fechaObj = new Date(fecha + 'T00:00:00');
            const fechaFormateada = fechaObj.toLocaleDateString('es-ES', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });
            
            document.getElementById('modalFecha').textContent = fechaFormateada;
            document.getElementById('modalCantidad').textContent = cantidad + ' placas';
            document.getElementById('modalArchivo').textContent = archivo;
            
            modal.style.display = 'flex';
        }

        function cerrarModal() {
            document.getElementById('confirmModal').style.display = 'none';
        }

        function confirmarGuardado() {
            cerrarModal();
            document.getElementById('confirmarGuardadoForm').submit();
        }

        // Confirmar antes de cancelar
        document.querySelectorAll('form input[name="cancelar"]').forEach(input => {
            input.closest('form').addEventListener('submit', function(e) {
                if (!confirm('¿Estás seguro de cancelar? Se perderán los datos procesados.')) {
                    e.preventDefault();
                }
            });
        });

        // Deshabilitar botón de envío para evitar doble click
        document.querySelectorAll('form button[type="submit"]').forEach(button => {
            button.addEventListener('click', function() {
                setTimeout(() => {
                    this.disabled = true;
                }, 100);
            });
        });

        // Mostrar nombre del archivo seleccionado
        const fileInput = document.querySelector('input[type="file"]');
        if (fileInput) {
            fileInput.addEventListener('change', function() {
                const fileName = this.files[0]?.name;
                if (fileName) {
                    console.log('Archivo seleccionado:', fileName);
                }
            });
        }

        // Cerrar modal con tecla ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarModal();
            }
        });

        // Cerrar modal al hacer click fuera
        document.getElementById('confirmModal').addEventListener('click', function(e) {
            if (e.target === this) {
                cerrarModal();
            }
        });
    </script>
</body>
</html>