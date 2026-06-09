<?php
// escola/pedagogico/justificativas.php - Gerenciar Justificativas de Chamada

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];
$funcionario_id = $funcionario['id'];

// ============================================
// PROCESSAR AÇÕES (atualizar observacao com a decisão)
// ============================================

// Aprovar justificativa (adicionar observação de aprovação)
if (isset($_POST['action']) && $_POST['action'] == 'aprovar' && isset($_POST['chamada_id'])) {
    $chamada_id = (int)$_POST['chamada_id'];
    $observacao = trim($_POST['observacao'] ?? '');
    
    $observacao_atual = "✅ JUSTIFICATIVA APROVADA por " . $funcionario['nome'] . " em " . date('d/m/Y H:i') . ". " . $observacao;
    
    $sql = "UPDATE chamada SET observacao = CONCAT(IFNULL(observacao, ''), '\n\n---\n', :observacao) WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':observacao' => $observacao_atual,
        ':id' => $chamada_id
    ]);
    
    $mensagem = "Justificativa aprovada com sucesso!";
}

// Rejeitar justificativa
if (isset($_POST['action']) && $_POST['action'] == 'rejeitar' && isset($_POST['chamada_id'])) {
    $chamada_id = (int)$_POST['chamada_id'];
    $motivo_rejeicao = trim($_POST['motivo_rejeicao'] ?? '');
    
    $observacao_atual = "❌ JUSTIFICATIVA REJEITADA por " . $funcionario['nome'] . " em " . date('d/m/Y H:i') . ". Motivo: " . $motivo_rejeicao;
    
    $sql = "UPDATE chamada SET observacao = CONCAT(IFNULL(observacao, ''), '\n\n---\n', :observacao) WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':observacao' => $observacao_atual,
        ':id' => $chamada_id
    ]);
    
    $mensagem = "Justificativa rejeitada.";
}

// ============================================
// BUSCAR JUSTIFICATIVAS
// ============================================

$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'pendente';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : '';
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : '';
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

$sql = "
    SELECT 
        c.id,
        c.escola_id,
        c.ano_letivo_id,
        c.turma_id,
        c.disciplina_id,
        c.professor_id,
        c.estudante_id,
        c.data_aula,
        c.horario_inicio,
        c.horario_fim,
        c.status,
        c.minutos_atraso,
        c.justificativa,
        c.documento_justificativa,
        c.observacao,
        c.bimestre,
        c.data_lancamento,
        e.id as aluno_id,
        e.nome as aluno_nome,
        e.matricula as aluno_matricula,
        e.bi as aluno_bi,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        pf.nome as professor_nome
    FROM chamada c
    JOIN estudantes e ON e.id = c.estudante_id
    JOIN turmas t ON t.id = c.turma_id
    JOIN disciplinas d ON d.id = c.disciplina_id
    LEFT JOIN funcionarios pf ON pf.id = c.professor_id
    WHERE c.escola_id = :escola_id
    AND c.justificativa IS NOT NULL 
    AND c.justificativa != ''
";

if ($status_filtro == 'pendente') {
    $sql .= " AND (c.observacao NOT LIKE '%JUSTIFICATIVA APROVADA%' AND c.observacao NOT LIKE '%JUSTIFICATIVA REJEITADA%')";
} elseif ($status_filtro == 'aprovado') {
    $sql .= " AND c.observacao LIKE '%JUSTIFICATIVA APROVADA%'";
} elseif ($status_filtro == 'rejeitado') {
    $sql .= " AND c.observacao LIKE '%JUSTIFICATIVA REJEITADA%'";
}

if (!empty($busca)) {
    $sql .= " AND (e.nome LIKE :busca OR e.matricula LIKE :busca OR e.bi LIKE :busca)";
}
if (!empty($data_inicio)) {
    $sql .= " AND DATE(c.data_aula) >= :data_inicio";
}
if (!empty($data_fim)) {
    $sql .= " AND DATE(c.data_aula) <= :data_fim";
}
if ($bimestre_filtro > 0) {
    $sql .= " AND c.bimestre = :bimestre";
}

