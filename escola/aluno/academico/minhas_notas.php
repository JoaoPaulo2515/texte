<?php
// escola/aluno/academico/minhas_notas.php - Minhas Notas do Aluno (Completo com Controle Financeiro)

require_once __DIR__ . '/../../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// ============================================
// VERIFICAÇÃO FINANCEIRA
// ============================================

// 1. Verificar se o boletim foi pago
$boletim_pago = false;
try {
    $sql_tipo = "SELECT id FROM tipos_pagamento WHERE nome LIKE '%Boletim%' LIMIT 1";
    $stmt_tipo = $conn->prepare($sql_tipo);
    $stmt_tipo->execute();
    $tipo = $stmt_tipo->fetch(PDO::FETCH_ASSOC);
    
    if ($tipo) {
        $sql_boletim = "SELECT id FROM outros_pagamentos 
                        WHERE escola_id = :escola_id 
                        AND aluno_id = :aluno_id 
                        AND tipo_pagamento_id = :tipo_id
                        AND status = 'pago'
                        LIMIT 1";
        $stmt_boletim = $conn->prepare($sql_boletim);
        $stmt_boletim->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':tipo_id' => $tipo['id']
        ]);
        $boletim_pago = $stmt_boletim->fetch();
    }
} catch (Exception $e) {
    $boletim_pago = false;
}

// 2. Verificar dívidas em mensalidades
$dividas_mensalidades = 0;
$valor_divida_mensalidades = 0;

try {
    $sql_mensalidades = "SELECT COUNT(*) as total, COALESCE(SUM(valor_total - valor_pago), 0) as valor 
                         FROM mensalidades 
                         WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                         AND status IN ('pendente', 'parcial','atrasado')";
    $stmt_mensalidades = $conn->prepare($sql_mensalidades);
    $stmt_mensalidades->execute([':escola_id' => $escola_id, ':aluno_id' => $aluno_id]);
    $mens_result = $stmt_mensalidades->fetch(PDO::FETCH_ASSOC);
    $dividas_mensalidades = $mens_result['total'] ?? 0;
    $valor_divida_mensalidades = $mens_result['valor'] ?? 0;
    
} catch (Exception $e) {
    $dividas_mensalidades = 0;
    $valor_divida_mensalidades = 0;
}

// 3. Verificar dívidas em otros pagamentos
$dividas_outros_pagamentos = 0;
$valor_divida_outros_pagamentos = 0;

try {
    $sql_outros_pagamentos = "SELECT COUNT(*) as total, COALESCE(SUM(valor_total - valor_pago), 0) as valor 
                         FROM outros_pagamentos 
                         WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                         AND status IN ('pendente', 'parcial')";
    $stmt_outros_pagamentos = $conn->prepare($sql_outros_pagamentos);
    $stmt_outros_pagamentos->execute([':escola_id' => $escola_id, ':aluno_id' => $aluno_id]);
    $mens_result = $stmt_outros_pagamentos->fetch(PDO::FETCH_ASSOC);
    $dividas_outros_pagamentos = $mens_result['total'] ?? 0;
    $valor_divida_outros_pagamentos = $mens_result['valor'] ?? 0;
    
} catch (Exception $e) {
    $dividas_outros_pagamentos = 0;
    $valor_divida_outros_pagamentos = 0;
}

$tem_dividas = (($dividas_mensalidades > 0) || ($dividas_outros_pagamentos > 0));
$valor_total_divida = ($valor_divida_mensalidades + $valor_divida_outros_pagamentos);

// 4. Definir status de visualização
$visualizacao_total = false;
$visualizacao_parcial = false;
$bloqueio_total = false;

if (!$boletim_pago) {
    $bloqueio_total = true;
    $visualizacao_parcial = false;
    $visualizacao_total = false;
    $mensagem_bloqueio = "O boletim não foi pago. Efetue o pagamento para acessar suas notas.";
} elseif ($boletim_pago && !$tem_dividas) {
    $visualizacao_total = true;
    $visualizacao_parcial = false;
    $bloqueio_total = false;
    $mensagem_liberacao = "Boletim liberado! Você tem acesso a todas as notas.";
} elseif ($boletim_pago && $tem_dividas) {
    $visualizacao_parcial = true;
    $visualizacao_total = false;
    $bloqueio_total = false;
    $mensagem_parcial = "Boletim pago, mas você possui dívidas pendentes de <strong>" . number_format($valor_total_divida, 2, ',', '.') . " Kz</strong>. Regularize suas dívidas para acessar as notas finais.";
}

