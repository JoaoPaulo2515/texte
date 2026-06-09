<?php
// escola/pedagogico/gerar_comprovativo_matricula.php - Gerar Comprovativo de Matrícula PDF

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$matricula_id = isset($_GET['matricula_id']) ? (int)$_GET['matricula_id'] : 0;

// Se não veio matricula_id, tentar buscar por estudante_id
if ($matricula_id <= 0) {
    $estudante_id = isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : 0;
    if ($estudante_id > 0) {
        $sql_busca_matricula = "
            SELECT id FROM matriculas 
            WHERE estudante_id = :estudante_id 
            AND status = 'ativa' 
            LIMIT 1
        ";
        $stmt_busca = $conn->prepare($sql_busca_matricula);
        $stmt_busca->execute([':estudante_id' => $estudante_id]);
        $matricula = $stmt_busca->fetch(PDO::FETCH_ASSOC);
        if ($matricula) {
            $matricula_id = $matricula['id'];
        }
    }
}

if ($matricula_id <= 0) {
    die('Matrícula não encontrada. Verifique se o aluno está matriculado.');
}

// ============================================
// BUSCAR DADOS DO ALUNO E MATRÍCULA
// ============================================
$sql_dados = "
    SELECT 
        e.id as aluno_id,
        e.nome as aluno_nome,
        e.matricula as aluno_matricula,
        e.bi,
        DATE_FORMAT(e.data_nascimento, '%d/%m/%Y') as data_nascimento,
        e.genero,
        e.endereco,
        e.telefone,
        e.email,
        e.pai_nome,
        e.mae_nome,
        e.encarregado_nome,
        e.encarregado_telefone,
        e.encarregado_email,
        m.id as matricula_id,
        m.numero_processo,
        DATE_FORMAT(m.data_matricula, '%d/%m/%Y') as data_matricula,
        m.ano_letivo,
        m.status as matricula_status,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno,
        t.sala,
        esc.nome as escola_nome,
        esc.endereco as escola_endereco,
        esc.telefone as escola_telefone,
        esc.email as escola_email,
        esc.nif as escola_nif,
        esc.logo as escola_logo
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    INNER JOIN turmas t ON t.id = m.turma_id
    INNER JOIN escolas esc ON esc.id = t.escola_id
    WHERE m.id = :matricula_id
";
$stmt_dados = $conn->prepare($sql_dados);
$stmt_dados->execute([':matricula_id' => $matricula_id]);
$dados = $stmt_dados->fetch(PDO::FETCH_ASSOC);

if (!$dados) {
    $estudante_id = isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : 0;
    if ($estudante_id > 0) {
        $sql_dados2 = "
            SELECT 
                e.id as aluno_id,
                e.nome as aluno_nome,
                e.matricula as aluno_matricula,
                e.bi,
                DATE_FORMAT(e.data_nascimento, '%d/%m/%Y') as data_nascimento,
                e.genero,
                e.endereco,
                e.telefone,
                e.email,
                e.pai_nome,
                e.mae_nome,
                e.encarregado_nome,
                e.encarregado_telefone,
                e.encarregado_email,
                NULL as matricula_id,
                e.numero_processo,
                NULL as data_matricula,
                '' as ano_letivo,
                'pendente' as matricula_status,
                NULL as turma_id,
                '' as turma_nome,
                0 as turma_ano,
                '' as turno,
                '' as sala,
                esc.nome as escola_nome,
                esc.endereco as escola_endereco,
                esc.telefone as escola_telefone,
                esc.email as escola_email,
                esc.nif as escola_nif,
                esc.logo as escola_logo
            FROM estudantes e
            INNER JOIN escolas esc ON esc.id = e.escola_id
            WHERE e.id = :estudante_id
        ";
        $stmt_dados2 = $conn->prepare($sql_dados2);
        $stmt_dados2->execute([':estudante_id' => $estudante_id]);
        $dados = $stmt_dados2->fetch(PDO::FETCH_ASSOC);
        
        if (!$dados) {
            die('Matrícula não encontrada.');
        }
    } else {
        die('Matrícula não encontrada.');
    }
}

// Buscar ano letivo
$ano_letivo_ano = $dados['ano_letivo'] ?? date('Y');

