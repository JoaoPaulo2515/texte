<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Dompdf\Dompdf;
use Dompdf\Options;

class ExportManager {
    
    public static function exportToExcel($pagamentos, $aluno, $turma, $total_valor, $filtros = []) {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // Configurar cabeçalho da escola
        $sheet->setCellValue('A1', 'ESCOLA ' . strtoupper($_SESSION['escola_nome'] ?? 'SISTEMA DE GESTÃO'));
        $sheet->mergeCells('A1:G1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $sheet->setCellValue('A2', 'RELATÓRIO DE PAGAMENTOS DO ALUNO');
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Informações do aluno
        $sheet->setCellValue('A4', 'DADOS DO ALUNO');
        $sheet->mergeCells('A4:G4');
        $sheet->getStyle('A4')->getFont()->setBold(true);
        $sheet->getStyle('A4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8F5E9');
        
        $sheet->setCellValue('A5', 'Nome:');
        $sheet->setCellValue('B5', $aluno['nome']);
        $sheet->setCellValue('D5', 'Matrícula:');
        $sheet->setCellValue('E5', $aluno['matricula']);
        $sheet->setCellValue('A6', 'Turma:');
        $sheet->setCellValue('B6', $turma['ano'] . 'ª - ' . ($turma['nome'] ?? 'Não atribuída'));
        $sheet->setCellValue('D6', 'Total Pago:');
        $sheet->setCellValue('E6', self::formatCurrency($total_valor));
        
        // Filtros aplicados
        $row = 8;
        if (!empty($filtros)) {
            $sheet->setCellValue('A' . $row, 'FILTROS APLICADOS:');
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);
            $row++;
            $filtros_text = [];
            if (!empty($filtros['ano'])) $filtros_text[] = 'Ano: ' . $filtros['ano'];
            if (!empty($filtros['tipo']) && $filtros['tipo'] != 'todos') $filtros_text[] = 'Tipo: ' . ucfirst($filtros['tipo']);
            $sheet->setCellValue('A' . $row, implode(' | ', $filtros_text));
            $row++;
        }
        
        // Cabeçalho da tabela
        $row += 2;
        $headers = ['Data', 'Tipo', 'Descrição', 'Valor (Kz)', 'Forma de Pagamento', 'Nº Fatura', 'Referência'];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . $row, $header);
            $sheet->getStyle($col . $row)->getFont()->setBold(true);
            $sheet->getStyle($col . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('006B3E');
            $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $col++;
        }
        
        // Dados
        $row++;
        foreach ($pagamentos as $pg) {
            $sheet->setCellValue('A' . $row, date('d/m/Y', strtotime($pg['data_pagamento'])));
            $sheet->setCellValue('B' . $row, self::getTipoLabel($pg['tipo_pagamento']));
            $sheet->setCellValue('C' . $row, $pg['descricao_completa']);
            $sheet->setCellValue('D' . $row, $pg['valor']);
            $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->setCellValue('E' . $row, ucfirst($pg['metodo_pagamento']));
            $sheet->setCellValue('F' . $row, $pg['numero_fatura']);
            $sheet->setCellValue('G' . $row, $pg['numero_referencia'] ?? '-');
            $row++;
        }
        
        // Total
        $sheet->setCellValue('C' . $row, 'TOTAL GERAL:');
        $sheet->getStyle('C' . $row)->getFont()->setBold(true);
        $sheet->setCellValue('D' . $row, $total_valor);
        $sheet->getStyle('D' . $row)->getFont()->setBold(true);
        $sheet->getStyle('D' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->mergeCells('C' . $row . ':E' . $row);
        
        // Aplicar bordas
        $lastRow = $row;
        $styleArray = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];
        $sheet->getStyle('A8:G' . $lastRow)->applyFromArray($styleArray);
        
