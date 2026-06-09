<?php
// escola/professor/historico_completo.php - Histórico Completo de Notas da Turma

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$sql_turma = "
    SELECT t.*, COUNT(DISTINCT m.estudante_id) as total_alunos
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE t.id = :turma_id AND t.escola_id = :escola_id
    GROUP BY t.id
";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

if (!$turma) {
    die('Turma não encontrada');
}

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
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

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR PARA ESTA TURMA
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id AND pdt.turma_id = :turma_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id, ':turma_id' => $turma_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

// ============================================
// BUSCAR NOTAS POR DISCIPLINA E ALUNO
// ============================================
$notas_por_aluno = [];
$medias_por_disciplina = [];
$total_geral = 0;
$count_notas = 0;

foreach ($alunos as $aluno) {
    $notas_por_aluno[$aluno['id']] = [];
    foreach ($disciplinas as $disciplina) {
        $sql_notas = "
            SELECT 
                bimestre,
                mac,
                npt,
                exame_normal,
                exame_recurso,
                exame_especial,
                exame_oral,
                exame_escrito,
                media_final,
                status
            FROM notas
            WHERE estudante_id = :estudante_id 
            AND disciplina_id = :disciplina_id 
            AND ano_letivo_id = :ano_letivo_id
            ORDER BY bimestre
        ";
        $stmt_notas = $conn->prepare($sql_notas);
        $stmt_notas->execute([
            ':estudante_id' => $aluno['id'],
            ':disciplina_id' => $disciplina['id'],
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
        
        $media_final = 0;
        $status = 'pendente';
        foreach ($notas as $nota) {
            if ($nota['media_final'] > 0) {
                $media_final = $nota['media_final'];
                $status = $nota['status'];
                break;
            }
        }
        
        $notas_por_aluno[$aluno['id']][$disciplina['id']] = [
            'disciplina_nome' => $disciplina['nome'],
            'notas' => $notas,
            'media_final' => $media_final,
            'status' => $status
        ];
        
        if ($media_final > 0) {
            if (!isset($medias_por_disciplina[$disciplina['id']])) {
                $medias_por_disciplina[$disciplina['id']] = [
                    'nome' => $disciplina['nome'],
                    'soma' => 0,
                    'count' => 0,
                    'total' => 0
                ];
            }
            $medias_por_disciplina[$disciplina['id']]['soma'] += $media_final;
            $medias_por_disciplina[$disciplina['id']]['count']++;
            $total_geral += $media_final;
            $count_notas++;
        }
    }
}

// Calcular médias por disciplina
foreach ($medias_por_disciplina as $id => $disciplina) {
    $medias_por_disciplina[$id]['media'] = $disciplina['count'] > 0 ? round($disciplina['soma'] / $disciplina['count'], 1) : 0;
}

$media_geral_turma = $count_notas > 0 ? round($total_geral / $count_notas, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function getStatusBadge($status) {
    switch ($status) {
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

function formatarNota($nota) {
    if (empty($nota) && $nota !== 0) return '-';
    return number_format($nota, 1);
}

function getSituacaoMedia($media) {
    if ($media >= 10) return ['texto' => 'Aprovado', 'classe' => 'success'];
    if ($media >= 7) return ['texto' => 'Recuperação', 'classe' => 'warning'];
    return ['texto' => 'Reprovado', 'classe' => 'danger'];
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico Completo - <?php echo htmlspecialchars($turma['nome']); ?> | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: white;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 20px;
        }
        .header h1 {
            color: #006B3E;
            font-size: 24px;
            margin-bottom: 5px;
        }
        .header h3 {
            color: #1A2A6C;
            font-size: 18px;
        }
        .info-turma {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #006B3E;
        }
        .table-notas {
            font-size: 12px;
            border-collapse: collapse;
            width: 100%;
        }
        .table-notas th {
            background: #006B3E;
            color: white;
            padding: 10px 5px;
            text-align: center;
            border: 1px solid #ddd;
            font-weight: 600;
        }
        .table-notas td {
            padding: 8px 5px;
            text-align: center;
            border: 1px solid #ddd;
            vertical-align: middle;
        }
        .table-notas td:first-child,
        .table-notas td:nth-child(2) {
            text-align: left;
            font-weight: 500;
        }
        .table-notas tr:hover {
            background: #f8f9fa;
        }
        .media-disciplina {
            background: #e8f5e9;
            font-weight: bold;
        }
        .media-geral {
            background: #006B3E;
            color: white;
            font-weight: bold;
        }
        .foto-mini {
            width: 35px;
            height: 35px;
            object-fit: cover;
            border-radius: 50%;
        }
        .btn-pdf {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .btn-pdf:hover {
            background: #bb2d3b;
            color: white;
        }
        .btn-excel {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .btn-excel:hover {
            background: #1e7e34;
            color: white;
        }
        .btn-print {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 5px;
        }
        .btn-print:hover {
            background: #138496;
            color: white;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                padding: 0;
                margin: 0;
            }
            .table-notas th {
                background: #006B3E !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .media-disciplina {
                background: #e8f5e9 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .media-geral {
                background: #006B3E !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        .badge-aprovado { background: #28a745; color: white; padding: 3px 8px; border-radius: 15px; font-size: 10px; }
        .badge-reprovado { background: #dc3545; color: white; padding: 3px 8px; border-radius: 15px; font-size: 10px; }
        .badge-recuperacao { background: #ffc107; color: #333; padding: 3px 8px; border-radius: 15px; font-size: 10px; }
        .badge-pendente { background: #6c757d; color: white; padding: 3px 8px; border-radius: 15px; font-size: 10px; }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 20px;">
        <div class="d-flex justify-content-between align-items-center">
            <a href="lancar_notas.php?turma_id=<?php echo $turma_id; ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
            <div>
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <button onclick="gerarPDF()" class="btn btn-pdf">
                    <i class="fas fa-file-pdf"></i> Gerar PDF
                </button>
                <button onclick="gerarExcel()" class="btn btn-excel">
                    <i class="fas fa-file-excel"></i> Gerar Excel
                </button>
            </div>
        </div>
    </div>
    
    <div class="header">
        <?php if (!empty($turma['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $turma['escola_logo'])): ?>
            <img src="../../uploads/escolas/logos/<?php echo $turma['escola_logo']; ?>" style="height: 60px;">
        <?php endif; ?>
        <h1>HISTÓRICO DE NOTAS</h1>
        <h3><?php echo htmlspecialchars($turma['escola_nome'] ?? 'ESCOLA'); ?></h3>
    </div>
    
    <div class="info-turma">
        <div class="row">
            <div class="col-md-3">
                <strong><i class="fas fa-users"></i> Turma:</strong> <?php echo $turma['ano'] . 'ª ' . $turma['nome']; ?>
            </div>
            <div class="col-md-3">
                <strong><i class="fas fa-clock"></i> Turno:</strong> <?php echo ucfirst($turma['turno']); ?>
            </div>
            <div class="col-md-3">
                <strong><i class="fas fa-door-open"></i> Sala:</strong> <?php echo $turma['sala'] ?: 'Não definida'; ?>
            </div>
            <div class="col-md-3">
                <strong><i class="fas fa-calendar-alt"></i> Ano Letivo:</strong> <?php echo $ano_letivo['ano'] ?? date('Y'); ?>
            </div>
        </div>
        <div class="row mt-2">
            <div class="col-md-3">
                <strong><i class="fas fa-users"></i> Total Alunos:</strong> <?php echo count($alunos); ?>
            </div>
            <div class="col-md-3">
                <strong><i class="fas fa-chart-line"></i> Média Geral da Turma:</strong> 
                <span class="badge bg-primary"><?php echo number_format($media_geral_turma, 1); ?> valores</span>
            </div>
            <div class="col-md-6">
                <strong><i class="fas fa-book"></i> Disciplinas:</strong> 
                <?php foreach ($disciplinas as $d): ?>
                    <span class="badge bg-secondary"><?php echo htmlspecialchars($d['nome']); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <div class="table-responsive">
        <table class="table-notas">
            <thead>
                <tr>
                    <th width="5%">#</th>
                    <th width="20%">Aluno</th>
                    <th width="10%">Matrícula</th>
                    <?php foreach ($disciplinas as $disciplina): ?>
                    <th width="12%">
                        <?php echo htmlspecialchars($disciplina['nome']); ?>
                        <br>
                        <small>Média Final</small>
                    </th>
                    <?php endforeach; ?>
                    <th width="8%">Média Geral</th>
                    <th width="10%">Situação</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_medias = 0;
                $total_alunos_com_nota = 0;
                ?>
                <?php foreach ($alunos as $index => $aluno): ?>
                <?php 
                    $soma_notas_aluno = 0;
                    $count_notas_aluno = 0;
                ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td>
                        <div class="d-flex align-items-center">
                            <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-mini me-2">
                            <?php else: ?>
                                <img src="../../assets/images/avatar-padrao.png" class="foto-mini me-2">
                            <?php endif; ?>
                            <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                        </div>
                    </td>
                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                    
                    <?php foreach ($disciplinas as $disciplina): ?>
                    <?php 
                        $nota_aluno = $notas_por_aluno[$aluno['id']][$disciplina['id']]['media_final'] ?? 0;
                        $status_aluno = $notas_por_aluno[$aluno['id']][$disciplina['id']]['status'] ?? 'pendente';
                        if ($nota_aluno > 0) {
                            $soma_notas_aluno += $nota_aluno;
                            $count_notas_aluno++;
                        }
                    ?>
                    <td>
                        <?php if ($nota_aluno > 0): ?>
                            <strong><?php echo number_format($nota_aluno, 1); ?></strong>
                            <br>
                            <?php 
                                $badge_class = $status_aluno == 'aprovado' ? 'badge-aprovado' : ($status_aluno == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado');
                            ?>
                            <span class="<?php echo $badge_class; ?>"><?php echo ucfirst($status_aluno); ?></span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                    
                    <?php 
                    $media_aluno = $count_notas_aluno > 0 ? round($soma_notas_aluno / $count_notas_aluno, 1) : 0;
                    if ($media_aluno > 0) {
                        $total_medias += $media_aluno;
                        $total_alunos_com_nota++;
                    }
                    $situacao_aluno = getSituacaoMedia($media_aluno);
                    ?>
                    <td class="fw-bold"><?php echo number_format($media_aluno, 1); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $situacao_aluno['classe']; ?>">
                            <?php echo $situacao_aluno['texto']; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="media-disciplina">
                    <td colspan="3" class="fw-bold text-end">Média da Turma por Disciplina:</td>
                    <?php foreach ($disciplinas as $disciplina): ?>
                    <td class="fw-bold">
                        <?php echo number_format($medias_por_disciplina[$disciplina['id']]['media'] ?? 0, 1); ?>
                    </td>
                    <?php endforeach; ?>
                    <td class="fw-bold">
                        <?php echo number_format($media_geral_turma, 1); ?>
                    </td>
                    <td></td>
                </tr>
                <tr class="media-geral">
                    <td colspan="3" class="fw-bold text-end">Classificação Geral:</td>
                    <?php foreach ($disciplinas as $disciplina): ?>
                    <td class="fw-bold">
                        <?php 
                        $media_disciplina = $medias_por_disciplina[$disciplina['id']]['media'] ?? 0;
                        $situacao_disciplina = getSituacaoMedia($media_disciplina);
                        ?>
                        <span class="badge bg-<?php echo $situacao_disciplina['classe']; ?>">
                            <?php echo $situacao_disciplina['texto']; ?>
                        </span>
                    </td>
                    <?php endforeach; ?>
                    <td class="fw-bold">
                        <?php $situacao_geral = getSituacaoMedia($media_geral_turma); ?>
                        <span class="badge bg-<?php echo $situacao_geral['classe']; ?>">
                            <?php echo $situacao_geral['texto']; ?>
                        </span>
                    </td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <div class="footer">
        <div>Documento emitido eletronicamente - <?php echo date('d/m/Y H:i'); ?></div>
        <div><?php echo htmlspecialchars($turma['escola_nome'] ?? ''); ?> - Histórico de Notas da Turma <?php echo $turma['ano'] . 'ª ' . $turma['nome']; ?></div>
    </div>
    
    <script>
        function gerarPDF() {
            window.print();
        }
        
        function gerarExcel() {
            var turmaId = <?php echo $turma_id; ?>;
            window.location.href = `gerar_excel_historico.php?turma_id=${turmaId}`;
        }
    </script>
</body>
</html>