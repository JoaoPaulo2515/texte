<?php
// escola/notas/index.php - Lançamento de Notas (Professor e Admin)

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// ============================================
// DETECTAR TIPO DE USUÁRIO
// ============================================
$is_professor = ($usuario_tipo == 'professor' || $papel == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');

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
// VARIÁVEIS DE FILTRO - COMPLETAS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$ano_letivo_filtro = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : $ano_letivo_id;
$order_by = isset($_GET['order_by']) ? $_GET['order_by'] : 'nome';
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';

// ============================================
// BUSCAR FUNCIONÁRIO ID (para professor)
// ============================================
$funcionario_id = null;
if ($is_professor) {
    $sql_funcionario = "SELECT id FROM funcionarios WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
    $stmt_funcionario = $conn->prepare($sql_funcionario);
    $stmt_funcionario->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
    $funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
    $funcionario_id = $funcionario['id'] ?? null;
}

// ============================================
// BUSCAR TURMAS
// ============================================
if ($is_professor && $funcionario_id) {
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
} else {
    $sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':escola_id' => $escola_id]);
    $turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// BUSCAR DISCIPLINAS
// ============================================
if ($is_professor && $funcionario_id) {
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
    $sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':escola_id' => $escola_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusNota($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'bg-secondary', 'icone' => 'fa-minus-circle'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'bg-success', 'icone' => 'fa-check-circle'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'bg-warning text-dark', 'icone' => 'fa-exclamation-triangle'];
    return ['texto' => 'Reprovado', 'classe' => 'bg-danger', 'icone' => 'fa-times-circle'];
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

function calcularMediaFinal($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe, $is_linguagem, $trimestre) {
    $media_bimestre = null;
    $valores = [];
    if ($mac !== null && $mac > 0) $valores[] = $mac;
    if ($npt !== null && $npt > 0) $valores[] = $npt;
    $media_bimestre = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
    
    if (!$is_exame_classe) {
        return $media_bimestre;
    }
    
    if ($trimestre == 3) {
        if ($is_linguagem) {
            $media_exame = null;
            $valores_exame = [];
            if ($exame_oral !== null && $exame_oral > 0) $valores_exame[] = $exame_oral;
            if ($exame_escrita !== null && $exame_escrita > 0) $valores_exame[] = $exame_escrita;
            $media_exame = !empty($valores_exame) ? round(array_sum($valores_exame) / count($valores_exame), 2) : null;
            
            if ($media_bimestre !== null && $media_exame !== null) {
                return round(($media_bimestre * 0.4) + ($media_exame * 0.6), 2);
            }
            return $media_bimestre !== null ? $media_bimestre : $media_exame;
        } else {
            if ($media_bimestre !== null && $exame_normal !== null && $exame_normal > 0) {
                return round(($media_bimestre * 0.4) + ($exame_normal * 0.6), 2);
            }
            return $media_bimestre !== null ? $media_bimestre : $exame_normal;
        }
    }
    
    return $media_bimestre;
}

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$alunos = [];
$alunos_filtrados = [];
$notas = [];
$turma_info = null;
$disciplina_info = null;
$is_exame_classe = false;
$is_linguagem = false;

if ($turma_id > 0 && $disciplina_id > 0) {
    $sql_turma_info = "SELECT id, nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    $is_exame_classe = $turma_info ? isClasseExame($turma_info['ano']) : false;
    
    $sql_disc_info = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    $is_linguagem = $disciplina_info ? isLinguagem($disciplina_info['nome']) : false;
    
    $order_sql = ($order_by == 'matricula') ? 'e.matricula ASC' : 'e.nome ASC';
    
    $sql_alunos = "
        SELECT e.id, e.nome, e.matricula, e.genero
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        WHERE m.turma_id = :turma_id 
        AND m.status = 'ativa' 
        AND m.ano_letivo = :ano_letivo_id
        ORDER BY $order_sql
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_filtro
    ]);
    $alunos_base = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
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
        ':ano_letivo_id' => $ano_letivo_filtro
    ]);
    $notas_raw = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($notas_raw as $nr) {
        $notas[$nr['estudante_id']] = $nr;
    }
    
    $alunos_todos = [];
    foreach ($alunos_base as $aluno) {
        $nota = $notas[$aluno['id']] ?? null;
        
        $mac = $nota && $nota['mac'] !== null ? (float)$nota['mac'] : null;
        $npt = $nota && $nota['npt'] !== null ? (float)$nota['npt'] : null;
        $exame_normal = $nota && $nota['nota_exame_normal'] !== null ? (float)$nota['nota_exame_normal'] : null;
        $exame_oral = $nota && $nota['nota_exame_oral'] !== null ? (float)$nota['nota_exame_oral'] : null;
        $exame_escrita = $nota && $nota['nota_exame_escrita'] !== null ? (float)$nota['nota_exame_escrita'] : null;
        $media_final = $nota && $nota['media_final'] !== null ? (float)$nota['media_final'] : null;
        $nota_id = $nota['id'] ?? null;
        
        if ($media_final === null && ($mac !== null || $npt !== null)) {
            $media_final = calcularMediaFinal($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe, $is_linguagem, $trimestre);
        }
        
        $status = getStatusNota($media_final);
        
        $alunos_todos[] = [
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
            'status_classe' => $status['classe'],
            'status_icone' => $status['icone']
        ];
    }
    
    // Aplicar filtro de status
    if ($status_filtro != 'todos') {
        $alunos_filtrados = array_filter($alunos_todos, function($aluno) use ($status_filtro) {
            if ($status_filtro == 'aprovados') return $aluno['media_final'] !== null && $aluno['media_final'] >= 14;
            if ($status_filtro == 'exame') return $aluno['media_final'] !== null && $aluno['media_final'] >= 10 && $aluno['media_final'] < 14;
            if ($status_filtro == 'reprovados') return $aluno['media_final'] !== null && $aluno['media_final'] < 10 && $aluno['media_final'] > 0;
            if ($status_filtro == 'sem_nota') return $aluno['media_final'] === null || $aluno['media_final'] <= 0;
            return true;
        });
        $alunos_filtrados = array_values($alunos_filtrados);
    } else {
        $alunos_filtrados = $alunos_todos;
    }
    
    // Calcular estatísticas
    $soma_medias = 0;
    $total_com_nota = 0;
    foreach ($alunos_filtrados as $aluno) {
        if ($aluno['media_final'] !== null && $aluno['media_final'] > 0) {
            $soma_medias += $aluno['media_final'];
            $total_com_nota++;
        }
    }
    $media_geral_turma = $total_com_nota > 0 ? round($soma_medias / $total_com_nota, 2) : 0;
    
    $total_aprovados = count(array_filter($alunos_todos, fn($a) => $a['media_final'] !== null && $a['media_final'] >= 14));
    $total_exame = count(array_filter($alunos_todos, fn($a) => $a['media_final'] !== null && $a['media_final'] >= 10 && $a['media_final'] < 14));
    
    $alunos = $alunos_filtrados;
}

