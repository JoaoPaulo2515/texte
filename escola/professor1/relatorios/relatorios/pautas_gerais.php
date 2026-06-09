<?php
// escola/professor/relatorios/pautas_gerais.php - Pautas Gerais de Notas (Todos os Bimestres)

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
// BUSCAR ALUNOS E NOTAS (TODOS OS BIMESTRES)
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
    
    // Buscar alunos e notas (todos os bimestres)
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            n.bimestre,
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
            AND n.ano_letivo_id = :ano_letivo_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome, n.bimestre
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $notas_raw = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar notas por aluno e bimestre
    $alunos_data = [];
    foreach ($notas_raw as $nota) {
        $aluno_id = $nota['id'];
        if (!isset($alunos_data[$aluno_id])) {
            $alunos_data[$aluno_id] = [
                'nome' => $nota['nome'],
                'matricula' => $nota['matricula'],
                'bimestres' => []
            ];
        }
        $bim = $nota['bimestre'];
        if ($bim) {
            $alunos_data[$aluno_id]['bimestres'][$bim] = [
                'mac' => $nota['mac'],
                'npt' => $nota['npt'],
                'media_final' => $nota['media_final'],
                'situacao' => $nota['situacao']
            ];
        }
    }
    
    // Completar bimestres faltantes e calcular média anual
    foreach ($alunos_data as $aluno_id => &$aluno) {
        $soma_medias = 0;
        $bimestres_com_nota = 0;
        for ($b = 1; $b <= 3; $b++) {
            if (!isset($aluno['bimestres'][$b])) {
                $aluno['bimestres'][$b] = [
                    'mac' => null,
                    'npt' => null,
                    'media_final' => 0,
                    'situacao' => 'pendente'
                ];
            } else {
                if ($aluno['bimestres'][$b]['media_final'] > 0) {
                    $soma_medias += $aluno['bimestres'][$b]['media_final'];
                    $bimestres_com_nota++;
                }
            }
        }
        $aluno['media_anual'] = $bimestres_com_nota > 0 ? round($soma_medias / $bimestres_com_nota, 1) : 0;
        
        // Calcular situação final
        if ($aluno['media_anual'] >= 10) {
            $aluno['situacao_final'] = 'aprovado';
        } elseif ($aluno['media_anual'] >= 5) {
            $aluno['situacao_final'] = 'recuperacao';
        } elseif ($aluno['media_anual'] > 0) {
            $aluno['situacao_final'] = 'reprovado';
        } else {
            $aluno['situacao_final'] = 'pendente';
        }
    }
    unset($aluno);
    $alunos = $alunos_data;
}

// ============================================
// ESTATÍSTICAS
// ============================================
$total_alunos = count($alunos);
$total_aprovados = 0;
$total_recuperacao = 0;
$total_reprovados = 0;
$soma_medias_anuais = 0;

foreach ($alunos as $aluno) {
    if ($aluno['situacao_final'] == 'aprovado') $total_aprovados++;
    elseif ($aluno['situacao_final'] == 'recuperacao') $total_recuperacao++;
    elseif ($aluno['situacao_final'] == 'reprovado') $total_reprovados++;
    $soma_medias_anuais += $aluno['media_anual'];
}
$media_geral_anual = $total_alunos > 0 ? round($soma_medias_anuais / $total_alunos, 1) : 0;
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