$boletim_liberado = $visualizacao_total;
$boletim_parcial = $visualizacao_parcial;
$boletim_bloqueado = $bloqueio_total;
$tota_divida = $valor_total_divida;

$titulo_pagina = 'Minhas Notas';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Determinar o ciclo
$ciclo = 2;
$nota_maxima = 20;
$nota_minima_aprovacao = 10;

if ($turma && $turma['ano'] <= 6) {
    $ciclo = 1;
    $nota_maxima = 10;
    $nota_minima_aprovacao = 5;
}

// Filtros// Filtros - Buscar anos letivos disponíveis relacionando com tabela ano_letivo
$sql_anos = "SELECT DISTINCT al.id, al.ano 
             FROM notas n
             INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
             WHERE n.estudante_id = :aluno_id 
             ORDER BY al.ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_disponiveis)) {
    // Buscar o ano letivo ativo da escola
    $sql_ano_ativo = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id AND is_ativo = 1 LIMIT 1";
    $stmt_ano_ativo = $conn->prepare($sql_ano_ativo);
    $stmt_ano_ativo->execute([':escola_id' => $escola_id]);
    $ano_ativo = $stmt_ano_ativo->fetch(PDO::FETCH_ASSOC);
    
    if ($ano_ativo) {
        $anos_disponiveis = [$ano_ativo];
    } else {
        // Fallback: usar o ano atual
        $anos_disponiveis = [['id' => date('Y'), 'ano' => date('Y')]];
    }
}

// Ano selecionado
$ano_letivo_selecionado_id = isset($_GET['ano_id']) ? (int)$_GET['ano_id'] : $anos_disponiveis[0]['id'];
$ano_letivo_selecionado_valor = '';

foreach ($anos_disponiveis as $a) {
    if ($a['id'] == $ano_letivo_selecionado_id) {
        $ano_letivo_selecionado_valor = $a['ano'];
        break;
    }
}

// Buscar notas - usando o ano_letivo_id
$sql_notas = "SELECT n.*, d.nome as disciplina, d.codigo, d.carga_horaria
              FROM notas n
              JOIN disciplinas d ON d.id = n.disciplina_id
              WHERE n.estudante_id = :aluno_id AND n.ano_letivo_id = :ano_id
              ORDER BY d.nome ASC, n.bimestre ASC";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':aluno_id' => $aluno_id, ':ano_id' => $ano_letivo_selecionado_id]);
$notas_raw = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// Organizar notas
$disciplinas = [];