// ============================================
// SALVAR NOTAS
// ============================================
$mensagem_sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_notas'])) {
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $trimestre_post = (int)$_POST['trimestre'];
    $ano_letivo_post = (int)$_POST['ano_letivo'];
    
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
    
    try {
        $conn->beginTransaction();
        $contador = 0;
        
        foreach ($_POST['notas'] as $estudante_id => $nota_data) {
            $estudante_id = (int)$estudante_id;
            $mac = !empty($nota_data['mac']) ? (float)str_replace(',', '.', $nota_data['mac']) : null;
            $npt = !empty($nota_data['npt']) ? (float)str_replace(',', '.', $nota_data['npt']) : null;
            $exame_normal = !empty($nota_data['exame_normal']) ? (float)str_replace(',', '.', $nota_data['exame_normal']) : null;
            $exame_oral = !empty($nota_data['exame_oral']) ? (float)str_replace(',', '.', $nota_data['exame_oral']) : null;
            $exame_escrita = !empty($nota_data['exame_escrita']) ? (float)str_replace(',', '.', $nota_data['exame_escrita']) : null;
            
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
                continue;
            }
            
            $media_final = calcularMediaFinal($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe_calc, $is_linguagem_calc, $trimestre_post);
            
            $sql_check_nota = "SELECT id FROM notas 
                              WHERE estudante_id = :estudante_id 
                              AND disciplina_id = :disciplina_id 
                              AND bimestre = :trimestre
                              AND ano_letivo_id = :ano_letivo_id";
            $stmt_check_nota = $conn->prepare($sql_check_nota);
            $stmt_check_nota->execute([
                ':estudante_id' => $estudante_id,
                ':disciplina_id' => $disciplina_id_post,
                ':trimestre' => $trimestre_post,
                ':ano_letivo_id' => $ano_letivo_post
            ]);
            $nota_existente = $stmt_check_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($nota_existente) {
                $sql = "UPDATE notas SET 
                            mac = :mac, 
                            npt = :npt, 
                            nota_exame_normal = :exame_normal,
                            nota_exame_oral = :exame_oral,
                            nota_exame_escrita = :exame_escrita,
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
                    ':id' => $nota_existente['id']
                ]);
            } else {
                $sql = "INSERT INTO notas (
                            estudante_id, disciplina_id, bimestre, ano_letivo_id,
                            mac, npt, nota_exame_normal, nota_exame_oral, nota_exame_escrita,
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
            $contador++;
        }
        
        $conn->commit();
        $mensagem_sucesso = "$contador registro(s) de nota(s) salvo(s) com sucesso!";
        
        header("Location: index.php?turma_id=$turma_id_post&disciplina_id=$disciplina_id_post&trimestre=$trimestre_post&ano_letivo=$ano_letivo_post&msg=success");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao salvar notas: " . $e->getMessage();
    }
}

