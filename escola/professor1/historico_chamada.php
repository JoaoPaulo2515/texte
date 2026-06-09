<?php
// escola/professor/historico_chamada.php - Histórico Completo de Chamadas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-01');
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-t');
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$aluno_filtro = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;

// ============================================
// VALIDAÇÕES
// ============================================
if ($turma_id <= 0 || $disciplina_id <= 0) {
    header('Location: dashboard.php?erro=Parâmetros inválidos');
    exit;
}

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "SELECT t.*, COUNT(DISTINCT m.estudante_id) as total_alunos
              FROM turmas t
              LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
              WHERE t.id = :turma_id AND t.escola_id = :escola_id
              GROUP BY t.id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    header('Location: dashboard.php?erro=Turma não encontrada');
    exit;
}

// ============================================
// BUSCAR DISCIPLINA
// ============================================
$sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
$stmt_disciplina = $conn->prepare($sql_disciplina);
$stmt_disciplina->execute([':id' => $disciplina_id]);
$disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);

if (!$disciplina) {
    header('Location: dashboard.php?erro=Disciplina não encontrada');
    exit;
}

// ============================================
// BUSCAR ALUNOS DA TURMA PARA FILTRO
// ============================================
$sql_alunos = "SELECT e.id, e.nome, e.matricula
               FROM matriculas m
               INNER JOIN estudantes e ON e.id = m.estudante_id
               WHERE m.turma_id = :turma_id AND m.status = 'ativa'
               ORDER BY e.nome";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos_lista = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR HISTÓRICO DE CHAMADAS
// ============================================
$sql_historico = "
    SELECT 
        c.*,
        e.nome as aluno_nome,
        e.matricula as aluno_matricula,
        d.nome as disciplina_nome,
        t.nome as turma_nome,
        t.ano as turma_ano,
        p.nome as professor_nome
    FROM chamada c
    INNER JOIN estudantes e ON e.id = c.estudante_id
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN turmas t ON t.id = c.turma_id
    INNER JOIN funcionarios p ON p.id = c.professor_id
    WHERE c.turma_id = :turma_id 
    AND c.disciplina_id = :disciplina_id
    AND c.data_aula BETWEEN :data_inicio AND :data_fim
";

if ($aluno_filtro > 0) {
    $sql_historico .= " AND c.estudante_id = :aluno_id";
}
if ($status_filtro != 'todos') {
    $sql_historico .= " AND c.status = :status";
}

$sql_historico .= " ORDER BY c.data_aula DESC, e.nome";

$stmt_historico = $conn->prepare($sql_historico);
$params = [
    ':turma_id' => $turma_id,
    ':disciplina_id' => $disciplina_id,
    ':data_inicio' => $data_inicio,
    ':data_fim' => $data_fim
];
if ($aluno_filtro > 0) {
    $params[':aluno_id'] = $aluno_filtro;
}
if ($status_filtro != 'todos') {
    $params[':status'] = $status_filtro;
}
$stmt_historico->execute($params);
$chamadas = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_registros = count($chamadas);
$total_presentes = 0;
$total_faltas = 0;
$total_atrasos = 0;
$total_justificados = 0;
$dias_letivos = [];

