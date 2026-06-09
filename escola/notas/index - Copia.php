<?php
// escola/notas/index.php - Lançamento de Notas (Professor e Admin)

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'];
$ano_letivo_atual = date('Y');

// ============================================
// DETECTAR TIPO DE USUÁRIO
// ============================================
$is_professor = ($usuario_tipo == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor');

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo_ativo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo_ativo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo_ativo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR (se for professor)
// ============================================
if ($is_professor) {
    // Buscar funcionário_id baseado no usuario_id
    $sql_funcionario = "SELECT id FROM funcionarios WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
    $stmt_funcionario = $conn->prepare($sql_funcionario);
    $stmt_funcionario->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
    $funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
    
    if ($funcionario) {
        $funcionario_id = $funcionario['id'];
        
        // Buscar turmas do professor
        $sql_turmas = "
            SELECT DISTINCT t.id, t.nome, t.ano, t.turno
            FROM turmas t
            INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
            WHERE pdt.professor_id = :funcionario_id AND t.escola_id = :escola_id
            ORDER BY t.ano, t.nome
        ";
        $stmt_turmas = $conn->prepare($sql_turmas);
        $stmt_turmas->execute([':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
        $turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar disciplinas do professor
        $sql_disciplinas = "
            SELECT DISTINCT d.id, d.nome, d.codigo
            FROM disciplinas d
            INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
            WHERE pdt.professor_id = :funcionario_id AND d.escola_id = :escola_id
            ORDER BY d.nome
        ";
        $stmt_disciplinas = $conn->prepare($sql_disciplinas);
        $stmt_disciplinas->execute([':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
        $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $turmas = [];
        $disciplinas = [];
    }
} else {
    // Buscar todas as turmas da escola (para admin)
    $sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':escola_id' => $escola_id]);
    $turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar todas as disciplinas da escola
    $sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':escola_id' => $escola_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$ano_letivo = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : $ano_letivo_id;

$message = '';
$error = '';

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusNota($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'bg-secondary'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'bg-success'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'bg-warning text-dark'];
    return ['texto' => 'Reprovado', 'classe' => 'bg-danger'];
}

function isClasseExame($ano_turma) {
    $classes_exame = [6, 9, 12];
    return in_array($ano_turma, $classes_exame);
}

function isLinguagem($disciplina_nome) {
    $linguagens = ['Português', 'Inglês', 'Língua Portuguesa', 'Língua Inglesa', 'Portuguese', 'English'];
    $disciplina_lower = strtolower($disciplina_nome);
    foreach ($linguagens as $ling) {
        if (strpos($disciplina_lower, strtolower($ling)) !== false) {
            return true;
        }
    }
    return false;
}

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$alunos = [];
$notas = [];
$turma_info = null;
$disciplina_info = null;
$is_exame_classe = false;
$is_linguagem = false;

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    $is_exame_classe = $turma_info ? isClasseExame($turma_info['ano']) : false;
    
    // Buscar informações da disciplina
    $sql_disc_info = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    $is_linguagem = $disciplina_info ? isLinguagem($disciplina_info['nome']) : false;
    
    // Buscar alunos da turma (CORRIGIDO: usando estudante_id diretamente)
    $sql_alunos = "
        SELECT e.id, e.nome, e.matricula, e.genero
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        WHERE m.turma_id = :turma_id 
        AND m.status = 'ativa' 
        AND m.ano_letivo = :ano_letivo_id
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo
    ]);
    $alunos_base = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar notas existentes (CORRIGIDO: usando estudante_id)
    $sql_notas = "
        SELECT n.*, n.estudante_id
        FROM notas n
        WHERE n.disciplina_id = :disciplina_id 
        AND n.bimestre = :trimestre
        AND n.ano_letivo_id = :ano_letivo_id
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':disciplina_id' => $disciplina_id,
        ':trimestre' => $trimestre,
        ':ano_letivo_id' => $ano_letivo
    ]);
    $notas_raw = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($notas_raw as $nr) {
        $notas[$nr['estudante_id']] = $nr;
    }
    
    // Preparar array de alunos com dados organizados
    foreach ($alunos_base as $aluno) {
        $nota = $notas[$aluno['id']] ?? null;
        
        $mac = $nota ? (float)$nota['mac'] : null;
        $npt = $nota ? (float)$nota['npt'] : null;
        $exame_normal = $nota ? (float)$nota['exame_normal'] : null;
        $exame_oral = $nota ? (float)$nota['exame_oral'] : null;
        $exame_escrita = $nota ? (float)$nota['exame_escrito'] : null;
        $media_final = $nota ? (float)$nota['media_final'] : null;
        $nota_id = $nota ? $nota['id'] : null;
        
        // Calcular média se necessário
        if ($media_final === null) {
            if ($is_exame_classe && $trimestre == 3) {
                if ($is_linguagem) {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($exame_oral !== null) $valores[] = $exame_oral;
                    if ($exame_escrita !== null) $valores[] = $exame_escrita;
                    $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                } else {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($exame_normal !== null) $valores[] = $exame_normal;
                    $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                }
            } else {
                $valores = [];
                if ($mac !== null) $valores[] = $mac;
                if ($npt !== null) $valores[] = $npt;
                $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
            }
        }
        
        $status = getStatusNota($media_final);
        
        $alunos[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'],
            'mac' => $mac,
            'npt' => $npt,
            'exame_normal' => $exame_normal,
            'exame_oral' => $exame_oral,
            'exame_escrita' => $exame_escrita,
            'media_final' => $media_final,
            'nota_id' => $nota_id,
            'status_texto' => $status['texto'],
            'status_classe' => $status['classe']
        ];
    }
}

