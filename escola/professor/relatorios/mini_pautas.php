<?php
// escola/professor/relatorios/mini_pautas.php - Mini Pautas de Notas

require_once '../includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PARÂMETROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

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
// BUSCAR ALUNOS E NOTAS
// ============================================
$alunos = [];
$turma_info = null;
$disciplina_info = null;

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar info da turma
    $sql_turma_info = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar info da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos e notas
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            n.mac,
            n.npt,
            n.exame_normal,
            n.exame_recurso,
            n.exame_especial,
            n.media_final,
            n.status as situacao
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        LEFT JOIN notas n ON n.estudante_id = e.id 
            AND n.disciplina_id = :disciplina_id 
            AND n.bimestre = :bimestre 
            AND n.ano_letivo_id = :ano_letivo_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_notas = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao'] == 'reprovado') $total_reprovados++;
    
    if ($aluno['media_final'] > 0) {
        $soma_notas += $aluno['media_final'];
    }
}
$media_geral = $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0;
$percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getSituacaoBadge($situacao) {
    switch ($situacao) {
        case 'aprovado':
            return '<span class="badge bg-success">Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning text-dark">Recuperação</span>';
        case 'reprovado':
            return '<span class="badge bg-danger">Reprovado</span>';
        default:
            return '<span class="badge bg-secondary">Pendente</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mini Pautas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
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
            margin-bottom: 15px;
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
        }
        .table-pauta th {
            background: #006B3E;
            color: white;
            text-align: center;
            font-size: 12px;
        }
        .table-pauta td {
            text-align: center;
            vertical-align: middle;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
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
            border: none;
        }
        .btn-print:hover {
            background: #138496;
            color: white;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        @media print {
            .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include '../includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-file-alt"></i> Mini Pautas</h2>
                    <p>Visualize as notas dos alunos de forma rápida e resumida</p>
                </div>
                <div class="no-print">
                    <a href="index.php" class="btn-voltar btn me-2">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button onclick="window.print()" class="btn-print btn me-2">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button onclick="gerarPDF()" class="btn-pdf btn me-2">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button onclick="gerarExcel()" class="btn-excel btn">
                        <i class="fas fa-file-excel"></i> Excel
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
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
                <div class="col-md-4">
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
                <div class="col-md-2">
                    <label class="form-label">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        
        <!-- Resumo da Turma -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_alunos; ?></div>
                    <div class="stat-label">Total de Alunos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-success"><?php echo $total_aprovados; ?></div>
                    <div class="stat-label">Aprovados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-warning"><?php echo $total_recuperacao; ?></div>
                    <div class="stat-label">Recuperação</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-number text-danger"><?php echo $total_reprovados; ?></div>
                    <div class="stat-label">Reprovados</div>
                </div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $media_geral; ?></div>
                    <div class="stat-label">Média Geral da Turma</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $percentual_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Notas -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">
                    <i class="fas fa-chalkboard"></i> 
                    <?php echo $turma_info['ano'] . 'ª - ' . $turma_info['nome']; ?> | 
                    <?php echo htmlspecialchars($disciplina_info['nome']); ?> | 
                    <?php echo $bimestre; ?>º Bimestre
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-pauta">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="30%">Aluno</th>
                                <th width="15%">Matrícula</th>
                                <th width="15%">MAC</th>
                                <th width="15%">NPT</th>
                                <th width="15%">Média</th>
                                <th width="15%">Situação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno): ?>
                            <tr>
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td class="text-start"><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td class="text-center"><?php echo number_format($aluno['mac'] ?? 0, 1); ?></td>
                                <td class="text-center"><?php echo number_format($aluno['npt'] ?? 0, 1); ?></td>
                                <td class="text-center"><strong><?php echo number_format($aluno['media_final'] ?? 0, 1); ?></strong></td>
                                <td class="text-center"><?php echo getSituacaoBadge($aluno['situacao']); ?></td>
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
                <i class="fas fa-filter"></i> Selecione uma turma e disciplina para visualizar as notas.
            </div>
        <?php endif; ?>
        
        <!-- Rodapé do Relatório -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="text-center text-muted small">
                    <hr>
                    <i class="fas fa-file-alt"></i> 
                    Relatório gerado em <?php echo date('d/m/Y H:i:s'); ?> | 
                    SIGE Angola - Sistema Integrado de Gestão Escolar
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function gerarPDF() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (turmaId && disciplinaId) {
                window.open(`gerar_pdf_mini_pautas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            if (turmaId && disciplinaId) {
                window.location.href = `gerar_excel_mini_pautas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
    </script>
</body>
</html>