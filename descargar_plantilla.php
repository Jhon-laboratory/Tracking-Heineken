<?php
declare(strict_types=1);

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
$sheet->setCellValue('A1', 'NOMBRE COMPLETO');
$sheet->setCellValue('B1', 'PLACA');

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
// EJEMPLO ÚNICO
// ============================================
$sheet->setCellValue('A2', 'Juan Pérez');
$sheet->setCellValue('B2', 'ABC1234');

// Estilo para celda de datos
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

$sheet->getStyle('A2:B2')->applyFromArray($dataStyle);

// ============================================
// FORMATO ESPECIAL PARA COLUMNA DE PLACAS (TEXTO)
// ============================================
$sheet->getStyle('B2')
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
$nombreArchivo = 'Plantilla_Conductores_RANSA.xlsx';

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