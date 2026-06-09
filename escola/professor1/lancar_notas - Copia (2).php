<?php
// escola/professor/lancar_notas.php - Lançar Notas

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
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;
$estudante_id_filtro = isset($_GET['estudante_id']) ? (int)$_GET['estudante_id'] : 0;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_atual = $ano_letivo['ano'] ?? date('Y') . '/' . (date('Y') + 1);

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
$total_alunos = 0;
$notas_existentes = [];

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.foto,
            e.status as aluno_status
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    $total_alunos = count($alunos);
    
    // Buscar notas existentes
    $sql_notas_existentes = "
        SELECT 
            estudante_id,
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
        WHERE disciplina_id = :disciplina_id 
        AND bimestre = :bimestre 
        AND ano_letivo_id = :ano_letivo_id
    ";
    $stmt_notas = $conn->prepare($sql_notas_existentes);
    $stmt_notas->execute([
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
        $notas_existentes[$row['estudante_id']] = $row;
    }
}

// ============================================
// PROCESSAR FORMULÁRIO
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_notas'])) {
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $bimestre_post = (int)$_POST['bimestre'];
    
    try {
        $conn->beginTransaction();
        
        foreach ($_POST['mac'] as $estudante_id => $mac) {
            $npt = $_POST['npt'][$estudante_id] ?? null;
            $exame_normal = $_POST['exame_normal'][$estudante_id] ?? null;
            $exame_recurso = $_POST['exame_recurso'][$estudante_id] ?? null;
            $exame_especial = $_POST['exame_especial'][$estudante_id] ?? null;
            $exame_oral = $_POST['exame_oral'][$estudante_id] ?? null;
            $exame_escrito = $_POST['exame_escrito'][$estudante_id] ?? null;
            
            // Calcular média
            $media_parcial = (floatval($mac) + floatval($npt)) / 2;
            $media_final = $media_parcial;
            $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            
            // Verificar se tem exame
            if ($exame_normal && $exame_normal > 0) {
                $media_final = ($media_parcial + floatval($exame_normal)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } elseif ($exame_recurso && $exame_recurso > 0) {
                $media_final = ($media_parcial + floatval($exame_recurso)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } elseif ($exame_especial && $exame_especial > 0) {
                $media_final = ($media_parcial + floatval($exame_especial)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } elseif ($exame_oral && $exame_oral > 0) {
                $media_final = ($media_parcial + floatval($exame_oral)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } elseif ($exame_escrito && $exame_escrito > 0) {
                $media_final = ($media_parcial + floatval($exame_escrito)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            }
            
            // Verificar se já existe registro
            $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':estudante_id' => $estudante_id,
                ':disciplina_id' => $disciplina_id_post,
                ':bimestre' => $bimestre_post,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            
            if ($stmt_check->fetch()) {
                $sql = "UPDATE notas SET 
                            mac = :mac, npt = :npt, 
                            exame_normal = :exame_normal, exame_recurso = :exame_recurso,
                            exame_especial = :exame_especial, exame_oral = :exame_oral, exame_escrito = :exame_escrito,
                            media_final = :media_final, status = :status, updated_at = NOW() 
                        WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id 
                        AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
            } else {
                $sql = "INSERT INTO notas (
                            estudante_id, disciplina_id, professor_id, bimestre, 
                            mac, npt, exame_normal, exame_recurso, exame_especial,
                            exame_oral, exame_escrito, media_final, status, ano_letivo_id
                        ) VALUES (
                            :estudante_id, :disciplina_id, :professor_id, :bimestre,
                            :mac, :npt, :exame_normal, :exame_recurso, :exame_especial,
                            :exame_oral, :exame_escrito, :media_final, :status, :ano_letivo_id
                        )";
            }
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':estudante_id' => $estudante_id,
                ':disciplina_id' => $disciplina_id_post,
                ':professor_id' => $professor_id,
                ':bimestre' => $bimestre_post,
                ':mac' => $mac ?: null,
                ':npt' => $npt ?: null,
                ':exame_normal' => $exame_normal ?: null,
                ':exame_recurso' => $exame_recurso ?: null,
                ':exame_especial' => $exame_especial ?: null,
                ':exame_oral' => $exame_oral ?: null,
                ':exame_escrito' => $exame_escrito ?: null,
                ':media_final' => $media_final,
                ':status' => $status,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
        }
        
        $conn->commit();
        $success = "Notas salvas com sucesso!";
        
        // Recarregar notas
        $stmt_notas = $conn->prepare($sql_notas_existentes);
        $stmt_notas->execute([
            ':disciplina_id' => $disciplina_id_post,
            ':bimestre' => $bimestre_post,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $notas_existentes = [];
        while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
            $notas_existentes[$row['estudante_id']] = $row;
        }
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $error = "Erro ao salvar notas: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançar Notas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 25px;
        }
        .page-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }
        .page-header p {
            margin: 5px 0 0;
            opacity: 0.8;
            font-size: 0.85rem;
        }
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .filter-label {
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 5px;
        }
        .filter-value {
            font-weight: 700;
            font-size: 1rem;
            color: #006B3E;
        }
        .btn-action {
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.75rem;
            margin: 2px;
        }
        .btn-group-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .notas-table th {
            background: #f8f9fa;
            text-align: center;
            font-size: 0.75rem;
            padding: 10px 5px;
        }
        .notas-table td {
            vertical-align: middle;
            text-align: center;
            font-size: 0.8rem;
        }
        .media-input {
            width: 70px;
            text-align: center;
            font-size: 0.75rem;
            padding: 4px;
        }
        .foto-mini {
            width: 35px;
            height: 35px;
            object-fit: cover;
            border-radius: 50%;
        }
        .badge-aprovado {
            background: #28a745;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .badge-reprovado {
            background: #dc3545;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .badge-recuperacao {
            background: #ffc107;
            color: #333;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .btn-salvar {
            background: #006B3E;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 30px;
            font-weight: 600;
        }
        .btn-salvar:hover {
            background: #004d2d;
            color: white;
        }
        .info-bar {
            background: #e8f5e9;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .info-bar span {
            font-weight: 600;
            color: #006B3E;
        }
        select.form-select-sm {
            font-size: 0.8rem;
        }
        .table-responsive {
            max-height: 500px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <h2><i class="fas fa-pen-alt"></i> Lançamento de Notas</h2>
            <p>Gerencie as notas dos alunos de forma rápida e intuitiva. Selecione a turma e disciplina para visualizar os alunos.</p>
        </div>
        
        <!-- Como funciona -->
        <div class="filter-card">
            <div class="row">
                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="filter-label">ANO LETIVO</span>
                            <div class="filter-value"><?php echo $ano_letivo_atual; ?></div>
                        </div>
                        <div class="btn-group-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-action" id="btnBuscarAlunos">
                                <i class="fas fa-search"></i> Buscar Alunos
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-action" id="btnCalcularTodas">
                                <i class="fas fa-calculator"></i> Calcular Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-success btn-action" id="btnSalvarTodas">
                                <i class="fas fa-save"></i> Salvar Todas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="filter-label">TURMA</label>
                    <select name="turma_id" id="turma_id" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="">Selecionar...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-2 btn-group-actions">
                        <button type="button" class="btn btn-sm btn-outline-info btn-action" onclick="window.open('historico_completo.php?turma_id='+$('#turma_id').val(), '_blank')">
                            <i class="fas fa-history"></i> Histórico Completo
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-action" onclick="gerarPDF()">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action" onclick="gerarExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                    </div>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="filter-label">DISCIPLINA</label>
                    <select name="disciplina_id" id="disciplina_id" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="">Selecionar...</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="filter-label">BIMESTRE</label>
                    <select name="bimestre" id="bimestre" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
                    </select>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="filter-label">AÇÕES RÁPIDAS</label>
                    <div class="btn-group-actions">
                        <button type="button" class="btn btn-sm btn-secondary btn-action" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Recarregar
                        </button>
                        <button type="button" class="btn btn-sm btn-warning btn-action" onclick="resetarFormulario()">
                            <i class="fas fa-undo-alt"></i> Resetar
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Tabela de Notas -->
        <div class="filter-card">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="fas fa-table"></i> Notas dos Alunos</h5>
                <span class="badge bg-primary"><?php echo $total_alunos; ?> alunos carregados</span>
            </div>
            
            <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
            <form method="POST" id="formNotas">
                <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                <input type="hidden" name="bimestre" value="<?php echo $bimestre; ?>">
                
                <div class="table-responsive">
                    <table class="table table-bordered notas-table">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="8%">Foto</th>
                                <th width="15%">Aluno</th>
                                <th width="8%">MAC<br><small>(0-10)</small></th>
                                <th width="8%">NPT<br><small>(0-10)</small></th>
                                <th width="8%">Ex.Normal<br><small>(0-20)</small></th>
                                <th width="8%">Ex.Especial<br><small>(0-20)</small></th>
                                <th width="8%">Ex.Oral<br><small>(0-20)</small></th>
                                <th width="8%">Ex.Escrito<br><small>(0-20)</small></th>
                                <th width="8%">Média</th>
                                <th width="10%">Status</th>
                                <th width="8%">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno):
                                $nota = $notas_existentes[$aluno['id']] ?? null;
                            ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td>
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-mini">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="foto-mini">
                                    <?php endif; ?>
                                </td>
                                <td class="text-start">
                                    <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo $aluno['matricula']; ?></small>
                                 </td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="10" 
                                           name="mac[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input nota-input" 
                                           value="<?php echo $nota['mac'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                 </td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="10" 
                                           name="npt[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input nota-input" 
                                           value="<?php echo $nota['npt'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                 </td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_normal[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_normal'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                 </td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_especial[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_especial'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                 </td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_oral[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_oral'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                 </td>
                                <td>
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_escrito[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_escrito'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                 </td>
                                <td>
                                    <span id="media_<?php echo $aluno['id']; ?>" class="fw-bold">
                                        <?php echo number_format($nota['media_final'] ?? 0, 1); ?>
                                    </span>
                                 </td>
                                <td>
                                    <span id="status_<?php echo $aluno['id']; ?>" class="badge <?php 
                                        echo $nota['status'] == 'aprovado' ? 'badge-aprovado' : 
                                            ($nota['status'] == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado'); 
                                    ?>">
                                        <?php echo ucfirst($nota['status'] ?? 'Pendente'); ?>
                                    </span>
                                 </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="calcularMedia(<?php echo $aluno['id']; ?>)">
                                        <i class="fas fa-calculator"></i>
                                    </button>
                                 </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="text-end mt-4">
                    <button type="submit" name="salvar_notas" class="btn btn-salvar">
                        <i class="fas fa-save"></i> Salvar Todas as Notas
                    </button>
                </div>
            </form>
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
                    <i class="fas fa-filter"></i> Carregue os filtros para ver as notas.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function aplicarFiltros() {
            var turmaId = $('#turma_id').val();
            var disciplinaId = $('#disciplina_id').val();
            var bimestre = $('#bimestre').val();
            
            if (turmaId && disciplinaId) {
                window.location.href = `lancar_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else if (turmaId) {
                window.location.href = `lancar_notas.php?turma_id=${turmaId}&bimestre=${bimestre}`;
            } else {
                window.location.href = `lancar_notas.php?bimestre=${bimestre}`;
            }
        }
        
        function calcularMedia(alunoId) {
            var mac = parseFloat(document.querySelector(`input[name="mac[${alunoId}]"]`).value) || 0;
            var npt = parseFloat(document.querySelector(`input[name="npt[${alunoId}]"]`).value) || 0;
            var exameNormal = parseFloat(document.querySelector(`input[name="exame_normal[${alunoId}]"]`).value) || 0;
            var exameEspecial = parseFloat(document.querySelector(`input[name="exame_especial[${alunoId}]"]`).value) || 0;
            var exameOral = parseFloat(document.querySelector(`input[name="exame_oral[${alunoId}]"]`).value) || 0;
            var exameEscrito = parseFloat(document.querySelector(`input[name="exame_escrito[${alunoId}]"]`).value) || 0;
            
            var media = (mac + npt) / 2;
            var situacao = '';
            var badgeClass = '';
            
            if (exameNormal > 0) {
                media = (media + exameNormal) / 2;
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            } else if (exameEspecial > 0) {
                media = (media + exameEspecial) / 2;
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            } else if (exameOral > 0) {
                media = (media + exameOral) / 2;
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            } else if (exameEscrito > 0) {
                media = (media + exameEscrito) / 2;
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            } else {
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            }
            
            document.getElementById(`media_${alunoId}`).innerHTML = media.toFixed(1);
            var statusSpan = document.getElementById(`status_${alunoId}`);
            statusSpan.innerHTML = situacao;
            statusSpan.className = `badge ${badgeClass}`;
        }
        
        function calcularTodas() {
            document.querySelectorAll('.nota-input').forEach(input => {
                var alunoId = input.getAttribute('data-aluno');
                if (alunoId) calcularMedia(alunoId);
            });
        }
        
        function resetarFormulario() {
            document.querySelectorAll('.media-input').forEach(input => {
                input.value = '';
            });
            calcularTodas();
        }
        
        function gerarPDF() {
            var turmaId = $('#turma_id').val();
            var disciplinaId = $('#disciplina_id').val();
            var bimestre = $('#bimestre').val();
            if (turmaId && disciplinaId) {
                window.open(`gerar_pdf_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = $('#turma_id').val();
            var disciplinaId = $('#disciplina_id').val();
            var bimestre = $('#bimestre').val();
            if (turmaId && disciplinaId) {
                window.location.href = `gerar_excel_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        $('#btnBuscarAlunos').click(function() {
            aplicarFiltros();
        });
        
        $('#btnCalcularTodas').click(function() {
            calcularTodas();
        });
        
        $('#btnSalvarTodas').click(function() {
            $('#formNotas').submit();
        });
    </script>
</body>
</html>