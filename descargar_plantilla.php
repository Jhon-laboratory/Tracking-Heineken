<?php
// ============================================
// DESCARGAR PLANTILLA EXCEL PARA CONDUCTORES
// ============================================

// Cargar autoload de Composer para PhpSpreadsheet
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

// Crear nuevo objeto Spreadsheet
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Configurar título de la hoja
$sheet->setTitle('Plantilla Conductores');

// ============================================
// ENCABEZADOS
// ============================================
$encabezados = [
    'A1' => 'NOMBRE COMPLETO',
    'B1' => 'PLACA'
];

foreach ($encabezados as $celda => $texto) {
    $sheet->setCellValue($celda, $texto);
}

// Estilo para encabezados
$headerStyle = [
    'font' => [
        'bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12,
    ],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '009A3F'], // Verde corporativo
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
        ],
    ],
];

$sheet->getStyle('A1:B1')->applyFromArray($headerStyle);

// ============================================
// EJEMPLOS DE DATOS
// ============================================
$ejemplos = [
    ['Juan Pérez', 'ABC1234'],

];

$fila = 2;
foreach ($ejemplos as $ejemplo) {
    $sheet->setCellValue('A' . $fila, $ejemplo[0]);
    $sheet->setCellValue('B' . $fila, $ejemplo[1]);
    $fila++;
}

// Estilo para celdas de datos
$dataStyle = [
    'alignment' => [
        'vertical' => Alignment::VERTICAL_CENTER,
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC'],
        ],
    ],
];

$sheet->getStyle('A2:B' . ($fila - 1))->applyFromArray($dataStyle);

// ============================================
// FORMATO ESPECIAL PARA COLUMNA DE PLACAS (TEXTO)
// ============================================
$sheet->getStyle('B2:B' . ($fila - 1))
      ->getNumberFormat()
      ->setFormatCode('@'); // Formato texto

// ============================================
// ANCHO DE COLUMNAS
// ============================================
$sheet->getColumnDimension('A')->setWidth(40); // Nombre
$sheet->getColumnDimension('B')->setWidth(20); // Placa

// ============================================
// CONFIGURAR DESCARGA
// ============================================
$nombreArchivo = 'Plantilla_Conductores_RANSA_' . date('Y-m-d') . '.xlsx';

// Configurar headers para descarga
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
header('Cache-Control: max-age=0');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
header('Cache-Control: cache, must-revalidate');
header('Pragma: public');

// Crear el archivo y enviarlo al navegador
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>