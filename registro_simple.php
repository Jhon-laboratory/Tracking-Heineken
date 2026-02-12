<?php
require_once 'conexion.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = $_POST['nombre'] ?? '';
    $usuario = $_POST['usuario'] ?? '';
    $password = $_POST['password'] ?? '';
    $ciudad = $_POST['ciudad'] ?? '';
    
    if ($nombre && $usuario && $password && $ciudad) {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Hash de la contraseña
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Insertar usuario
        $query = "INSERT INTO DPL.externos.users_tracking 
                  (nombre_completo, usuario, password, ciudad) 
                  VALUES (?, ?, ?, ?)";
        
        $params = array($nombre, $usuario, $password_hash, $ciudad);
        $stmt = sqlsrv_query($conn, $query, $params);
        
        if ($stmt) {
            $mensaje = '<div style="color:green; padding:10px; background:#e8f5e9; border-radius:8px;">✅ Usuario registrado exitosamente</div>';
            sqlsrv_free_stmt($stmt);
        } else {
            $errors = sqlsrv_errors();
            $mensaje = '<div style="color:#c62828; padding:10px; background:#ffebee; border-radius:8px;">❌ Error: ' . $errors[0]['message'] . '</div>';
        }
    } else {
        $mensaje = '<div style="color:#c62828; padding:10px; background:#ffebee; border-radius:8px;">❌ Todos los campos son obligatorios</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Registro Simple - RANSA</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f6f7f8;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        h2 {
            color: #009A3F;
            margin-top: 0;
            text-align: center;
        }
        input, select {
            width: 100%;
            padding: 12px;
            margin: 8px 0 15px;
            border: 2px solid #e5e5e5;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 14px;
        }
        select {
            background: white;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23666' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='6 9 12 15 18 9'/></svg>");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }
        select:focus {
            border-color: #009A3F;
            outline: none;
        }
        button {
            width: 100%;
            padding: 14px;
            background: #009A3F;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }
        button:hover {
            background: #007a32;
        }
        .mensaje {
            margin-bottom: 20px;
        }
        .link {
            text-align: center;
            margin-top: 15px;
        }
        a {
            color: #009A3F;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        label {
            font-weight: 600;
            color: #555;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2>📝 Registrar Usuario</h2>
            
            <div class="mensaje"><?= $mensaje ?></div>
            
            <form method="POST">
                <label>Nombre Completo</label>
                <input type="text" name="nombre" required placeholder="Ej: Juan Pérez">
                
                <label>Usuario</label>
                <input type="text" name="usuario" required placeholder="Ej: jperez">
                
                <label>Contraseña</label>
                <input type="password" name="password" required placeholder="••••••••">
                
                <label>Ciudad</label>
                <select name="ciudad" required>
                    <option value="" disabled selected>-- Selecciona una ciudad --</option>
                    <option value="Guayaquil">🇪🇨 Guayaquil</option>
                    <option value="Manta">🇪🇨 Manta</option>
                    <option value="Quito">🇪🇨 Quito</option>
                    <option value="Machala">🇪🇨 Machala</option>
                </select>
                
                <button type="submit">Registrar Usuario</button>
            </form>
            
            <div class="link">
                <a href="login.php">← Volver al Login</a>
            </div>
        </div>
    </div>
</body>
</html>