if (isset($_GET['msg']) && $_GET['msg'] == 'success') {
    $mensagem_sucesso = "Notas salvas com sucesso!";
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
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #218838; }
        .nota-input { width: 90px; text-align: center; }
        .tipo-usuario { font-size: 12px; padding: 4px 12px; border-radius: 20px; }
        .tipo-professor { background: #17a2b8; color: white; }
        .tipo-admin { background: #28a745; color: white; }
        .table-notas th { background: #e9ecef; text-align: center; vertical-align: middle; }
        .table-notas td { vertical-align: middle; text-align: center; }
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; color: white; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .media-cell { font-size: 1.1rem; font-weight: 600; }
        .info-box { background: #e8f5e9; border-left: 4px solid #006B3E; padding: 12px 15px; margin-bottom: 20px; border-radius: 8px; }
        
        /* Botão de Ajuda */
        .btn-ajuda {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        .btn-ajuda i { font-size: 28px; }
        .btn-ajuda .tooltip-text {
            position: absolute;
            right: 70px;
            background: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        .btn-ajuda:hover .tooltip-text { opacity: 1; }
        @media (max-width: 768px) {
            .btn-ajuda { bottom: 20px; right: 20px; width: 50px; height: 50px; }
            .btn-ajuda i { font-size: 24px; }
        }
        
        .ajuda-section { margin-bottom: 20px; }
        .ajuda-section h5 { color: #006B3E; margin-bottom: 10px; padding-bottom: 5px; border-bottom: 2px solid #006B3E; }
        .ajuda-section ul, .ajuda-section ol { padding-left: 20px; }
        .ajuda-section li { margin-bottom: 8px; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
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
            <div>
                <span><i class="fas fa-calendar-alt"></i> <?php echo date('d/m/Y'); ?></span>
                <span class="ms-3"><i class="fas fa-user"></i> <?php echo htmlspecialchars($usuario_nome); ?></span>
            </div>
        </div>
        
        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h3 class="mb-0"><i class="fas fa-filter"></i> Filtros de Pesquisa</h3></div>
            <div class="card-body">
                <form method="GET" class="row g-3" id="formFiltros">
                    <div class="col-md-2">
                        <label class="filter-label">Ano Letivo</label>
                        <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                            <?php for ($i = $ano_letivo_ano - 2; $i <= $ano_letivo_ano + 1; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $ano_letivo_filtro == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="filter-label">Turma</label>
                        <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                            <option value="0">-- Selecione uma turma --</option>
                            <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>><?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']) . ' (' . ucfirst($t['turno']) . ')'; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="filter-label">Disciplina</label>
                        <select name="disciplina_id" class="form-select" id="disciplina_id" onchange="this.form.submit()">
                            <option value="0">-- Selecione uma disciplina --</option>
                            <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $disciplina_id == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['nome']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="filter-label">Trimestre</label>
                        <select name="trimestre" class="form-select" onchange="this.form.submit()">
                            <option value="1" <?php echo $trimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                            <option value="2" <?php echo $trimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                            <option value="3" <?php echo $trimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button></div>
                </form>
                
                <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
                <hr class="my-3">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                    <input type="hidden" name="trimestre" value="<?php echo $trimestre; ?>">
                    <input type="hidden" name="ano_letivo" value="<?php echo $ano_letivo_filtro; ?>">
                    <div class="col-md-3"><label class="filter-label">Ordenar por</label><select name="order_by" class="form-select" onchange="this.form.submit()"><option value="nome" <?php echo $order_by == 'nome' ? 'selected' : ''; ?>>Nome do Aluno</option><option value="matricula" <?php echo $order_by == 'matricula' ? 'selected' : ''; ?>>Número de Matrícula</option></select></div>
                    <div class="col-md-3"><label class="filter-label">Status do Aluno</label><select name="status" class="form-select" onchange="this.form.submit()"><option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option><option value="aprovados" <?php echo $status_filtro == 'aprovados' ? 'selected' : ''; ?>>Aprovados (≥14)</option><option value="exame" <?php echo $status_filtro == 'exame' ? 'selected' : ''; ?>>Exame (10-13.9)</option><option value="reprovados" <?php echo $status_filtro == 'reprovados' ? 'selected' : ''; ?>>Reprovados (<10)</option><option value="sem_nota" <?php echo $status_filtro == 'sem_nota' ? 'selected' : ''; ?>>Sem Nota</option></select></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Formulário de Lançamento de Notas -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-chalkboard"></i> Lançar Notas - <?php echo htmlspecialchars($turma_info['nome']); ?> (<?php echo $turma_info['ano']; ?>ª) - <?php echo htmlspecialchars($disciplina_info['nome']); ?> - <?php echo $trimestre; ?>º Trimestre
                    <?php if ($is_exame_classe && $trimestre == 3): ?>
                    <span class="badge bg-warning text-dark ms-2"><i class="fas fa-exclamation-triangle"></i> Classe com Exame Final</span>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-body">
                <div class="info-box">
                    <div class="row">
                        <div class="col-md-3"><i class="fas fa-users"></i> <strong>Total Alunos:</strong> <?php echo count($alunos); ?></div>
                        <div class="col-md-3"><i class="fas fa-chart-line"></i> <strong>Média Geral:</strong> <?php echo $media_geral_turma > 0 ? number_format($media_geral_turma, 1) : '--'; ?></div>
                        <div class="col-md-3"><i class="fas fa-check-circle text-success"></i> <strong>Aprovados:</strong> <?php echo $total_aprovados; ?></div>
                        <div class="col-md-3"><i class="fas fa-exclamation-triangle text-warning"></i> <strong>Exame:</strong> <?php echo $total_exame; ?></div>
                    </div>
                </div>
                
                <form method="POST" id="formNotas">
                    <input type="hidden" name="salvar_notas" value="1">
                    <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                    <input type="hidden" name="trimestre" value="<?php echo $trimestre; ?>">
                    <input type="hidden" name="ano_letivo" value="<?php echo $ano_letivo_filtro; ?>">
                    
                    <div class="table-responsive">
                        <table class="table table-bordered table-notas">
                            <thead>
                                <tr><th>#</th><th>Aluno</th><th>Nº Matrícula</th>
                                <?php if ($is_exame_classe && $trimestre == 3 && $is_linguagem): ?>
                                    <th>MAC (40%)</th><th>NPT (40%)</th><th>Média Parcial</th><th>Exame Oral (60%)</th><th>Exame Escrito (60%)</th><th>Média Exame</th>
                                <?php elseif ($is_exame_classe && $trimestre == 3): ?>
                                    <th>MAC (40%)</th><th>NPT (40%)</th><th>Média Parcial</th><th>Exame Normal (60%)</th>
                                <?php else: ?>
                                    <th>MAC (50%)</th><th>NPT (50%)</th><th>Média Parcial</th>
                                <?php endif; ?>
                                <th>Média Final</th><th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $contador = 1; ?>
                                <?php foreach ($alunos as $aluno): ?>
                                <tr>
                                    <td><?php echo $contador++; ?></td>
                                    <td class="text-start"><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    
                                    <?php if ($is_exame_classe && $trimestre == 3 && $is_linguagem): ?>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][mac]" value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : ''; ?>" class="form-control nota-input mac-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][npt]" value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : ''; ?>" class="form-control nota-input npt-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td class="media-cell"><span id="media-parcial-<?php echo $aluno['id']; ?>"><?php $mp = null; $v = []; if($aluno['mac']!==null) $v[]=$aluno['mac']; if($aluno['npt']!==null) $v[]=$aluno['npt']; $mp = !empty($v) ? round(array_sum($v)/count($v),1):null; echo $mp!==null ? number_format($mp,1) : '--'; ?></span></td>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][exame_oral]" value="<?php echo $aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 1) : ''; ?>" class="form-control nota-input exame-oral-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][exame_escrita]" value="<?php echo $aluno['exame_escrita'] !== null ? number_format($aluno['exame_escrita'], 1) : ''; ?>" class="form-control nota-input exame-escrita-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td class="media-cell"><span id="media-exame-<?php echo $aluno['id']; ?>"><?php $me = null; $ve = []; if($aluno['exame_oral']!==null) $ve[]=$aluno['exame_oral']; if($aluno['exame_escrita']!==null) $ve[]=$aluno['exame_escrita']; $me = !empty($ve) ? round(array_sum($ve)/count($ve),1):null; echo $me!==null ? number_format($me,1) : '--'; ?></span></td>
                                    <?php elseif ($is_exame_classe && $trimestre == 3): ?>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][mac]" value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : ''; ?>" class="form-control nota-input mac-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][npt]" value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : ''; ?>" class="form-control nota-input npt-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td class="media-cell"><span id="media-parcial-<?php echo $aluno['id']; ?>"><?php $mp = null; $v = []; if($aluno['mac']!==null) $v[]=$aluno['mac']; if($aluno['npt']!==null) $v[]=$aluno['npt']; $mp = !empty($v) ? round(array_sum($v)/count($v),1):null; echo $mp!==null ? number_format($mp,1) : '--'; ?></span></td>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][exame_normal]" value="<?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1) : ''; ?>" class="form-control nota-input exame-normal-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                    <?php else: ?>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][mac]" value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : ''; ?>" class="form-control nota-input mac-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td><input type="number" step="0.1" min="0" max="20" name="notas[<?php echo $aluno['id']; ?>][npt]" value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : ''; ?>" class="form-control nota-input npt-input" data-aluno="<?php echo $aluno['id']; ?>"></td>
                                        <td class="media-cell"><span id="media-parcial-<?php echo $aluno['id']; ?>"><?php $mp = null; $v = []; if($aluno['mac']!==null) $v[]=$aluno['mac']; if($aluno['npt']!==null) $v[]=$aluno['npt']; $mp = !empty($v) ? round(array_sum($v)/count($v),1):null; echo $mp!==null ? number_format($mp,1) : '--'; ?></span></td>
                                    <?php endif; ?>
                                    
                                    <td class="media-cell"><strong><span id="media-final-<?php echo $aluno['id']; ?>" class="badge bg-<?php echo $aluno['media_final'] >= 14 ? 'success' : ($aluno['media_final'] >= 10 ? 'warning text-dark' : 'danger'); ?> p-2" style="font-size:1rem;"><?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 1) : '--'; ?></span></strong></td>
                                    <td><span class="status-badge <?php echo $aluno['status_classe']; ?>"><i class="fas <?php echo $aluno['status_icone']; ?>"></i> <?php echo $aluno['status_texto']; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-warning" onclick="preencherExemplo()"><i class="fas fa-chart-line"></i> Preencher Dados de Exemplo</button>
                        <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save"></i> Salvar Notas</button>
                    </div>
                </form>
            </div>
        </div>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos)): ?>
        <div class="card"><div class="card-body text-center py-5"><i class="fas fa-users fa-3x text-muted mb-3"></i><h4>Nenhum aluno encontrado</h4><p>Não há alunos matriculados nesta turma para o ano letivo selecionado.</p><a href="index.php" class="btn btn-primary"><i class="fas fa-arrow-left"></i> Voltar</a></div></div>
        <?php endif; ?>
    </div>
    
    <!-- Botão de Ajuda Flutuante -->
    <button class="btn-ajuda" id="btnAjuda">
        <i class="fas fa-question"></i>
        <span class="tooltip-text">Precisa de ajuda?</span>
    </button>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Lançamento de Notas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="ajuda-section">
                        <h5><i class="fas fa-graduation-cap"></i> Como lançar notas</h5>
                        <ol>
                            <li>Selecione a <strong>Turma</strong> desejada</li>
                            <li>Escolha a <strong>Disciplina</strong></li>
                            <li>Selecione o <strong>Trimestre</strong> (1º, 2º ou 3º)</li>
                            <li>Preencha as notas <strong>MAC</strong> e <strong>NPT</strong> para cada aluno</li>
                            <li>Clique em <strong>Salvar Notas</strong></li>
                        </ol>
                    </div>
                    
                    <div class="ajuda-section">
                        <h5><i class="fas fa-calculator"></i> Como a média é calculada</h5>
                        <ul>
                            <li><strong>Média Parcial:</strong> (MAC + NPT) / 2</li>
                            <li><strong>Classes de Exame (6º, 9º, 12º):</strong> 40% Média Parcial + 60% Exame</li>
                            <li><strong>Disciplinas de Línguas:</strong> Média do Exame = (Oral + Escrito) / 2</li>
                        </ul>
                    </div>
                    
                    <div class="ajuda-section">
                        <h5><i class="fas fa-flag-checkered"></i> Critérios de aprovação</h5>
                        <ul>
                            <li><span class="badge bg-success">Aprovado</span> - Média ≥ 14</li>
                            <li><span class="badge bg-warning text-dark">Exame</span> - Média entre 10 e 13.9</li>
                            <li><span class="badge bg-danger">Reprovado</span> - Média &lt; 10</li>
                        </ul>
                    </div>
                    
                    <div class="ajuda-section">
                        <h5><i class="fas fa-lightbulb"></i> Dicas rápidas</h5>
                        <ul>
                            <li>Use os filtros para refinar a visualização dos alunos</li>
                            <li>As médias são calculadas automaticamente em tempo real</li>
                            <li>Clique em "Preencher Dados de Exemplo" para testar o sistema</li>
                            <li>Notas devem ser de 0 a 20</li>
                        </ul>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle"></i>
                        <strong>Precisa de mais ajuda?</strong>
                        <a href="suporte/faq.php" class="alert-link">Veja as perguntas frequentes</a> ou 
                        <a href="suporte/chamados.php" class="alert-link">abra um chamado de suporte</a>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <a href="suporte/faq.php" class="btn btn-primary-custom"><i class="fas fa-book"></i> Ver FAQ</a>
                    <a href="suporte/chamados.php" class="btn btn-info"><i class="fas fa-headset"></i> Abrir Chamado</a>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#menuToggle').click(function() { $('.sidebar').toggleClass('active'); $('.main-content').toggleClass('active'); });
            
            $('#btnAjuda').click(function() { new bootstrap.Modal(document.getElementById('modalAjuda')).show(); });
            
            $('.nota-input').on('input', function() {
                let value = $(this).val();
                value = value.replace(/[^0-9,]/g, '');
                if (value === '') { $(this).val(''); }
                else {
                    value = value.replace(',', '.');
                    let num = parseFloat(value);
                    if (!isNaN(num)) { if (num < 0) num = 0; if (num > 20) num = 20; $(this).val(num.toFixed(1).replace('.', ',')); }
                }
            });
            
            function calcularMedias(alunoId, isExameClasse, trimestre, isLinguagem) {
                let mac = parseFloat($(`input[name='notas[${alunoId}][mac]']`).val().replace(',', '.'));
                let npt = parseFloat($(`input[name='notas[${alunoId}][npt]']`).val().replace(',', '.'));
                let valores = [];
                if (!isNaN(mac)) valores.push(mac);
                if (!isNaN(npt)) valores.push(npt);
                let mediaParcial = valores.length > 0 ? (valores.reduce((a, b) => a + b, 0) / valores.length) : null;
                if (mediaParcial !== null) { $(`#media-parcial-${alunoId}`).text(mediaParcial.toFixed(1)); } else { $(`#media-parcial-${alunoId}`).text('--'); }
                
                let mediaFinal = null;
                if (isExameClasse && trimestre == 3) {
                    if (isLinguagem) {
                        let exameOral = parseFloat($(`input[name='notas[${alunoId}][exame_oral]']`).val().replace(',', '.'));
                        let exameEscrita = parseFloat($(`input[name='notas[${alunoId}][exame_escrita]']`).val().replace(',', '.'));
                        let valoresExame = [];
                        if (!isNaN(exameOral)) valoresExame.push(exameOral);
                        if (!isNaN(exameEscrita)) valoresExame.push(exameEscrita);
                        let mediaExame = valoresExame.length > 0 ? (valoresExame.reduce((a, b) => a + b, 0) / valoresExame.length) : null;
                        if (mediaExame !== null) { $(`#media-exame-${alunoId}`).text(mediaExame.toFixed(1)); } else { $(`#media-exame-${alunoId}`).text('--'); }
                        if (mediaParcial !== null && mediaExame !== null) { mediaFinal = (mediaParcial * 0.4) + (mediaExame * 0.6); }
                        else if (mediaParcial !== null) { mediaFinal = mediaParcial; }
                        else if (mediaExame !== null) { mediaFinal = mediaExame; }
                    } else {
                        let exameNormal = parseFloat($(`input[name='notas[${alunoId}][exame_normal]']`).val().replace(',', '.'));
                        if (mediaParcial !== null && !isNaN(exameNormal)) { mediaFinal = (mediaParcial * 0.4) + (exameNormal * 0.6); }
                        else if (mediaParcial !== null) { mediaFinal = mediaParcial; }
                        else if (!isNaN(exameNormal)) { mediaFinal = exameNormal; }
                    }
                } else { mediaFinal = mediaParcial; }
                
                if (mediaFinal !== null) {
                    mediaFinal = Math.round(mediaFinal * 10) / 10;
                    $(`#media-final-${alunoId}`).text(mediaFinal.toFixed(1));
                    let badge = $(`#media-final-${alunoId}`);
                    if (mediaFinal >= 14) {
                        badge.removeClass('bg-warning bg-danger').addClass('bg-success');
                        $(`#media-final-${alunoId}`).closest('tr').find('.status-badge').removeClass('bg-secondary bg-warning bg-danger').addClass('bg-success').html('<i class="fas fa-check-circle"></i> Aprovado');
                    } else if (mediaFinal >= 10) {
                        badge.removeClass('bg-success bg-danger').addClass('bg-warning text-dark');
                        $(`#media-final-${alunoId}`).closest('tr').find('.status-badge').removeClass('bg-secondary bg-success bg-danger').addClass('bg-warning text-dark').html('<i class="fas fa-exclamation-triangle"></i> Exame');
                    } else if (mediaFinal > 0) {
                        badge.removeClass('bg-success bg-warning').addClass('bg-danger');
                        $(`#media-final-${alunoId}`).closest('tr').find('.status-badge').removeClass('bg-secondary bg-success bg-warning').addClass('bg-danger').html('<i class="fas fa-times-circle"></i> Reprovado');
                    }
                }
            }
            
            let isExameClasse = <?php echo $is_exame_classe ? 'true' : 'false'; ?>;
            let trimestre = <?php echo $trimestre; ?>;
            let isLinguagem = <?php echo $is_linguagem ? 'true' : 'false'; ?>;
            $('.mac-input, .npt-input, .exame-normal-input, .exame-oral-input, .exame-escrita-input').on('input', function() { calcularMedias($(this).data('aluno'), isExameClasse, trimestre, isLinguagem); });
        });
        
        function preencherExemplo() {
            $('.mac-input').each(function() { if ($(this).val() === '') { $(this).val('15,0'); } });
            $('.npt-input').each(function() { if ($(this).val() === '') { $(this).val('14,0'); } });
            $('.exame-normal-input').each(function() { if ($(this).val() === '') { $(this).val('16,0'); } });
            $('.exame-oral-input').each(function() { if ($(this).val() === '') { $(this).val('15,0'); } });
            $('.exame-escrita-input').each(function() { if ($(this).val() === '') { $(this).val('16,0'); } });
            $('.mac-input, .npt-input, .exame-normal-input, .exame-oral-input, .exame-escrita-input').trigger('input');
            alert('Dados de exemplo preenchidos! Clique em "Salvar Notas" para confirmar.');
        }
    </script>
</body>
</html>