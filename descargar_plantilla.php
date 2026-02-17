<?php
// ============================================
// DESCARGA DE PLANTILLA EXCEL SIMPLE
// FORMATO: NOMBRE COMPLETO | PLACA
// ============================================

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Crear nuevo archivo Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Títulos simples
$sheet->setCellValue('A1', 'NOMBRE COMPLETO');
$sheet->setCellValue('B1', 'PLACA');

// Datos de ejemplo
$sheet->setCellValue('A2', 'Juan Pérez');
$sheet->setCellValue('B2', 'NPL2575');


// Estilo para los títulos
$styleHeader = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '009A3F'], // Verde RANSA
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
    ],
];

$sheet->getStyle('A1:B1')->applyFromArray($styleHeader);

// Autoajustar ancho de columnas
foreach (range('A', 'B') as $column) {
    $sheet->getColumnDimension($column)->setAutoSize(true);
}

// Configurar headers para descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="plantilla_conductores.xlsx"');
header('Cache-Control: max-age=0');

// Crear el archivo y enviarlo al navegador
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>