        // Gerar arquivo
        $writer = new Xlsx($spreadsheet);
        $filename = 'pagamentos_' . date('Ymd_His') . '.xlsx';
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
    }
    
    public static function exportToPDF($pagamentos, $aluno, $turma, $total_valor, $filtros = []) {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        
        $dompdf = new Dompdf($options);
        
        // Preparar HTML
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Relatório de Pagamentos</title>
            <style>
                body { font-family: DejaVu Sans, sans-serif; margin: 20px; }
                .header { text-align: center; margin-bottom: 30px; }
                .header h1 { color: #006B3E; margin: 0; }
                .header h3 { margin: 5px 0; }
                .info-box { 
                    background: #f5f5f5; 
                    padding: 15px; 
                    margin-bottom: 20px; 
                    border-left: 4px solid #006B3E;
                }
                .info-row { margin: 5px 0; }
                .info-label { font-weight: bold; display: inline-block; width: 120px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { 
                    background-color: #006B3E; 
                    color: white; 
                    font-weight: bold;
                }
                .footer { 
                    margin-top: 30px; 
                    text-align: center; 
                    font-size: 12px; 
                    color: #666;
                    border-top: 1px solid #ddd;
                    padding-top: 20px;
                }
                .text-right { text-align: right; }
                .total-row { font-weight: bold; background-color: #f0f0f0; }
                .badge {
                    display: inline-block;
                    padding: 3px 8px;
                    border-radius: 4px;
                    font-size: 11px;
                    font-weight: bold;
                }
                .badge-mensalidade { background-color: #006B3E; color: white; }
                .badge-matricula { background-color: #28a745; color: white; }
                .badge-certificado { background-color: #17a2b8; color: white; }
                .badge-material { background-color: #ffc107; color: #000; }
                .badge-outro { background-color: #6c757d; color: white; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>' . htmlspecialchars($_SESSION['escola_nome'] ?? 'SISTEMA DE GESTÃO ESCOLAR') . '</h1>
                <h3>RELATÓRIO DE PAGAMENTOS DO ALUNO</h3>
                <p>Gerado em: ' . date('d/m/Y H:i:s') . '</p>
            </div>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Aluno:</span>
                    <span>' . htmlspecialchars($aluno['nome']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Matrícula:</span>
                    <span>' . htmlspecialchars($aluno['matricula']) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Turma:</span>
                    <span>' . $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída') . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Pago:</span>
                    <span><strong>' . self::formatCurrency($total_valor) . '</strong></span>
                </div>';
        
        if (!empty($filtros)) {
            $filtros_text = [];
            if (!empty($filtros['ano'])) $filtros_text[] = 'Ano: ' . $filtros['ano'];
            if (!empty($filtros['tipo']) && $filtros['tipo'] != 'todos') $filtros_text[] = 'Tipo: ' . ucfirst($filtros['tipo']);
            $html .= '<div class="info-row" style="margin-top: 10px;">
                        <span class="info-label">Filtros:</span>
                        <span>' . implode(' | ', $filtros_text) . '</span>
                      </div>';
        }
        
        $html .= '</div>';
        
        // Tabela de pagamentos
        $html .= '
            <table>
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Tipo</th>
                        <th>Descrição</th>
                        <th class="text-right">Valor (Kz)</th>
                        <th>Forma de Pagamento</th>
                        <th>Nº Fatura</th>
                    </tr>
                </thead>
                <tbody>';
        
        foreach ($pagamentos as $pg) {
            $html .= '<tr>
                        <td>' . date('d/m/Y', strtotime($pg['data_pagamento'])) . '</td>
                        <td><span class="badge badge-' . $pg['tipo_pagamento'] . '">' . self::getTipoLabel($pg['tipo_pagamento']) . '</span></td>
                        <td>' . htmlspecialchars($pg['descricao_completa']) . '</td>
                        <td class="text-right">' . number_format($pg['valor'], 2, ',', '.') . '</td>
                        <td>' . ucfirst($pg['metodo_pagamento']) . '</td>
                        <td>' . $pg['numero_fatura'] . '</td>
                    </tr>';
        }
        
        $html .= '<tr class="total-row">
                    <td colspan="3" class="text-right"><strong>TOTAL GERAL:</strong></td>
                    <td class="text-right"><strong>' . number_format($total_valor, 2, ',', '.') . ' Kz</strong></td>
                    <td colspan="2"></td>
                  </tr>';
        
        $html .= '
                </tbody>
            </table>
            
            <div class="footer">
                <p>Documento gerado eletronicamente - Sistema de Gestão Escolar</p>
                <p>Este documento é válido como comprovante de histórico de pagamentos</p>
            </div>
        </body>
        </html>';
        
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        
        // Output PDF
        $dompdf->stream('pagamentos_' . date('Ymd_His') . '.pdf', ['Attachment' => true]);
        exit;
    }
    
    private static function getTipoLabel($tipo) {
        $labels = [
            'mensalidade' => 'Mensalidade',
            'matricula' => 'Matrícula',
            'certificado' => 'Certificado',
            'material' => 'Material',
            'outro' => 'Outro'
        ];
        return $labels[$tipo] ?? ucfirst($tipo);
    }
    
    private static function formatCurrency($value) {
        return number_format($value, 2, ',', '.') . ' Kz';
    }
}
?>