// Buscar pagamentos obrigatórios
$sql_pagamentos = "
    SELECT 
        po.*,
        tp.nome as tipo_nome,
        tp.descricao as tipo_categoria
    FROM pagamentos_obrigatorios po
    INNER JOIN tipos_pagamento tp ON tp.id = po.tipo_pagamento_id
    WHERE po.escola_id = :escola_id
    AND po.ano_letivo_id = :ano_letivo_id
    AND po.ativo = 1
    ORDER BY po.data_vencimento ASC
";
$stmt_pagamentos = $conn->prepare($sql_pagamentos);
$stmt_pagamentos->execute([
    ':escola_id' => $escola_id,
    ':ano_letivo_id' => 1
]);
$pagamentos_obrigatorios = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);

// Calcular valores
$total_matricula = 0;
$total_mensalidades = 0;
$total_taxas = 0;
$valores_inseridos = [];

foreach ($pagamentos_obrigatorios as $pagamento) {
    $valor = floatval($pagamento['valor']);
    $tipo_categoria = $pagamento['tipo_categoria'];
    $tipo_nome = $pagamento['tipo_nome'];
    
    if ($tipo_categoria == 'mensalidade') {
        $total_mensalidades += $valor;
    } elseif ($tipo_categoria == 'matricula') {
        $total_matricula += $valor;
    } else {
        $total_taxas += $valor;
    }
    
    $valores_inseridos[] = [
        'tipo' => $tipo_nome,
        'categoria' => $tipo_categoria,
        'valor' => $valor,
        'data_vencimento' => $pagamento['data_vencimento']
    ];
}

$total_geral = $total_matricula + $total_mensalidades + $total_taxas;
$desconto_vista = $total_geral * 0.10;
$total_com_desconto = $total_geral - $desconto_vista;

// Gerar código de autenticação
$codigo_autenticacao = strtoupper(substr(md5(($dados['matricula_id'] ?? $dados['aluno_id']) . date('Ymd')), 0, 16));

