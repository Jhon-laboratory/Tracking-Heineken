<?php
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

echo "✅ Autoload cargado correctamente<br>";

$spreadsheet = new Spreadsheet();
echo "✅ Spreadsheet creado correctamente<br>";

$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', 'Test');
echo "✅ Celda escrita correctamente<br>";

echo "<br><strong>PhpSpreadsheet funciona correctamente!</strong>";
?>