$sql .= " ORDER BY c.data_aula DESC, c.data_lancamento DESC";

$stmt = $conn->prepare($sql);
$params = [':escola_id' => $escola_id];
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
if (!empty($data_inicio)) {
    $params[':data_inicio'] = $data_inicio;
}
if (!empty($data_fim)) {
    $params[':data_fim'] = $data_fim;
}
if ($bimestre_filtro > 0) {
    $params[':bimestre'] = $bimestre_filtro;
}
$stmt->execute($params);
$justificativas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contadores por status
$pendentes_count = 0;
$aprovados_count = 0;
$rejeitados_count = 0;

foreach ($justificativas as $j) {
    if (strpos($j['observacao'], 'JUSTIFICATIVA APROVADA') !== false) {
        $aprovados_count++;
    } elseif (strpos($j['observacao'], 'JUSTIFICATIVA REJEITADA') !== false) {
        $rejeitados_count++;
    } else {
        $pendentes_count++;
    }
}
$total_count = count($justificativas);

$caminho_base = '/sige_Plataforma/uploads/justificativas/';
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Justificativas de Falta - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-title h1 { font-size: 24px; margin-bottom: 5px; }
        .header-title p { opacity: 0.9; font-size: 14px; }
        
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-voltar:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .stats-row {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            flex: 1;
            min-width: 180px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            cursor: pointer;
            border: 2px solid transparent;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card.active {
            border-color: #1e5799;
            background: linear-gradient(135deg, #f0f8ff, #e8f4fd);
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .stat-pendente .stat-number { color: #f39c12; }
        .stat-aprovado .stat-number { color: #27ae60; }
        .stat-rejeitado .stat-number { color: #e74c3c; }
        .stat-total .stat-number { color: #1e5799; }
        
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body { padding: 20px; }
        
        .filtros-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 150px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 12px;
            color: #2c3e50;
        }
        
        .filtro-select, .filtro-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        
        .table-justificativas {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-justificativas th {
            background: #f8f9fa;
            padding: 12px;
            font-size: 12px;
            font-weight: 700;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
        }
        
        .table-justificativas td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        
        .table-justificativas tr:hover {
            background: #f8f9fa;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .status-pendente { background: #fef9e7; color: #f39c12; }
        .status-aprovado { background: #d5f4e6; color: #27ae60; }
        .status-rejeitado { background: #fadbd8; color: #e74c3c; }
        
        .btn-acao {
            background: none;
            border: none;
            padding: 5px 10px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .btn-aprovar {
            background: #27ae60;
            color: white;
        }
        
        .btn-rejeitar {
            background: #e74c3c;
            color: white;
        }
        
        .btn-info {
            background: #3498db;
            color: white;
        }
        
        .btn-documento {
            background: #17a2b8;
            color: white;
        }
        
        .btn-aprovar:hover, .btn-rejeitar:hover, .btn-info:hover, .btn-documento:hover {
            transform: translateY(-2px);
            opacity: 0.9;
        }
        
        .documento-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #ecf0f1;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 11px;
            text-decoration: none;
            color: #2c3e50;
        }
        
        .documento-link:hover {
            background: #d5dbdb;
        }
        
        .modal-custom {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-custom-content {
            background: white;
            margin: 5% auto;
            width: 90%;
            max-width: 500px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-custom-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 20px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-custom-body { padding: 20px; }
        
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close-modal:hover { color: #ddd; }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 15px;
        }
        
        .justificativa-texto {
            background: #f8f9fa;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            max-height: 200px;
            overflow-y: auto;
            font-size: 14px;
            white-space: pre-wrap;
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .stats-row { gap: 10px; }
            .stat-card { min-width: calc(50% - 10px); padding: 15px; }
            .table-justificativas { font-size: 11px; }
            .table-justificativas th, .table-justificativas td { padding: 6px; }
            .btn-acao { padding: 3px 6px; font-size: 10px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1><i class="fas fa-file-alt me-2"></i> Justificativas de Falta</h1>
            <p>Gerencie as justificativas apresentadas pelos alunos</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <?php if (isset($mensagem)): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <!-- Cards de Estatísticas -->
    <div class="stats-row">
        <div class="stat-card stat-pendente <?php echo $status_filtro == 'pendente' ? 'active' : ''; ?>" onclick="filtrarPorStatus('pendente')">
            <div class="stat-number"><?php echo $pendentes_count; ?></div>
            <div class="stat-label">Pendentes</div>
        </div>
        <div class="stat-card stat-aprovado <?php echo $status_filtro == 'aprovado' ? 'active' : ''; ?>" onclick="filtrarPorStatus('aprovado')">
            <div class="stat-number"><?php echo $aprovados_count; ?></div>
            <div class="stat-label">Aprovadas</div>
        </div>
        <div class="stat-card stat-rejeitado <?php echo $status_filtro == 'rejeitado' ? 'active' : ''; ?>" onclick="filtrarPorStatus('rejeitado')">
            <div class="stat-number"><?php echo $rejeitados_count; ?></div>
            <div class="stat-label">Rejeitadas</div>
        </div>
        <div class="stat-card stat-total <?php echo $status_filtro == 'todos' ? 'active' : ''; ?>" onclick="filtrarPorStatus('todos')">
            <div class="stat-number"><?php echo $total_count; ?></div>
            <div class="stat-label">Total</div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-filter me-2"></i> Filtros de Busca
        </div>
        <div class="card-body">
            <form method="GET" action="">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Status</label>
                        <select name="status" class="filtro-select">
                            <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                            <option value="aprovado" <?php echo $status_filtro == 'aprovado' ? 'selected' : ''; ?>>Aprovadas</option>
                            <option value="rejeitado" <?php echo $status_filtro == 'rejeitado' ? 'selected' : ''; ?>>Rejeitadas</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="0">Todos</option>
                            <option value="1" <?php echo $bimestre_filtro == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo $bimestre_filtro == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo $bimestre_filtro == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                            <option value="4" <?php echo $bimestre_filtro == 4 ? 'selected' : ''; ?>>4º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Buscar por Nome/Matrícula/BI</label>
                        <input type="text" name="busca" class="filtro-input" placeholder="Digite para buscar..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="filtro-group">
                        <label>Data Início</label>
                        <input type="date" name="data_inicio" class="filtro-input" value="<?php echo $data_inicio; ?>">
                    </div>
                    <div class="filtro-group">
                        <label>Data Fim</label>
                        <input type="date" name="data_fim" class="filtro-input" value="<?php echo $data_fim; ?>">
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar"><i class="fas fa-search me-1"></i> Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Justificativas -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-list me-2"></i> Lista de Justificativas
            <span class="badge bg-light text-dark ms-2"><?php echo count($justificativas); ?> registros</span>
        </div>
        <div class="card-body" style="padding: 0; overflow-x: auto;">
            <?php if (empty($justificativas)): ?>
                <div class="text-center p-5 text-muted">
                    <i class="fas fa-inbox fa-3x mb-3"></i>
                    <p>Nenhuma justificativa encontrada.</p>
                </div>
            <?php else: ?>
                <table class="table-justificativas">
                    <thead>
                        <tr>
                            <th>Aluno</th>
                            <th>Matrícula</th>
                            <th>Turma</th>
                            <th>Disciplina</th>
                            <th>Data Aula</th>
                            <th>Atraso</th>
                            <th>Bim</th>
                            <th>Status</th>
                            <th>Documento</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($justificativas as $j): 
                            if (strpos($j['observacao'], 'JUSTIFICATIVA APROVADA') !== false) {
                                $status_class = 'status-aprovado';
                                $status_text = 'Aprovado';
                            } elseif (strpos($j['observacao'], 'JUSTIFICATIVA REJEITADA') !== false) {
                                $status_class = 'status-rejeitado';
                                $status_text = 'Rejeitado';
                            } else {
                                $status_class = 'status-pendente';
                                $status_text = 'Pendente';
                            }
                            
                            $minutos_atraso = $j['minutos_atraso'] > 0 ? $j['minutos_atraso'] . ' min' : '-';
                        ?>
                            <tr>
                                <td class="text-start"><strong><?php echo htmlspecialchars($j['aluno_nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($j['aluno_matricula']); ?></td>
                                <td><?php echo $j['turma_ano']; ?>ª - <?php echo htmlspecialchars($j['turma_nome']); ?></td>
                                <td><?php echo htmlspecialchars($j['disciplina_nome']); ?></td>
                                <td><?php echo date('d/m/Y', strtotime($j['data_aula'])); ?> <small><?php echo substr($j['horario_inicio'], 0, 5); ?>h</small></td>
                                <td><?php echo $minutos_atraso; ?></td>
                                <td><?php echo $j['bimestre']; ?>º</td>
                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                <td>
                                    <?php if (!empty($j['documento_justificativa'])): ?>
                                        <a href="<?php echo $caminho_base . $j['documento_justificativa']; ?>" class="documento-link" target="_blank">
                                            <i class="fas fa-file-pdf"></i> Ver
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-acao btn-info" onclick="verJustificativa(<?php echo $j['id']; ?>, '<?php echo htmlspecialchars($j['justificativa']); ?>', '<?php echo htmlspecialchars($j['observacao']); ?>')">
                                        <i class="fas fa-eye"></i> Ver
                                    </button>
                                    <?php if (strpos($j['observacao'], 'JUSTIFICATIVA APROVADA') === false && strpos($j['observacao'], 'JUSTIFICATIVA REJEITADA') === false): ?>
                                        <button class="btn-acao btn-aprovar" onclick="abrirModalAprovar(<?php echo $j['id']; ?>, '<?php echo htmlspecialchars($j['aluno_nome']); ?>')">
                                            <i class="fas fa-check"></i> Aprovar
                                        </button>
                                        <button class="btn-acao btn-rejeitar" onclick="abrirModalRejeitar(<?php echo $j['id']; ?>, '<?php echo htmlspecialchars($j['aluno_nome']); ?>')">
                                            <i class="fas fa-times"></i> Rejeitar
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal Ver Justificativa -->
<div id="modalVerJustificativa" class="modal-custom">
    <div class="modal-custom-content" style="max-width: 600px;">
        <div class="modal-custom-header">
            <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i> Justificativa do Aluno</h5>
            <span class="close-modal" onclick="fecharModal('modalVerJustificativa')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p><strong>Aluno:</strong> <span id="ver_aluno_nome"></span></p>
            <div class="justificativa-texto" id="ver_justificativa_texto"></div>
            <hr>
            <p><strong>Observações/Histórico:</strong></p>
            <div class="justificativa-texto" id="ver_observacao_texto" style="background:#e9ecef;"></div>
            <div class="text-end">
                <button class="btn-acao btn-info" onclick="fecharModal('modalVerJustificativa')">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Aprovar -->
<div id="modalAprovar" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i> Aprovar Justificativa</h5>
            <span class="close-modal" onclick="fecharModal('modalAprovar')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p>Deseja aprovar a justificativa de <strong id="aprovado_nome"></strong>?</p>
            <textarea id="observacao_aprovacao" class="form-control" rows="3" placeholder="Observações (opcional)"></textarea>
            <input type="hidden" id="aprovado_id">
            <div class="mt-3">
                <button class="btn-acao btn-aprovar" onclick="confirmarAprovacao()">Confirmar Aprovação</button>
                <button class="btn-acao btn-rejeitar" onclick="fecharModal('modalAprovar')">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Rejeitar -->
<div id="modalRejeitar" class="modal-custom">
    <div class="modal-custom-content">
        <div class="modal-custom-header">
            <h5 class="mb-0"><i class="fas fa-times-circle me-2"></i> Rejeitar Justificativa</h5>
            <span class="close-modal" onclick="fecharModal('modalRejeitar')">&times;</span>
        </div>
        <div class="modal-custom-body">
            <p>Deseja rejeitar a justificativa de <strong id="rejeitado_nome"></strong>?</p>
            <textarea id="motivo_rejeicao" class="form-control" rows="3" placeholder="Informe o motivo da rejeição" required></textarea>
            <input type="hidden" id="rejeitado_id">
            <div class="mt-3">
                <button class="btn-acao btn-rejeitar" onclick="confirmarRejeicao()">Confirmar Rejeição</button>
                <button class="btn-acao btn-aprovar" onclick="fecharModal('modalRejeitar')">Cancelar</button>
            </div>
        </div>
    </div>
</div>

<script>
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        toast.textContent = message;
        toast.style.backgroundColor = isError ? '#e74c3c' : '#27ae60';
        toast.style.display = 'block';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 3000);
    }
    
    function filtrarPorStatus(status) {
        const url = new URL(window.location.href);
        url.searchParams.set('status', status);
        window.location.href = url.toString();
    }
    
    function verJustificativa(id, justificativa, observacao) {
        document.getElementById('ver_justificativa_texto').innerHTML = (justificativa || '').replace(/\n/g, '<br>');
        document.getElementById('ver_observacao_texto').innerHTML = (observacao || 'Nenhuma observação registrada.').replace(/\n/g, '<br>');
        document.getElementById('modalVerJustificativa').style.display = 'block';
    }
    
    function abrirModalAprovar(id, nome) {
        document.getElementById('aprovado_id').value = id;
        document.getElementById('aprovado_nome').innerText = nome;
        document.getElementById('observacao_aprovacao').value = '';
        document.getElementById('modalAprovar').style.display = 'block';
    }
    
    function abrirModalRejeitar(id, nome) {
        document.getElementById('rejeitado_id').value = id;
        document.getElementById('rejeitado_nome').innerText = nome;
        document.getElementById('motivo_rejeicao').value = '';
        document.getElementById('modalRejeitar').style.display = 'block';
    }
    
    function confirmarAprovacao() {
        const id = document.getElementById('aprovado_id').value;
        const observacao = document.getElementById('observacao_aprovacao').value;
        
        const formData = new FormData();
        formData.append('action', 'aprovar');
        formData.append('chamada_id', id);
        formData.append('observacao', observacao);
        
        fetch('justificativas.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            showToast('Justificativa aprovada com sucesso!');
            setTimeout(() => location.reload(), 1500);
        })
        .catch(error => showToast('Erro ao aprovar: ' + error, true));
        
        fecharModal('modalAprovar');
    }
    
    function confirmarRejeicao() {
        const id = document.getElementById('rejeitado_id').value;
        const motivo = document.getElementById('motivo_rejeicao').value;
        
        if (!motivo) {
            showToast('Por favor, informe o motivo da rejeição.', true);
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'rejeitar');
        formData.append('chamada_id', id);
        formData.append('motivo_rejeicao', motivo);
        
        fetch('justificativas.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(() => {
            showToast('Justificativa rejeitada.');
            setTimeout(() => location.reload(), 1500);
        })
        .catch(error => showToast('Erro ao rejeitar: ' + error, true));
        
        fecharModal('modalRejeitar');
    }
    
    function fecharModal(modalId) {
        document.getElementById(modalId).style.display = 'none';
    }
    
    window.onclick = function(event) {
        if (event.target.classList.contains('modal-custom')) {
            event.target.style.display = 'none';
        }
    }
</script>
</body>
</html>