function getNotaFormatada($nota) {
    if (is_null($nota) || $nota == 0) return '-';
    return number_format($nota, 1);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pautas Gerais | Professor | SIGE Angola</title>
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
            font-size: 11px;
            vertical-align: middle;
        }
        .table-pauta td {
            text-align: center;
            vertical-align: middle;
            font-size: 12px;
        }
        .table-pauta .aluno-cell {
            text-align: left;
            font-weight: bold;
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
        .nota-baixa {
            background-color: #f8d7da;
        }
        .nota-media {
            background-color: #fff3cd;
        }
        .nota-alta {
            background-color: #d4edda;
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
                    <h2><i class="fas fa-file-alt"></i> Pautas Gerais</h2>
                    <p>Relatório completo com todas as notas e bimestres da turma</p>
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
                <div class="col-md-5">
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
                <div class="col-md-5">
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
                    <div class="stat-number"><?php echo $media_geral_anual; ?></div>
                    <div class="stat-label">Média Anual da Turma</div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $percentual_aprovacao; ?>%</div>
                    <div class="stat-label">Taxa de Aprovação</div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Notas (Todos os Bimestres) -->
        <div class="card">
            <div class="card-header bg-white">
                <h5 class="mb-0">
                    <i class="fas fa-chalkboard"></i> 
                    <?php echo $turma_info['ano'] . 'ª - ' . $turma_info['nome']; ?> | 
                    <?php echo htmlspecialchars($disciplina_info['nome']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-pauta">
                        <thead>
                            <tr>
                                <th rowspan="2" width="5%">#</th>
                                <th rowspan="2" width="25%">Aluno</th>
                                <th rowspan="2" width="10%">Matrícula</th>
                                <th colspan="3" class="text-center">1º Bimestre</th>
                                <th colspan="3" class="text-center">2º Bimestre</th>
                                <th colspan="3" class="text-center">3º Bimestre</th>
                                <th rowspan="2" width="8%">Média<br>Anual</th>
                                <th rowspan="2" width="12%">Situação<br>Final</th>
                            </tr>
                            <tr>
                                <th>MAC</th><th>NPT</th><th>Média</th>
                                <th>MAC</th><th>NPT</th><th>Média</th>
                                <th>MAC</th><th>NPT</th><th>Média</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $contador = 1; ?>
                            <?php foreach ($alunos as $aluno_id => $aluno): ?>
                            <tr>
                                <td class="text-center"><?php echo $contador++; ?></td>
                                <td class="aluno-cell"><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                
                                <!-- 1º Bimestre -->
                                <?php $b1 = $aluno['bimestres'][1]; ?>
                                <td class="text-center <?php echo ($b1['media_final'] >= 9.5 ? 'nota-alta' : ($b1['media_final'] >= 5 ? 'nota-media' : ($b1['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <?php echo getNotaFormatada($b1['mac']); ?>
                                </td>
                                <td class="text-center <?php echo ($b1['media_final'] >= 9.5 ? 'nota-alta' : ($b1['media_final'] >= 5 ? 'nota-media' : ($b1['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <?php echo getNotaFormatada($b1['npt']); ?>
                                </td>
                                <td class="text-center <?php echo ($b1['media_final'] >= 9.5 ? 'nota-alta' : ($b1['media_final'] >= 5 ? 'nota-media' : ($b1['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <strong><?php echo getNotaFormatada($b1['media_final']); ?></strong>
                                </td>
                                
                                <!-- 2º Bimestre -->
                                <?php $b2 = $aluno['bimestres'][2]; ?>
                                <td class="text-center <?php echo ($b2['media_final'] >= 9.5 ? 'nota-alta' : ($b2['media_final'] >= 5 ? 'nota-media' : ($b2['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <?php echo getNotaFormatada($b2['mac']); ?>
                                </td>
                                <td class="text-center <?php echo ($b2['media_final'] >= 9.5 ? 'nota-alta' : ($b2['media_final'] >= 5 ? 'nota-media' : ($b2['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <?php echo getNotaFormatada($b2['npt']); ?>
                                </td>
                                <td class="text-center <?php echo ($b2['media_final'] >= 9.5 ? 'nota-alta' : ($b2['media_final'] >= 5 ? 'nota-media' : ($b2['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <strong><?php echo getNotaFormatada($b2['media_final']); ?></strong>
                                </td>
                                
                                <!-- 3º Bimestre -->
                                <?php $b3 = $aluno['bimestres'][3]; ?>
                                <td class="text-center <?php echo ($b3['media_final'] >= 9.5 ? 'nota-alta' : ($b3['media_final'] >= 5 ? 'nota-media' : ($b3['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <?php echo getNotaFormatada($b3['mac']); ?>
                                </td>
                                <td class="text-center <?php echo ($b3['media_final'] >= 9.5 ? 'nota-alta' : ($b3['media_final'] >= 5 ? 'nota-media' : ($b3['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <?php echo getNotaFormatada($b3['npt']); ?>
                                </td>
                                <td class="text-center <?php echo ($b3['media_final'] >= 9.5 ? 'nota-alta' : ($b3['media_final'] >= 5 ? 'nota-media' : ($b3['media_final'] > 0 ? 'nota-baixa' : ''))); ?>">
                                    <strong><?php echo getNotaFormatada($b3['media_final']); ?></strong>
                                </td>
                                
                                <!-- Média Anual -->
                                <td class="text-center"><strong><?php echo number_format($aluno['media_anual'], 1); ?></strong></td>
                                
                                <!-- Situação Final -->
                                <td class="text-center"><?php echo getSituacaoBadge($aluno['situacao_final']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Legenda -->
        <div class="card mt-3 no-print">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="fas fa-info-circle"></i> Legenda</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <span class="badge bg-success">Aprovado</span> - Média anual ≥ 10
                    </div>
                    <div class="col-md-3">
                        <span class="badge bg-warning text-dark">Recuperação</span> - Média anual ≥ 5
                    </div>
                    <div class="col-md-3">
                        <span class="badge bg-danger">Reprovado</span> - Média anual < 5
                    </div>
                    <div class="col-md-3">
                        <span class="badge bg-secondary">Pendente</span> - Sem notas lançadas
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3">
                        <span class="badge bg-success">Verde</span> - Nota ≥ 9.5
                    </div>
                    <div class="col-md-3">
                        <span class="badge bg-warning text-dark">Amarelo</span> - Nota 5.0 - 9.4
                    </div>
                    <div class="col-md-3">
                        <span class="badge bg-danger">Vermelho</span> - Nota < 5.0
                    </div>
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
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function gerarPDF() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            if (turmaId && disciplinaId) {
                window.open(`gerar_pdf_pautas_gerais.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}`, '_blank');
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            if (turmaId && disciplinaId) {
                window.location.href = `gerar_excel_pautas_gerais.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}`;
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
    </script>
</body>
</html>