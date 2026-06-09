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
// VARIÁVEIS DE FILTRO - CORRIGIDAS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1; // CORRIGIDO: mudado de 'bimestre' para 'trimestre'
$ano_letivo_filtro = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : $ano_letivo_id;
$tipo_avaliacao = isset($_GET['tipo_avaliacao']) ? $_GET['tipo_avaliacao'] : 'todas';
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
    // Calcular média do bimestre (MAC + NPT)
    $media_bimestre = null;
    $valores = [];
    if ($mac !== null && $mac > 0) $valores[] = $mac;
    if ($npt !== null && $npt > 0) $valores[] = $npt;
    $media_bimestre = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
    
    // Se não for classe de exame, retorna apenas a média do bimestre
    if (!$is_exame_classe) {
        return $media_bimestre;
    }
    
    // Se for 3º trimestre e classe de exame, aplica regra de exame
    if ($trimestre == 3) {
        if ($is_linguagem) {
            // Média do exame para línguas (Oral + Escrito)
            $media_exame = null;
            $valores_exame = [];
            if ($exame_oral !== null && $exame_oral > 0) $valores_exame[] = $exame_oral;
            if ($exame_escrita !== null && $exame_escrita > 0) $valores_exame[] = $exame_escrita;
            $media_exame = !empty($valores_exame) ? round(array_sum($valores_exame) / count($valores_exame), 2) : null;
            
            // Fórmula: 40% média bimestre + 60% média exame
            if ($media_bimestre !== null && $media_exame !== null) {
                return round(($media_bimestre * 0.4) + ($media_exame * 0.6), 2);
            }
            return $media_bimestre !== null ? $media_bimestre : $media_exame;
        } else {
            // Disciplina normal com exame normal
            if ($media_bimestre !== null && $exame_normal !== null && $exame_normal > 0) {
                // Fórmula: 40% média bimestre + 60% exame normal
                return round(($media_bimestre * 0.4) + ($exame_normal * 0.6), 2);
            }
            return $media_bimestre !== null ? $media_bimestre : $exame_normal;
        }
    }
    
    // Para 1º e 2º trimestre em classe de exame, sem exame
    return $media_bimestre;
}

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$alunos = [];
$alunos_filtrados = []; // Array para alunos após filtro
$notas = [];
$turma_info = null;
$disciplina_info = null;
$is_exame_classe = false;
$is_linguagem = false;

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT id, nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    $is_exame_classe = $turma_info ? isClasseExame($turma_info['ano']) : false;
    
    // Buscar informações da disciplina
    $sql_disc_info = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    $is_linguagem = $disciplina_info ? isLinguagem($disciplina_info['nome']) : false;
    
    // Ordenação
    $order_sql = ($order_by == 'matricula') ? 'e.matricula ASC' : 'e.nome ASC';
    
    // CORRIGIDO: Buscar alunos da turma com o nome correto do campo ano_letivo_id
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
    
    // CORRIGIDO: Buscar notas existentes com os nomes corretos dos campos
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
    
    // Preparar array de alunos com dados organizados
    foreach ($alunos_base as $aluno) {
        $nota = $notas[$aluno['id']] ?? null;
        
        $mac = $nota && $nota['mac'] !== null ? (float)$nota['mac'] : null;
        $npt = $nota && $nota['npt'] !== null ? (float)$nota['npt'] : null;
        $exame_normal = $nota && $nota['nota_exame_normal'] !== null ? (float)$nota['nota_exame_normal'] : null;
        $exame_oral = $nota && $nota['nota_exame_oral'] !== null ? (float)$nota['nota_exame_oral'] : null;
        $exame_escrita = $nota && $nota['nota_exame_escrita'] !== null ? (float)$nota['nota_exame_escrita'] : null;
        $media_final = $nota && $nota['media_final'] !== null ? (float)$nota['media_final'] : null;
        $nota_id = $nota['id'] ?? null;
        
        // Calcular média se necessário
        if ($media_final === null && ($mac !== null || $npt !== null || $exame_normal !== null || $exame_oral !== null || $exame_escrita !== null)) {
            $media_final = calcularMediaFinal($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe, $is_linguagem, $trimestre);
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
            'status_classe' => $status['classe'],
            'status_icone' => $status['icone']
        ];
    }
    
    // CORRIGIDO: Aplicar filtro de status ANTES de calcular as estatísticas
    if ($status_filtro != 'todos') {
        $alunos_filtrados = array_filter($alunos, function($aluno) use ($status_filtro) {
            if ($status_filtro == 'aprovados') return $aluno['media_final'] !== null && $aluno['media_final'] >= 14;
            if ($status_filtro == 'exame') return $aluno['media_final'] !== null && $aluno['media_final'] >= 10 && $aluno['media_final'] < 14;
            if ($status_filtro == 'reprovados') return $aluno['media_final'] !== null && $aluno['media_final'] < 10 && $aluno['media_final'] > 0;
            if ($status_filtro == 'sem_nota') return $aluno['media_final'] === null || $aluno['media_final'] <= 0;
            return true;
        });
        // Reindexar array
        $alunos_filtrados = array_values($alunos_filtrados);
    } else {
        $alunos_filtrados = $alunos;
    }
    
    // Calcular estatísticas APENAS com os alunos filtrados
    $soma_medias = 0;
    $total_com_nota = 0;
    foreach ($alunos_filtrados as $aluno) {
        if ($aluno['media_final'] !== null && $aluno['media_final'] > 0) {
            $soma_medias += $aluno['media_final'];
            $total_com_nota++;
        }
    }
    $media_geral_turma = $total_com_nota > 0 ? round($soma_medias / $total_com_nota, 2) : 0;
    
    // Calcular contagens para exibição
    $total_aprovados = count(array_filter($alunos, fn($a) => $a['media_final'] !== null && $a['media_final'] >= 14));
    $total_exame = count(array_filter($alunos, fn($a) => $a['media_final'] !== null && $a['media_final'] >= 10 && $a['media_final'] < 14));
    $total_reprovados = count(array_filter($alunos, fn($a) => $a['media_final'] !== null && $a['media_final'] < 10 && $a['media_final'] > 0));
    $total_sem_nota = count(array_filter($alunos, fn($a) => $a['media_final'] === null || $a['media_final'] <= 0));
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
    
    // Buscar informações da turma para as regras de cálculo
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
            
            // Validar se o estudante pertence à turma
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
            
            // Calcular média final
            $media_final = calcularMediaFinal($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe_calc, $is_linguagem_calc, $trimestre_post);
            
            // CORRIGIDO: Verificar se já existe nota com os nomes corretos dos campos
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
                // Atualizar
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
                // Inserir
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
        
        // Redirecionar para evitar reenvio
        header("Location: index.php?turma_id=$turma_id_post&disciplina_id=$disciplina_id_post&trimestre=$trimestre_post&ano_letivo=$ano_letivo_post&msg=success");
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $erro = "Erro ao salvar notas: " . $e->getMessage();
    }
}