// Carregar Dompdf
require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$html = '
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <title>Comprovativo de Matrícula - ' . htmlspecialchars($dados['aluno_nome']) . '</title>
    <style>
        @page { 
            margin: 2cm 1.5cm;
            size: A4;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: "DejaVu Sans", "Arial", sans-serif; 
            font-size: 9pt; 
            color: #2c3e50; 
            line-height: 1.4;
            background: white;
        }
        
        /* Container principal */
        .comprovativo {
            max-width: 100%;
        }
        
        /* Cabeçalho com borda decorativa */
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid;
            border-image: linear-gradient(90deg, #1e5799, #2ecc71, #1e5799) 1;
            border-bottom-style: solid;
            border-bottom-width: 3px;
        }
        
        .escola-nome { 
            font-size: 18pt; 
            font-weight: 800; 
            color: #1e5799; 
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 5px;
        }
        
        .escola-info { 
            font-size: 7.5pt; 
            color: #7f8c8d; 
            line-height: 1.3;
        }
        
        /* Título principal */
        .titulo-principal {
            text-align: center;
            margin: 15px 0 20px 0;
            position: relative;
        }
        
        .titulo-principal h1 {
            font-size: 16pt;
            color: #2c3e50;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 2px;
        }
        
        .status-badge {
            display: inline-block;
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 3px 12px;
            border-radius: 20px;
            font-size: 8pt;
            font-weight: bold;
            margin-left: 10px;
        }
        
        /* Layout de duas colunas */
        .two-columns {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .column {
            flex: 1;
        }
        
        /* Cards estilizados */
        .card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
            border: 1px solid #e8ecef;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 10px 15px;
            font-weight: bold;
            font-size: 11pt;
            letter-spacing: 0.5px;
        }
        
        .card-header i {
            margin-right: 8px;
        }
        
        .card-body {
            padding: 15px;
        }
        
        /* Info rows */
        .info-row {
            display: flex;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px dashed #ecf0f1;
        }
        
        .info-row:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }
        
        .info-label {
            width: 38%;
            font-weight: 600;
            color: #7f8c8d;
            font-size: 8.5pt;
        }
        
        .info-value {
            width: 62%;
            color: #2c3e50;
            font-size: 8.5pt;
            font-weight: 500;
        }
        
        .info-value strong {
            color: #1e5799;
        }
        
        /* Seção de resumo financeiro */
        .resumo-financeiro {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            color: white;
        }
        
        .resumo-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        
        .resumo-item {
            text-align: center;
        }
        
        .resumo-label {
            font-size: 7.5pt;
            opacity: 0.9;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        
        .resumo-valor {
            font-size: 13pt;
            font-weight: bold;
        }
        
        /* Tabela de valores */
        .section-title {
            font-size: 11pt;
            font-weight: bold;
            color: #1e5799;
            margin: 15px 0 10px 0;
            padding-left: 10px;
            border-left: 4px solid #2ecc71;
        }
        
        .table-valores {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
            font-size: 8pt;
        }
        
        .table-valores th {
            background: #ecf0f1;
            color: #2c3e50;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #1e5799;
        }
        
        .table-valores td {
            padding: 8px;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .table-valores tr:hover {
            background: #f8f9fa;
        }
        
        .total-row {
            background: #e8f5e9;
            font-weight: bold;
        }
        
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        /* Destaque de pagamento */
        .destaque {
            background: #fff9e6;
            border-left: 4px solid #f39c12;
            padding: 12px 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 8pt;
        }
        
        .destaque strong {
            color: #e67e22;
        }
        
        /* Código de autenticação */
        .codigo-box {
            background: #f8f9fa;
            border: 2px dashed #1e5799;
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            margin: 15px 0;
        }
        
        .codigo-label {
            font-size: 7pt;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .codigo-valor {
            font-family: "Courier New", monospace;
            font-size: 14pt;
            font-weight: bold;
            color: #1e5799;
            letter-spacing: 2px;
            margin-top: 5px;
        }
        
        /* Instruções */
        .instrucoes {
            background: #e8f5e9;
            border-radius: 8px;
            padding: 12px 15px;
            margin: 15px 0;
            font-size: 7.5pt;
        }
        
        .instrucoes strong {
            color: #27ae60;
        }
        
        /* Assinaturas */
        .assinaturas {
            display: flex;
            justify-content: space-between;
            margin: 25px 0 20px 0;
            gap: 40px;
        }
        
        .assinatura-item {
            flex: 1;
            text-align: center;
        }
        
        .assinatura-linha {
            border-top: 1px solid #2c3e50;
            margin-top: 25px;
            padding-top: 8px;
            font-size: 8pt;
            font-weight: 500;
        }
        
        .assinatura-cargo {
            font-size: 7pt;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        /* Rodapé */
        .footer {
            text-align: center;
            font-size: 6.5pt;
            color: #95a5a6;
            border-top: 1px solid #ecf0f1;
            padding-top: 12px;
            margin-top: 10px;
        }
        
        /* Divisória elegante */
        .divider {
            height: 2px;
            background: linear-gradient(90deg, transparent, #1e5799, #2ecc71, #1e5799, transparent);
            margin: 15px 0;
        }
        
        /* Responsivo */
        @media (max-width: 600px) {
            .two-columns {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="comprovativo">
    <!-- Cabeçalho -->
    <div class="header">
        <div class="escola-nome">' . strtoupper(htmlspecialchars($dados['escola_nome'])) . '</div>
        <div class="escola-info">
            ' . htmlspecialchars($dados['escola_endereco']) . '<br>
            📞 ' . htmlspecialchars($dados['escola_telefone']) . ' | ✉ ' . htmlspecialchars($dados['escola_email']) . ' | 📄 NIF: ' . htmlspecialchars($dados['escola_nif']) . '
        </div>
    </div>

    <!-- Título -->
    <div class="titulo-principal">
        <h1>COMPROVATIVO DE MATRÍCULA <span class="status-badge">' . ($dados['matricula_status'] == 'ativa' ? '✓ ATIVA' : '⏳ PENDENTE') . '</span></h1>
    </div>

    <!-- Dados da Matrícula (Card único) -->
    <div class="card">
        <div class="card-header">
            📋 INFORMAÇÕES DA MATRÍCULA
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 30px;">
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Nº de Processo:</div>
                        <div class="info-value"><strong>' . htmlspecialchars($dados['numero_processo'] ?? '-') . '</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data da Matrícula:</div>
                        <div class="info-value">' . ($dados['data_matricula'] ?? date('d/m/Y')) . '</div>
                    </div>
                </div>
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Ano Letivo:</div>
                        <div class="info-value"><strong>' . htmlspecialchars($ano_letivo_ano) . '</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Código Autenticação:</div>
                        <div class="info-value"><strong>' . $codigo_autenticacao . '</strong></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Layout de duas colunas: Turma e Aluno lado a lado -->
    <div class="two-columns">
        <!-- Coluna Esquerda: Dados da Turma -->
        <div class="column">
            <div class="card">
                <div class="card-header">
                    🏫 DADOS DA TURMA
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">Turma:</div>
                        <div class="info-value"><strong>' . ($dados['turma_ano'] ? $dados['turma_ano'] . 'ª ' : '') . htmlspecialchars($dados['turma_nome'] ?? 'Não atribuída') . '</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Turno:</div>
                        <div class="info-value">' . ucfirst($dados['turno'] ?? '-') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Sala:</div>
                        <div class="info-value">' . ($dados['sala'] ?? 'Não definida') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Ano Escolar:</div>
                        <div class="info-value">' . ($dados['turma_ano'] ? $dados['turma_ano'] . 'º Ano' : '-') . '</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna Direita: Dados do Aluno -->
        <div class="column">
            <div class="card">
                <div class="card-header">
                    👨‍🎓 DADOS DO ALUNO
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <div class="info-label">Nome Completo:</div>
                        <div class="info-value"><strong>' . strtoupper(htmlspecialchars($dados['aluno_nome'])) . '</strong></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Data Nascimento:</div>
                        <div class="info-value">' . ($dados['data_nascimento'] ?? '-') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">BI / Documento:</div>
                        <div class="info-value">' . htmlspecialchars($dados['bi'] ?? '---') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Género:</div>
                        <div class="info-value">' . ($dados['genero'] == 'M' ? 'Masculino' : ($dados['genero'] == 'F' ? 'Feminino' : '-')) . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Telefone:</div>
                        <div class="info-value">' . htmlspecialchars($dados['telefone'] ?? '---') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">' . htmlspecialchars($dados['email'] ?? '---') . '</div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Endereço:</div>
                        <div class="info-value">' . htmlspecialchars($dados['endereco'] ?? '---') . '</div>
                    </div>
                </div>
            </div>
        </div>
    </div>';

// Só mostrar encarregado se existir
if (!empty($dados['encarregado_nome'])) {
    $html .= '
    <!-- Encarregado de Educação (full width) -->
    <div class="card">
        <div class="card-header">
            👨‍👩‍👧 ENCARREGADO DE EDUCAÇÃO
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 30px;">
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Nome:</div>
                        <div class="info-value"><strong>' . htmlspecialchars($dados['encarregado_nome']) . '</strong></div>
                    </div>
                </div>
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Telefone:</div>
                        <div class="info-value">' . htmlspecialchars($dados['encarregado_telefone'] ?? '---') . '</div>
                    </div>
                </div>
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Email:</div>
                        <div class="info-value">' . htmlspecialchars($dados['encarregado_email'] ?? '---') . '</div>
                    </div>
                </div>
            </div>
        </div>
    </div>';
}

$html .= '
    <div class="divider"></div>

    <!-- Resumo Financeiro -->
    <div class="resumo-financeiro">
        <div class="resumo-grid">
            <div class="resumo-item">
                <div class="resumo-label">Matrícula</div>
                <div class="resumo-valor">' . number_format($total_matricula, 2, ',', '.') . ' Kz</div>
            </div>
            <div class="resumo-item">
                <div class="resumo-label">Mensalidades (10x)</div>
                <div class="resumo-valor">' . number_format($total_mensalidades, 2, ',', '.') . ' Kz</div>
            </div>
            <div class="resumo-item">
                <div class="resumo-label">Taxas</div>
                <div class="resumo-valor">' . number_format($total_taxas, 2, ',', '.') . ' Kz</div>
            </div>
            <div class="resumo-item">
                <div class="resumo-label">TOTAL GERAL</div>
                <div class="resumo-valor">' . number_format($total_geral, 2, ',', '.') . ' Kz</div>
            </div>
        </div>
    </div>';

// Mostrar tabela de valores apenas se houver itens
if (!empty($valores_inseridos)) {
    $html .= '
    <div class="section-title">📊 DISCRIMINAÇÃO DOS VALORES</div>
    <table class="table-valores">
        <thead>
            <tr><th>Descrição</th><th>Valor (Kz)</th><th>Data Vencimento</th></tr>
        </thead>
        <tbody>';
    
    foreach ($valores_inseridos as $valor) {
        $html .= '
            <tr>
                <td>' . htmlspecialchars($valor['tipo']) . '</td>
                <td class="text-right">' . number_format($valor['valor'], 2, ',', '.') . '</td>
                <td class="text-center">' . date('d/m/Y', strtotime($valor['data_vencimento'])) . '</td>
            </tr>';
    }
    
    $html .= '
            <tr class="total-row">
                <td><strong>TOTAL</strong></td>
                <td class="text-right"><strong>' . number_format($total_geral, 2, ',', '.') . ' Kz</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>';
}

$html .= '
    <!-- Opções de Pagamento -->
    <div class="destaque">
        <strong>💳 FORMAS DE PAGAMENTO</strong><br>
        • <strong>Pagamento à vista:</strong> ' . number_format($total_com_desconto, 2, ',', '.') . ' Kz 
        <span style="color: #27ae60;">(Economia de ' . number_format($desconto_vista, 2, ',', '.') . ' Kz - 10% desconto)</span><br>
        • <strong>Pagamento parcelado:</strong> ' . ($total_mensalidades > 0 ? number_format($total_mensalidades / 10, 2, ',', '.') . ' Kz/mês (10 parcelas)' : 'Não aplicável') . '<br>
        • <strong>Multa por atraso:</strong> 2% ao mês sobre o valor em atraso
    </div>

    <!-- Acesso ao Portal -->
    <div class="card">
        <div class="card-header">
            🌐 ACESSO AO PORTAL DO ALUNO
        </div>
        <div class="card-body">
            <div style="display: flex; gap: 30px;">
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Usuário:</div>
                        <div class="info-value"><strong>' . htmlspecialchars($dados['numero_processo'] ?? $dados['aluno_matricula'] ?? '-') . '</strong></div>
                    </div>
                </div>
                <div style="flex: 1;">
                    <div class="info-row">
                        <div class="info-label">Senha Temporária:</div>
                        <div class="info-value"><strong>' . htmlspecialchars($dados['bi'] ?? $dados['numero_processo'] ?? 'alterar') . '</strong></div>
                    </div>
                </div>
                <div style="flex: 2;">
                    <div class="info-row">
                        <div class="info-label">Link de Acesso:</div>
                        <div class="info-value" style="font-size: 7pt;">' . $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/sige_Plataforma/escola/aluno/login.php</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Instruções -->
    <div class="instrucoes">
        <strong>📌 INSTRUÇÕES IMPORTANTES</strong><br>
        1. Guarde este comprovativo para fins de consulta e validação da matrícula.<br>
        2. O código de autenticação é pessoal e intransferível, utilizado para validação do documento.<br>
        3. Acesse o portal do aluno com o usuário e senha fornecidos para acompanhamento escolar.<br>
        4. Recomendamos alterar a senha no primeiro acesso ao sistema.<br>
        5. Em caso de dúvidas ou necessidade de informações adicionais, contacte a secretaria da escola.
    </div>

    <!-- Assinaturas -->
    <div class="assinaturas">
        <div class="assinatura-item">
            <div class="assinatura-linha">_________________________</div>
            <div class="assinatura-cargo">Secretaria Escolar</div>
        </div>
        <div class="assinatura-item">
            <div class="assinatura-linha">_________________________</div>
            <div class="assinatura-cargo">Direção da Escola</div>
        </div>
    </div>

    <!-- Rodapé -->
    <div class="footer">
        Documento emitido eletronicamente por SIGE Angola - Sistema Integrado de Gestão Escolar<br>
        Emissão: ' . date('d/m/Y \à\s H:i:s') . ' | Documento válido em todo território nacional
    </div>
</div>
</body>
</html>';

// Gerar PDF
try {
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $nome_arquivo = 'comprovativo_matricula_' . ($dados['numero_processo'] ?? $dados['aluno_id']) . '_' . date('Ymd') . '.pdf';
    
    if (ob_get_level()) ob_end_clean();
    $dompdf->stream($nome_arquivo, ['Attachment' => false]);
    exit;
    
} catch (Exception $e) {
    die('Erro ao gerar PDF: ' . $e->getMessage());
}
?>