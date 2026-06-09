<?php
// escola/professor/registrar_chamada.php - Registrar Chamada com Botões AJAX

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$data_aula = isset($_GET['data']) ? $_GET['data'] : date('Y-m-d');

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$alunos = [];
$chamada_existente = [];
$chamada_existente_obs = [];

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.foto
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Verificar se já existe chamada para esta data
    $sql_chamada = "
        SELECT 
            estudante_id, status, observacao
        FROM chamada
        WHERE turma_id = :turma_id 
        AND disciplina_id = :disciplina_id 
        AND data_aula = :data_aula
        AND professor_id = :professor_id
    ";
    $stmt_chamada = $conn->prepare($sql_chamada);
    $stmt_chamada->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':data_aula' => $data_aula,
        ':professor_id' => $professor_id
    ]);
    
    while ($row = $stmt_chamada->fetch(PDO::FETCH_ASSOC)) {
        $chamada_existente[$row['estudante_id']] = $row['status'];
        $chamada_existente_obs[$row['estudante_id']] = $row['observacao'];
    }
}

// Buscar dados da escola para o PDF
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Buscar nome da disciplina
$disciplina_nome = '';
if ($disciplina_id > 0) {
    $sql_disc = "SELECT nome FROM disciplinas WHERE id = :id";
    $stmt_disc = $conn->prepare($sql_disc);
    $stmt_disc->execute([':id' => $disciplina_id]);
    $disc = $stmt_disc->fetch(PDO::FETCH_ASSOC);
    $disciplina_nome = $disc['nome'] ?? '';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Chamada | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Menu do Professor - Sidebar */
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        /* Sidebar Menu */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            gap: 12px;
            transition: all 0.3s;
        }
        
        .nav-link:hover,
        .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
                position: fixed;
                top: 20px;
                left: 20px;
                z-index: 1001;
                background: #006B3E;
                color: white;
                border: none;
                width: 40px;
                height: 40px;
                border-radius: 8px;
                cursor: pointer;
            }
        }
        
        .menu-toggle {
            display: none;
        }
        
        /* Estilos da Chamada */
        .chamada-table th {
            background: #006B3E;
            color: white;
            text-align: center;
        }
        .chamada-table td {
            vertical-align: middle;
            text-align: center;
        }
        .foto-mini {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
        }
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .resumo-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: transform 0.2s;
        }
        .resumo-card:hover {
            transform: translateY(-3px);
        }
        .resumo-numero {
            font-size: 28px;
            font-weight: bold;
        }
        
        /* Estilos dos botões de status */
        .btn-status {
            border-radius: 25px;
            padding: 8px 20px;
            margin: 3px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            border: none;
        }
        .btn-status:hover {
            transform: scale(1.02);
        }
        .btn-status-active {
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .btn-presente {
            background-color: #28a745;
            color: white;
        }
        .btn-presente:hover, .btn-presente.btn-status-active {
            background-color: #1e7e34;
        }
        .btn-falta {
            background-color: #dc3545;
            color: white;
        }
        .btn-falta:hover, .btn-falta.btn-status-active {
            background-color: #bd2130;
        }
        .btn-atraso {
            background-color: #ffc107;
            color: #212529;
        }
        .btn-atraso:hover, .btn-atraso.btn-status-active {
            background-color: #e0a800;
        }
        .btn-justificado {
            background-color: #17a2b8;
            color: white;
        }
        .btn-justificado:hover, .btn-justificado.btn-status-active {
            background-color: #117a8b;
        }
        
        /* Botões de Ações Rápidas */
        .btn-acao-rapida {
            border-radius: 25px;
            padding: 10px 24px;
            margin: 5px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-acao-rapida:hover {
            transform: translateY(-2px);
        }
        .btn-todos-presentes {
            background-color: #28a745;
            color: white;
            border: none;
        }
        .btn-todos-faltas {
            background-color: #dc3545;
            color: white;
            border: none;
        }
        .btn-limpar {
            background-color: #6c757d;
            color: white;
            border: none;
        }
        .btn-ajuda {
            background-color: #17a2b8;
            color: white;
            border: none;
        }
        
        /* Botões de Relatórios */
        .btn-relatorio {
            border-radius: 25px;
            padding: 10px 20px;
            margin: 5px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-relatorio:hover {
            transform: translateY(-2px);
        }
        .btn-historico {
            background-color: #6f42c1;
            color: white;
            border: none;
        }
        .btn-pdf {
            background-color: #dc3545;
            color: white;
            border: none;
        }
        .btn-excel {
            background-color: #28a745;
            color: white;
            border: none;
        }
        .btn-imprimir {
            background-color: #17a2b8;
            color: white;
            border: none;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-badge-presente { background: #d4edda; color: #155724; }
        .status-badge-falta { background: #f8d7da; color: #721c24; }
        .status-badge-atraso { background: #fff3cd; color: #856404; }
        .status-badge-justificado { background: #d1ecf1; color: #0c5460; }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin: 0;
                padding: 0;
            }
            .sidebar {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Botão Menu Toggle para Mobile -->
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-clipboard-list"></i> Registrar Chamada</h2>
            <div class="no-print">
                <button type="button" class="btn btn-ajuda me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                    <i class="fas fa-question-circle"></i> Ajuda
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Botões de Relatórios -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        <div class="row mb-3 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-center flex-wrap">
                            <button type="button" class="btn-relatorio btn-historico" onclick="window.location.href='historico_chamada.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>'">
                                <i class="fas fa-history"></i> Histórico Completo
                            </button>
                            <button type="button" class="btn-relatorio btn-pdf" onclick="gerarPDFChamada()">
                                <i class="fas fa-file-pdf"></i> Gerar PDF
                            </button>
                            <button type="button" class="btn-relatorio btn-excel" onclick="gerarExcel()">
                                <i class="fas fa-file-excel"></i> Gerar Excel
                            </button>
                            <button type="button" class="btn-relatorio btn-imprimir" onclick="imprimirChamada()">
                                <i class="fas fa-print"></i> Imprimir
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end" id="formFiltros">
                <div class="col-md-3">
                    <label class="form-label">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data</label>
                    <input type="date" name="data" class="form-control" value="<?php echo $data_aula; ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        
        <!-- Resumo da Turma (atualiza automaticamente) -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="resumo-card text-center">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <div class="resumo-numero" id="totalAlunos"><?php echo count($alunos); ?></div>
                    <small>Total de Alunos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card text-center">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="resumo-numero" id="resumoPresentes">0</div>
                    <small>Presentes</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card text-center">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="resumo-numero" id="resumoFaltas">0</div>
                    <small>Faltas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card text-center">
                    <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                    <div class="resumo-numero" id="resumoOutros">0</div>
                    <small>Atrasos/Justificados</small>
                </div>
            </div>
        </div>
        
        <!-- Botões de Ações Rápidas -->
        <div class="row mb-3 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="d-flex justify-content-center flex-wrap">
                            <button type="button" class="btn-acao-rapida btn-todos-presentes" onclick="marcarTodos('presente')">
                                <i class="fas fa-check-circle"></i> Marcar Todos Presentes
                            </button>
                            <button type="button" class="btn-acao-rapida btn-todos-faltas" onclick="marcarTodos('falta')">
                                <i class="fas fa-times-circle"></i> Marcar Todos em Falta
                            </button>
                            <button type="button" class="btn-acao-rapida btn-warning" onclick="marcarTodos('atraso')" style="background-color:#ffc107;color:#212529;border:none;">
                                <i class="fas fa-clock"></i> Marcar Todos Atraso
                            </button>
                            <button type="button" class="btn-acao-rapida btn-info" onclick="marcarTodos('justificado')" style="background-color:#17a2b8;color:white;border:none;">
                                <i class="fas fa-file-alt"></i> Marcar Todos Justificados
                            </button>
                            <button type="button" class="btn-acao-rapida btn-limpar" onclick="confirmarLimparTudo()">
                                <i class="fas fa-trash-alt"></i> Limpar Todos
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Formulário de Chamada com Botões -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-clipboard-list"></i> 
                    Chamada - <?php echo date('d/m/Y', strtotime($data_aula)); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover chamada-table" id="tabelaChamada">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="8%">Foto</th>
                                <th width="25%">Aluno</th>
                                <th width="12%">Matrícula</th>
                                <th width="35%">Status</th>
                                <th width="15%">Status Atual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno): 
                                $status_atual = isset($chamada_existente[$aluno['id']]) ? $chamada_existente[$aluno['id']] : 'presente';
                                $status_textos = [
                                    'presente' => 'Presente',
                                    'falta' => 'Falta',
                                    'atraso' => 'Atraso',
                                    'justificado' => 'Justificado'
                                ];
                                $status_classes = [
                                    'presente' => 'status-badge-presente',
                                    'falta' => 'status-badge-falta',
                                    'atraso' => 'status-badge-atraso',
                                    'justificado' => 'status-badge-justificado'
                                ];
                                $status_texto = isset($status_textos[$status_atual]) ? $status_textos[$status_atual] : 'Presente';
                                $status_class = isset($status_classes[$status_atual]) ? $status_classes[$status_atual] : 'status-badge-presente';
                            ?>
                            <tr id="row-<?php echo $aluno['id']; ?>" data-aluno-id="<?php echo $aluno['id']; ?>">
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td class="text-center">
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-mini">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="foto-mini">
                                    <?php endif; ?>
                                </td>
                                <td class="text-start">
                                    <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td class="text-center">
                                    <div class="btn-group no-print" role="group">
                                        <button type="button" 
                                                class="btn-status btn-presente <?php echo $status_atual == 'presente' ? 'btn-status-active' : ''; ?>"
                                                onclick="registrarStatus(<?php echo $aluno['id']; ?>, 'presente')">
                                            <i class="fas fa-check-circle"></i> Presente
                                        </button>
                                        <button type="button" 
                                                class="btn-status btn-falta <?php echo $status_atual == 'falta' ? 'btn-status-active' : ''; ?>"
                                                onclick="registrarStatus(<?php echo $aluno['id']; ?>, 'falta')">
                                            <i class="fas fa-times-circle"></i> Falta
                                        </button>
                                        <button type="button" 
                                                class="btn-status btn-atraso <?php echo $status_atual == 'atraso' ? 'btn-status-active' : ''; ?>"
                                                onclick="registrarStatus(<?php echo $aluno['id']; ?>, 'atraso')">
                                            <i class="fas fa-clock"></i> Atraso
                                        </button>
                                        <button type="button" 
                                                class="btn-status btn-justificado <?php echo $status_atual == 'justificado' ? 'btn-status-active' : ''; ?>"
                                                onclick="registrarStatus(<?php echo $aluno['id']; ?>, 'justificado')">
                                            <i class="fas fa-file-alt"></i> Justificado
                                        </button>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span class="status-badge <?php echo $status_class; ?>" id="status-label-<?php echo $aluno['id']; ?>">
                                        <?php echo $status_texto; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id > 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum aluno encontrado nesta turma.
            </div>
        <?php elseif ($turma_id > 0): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> Selecione uma disciplina para continuar.
            </div>
        <?php else: ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para iniciar a chamada.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade no-print" id="modalAjuda" tabindex="-1" aria-labelledby="modalAjudaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title" id="modalAjudaLabel">
                        <i class="fas fa-question-circle"></i> Ajuda - Registro de Chamada
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-info-circle"></i> Sobre a Chamada
                        </div>
                        <p>O registro de chamada permite marcar a presença ou ausência dos alunos em cada aula. Os status disponíveis são:</p>
                        <ul>
                            <li><span class="badge bg-success">✅ Presente</span> - Aluno compareceu à aula normalmente</li>
                            <li><span class="badge bg-danger">❌ Falta</span> - Aluno não compareceu à aula</li>
                            <li><span class="badge bg-warning text-dark">⏰ Atraso</span> - Aluno chegou após o horário permitido</li>
                            <li><span class="badge bg-info">📋 Justificado</span> - Falta com justificativa (atestado médico, etc.)</li>
                        </ul>
                    </div>
                    
                    <div class="help-section">
                        <div class="help-title">
                            <i class="fas fa-hand-pointer"></i> Como Registrar
                        </div>
                        <ol>
                            <li>Selecione a <strong>Turma</strong> no filtro superior</li>
                            <li>Selecione a <strong>Disciplina</strong> que está lecionando</li>
                            <li>Escolha a <strong>Data</strong> da aula (padrão é a data atual)</li>
                            <li>Clique nos botões de status para cada aluno</li>
                            <li>O status é <strong>salvo automaticamente</strong> ao clicar no botão</li>
                            <li>Use os botões de <strong>Ações Rápidas</strong> para marcar todos os alunos de uma vez</li>
                        </ol>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-headset"></i> <strong>Precisa de mais ajuda?</strong>
                        <p class="mb-0 mt-1">Entre em contato com o suporte técnico da sua escola.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Imprimir Ajuda
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu Toggle para Mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Inicializar Toast
        let toastEl = document.getElementById('notificationToast');
        let toast = toastEl ? new bootstrap.Toast(toastEl, { delay: 2000 }) : null;
        
        function showToast(message, type = 'success') {
            // Criar toast se não existir
            let toastContainer = document.querySelector('.toast-notification');
            if (!toastContainer) {
                toastContainer = document.createElement('div');
                toastContainer.className = 'toast-notification no-print';
                document.body.appendChild(toastContainer);
            }
            
            const toastHTML = `
                <div class="toast" role="alert" data-bs-autohide="true" data-bs-delay="2000">
                    <div class="toast-header" style="background: ${type === 'success' ? '#d4edda' : '#f8d7da'}; color: ${type === 'success' ? '#155724' : '#721c24'}">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'} me-2"></i>
                        <strong class="me-auto">SIGE Angola</strong>
                        <small>agora</small>
                        <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                    </div>
                    <div class="toast-body">
                        ${message}
                    </div>
                </div>
            `;
            toastContainer.innerHTML = toastHTML;
            const toast = new bootstrap.Toast(toastContainer.querySelector('.toast'));
            toast.show();
        }
        
        function atualizarResumo() {
            let presentes = 0;
            let faltas = 0;
            let outros = 0;
            
            document.querySelectorAll('.status-badge').forEach(badge => {
                const texto = badge.textContent;
                if (texto === 'Presente') {
                    presentes++;
                } else if (texto === 'Falta') {
                    faltas++;
                } else {
                    outros++;
                }
            });
            
            const totalPresentes = document.getElementById('resumoPresentes');
            const totalFaltas = document.getElementById('resumoFaltas');
            const totalOutros = document.getElementById('resumoOutros');
            
            if (totalPresentes) totalPresentes.textContent = presentes;
            if (totalFaltas) totalFaltas.textContent = faltas;
            if (totalOutros) totalOutros.textContent = outros;
        }
        
        function atualizarBotoes(alunoId, status) {
            const row = document.getElementById(`row-${alunoId}`);
            if (!row) return;
            
            const botoes = row.querySelectorAll('.btn-status');
            botoes.forEach(btn => {
                btn.classList.remove('btn-status-active');
            });
            
            let botaoClicado;
            if (status === 'presente') botaoClicado = row.querySelector('.btn-presente');
            else if (status === 'falta') botaoClicado = row.querySelector('.btn-falta');
            else if (status === 'atraso') botaoClicado = row.querySelector('.btn-atraso');
            else if (status === 'justificado') botaoClicado = row.querySelector('.btn-justificado');
            
            if (botaoClicado) {
                botaoClicado.classList.add('btn-status-active');
            }
            
            const statusLabel = document.getElementById(`status-label-${alunoId}`);
            if (statusLabel) {
                const statusTextos = {
                    'presente': 'Presente',
                    'falta': 'Falta',
                    'atraso': 'Atraso',
                    'justificado': 'Justificado'
                };
                const statusClasses = {
                    'presente': 'status-badge-presente',
                    'falta': 'status-badge-falta',
                    'atraso': 'status-badge-atraso',
                    'justificado': 'status-badge-justificado'
                };
                
                statusLabel.textContent = statusTextos[status];
                statusLabel.className = `status-badge ${statusClasses[status]}`;
            }
        }
        
        function marcarTodos(status) {
            const alunos = document.querySelectorAll('.status-badge');
            if (alunos.length === 0) return;
            
            let mensagem = '';
            switch(status) {
                case 'presente': mensagem = 'Marcar TODOS os alunos como Presentes?'; break;
                case 'falta': mensagem = 'Marcar TODOS os alunos como Falta?'; break;
                case 'atraso': mensagem = 'Marcar TODOS os alunos como Atraso?'; break;
                case 'justificado': mensagem = 'Marcar TODOS os alunos como Justificado?'; break;
                default: return;
            }
            
            if (confirm(mensagem)) {
                document.querySelectorAll('.status-badge').forEach(badge => {
                    const row = badge.closest('tr');
                    const alunoId = row ? row.getAttribute('data-aluno-id') : null;
                    if (alunoId) {
                        registrarStatus(parseInt(alunoId), status, false);
                    }
                });
                setTimeout(() => {
                    showToast(`Todos os alunos foram marcados como ${getStatusTexto(status)}!`, 'success');
                    atualizarResumo();
                }, 500);
            }
        }
        
        function confirmarLimparTudo() {
            if (confirm('ATENÇÃO: Isso irá remover TODOS os registros de chamada desta data para esta turma/disciplina.\n\nDeseja continuar?')) {
                const dados = new FormData();
                dados.append('turma_id', <?php echo $turma_id; ?>);
                dados.append('disciplina_id', <?php echo $disciplina_id; ?>);
                dados.append('data_aula', '<?php echo $data_aula; ?>');
                dados.append('professor_id', <?php echo $professor_id; ?>);
                dados.append('limpar_tudo', '1');
                
                fetch('ajax_registrar_chamada.php', {
                    method: 'POST',
                    body: dados
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('.status-badge').forEach(badge => {
                            const row = badge.closest('tr');
                            const alunoId = row ? row.getAttribute('data-aluno-id') : null;
                            if (alunoId) {
                                atualizarBotoes(parseInt(alunoId), 'presente');
                            }
                        });
                        showToast('Todos os registros foram removidos!', 'success');
                        atualizarResumo();
                    } else {
                        showToast(data.message || 'Erro ao limpar registros', 'error');
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    showToast('Erro de conexão com o servidor', 'error');
                });
            }
        }
        
        function registrarStatus(alunoId, status, mostrarToast = true) {
            return new Promise((resolve) => {
                const row = document.getElementById(`row-${alunoId}`);
                if (!row) {
                    resolve(false);
                    return;
                }
                
                let botaoClicado;
                if (status === 'presente') botaoClicado = row.querySelector('.btn-presente');
                else if (status === 'falta') botaoClicado = row.querySelector('.btn-falta');
                else if (status === 'atraso') botaoClicado = row.querySelector('.btn-atraso');
                else if (status === 'justificado') botaoClicado = row.querySelector('.btn-justificado');
                
                if (!botaoClicado) {
                    resolve(false);
                    return;
                }
                
                const textoOriginal = botaoClicado.innerHTML;
                botaoClicado.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                botaoClicado.disabled = true;
                
                const dados = new FormData();
                dados.append('estudante_id', alunoId);
                dados.append('status', status);
                dados.append('turma_id', <?php echo $turma_id; ?>);
                dados.append('disciplina_id', <?php echo $disciplina_id; ?>);
                dados.append('data_aula', '<?php echo $data_aula; ?>');
                dados.append('escola_id', <?php echo $escola_id; ?>);
                dados.append('ano_letivo_id', <?php echo $ano_letivo_id; ?>);
                dados.append('professor_id', <?php echo $professor_id; ?>);
                
                fetch('ajax_registrar_chamada.php', {
                    method: 'POST',
                    body: dados
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        atualizarBotoes(alunoId, status);
                        if (mostrarToast) {
                            showToast(`Status alterado para ${getStatusTexto(status)}!`, 'success');
                        }
                        atualizarResumo();
                        resolve(true);
                    } else {
                        if (mostrarToast) {
                            showToast(data.message || 'Erro ao registrar status', 'error');
                        }
                        resolve(false);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    if (mostrarToast) {
                        showToast('Erro de conexão com o servidor', 'error');
                    }
                    resolve(false);
                })
                .finally(() => {
                    botaoClicado.innerHTML = textoOriginal;
                    botaoClicado.disabled = false;
                });
            });
        }
        
        function getStatusTexto(status) {
            const textos = {
                'presente': 'Presente',
                'falta': 'Falta',
                'atraso': 'Atraso',
                'justificado': 'Justificado'
            };
            return textos[status] || status;
        }
        
        function gerarPDFChamada() {
            const turmaId = <?php echo $turma_id; ?>;
            const disciplinaId = <?php echo $disciplina_id; ?>;
            const dataAula = '<?php echo $data_aula; ?>';
            showToast('Gerando PDF... Por favor, aguarde.', 'info');
            window.open(`gerar_pdf_chamada.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&data=${dataAula}`, '_blank');
        }
        
        function gerarExcel() {
            window.location.href = 'gerar_excel_chamada.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&data=<?php echo $data_aula; ?>';
        }
        
        function imprimirChamada() {
            window.print();
        }
        
        // Inicializar resumo ao carregar
        setTimeout(atualizarResumo, 100);
    </script>
</body>
</html>