// Verificar mensagem de sucesso via GET
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
        .badge-exame { background: #ffc107; color: #333; }
        .info-box { background: #e8f5e9; border-left: 4px solid #006B3E; padding: 12px 15px; margin-bottom: 20px; border-radius: 8px; }
        .exame-fields { background: #fff3cd; padding: 10px; border-radius: 8px; margin-top: 5px; }
        .table-responsive { overflow-x: auto; }
        .fixed-column { position: sticky; left: 0; background: white; z-index: 1; }
        .input-group-text { background: #f8f9fa; }
        .nota-input:focus { border-color: #006B3E; box-shadow: 0 0 0 0.2rem rgba(0,107,62,0.25); }
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
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-filter"></i> Filtros de Pesquisa</h3>
            </div>
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
                            <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>>
                                <?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']) . ' (' . ucfirst($t['turno']) . ')'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="filter-label">Disciplina</label>
                        <select name="disciplina_id" class="form-select" id="disciplina_id" onchange="this.form.submit()">
                            <option value="0">-- Selecione uma disciplina --</option>
                            <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo $disciplina_id == $d['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['nome']); ?>
                                <?php if ($d['codigo']): ?> (<?php echo $d['codigo']; ?>)<?php endif; ?>
                            </option>
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
                    
                    <div class="col-md-2">
                        <label class="filter-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                    </div>
                </form>
                
                <!-- Filtros adicionais (aparecem quando há dados) -->
                <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
                <hr class="my-3">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                    <input type="hidden" name="trimestre" value="<?php echo $trimestre; ?>">
                    <input type="hidden" name="ano_letivo" value="<?php echo $ano_letivo_filtro; ?>">
                    
                    <div class="col-md-3">
                        <label class="filter-label">Ordenar por</label>
                        <select name="order_by" class="form-select" onchange="this.form.submit()">
                            <option value="nome" <?php echo $order_by == 'nome' ? 'selected' : ''; ?>>Nome do Aluno</option>
                            <option value="matricula" <?php echo $order_by == 'matricula' ? 'selected' : ''; ?>>Número de Matrícula</option>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="filter-label">Status do Aluno</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                            <option value="aprovados" <?php echo $status_filtro == 'aprovados' ? 'selected' : ''; ?>>Aprovados (≥14)</option>
                            <option value="exame" <?php echo $status_filtro == 'exame' ? 'selected' : ''; ?>>Exame (10-13.9)</option>
                            <option value="reprovados" <?php echo $status_filtro == 'reprovados' ? 'selected' : ''; ?>>Reprovados (<10)</option>
                            <option value="sem_nota" <?php echo $status_filtro == 'sem_nota' ? 'selected' : ''; ?>>Sem Nota</option>
                        </select>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Formulário de Lançamento de Notas -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos_filtrados)): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="mb-0">
                    <i class="fas fa-chalkboard"></i> 
                    Lançar Notas - <?php echo htmlspecialchars($turma_info['nome']); ?> 
                    (<?php echo $turma_info['ano']; ?>ª) - 
                    <?php echo htmlspecialchars($disciplina_info['nome']); ?>
                    - <?php echo $trimestre; ?>º Trimestre
                    
                    <?php if ($is_exame_classe && $trimestre == 3): ?>
                    <span class="badge bg-warning text-dark ms-2">
                        <i class="fas fa-exclamation-triangle"></i> Classe com Exame Final
                    </span>
                    <?php endif; ?>
                    
                    <?php if ($status_filtro != 'todos'): ?>
                    <span class="badge bg-info ms-2">
                        <i class="fas fa-filter"></i> Filtro Ativo: 
                        <?php 
                            switch($status_filtro) {
                                case 'aprovados': echo 'Aprovados'; break;
                                case 'exame': echo 'Exame'; break;
                                case 'reprovados': echo 'Reprovados'; break;
                                case 'sem_nota': echo 'Sem Nota'; break;
                            }
                        ?>
                    </span>
                    <?php endif; ?>
                </h3>
            </div>
            <div class="card-body">
                <!-- Informações da Turma -->
                <div class="info-box">
                    <div class="row">
                        <div class="col-md-3">
                            <i class="fas fa-users"></i> <strong>Total Alunos:</strong> <?php echo count($alunos_filtrados); ?>
                            <?php if ($status_filtro != 'todos'): ?>
                                <small class="text-muted">(filtrado)</small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-chart-line"></i> <strong>Média Geral:</strong> 
                            <?php echo $media_geral_turma > 0 ? number_format($media_geral_turma, 1) : '--'; ?>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-check-circle text-success"></i> <strong>Aprovados:</strong> <?php echo $total_aprovados; ?>
                        </div>
                        <div class="col-md-3">
                            <i class="fas fa-exclamation-triangle text-warning"></i> <strong>Exame:</strong> <?php echo $total_exame; ?>
                        </div>
                    </div>
                    <?php if ($status_filtro != 'todos'): ?>
                    <div class="row mt-2">
                        <div class="col-12">
                            <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_filtro; ?>&order_by=<?php echo $order_by; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fas fa-times"></i> Limpar Filtro
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
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
                                <tr>
                                    <th style="width: 50px;">#</th>
                                    <th style="min-width: 200px;">Aluno</th>
                                    <th style="width: 100px;">Nº Matrícula</th>
                                    <?php if ($is_exame_classe && $trimestre == 3 && $is_linguagem): ?>
                                        <th style="width: 100px;">MAC (40%)</th>
                                        <th style="width: 100px;">NPT (40%)</th>
                                        <th style="width: 100px;">Média Parcial</th>
                                        <th style="width: 100px;">Exame Oral (60%)</th>
                                        <th style="width: 100px;">Exame Escrito (60%)</th>
                                        <th style="width: 100px;">Média Exame</th>
                                    <?php elseif ($is_exame_classe && $trimestre == 3): ?>
                                        <th style="width: 100px;">MAC (40%)</th>
                                        <th style="width: 100px;">NPT (40%)</th>
                                        <th style="width: 100px;">Média Parcial</th>
                                        <th style="width: 100px;">Exame Normal (60%)</th>
                                    <?php else: ?>
                                        <th style="width: 100px;">MAC (50%)</th>
                                        <th style="width: 100px;">NPT (50%)</th>
                                        <th style="width: 100px;">Média Parcial</th>
                                    <?php endif; ?>
                                    <th style="width: 100px;">Média Final</th>
                                    <th style="width: 100px;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $contador = 1; ?>
                                <?php foreach ($alunos_filtrados as $aluno): ?>
                                <tr>
                                    <td><?php echo $contador++; ?></td>
                                    <td class="text-start">
                                        <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                        <?php if ($aluno['genero'] == 'feminino'): ?>
                                            <i class="fas fa-female text-pink"></i>
                                        <?php else: ?>
                                            <i class="fas fa-male text-info"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    
                                    <?php if ($is_exame_classe && $trimestre == 3 && $is_linguagem): ?>
                                        <!-- MAC -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][mac]" 
                                                   value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : ''; ?>"
                                                   class="form-control nota-input mac-input" 
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   data-trimestre="<?php echo $trimestre; ?>"
                                                   data-exame="1">
                                        </td>
                                        <!-- NPT -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][npt]" 
                                                   value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : ''; ?>"
                                                   class="form-control nota-input npt-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>">
                                        </td>
                                        <!-- Média Parcial -->
                                        <td class="media-cell">
                                            <span id="media-parcial-<?php echo $aluno['id']; ?>">
                                                <?php 
                                                    $media_parcial = null;
                                                    $valores = [];
                                                    if ($aluno['mac'] !== null && $aluno['mac'] > 0) $valores[] = $aluno['mac'];
                                                    if ($aluno['npt'] !== null && $aluno['npt'] > 0) $valores[] = $aluno['npt'];
                                                    $media_parcial = !empty($valores) ? round(array_sum($valores) / count($valores), 1) : null;
                                                    echo $media_parcial !== null ? number_format($media_parcial, 1) : '--';
                                                ?>
                                            </span>
                                        </td>
                                        <!-- Exame Oral -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][exame_oral]" 
                                                   value="<?php echo $aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 1) : ''; ?>"
                                                   class="form-control nota-input exame-oral-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>">
                                        </td>
                                        <!-- Exame Escrito -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][exame_escrita]" 
                                                   value="<?php echo $aluno['exame_escrita'] !== null ? number_format($aluno['exame_escrita'], 1) : ''; ?>"
                                                   class="form-control nota-input exame-escrita-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>">
                                        </td>
                                        <!-- Média Exame -->
                                        <td class="media-cell">
                                            <span id="media-exame-<?php echo $aluno['id']; ?>">
                                                <?php
                                                    $media_exame = null;
                                                    $vals_exame = [];
                                                    if ($aluno['exame_oral'] !== null && $aluno['exame_oral'] > 0) $vals_exame[] = $aluno['exame_oral'];
                                                    if ($aluno['exame_escrita'] !== null && $aluno['exame_escrita'] > 0) $vals_exame[] = $aluno['exame_escrita'];
                                                    $media_exame = !empty($vals_exame) ? round(array_sum($vals_exame) / count($vals_exame), 1) : null;
                                                    echo $media_exame !== null ? number_format($media_exame, 1) : '--';
                                                ?>
                                            </span>
                                        </td>
                                    <?php elseif ($is_exame_classe && $trimestre == 3): ?>
                                        <!-- MAC -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][mac]" 
                                                   value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : ''; ?>"
                                                   class="form-control nota-input mac-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   data-trimestre="<?php echo $trimestre; ?>"
                                                   data-exame="1">
                                        </td>
                                        <!-- NPT -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][npt]" 
                                                   value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : ''; ?>"
                                                   class="form-control nota-input npt-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>">
                                        </td>
                                        <!-- Média Parcial -->
                                        <td class="media-cell">
                                            <span id="media-parcial-<?php echo $aluno['id']; ?>">
                                                <?php 
                                                    $media_parcial = null;
                                                    $valores = [];
                                                    if ($aluno['mac'] !== null && $aluno['mac'] > 0) $valores[] = $aluno['mac'];
                                                    if ($aluno['npt'] !== null && $aluno['npt'] > 0) $valores[] = $aluno['npt'];
                                                    $media_parcial = !empty($valores) ? round(array_sum($valores) / count($valores), 1) : null;
                                                    echo $media_parcial !== null ? number_format($media_parcial, 1) : '--';
                                                ?>
                                            </span>
                                        </td>
                                        <!-- Exame Normal -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][exame_normal]" 
                                                   value="<?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1) : ''; ?>"
                                                   class="form-control nota-input exame-normal-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>">
                                        </td>
                                    <?php else: ?>
                                        <!-- MAC -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][mac]" 
                                                   value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1) : ''; ?>"
                                                   class="form-control nota-input mac-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>"
                                                   data-trimestre="<?php echo $trimestre; ?>">
                                        </td>
                                        <!-- NPT -->
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" 
                                                   name="notas[<?php echo $aluno['id']; ?>][npt]" 
                                                   value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1) : ''; ?>"
                                                   class="form-control nota-input npt-input"
                                                   data-aluno="<?php echo $aluno['id']; ?>">
                                        </td>
                                        <!-- Média Parcial -->
                                        <td class="media-cell">
                                            <span id="media-parcial-<?php echo $aluno['id']; ?>">
                                                <?php 
                                                    $media_parcial = null;
                                                    $valores = [];
                                                    if ($aluno['mac'] !== null && $aluno['mac'] > 0) $valores[] = $aluno['mac'];
                                                    if ($aluno['npt'] !== null && $aluno['npt'] > 0) $valores[] = $aluno['npt'];
                                                    $media_parcial = !empty($valores) ? round(array_sum($valores) / count($valores), 1) : null;
                                                    echo $media_parcial !== null ? number_format($media_parcial, 1) : '--';
                                                ?>
                                            </span>
                                        </td>
                                    <?php endif; ?>
                                    
                                    <!-- Média Final -->
                                    <td class="media-cell">
                                        <strong>
                                            <span id="media-final-<?php echo $aluno['id']; ?>" class="badge bg-<?php 
                                                echo $aluno['media_final'] >= 14 ? 'success' : ($aluno['media_final'] >= 10 ? 'warning text-dark' : 'danger');
                                            ?> p-2" style="font-size: 1rem;">
                                                <?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 1) : '--'; ?>
                                            </span>
                                        </strong>
                                    </td>
                                    
                                    <!-- Status -->
                                    <td>
                                        <span class="status-badge <?php echo $aluno['status_classe']; ?>">
                                            <i class="fas <?php echo $aluno['status_icone']; ?>"></i>
                                            <?php echo $aluno['status_texto']; ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <button type="button" class="btn btn-warning" onclick="preencherExemplo()">
                            <i class="fas fa-chart-line"></i> Preencher Dados de Exemplo
                        </button>
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-save"></i> Salvar Notas
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h4>Nenhum aluno encontrado</h4>
                <p>Não há alunos matriculados nesta turma para o ano letivo selecionado.</p>
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        <?php elseif ($status_filtro != 'todos' && !empty($alunos) && empty($alunos_filtrados)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-filter fa-3x text-muted mb-3"></i>
                <h4>Nenhum aluno encontrado com este filtro</h4>
                <p>Não há alunos com o status selecionado nesta turma/disciplina.</p>
                <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_filtro; ?>&order_by=<?php echo $order_by; ?>" class="btn btn-primary">
                    <i class="fas fa-times"></i> Limpar Filtro
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Menu toggle para mobile
            $('#menuToggle').click(function() {
                $('.sidebar').toggleClass('active');
                $('.main-content').toggleClass('active');
            });
            
            // Máscara para inputs de nota (permitir apenas números e vírgula)
            $('.nota-input').on('input', function() {
                let value = $(this).val();
                value = value.replace(/[^0-9,]/g, '');
                if (value === '') {
                    $(this).val('');
                } else {
                    value = value.replace(',', '.');
                    let num = parseFloat(value);
                    if (!isNaN(num)) {
                        if (num < 0) num = 0;
                        if (num > 20) num = 20;
                        $(this).val(num.toFixed(1).replace('.', ','));
                    }
                }
            });
            
            // Calcular médias em tempo real
            function calcularMedias(alunoId, isExameClasse, trimestre, isLinguagem) {
                let mac = parseFloat($(`input[name='notas[${alunoId}][mac]']`).val().replace(',', '.'));
                let npt = parseFloat($(`input[name='notas[${alunoId}][npt]']`).val().replace(',', '.'));
                
                // Calcular média parcial
                let valores = [];
                if (!isNaN(mac) && mac > 0) valores.push(mac);
                if (!isNaN(npt) && npt > 0) valores.push(npt);
                
                let mediaParcial = valores.length > 0 ? (valores.reduce((a, b) => a + b, 0) / valores.length) : null;
                
                if (mediaParcial !== null) {
                    $(`#media-parcial-${alunoId}`).text(mediaParcial.toFixed(1));
                } else {
                    $(`#media-parcial-${alunoId}`).text('--');
                }
                
                let mediaFinal = null;
                
                if (isExameClasse && trimestre == 3) {
                    if (isLinguagem) {
                        let exameOral = parseFloat($(`input[name='notas[${alunoId}][exame_oral]']`).val().replace(',', '.'));
                        let exameEscrita = parseFloat($(`input[name='notas[${alunoId}][exame_escrita]']`).val().replace(',', '.'));
                        
                        let valoresExame = [];
                        if (!isNaN(exameOral) && exameOral > 0) valoresExame.push(exameOral);
                        if (!isNaN(exameEscrita) && exameEscrita > 0) valoresExame.push(exameEscrita);
                        
                        let mediaExame = valoresExame.length > 0 ? (valoresExame.reduce((a, b) => a + b, 0) / valoresExame.length) : null;
                        
                        if (mediaExame !== null) {
                            $(`#media-exame-${alunoId}`).text(mediaExame.toFixed(1));
                        } else {
                            $(`#media-exame-${alunoId}`).text('--');
                        }
                        
                        if (mediaParcial !== null && mediaExame !== null) {
                            mediaFinal = (mediaParcial * 0.4) + (mediaExame * 0.6);
                        } else if (mediaParcial !== null) {
                            mediaFinal = mediaParcial;
                        } else if (mediaExame !== null) {
                            mediaFinal = mediaExame;
                        }
                    } else {
                        let exameNormal = parseFloat($(`input[name='notas[${alunoId}][exame_normal]']`).val().replace(',', '.'));
                        
                        if (mediaParcial !== null && !isNaN(exameNormal) && exameNormal > 0) {
                            mediaFinal = (mediaParcial * 0.4) + (exameNormal * 0.6);
                        } else if (mediaParcial !== null) {
                            mediaFinal = mediaParcial;
                        } else if (!isNaN(exameNormal) && exameNormal > 0) {
                            mediaFinal = exameNormal;
                        }
                    }
                } else {
                    mediaFinal = mediaParcial;
                }
                
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
                    } else {
                        $(`#media-final-${alunoId}`).closest('tr').find('.status-badge').removeClass('bg-success bg-warning bg-danger').addClass('bg-secondary').html('<i class="fas fa-minus-circle"></i> Sem nota');
                    }
                } else {
                    $(`#media-final-${alunoId}`).text('--');
                    $(`#media-final-${alunoId}`).closest('tr').find('.status-badge').removeClass('bg-success bg-warning bg-danger').addClass('bg-secondary').html('<i class="fas fa-minus-circle"></i> Sem nota');
                }
            }
            
            // Configuração das classes
            let isExameClasse = <?php echo $is_exame_classe ? 'true' : 'false'; ?>;
            let trimestre = <?php echo $trimestre; ?>;
            let isLinguagem = <?php echo $is_linguagem ? 'true' : 'false'; ?>;
            
            // Event listeners para atualização em tempo real
            $('.mac-input, .npt-input, .exame-normal-input, .exame-oral-input, .exame-escrita-input').on('input', function() {
                let alunoId = $(this).data('aluno');
                calcularMedias(alunoId, isExameClasse, trimestre, isLinguagem);
            });
        });
        
        function preencherExemplo() {
            // Função para preencher dados de exemplo (apenas para teste)
            $('.mac-input').each(function() {
                if ($(this).val() === '') {
                    $(this).val('15,0');
                }
            });
            $('.npt-input').each(function() {
                if ($(this).val() === '') {
                    $(this).val('14,0');
                }
            });
            $('.exame-normal-input').each(function() {
                if ($(this).val() === '') {
                    $(this).val('16,0');
                }
            });
            $('.exame-oral-input').each(function() {
                if ($(this).val() === '') {
                    $(this).val('15,0');
                }
            });
            $('.exame-escrita-input').each(function() {
                if ($(this).val() === '') {
                    $(this).val('16,0');
                }
            });
            
            // Trigger para recalcular médias
            $('.mac-input, .npt-input, .exame-normal-input, .exame-oral-input, .exame-escrita-input').trigger('input');
            
            alert('Dados de exemplo preenchidos! Clique em "Salvar Notas" para confirmar.');
        }
    </script>
</body>
</html>