foreach ($notas_raw as $nota) {
    $disciplina = $nota['disciplina'];
    $bimestre = $nota['bimestre'] ?? 1;
    
    if ($bimestre > 3) continue;
    
    if (!isset($disciplinas[$disciplina])) {
        $disciplinas[$disciplina] = [
            'codigo' => $nota['codigo'],
            'b1_mac' => null,
            'b1_npt' => null,
            'b1_media' => null,
            'b2_mac' => null,
            'b2_npt' => null,
            'b2_media' => null,
            'b3_mac' => null,
            'b3_npt' => null,
            'b3_media' => null,
            'media_parcial' => 0,
            'exame_normal' => null,
            'exame_recurso' => null,
            'exame_especial' => null,
            'exame_oral' => null,
            'exame_escrito' => null,
            'media_final' => 0
        ];
    }
    
    if ($bimestre == 1) {
        $disciplinas[$disciplina]['b1_mac'] = $nota['mac'] ?? null;
        $disciplinas[$disciplina]['b1_npt'] = $nota['npt'] ?? null;
        $disciplinas[$disciplina]['b1_media'] = $nota['media_parcial'] ?? null;
    } elseif ($bimestre == 2) {
        $disciplinas[$disciplina]['b2_mac'] = $nota['mac'] ?? null;
        $disciplinas[$disciplina]['b2_npt'] = $nota['npt'] ?? null;
        $disciplinas[$disciplina]['b2_media'] = $nota['media_parcial'] ?? null;
    } elseif ($bimestre == 3) {
        $disciplinas[$disciplina]['b3_mac'] = $nota['mac'] ?? null;
        $disciplinas[$disciplina]['b3_npt'] = $nota['npt'] ?? null;
        $disciplinas[$disciplina]['b3_media'] = $nota['media_parcial'] ?? null;
    }
    
    if (isset($nota['exame_normal'])) $disciplinas[$disciplina]['exame_normal'] = $nota['exame_normal'];
    if (isset($nota['exame_recurso'])) $disciplinas[$disciplina]['exame_recurso'] = $nota['exame_recurso'];
    if (isset($nota['exame_especial'])) $disciplinas[$disciplina]['exame_especial'] = $nota['exame_especial'];
    if (isset($nota['exame_oral'])) $disciplinas[$disciplina]['exame_oral'] = $nota['exame_oral'];
    if (isset($nota['exame_escrito'])) $disciplinas[$disciplina]['exame_escrito'] = $nota['exame_escrito'];
    if (isset($nota['media_final'])) $disciplinas[$disciplina]['media_final'] = $nota['media_final'];
}

// Calcular médias
foreach ($disciplinas as $disciplina => $dados) {
    $medias = [];
    if ($dados['b1_media'] !== null) $medias[] = $dados['b1_media'];
    if ($dados['b2_media'] !== null) $medias[] = $dados['b2_media'];
    if ($dados['b3_media'] !== null) $medias[] = $dados['b3_media'];
    
    if (!empty($medias)) {
        $disciplinas[$disciplina]['media_parcial'] = array_sum($medias) / count($medias);
    }
}

// Calcular estatísticas
$total_disciplinas = count($disciplinas);
$aprovados = 0;
$reprovados = 0;
$soma_medias_finais = 0;

foreach ($disciplinas as $disciplina => $dados) {
    $media_final = $dados['media_final'] ?? $dados['media_parcial'];
    $soma_medias_finais += $media_final;
    
    if ($visualizacao_total) {
        if ($ciclo == 1) {
            if ($media_final >= 5) $aprovados++;
            else $reprovados++;
        } else {
            if ($media_final >= 10) $aprovados++;
            else $reprovados++;
        }
    }
}

$media_geral = $total_disciplinas > 0 ? $soma_medias_finais / $total_disciplinas : 0;

// Funções auxiliares
function classificarNota($nota, $ciclo) {
    if ($ciclo == 1) {
        if ($nota >= 9) return ['texto' => 'Excelente!', 'classe' => 'excelente', 'cor' => '#28a745'];
        if ($nota >= 7.5) return ['texto' => 'Muito Bom!', 'classe' => 'muito-bom', 'cor' => '#20c997'];
        if ($nota >= 6) return ['texto' => 'Bom!', 'classe' => 'bom', 'cor' => '#17a2b8'];
        if ($nota >= 5) return ['texto' => 'Satisfatório', 'classe' => 'satisfatorio', 'cor' => '#ffc107'];
        if ($nota >= 3.5) return ['texto' => 'Insuficiente', 'classe' => 'insuficiente', 'cor' => '#fd7e14'];
        return ['texto' => 'Muito Insuficiente', 'classe' => 'muito-insuficiente', 'cor' => '#dc3545'];
    } else {
        if ($nota >= 18) return ['texto' => 'Excelente!', 'classe' => 'excelente', 'cor' => '#28a745'];
        if ($nota >= 15) return ['texto' => 'Muito Bom!', 'classe' => 'muito-bom', 'cor' => '#20c997'];
        if ($nota >= 12) return ['texto' => 'Bom!', 'classe' => 'bom', 'cor' => '#17a2b8'];
        if ($nota >= 10) return ['texto' => 'Satisfatório', 'classe' => 'satisfatorio', 'cor' => '#ffc107'];
        if ($nota >= 7) return ['texto' => 'Insuficiente', 'classe' => 'insuficiente', 'cor' => '#fd7e14'];
        return ['texto' => 'Muito Insuficiente', 'classe' => 'muito-insuficiente', 'cor' => '#dc3545'];
    }
}

