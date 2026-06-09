<?php
// escola/relatorios/manipautas.php - Manipulação de Pautas (Notas) com seleção múltipla

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$professor_id = isset($_GET['professor_id']) ? (int)$_GET['professor_id'] : 0;
$tipo_pauta = isset($_GET['tipo_pauta']) ? $_GET['tipo_pauta'] : 'todas';
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;
$disciplinas_selecionadas = isset($_GET['disciplinas']) ? $_GET['disciplinas'] : [];
$exportar_todas = isset($_GET['exportar_todas']) ? (int)$_GET['exportar_todas'] : 0;

// Se veio por POST (checkbox), converter para array
if (isset($_POST['disciplinas_selecionadas'])) {
    $disciplinas_selecionadas = $_POST['disciplinas_selecionadas'];
    if (!empty($disciplinas_selecionadas)) {
        $disciplinas_param = implode(',', $disciplinas_selecionadas);
        header("Location: manipautas.php?professor_id=$professor_id&disciplinas=$disciplinas_param&tipo_pauta=$tipo_pauta&trimestre=$trimestre&ano_letivo=$ano_letivo_id");
        exit;
    }
}

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_letivos)) {
    $anos_letivos = [['id' => 1, 'ano' => date('Y')]];
}
if ($ano_letivo_id == 0) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR TURMAS
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PROFESSORES
// ============================================
$sql_professores = "SELECT id, nome FROM funcionarios WHERE escola_id = :escola_id AND status = 'ativo' AND tipo_funcionario = 'professor' ORDER BY nome";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([':escola_id' => $escola_id]);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

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

function calcularMediaFinal($mac, $npt, $exame_oral = null, $exame_escrita = null, $is_exame_classe = false, $is_linguagem = false, $trimestre = 1) {
    if ($is_exame_classe && $trimestre == 3) {
        if ($is_linguagem) {
            // Para línguas: MAC + Exame Oral + Exame Escrita (média dos 3)
            $valores = [];
            if ($mac !== null) $valores[] = $mac;
            if ($exame_oral !== null) $valores[] = $exame_oral;
            if ($exame_escrita !== null) $valores[] = $exame_escrita;
            return !empty($valores) ? array_sum($valores) / count($valores) : null;
        } else {
            // Para outras disciplinas: MAC + Exame Normal (média de 2)
            $valores = [];
            if ($mac !== null) $valores[] = $mac;
            if ($exame_escrita !== null) $valores[] = $exame_escrita;
            return !empty($valores) ? array_sum($valores) / count($valores) : null;
        }
    } else {
        // 1º, 2º trimestre ou 3º trimestre para classes normais: MAC + NPT
        $valores = [];
        if ($mac !== null) $valores[] = $mac;
        if ($npt !== null) $valores[] = $npt;
        return !empty($valores) ? array_sum($valores) / count($valores) : null;
    }
}

function getStatus($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'status-sem-nota'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'status-aprovado'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'status-exame'];
    return ['texto' => 'Reprovado', 'classe' => 'status-reprovado'];
}

