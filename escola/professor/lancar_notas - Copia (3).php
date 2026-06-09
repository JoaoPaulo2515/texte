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
    // Buscar dados da turma e disciplina para regras
    $sql_turma_info = "SELECT ano FROM turmas WHERE id = :turma_id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':turma_id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    $classe_ano = $turma_info['ano'] ?? 0;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9);
    
    $sql_disc_info = "SELECT nome FROM disciplinas WHERE id = :disciplina_id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':disciplina_id' => $disciplina_id]);
    $disc_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    $disciplina_nome = $disc_info['nome'] ?? '';
    $is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || stripos($disciplina_nome, 'inglês') !== false);
    
    // Buscar alunos
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
        
        $total_aprovados = 0;
        $total_recuperacao = 0;
        $total_reprovados = 0;
        $soma_notas = 0;
        
        foreach ($_POST['mac'] as $estudante_id => $mac) {
            $npt = $_POST['npt'][$estudante_id] ?? null;
            $exame_normal = $_POST['exame_normal'][$estudante_id] ?? null;
            $exame_recurso = $_POST['exame_recurso'][$estudante_id] ?? null;
            $exame_especial = $_POST['exame_especial'][$estudante_id] ?? null;
            $exame_oral = $_POST['exame_oral'][$estudante_id] ?? null;
            $exame_escrito = $_POST['exame_escrito'][$estudante_id] ?? null;
            
            $media_parcial = (floatval($mac) + floatval($npt)) / 2;
            $media_final = $media_parcial;
            $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            
            // Lógica para 3º bimestre
            if ($bimestre_post == 3) {
                if ($is_classe_exame) {
                    if ($exame_normal && $exame_normal > 0) {
                        $media_final = ($media_parcial * 0.4) + (floatval($exame_normal) * 0.6);
                        $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
                    }
                } elseif ($is_disciplina_lingua) {
                    if ($exame_oral && $exame_escrito && $exame_oral > 0 && $exame_escrito > 0) {
                        $media_exame = (floatval($exame_oral) + floatval($exame_escrito)) / 2;
                        $media_final = ($media_parcial + $media_exame) / 2;
                        $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
                    }
                } else {
                    if ($exame_normal && $exame_normal > 0) {
                        $media_final = ($media_parcial + floatval($exame_normal)) / 2;
                        $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
                    }
                }
            }
            
            // Atualizar estatísticas
            if ($status == 'aprovado') $total_aprovados++;
            elseif ($status == 'recuperacao') $total_recuperacao++;
            elseif ($status == 'reprovado') $total_reprovados++;
            $soma_notas += $media_final;
            
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
                            exame_oral, exame_escrito, media_final, status, ano_letivo_id, escola_id
                        ) VALUES (
                            :estudante_id, :disciplina_id, :professor_id, :bimestre,
                            :mac, :npt, :exame_normal, :exame_recurso, :exame_especial,
                            :exame_oral, :exame_escrito, :media_final, :status, :ano_letivo_id, :escola_id
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
                ':ano_letivo_id' => $ano_letivo_id,
                ':escola_id' => $escola_id
            ]);
        }
        
        $conn->commit();
        
        $media_geral = $total_alunos > 0 ? round($soma_notas / $total_alunos, 1) : 0;
        $success = "Notas salvas com sucesso!<br>
                    <strong>Estatísticas:</strong><br>
                    ✅ Aprovados: $total_aprovados<br>
                    ⚠️ Recuperação: $total_recuperacao<br>
                    ❌ Reprovados: $total_reprovados<br>
                    📊 Média Geral: $media_geral valores";
        
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
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        .btn-primary:hover {
            background: #004d2d;
        }
        .btn-help {
            background: #17a2b8;
            color: white;
            border: none;
        }
        .btn-help:hover {
            background: #138496;
            color: white;
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
        .badge-aprovado { background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .badge-reprovado { background: #dc3545; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .badge-recuperacao { background: #ffc107; color: #333; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
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
        .stats-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-3px);
        }
        .stats-number {
            font-size: 28px;
            font-weight: bold;
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
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .help-step {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .help-number {
            width: 40px;
            height: 40px;
            background: #006B3E;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            margin-right: 15px;
        }
        .help-content { flex: 1; }
        .help-content h6 { margin-bottom: 5px; color: #006B3E; }
        .help-content p { margin-bottom: 0; font-size: 13px; color: #666; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-pen-alt"></i> Lançar Notas</h2>
            <div>
                <button type="button" class="btn btn-help me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                    <i class="fas fa-question-circle"></i> Ajuda
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
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
                    <label class="form-label">Bimestre</label>
                    <select name="bimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ano Letivo</label>
                    <input type="text" class="form-control" value="<?php echo $ano_letivo_atual; ?>" disabled>
                </div>
            </form>
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
        
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        
        <!-- Estatísticas - ACRESCENTADO -->
        <?php
        $total_aprovados_stat = 0;
        $total_recuperacao_stat = 0;
        $total_reprovados_stat = 0;
        $soma_medias = 0;
        $count_com_nota = 0;
        
        foreach ($alunos as $aluno) {
            $nota = $notas_existentes[$aluno['id']] ?? null;
            $status = $nota['status'] ?? null;
            $media = $nota['media_final'] ?? 0;
            
            if ($status == 'aprovado') $total_aprovados_stat++;
            elseif ($status == 'recuperacao') $total_recuperacao_stat++;
            elseif ($status == 'reprovado') $total_reprovados_stat++;
            
            if ($media > 0) {
                $soma_medias += $media;
                $count_com_nota++;
            }
        }
        $media_geral_stat = $count_com_nota > 0 ? round($soma_medias / $count_com_nota, 1) : 0;
        ?>
        
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <div class="stats-number"><?php echo $total_alunos; ?></div>
                    <small>Total de Alunos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stats-number text-success"><?php echo $total_aprovados_stat; ?></div>
                    <small>Aprovados</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                    <div class="stats-number text-warning"><?php echo $total_recuperacao_stat; ?></div>
                    <small>Recuperação</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stats-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="stats-number text-danger"><?php echo $total_reprovados_stat; ?></div>
                    <small>Reprovados</small>
                </div>
            </div>
        </div>
        
        <!-- Info da Turma - ACRESCENTADO -->
        <div class="info-bar">
            <div>
                <i class="fas fa-info-circle text-primary"></i>
                <strong><?php echo $turma_info['ano'] ?? ''; ?>ª Classe - <?php echo $disciplina_nome; ?></strong>
                <?php if (($classe_ano == 6 || $classe_ano == 9) && $bimestre == 3): ?>
                    <span class="badge bg-danger ms-2">Classe de Exame (6ª/9ª)</span>
                <?php endif; ?>
                <?php if ($is_disciplina_lingua && $bimestre == 3): ?>
                    <span class="badge bg-info ms-2">Disciplina de Língua</span>
                <?php endif; ?>
            </div>
            <div>
                <span class="badge bg-primary"><?php echo $bimestre; ?>º Bimestre</span>
                <span class="badge bg-secondary ms-1">Média Geral: <?php echo $media_geral_stat; ?></span>
            </div>
        </div>
        
        <!-- Formulário de Notas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Lançamento de Notas</h5>
            </div>
            <div class="card-body">
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
                                    <th width="20%">Aluno</th>
                                    <th width="10%">Matrícula</th>
                                    <th width="8%">MAC<br><small>(0-10)</small></th>
                                    <th width="8%">NPT<br><small>(0-10)</small></th>
                                    <?php if ($bimestre == 3): ?>
                                        <?php if ($classe_ano == 6 || $classe_ano == 9): ?>
                                            <th width="10%">Exame Normal<br><small>(0-20)</small></th>
                                        <?php elseif ($is_disciplina_lingua): ?>
                                            <th width="8%">Exame Oral<br><small>(0-20)</small></th>
                                            <th width="8%">Exame Escrito<br><small>(0-20)</small></th>
                                        <?php else: ?>
                                            <th width="10%">Exame Normal<br><small>(0-20)</small></th>
                                        <?php endif; ?>
                                    <?php endif; ?>
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
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
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
                                    <?php if ($bimestre == 3): ?>
                                        <?php if ($classe_ano == 6 || $classe_ano == 9): ?>
                                        <td>
                                            <input type="number" step="0.5" min="0" max="20" 
                                                   name="exame_normal[<?php echo $aluno['id']; ?>]" 
                                                   class="form-control form-control-sm media-input exame-input" 
                                                   value="<?php echo $nota['exame_normal'] ?? ''; ?>"
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                        </td>
                                        <?php elseif ($is_disciplina_lingua): ?>
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
                                        <?php else: ?>
                                        <td>
                                            <input type="number" step="0.5" min="0" max="20" 
                                                   name="exame_normal[<?php echo $aluno['id']; ?>]" 
                                                   class="form-control form-control-sm media-input exame-input" 
                                                   value="<?php echo $nota['exame_normal'] ?? ''; ?>"
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   onchange="calcularMedia(<?php echo $aluno['id']; ?>)">
                                        </td>
                                        <?php endif; ?>
                                    <?php endif; ?>
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
                <i class="fas fa-filter"></i> Selecione uma turma e disciplina para começar.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda - ACRESCENTADO -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Como funciona o Lançamento de Notas?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6>Selecione os Filtros</h6>
                            <p>Escolha a Turma, Disciplina e o Bimestre desejado. Os campos disponíveis se adaptam automaticamente conforme as regras de avaliação.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6>Preencha as Notas</h6>
                            <p>Digite as notas nos campos disponíveis. O sistema calcula automaticamente a média final e a situação do aluno.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6>Regras por Bimestre</h6>
                            <p><strong>1º e 2º Bimestre:</strong> Apenas MAC e NPT. Média = (MAC + NPT) / 2</p>
                            <p><strong>3º Bimestre:</strong> Regras especiais:
                                <br>- <strong>Classe de Exame (6ª e 9ª):</strong> Média = (Média dos bimestres × 40%) + (Exame × 60%)
                                <br>- <strong>Disciplina de Língua:</strong> Média = (MAC+NPT)/2 + (Oral+Escrito)/2
                                <br>- <strong>Classe Normal:</strong> Média = (MAC+NPT)/2 + Exame (quando aplicável)
                            </p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">4</div>
                        <div class="help-content">
                            <h6>Salve as Notas</h6>
                            <p>Clique em "Salvar Todas as Notas" para guardar as informações. O sistema exibe automaticamente as estatísticas de aprovação.</p>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Dica:</strong> As notas são calculadas automaticamente enquanto você digita. Use o botão de calculadora para recalcular manualmente.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function calcularMedia(alunoId) {
            var mac = parseFloat(document.querySelector(`input[name="mac[${alunoId}]"]`).value) || 0;
            var npt = parseFloat(document.querySelector(`input[name="npt[${alunoId}]"]`).value) || 0;
            var exameNormal = parseFloat(document.querySelector(`input[name="exame_normal[${alunoId}]"]`).value) || 0;
            var exameOral = parseFloat(document.querySelector(`input[name="exame_oral[${alunoId}]"]`).value) || 0;
            var exameEscrito = parseFloat(document.querySelector(`input[name="exame_escrito[${alunoId}]"]`).value) || 0;
            
            var media = (mac + npt) / 2;
            var situacao = '';
            var badgeClass = '';
            
            // Verificar se é 3º bimestre e classe de exame (6ª/9ª)
            var isClasseExame = <?php echo ($classe_ano == 6 || $classe_ano == 9) && $bimestre == 3 ? 'true' : 'false'; ?>;
            var isDisciplinaLingua = <?php echo $is_disciplina_lingua && $bimestre == 3 ? 'true' : 'false'; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            
            if (bimestre == 3 && isClasseExame) {
                if (exameNormal > 0) {
                    media = (media * 0.4) + (exameNormal * 0.6);
                }
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            } 
            else if (bimestre == 3 && isDisciplinaLingua) {
                if (exameOral > 0 && exameEscrito > 0) {
                    var mediaExame = (exameOral + exameEscrito) / 2;
                    media = (media + mediaExame) / 2;
                }
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            }
            else if (bimestre == 3 && exameNormal > 0) {
                media = (media + exameNormal) / 2;
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
        
        // Auto-calcular ao digitar
        $(document).on('input', '.nota-input, .exame-input', function() {
            var alunoId = $(this).data('aluno');
            if (alunoId) calcularMedia(alunoId);
        });
    </script>
</body>
</html>