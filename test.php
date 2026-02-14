<?php
echo "Buscando PHPExcel en: " . __DIR__ . "/PHPExcel/Classes/PHPExcel.php<br>";
if (file_exists(__DIR__ . "/PHPExcel/Classes/PHPExcel.php")) {
    echo "✅ ¡Encontrado!";
} else {
    echo "❌ No encontrado. Verifica el nombre de la carpeta.";
}