// ============================================
// SALVAR NOTAS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_notas'])) {
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $trimestre_post = (int)$_POST['trimestre'];
    $ano_letivo_post = (int)$_POST['ano_letivo'];
    
    try {
        $conn->beginTransaction();
        
        foreach ($_POST['notas'] as $estudante_id => $nota_data) {
            $estudante_id = (int)$estudante_id;
            $mac = !empty($nota_data['mac']) ? (float)str_replace(',', '.', $nota_data['mac']) : null;
            $npt = !empty($nota_data['npt']) ? (float)str_replace(',', '.', $nota_data['npt']) : null;
            $exame_normal = !empty($nota_data['exame_normal']) ? (float)str_replace(',', '.', $nota_data['exame_normal']) : null;
            $exame_oral = !empty($nota_data['exame_oral']) ? (float)str_replace(',', '.', $nota_data['exame_oral']) : null;
            $exame_escrita = !empty($nota_data['exame_escrita']) ? (float)str_replace(',', '.', $nota_data['exame_escrita']) : null;
            
            // Verificar se o estudante existe na turma
            $sql_check = "SELECT COUNT(*) as total FROM matriculas 
                         WHERE estudante_id = :estudante_id 
                         AND turma_id = :turma_id 
                         AND status = 'ativa' 
                         AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':estudante_id' => $estudante_id,
                ':turma_id' => $turma_id_post,
                ':ano_letivo_id' => $ano_letivo_post
            ]);
            $existe_matricula = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($existe_matricula == 0) {
                continue; // Pular se o estudante não pertence à turma
            }
            
            // Buscar informações da turma e disciplina para calcular média corretamente
            $sql_turma_ano = "SELECT ano FROM turmas WHERE id = :id";
            $stmt_turma_ano = $conn->prepare($sql_turma_ano);
            $stmt_turma_ano->execute([':id' => $turma_id_post]);
            $turma_ano_info = $stmt_turma_ano->fetch(PDO::FETCH_ASSOC);
            $is_exame_classe_calc = $turma_ano_info ? isClasseExame($turma_ano_info['ano']) : false;
            
            $sql_disc_nome = "SELECT nome FROM disciplinas WHERE id = :id";
            $stmt_disc_nome = $conn->prepare($sql_disc_nome);
            $stmt_disc_nome->execute([':id' => $disciplina_id_post]);
            $disc_nome_info = $stmt_disc_nome->fetch(PDO::FETCH_ASSOC);
            $is_linguagem_calc = $disc_nome_info ? isLinguagem($disc_nome_info['nome']) : false;
            
            // Calcular média final com as regras
            $media_final = null;
            if ($is_exame_classe_calc && $trimestre_post == 3) {
                if ($is_linguagem_calc) {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($exame_oral !== null) $valores[] = $exame_oral;
                    if ($exame_escrita !== null) $valores[] = $exame_escrita;
                    $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                } else {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($exame_normal !== null) $valores[] = $exame_normal;
                    $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                }
            } else {
                $valores = [];
                if ($mac !== null) $valores[] = $mac;
                if ($npt !== null) $valores[] = $npt;
                $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
            }
            
            // Verificar se já existe nota
            $sql_check_nota = "SELECT id FROM notas 
                              WHERE estudante_id = :estudante_id 
                              AND disciplina_id = :disciplina_id 
                              AND trimestre = :trimestre
                              AND ano_letivo_id = :ano_letivo_id";
            $stmt_check_nota = $conn->prepare($sql_check_nota);
            $stmt_check_nota->execute([
                ':estudante_id' => $estudante_id,
                ':disciplina_id' => $disciplina_id_post,
                ':trimestre' => $trimestre_post,
                ':ano_letivo_id' => $ano_letivo_post
            ]);
            $existente = $stmt_check_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($existente) {
                // Atualizar
                $sql = "UPDATE notas SET 
                            mac = :mac, 
                            npt = :npt, 
                            exame_normal = :exame_normal,
                            exame_oral = :exame_oral,
                            exame_escrita = :exame_escrita,
                            media_final = :media_final,
                            data_lancamento = NOW()
                        WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':mac' => $mac,
                    ':npt' => $npt,
                    ':exame_normal' => $exame_normal,
                    ':exame_oral' => $exame_oral,
                    ':exame_escrita' => $exame_escrita,
                    ':media_final' => $media_final,
                    ':id' => $existente['id']
                ]);
            } else {
                // Inserir
                $sql = "INSERT INTO notas (
                            estudante_id, disciplina_id, trimestre, ano_letivo_id,
                            mac, npt, exame_normal, exame_oral, exame_escrita,
                            media_final, data_lancamento
                        ) VALUES (
                            :estudante_id, :disciplina_id, :trimestre, :ano_letivo_id,
                            :mac, :npt, :exame_normal, :exame_oral, :exame_escrita,
                            :media_final, NOW()
                        )";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':estudante_id' => $estudante_id,
                    ':disciplina_id' => $disciplina_id_post,
                    ':trimestre' => $trimestre_post,
                    ':ano_letivo_id' => $ano_letivo_post,
                    ':mac' => $mac,
                    ':npt' => $npt,
                    ':exame_normal' => $exame_normal,
                    ':exame_oral' => $exame_oral,
                    ':exame_escrita' => $exame_escrita,
                    ':media_final' => $media_final
                ]);
            }
        }
        
        $conn->commit();
        $message = "Notas salvas com sucesso!";
        
        // Redirecionar para evitar reenvio do formulário
        header("Location: index.php?turma_id=$turma_id_post&disciplina_id=$disciplina_id_post&trimestre=$trimestre_post&ano_letivo=$ano_letivo_post&msg=success");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Erro ao salvar notas: " . $e->getMessage();
    }
}

