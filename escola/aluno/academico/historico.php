<?php
// escola/aluno/academico/historico.php - Histórico Escolar do Aluno

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
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';
// ============================================
// VERIFICAÇÃO FINANCEIRA PARA HISTÓRICO
// ============================================

// Verificar dívidas em mensalidades
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

// Verificar dívidas em outros pagamentos
$dividas_outros_pagamentos = 0;
$valor_divida_outros_pagamentos = 0;

try {
    $sql_outros_pagamentos = "SELECT COUNT(*) as total, COALESCE(SUM(valor_total - valor_pago), 0) as valor 
                         FROM outros_pagamentos 
                         WHERE escola_id = :escola_id AND aluno_id = :aluno_id 
                         AND status IN ('pendente', 'parcial')";
    $stmt_outros_pagamentos = $conn->prepare($sql_outros_pagamentos);
    $stmt_outros_pagamentos->execute([':escola_id' => $escola_id, ':aluno_id' => $aluno_id]);
    $outros_result = $stmt_outros_pagamentos->fetch(PDO::FETCH_ASSOC);
    $dividas_outros_pagamentos = $outros_result['total'] ?? 0;
    $valor_divida_outros_pagamentos = $outros_result['valor'] ?? 0;
    
} catch (Exception $e) {
    $dividas_outros_pagamentos = 0;
    $valor_divida_outros_pagamentos = 0;
}

$tem_dividas = (($dividas_mensalidades > 0) || ($dividas_outros_pagamentos > 0));
$valor_total_divida = ($valor_divida_mensalidades + $valor_divida_outros_pagamentos);

// Definir status de visualização - só pode ver o histórico se NÃO tiver dívidas
$pode_ver_historico = !$tem_dividas;

if (!$pode_ver_historico) {
    $mensagem_historico = "Você possui dívidas pendentes no valor de <strong>" . number_format($valor_total_divida, 2, ',', '.') . " Kz</strong>. Regularize sua situação financeira para acessar seu histórico escolar completo.";
}

// Definir título da página
$titulo_pagina = 'Histórico Escolar';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula, data_nascimento, naturalidade, genero, pai_nome, mae_nome 
              FROM estudantes WHERE id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar todo o histórico do aluno (anos letivos completos)
$sql_historico = "SELECT 
                    al.ano as ano_letivo,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    SUM(CASE WHEN n.status = 'aprovado' THEN 1 ELSE 0 END) as disciplinas_aprovadas,
                    COUNT(DISTINCT n.disciplina_id) as total_disciplinas,
                    ROUND(AVG(n.media_final), 1) as media_geral
                  FROM notas n
                  JOIN disciplinas d ON d.id = n.disciplina_id
                  JOIN ano_letivo al ON al.id = n.ano_letivo_id
                  LEFT JOIN matriculas m ON m.estudante_id = n.estudante_id AND m.ano_letivo = n.ano_letivo_id
                  LEFT JOIN turmas t ON t.id = m.turma_id
                  WHERE n.estudante_id = :aluno_id
                  GROUP BY al.id, al.ano, t.nome, t.ano
                  ORDER BY al.ano DESC";
$stmt_historico = $conn->prepare($sql_historico);
$stmt_historico->execute([':aluno_id' => $aluno_id]);
$historico_anos = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas cursadas por ano
$sql_disciplinas = "SELECT 
                      al.ano as ano_letivo,
                      d.nome as disciplina,
                      n.media_final,
                      n.status as situacao,
                      CASE 
                        WHEN n.media_final >= 18 THEN 'Excelente'
                        WHEN n.media_final >= 15 THEN 'Muito Bom'
                        WHEN n.media_final >= 12 THEN 'Bom'
                        WHEN n.media_final >= 10 THEN 'Satisfatório'
                        WHEN n.media_final >= 7 THEN 'Insuficiente'
                        ELSE 'Muito Insuficiente'
                      END as classificação
                    FROM notas n
                    JOIN disciplinas d ON d.id = n.disciplina_id
                    JOIN ano_letivo al ON al.id = n.ano_letivo_id
                    WHERE n.estudante_id = :aluno_id
                    ORDER BY al.ano DESC, d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id]);
