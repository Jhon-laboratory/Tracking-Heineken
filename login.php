<?php
session_start();

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['placa'])) {
    header('Location: dashboard.php');
    exit;
}

require_once 'conexion.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $placa = $_POST['placa'] ?? '';
    
    // Limpiar y estandarizar la placa (eliminar guiones, espacios, mayúsculas)
    $placa_limpia = strtoupper(trim($placa));
    $placa_limpia = str_replace(['-', ' ', '.'], '', $placa_limpia);
    
    if (empty($placa_limpia)) {
        $error = 'Por favor ingrese una placa';
    } else {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Fecha actual
        $fecha_actual = date('Y-m-d');
        
        // Buscar la placa en la tabla plantillas_conductores para la fecha actual
        $query = "SELECT id, nombre, placa, fecha_plantilla 
                  FROM DPL.externos.plantillas_conductores 
                  WHERE placa = ? AND fecha_plantilla = ? AND activo = 1";
        
        $params = array($placa_limpia, $fecha_actual);
        $stmt = sqlsrv_query($conn, $query, $params);
        
        if ($stmt === false) {
            $errors = sqlsrv_errors();
            $error = "Error en la consulta";
            error_log(print_r($errors, true));
        } else {
            $conductor = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
            
            if ($conductor) {
                // Login exitoso - guardar datos del conductor en sesión
                $_SESSION['placa'] = $conductor['placa'];
                $_SESSION['nombre_conductor'] = $conductor['nombre'];
                $_SESSION['fecha_ingreso'] = date('Y-m-d H:i:s');
                
                // Liberar recursos
                sqlsrv_free_stmt($stmt);
                
                header('Location: dashboard.php');
                exit;
            } else {
                // Mensaje simple - placa no registrada para hoy
                $error = 'Placa no registrada para la fecha actual';
            }
            
            sqlsrv_free_stmt($stmt);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <title>RANSA - Login por Placa</title>
    <style>
        /* Reset completo */
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
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
            margin: 0;
        }
        
        /* Contenedor que ocupa toda la pantalla */
        .login-container {
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        
        /* Tarjeta que ocupa toda la pantalla */
        .login-card {
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            background: white;
            padding: 40px 30px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            box-shadow: none;
            border-radius: 0;
            animation: none;
            position: relative;
            overflow-y: auto;
        }
        
        /* Línea decorativa superior */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--verde), var(--naranja));
        }
        
        h1 {
            color: var(--verde);
            font-size: 3.5rem;
            text-align: center;
            margin-bottom: 10px;
            letter-spacing: 2px;
            font-weight: 700;
        }
        
        .subtitle {
            text-align: center;
            color: #777;
            font-size: 1rem;
            margin-bottom: 30px;
            border-bottom: 1.5px solid #f0f0f0;
            padding-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 25px;
            max-width: 400px;
            width: 100%;
            margin-left: auto;
            margin-right: auto;
        }
        
        label {
            display: block;
            margin-bottom: 10px;
            color: #555;
            font-weight: 600;
            font-size: 1rem;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 18px 20px;
            border: 2px solid #e5e5e5;
            border-radius: 15px;
            font-size: 1.2rem;
            transition: all 0.2s;
            background: #fafafa;
            text-transform: uppercase;
        }
        
        input[type="text"]:focus {
            border-color: var(--verde);
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(0,154,63,0.1);
        }
        
        input[type="text"]::placeholder {
            text-transform: none;
            font-size: 1rem;
        }
        
        button {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            display: block;
            padding: 18px;
            border: none;
            border-radius: 22px;
            font-size: 1.3rem;
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
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 1rem;
            border-left: 5px solid #c62828;
            display: <?php echo $error ? 'block' : 'none'; ?>;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
        
        .info-text {
            margin-top: 30px;
            text-align: center;
            color: #888;
            font-size: 0.9rem;
            padding-top: 20px;
            border-top: 1.5px solid #f0f0f0;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
        
        .fecha-info {
            text-align: center;
            color: var(--naranja);
            font-size: 1.1rem;
            margin-bottom: 20px;
            font-weight: 600;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
            width: 100%;
        }
        
        /* Para pantallas muy pequeñas */
        @media (max-width: 480px) {
            h1 {
                font-size: 2.8rem;
            }
            
            .login-card {
                padding: 30px 20px;
            }
            
            input[type="text"] {
                padding: 15px 18px;
                font-size: 1.1rem;
            }
            
            button {
                padding: 15px;
                font-size: 1.2rem;
            }
        }
        
        /* Para pantallas muy grandes */
        @media (min-width: 1200px) {
            h1 {
                font-size: 4rem;
            }
            
            .login-card {
                padding: 60px 40px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div style="max-width: 400px; width: 100%; margin: 0 auto;">
                <h1>RANSA</h1>
                <div class="subtitle">Sistema de Rastreo</div>
                
                <div class="fecha-info">
                    <i class="fas fa-calendar-alt"></i> Hoy: <?php echo date('d/m/Y'); ?>
                </div>
                
                <div class="error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label>Placa del vehículo</label>
                        <input type="text" name="placa" placeholder="Ej: ABC1234" value="<?php echo isset($_POST['placa']) ? htmlspecialchars($_POST['placa']) : ''; ?>" required autofocus>
                    </div>
                    
                    <button type="submit">INGRESAR</button>
                    
                    <div class="info-text">
                        Acceso solo para conductores con placa registrada en la fecha actual
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-focus en el campo de placa
        document.querySelector('input[name="placa"]').focus();
        
        // Convertir a mayúsculas mientras escribe
        document.querySelector('input[name="placa"]').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        // Prevenir zoom en inputs en iOS
        document.querySelectorAll('input').forEach(input => {
            input.addEventListener('focus', function() {
                this.style.fontSize = '16px';
            });
        });
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</body>
</html>