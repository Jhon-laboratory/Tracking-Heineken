<?php
session_start();

// Si ya está logueado y tiene el token persistente, redirigir al dashboard
if (isset($_SESSION['user_id']) && isset($_COOKIE['remember_token'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'conexion.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = $_POST['usuario'] ?? '';
    $password = $_POST['password'] ?? '';
    $recordar = isset($_POST['recordar']) ? true : false;
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Buscar usuario
    $query = "SELECT id, nombre_completo, usuario, password, ciudad 
              FROM DPL.externos.users_tracking 
              WHERE usuario = ? AND activo = 1";
    
    $params = array($usuario);
    $stmt = sqlsrv_query($conn, $query, $params);
    
    if ($stmt === false) {
        $errors = sqlsrv_errors();
        $error = "Error en la consulta";
        error_log(print_r($errors, true));
    } else {
        $user = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
        
        if ($user) {
            // Verificar contraseña
            if (password_verify($password, $user['password'])) {
                // Login exitoso
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_nombre'] = $user['nombre_completo'];
                $_SESSION['user_usuario'] = $user['usuario'];
                $_SESSION['user_ciudad'] = $user['ciudad'];
                $_SESSION['login_time'] = time();
                
                // Actualizar último acceso
                $update = "UPDATE DPL.externos.users_tracking SET ultimo_acceso = GETDATE() WHERE id = ?";
                $params_update = array($user['id']);
                sqlsrv_query($conn, $update, $params_update);
                
                // Generar token persistente (para "loguearse una sola vez")
                if ($recordar) {
                    $token = bin2hex(random_bytes(32));
                    $expiry_days = 30; // 30 días
                    
                    // Guardar token en base de datos
                    $token_sql = "UPDATE DPL.externos.users_tracking 
                                 SET remember_token = ?, 
                                     token_expiry = DATEADD(day, ?, GETDATE())
                                 WHERE id = ?";
                    $params_token = array($token, $expiry_days, $user['id']);
                    sqlsrv_query($conn, $token_sql, $params_token);
                    
                    // Cookie persistente (30 días)
                    setcookie('remember_token', $token, time() + (86400 * 30), '/', '', false, true);
                    setcookie('remember_user', $user['id'], time() + (86400 * 30), '/', '', false, true);
                }
                
                // Liberar recursos
                sqlsrv_free_stmt($stmt);
                
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Contraseña incorrecta';
            }
        } else {
            $error = 'Usuario no encontrado o inactivo';
        }
        
        sqlsrv_free_stmt($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>RANSA - Login</title>
    <style>
        /* Mismos estilos que te di anteriormente */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --verde: #009A3F;
            --naranja: #F39200;
            --gris: #9D9D9C;
            --fondo: #f6f7f8;
        }
        
        body {
            font-family: 'Segoe UI', sans-serif;
            background: var(--fondo);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            height: 100dvh;
            width: 100vw;
            width: 100dvw;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            padding: 20px;
        }
        
        .login-card {
            background: white;
            border-radius: 28px;
            padding: 30px 25px;
            box-shadow: 0 15px 30px rgba(0,0,0,.06);
            animation: pop .4s ease;
        }
        
        h1 {
            color: var(--verde);
            font-size: 2.5rem;
            text-align: center;
            margin-bottom: 5px;
            letter-spacing: 1.5px;
        }
        
        .subtitle {
            text-align: center;
            color: #777;
            font-size: 0.9rem;
            margin-bottom: 30px;
            border-bottom: 1.5px solid #f0f0f0;
            padding-bottom: 15px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #555;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 15px 18px;
            border: 2px solid #e5e5e5;
            border-radius: 15px;
            font-size: 1rem;
            transition: all 0.2s;
            background: #fafafa;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            border-color: var(--verde);
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(0,154,63,0.1);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin: 20px 0 25px;
        }
        
        input[type="checkbox"] {
            width: 22px;
            height: 22px;
            margin-right: 12px;
            accent-color: var(--verde);
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
            font-weight: normal;
        }
        
        button {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 22px;
            font-size: 1.2rem;
            font-weight: 800;
            background: var(--verde);
            color: white;
            cursor: pointer;
            transition: .2s;
            letter-spacing: 1px;
            box-shadow: 0 4px 0 #006e2c;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        button:hover {
            background: #007a32;
            transform: translateY(-3px);
            box-shadow: 0 7px 0 #006e2c, 0 15px 25px rgba(0,122,50,.25);
        }
        
        button:active {
            transform: translateY(4px);
            box-shadow: 0 2px 0 #006e2c;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 12px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 0.95rem;
            border-left: 5px solid #c62828;
            display: <?= $error ? 'block' : 'none' ?>;
        }
        
        .info-text {
            margin-top: 25px;
            text-align: center;
            color: #888;
            font-size: 0.8rem;
            padding-top: 15px;
            border-top: 1.5px solid #f0f0f0;
        }
        
        @keyframes pop {
            from{transform: scale(.95); opacity: .7}
            to{transform: scale(1); opacity: 1}
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <h1>RANSA</h1>
            <div class="subtitle">Sistema de Rastreo</div>
            
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Usuario</label>
                    <input type="text" name="usuario" placeholder="Ingresa tu usuario" required autofocus>
                </div>
                
                <div class="form-group">
                    <label>Contraseña</label>
                    <input type="password" name="password" placeholder="Ingresa tu contraseña" required>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="recordar" name="recordar" checked>
                    <label for="recordar">Mantenerme conectado (no pedir login nuevamente)</label>
                </div>
                
                <button type="submit">INGRESAR</button>
                
                <div class="info-text">
                    Acceso solo para personal autorizado
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Auto-focus en el campo de usuario
        document.querySelector('input[name="usuario"]').focus();
        
        // Prevenir zoom en inputs en iOS
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('focus', function() {
                this.style.fontSize = '16px';
            });
        });
    </script>
</body>
</html>