$disciplinas_cursadas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Agrupar disciplinas por ano
$disciplinas_por_ano = [];
foreach ($disciplinas_cursadas as $disc) {
    $ano = $disc['ano_letivo'];
    if (!isset($disciplinas_por_ano[$ano])) {
        $disciplinas_por_ano[$ano] = [];
    }
    $disciplinas_por_ano[$ano][] = $disc;
}

// Estatísticas gerais do aluno
$total_anos = count($historico_anos);
$total_disciplinas_geral = count($disciplinas_cursadas);
$disciplinas_aprovadas_geral = 0;
foreach ($disciplinas_cursadas as $disc) {
    if ($disc['situacao'] == 'aprovado') {
        $disciplinas_aprovadas_geral++;
    }
}
$media_geral_historico = 0;
foreach ($disciplinas_cursadas as $disc) {
    $media_geral_historico += $disc['media_final'];
}
$media_geral_historico = $total_disciplinas_geral > 0 ? $media_geral_historico / $total_disciplinas_geral : 0;

// Função para classificar nota
function getClassificacaoHistorico($media) {
    if ($media >= 18) return 'Excelente';
    if ($media >= 15) return 'Muito Bom';
    if ($media >= 12) return 'Bom';
    if ($media >= 10) return 'Satisfatório';
    if ($media >= 7) return 'Insuficiente';
    return 'Muito Insuficiente';
}

function getStatusBadgeHistorico($status) {
    if ($status == 'aprovado') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
    }
}

include '../includes/menu_aluno.php';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .historico-timeline {
            position: relative;
            padding-left: 30px;
        }
        
        .historico-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
        }
        
        .timeline-item {
            position: relative;
            margin-bottom: 30px;
        }
        
        .timeline-badge {
            position: absolute;
            left: -25px;
            top: 0;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: #006B3E;
            border: 3px solid white;
            box-shadow: 0 0 0 2px #006B3E;
        }
        
        .timeline-content {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-left: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .ano-badge {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            display: inline-block;
        }
        
        .table-historico th, .table-historico td {
            vertical-align: middle;
        }
        
        .table-historico tr:hover {
            background: #f8f9fa;
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
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
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
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- Botão de Ajuda Flutuante -->
<button class="btn-ajuda" id="btnAjuda">
    <i class="fas fa-question fa-lg"></i>
</button>

<!-- Modal de Ajuda -->
<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Histórico Escolar</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">
                    Esta página exibe todo o seu histórico escolar, organizado por ano letivo.
                    Você pode acompanhar seu desempenho ao longo de toda a sua trajetória acadêmica.
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Resumo por Ano</div>
                <div class="ajuda-texto">
                    Para cada ano letivo, são exibidas as disciplinas cursadas, as notas finais,
                    a classificação e o resultado (aprovado/reprovado).
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Classificação das Notas</div>
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
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Estatísticas</div>
                <div class="ajuda-texto">
                    <strong>Total de Anos:</strong> Quantidade de anos letivos cursados<br>
                    <strong>Total de Disciplinas:</strong> Número total de disciplinas cursadas<br>
                    <strong>Média Geral:</strong> Média de todas as notas em todo o histórico
                </div>
            </div>
            
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">5</span> Dicas</div>
                <div class="ajuda-texto">
                    • Clique em "Imprimir" para gerar uma cópia do seu histórico escolar<br>
                    • O histórico pode ser utilizado para fins de transferência escolar<br>
                    • Mantenha seus dados sempre atualizados
                </div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <!-- Cabeçalho -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-history"></i> Histórico Escolar</h4>
            <p class="text-muted mb-0">Registro completo da sua trajetória acadêmica</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir Histórico
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['nome']); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno['matricula']; ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-calendar-alt"></i> Data Nasc.</small>
                    <h6 class="mb-0"><?php echo date('d/m/Y', strtotime($aluno['data_nascimento'] ?? 'now')); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-flag"></i> Naturalidade</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['naturalidade'] ?? '-'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-venus-mars"></i> Genero</small>
                    <h6 class="mb-0"><?php echo $aluno['genero'] == 'M' ? 'Masculino' : 'Feminino'; ?></h6>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-md-6">
                    <small class="text-muted"><i class="fas fa-father"></i> Nome do Pai</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['pai_nome'] ?? '-'); ?></h6>
                </div>
                <div class="col-md-6">
                    <small class="text-muted"><i class="fas fa-mother"></i> Nome da Mãe</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno['mae_nome'] ?? '-'); ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo $total_anos; ?></div>
                <div class="stat-label">Total de Anos Cursados</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_disciplinas_geral; ?></div>
                <div class="stat-label">Disciplinas Cursadas</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo number_format($media_geral_historico, 1, ',', '.'); ?></div>
                <div class="stat-label">Média Geral Histórica</div>
            </div>
        </div>
    </div>
    
    <!-- Histórico por Ano -->
   <!-- Histórico por Ano -->