// Verificar se veio mensagem de sucesso por GET
if (isset($_GET['msg']) && $_GET['msg'] == 'success') {
    $message = "Notas salvas com sucesso!";
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançamento de Notas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .nota-input { width: 90px; text-align: center; }
        .tipo-usuario { font-size: 12px; padding: 4px 12px; border-radius: 20px; }
        .tipo-professor { background: #17a2b8; color: white; }
        .tipo-admin { background: #28a745; color: white; }
        .table-notas th { background: #e9ecef; text-align: center; }
        .table-notas td { vertical-align: middle; text-align: center; }
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-edit"></i> Lançamento de Notas</h2>
                <?php if ($is_professor): ?>
                    <span class="tipo-usuario tipo-professor"><i class="fas fa-chalkboard-user"></i> Modo Professor</span>
                <?php else: ?>
                    <span class="tipo-usuario tipo-admin"><i class="fas fa-user-shield"></i> Modo Administrador</span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-filter"></i> Filtros</h3>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3" id="formFiltros">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Ano Letivo</label>
                        <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                            <?php for ($i = $ano_letivo_ano - 2; $i <= $ano_letivo_ano + 1; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ano_letivo == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Turma</label>
                        <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                            <option value="0">Selecione...</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']) . ' (' . ucfirst($t['turno']) . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Disciplina</label>
                        <select name="disciplina_id" class="form-select" id="disciplina_id" onchange="this.form.submit()">
                            <option value="0">Selecione...</option>
                            <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $disciplina_id == $d['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Trimestre</label>
                        <select name="trimestre" class="form-select" onchange="this.form.submit()">
                            <option value="1" <?php echo $trimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                            <option value="2" <?php echo $trimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                            <option value="3" <?php echo $trimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Tabela de Notas -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">
                    <i class="fas fa-chalkboard"></i> 
                    <?php echo htmlspecialchars($turma_info['nome'] ?? 'Turma'); ?> - 
                    <?php echo htmlspecialchars($disciplina_info['nome'] ?? 'Disciplina'); ?> - 
                    <?php echo $trimestre; ?>º Trimestre
                </h3>
                <?php if ($is_exame_classe && $trimestre == 3): ?>
                    <small class="text-white-50">
                        <i class="fas fa-info-circle"></i> Classe de Exame - 
                        <?php echo $is_linguagem ? 'Avaliação: MAC + Exame Oral + Exame Escrita' : 'Avaliação: MAC + Exame Normal'; ?>
                    </small>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="formNotas">
                    <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                    <input type="hidden" name="trimestre" value="<?php echo $trimestre; ?>">
                    <input type="hidden" name="ano_letivo" value="<?php echo $ano_letivo; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-notas">
                            <thead>
                                <tr>
                                    <th width="5%">#</th>
                                    <th width="10%">Matrícula</th>
                                    <th width="25%">Aluno</th>
                                    <th width="8%">Gênero</th>
                                    <th width="8%">MAC</th>
                                    <th width="8%">NPT</th>
                                    <?php if ($trimestre == 3 && $is_exame_classe): ?>
                                        <?php if ($is_linguagem): ?>
                                            <th width="8%">Exame Oral</th>
                                            <th width="8%">Exame Escrita</th>
                                        <?php else: ?>
                                            <th width="8%">Exame Normal</th>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <th width="8%">Média</th>
                                    <th width="10%">Status</th>
                                 </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos as $index => $aluno): ?>
                                <tr id="row-<?php echo $aluno['id']; ?>">
                                    <td class="text-center"><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                    <td class="text-center">
                                        <?php if ($aluno['genero'] == 'masculino'): ?>
                                            <i class="fas fa-mars text-primary"></i> M
                                        <?php else: ?>
                                            <i class="fas fa-venus text-danger"></i> F
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.5" min="0" max="20" 
                                               name="notas[<?php echo $aluno['id']; ?>][mac]" 
                                               class="form-control nota-input" 
                                               value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1, '.', '') : ''; ?>"
                                               onchange="calcularMedia(<?php echo $aluno['id']; ?>, <?php echo $trimestre; ?>, <?php echo $is_exame_classe ? 1 : 0; ?>, <?php echo $is_linguagem ? 1 : 0; ?>)">
                                    </td>
                                    <td>
                                        <input type="number" step="0.5" min="0" max="20" 
                                               name="notas[<?php echo $aluno['id']; ?>][npt]" 
                                               class="form-control nota-input" 
                                               value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1, '.', '') : ''; ?>"
                                               onchange="calcularMedia(<?php echo $aluno['id']; ?>, <?php echo $trimestre; ?>, <?php echo $is_exame_classe ? 1 : 0; ?>, <?php echo $is_linguagem ? 1 : 0; ?>)">
                                    </td>
                                    <?php if ($trimestre == 3 && $is_exame_classe): ?>
                                        <?php if ($is_linguagem): ?>
                                            <td>
                                                <input type="number" step="0.5" min="0" max="20" 
                                                       name="notas[<?php echo $aluno['id']; ?>][exame_oral]" 
                                                       class="form-control nota-input" 
                                                       value="<?php echo $aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 1, '.', '') : ''; ?>"
                                                       onchange="calcularMedia(<?php echo $aluno['id']; ?>, <?php echo $trimestre; ?>, <?php echo $is_exame_classe ? 1 : 0; ?>, <?php echo $is_linguagem ? 1 : 0; ?>)">
                                            </td>
                                            <td>
                                                <input type="number" step="0.5" min="0" max="20" 
                                                       name="notas[<?php echo $aluno['id']; ?>][exame_escrita]" 
                                                       class="form-control nota-input" 
                                                       value="<?php echo $aluno['exame_escrita'] !== null ? number_format($aluno['exame_escrita'], 1, '.', '') : ''; ?>"
                                                       onchange="calcularMedia(<?php echo $aluno['id']; ?>, <?php echo $trimestre; ?>, <?php echo $is_exame_classe ? 1 : 0; ?>, <?php echo $is_linguagem ? 1 : 0; ?>)">
                                            </td>
                                        <?php else: ?>
                                            <td>
                                                <input type="number" step="0.5" min="0" max="20" 
                                                       name="notas[<?php echo $aluno['id']; ?>][exame_normal]" 
                                                       class="form-control nota-input" 
                                                       value="<?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1, '.', '') : ''; ?>"
                                                       onchange="calcularMedia(<?php echo $aluno['id']; ?>, <?php echo $trimestre; ?>, <?php echo $is_exame_classe ? 1 : 0; ?>, <?php echo $is_linguagem ? 1 : 0; ?>)">
                                            </td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <td class="media-cell fw-bold text-center" id="media-<?php echo $aluno['id']; ?>">
                                        <?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 1, ',', '.') : '---'; ?>
                                    </td>
                                    <td class="status-cell text-center" id="status-<?php echo $aluno['id']; ?>">
                                        <span class="status-badge <?php echo $aluno['status_classe']; ?>">
                                            <?php echo $aluno['status_texto']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" name="salvar_notas" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Salvar Notas
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos)): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> Nenhum aluno encontrado nesta turma.
            </div>
        <?php elseif ($turma_id > 0 && $disciplina_id == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione uma disciplina para lançar as notas.
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para começar.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Menu Toggle
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Função para calcular média
        function calcularMedia(alunoId, trimestre, isExameClasse, isLinguagem) {
            var mac = parseFloat($('input[name="notas[' + alunoId + '][mac]"]').val()) || 0;
            var npt = parseFloat($('input[name="notas[' + alunoId + '][npt]"]').val()) || 0;
            var exameOral = parseFloat($('input[name="notas[' + alunoId + '][exame_oral]"]').val()) || 0;
            var exameEscrita = parseFloat($('input[name="notas[' + alunoId + '][exame_escrita]"]').val()) || 0;
            var exameNormal = parseFloat($('input[name="notas[' + alunoId + '][exame_normal]"]').val()) || 0;
            
            var media = 0;
            var mediaBimestre = (mac + npt) / 2;
            
            if (trimestre == 3 && isExameClasse == 1) {
                if (isLinguagem == 1) {
                    // Línguas: MAC + Exame Oral + Exame Escrita
                    var mediaExame = (exameOral + exameEscrita) / 2;
                    media = (mediaBimestre * 0.4) + (mediaExame * 0.6);
                } else {
                    // Outras disciplinas: MAC + Exame Normal
                    media = (mediaBimestre * 0.4) + (exameNormal * 0.6);
                }
            } else {
                // 1º e 2º trimestre ou classes normais
                media = mediaBimestre;
            }
            
            // Atualizar média na tela
            $('#media-' + alunoId).text(media.toFixed(1));
            
            // Determinar status
            var statusText = '';
            var statusClass = '';
            
            if (media >= 14) {
                statusText = 'Aprovado';
                statusClass = 'bg-success';
            } else if (media >= 10) {
                statusText = 'Exame';
                statusClass = 'bg-warning text-dark';
            } else if (media > 0) {
                statusText = 'Reprovado';
                statusClass = 'bg-danger';
            } else {
                statusText = 'Sem nota';
                statusClass = 'bg-secondary';
            }
            
            $('#status-' + alunoId).html('<span class="status-badge ' + statusClass + '">' + statusText + '</span>');
        }
        
        // Para professor: carregar disciplinas automaticamente ao selecionar turma (se necessário)
        <?php if ($is_professor): ?>
        $('#turma_id').change(function() {
            var turmaId = $(this).val();
            if (turmaId && turmaId != 0) {
                // Opcional: carregar disciplinas via AJAX
                window.location.href = 'index.php?turma_id=' + turmaId + '&ano_letivo=<?php echo $ano_letivo; ?>';
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>