// ============================================
// BUSCAR DISCIPLINAS POR TURMA
// ============================================
$disciplinas_turma = [];
if ($turma_id > 0) {
    $sql_disc_turma = "SELECT DISTINCT d.id, d.nome, d.codigo
                       FROM disciplinas d
                       INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                       WHERE pdt.turma_id = :turma_id AND d.escola_id = :escola_id
                       ORDER BY d.nome";
    $stmt_disc_turma = $conn->prepare($sql_disc_turma);
    $stmt_disc_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
    $disciplinas_turma = $stmt_disc_turma->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// BUSCAR DISCIPLINAS POR PROFESSOR
// ============================================
$disciplinas_professor = [];
if ($professor_id > 0) {
    $sql_disc_prof = "SELECT DISTINCT d.id, d.nome, d.codigo, pdt.turma_id, t.nome as turma_nome, t.ano as turma_ano
                      FROM disciplinas d
                      INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                      INNER JOIN turmas t ON t.id = pdt.turma_id
                      WHERE pdt.professor_id = :professor_id AND d.escola_id = :escola_id
                      ORDER BY t.ano, t.nome, d.nome";
    $stmt_disc_prof = $conn->prepare($sql_disc_prof);
    $stmt_disc_prof->execute([':professor_id' => $professor_id, ':escola_id' => $escola_id]);
    $disciplinas_professor = $stmt_disc_prof->fetchAll(PDO::FETCH_ASSOC);
}

// Converter disciplinas selecionadas para array se for string
if (is_string($disciplinas_selecionadas) && !empty($disciplinas_selecionadas)) {
    $disciplinas_selecionadas = explode(',', $disciplinas_selecionadas);
}

// ============================================
// BUSCAR INFORMAÇÕES DA TURMA E DISCIPLINA ATUAL
// ============================================
$turma_atual_info = null;
if ($turma_id > 0) {
    foreach ($turmas as $t) {
        if ($t['id'] == $turma_id) {
            $turma_atual_info = $t;
            break;
        }
    }
}

$disciplina_atual_info = null;
$disciplina_atual_nome = '';
if ($disciplina_id > 0) {
    $sql_disc_atual = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc_atual = $conn->prepare($sql_disc_atual);
    $stmt_disc_atual->execute([':id' => $disciplina_id]);
    $disciplina_atual_info = $stmt_disc_atual->fetch(PDO::FETCH_ASSOC);
    $disciplina_atual_nome = $disciplina_atual_info['nome'] ?? 'Disciplina';
}

$is_exame_classe = $turma_atual_info ? isClasseExame($turma_atual_info['ano']) : false;
$is_linguagem = $disciplina_atual_info ? isLinguagem($disciplina_atual_info['nome']) : false;

// ============================================
// BUSCAR ALUNOS E NOTAS
// ============================================
$alunos_notas = [];
$estatisticas = [
    'total_alunos' => 0,
    'total_aprovados' => 0,
    'total_reprovados' => 0,
    'total_exame' => 0,
    'media_geral' => 0,
    'maior_nota' => 0,
    'menor_nota' => 0
];

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar alunos da turma
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos_base = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    $soma_notas = 0;
    $total_com_nota = 0;
    
    foreach ($alunos_base as $aluno) {
        $sql_nota = "SELECT id, mac, npt, exame_normal, exame_oral, exame_escrito, media_final, data_lancamento
                     FROM notas 
                     WHERE estudante_id = :estudante_id 
                     AND disciplina_id = :disciplina_id 
                     AND bimestre = :trimestre
                     AND ano_letivo_id = :ano_letivo_id";
        $stmt_nota = $conn->prepare($sql_nota);
        $stmt_nota->execute([
            ':estudante_id' => $aluno['id'],
            ':disciplina_id' => $disciplina_id,
            ':trimestre' => $trimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $nota_data = $stmt_nota->fetch(PDO::FETCH_ASSOC);
        
        $mac = $nota_data ? (float)$nota_data['mac'] : null;
        $npt = $nota_data ? (float)$nota_data['npt'] : null;
        $exame_normal = $nota_data ? (float)$nota_data['exame_normal'] : null;
        $exame_oral = $nota_data ? (float)$nota_data['exame_oral'] : null;
        $exame_escrita = $nota_data ? (float)$nota_data['exame_escrito'] : null;
        $media_final = $nota_data ? (float)$nota_data['media_final'] : null;
        $nota_id = $nota_data ? $nota_data['id'] : null;
        
        // Calcular média se necessário
        if ($media_final === null) {
            if ($is_exame_classe && $trimestre == 3) {
                if ($is_linguagem) {
                    $media_final = calcularMediaFinal($mac, null, $exame_oral, $exame_escrita, true, true, $trimestre);
                } else {
                    $media_final = calcularMediaFinal($mac, null, null, $exame_normal, true, false, $trimestre);
                }
            } else {
                $media_final = calcularMediaFinal($mac, $npt, null, null, false, false, $trimestre);
            }
        }
        
        $status_info = getStatus($media_final);
        
        if ($media_final !== null && $media_final > 0) {
            $soma_notas += $media_final;
            $total_com_nota++;
            
            if ($media_final >= 14) $estatisticas['total_aprovados']++;
            elseif ($media_final >= 10) $estatisticas['total_exame']++;
            else $estatisticas['total_reprovados']++;
            
            if ($media_final > $estatisticas['maior_nota']) $estatisticas['maior_nota'] = $media_final;
            if ($estatisticas['menor_nota'] == 0 || $media_final < $estatisticas['menor_nota']) $estatisticas['menor_nota'] = $media_final;
        }
        
        $alunos_notas[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'],
            'mac' => $mac,
            'npt' => $npt,
            'exame_normal' => $exame_normal,
            'exame_oral' => $exame_oral,
            'exame_escrito' => $exame_escrita,
            'media_final' => $media_final,
            'nota_id' => $nota_id,
            'status_texto' => $status_info['texto'],
            'status_classe' => $status_info['classe']
        ];
    }
    
    $estatisticas['total_alunos'] = count($alunos_notas);
    $estatisticas['media_geral'] = $total_com_nota > 0 ? round($soma_notas / $total_com_nota, 2) : 0;
}

// ============================================
// PROCESSAR AÇÕES EM MASSA
// ============================================
$mensagem_sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao_massa'])) {
    $acao_massa = $_POST['acao_massa'];
    $disciplina_id_massa = (int)$_POST['disciplina_id_massa'];
    $turma_id_massa = (int)$_POST['turma_id_massa'];
    $trimestre_massa = (int)$_POST['trimestre_massa'];
    $mac_padrao = isset($_POST['mac_padrao']) && $_POST['mac_padrao'] !== '' ? (float)$_POST['mac_padrao'] : null;
    $npt_padrao = isset($_POST['npt_padrao']) && $_POST['npt_padrao'] !== '' ? (float)$_POST['npt_padrao'] : null;
    $exame_normal_padrao = isset($_POST['exame_normal_padrao']) && $_POST['exame_normal_padrao'] !== '' ? (float)$_POST['exame_normal_padrao'] : null;
    $exame_oral_padrao = isset($_POST['exame_oral_padrao']) && $_POST['exame_oral_padrao'] !== '' ? (float)$_POST['exame_oral_padrao'] : null;
    $exame_escrita_padrao = isset($_POST['exame_escrita_padrao']) && $_POST['exame_escrita_padrao'] !== '' ? (float)$_POST['exame_escrita_padrao'] : null;
    
    if ($acao_massa == 'lancar_padrao') {
        $sql_alunos_massa = "SELECT e.id FROM estudantes e
                             INNER JOIN matriculas m ON m.estudante_id = e.id
                             WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id";
        $stmt_alunos_massa = $conn->prepare($sql_alunos_massa);
        $stmt_alunos_massa->execute([
            ':turma_id' => $turma_id_massa,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $alunos_massa = $stmt_alunos_massa->fetchAll(PDO::FETCH_ASSOC);
        
        $contador = 0;
        $is_exame_classe_massa = false;
        $is_linguagem_massa = false;
        
        // Buscar informações da turma e disciplina para cálculo
        $sql_turma_info = "SELECT ano FROM turmas WHERE id = :id";
        $stmt_turma_info = $conn->prepare($sql_turma_info);
        $stmt_turma_info->execute([':id' => $turma_id_massa]);
        $turma_info_massa = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
        $is_exame_classe_massa = $turma_info_massa ? isClasseExame($turma_info_massa['ano']) : false;
        
        $sql_disc_info = "SELECT nome FROM disciplinas WHERE id = :id";
        $stmt_disc_info = $conn->prepare($sql_disc_info);
        $stmt_disc_info->execute([':id' => $disciplina_id_massa]);
        $disc_info_massa = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
        $is_linguagem_massa = $disc_info_massa ? isLinguagem($disc_info_massa['nome']) : false;
        
        foreach ($alunos_massa as $aluno) {
            $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND trimestre = :trimestre AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':estudante_id' => $aluno['id'],
                ':disciplina_id' => $disciplina_id_massa,
                ':trimestre' => $trimestre_massa,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($is_exame_classe_massa && $trimestre_massa == 3) {
                if ($is_linguagem_massa) {
                    $media_final = calcularMediaFinal($mac_padrao, null, $exame_oral_padrao, $exame_escrita_padrao, true, true, $trimestre_massa);
                } else {
                    $media_final = calcularMediaFinal($mac_padrao, null, null, $exame_normal_padrao, true, false, $trimestre_massa);
                }
            } else {
                $media_final = calcularMediaFinal($mac_padrao, $npt_padrao, null, null, false, false, $trimestre_massa);
            }
            
            if ($existe) {
                $sql = "UPDATE notas SET mac = :mac, npt = :npt, exame_normal = :exame_normal, exame_oral = :exame_oral, exame_escrito = :exame_escrito, media_final = :media_final, data_lancamento = NOW() WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':mac' => $mac_padrao,
                    ':npt' => $npt_padrao,
                    ':exame_normal' => $exame_normal_padrao,
                    ':exame_oral' => $exame_oral_padrao,
                    ':exame_escrito' => $exame_escrita_padrao,
                    ':media_final' => $media_final,
                    ':id' => $existe['id']
                ]);
            } else {
                $sql = "INSERT INTO notas (estudante_id, disciplina_id, mac, npt, exame_normal, exame_oral, exame_escrito, media_final, bimestre, ano_letivo_id, data_lancamento) 
                        VALUES (:estudante_id, :disciplina_id, :mac, :npt, :exame_normal, :exame_oral, :exame_escrito, :media_final, :trimestre, :ano_letivo_id, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':estudante_id' => $aluno['id'],
                    ':disciplina_id' => $disciplina_id_massa,
                    ':mac' => $mac_padrao,
                    ':npt' => $npt_padrao,
                    ':exame_normal' => $exame_normal_padrao,
                    ':exame_oral' => $exame_oral_padrao,
                    ':exame_escrito' => $exame_escrita_padrao,
                    ':media_final' => $media_final,
                    ':trimestre' => $trimestre_massa,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
            }
            $contador++;
        }
        $mensagem_sucesso = "Notas lançadas para $contador alunos!";
        header("Location: manipautas.php?turma_id=$turma_id_massa&disciplina_id=$disciplina_id_massa&trimestre=$trimestre_massa&tipo_pauta=$tipo_pauta&ano_letivo=$ano_letivo_id");
        exit;
    }
    
    if ($acao_massa == 'limpar_tudo') {
        $sql = "DELETE FROM notas 
                WHERE disciplina_id = :disciplina_id 
                AND bimestre = :trimestre 
                AND ano_letivo_id = :ano_letivo_id
                AND estudante_id IN (SELECT e.id FROM estudantes e
                                 INNER JOIN matriculas m ON m.estudante_id = e.id
                                 WHERE m.turma_id = :turma_id)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':disciplina_id' => $disciplina_id_massa,
            ':trimestre' => $trimestre_massa,
            ':ano_letivo_id' => $ano_letivo_id,
            ':turma_id' => $turma_id_massa
        ]);
        $mensagem_sucesso = "Todas as notas foram removidas!";
        header("Location: manipautas.php?turma_id=$turma_id_massa&disciplina_id=$disciplina_id_massa&trimestre=$trimestre_massa&tipo_pauta=$tipo_pauta&ano_letivo=$ano_letivo_id");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manipulação de Pautas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .filter-bar { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .stat-card { background: white; border-radius: 10px; padding: 15px; text-align: center; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 15px; }
        .stat-number { font-size: 24px; font-weight: bold; }
        .nota-input { width: 90px; text-align: center; }
        .nota-input-small { width: 70px; text-align: center; }
        .status-aprovado { color: #28a745; font-weight: bold; }
        .status-exame { color: #ffc107; font-weight: bold; }
        .status-reprovado { color: #dc3545; font-weight: bold; }
        .status-sem-nota { color: #6c757d; }
        @media print { .no-print { display: none !important; } .sidebar { display: none; } .main-content { margin-left: 0; padding: 10px; } }
        .help-modal-step { background: #f8f9fa; border-left: 4px solid #006B3E; padding: 15px; margin-bottom: 15px; border-radius: 5px; }
        .step-number { background: #006B3E; color: white; width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; margin-right: 10px; }
        .checkbox-card { border: 1px solid #dee2e6; border-radius: 8px; padding: 10px; margin-bottom: 10px; transition: all 0.3s; cursor: pointer; }
        .checkbox-card:hover { background: #f8f9fa; border-color: #006B3E; }
        .checkbox-card.selected { background: #e8f5e9; border-color: #006B3E; }
        .disciplina-checkbox { margin-right: 10px; transform: scale(1.2); }
        .btn-selecionar-todos { background: #006B3E; color: white; border: none; padding: 5px 15px; border-radius: 5px; margin-bottom: 15px; }
        .btn-selecionar-todos:hover { background: #004d2d; }
        .btn-export { border-radius: 25px; padding: 10px 24px; margin: 5px; font-weight: 500; transition: all 0.2s; }
        .btn-export:hover { transform: translateY(-2px); }
        .btn-pdf { background-color: #dc3545; color: white; border: none; }
        .btn-excel { background-color: #28a745; color: white; border: none; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-book-open"></i> Manipulação de Pautas</h2>
            <div class="no-print">
                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                    <i class="fas fa-question-circle"></i> Ajuda / Tutorial
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end" id="formFiltros">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_id == $ano['id'] ? 'selected' : ''; ?>><?php echo $ano['ano']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                        <option value="0">Selecione uma turma...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Tipo de Pauta</label>
                    <select name="tipo_pauta" class="form-select" onchange="this.form.submit()">
                        <option value="todas" <?php echo $tipo_pauta == 'todas' ? 'selected' : ''; ?>>Todas (com/sem nota)</option>
                        <option value="com_nota" <?php echo $tipo_pauta == 'com_nota' ? 'selected' : ''; ?>>Apenas com nota</option>
                        <option value="sem_nota" <?php echo $tipo_pauta == 'sem_nota' ? 'selected' : ''; ?>>Apenas sem nota</option>
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
        
        <!-- Seleção de Disciplina (por Turma) -->
        <?php if ($turma_id > 0): ?>
        <div class="filter-bar no-print">
            <div class="row">
                <div class="col-md-12">
                    <label class="form-label fw-bold"><i class="fas fa-book"></i> Selecione a Disciplina</label>
                    <div class="row">
                        <?php foreach ($disciplinas_turma as $disc): ?>
                        <div class="col-md-3 col-sm-6 mb-2">
                            <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disc['id']; ?>&tipo_pauta=<?php echo $tipo_pauta; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>" 
                               class="btn btn-outline-primary w-100 <?php echo $disciplina_id == $disc['id'] ? 'active' : ''; ?>">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($disc['nome']); ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <!-- Seleção por Professor (alternativa com checkboxes) - VERSÃO CORRIGIDA -->
<?php if ($turma_id == 0): ?>
<div class="filter-bar no-print">
    <div class="row">
        <div class="col-md-12">
            <label class="form-label fw-bold"><i class="fas fa-chalkboard-user"></i> Selecione por Professor</label>
            <select name="professor_id" class="form-select" id="professor_id" onchange="carregarDisciplinasProfessor()">
                <option value="0">Selecione um professor...</option>
                <?php foreach ($professores as $prof): ?>
                <option value="<?php echo $prof['id']; ?>" <?php echo $professor_id == $prof['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($prof['nome']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <?php if ($professor_id > 0 && !empty($disciplinas_professor)): ?>
    <div class="row mt-3">
        <div class="col-md-12">
            <form method="POST" id="formDisciplinas">
                <label class="form-label fw-bold"><i class="fas fa-list"></i> Disciplinas do Professor (selecione uma ou mais)</label>
                
                <div class="mb-3">
                    <button type="button" class="btn-selecionar-todos" onclick="selecionarTodas(true)">
                        <i class="fas fa-check-square"></i> Selecionar Todas
                    </button>
                    <button type="button" class="btn-selecionar-todos" onclick="selecionarTodas(false)" style="background: #6c757d;">
                        <i class="fas fa-square"></i> Desmarcar Todas
                    </button>
                </div>
                
                <div class="row" id="disciplinasContainer">
                    <?php foreach ($disciplinas_professor as $disc): 
                        $checked = in_array($disc['id'], $disciplinas_selecionadas) ? 'checked' : '';
                    ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="checkbox-card" onclick="toggleCheckbox(<?php echo $disc['id']; ?>)">
                            <input type="checkbox" 
                                   name="disciplinas_selecionadas[]" 
                                   id="disc_<?php echo $disc['id']; ?>" 
                                   value="<?php echo $disc['id']; ?>"
                                   data-turma-id="<?php echo $disc['turma_id']; ?>"
                                   data-turma-nome="<?php echo $disc['turma_ano'] . 'ª ' . $disc['turma_nome']; ?>"
                                   data-disciplina-nome="<?php echo htmlspecialchars($disc['nome']); ?>"
                                   class="disciplina-checkbox"
                                   <?php echo $checked; ?>
                                   onclick="event.stopPropagation()">
                            <label for="disc_<?php echo $disc['id']; ?>" style="cursor: pointer;">
                                <strong><?php echo htmlspecialchars($disc['nome']); ?></strong><br>
                                <small class="text-muted">
                                    Turma: <?php echo $disc['turma_ano'] . 'ª ' . htmlspecialchars($disc['turma_nome']); ?>
                                </small>
                            </label>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-eye"></i> Visualizar Disciplinas Selecionadas
                    </button>
                    <span class="text-muted ms-3" id="contadorSelecionadas">
                        <?php echo count($disciplinas_selecionadas); ?> disciplina(s) selecionada(s)
                    </span>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Botões de Exportação (corrigidos com turma_id por disciplina) -->
<?php if ((!empty($disciplinas_selecionadas) && $professor_id > 0) || ($disciplina_id > 0 && $turma_id > 0)): ?>
<div class="row mb-4 no-print">
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="mb-3"><i class="fas fa-download"></i> Exportar Pautas</h6>
                <div class="d-flex justify-content-center flex-wrap">
                    <?php if (!empty($disciplinas_selecionadas) && $professor_id > 0): 
                        // Construir parâmetros com disciplina e turma
                        $params_disciplinas = [];
                        foreach ($disciplinas_professor as $disc) {
                            if (in_array($disc['id'], $disciplinas_selecionadas)) {
                                $params_disciplinas[] = $disc['id'] . '_' . $disc['turma_id'];
                            }
                        }
                        $disciplinas_param = implode(',', $params_disciplinas);
                    ?>
                        <a href="gerar_pdf_multiplo.php?professor_id=<?php echo $professor_id; ?>&disciplinas=<?php echo urlencode($disciplinas_param); ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>" 
                           class="btn-export btn-pdf" target="_blank">
                            <i class="fas fa-file-pdf"></i> Exportar PDF (<?php echo count($disciplinas_selecionadas); ?> disciplinas)
                        </a>
                        <a href="gerar_excel_multiplo.php?professor_id=<?php echo $professor_id; ?>&disciplinas=<?php echo urlencode($disciplinas_param); ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>" 
                           class="btn-export btn-excel">
                            <i class="fas fa-file-excel"></i> Exportar Excel (<?php echo count($disciplinas_selecionadas); ?> disciplinas)
                        </a>
                    <?php elseif ($disciplina_id > 0 && $turma_id > 0): ?>
                        <button type="button" class="btn-export btn-pdf" onclick="exportarPDF()">
                            <i class="fas fa-file-pdf"></i> Exportar PDF
                        </button>
                        <button type="button" class="btn-export btn-excel" onclick="exportarExcel()">
                            <i class="fas fa-file-excel"></i> Exportar Excel
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
        <!-- Tabela de Pauta (quando selecionada uma disciplina específica) -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos_notas)): ?>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            <strong>Disciplina Atual:</strong> <?php echo htmlspecialchars($disciplina_atual_nome); ?> | 
            <strong>Turma:</strong> <?php echo $turma_atual_info['ano'] . 'ª - ' . $turma_atual_info['nome']; ?> | 
            <strong>Trimestre:</strong> <?php echo $trimestre; ?>º |
            <strong>Tipo de Avaliação:</strong> 
            <?php 
            if ($is_exame_classe && $trimestre == 3) {
                if ($is_linguagem) {
                    echo "MAC + Exame Oral + Exame Escrita";
                } else {
                    echo "MAC + Exame Normal";
                }
            } else {
                echo "MAC + NPT";
            }
            ?>
        </div>
        
        <div class="row mb-4">
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-users fa-2x text-primary mb-2"></i><div class="stat-number"><?php echo $estatisticas['total_alunos']; ?></div><div class="text-muted small">Total Alunos</div></div></div>
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-check-circle fa-2x text-success mb-2"></i><div class="stat-number text-success"><?php echo $estatisticas['total_aprovados']; ?></div><div class="text-muted small">Aprovados (≥14)</div></div></div>
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-chalkboard fa-2x text-warning mb-2"></i><div class="stat-number text-warning"><?php echo $estatisticas['total_exame']; ?></div><div class="text-muted small">Exame (10-13)</div></div></div>
            <div class="col-md-3 col-6"><div class="stat-card"><i class="fas fa-times-circle fa-2x text-danger mb-2"></i><div class="stat-number text-danger"><?php echo $estatisticas['total_reprovados']; ?></div><div class="text-muted small">Reprovados (<10)</div></div></div>
        </div>
        
        <div class="row mb-4 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white"><h6 class="mb-0"><i class="fas fa-tasks"></i> Ações em Massa</h6></div>
                    <div class="card-body">
                        <form method="POST" class="row align-items-end" onsubmit="return confirm('Tem certeza que deseja lançar as notas para todos os alunos?')">
                            <input type="hidden" name="acao_massa" value="lancar_padrao">
                            <input type="hidden" name="disciplina_id_massa" value="<?php echo $disciplina_id; ?>">
                            <input type="hidden" name="turma_id_massa" value="<?php echo $turma_id; ?>">
                            <input type="hidden" name="trimestre_massa" value="<?php echo $trimestre; ?>">
                            
                            <div class="col-md-2">
                                <label class="form-label">MAC (0-20)</label>
                                <input type="number" name="mac_padrao" class="form-control" step="0.5" min="0" max="20" placeholder="MAC">
                            </div>
                            
                            <?php if (!($is_exame_classe && $trimestre == 3)): ?>
                            <div class="col-md-2">
                                <label class="form-label">NPT (0-20)</label>
                                <input type="number" name="npt_padrao" class="form-control" step="0.5" min="0" max="20" placeholder="NPT">
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($is_exame_classe && $trimestre == 3): ?>
                                <?php if ($is_linguagem): ?>
                                <div class="col-md-2">
                                    <label class="form-label">Exame Oral (0-20)</label>
                                    <input type="number" name="exame_oral_padrao" class="form-control" step="0.5" min="0" max="20" placeholder="Oral">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Exame Escrita (0-20)</label>
                                    <input type="number" name="exame_escrita_padrao" class="form-control" step="0.5" min="0" max="20" placeholder="Escrita">
                                </div>
                                <?php else: ?>
                                <div class="col-md-2">
                                    <label class="form-label">Exame Normal (0-20)</label>
                                    <input type="number" name="exame_normal_padrao" class="form-control" step="0.5" min="0" max="20" placeholder="Exame">
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">Lançar para Todos</button>
                            </div>
                            <div class="col-md-2">
                                <form method="POST" onsubmit="return confirm('ATENÇÃO: Remover todas as notas?')">
                                    <input type="hidden" name="acao_massa" value="limpar_tudo">
                                    <input type="hidden" name="disciplina_id_massa" value="<?php echo $disciplina_id; ?>">
                                    <input type="hidden" name="turma_id_massa" value="<?php echo $turma_id; ?>">
                                    <input type="hidden" name="trimestre_massa" value="<?php echo $trimestre; ?>">
                                    <button type="submit" class="btn btn-danger w-100">Limpar Todas</button>
                                </form>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0"><i class="fas fa-table"></i> Pauta de Notas - <?php echo $trimestre; ?>º Trimestre - <strong>Disciplina Atual:</strong> <?php echo htmlspecialchars($disciplina_atual_nome); ?> - <strong>Turma:</strong> <?php echo $turma_atual_info['ano'] . 'ª - ' . $turma_atual_info['nome']; ?></h5>
            </div> 
               <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaNotas">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="8%">Matrícula</th>
                                <th width="20%">Aluno</th>
                                <th width="5%">Gênero</th>
                                <th width="8%">MAC</th>
                                <?php if (!($is_exame_classe && $trimestre == 3)): ?>
                                <th width="8%">NPT</th>
                                <?php endif; ?>
                                <?php if ($is_exame_classe && $trimestre == 3): ?>
                                    <?php if ($is_linguagem): ?>
                                    <th width="8%">Exame Oral</th>
                                    <th width="8%">Exame Escrita</th>
                                    <?php else: ?>
                                    <th width="8%">Exame Normal</th>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <th width="8%">Média Final</th>
                                <th width="10%">Status</th>
                                <th width="10%" class="no-print">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos_notas as $index => $aluno): ?>
                            <tr id="row-<?php echo $aluno['id']; ?>">
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <td class="text-center"><?php echo ($aluno['genero'] == 'masculino') ? '<i class="fas fa-mars text-primary"></i> M' : '<i class="fas fa-venus text-danger"></i> F'; ?></td>
                                <td><input type="number" class="form-control nota-input-small" id="mac-<?php echo $aluno['id']; ?>" value="<?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 2, '.', '') : ''; ?>" step="0.5" min="0" max="20" placeholder="MAC" onchange="salvarNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>)"></td>>
                                <?php if (!($is_exame_classe && $trimestre == 3)): ?>
                                <td><input type="number" class="form-control nota-input-small" id="npt-<?php echo $aluno['id']; ?>" value="<?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 2, '.', '') : ''; ?>" step="0.5" min="0" max="20" placeholder="NPT" onchange="salvarNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>)"></td>>
                                <?php endif; ?>
                                <?php if ($is_exame_classe && $trimestre == 3): ?>
                                    <?php if ($is_linguagem): ?>
                                    <td><input type="number" class="form-control nota-input-small" id="exame_oral-<?php echo $aluno['id']; ?>" value="<?php echo $aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 2, '.', '') : ''; ?>" step="0.5" min="0" max="20" placeholder="Oral" onchange="salvarNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>)"></td>>
                                    <td><input type="number" class="form-control nota-input-small" id="exame_escrito-<?php echo $aluno['id']; ?>" value="<?php echo $aluno['exame_escrito'] !== null ? number_format($aluno['exame_escrito'], 2, '.', '') : ''; ?>" step="0.5" min="0" max="20" placeholder="Escrita" onchange="salvarNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>)"></td>>
                                    <?php else: ?>
                                    <td><input type="number" class="form-control nota-input-small" id="exame_normal-<?php echo $aluno['id']; ?>" value="<?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 2, '.', '') : ''; ?>" step="0.5" min="0" max="20" placeholder="Exame" onchange="salvarNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>)"></td>>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <td class="fw-bold text-center" id="media-<?php echo $aluno['id']; ?>"><?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 2, ',', '.') : '---'; ?></td>
                                <td class="<?php echo $aluno['status_classe']; ?> text-center" id="status-<?php echo $aluno['id']; ?>"><?php echo $aluno['status_texto']; ?></td>
                                <td class="no-print text-center">
                                    <button class="btn btn-sm btn-danger" onclick="limparNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>, <?php echo $aluno['nota_id'] ?: 0; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="<?php 
                                    $colspan = 5; // #, Matrícula, Aluno, Gênero, MAC
                                    if (!($is_exame_classe && $trimestre == 3)) $colspan++;
                                    if ($is_exame_classe && $trimestre == 3) {
                                        if ($is_linguagem) $colspan += 2;
                                        else $colspan++;
                                    }
                                    echo $colspan;
                                ?>" class="text-end fw-bold">Média Geral:</td>
                                <td colspan="2" class="fw-bold"><?php echo number_format($estatisticas['media_geral'], 2, ',', '.'); ?> valores</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos_notas)): ?>
            <div class="alert alert-warning text-center">Nenhum aluno encontrado nesta turma.</div>
        <?php elseif ($turma_id > 0 && $disciplina_id == 0): ?>
            <div class="alert alert-info text-center">Selecione uma disciplina para visualizar a pauta.</div>
        <?php elseif ($turma_id == 0 && $professor_id == 0): ?>
            <div class="alert alert-secondary text-center">Selecione uma turma ou professor para começar.</div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade no-print" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Tutorial - Manipulação de Pautas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info"><strong>O que é esta página?</strong><br>Aqui você pode lançar, editar e visualizar notas dos alunos.</div>
                    <h6 class="mt-4 mb-3">Passo a Passo</h6>
                    <div class="help-modal-step"><span class="step-number">1</span><strong>Selecione o Ano Letivo</strong><p class="mt-2 mb-0 text-muted">Escolha o ano letivo desejado.</p></div>
                    <div class="help-modal-step"><span class="step-number">2</span><strong>Duas formas de trabalhar:</strong><p class="mt-2 mb-0 text-muted"><strong>Opção A - Por Turma:</strong> Selecione uma turma e depois a disciplina.<br><strong>Opção B - Por Professor:</strong> Selecione um professor e marque as disciplinas desejadas.</p></div>
                    <div class="help-modal-step"><span class="step-number">3</span><strong>Tipos de Avaliação:</strong><p class="mt-2 mb-0 text-muted"><strong>1º e 2º Trimestre:</strong> MAC + NPT (média dos 2)<br><strong>3º Trimestre (6º,9º,12º):</strong> MAC + Exame Normal (média dos 2)<br><strong>3º Trimestre (Línguas - 6º,9º,12º):</strong> MAC + Exame Oral + Exame Escrita (média dos 3)<br><strong>3º Trimestre (outras classes):</strong> MAC + NPT (média dos 2)</p></div>
                    <div class="help-modal-step"><span class="step-number">4</span><strong>Exportação:</strong><p class="mt-2 mb-0 text-muted">Após selecionar disciplinas (via professor), os botões de exportação PDF e Excel serão ativados para todas as disciplinas selecionadas.</p></div>
                    <div class="alert alert-success mt-3"><i class="fas fa-lightbulb"></i> <strong>Dica:</strong> As notas são salvas automaticamente!</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $('#tabelaNotas').DataTable({ language: { url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json' }, order: [[2, 'asc']], pageLength: 25 });
        
        function salvarNota(alunoId, disciplinaId, trimestre, anoLetivoId) {
            var mac = $('#mac-' + alunoId).val();
            var npt = $('#npt-' + alunoId).val();
            var exameNormal = $('#exame_normal-' + alunoId).val();
            var exameOral = $('#exame_oral-' + alunoId).val();
            var exameEscrita = $('#exame_escrita-' + alunoId).val();
            
            $.ajax({
                url: 'ajax_salvar_nota.php',
                method: 'POST',
                data: {
                    estudante_id: alunoId,
                    disciplina_id: disciplinaId,
                    mac: mac,
                    npt: npt,
                    exame_normal: exameNormal,
                    exame_oral: exameOral,
                    exame_escrita: exameEscrita,
                    trimestre: trimestre,
                    ano_letivo_id: anoLetivoId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#media-' + alunoId).text(response.media_formatada);
                        $('#status-' + alunoId).text(response.status_text).removeClass('status-aprovado status-exame status-reprovado status-sem-nota').addClass(response.status_class);
                        showToast('Nota salva com sucesso!', 'success');
                    } else {
                        showToast(response.message || 'Erro ao salvar nota', 'error');
                    }
                },
                error: function() { showToast('Erro de conexão', 'error'); }
            });
        }
        
        function limparNota(alunoId, disciplinaId, trimestre, anoLetivoId, notaId) {
            if (!confirm('Tem certeza que deseja remover esta nota?')) return;
            $.ajax({
                url: 'ajax_limpar_nota.php',
                method: 'POST',
                data: { estudante_id: alunoId, disciplina_id: disciplinaId, trimestre: trimestre, ano_letivo_id: anoLetivoId, nota_id: notaId },
                success: function(response) { 
                    if(response.success) location.reload();
                    else showToast(response.message || 'Erro ao remover nota', 'error');
                },
                error: function() { showToast('Erro de conexão', 'error'); }
            });
        }
        
        function showToast(message, type) {
            var toastHtml = `<div class="toast align-items-center text-white bg-${type == 'success' ? 'success' : 'danger'} border-0 position-fixed bottom-0 end-0 m-3"><div class="d-flex"><div class="toast-body">${message}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>`;
            $('body').append(toastHtml);
            var toast = new bootstrap.Toast($('.toast').last());
            toast.show();
            setTimeout(function() { $('.toast').last().remove(); }, 3000);
        }
        
        function carregarDisciplinasProfessor() {
            var professorId = $('#professor_id').val();
            if (professorId > 0) window.location.href = 'manipautas.php?professor_id=' + professorId + '&ano_letivo=<?php echo $ano_letivo_id; ?>';
        }
        
        function selecionarTodas(selecionar) { $('.disciplina-checkbox').prop('checked', selecionar); atualizarContador(); }
        function atualizarContador() { $('#contadorSelecionadas').text($('.disciplina-checkbox:checked').length + ' disciplina(s) selecionada(s)'); }
        function toggleCheckbox(id) { var cb = $('#disc_' + id); cb.prop('checked', !cb.prop('checked')); atualizarContador(); }
        function exportarPDF() { window.location.href = 'gerar_pdf_pauta.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>'; }
        function exportarExcel() { window.location.href = 'exportar_excel_pauta.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>'; }
        
        $(document).on('change', '.disciplina-checkbox', function() { atualizarContador(); });
        atualizarContador();
    </script>
</body>
</html>