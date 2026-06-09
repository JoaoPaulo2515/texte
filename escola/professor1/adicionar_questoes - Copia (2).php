<?php
// escola/professor/provas/adicionar_questoes.php - Adicionar Questões à Prova

require_once __DIR__ . '/../../../config/database.php';
session_start();
/*
// Verificar se o professor está logado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}*/

$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['usuario_nome'] ?? 'Professor';

// Buscar dados do professor
$sql_professor = "SELECT f.id as funcionario_id FROM funcionarios f WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $professor['funcionario_id'] ?? 0;

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id, ':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die('Prova não encontrada ou você não tem permissão para editá-la.');
}

// Processar adição de questão
$erro = '';
$sucesso = '';
$modo_edicao = false;
$questao_edit_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'salvar_questao') {
        $enunciado = $_POST['enunciado'] ?? '';
        $tipo = $_POST['tipo'] ?? 'multipla_escolha';
        $pontuacao = (float)$_POST['pontuacao'] ?? 1.00;
        $alternativas = $_POST['alternativas'] ?? [];
        $correta = isset($_POST['correta']) ? (int)$_POST['correta'] : null;
        $questao_id = isset($_POST['questao_id']) ? (int)$_POST['questao_id'] : 0;
        
        if (empty($enunciado)) {
            $erro = 'Digite o enunciado da questão.';
        } else {
            try {
                if ($questao_id > 0) {
                    // Atualizar questão existente
                    $sql = "UPDATE online_provas_questoes SET enunciado = :enunciado, tipo = :tipo, pontuacao = :pontuacao WHERE id = :id AND prova_id = :prova_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':enunciado' => $enunciado,
                        ':tipo' => $tipo,
                        ':pontuacao' => $pontuacao,
                        ':id' => $questao_id,
                        ':prova_id' => $prova_id
                    ]);
                    
                    // Remover alternativas antigas
                    $stmt = $conn->prepare("DELETE FROM online_provas_alternativas WHERE questao_id = :questao_id");
                    $stmt->execute([':questao_id' => $questao_id]);
                    
                    $sucesso = 'Questão atualizada com sucesso!';
                    $questao_nova_id = $questao_id;
                } else {
                    // CORREÇÃO: Buscar o próximo número de ordem separadamente
                    $sql_max = "SELECT COALESCE(MAX(ordem), 0) + 1 as prox_ordem FROM online_provas_questoes WHERE prova_id = :prova_id";
                    $stmt_max = $conn->prepare($sql_max);
                    $stmt_max->execute([':prova_id' => $prova_id]);
                    $result_max = $stmt_max->fetch(PDO::FETCH_ASSOC);
                    $proxima_ordem = $result_max['prox_ordem'] ?? 1;
                    
                    // Inserir nova questão
                    $sql = "INSERT INTO online_provas_questoes (prova_id, enunciado, tipo, pontuacao, ordem) 
                            VALUES (:prova_id, :enunciado, :tipo, :pontuacao, :ordem)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':prova_id' => $prova_id,
                        ':enunciado' => $enunciado,
                        ':tipo' => $tipo,
                        ':pontuacao' => $pontuacao,
                        ':ordem' => $proxima_ordem
                    ]);
                    $questao_nova_id = $conn->lastInsertId();
                    $sucesso = 'Questão adicionada com sucesso!';
                }
                
                // Adicionar alternativas (para múltipla escolha)
                if ($tipo == 'multipla_escolha' && !empty($alternativas)) {
                    foreach ($alternativas as $idx => $texto) {
                        if (!empty($texto)) {
                            $is_correta = ($correta == $idx) ? 1 : 0;
                            $sql = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) VALUES (:questao_id, :texto, :correta, :ordem)";
                            $stmt = $conn->prepare($sql);
                            $stmt->execute([
                                ':questao_id' => $questao_nova_id,
                                ':texto' => $texto,
                                ':correta' => $is_correta,
                                ':ordem' => $idx
                            ]);
                        }
                    }
                } elseif ($tipo == 'verdadeiro_falso') {
                    // Adicionar Verdadeiro e Falso
                    $sql = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) VALUES 
                            (:questao_id, 'Verdadeiro', :correta_v, 1),
                            (:questao_id, 'Falso', :correta_f, 2)";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':questao_id' => $questao_nova_id,
                        ':correta_v' => ($correta == 0) ? 1 : 0,
                        ':correta_f' => ($correta == 1) ? 1 : 0
                    ]);
                }
                
            } catch (PDOException $e) {
                $erro = 'Erro ao salvar questão: ' . $e->getMessage();
            }
        }
    } elseif ($action == 'editar_questao') {
        $questao_edit_id = (int)$_POST['questao_id'];
        $modo_edicao = true;
        
        $sql = "SELECT * FROM online_provas_questoes WHERE id = :id AND prova_id = :prova_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $questao_edit_id, ':prova_id' => $prova_id]);
        $questao_edit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($questao_edit) {
            $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
            $stmt_alt = $conn->prepare($sql_alt);
            $stmt_alt->execute([':questao_id' => $questao_edit_id]);
            $alternativas_edit = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($action == 'excluir_questao') {
        $questao_id = (int)$_POST['questao_id'];
        
        $sql = "DELETE FROM online_provas_questoes WHERE id = :id AND prova_id = :prova_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $questao_id, ':prova_id' => $prova_id]);
        
        $sucesso = 'Questão excluída com sucesso!';
    } elseif ($action == 'reordenar') {
        $ordens = $_POST['ordem'] ?? [];
        foreach ($ordens as $id => $ordem) {
            $sql = "UPDATE online_provas_questoes SET ordem = :ordem WHERE id = :id AND prova_id = :prova_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':ordem' => $ordem, ':id' => $id, ':prova_id' => $prova_id]);
        }
        $sucesso = 'Ordem das questões atualizada!';
    }
}

// Buscar questões da prova
$sql_questoes = "SELECT q.*, (SELECT COUNT(*) FROM online_provas_alternativas WHERE questao_id = q.id) as num_alternativas
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

$total_pontos = array_sum(array_column($questoes, 'pontuacao'));

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adicionar Questões - <?php echo htmlspecialchars($prova['titulo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        .questao-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
        }
        .questao-header {
            padding: 12px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .questao-body {
            padding: 20px;
        }
        .alternativa-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .alternativa-texto {
            flex: 1;
        }
        .btn-add-alternativa {
            background: #f8f9fa;
            border: 1px dashed #006B3E;
            color: #006B3E;
            border-radius: 10px;
            padding: 10px;
            width: 100%;
        }
        .btn-editar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 5px;
        }
        .btn-excluir {
            background: #dc3545;
            color: white;
            border: none;
            padding: 3px 8px;
            border-radius: 5px;
        }
        .btn-publicar {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 25px;
        }
        .drag-handle {
            cursor: move;
            color: #999;
        }
    </style>
</head>
<body>
    
     <?php include '../includes/menu_professor.php'; ?>
<div class="main-content">
    <div class="container-fluid">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-questions"></i> Adicionar Questões</h4>
                <p class="text-muted mb-0">Prova: <?php echo htmlspecialchars($prova['titulo']); ?></p>
                <p class="text-muted small">Disciplina: <?php echo htmlspecialchars($prova['disciplina_nome']); ?> | Turma: <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></p>
            </div>
            <div>
                <a href="listar_provas.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <!-- Resumo -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-primary"><?php echo count($questoes); ?></h3>
                        <p class="text-muted mb-0">Questões</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-success"><?php echo number_format($total_pontos, 1); ?></h3>
                        <p class="text-muted mb-0">Pontos Totais</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?php echo $prova['nota_maxima']; ?></h3>
                        <p class="text-muted mb-0">Nota Máxima</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center">
                        <a href="previsualizar_prova.php?id=<?php echo $prova_id; ?>" class="btn btn-primary" target="_blank">
                            <i class="fas fa-eye"></i> Pré-visualizar
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($erro): ?>
            <div class="alert alert-danger"><?php echo $erro; ?></div>
        <?php endif; ?>
        <?php if ($sucesso): ?>
            <div class="alert alert-success"><?php echo $sucesso; ?></div>
        <?php endif; ?>

        <!-- Formulário de Nova Questão -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-plus-circle"></i> <?php echo $modo_edicao ? 'Editar Questão' : 'Nova Questão'; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="formQuestao">
                    <input type="hidden" name="action" value="salvar_questao">
                    <input type="hidden" name="questao_id" id="questao_id" value="<?php echo $questao_edit_id ?? 0; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Enunciado da Questão</label>
                        <textarea name="enunciado" id="enunciado" class="form-control" rows="3" required><?php echo htmlspecialchars($questao_edit['enunciado'] ?? ''); ?></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Tipo de Questão</label>
                                <select name="tipo" id="tipo" class="form-select" onchange="mudarTipoQuestao()">
                                    <option value="multipla_escolha" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'multipla_escolha') ? 'selected' : ''; ?>>Múltipla Escolha</option>
                                    <option value="verdadeiro_falso" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'verdadeiro_falso') ? 'selected' : ''; ?>>Verdadeiro ou Falso</option>
                                    <option value="dissertativa" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'dissertativa') ? 'selected' : ''; ?>>Dissertativa</option>
                                    <option value="completar" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'completar') ? 'selected' : ''; ?>>Completar</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Pontuação</label>
                                <input type="number" name="pontuacao" id="pontuacao" class="form-control" step="0.5" value="<?php echo $questao_edit['pontuacao'] ?? 1.00; ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Alternativas para múltipla escolha -->
                    <div id="alternativas_div">
                        <label class="form-label">Alternativas</label>
                        <div id="alternativas_container">
                            <?php 
                            $num_alt = 4;
                            if ($modo_edicao && isset($alternativas_edit) && count($alternativas_edit) > 0) {
                                foreach ($alternativas_edit as $idx => $alt) {
                                    echo '<div class="alternativa-item">';
                                    echo '<input type="radio" name="correta" value="' . $idx . '" ' . ($alt['correta'] ? 'checked' : '') . '>';
                                    echo '<input type="text" name="alternativas[]" class="form-control alternativa-texto" value="' . htmlspecialchars($alt['texto']) . '" placeholder="Digite a alternativa...">';
                                    echo '<button type="button" class="btn btn-sm btn-danger" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>';
                                    echo '</div>';
                                }
                                $num_alt = count($alternativas_edit);
                            } else {
                                for ($i = 0; $i < 4; $i++) {
                                    echo '<div class="alternativa-item">';
                                    echo '<input type="radio" name="correta" value="' . $i . '">';
                                    echo '<input type="text" name="alternativas[]" class="form-control alternativa-texto" placeholder="Digite a alternativa...">';
                                    echo '<button type="button" class="btn btn-sm btn-danger" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>';
                                    echo '</div>';
                                }
                            }
                            ?>
                        </div>
                        <button type="button" class="btn-add-alternativa" onclick="adicionarAlternativa()">
                            <i class="fas fa-plus"></i> Adicionar Alternativa
                        </button>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> <?php echo $modo_edicao ? 'Atualizar Questão' : 'Adicionar Questão'; ?>
                        </button>
                        <?php if ($modo_edicao): ?>
                        <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn btn-secondary">Cancelar</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Lista de Questões -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Questões da Prova
                <span class="badge bg-primary float-end"><?php echo count($questoes); ?> questões | Total: <?php echo number_format($total_pontos, 1); ?> pts</span>
            </div>
            <div class="card-body">
                <form method="POST" id="formReordenar">
                    <input type="hidden" name="action" value="reordenar">
                    <div id="questoes-list">
                        <?php foreach ($questoes as $index => $q): 
                            $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
                            $stmt_alt = $conn->prepare($sql_alt);
                            $stmt_alt->execute([':questao_id' => $q['id']]);
                            $alternativas_q = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <div class="questao-card" data-id="<?php echo $q['id']; ?>">
                            <div class="questao-header">
                                <div>
                                    <span class="badge bg-secondary me-2">Q<?php echo $index + 1; ?></span>
                                    <strong><?php echo htmlspecialchars(substr($q['enunciado'], 0, 100)) . (strlen($q['enunciado']) > 100 ? '...' : ''); ?></strong>
                                    <span class="badge bg-info ms-2"><?php echo ucfirst(str_replace('_', ' ', $q['tipo'])); ?></span>
                                </div>
                                <div>
                                    <span class="badge bg-success me-2"><?php echo $q['pontuacao']; ?> pts</span>
                                    <button type="button" class="btn-editar" onclick="editarQuestao(<?php echo $q['id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="btn-excluir" onclick="excluirQuestao(<?php echo $q['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <i class="fas fa-grip-vertical drag-handle ms-2"></i>
                                    <input type="hidden" name="ordem[<?php echo $q['id']; ?>]" value="<?php echo $q['ordem']; ?>">
                                </div>
                            </div>
                            <div class="questao-body">
                                <?php if (!empty($alternativas_q)): ?>
                                    <div class="row">
                                        <?php foreach ($alternativas_q as $alt): ?>
                                        <div class="col-md-6">
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="badge <?php echo $alt['correta'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $alt['correta'] ? '✓ Correta' : '○'; ?>
                                                </span>
                                                <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php elseif ($q['tipo'] == 'dissertativa'): ?>
                                    <p class="text-muted mb-0"><i class="fas fa-pen"></i> Questão dissertativa - resposta livre</p>
                                <?php elseif ($q['tipo'] == 'completar'): ?>
                                    <p class="text-muted mb-0"><i class="fas fa-ellipsis-h"></i> Questão de completar - resposta textual</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (count($questoes) > 1): ?>
                    <div class="text-center mt-3">
                        <button type="submit" class="btn btn-sm btn-primary" form="formReordenar">
                            <i class="fas fa-save"></i> Salvar Ordem
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
                
                <?php if (empty($questoes)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma questão adicionada ainda. Comece criando uma questão acima!
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Botão Publicar -->
        <?php if (!empty($questoes)): ?>
        <div class="text-center mt-4">
            <a href="publicar_prova.php?id=<?php echo $prova_id; ?>" class="btn-publicar" onclick="return confirm('Tem certeza que deseja publicar esta prova? Após publicada, os alunos poderão visualizá-la.')">
                <i class="fas fa-check-circle"></i> Publicar Prova
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
    let contadorAlternativas = <?php echo $num_alt ?? 4; ?>;
    
    function adicionarAlternativa() {
        const container = document.getElementById('alternativas_container');
        const newDiv = document.createElement('div');
        newDiv.className = 'alternativa-item';
        newDiv.innerHTML = `
            <input type="radio" name="correta" value="${contadorAlternativas}">
            <input type="text" name="alternativas[]" class="form-control alternativa-texto" placeholder="Digite a alternativa...">
            <button type="button" class="btn btn-sm btn-danger" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>
        `;
        container.appendChild(newDiv);
        contadorAlternativas++;
    }
    
    function removerAlternativa(btn) {
        btn.closest('.alternativa-item').remove();
    }
    
    function mudarTipoQuestao() {
        const tipo = document.getElementById('tipo').value;
        const divAlternativas = document.getElementById('alternativas_div');
        
        if (tipo == 'multipla_escolha') {
            divAlternativas.style.display = 'block';
        } else if (tipo == 'verdadeiro_falso') {
            divAlternativas.style.display = 'block';
            const container = document.getElementById('alternativas_container');
            container.innerHTML = `
                <div class="alternativa-item">
                    <input type="radio" name="correta" value="0" checked>
                    <input type="text" name="alternativas[]" class="form-control alternativa-texto" value="Verdadeiro" readonly>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removerAlternativa(this)" style="visibility: hidden;"><i class="fas fa-trash"></i></button>
                </div>
                <div class="alternativa-item">
                    <input type="radio" name="correta" value="1">
                    <input type="text" name="alternativas[]" class="form-control alternativa-texto" value="Falso" readonly>
                    <button type="button" class="btn btn-sm btn-danger" onclick="removerAlternativa(this)" style="visibility: hidden;"><i class="fas fa-trash"></i></button>
                </div>
            `;
        } else {
            divAlternativas.style.display = 'none';
        }
    }
    
    function editarQuestao(id) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="editar_questao">
            <input type="hidden" name="questao_id" value="${id}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    function excluirQuestao(id) {
        if (confirm('Tem certeza que deseja excluir esta questão?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="excluir_questao">
                <input type="hidden" name="questao_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Drag and drop para reordenar
    const list = document.getElementById('questoes-list');
    if (list) {
        new Sortable(list, {
            handle: '.drag-handle',
            animation: 150,
            onEnd: function() {
                const items = document.querySelectorAll('#questoes-list .questao-card');
                items.forEach((item, index) => {
                    const input = item.querySelector('input[name^="ordem"]');
                    if (input) input.value = index + 1;
                });
            }
        });
    }
    
    mudarTipoQuestao();
</script>
</body>
</html>