foreach ($chamadas as $c) {
    switch ($c['status']) {
        case 'presente': $total_presentes++; break;
        case 'falta': $total_faltas++; break;
        case 'atraso': $total_atrasos++; break;
        case 'justificado': $total_justificados++; break;
    }
    $dias_letivos[$c['data_aula']] = true;
}
$total_dias = count($dias_letivos);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'presente':
            return '<span class="badge bg-success">✅ Presente</span>';
        case 'falta':
            return '<span class="badge bg-danger">❌ Falta</span>';
        case 'atraso':
            return '<span class="badge bg-warning text-dark">⏰ Atraso</span>';
        case 'justificado':
            return '<span class="badge bg-info">📋 Justificado</span>';
        default:
            return '<span class="badge bg-secondary">-</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataHora($data) {
    if (empty($data)) return '-';
    return date('d/m/Y H:i', strtotime($data));
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Chamadas - <?php echo htmlspecialchars($disciplina['nome']); ?> | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #008B4E 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s;
            margin-bottom: 15px;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #006B3E;
        }
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
        }
        .table-chamadas th {
            background: #006B3E;
            color: white;
            text-align: center;
        }
        .table-chamadas td {
            vertical-align: middle;
            text-align: center;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        .btn-excel {
            background: #28a745;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-excel:hover {
            background: #1e7e34;
            color: white;
        }
        .btn-pdf {
            background: #dc3545;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-pdf:hover {
            background: #bd2130;
            color: white;
        }
        .btn-print {
            background: #17a2b8;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-print:hover {
            background: #138496;
            color: white;
        }
        .foto-mini {
            width: 35px;
            height: 35px;
            object-fit: cover;
            border-radius: 50%;
        }
        .resumo-info {
            background: #e9ecef;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin: 0;
                padding: 0;
            }
            .page-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .table-chamadas th {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="mb-1">
                        <i class="fas fa-history"></i> Histórico de Chamadas
                    </h2>
                    <p class="mb-0">
                        <i class="fas fa-chalkboard-user"></i> Turma: <?php echo $turma['ano'] . 'ª ' . $turma['nome']; ?> |
                        <i class="fas fa-book"></i> Disciplina: <?php echo htmlspecialchars($disciplina['nome']); ?> |
                        <i class="fas fa-calendar"></i> Período: <?php echo formatarData($data_inicio); ?> a <?php echo formatarData($data_fim); ?>
                    </p>
                </div>
                <div class="no-print">
                    <a href="javascript:history.back()" class="btn-voltar btn me-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button onclick="window.print()" class="btn-print btn me-2">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button onclick="gerarExcel()" class="btn-excel btn me-2">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                    <button onclick="gerarPDF()" class="btn-pdf btn">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print">
            <form method="GET" class="row align-items-end">
                <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                
                <div class="col-md-3">
                    <label class="form-label">Data Início</label>
                    <input type="date" name="data_inicio" class="form-control" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Data Fim</label>
                    <input type="date" name="data_fim" class="form-control" value="<?php echo $data_fim; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Aluno</label>
                    <select name="aluno_id" class="form-select">
                        <option value="0">Todos os alunos</option>
                        <?php foreach ($alunos_lista as $aluno): ?>
                        <option value="<?php echo $aluno['id']; ?>" <?php echo $aluno_filtro == $aluno['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="presente" <?php echo $status_filtro == 'presente' ? 'selected' : ''; ?>>Presente</option>
                        <option value="falta" <?php echo $status_filtro == 'falta' ? 'selected' : ''; ?>>Falta</option>
                        <option value="atraso" <?php echo $status_filtro == 'atraso' ? 'selected' : ''; ?>>Atraso</option>
                        <option value="justificado" <?php echo $status_filtro == 'justificado' ? 'selected' : ''; ?>>Justificado</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_dias; ?></div>
                    <div class="stat-label">Dias Letivos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_registros; ?></div>
                    <div class="stat-label">Total de Registros</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo $total_presentes; ?></div>
                    <div class="stat-label">Presentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-danger"><?php echo $total_faltas; ?></div>
                    <div class="stat-label">Faltas</div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo $total_atrasos; ?></div>
                    <div class="stat-label">Atrasos</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number text-info"><?php echo $total_justificados; ?></div>
                    <div class="stat-label">Justificados</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card">
                    <div class="stat-number">
                        <?php 
                        $percentual = $total_registros > 0 ? round(($total_presentes / $total_registros) * 100, 1) : 0;
                        echo $percentual; ?>%
                    </div>
                    <div class="stat-label">Taxa de Presença</div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Chamadas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list"></i> Registros de Chamada
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-chamadas" id="tabelaChamadas">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Data</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Status</th>
                                <th>Observação</th>
                                <th>Professor</th>
                                <th>Registrado em</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($chamadas)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">
                                    <i class="fas fa-info-circle"></i> Nenhum registro encontrado para o período selecionado.
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($chamadas as $index => $c): ?>
                                <tr>
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo formatarData($c['data_aula']); ?></td>
                                    <td class="text-start">
                                        <strong><?php echo htmlspecialchars($c['aluno_nome']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($c['aluno_matricula']); ?></td>
                                    <td><?php echo getStatusBadge($c['status']); ?></td>
                                    <td class="text-start"><?php echo htmlspecialchars($c['observacao'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($c['professor_nome']); ?></td>
                                    <td><?php echo formatarDataHora($c['created_at']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Resumo por Aluno -->
        <?php if ($aluno_filtro == 0 && !empty($chamadas)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-bar"></i> Resumo por Aluno
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-bordered">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Presentes</th>
                                <th>Faltas</th>
                                <th>Atrasos</th>
                                <th>Justificados</th>
                                <th>Total</th>
                                <th>Frequência</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $resumo_alunos = [];
                            foreach ($chamadas as $c) {
                                $aluno_id = $c['estudante_id'];
                                if (!isset($resumo_alunos[$aluno_id])) {
                                    $resumo_alunos[$aluno_id] = [
                                        'nome' => $c['aluno_nome'],
                                        'matricula' => $c['aluno_matricula'],
                                        'presentes' => 0,
                                        'faltas' => 0,
                                        'atrasos' => 0,
                                        'justificados' => 0,
                                        'total' => 0
                                    ];
                                }
                                $resumo_alunos[$aluno_id]['total']++;
                                switch ($c['status']) {
                                    case 'presente': $resumo_alunos[$aluno_id]['presentes']++; break;
                                    case 'falta': $resumo_alunos[$aluno_id]['faltas']++; break;
                                    case 'atraso': $resumo_alunos[$aluno_id]['atrasos']++; break;
                                    case 'justificado': $resumo_alunos[$aluno_id]['justificados']++; break;
                                }
                            }
                            
                            foreach ($resumo_alunos as $aluno):
                                $frequencia = $aluno['total'] > 0 ? round(($aluno['presentes'] / $aluno['total']) * 100, 1) : 0;
                                $barra_color = $frequencia >= 75 ? 'success' : ($frequencia >= 50 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td class="text-center text-success"><?php echo $aluno['presentes']; ?></td>
                                <td class="text-center text-danger"><?php echo $aluno['faltas']; ?></td>
                                <td class="text-center text-warning"><?php echo $aluno['atrasos']; ?></td>
                                <td class="text-center text-info"><?php echo $aluno['justificados']; ?></td>
                                <td class="text-center"><?php echo $aluno['total']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="me-2"><?php echo $frequencia; ?>%</span>
                                        <div class="progress flex-grow-1" style="height: 8px;">
                                            <div class="progress-bar bg-<?php echo $barra_color; ?>" 
                                                 style="width: <?php echo $frequencia; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Rodapé do Relatório -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="resumo-info text-center">
                    <small class="text-muted">
                        <i class="fas fa-file-alt"></i> 
                        Relatório gerado em <?php echo date('d/m/Y H:i:s'); ?> | 
                        SIGE Angola - Sistema Integrado de Gestão Escolar
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#tabelaChamadas').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                },
                order: [[1, 'desc']],
                pageLength: 25,
                responsive: true
            });
        });
        
        function gerarExcel() {
            window.location.href = 'gerar_excel_historico_chamada.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>&status=<?php echo $status_filtro; ?>&aluno_id=<?php echo $aluno_filtro; ?>';
        }
        
        function gerarPDF() {
            window.open('gerar_pdf_historico_chamada.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&data_inicio=<?php echo $data_inicio; ?>&data_fim=<?php echo $data_fim; ?>&status=<?php echo $status_filtro; ?>&aluno_id=<?php echo $aluno_filtro; ?>', '_blank');
        }
    </script>
</body>
</html>