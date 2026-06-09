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

// Se veio por POST (checkbox), converter para array
if (isset($_POST['disciplinas_selecionadas'])) {
    $disciplinas_selecionadas = $_POST['disciplinas_selecionadas'];
    if (!empty($disciplinas_selecionadas)) {
        // Redirecionar com as disciplinas selecionadas
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
$sql_professores = "SELECT id, nome FROM funcionarios WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([':escola_id' => $escola_id]);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

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
// BUSCAR DISCIPLINAS POR PROFESSOR (com checkbox)
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
// BUSCAR ALUNOS E NOTAS (para múltiplas disciplinas)
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

$disciplina_atual_nome = '';

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar nome da disciplina
    $sql_disc_nome = "SELECT nome FROM disciplinas WHERE id = :id";
    $stmt_disc_nome = $conn->prepare($sql_disc_nome);
    $stmt_disc_nome->execute([':id' => $disciplina_id]);
    $disc_nome = $stmt_disc_nome->fetch(PDO::FETCH_ASSOC);
    $disciplina_atual_nome = $disc_nome['nome'] ?? 'Disciplina';
    
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
    
    // Buscar notas dos alunos
    $soma_notas = 0;
    $total_com_nota = 0;
    
    foreach ($alunos_base as $aluno) {
        // Buscar nota do aluno
        $sql_nota = "SELECT id, media_final, status, data_lancamento
                     FROM notas 
                     WHERE estudante_id = :estudante_id 
                     AND disciplina_id = :disciplina_id 
                     AND trimestre = :trimestre
                     AND ano_letivo_id = :ano_letivo_id";
        $stmt_nota = $conn->prepare($sql_nota);
        $stmt_nota->execute([
            ':estudante_id' => $aluno['id'],
            ':disciplina_id' => $disciplina_id,
            ':trimestre' => $trimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $nota_data = $stmt_nota->fetch(PDO::FETCH_ASSOC);
        
        $nota = $nota_data ? (float)$nota_data['media_final'] : null;
        $nota_id = $nota_data ? $nota_data['id'] : null;
        
        if ($nota !== null && $nota > 0) {
            $soma_notas += $nota;
            $total_com_nota++;
            
            if ($nota >= 14) {
                $estatisticas['total_aprovados']++;
            } elseif ($nota >= 10 && $nota < 14) {
                $estatisticas['total_exame']++;
            } else {
                $estatisticas['total_reprovados']++;
            }
            
            if ($nota > $estatisticas['maior_nota']) {
                $estatisticas['maior_nota'] = $nota;
            }
            if ($estatisticas['menor_nota'] == 0 || $nota < $estatisticas['menor_nota']) {
                $estatisticas['menor_nota'] = $nota;
            }
        }
        
        $alunos_notas[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'],
            'media_final' => $nota,
            'nota_id' => $nota_id,
            'status' => $nota_data['status'] ?? '',
            'data_lancamento' => $nota_data['data_lancamento'] ?? ''
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
    $nota_padrao = isset($_POST['nota_padrao']) ? (float)$_POST['nota_padrao'] : null;
    
    if ($acao_massa == 'lancar_padrao' && $nota_padrao !== null) {
        // Lançar mesma nota para todos os alunos
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
        foreach ($alunos_massa as $aluno) {
            // Verificar se já existe nota
            $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND trimestre = :trimestre AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':estudante_id' => $aluno['id'],
                ':disciplina_id' => $disciplina_id_massa,
                ':trimestre' => $trimestre_massa,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($existe) {
                // Atualizar
                $sql = "UPDATE notas SET media_final = :media_final, data_lancamento = NOW() WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([':media_final' => $nota_padrao, ':id' => $existe['id']]);
            } else {
                // Inserir
                $sql = "INSERT INTO notas (estudante_id, disciplina_id, media_final, trimestre, ano_letivo_id, data_lancamento) 
                        VALUES (:estudante_id, :disciplina_id, :media_final, :trimestre, :ano_letivo_id, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':estudante_id' => $aluno['id'],
                    ':disciplina_id' => $disciplina_id_massa,
                    ':media_final' => $nota_padrao,
                    ':trimestre' => $trimestre_massa,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
            }
            $contador++;
        }
        $mensagem_sucesso = "Nota padrão ($nota_padrao valores) lançada para $contador alunos!";
        header("Location: manipautas.php?turma_id=$turma_id_massa&disciplina_id=$disciplina_id_massa&trimestre=$trimestre_massa&tipo_pauta=$tipo_pauta&ano_letivo=$ano_letivo_id");
        exit;
    }
    
    if ($acao_massa == 'limpar_tudo') {
        // Limpar todas as notas da turma/disciplina
        $sql = "DELETE FROM notas 
                WHERE disciplina_id = :disciplina_id 
                AND trimestre = :trimestre 
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
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 15px;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
        }
        
        .nota-input {
            width: 80px;
            text-align: center;
        }
        
        .status-aprovado { color: #28a745; font-weight: bold; }
        .status-exame { color: #ffc107; font-weight: bold; }
        .status-reprovado { color: #dc3545; font-weight: bold; }
        .status-sem-nota { color: #6c757d; }
        
        @media print {
            .no-print { display: none !important; }
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 10px; }
        }
        
        .help-modal-step {
            background: #f8f9fa;
            border-left: 4px solid #006B3E;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 5px;
        }
        
        .step-number {
            background: #006B3E;
            color: white;
            width: 30px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .checkbox-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .checkbox-card:hover {
            background: #f8f9fa;
            border-color: #006B3E;
        }
        
        .checkbox-card.selected {
            background: #e8f5e9;
            border-color: #006B3E;
        }
        
        .disciplina-checkbox {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .btn-selecionar-todos {
            background: #006B3E;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        .btn-selecionar-todos:hover {
            background: #004d2d;
        }
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
        
        <!-- Mensagens -->
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
                        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_id == $ano['id'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?>
                        </option>
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
                    <label class="form-label fw-bold">trimestre</label>
                    <select name="trimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $trimestre == 1 ? 'selected' : ''; ?>>1º trimestre</option>
                        <option value="2" <?php echo $trimestre == 2 ? 'selected' : ''; ?>>2º trimestre</option>
                        <option value="3" <?php echo $trimestre == 3 ? 'selected' : ''; ?>>3º trimestre</option>
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
        
        <!-- Seleção por Professor (alternativa com checkboxes) -->
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
        
        <!-- Tabela de Pauta (quando selecionada uma disciplina específica) -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos_notas)): ?>
        
        <!-- Informação da Disciplina Atual -->
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> 
            <strong>Disciplina Atual:</strong> <?php echo htmlspecialchars($disciplina_atual_nome); ?> | 
            <strong>Turma:</strong> <?php echo $turmas[array_search($turma_id, array_column($turmas, 'id'))]['ano'] . 'ª - ' . $turmas[array_search($turma_id, array_column($turmas, 'id'))]['nome']; ?> | 
            <strong>trimestre:</strong> <?php echo $trimestre; ?>º
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="row mb-4">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-users fa-2x text-primary mb-2"></i>
                    <div class="stat-number"><?php echo $estatisticas['total_alunos']; ?></div>
                    <div class="text-muted small">Total Alunos</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <div class="stat-number text-success"><?php echo $estatisticas['total_aprovados']; ?></div>
                    <div class="text-muted small">Aprovados (≥14)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-chalkboard fa-2x text-warning mb-2"></i>
                    <div class="stat-number text-warning"><?php echo $estatisticas['total_exame']; ?></div>
                    <div class="text-muted small">Exame (10-13)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <div class="stat-number text-danger"><?php echo $estatisticas['total_reprovados']; ?></div>
                    <div class="text-muted small">Reprovados (< 10)</div>
                </div>
            </div>
        </div>
        
        <!-- Botões de Ação em Massa -->
        <div class="row mb-4 no-print">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-tasks"></i> Ações em Massa</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <form method="POST" class="d-flex gap-2" onsubmit="return confirm('Tem certeza que deseja lançar a mesma nota para todos os alunos?')">
                                    <input type="hidden" name="acao_massa" value="lancar_padrao">
                                    <input type="hidden" name="disciplina_id_massa" value="<?php echo $disciplina_id; ?>">
                                    <input type="hidden" name="turma_id_massa" value="<?php echo $turma_id; ?>">
                                    <input type="hidden" name="trimestre_massa" value="<?php echo $trimestre; ?>">
                                    <input type="number" name="nota_padrao" class="form-control" step="0.5" min="0" max="20" placeholder="Nota padrão" required style="width: 120px;">
                                    <button type="submit" class="btn btn-primary">Lançar para Todos</button>
                                </form>
                            </div>
                            <div class="col-md-4">
                                <form method="POST" onsubmit="return confirm('ATENÇÃO: Isso irá REMOVER todas as notas desta turma/disciplina/trimestre! Continuar?')">
                                    <input type="hidden" name="acao_massa" value="limpar_tudo">
                                    <input type="hidden" name="disciplina_id_massa" value="<?php echo $disciplina_id; ?>">
                                    <input type="hidden" name="turma_id_massa" value="<?php echo $turma_id; ?>">
                                    <input type="hidden" name="trimestre_massa" value="<?php echo $trimestre; ?>">
                                    <button type="submit" class="btn btn-danger">Limpar Todas as Notas</button>
                                </form>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex gap-2">
                                    <button onclick="exportarExcel()" class="btn btn-success">
                                        <i class="fas fa-file-excel"></i> Baixar Excel
                                    </button>
                                    <button onclick="window.print()" class="btn btn-info">
                                        <i class="fas fa-print"></i> Imprimir
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Notas -->
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0">
                    <i class="fas fa-table"></i> 
                    Pauta de Notas - <?php echo $trimestre; ?>º trimestre
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaNotas">
                        <thead>
                            <tr>
                                <th width="5%">#</th>
                                <th width="12%">Matrícula</th>
                                <th width="35%">Aluno</th>
                                <th width="10%">Genero</th>
                                <th width="12%">Nota (0-20)</th>
                                <th width="10%">Status</th>
                                <th width="16%" class="no-print">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos_notas as $index => $aluno): 
                                $status = '';
                                $status_class = '';
                                if ($aluno['media_final'] !== null && $aluno['media_final'] > 0) {
                                    if ($aluno['media_final'] >= 14) {
                                        $status = 'Aprovado';
                                        $status_class = 'status-aprovado';
                                    } elseif ($aluno['media_final'] >= 10) {
                                        $status = 'Exame';
                                        $status_class = 'status-exame';
                                    } else {
                                        $status = 'Reprovado';
                                        $status_class = 'status-reprovado';
                                    }
                                } else {
                                    $status = 'Sem nota';
                                    $status_class = 'status-sem-nota';
                                }
                            ?>
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
                                    <input type="number" class="form-control nota-input" 
                                           id="nota-<?php echo $aluno['id']; ?>"
                                           value="<?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 2, '.', '') : ''; ?>"
                                           step="0.5" min="0" max="20" 
                                           placeholder="---"
                                           onchange="salvarNota(<?php echo $aluno['id']; ?>, <?php echo $disciplina_id; ?>, <?php echo $trimestre; ?>, <?php echo $ano_letivo_id; ?>)">
                                </td>
                                <td class="<?php echo $status_class; ?> text-center" id="status-<?php echo $aluno['id']; ?>">
                                    <?php echo $status; ?>
                                </td>
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
                                <td colspan="4" class="text-end fw-bold">Média Geral:</td>
                                <td colspan="3" class="fw-bold"><?php echo number_format($estatisticas['media_geral'], 2, ',', '.'); ?> valores</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($alunos_notas)): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> Nenhum aluno encontrado nesta turma.
            </div>
        <?php elseif ($turma_id > 0 && $disciplina_id == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione uma disciplina para visualizar a pauta.
            </div>
        <?php elseif ($turma_id == 0 && $professor_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma ou professor para começar.
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda / Tutorial -->
    <div class="modal fade no-print" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Tutorial - Manipulação de Pautas</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>O que é esta página?</strong><br>
                        Aqui você pode lançar, editar e visualizar notas dos alunos por turma, disciplina e trimestre.
                    </div>
                    
                    <h6 class="mt-4 mb-3"><i class="fas fa-road"></i> Passo a Passo</h6>
                    
                    <div class="help-modal-step">
                        <span class="step-number">1</span>
                        <strong>Selecione o Ano Letivo</strong>
                        <p class="mt-2 mb-0 text-muted">Escolha o ano letivo desejado no primeiro filtro.</p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">2</span>
                        <strong>Duas formas de trabalhar:</strong>
                        <p class="mt-2 mb-0 text-muted">
                            <strong>Opção A - Por Turma:</strong> Selecione uma turma e depois a disciplina desejada.<br>
                            <strong>Opção B - Por Professor:</strong> Selecione um professor, marque as disciplinas desejadas e clique em "Visualizar".
                        </p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">3</span>
                        <strong>Seleção Múltipla de Disciplinas (Professor)</strong>
                        <p class="mt-2 mb-0 text-muted">
                            Ao selecionar um professor, você verá todas as disciplinas que ele leciona com checkboxes.<br>
                            Marque quantas disciplinas quiser e clique em "Visualizar Disciplinas Selecionadas".
                        </p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">4</span>
                        <strong>Lançamento de Notas</strong>
                        <p class="mt-2 mb-0 text-muted">
                            <i class="fas fa-arrow-right"></i> <strong>Individual:</strong> Digite a nota no campo da tabela e clique fora ou pressione Enter.<br>
                            <i class="fas fa-arrow-right"></i> <strong>Em Massa:</strong> Use o campo "Nota padrão" e clique em "Lançar para Todos".<br>
                            <i class="fas fa-arrow-right"></i> <strong>Limpar Nota:</strong> Clique no botão vermelho (🗑️) para remover a nota.<br>
                            <i class="fas fa-arrow-right"></i> <strong>Limpar Tudo:</strong> Use o botão "Limpar Todas as Notas".
                        </p>
                    </div>
                    
                    <div class="help-modal-step">
                        <span class="step-number">5</span>
                        <strong>Status do Aluno</strong>
                        <p class="mt-2 mb-0 text-muted">
                            <span class="text-success">● Aprovado:</span> Nota ≥ 14 valores<br>
                            <span class="text-warning">● Exame:</span> Nota entre 10 e 13 valores<br>
                            <span class="text-danger">● Reprovado:</span> Nota < 10 valores<br>
                            <span class="text-secondary">● Sem nota:</span> Nota não lançada
                        </p>
                    </div>
                    
                    <div class="alert alert-success mt-3">
                        <i class="fas fa-lightbulb"></i> <strong>Dica:</strong> 
                        As notas são salvas automaticamente quando você digita e sai do campo. Não precisa clicar em nenhum botão de salvar!
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir Tutorial</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        // DataTable
        $('#tabelaNotas').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
            },
            order: [[2, 'asc']],
            pageLength: 25
        });
        
        // Função para salvar nota individual
        function salvarNota(alunoId, disciplinaId, trimestre, anoLetivoId) {
            var nota = $('#nota-' + alunoId).val();
            if (nota === '') return;
            
            $.ajax({
                url: 'ajax_salvar_nota.php',
                method: 'POST',
                data: {
                    estudante_id: alunoId,
                    disciplina_id: disciplinaId,
                    nota: nota,
                    trimestre: trimestre,
                    ano_letivo_id: anoLetivoId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Nota salva com sucesso!', 'success');
                        location.reload();
                    } else {
                        showToast(response.message || 'Erro ao salvar nota', 'error');
                    }
                },
                error: function() {
                    showToast('Erro de conexão', 'error');
                }
            });
        }
        
        // Função para limpar nota individual
        function limparNota(alunoId, disciplinaId, trimestre, anoLetivoId, notaId) {
            if (!confirm('Tem certeza que deseja remover esta nota?')) return;
            
            $.ajax({
                url: 'ajax_limpar_nota.php',
                method: 'POST',
                data: {
                    estudante_id: alunoId,
                    disciplina_id: disciplinaId,
                    trimestre: trimestre,
                    ano_letivo_id: anoLetivoId,
                    nota_id: notaId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showToast('Nota removida com sucesso!', 'success');
                        location.reload();
                    } else {
                        showToast(response.message || 'Erro ao remover nota', 'error');
                    }
                },
                error: function() {
                    showToast('Erro de conexão', 'error');
                }
            });
        }
        
        // Função para mostrar toast
        function showToast(message, type) {
            var toastHtml = `
                <div class="toast align-items-center text-white bg-${type == 'success' ? 'success' : 'danger'} border-0 position-fixed bottom-0 end-0 m-3" role="alert">
                    <div class="d-flex">
                        <div class="toast-body">${message}</div>
                        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            `;
            $('body').append(toastHtml);
            var toast = new bootstrap.Toast($('.toast').last());
            toast.show();
            setTimeout(function() { $('.toast').last().remove(); }, 3000);
        }
        
        // Carregar disciplinas do professor
        function carregarDisciplinasProfessor() {
            var professorId = $('#professor_id').val();
            if (professorId > 0) {
                window.location.href = 'manipautas.php?professor_id=' + professorId + '&ano_letivo=<?php echo $ano_letivo_id; ?>';
            }
        }
        
        // Selecionar/Deselecionar todas as disciplinas
        function selecionarTodas(selecionar) {
            $('.disciplina-checkbox').prop('checked', selecionar);
            atualizarContador();
        }
        
        // Atualizar contador de disciplinas selecionadas
        function atualizarContador() {
            var total = $('.disciplina-checkbox:checked').length;
            $('#contadorSelecionadas').text(total + ' disciplina(s) selecionada(s)');
        }
        
        // Toggle checkbox ao clicar no card
        function toggleCheckbox(id) {
            var checkbox = $('#disc_' + id);
            checkbox.prop('checked', !checkbox.prop('checked'));
            atualizarContador();
        }
        
        // Atualizar contador ao clicar nos checkboxes
        $(document).on('change', '.disciplina-checkbox', function() {
            atualizarContador();
        });
        
        // Inicializar contador
        atualizarContador();
        
        // Exportar Excel
        function exportarExcel() {
            window.location.href = 'exportar_excel_pauta.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>';
        }
    </script>
</body>
</html>