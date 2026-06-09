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
$questao_edit = null;
$alternativas_edit = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action == 'salvar_questao') {
        $enunciado = trim($_POST['enunciado'] ?? '');
        $tipo = $_POST['tipo'] ?? 'multipla_escolha';
        $pontuacao = (float)($_POST['pontuacao'] ?? 1.00);
        $questao_id = isset($_POST['questao_id']) ? (int)$_POST['questao_id'] : 0;
        
        if (empty($enunciado)) {
            $erro = 'Digite o enunciado da questão.';
        } else {
            try {
                // Iniciar transação
                $conn->beginTransaction();
                
                if ($questao_id > 0) {
                    // ATUALIZAR QUESTÃO EXISTENTE
                    $sql = "UPDATE online_provas_questoes 
                            SET enunciado = :enunciado, tipo = :tipo, pontuacao = :pontuacao 
                            WHERE id = :id AND prova_id = :prova_id";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(':enunciado', $enunciado);
                    $stmt->bindParam(':tipo', $tipo);
                    $stmt->bindParam(':pontuacao', $pontuacao);
                    $stmt->bindParam(':id', $questao_id);
                    $stmt->bindParam(':prova_id', $prova_id);
                    $stmt->execute();
                    
                    // Remover alternativas antigas
                    $stmt_del = $conn->prepare("DELETE FROM online_provas_alternativas WHERE questao_id = :questao_id");
                    $stmt_del->bindParam(':questao_id', $questao_id);
                    $stmt_del->execute();
                    
                    $questao_nova_id = $questao_id;
                    $sucesso = 'Questão atualizada com sucesso!';
                    
                } else {
                    // INSERIR NOVA QUESTÃO
                    $sql_max = "SELECT COALESCE(MAX(ordem), 0) + 1 as prox_ordem FROM online_provas_questoes WHERE prova_id = :prova_id";
                    $stmt_max = $conn->prepare($sql_max);
                    $stmt_max->bindParam(':prova_id', $prova_id);
                    $stmt_max->execute();
                    $result_max = $stmt_max->fetch(PDO::FETCH_ASSOC);
                    $proxima_ordem = $result_max['prox_ordem'] ?? 1;
                    
                    $sql = "INSERT INTO online_provas_questoes (prova_id, enunciado, tipo, pontuacao, ordem) 
                            VALUES (:prova_id, :enunciado, :tipo, :pontuacao, :ordem)";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(':prova_id', $prova_id);
                    $stmt->bindParam(':enunciado', $enunciado);
                    $stmt->bindParam(':tipo', $tipo);
                    $stmt->bindParam(':pontuacao', $pontuacao);
                    $stmt->bindParam(':ordem', $proxima_ordem);
                    $stmt->execute();
                    
                    $questao_nova_id = $conn->lastInsertId();
                    $sucesso = 'Questão adicionada com sucesso!';
                }
                
                // ADICIONAR ALTERNATIVAS
                if ($tipo == 'multipla_escolha') {
                    $alternativas = $_POST['alternativas'] ?? [];
                    $correta = isset($_POST['correta']) ? (int)$_POST['correta'] : 0;
                    
                    $sql_alt = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                                VALUES (:questao_id, :texto, :correta, :ordem)";
                    $stmt_alt = $conn->prepare($sql_alt);
                    
                    foreach ($alternativas as $idx => $texto_alt) {
                        $texto_alt = trim($texto_alt);
                        if (!empty($texto_alt)) {
                            $is_correta = ($correta == $idx) ? 1 : 0;
                            $stmt_alt->bindParam(':questao_id', $questao_nova_id);
                            $stmt_alt->bindParam(':texto', $texto_alt);
                            $stmt_alt->bindParam(':correta', $is_correta);
                            $stmt_alt->bindParam(':ordem', $idx);
                            $stmt_alt->execute();
                        }
                    }
                    
                } elseif ($tipo == 'verdadeiro_falso') {
                    $correta = isset($_POST['correta']) ? (int)$_POST['correta'] : 0;
                    
                    $sql_v = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                              VALUES (:questao_id, 'Verdadeiro', :correta_v, 1)";
                    $stmt_v = $conn->prepare($sql_v);
                    $correta_v = ($correta == 0) ? 1 : 0;
                    $stmt_v->bindParam(':questao_id', $questao_nova_id);
                    $stmt_v->bindParam(':correta_v', $correta_v);
                    $stmt_v->execute();
                    
                    $sql_f = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                              VALUES (:questao_id, 'Falso', :correta_f, 2)";
                    $stmt_f = $conn->prepare($sql_f);
                    $correta_f = ($correta == 1) ? 1 : 0;
                    $stmt_f->bindParam(':questao_id', $questao_nova_id);
                    $stmt_f->bindParam(':correta_f', $correta_f);
                    $stmt_f->execute();
                }
                
                $conn->commit();
                
            } catch (PDOException $e) {
                $conn->rollBack();
                $erro = 'Erro ao salvar questão: ' . $e->getMessage();
            }
        }
        
    } elseif ($action == 'editar_questao') {
        $questao_edit_id = (int)$_POST['questao_id'];
        $modo_edicao = true;
        
        $sql = "SELECT * FROM online_provas_questoes WHERE id = :id AND prova_id = :prova_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $questao_edit_id);
        $stmt->bindParam(':prova_id', $prova_id);
        $stmt->execute();
        $questao_edit = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($questao_edit) {
            $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem";
            $stmt_alt = $conn->prepare($sql_alt);
            $stmt_alt->bindParam(':questao_id', $questao_edit_id);
            $stmt_alt->execute();
            $alternativas_edit = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
        }
        
    } elseif ($action == 'excluir_questao') {
        $questao_id = (int)$_POST['questao_id'];
        
        $sql = "DELETE FROM online_provas_questoes WHERE id = :id AND prova_id = :prova_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $questao_id);
        $stmt->bindParam(':prova_id', $prova_id);
        $stmt->execute();
        
        $sucesso = 'Questão excluída com sucesso!';
        
    } elseif ($action == 'reordenar') {
        $ordens = $_POST['ordem'] ?? [];
        foreach ($ordens as $id => $ordem) {
            $sql = "UPDATE online_provas_questoes SET ordem = :ordem WHERE id = :id AND prova_id = :prova_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':ordem', $ordem);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':prova_id', $prova_id);
            $stmt->execute();
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
$stmt_questoes->bindParam(':prova_id', $prova_id);
$stmt_questoes->execute();
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
    <!-- KaTeX para fórmulas matemáticas -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.js"></script>
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
        
        /* Estilos do Editor */
        .editor-container {
            border: 1px solid #ddd;
            border-radius: 12px;
            overflow: hidden;
            background: white;
        }
        
        .editor-toolbar {
            background: #f8f9fa;
            border-bottom: 1px solid #ddd;
            padding: 8px 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        
        .toolbar-group {
            display: flex;
            gap: 3px;
            border-right: 1px solid #ddd;
            padding-right: 8px;
            margin-right: 8px;
        }
        
        .toolbar-btn {
            background: white;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            color: #333;
        }
        
        .toolbar-btn:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        .editor-content {
            min-height: 300px;
            padding: 15px;
            outline: none;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            overflow-y: auto;
        }
        
        .editor-content:focus {
            outline: none;
        }
        
        .editor-content table {
            border-collapse: collapse;
            width: 100%;
            margin: 10px 0;
        }
        
        .editor-content th, .editor-content td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .editor-content th {
            background: #f2f2f2;
        }
        
        .editor-content img {
            max-width: 100%;
            height: auto;
        }
        
        .editor-content .formula {
            background: #f0f0f0;
            padding: 5px 10px;
            border-radius: 5px;
            font-family: monospace;
            display: inline-block;
        }
        
        .editor-content .math-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 10px 0;
            font-size: 1.2em;
            overflow-x: auto;
        }
        
        .color-picker-panel {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            display: none;
            grid-template-columns: repeat(6, 30px);
            gap: 5px;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 4px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .formula-input {
            font-family: monospace;
            font-size: 16px;
        }
        
        .table-options {
            display: inline-flex;
            gap: 5px;
            margin-left: 10px;
        }
        
        #enunciado_original {
            display: none;
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
                <h4 class="mb-1"><i class="fas fa-plus-circle"></i> Adicionar Questões</h4>
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

        <!-- Formulário de Nova Questão com Editor Avançado -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-plus-circle"></i> <?php echo $modo_edicao ? 'Editar Questão' : 'Nova Questão'; ?>
            </div>
            <div class="card-body">
                <form method="POST" id="formQuestao" onsubmit="return antesDeEnviar()">
                    <input type="hidden" name="action" value="salvar_questao">
                    <input type="hidden" name="questao_id" id="questao_id" value="<?php echo $questao_edit_id ?? 0; ?>">
                    <input type="hidden" name="enunciado" id="enunciado_original">
                    
                    <div class="mb-3">
                        <label class="form-label">Enunciado da Questão</label>
                        <div class="editor-container">
                            <div class="editor-toolbar">
                                <!-- Formatação Básica -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('bold')" title="Negrito"><i class="fas fa-bold"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('italic')" title="Itálico"><i class="fas fa-italic"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('underline')" title="Sublinhado"><i class="fas fa-underline"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('strikethrough')" title="Tachado"><i class="fas fa-strikethrough"></i></button>
                                </div>
                                
                                <!-- Alinhamento -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyLeft')" title="Esquerda"><i class="fas fa-align-left"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyCenter')" title="Centro"><i class="fas fa-align-center"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyRight')" title="Direita"><i class="fas fa-align-right"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyFull')" title="Justificar"><i class="fas fa-align-justify"></i></button>
                                </div>
                                
                                <!-- Listas -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('insertUnorderedList')" title="Lista com marcadores"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('insertOrderedList')" title="Lista numerada"><i class="fas fa-list-ol"></i></button>
                                </div>
                                
                                <!-- Cores -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" id="btnCorTexto" onclick="mostrarSeletorCor('texto')" title="Cor do texto"><i class="fas fa-palette"></i> Texto</button>
                                    <button type="button" class="toolbar-btn" id="btnCorFundo" onclick="mostrarSeletorCor('fundo')" title="Cor de fundo"><i class="fas fa-highlighter"></i> Fundo</button>
                                </div>
                                
                                <!-- Tabelas -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="inserirTabelaEditor()" title="Inserir tabela"><i class="fas fa-table"></i></button>
                                </div>
                                
                                <!-- Inserir -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="inserirImagemEditor()" title="Inserir imagem"><i class="fas fa-image"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="inserirLinkEditor()" title="Inserir link"><i class="fas fa-link"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="abrirModalFormulaEditor()" title="Fórmula matemática"><i class="fas fa-square-root-alt"></i> ∑</button>
                                    <button type="button" class="toolbar-btn" onclick="inserirCodigoEditor()" title="Inserir código"><i class="fas fa-code"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="inserirCitacaoEditor()" title="Citação"><i class="fas fa-quote-right"></i></button>
                                </div>
                                
                                <!-- Níveis de cabeçalho -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('formatBlock', 'H1')" title="Título 1">H1</button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('formatBlock', 'H2')" title="Título 2">H2</button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('formatBlock', 'H3')" title="Título 3">H3</button>
                                    <button type="button" class="toolbar-btn" onclick="editorFormatar('formatBlock', 'p')" title="Normal">P</button>
                                </div>
                                
                                <!-- Ações -->
                                <div class="toolbar-group">
                                    <button type="button" class="toolbar-btn" onclick="editorDesfazer()" title="Desfazer"><i class="fas fa-undo"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="editorRefazer()" title="Refazer"><i class="fas fa-redo"></i></button>
                                    <button type="button" class="toolbar-btn" onclick="limparFormatacaoEditor()" title="Limpar formatação"><i class="fas fa-eraser"></i></button>
                                </div>
                            </div>
                            
                            <div id="editorContent" class="editor-content" contenteditable="true">
                                <?php echo htmlspecialchars_decode($questao_edit['enunciado'] ?? '<h2>Digite aqui o enunciado da questão...</h2><p>Utilize as ferramentas acima para formatar seu texto.</p>'); ?>
                            </div>
                        </div>
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
                            $stmt_alt->bindParam(':questao_id', $q['id']);
                            $stmt_alt->execute();
                            $alternativas_q = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        <div class="questao-card" data-id="<?php echo $q['id']; ?>">
                            <div class="questao-header">
                                <div>
                                    <span class="badge bg-secondary me-2">Q<?php echo $index + 1; ?></span>
                                    <strong><?php echo htmlspecialchars(substr(strip_tags($q['enunciado']), 0, 100)) . (strlen(strip_tags($q['enunciado'])) > 100 ? '...' : ''); ?></strong>
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

<!-- Modal para fórmulas matemáticas -->
<div class="modal fade" id="formulaModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-square-root-alt"></i> Inserir Fórmula Matemática</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Expressão LaTeX</label>
                    <textarea id="formulaInput" class="form-control formula-input" rows="3" placeholder="Ex: E = mc^2"></textarea>
                    <small class="text-muted">Use sintaxe LaTeX. Ex: \frac{a}{b}, \sqrt{x}, \sum_{i=1}^{n} x_i</small>
                </div>
                <div class="mb-3">
                    <label class="form-label">Pré-visualização</label>
                    <div id="previewFormula" class="p-3 bg-light rounded text-center"></div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" onclick="inserirTemplateFormula('frac')">\frac{a}{b}</button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" onclick="inserirTemplateFormula('sqrt')">\sqrt{x}</button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" onclick="inserirTemplateFormula('sum')">\sum_{i=1}^{n}</button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" onclick="inserirTemplateFormula('int')">\int_{a}^{b}</button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" onclick="inserirTemplateFormula('alpha')">\alpha</button>
                    </div>
                    <div class="col-md-4">
                        <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-1" onclick="inserirTemplateFormula('rightarrow')">\rightarrow</button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="inserirFormulaEditor()">Inserir Fórmula</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para imagem -->
<div class="modal fade" id="imagemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-image"></i> Inserir Imagem</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">URL da imagem</label>
                    <input type="text" id="imagemUrl" class="form-control" placeholder="https://exemplo.com/imagem.jpg">
                </div>
                <div class="mb-3">
                    <label class="form-label">Ou faça upload</label>
                    <input type="file" id="imagemUpload" class="form-control" accept="image/*">
                </div>
                <div class="mb-3">
                    <label class="form-label">Descrição (alt)</label>
                    <input type="text" id="imagemAlt" class="form-control" placeholder="Descrição da imagem">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="inserirImagemUrlEditor()">Inserir</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para link -->
<div class="modal fade" id="linkModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-link"></i> Inserir Link</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Texto do link</label>
                    <input type="text" id="linkTexto" class="form-control" placeholder="Texto que será exibido">
                </div>
                <div class="mb-3">
                    <label class="form-label">URL</label>
                    <input type="url" id="linkUrl" class="form-control" placeholder="https://exemplo.com">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="inserirLinkUrlEditor()">Inserir</button>
            </div>
        </div>
    </div>
</div>

<div id="colorPickerPanel" class="color-picker-panel"></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
    // Editor Global
    let editorElement = document.getElementById('editorContent');
    let undoStack = [];
    let redoStack = [];
    
    // Salvar estado para desfazer/refazer
    function salvarEstadoEditor() {
        undoStack.push(editorElement.innerHTML);
        redoStack = [];
    }
    
    function editorDesfazer() {
        if (undoStack.length > 1) {
            redoStack.push(undoStack.pop());
            editorElement.innerHTML = undoStack[undoStack.length - 1];
        }
        editorElement.focus();
    }
    
    function editorRefazer() {
        if (redoStack.length > 0) {
            let estado = redoStack.pop();
            undoStack.push(estado);
            editorElement.innerHTML = estado;
        }
        editorElement.focus();
    }
    
    function editorFormatar(comando, valor = null) {
        salvarEstadoEditor();
        if (valor) {
            document.execCommand(comando, false, valor);
        } else {
            document.execCommand(comando, false, null);
        }
        editorElement.focus();
    }
    
    function limparFormatacaoEditor() {
        salvarEstadoEditor();
        document.execCommand('removeFormat', false, null);
        editorElement.focus();
    }
    
    // Inserir tabela
    function inserirTabelaEditor() {
        salvarEstadoEditor();
        let linhas = prompt('Número de linhas:', '3');
        let colunas = prompt('Número de colunas:', '3');
        if (linhas && colunas) {
            let tabela = '<table class="table table-bordered" style="width:100%; border-collapse:collapse;">';
            for (let i = 0; i < linhas; i++) {
                tabela += '<tr>';
                for (let j = 0; j < colunas; j++) {
                    tabela += (i === 0) ? '<th>Coluna ' + (j+1) + '</th>' : '<td>Linha ' + (i+1) + '</td>';
                }
                tabela += '</table>';
            }
            tabela += '</table><br>';
            document.execCommand('insertHTML', false, tabela);
        }
        editorElement.focus();
    }
    
    // Inserir imagem
    function inserirImagemEditor() {
        $('#imagemModal').modal('show');
    }
    
    function inserirImagemUrlEditor() {
        let url = document.getElementById('imagemUrl').value;
        let alt = document.getElementById('imagemAlt').value;
        if (url) {
            salvarEstadoEditor();
            document.execCommand('insertHTML', false, `<img src="${url}" alt="${alt}" style="max-width:100%;">`);
            $('#imagemModal').modal('hide');
            document.getElementById('imagemUrl').value = '';
            document.getElementById('imagemAlt').value = '';
        }
    }
    
    // Inserir link
    function inserirLinkEditor() {
        let textoSelecionado = window.getSelection().toString();
        if (textoSelecionado) {
            document.getElementById('linkTexto').value = textoSelecionado;
        }
        $('#linkModal').modal('show');
    }
    
    function inserirLinkUrlEditor() {
        let texto = document.getElementById('linkTexto').value;
        let url = document.getElementById('linkUrl').value;
        if (texto && url) {
            salvarEstadoEditor();
            document.execCommand('insertHTML', false, `<a href="${url}" target="_blank">${texto}</a>`);
            $('#linkModal').modal('hide');
            document.getElementById('linkTexto').value = '';
            document.getElementById('linkUrl').value = '';
        }
    }
    
    // Inserir citação
    function inserirCitacaoEditor() {
        salvarEstadoEditor();
        document.execCommand('formatBlock', false, 'blockquote');
        editorElement.focus();
    }
    
    // Inserir código
    function inserirCodigoEditor() {
        let codigo = prompt('Digite o código:', 'console.log("Hello World");');
        if (codigo) {
            salvarEstadoEditor();
            document.execCommand('insertHTML', false, `<pre><code>${codigo}</code></pre>`);
        }
    }
    
    // Fórmulas matemáticas
    function abrirModalFormulaEditor() {
        document.getElementById('formulaInput').value = '';
        document.getElementById('previewFormula').innerHTML = '';
        $('#formulaModal').modal('show');
    }
    
    function inserirTemplateFormula(tipo) {
        let templates = {
            'frac': '\\frac{a}{b}',
            'sqrt': '\\sqrt{x}',
            'sum': '\\sum_{i=1}^{n} x_i',
            'int': '\\int_{a}^{b} f(x) dx',
            'alpha': '\\alpha',
            'rightarrow': '\\rightarrow'
        };
        let input = document.getElementById('formulaInput');
        input.value += templates[tipo];
        previewFormulaEditor();
    }
    
    function previewFormulaEditor() {
        let formula = document.getElementById('formulaInput').value;
        let preview = document.getElementById('previewFormula');
        if (formula) {
            try {
                let html = katex.renderToString(formula, { throwOnError: false });
                preview.innerHTML = html;
            } catch(e) {
                preview.innerHTML = '<span class="text-danger">Erro na fórmula: ' + e.message + '</span>';
            }
        } else {
            preview.innerHTML = '';
        }
    }
    
    function inserirFormulaEditor() {
        let formula = document.getElementById('formulaInput').value;
        if (formula) {
            salvarEstadoEditor();
            try {
                let html = katex.renderToString(formula, { throwOnError: false });
                document.execCommand('insertHTML', false, `<div class="math-display">${html}</div>`);
            } catch(e) {
                document.execCommand('insertHTML', false, `<div class="formula">${formula}</div>`);
            }
            $('#formulaModal').modal('hide');
        }
    }
    
    // Cores
    let cores = ['#000000', '#FF0000', '#00FF00', '#0000FF', '#FFFF00', '#FF00FF', '#00FFFF', '#FFA500', '#800080', '#008000', '#FFC0CB', '#808080', '#FFFFFF', '#f8f9fa', '#e9ecef', '#dee2e6'];
    
    function mostrarSeletorCor(tipo) {
        let panel = document.getElementById('colorPickerPanel');
        let btn = document.getElementById(`btnCor${tipo === 'texto' ? 'Texto' : 'Fundo'}`);
        let rect = btn.getBoundingClientRect();
        
        panel.innerHTML = '';
        cores.forEach(cor => {
            let div = document.createElement('div');
            div.className = 'color-option';
            div.style.backgroundColor = cor;
            div.style.border = cor === '#FFFFFF' ? '1px solid #ddd' : 'none';
            div.onclick = () => {
                if (tipo === 'texto') {
                    document.execCommand('foreColor', false, cor);
                } else {
                    document.execCommand('backColor', false, cor);
                }
                panel.style.display = 'none';
                editorElement.focus();
            };
            panel.appendChild(div);
        });
        
        panel.style.display = 'grid';
        panel.style.top = rect.bottom + window.scrollY + 'px';
        panel.style.left = rect.left + 'px';
        
        setTimeout(() => {
            document.addEventListener('click', function fechar(e) {
                if (!panel.contains(e.target) && e.target !== btn) {
                    panel.style.display = 'none';
                    document.removeEventListener('click', fechar);
                }
            });
        }, 100);
    }
    
    // Alternativas
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
            const container = document.getElementById('alternativas_container');
            if (container.children.length === 0) {
                for (let i = 0; i < 4; i++) {
                    adicionarAlternativa();
                }
            }
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
    
    // Editar e excluir questão
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
    
    // Antes de enviar o formulário, copiar o conteúdo do editor para o campo hidden
    function antesDeEnviar() {
        document.getElementById('enunciado_original').value = editorElement.innerHTML;
        return true;
    }
    
    // Upload de imagem
    document.getElementById('imagemUpload').addEventListener('change', function(e) {
        let file = e.target.files[0];
        if (file) {
            let reader = new FileReader();
            reader.onload = function(evt) {
                document.getElementById('imagemUrl').value = evt.target.result;
            };
            reader.readAsDataURL(file);
        }
    });
    
    // Preview fórmula ao digitar
    document.getElementById('formulaInput').addEventListener('input', previewFormulaEditor);
    
    // Detectar mudanças no editor
    editorElement.addEventListener('input', function() {
        salvarEstadoEditor();
    });
    
    // Inicializar editor
    salvarEstadoEditor();
    
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
    
    // Inicializar tipo de questão
    mudarTipoQuestao();
</script>
</body>
</html>