function getStatusBadge($nota, $ciclo, $bloqueado = false) {
    if ($bloqueado) {
        return '<span class="badge bg-secondary"><i class="fas fa-lock"></i> Bloqueado</span>';
    }
    $aprovado = ($ciclo == 1) ? $nota >= 5 : $nota >= 10;
    if ($aprovado) {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
    }
}

function formatarNota($nota, $bloqueado = false) {
    if ($bloqueado) return '---';
    if ($nota === null || $nota === '') return '-';
    return number_format((float)$nota, 1, ',', '.');
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
        }
        
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .table-notas th, .table-notas td {
            vertical-align: middle;
            text-align: center;
        }
        
        .table-notas tr:hover {
            background: #f8f9fa;
        }
        
        .table-dark th {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .table-bordered {
            border: 1px solid #dee2e6;
        }
        
        .progress-bar-excelente { background: #28a745; }
        .progress-bar-muito-bom { background: #20c997; }
        .progress-bar-bom { background: #17a2b8; }
        .progress-bar-satisfatorio { background: #ffc107; }
        .progress-bar-insuficiente { background: #fd7e14; }
        .progress-bar-muito-insuficiente { background: #dc3545; }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .alert-financeiro {
            border-left: 4px solid;
            border-radius: 10px;
        }
        
        .bloqueado {
            background: #e9ecef;
            color: #6c757d;
        }
        
        .bloqueado td {
            background: #f8f9fa;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table-notas {
            min-width: 1000px;
        }
        
        /* Botão de ajuda */
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        
        .modal-ajuda.show {
            display: flex;
        }
        
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-ajuda-body {
            padding: 20px;
        }
        
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .ajuda-item:last-child {
            border-bottom: none;
        }
        
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Menu Toggle - CORRIGIDO */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 12px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
        }
        
        /* Sidebar - responsivo */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            transition: all 0.3s ease;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        /* Main content */
        .main-content-aluno {
            margin-left: 280px;
            margin-top: 0;
            margin-bottom: 0;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
            transition: all 0.3s ease;
        }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .menu-toggle {
                display: block;
            }
            
            .sidebar {
                left: -280px;
            }
            
            .sidebar.active {
                left: 0;
            }
            
            .main-content-aluno {
                margin-left: 0;
            }
            
            .main-content-aluno.active {
                margin-left: 0;
            }
            
            /* Ajuste do cabeçalho em telas pequenas */
            .d-flex.justify-content-between.align-items-center.mb-4 {
                flex-direction: column;
                align-items: flex-start !important;
                gap: 15px;
            }
            
            .d-flex.justify-content-between.align-items-center.mb-4 > div:first-child {
                width: 100%;
            }
            
            .d-flex.justify-content-between.align-items-center.mb-4 > div:last-child {
                width: 100%;
                display: flex;
                justify-content: space-between;
            }
            
            /* Ajuste dos selects e botões */
            .d-inline-flex.gap-2 {
                display: flex !important;
                width: 100%;
                gap: 10px;
            }
            
            .d-inline-flex.gap-2 select {
                flex: 1;
            }
            
            /* Cards responsivos */
            .row.g-3 {
                margin: 0 -0.5rem;
            }
            
            .col-md-3, .col-md-4, .col-md-8 {
                padding: 0 0.5rem;
                margin-bottom: 1rem;
            }
            
            /* Cards de estatísticas em telas pequenas */
            .stat-card {
                margin-bottom: 15px;
            }
            
            /* Botão de ajuda responsivo */
            .btn-ajuda {
                bottom: 20px;
                right: 20px;
                width: 45px;
                height: 45px;
            }
            
            /* Modal de ajuda responsivo */
            .modal-ajuda-content {
                width: 95%;
                max-height: 85vh;
            }
        }
    </style>
</head>
<body>

<!-- Botão Menu Toggle -->

  <?php include '../includes/menu_aluno.php'; ?>
   
<!-- Botão de Ajuda Flutuante -->
<button class="btn-ajuda" id="btnAjuda">
    <i class="fas fa-question fa-lg"></i>
</button>

<!-- Modal de Ajuda -->
<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Minhas Notas</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">
                    Esta página exibe todas as suas notas acadêmicas organizadas por disciplina e bimestre. 
                    Você pode acompanhar seu desempenho em cada matéria ao longo do ano letivo.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Níveis de Acesso</div>
                <div class="ajuda-texto">
                    <strong>🟢 Acesso Total:</strong> Boletim pago e sem dívidas - visualiza todas as notas.<br>
                    <strong>🟡 Acesso Parcial:</strong> Boletim pago mas com dívidas - visualiza apenas 1º e 2º bimestre.<br>
                    <strong>🔴 Acesso Bloqueado:</strong> Boletim não pago - não visualiza nenhuma nota.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Campos da Tabela</div>
                <div class="ajuda-texto">
                    <strong>MAC:</strong> Média das Atividades Contínuas (trabalhos, exercícios, participação)<br>
                    <strong>NPT:</strong> Nota de Participação e Trabalho (assiduidade, comportamento)<br>
                    <strong>Média:</strong> Resultado do bimestre (MAC + NPT)<br>
                    <strong>Exames:</strong> Notas obtidas nas avaliações finais
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Classificação das Notas</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">18-20</span> Excelente<br>
                    <span class="badge" style="background:#20c997;">15-17.9</span> Muito Bom<br>
                    <span class="badge" style="background:#17a2b8;">12-14.9</span> Bom<br>
                    <span class="badge" style="background:#ffc107;">10-11.9</span> Satisfatório<br>
                    <span class="badge" style="background:#fd7e14;">7-9.9</span> Insuficiente<br>
                    <span class="badge bg-danger">0-6.9</span> Muito Insuficiente
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">5</span> Ciclos de Ensino</div>
                <div class="ajuda-texto">
                    <strong>1º Ciclo (1ª a 6ª Classe):</strong> Escala de 0 a 10, aprovação com nota ≥ 5<br>
                    <strong>2º Ciclo (7ª em diante):</strong> Escala de 0 a 20, aprovação com nota ≥ 10
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">6</span> Estatísticas</div>
                <div class="ajuda-texto">
                    <strong>Média Geral:</strong> Média de todas as disciplinas<br>
                    <strong>Disciplinas Aprovadas:</strong> Número de matérias com nota suficiente<br>
                    <strong>Aproveitamento:</strong> Percentual de disciplinas aprovadas
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">7</span> Dicas</div>
                <div class="ajuda-texto">
                    • Utilize o filtro de ano para consultar diferentes períodos<br>
                    • Clique em "Imprimir" para gerar uma cópia do seu boletim<br>
                    • As notas bloqueadas aparecem como "🔒" por questões financeiras
                </div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-chart-line"></i> Minhas Notas</h4>
            <p class="text-muted mb-0">Acompanhe seu desempenho acadêmico - <?php echo $ciclo == 1 ? '1º Ciclo (0-10)' : '2º Ciclo (0-20)'; ?></p>
        </div>
        <div>
           <form method="GET" class="d-inline-flex gap-2">
    <select name="ano_id" class="form-select" style="width: auto;">
        <?php foreach ($anos_disponiveis as $ano): ?>
        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_selecionado_id == $ano['id'] ? 'selected' : ''; ?>>
            <?php echo $ano['ano']; ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filtrar</button>
</form>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- ALERTA FINANCEIRO -->
    <?php if ($bloqueio_total): ?>
    <div class="alert alert-danger alert-financeiro mb-4 fade-in" style="border-left-color: #dc3545;">
        <div class="d-flex align-items-center">
            <i class="fas fa-lock fa-2x me-3"></i>
            <div>
                <strong><i class="fas fa-exclamation-triangle"></i> Acesso Bloqueado!</strong><br>
                <?php echo $mensagem_bloqueio; ?>
                <?php if ($tota_divida > 0): ?>
                <br>Valor em dívida: <strong><?php echo number_format($tota_divida, 2, ',', '.'); ?> Kz</strong>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php elseif ($visualizacao_parcial): ?>
    <div class="alert alert-warning alert-financeiro mb-4 fade-in" style="border-left-color: #ffc107;">
        <div class="d-flex align-items-center">
            <i class="fas fa-eye fa-2x me-3"></i>
            <div>
                <strong><i class="fas fa-info-circle"></i> Visualização Parcial</strong><br>
                <?php echo $mensagem_parcial; ?>
            </div>
        </div>
    </div>
    <?php elseif ($visualizacao_total): ?>
    <div class="alert alert-success alert-financeiro mb-4 fade-in" style="border-left-color: #28a745;">
        <div class="d-flex align-items-center">
            <i class="fas fa-check-circle fa-2x me-3"></i>
            <div>
                <strong><i class="fas fa-info-circle"></i> Acesso Liberado</strong><br>
                <?php echo $mensagem_liberacao; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Informações do Aluno -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                            <h6 class="mb-0"><?php echo htmlspecialchars($aluno['nome']); ?></h6>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                            <h6 class="mb-0"><?php echo $aluno['matricula']; ?></h6>
                        </div>
                        <div class="col-md-3">
                            <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                            <h6 class="mb-0"><?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                        </div>
                        <div class="col-md-2">
                            <small class="text-muted"><i class="fas fa-chart-simple"></i> Ciclo</small>
                            <h6 class="mb-0"><?php echo $ciclo == 1 ? '1º Ciclo (0-10)' : '2º Ciclo (0-20)'; ?></h6>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card text-center border-0 shadow-sm bg-primary text-white">
                <div class="card-body">
                    <small>Média Geral</small>
                    <h2 class="mb-0">
                        <?php if ($bloqueio_total): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo number_format($media_geral, 1, ',', '.'); ?>
                        <?php endif; ?>
                    </h2>
                    <small>/ <?php echo $nota_maxima; ?></small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-book fa-2x text-primary mb-2"></i>
                    <h3 class="mb-0"><?php echo $total_disciplinas; ?></h3>
                    <small class="text-muted">Total de Disciplinas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <h3 class="mb-0">
                        <?php if ($bloqueio_total || $visualizacao_parcial): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo $aprovados; ?>
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Disciplinas Aprovadas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <h3 class="mb-0">
                        <?php if ($bloqueio_total || $visualizacao_parcial): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo $reprovados; ?>
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Disciplinas Reprovadas</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <i class="fas fa-chart-line fa-2x text-info mb-2"></i>
                    <h3 class="mb-0">
                        <?php if ($bloqueio_total || $visualizacao_parcial): ?>
                            <i class="fas fa-lock"></i>
                        <?php else: ?>
                            <?php echo number_format(($aprovados / max($total_disciplinas, 1)) * 100, 0); ?>%
                        <?php endif; ?>
                    </h3>
                    <small class="text-muted">Aproveitamento</small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Tabela de Notas -->
    <div class="card border-0 shadow-sm fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-table"></i> Resultados Acadêmicos - <?php echo $ano_letivo_selecionado_valor; ?>
            <?php if ($visualizacao_parcial): ?>
            <span class="badge bg-warning ms-2">Visualização Parcial (1º e 2º Bimestre)</span>
            <?php elseif ($bloqueio_total): ?>
            <span class="badge bg-danger ms-2">Bloqueado - Pendência Financeira</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (empty($disciplinas)): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                    <p>Nenhuma nota encontrada para o ano letivo <?php echo $ano_letivo_selecionado_valor; ?>.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-notas">
                        <thead>
                            <tr class="table-dark">
                                <th rowspan="2" style="vertical-align: middle; width: 50px;">#</th>
                                <th rowspan="2" style="vertical-align: middle; width: 200px;">Disciplina</th>
                                <th colspan="3" class="text-center">1º Bimestre</th>
                                <th colspan="3" class="text-center">2º Bimestre</th>
                                <th colspan="3" class="text-center">3º Bimestre</th>
                                <th rowspan="2" class="text-center" style="width: 80px;">Média<br>Parcial</th>
                                <th colspan="5" class="text-center">Exames</th>
                                <th rowspan="2" class="text-center" style="width: 80px;">Média<br>Final</th>
                                <th rowspan="2" class="text-center" style="width: 100px;">Status</th>
                              </tr>
                            <tr class="table-dark">
                                <th class="text-center">MAC</th>
                                <th class="text-center">NPT</th>
                                <th class="text-center">Média</th>
                                <th class="text-center">MAC</th>
                                <th class="text-center">NPT</th>
                                <th class="text-center">Média</th>
                                <th class="text-center">MAC</th>
                                <th class="text-center">NPT</th>
                                <th class="text-center">Média</th>
                                <th class="text-center">Normal</th>
                                <th class="text-center">Recurso</th>
                                <th class="text-center">Especial</th>
                                <th class="text-center">Oral</th>
                                <th class="text-center">Escrito</th>
                              </tr>
                        </thead>
                        <tbody>
                            <?php $index = 1; foreach ($disciplinas as $disciplina => $dados): 
                                $media_final_disciplina = $dados['media_final'] ?? $dados['media_parcial'];
                                $classificacao = classificarNota($media_final_disciplina, $ciclo);
                                $percentual = ($media_final_disciplina / $nota_maxima) * 100;
                            ?>
                            <tr <?php echo $bloqueio_total ? 'class="bloqueado"' : ''; ?>>
                                <td class="text-center"><strong><?php echo $index++; ?></strong><?php echo ' '; ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($disciplina); ?></strong>
                                    <br><small class="text-muted"><?php echo $dados['codigo']; ?></small>
                                </td>
                                
                                <!-- Bimestre 1 -->
                                <td class="text-center"><?php echo formatarNota($dados['b1_mac'], $bloqueio_total); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['b1_npt'], $bloqueio_total); ?></td>
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['b1_media'], $bloqueio_total); ?></td>
                                
                                <!-- Bimestre 2 -->
                                <td class="text-center"><?php echo formatarNota($dados['b2_mac'], ($bloqueio_total)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['b2_npt'], ($bloqueio_total)); ?></td>
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['b2_media'], ($bloqueio_total)); ?></td>
                                
                                <!-- Bimestre 3 -->
                                <td class="text-center"><?php echo formatarNota($dados['b3_mac'], (!$visualizacao_total)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['b3_npt'], (!$visualizacao_total)); ?></td>
                                <td class="text-center fw-bold"><?php echo formatarNota($dados['b3_media'], (!$visualizacao_total)); ?></td>
                                
                                <!-- Média Parcial -->
                                <td class="text-center fw-bold">
                                    <?php if ($bloqueio_total): ?>
                                        <i class="fas fa-lock"></i>
                                    <?php else: ?>
                                        <?php echo formatarNota($dados['media_parcial']); ?>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Exames -->
                                <td class="text-center"><?php echo formatarNota($dados['exame_normal'], (!$visualizacao_total)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_recurso'], (!$visualizacao_total)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_especial'], (!$visualizacao_total)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_oral'], (!$visualizacao_total)); ?></td>
                                <td class="text-center"><?php echo formatarNota($dados['exame_escrito'], (!$visualizacao_total)); ?></td>
                                
                                <!-- Média Final -->
                                <td class="text-center fw-bold" style="background: <?php echo !$visualizacao_total ? '#e9ecef' : $classificacao['cor'] . '20'; ?>; color: <?php echo !$visualizacao_total ? '#6c757d' : $classificacao['cor']; ?>;">
                                    <?php if (!$visualizacao_total): ?>
                                        <i class="fas fa-lock"></i>
                                    <?php else: ?>
                                        <?php echo formatarNota($media_final_disciplina); ?>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- Status -->
                                <td class="text-center">
                                    <?php if (!$visualizacao_total): ?>
                                        <span class="badge bg-secondary"><i class="fas fa-lock"></i> Bloqueado</span>
                                    <?php else: ?>
                                        <?php echo getStatusBadge($media_final_disciplina, $ciclo, false); ?>
                                        <div class="progress mt-1" style="height: 4px;">
                                            <div class="progress-bar progress-bar-<?php echo $classificacao['classe']; ?>" style="width: <?php echo min($percentual, 100); ?>%"></div>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="2" class="text-end">Média Geral:</td>
                                <td colspan="17" class="text-center">
                                    <?php if ($bloqueio_total): ?>
                                        <i class="fas fa-lock"></i> Bloqueado
                                    <?php elseif ($visualizacao_parcial): ?>
                                        <i class="fas fa-eye"></i> Parcial
                                    <?php else: ?>
                                        <?php echo number_format($media_geral, 1, ',', '.'); ?> / <?php echo $nota_maxima; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($bloqueio_total): ?>
                                        <span class="text-danger">Aguardando regularização</span>
                                    <?php elseif ($visualizacao_parcial): ?>
                                        <span class="text-warning">Regularize dívidas</span>
                                    <?php else: ?>
                                        <?php echo $media_geral >= $nota_minima_aprovacao ? '<span class="text-success">Aluno Aprovado</span>' : '<span class="text-danger">Aluno Reprovado</span>'; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Legenda de Classificação -->
    <div class="card border-0 shadow-sm mt-4 fade-in">
        <div class="card-header bg-white fw-bold">
            <i class="fas fa-info-circle"></i> Legenda de Classificação
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-child"></i> 1º Ciclo (1ª a 6ª Classe) - Escala 0-10</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><span class="badge" style="background: #28a745;">9.0 - 10.0</span> Excelente</div>
                        <div class="col-md-4"><span class="badge" style="background: #20c997;">7.5 - 8.9</span> Muito Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #17a2b8;">6.0 - 7.4</span> Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #ffc107;">5.0 - 5.9</span> Satisfatório</div>
                        <div class="col-md-4"><span class="badge" style="background: #fd7e14;">3.5 - 4.9</span> Insuficiente</div>
                        <div class="col-md-4"><span class="badge" style="background: #dc3545;">0.0 - 3.4</span> Muito Insuficiente</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="mb-3"><i class="fas fa-user-graduate"></i> 2º Ciclo (7ª Classe em diante) - Escala 0-20</h6>
                    <div class="row g-2">
                        <div class="col-md-4"><span class="badge" style="background: #28a745;">18.0 - 20.0</span> Excelente</div>
                        <div class="col-md-4"><span class="badge" style="background: #20c997;">15.0 - 17.9</span> Muito Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #17a2b8;">12.0 - 14.9</span> Bom</div>
                        <div class="col-md-4"><span class="badge" style="background: #ffc107;">10.0 - 11.9</span> Satisfatório</div>
                        <div class="col-md-4"><span class="badge" style="background: #fd7e14;">7.0 - 9.9</span> Insuficiente</div>
                        <div class="col-md-4"><span class="badge" style="background: #dc3545;">0.0 - 6.9</span> Muito Insuficiente</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() {
        modalAjuda.classList.add('show');
    });
    
    closeAjuda.addEventListener('click', function() {
        modalAjuda.classList.remove('show');
    });
    
    modalAjuda.addEventListener('click', function(e) {
        if (e.target === modalAjuda) {
            modalAjuda.classList.remove('show');
        }
    });
    
    document.getElementById('btnExportar')?.addEventListener('click', function() {
        window.print();
    });

    // Menu Toggle - CORRIGIDO
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.querySelector('.sidebar');
        const mainContent = document.querySelector('.main-content-aluno');
        
        if (menuToggle && sidebar && mainContent) {
            menuToggle.addEventListener('click', function(e) {
                e.stopPropagation();
                sidebar.classList.toggle('active');
                mainContent.classList.toggle('active');
            });
        }
        
        // Fechar menu ao clicar fora em telas pequenas
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768) {
                const isClickInsideSidebar = sidebar && sidebar.contains(event.target);
                const isClickOnToggle = menuToggle && menuToggle.contains(event.target);
                
                if (!isClickInsideSidebar && !isClickOnToggle && sidebar && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    if (mainContent) mainContent.classList.remove('active');
                }
            }
        });
        
        // Ajustar ao redimensionar a tela
        window.addEventListener('resize', function() {
            if (window.innerWidth > 768 && sidebar && sidebar.classList.contains('active')) {
                sidebar.classList.remove('active');
                if (mainContent) mainContent.classList.remove('active');
            }
        });
    });
</script>

</body>
</html>