<div class="card border-0 shadow-sm fade-in">
    <div class="card-header bg-white fw-bold">
        <i class="fas fa-timeline"></i> Trajetória Acadêmica
    </div>
    <div class="card-body">
        <?php if (!$pode_ver_historico): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-lock fa-2x mb-2"></i>
                <p><?php echo $mensagem_historico; ?></p>
                <?php if ($valor_total_divida > 0): ?>
                <small>Valor em dívida: <strong><?php echo number_format($valor_total_divida, 2, ',', '.'); ?> Kz</strong></small>
                <?php endif; ?>
            </div>
        <?php elseif (empty($historico_anos)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle fa-2x mb-2"></i>
                <p>Nenhum registro encontrado no seu histórico escolar.</p>
            </div>
        <?php else: ?>
            <!-- Conteúdo do histórico normalmente -->
            <div class="historico-timeline">
                <?php foreach ($historico_anos as $ano): 
                    $percentual = $ano['total_disciplinas'] > 0 ? ($ano['disciplinas_aprovadas'] / $ano['total_disciplinas']) * 100 : 0;
                ?>
                <!-- ... resto do código do histórico ... -->
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
    
    <!-- Resumo Geral -->
    <?php if (!empty($historico_anos)): ?>
  <!-- Resumo Geral -->
<?php if (!empty($historico_anos) && $pode_ver_historico): ?>
<div class="card border-0 shadow-sm mt-4 fade-in">
    <div class="card-header bg-white fw-bold">
        <i class="fas fa-chart-bar"></i> Resumo Geral do Percurso Académico
    </div>
    <div class="card-body">
        <!-- ... conteúdo do resumo ... -->
    </div>
</div>
<?php elseif (!empty($historico_anos) && !$pode_ver_historico): ?>
<div class="card border-0 shadow-sm mt-4 fade-in">
    <div class="card-header bg-white fw-bold">
        <i class="fas fa-chart-bar"></i> Resumo Geral do Percurso Académico
    </div>
    <div class="card-body">
        <div class="alert alert-warning text-center">
            <i class="fas fa-lock fa-2x mb-2"></i>
            <p>Regularize sua situação financeira para visualizar o resumo do seu percurso académico.</p>
            <?php if ($valor_total_divida > 0): ?>
            <small>Valor em dívida: <strong><?php echo number_format($valor_total_divida, 2, ',', '.'); ?> Kz</strong></small>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
    <?php endif; ?>
    
    <!-- Rodapé do Documento -->
    <div class="text-center text-muted mt-4">
        <small>Documento emitido por computador - Válido como histórico escolar</small><br>
        <small>Gerado em <?php echo date('d/m/Y H:i:s'); ?></small>
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
</script>

</body>
</html>