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
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$alunos = [];
if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar alunos da turma (usando estudantes)
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
    
    // Buscar notas existentes para esta turma/disciplina/bimestre
    $sql_notas_existentes = "
        SELECT 
            estudante_id,
            mac,
            npt,
            exame_normal,
            exame_recurso,
            exame_especial,
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
    $notas_existentes = [];
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
            
            // Calcular média
            $media_parcial = (floatval($mac) + floatval($npt)) / 2;
            
            if ($exame_normal && $exame_normal > 0) {
                $media_final = ($media_parcial + floatval($exame_normal)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } elseif ($exame_recurso && $exame_recurso > 0) {
                $media_final = ($media_parcial + floatval($exame_recurso)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } elseif ($exame_especial && $exame_especial > 0) {
                $media_final = ($media_parcial + floatval($exame_especial)) / 2;
                $status = $media_final >= 10 ? 'aprovado' : ($media_final >= 7 ? 'recuperacao' : 'reprovado');
            } else {
                $media_final = $media_parcial;
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
                // Update
                $sql = "UPDATE notas SET 
                            mac = :mac, 
                            npt = :npt, 
                            exame_normal = :exame_normal, 
                            exame_recurso = :exame_recurso,
                            exame_especial = :exame_especial,
                            media_final = :media_final, 
                            status = :status, 
                            updated_at = NOW() 
                        WHERE estudante_id = :estudante_id 
                        AND disciplina_id = :disciplina_id 
                        AND bimestre = :bimestre 
                        AND ano_letivo_id = :ano_letivo_id";
            } else {
                // Insert
                $sql = "INSERT INTO notas (
                            estudante_id, disciplina_id, professor_id, bimestre, 
                            mac, npt, exame_normal, exame_recurso, exame_especial,
                            media_final, status, ano_letivo_id, created_at
                        ) VALUES (
                            :estudante_id, :disciplina_id, :professor_id, :bimestre,
                            :mac, :npt, :exame_normal, :exame_recurso, :exame_especial,
                            :media_final, :status, :ano_letivo_id, NOW()
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
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .notas-table th {
            background: #006B3E;
            color: white;
            text-align: center;
        }
        .notas-table td {
            vertical-align: middle;
            text-align: center;
        }
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .btn-lancar {
            background: #006B3E;
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 25px;
        }
        .btn-lancar:hover {
            background: #004d2d;
            color: white;
        }
        .media-input {
            width: 80px;
            text-align: center;
        }
        .foto-mini {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
        }
        .badge-aprovado {
            background: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .badge-reprovado {
            background: #dc3545;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .badge-recuperacao {
            background: #ffc107;
            color: #333;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        .info-turma {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-pen-alt"></i> Lançar Notas</h2>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
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
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
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
        
        <!-- Info da Turma -->
        <div class="info-turma">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h5 class="mb-0">
                        <i class="fas fa-users text-primary"></i> 
                        Turma: <?php echo $turmas[array_search($turma_id, array_column($turmas, 'id'))]['ano'] . 'ª ' . $turmas[array_search($turma_id, array_column($turmas, 'id'))]['nome']; ?>
                    </h5>
                    <small class="text-muted"><?php echo count($alunos); ?> alunos matriculados</small>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="badge bg-primary p-2">
                        <i class="fas fa-book"></i> <?php echo $disciplinas[array_search($disciplina_id, array_column($disciplinas, 'id'))]['nome'] ?? 'Disciplina'; ?>
                    </span>
                    <span class="badge bg-info p-2 ms-2">
                        <i class="fas fa-calendar"></i> <?php echo $bimestre; ?>º Bimestre
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Formulário de Notas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Lançamento de Notas</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                    <input type="hidden" name="bimestre" value="<?php echo $bimestre; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered notas-table">
                            <thead>
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="10%">Foto</th>
                                    <th width="20%">Aluno</th>
                                    <th width="10%">Matrícula</th>
                                    <th width="10%">MAC (0-10)</th>
                                    <th width="10%">NPT (0-10)</th>
                                    <th width="10%">Exame Normal (0-20)</th>
                                    <th width="10%">Exame Recurso (0-20)</th>
                                    <th width="8%">Média</th>
                                    <th width="7%">Status</th>
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
                                               name="exame_recurso[<?php echo $aluno['id']; ?>]" 
                                               class="form-control form-control-sm media-input exame-input" 
                                               value="<?php echo $nota['exame_recurso'] ?? ''; ?>"
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
                                </table>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-end mt-3">
                        <button type="submit" name="salvar_notas" class="btn btn-lancar">
                            <i class="fas fa-save"></i> Salvar Notas
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
    
    <script>
        function calcularMedia(alunoId) {
            var mac = parseFloat(document.querySelector(`input[name="mac[${alunoId}]"]`).value) || 0;
            var npt = parseFloat(document.querySelector(`input[name="npt[${alunoId}]"]`).value) || 0;
            var exameNormal = parseFloat(document.querySelector(`input[name="exame_normal[${alunoId}]"]`).value) || 0;
            var exameRecurso = parseFloat(document.querySelector(`input[name="exame_recurso[${alunoId}]"]`).value) || 0;
            
            var media = (mac + npt) / 2;
            var situacao = '';
            var badgeClass = '';
            
            if (exameNormal > 0) {
                media = (media + exameNormal) / 2;
                situacao = media >= 10 ? 'Aprovado' : (media >= 7 ? 'Recuperação' : 'Reprovado');
                badgeClass = media >= 10 ? 'badge-aprovado' : (media >= 7 ? 'badge-recuperacao' : 'badge-reprovado');
            } else if (exameRecurso > 0) {
                media = (media + exameRecurso) / 2;
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
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>