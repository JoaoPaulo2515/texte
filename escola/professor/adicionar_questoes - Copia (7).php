<?php
// escola/professor/provas/adicionar_questoes.php - Adicionar Questões à Prova

require_once __DIR__ . '/../../config/database.php';
session_start();

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
                $conn->beginTransaction();
                
                if ($questao_id > 0) {
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
                    
                    $stmt_del = $conn->prepare("DELETE FROM online_provas_alternativas WHERE questao_id = :questao_id");
                    $stmt_del->bindParam(':questao_id', $questao_id);
                    $stmt_del->execute();
                    
                    $questao_nova_id = $questao_id;
                    $sucesso = 'Questão atualizada com sucesso!';
                    
                } else {
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Adicionar Questões - <?php echo htmlspecialchars($prova['titulo']); ?> | Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.0/dist/katex.min.js"></script>
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 20px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 15px;
            }
        }

        /* ============================================
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .page-header h4 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 5px 0 0;
            opacity: 0.85;
            font-size: 0.85rem;
        }

        .btn-outline-secondary {
            border-radius: 30px;
            padding: 8px 20px;
            font-weight: 600;
            border: 2px solid #6c757d;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            transform: translateY(-2px);
            background: #6c757d;
            color: white;
        }

        /* ============================================
           CARDS DE ESTATÍSTICAS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.03);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .stat-card h3 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-card p {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
            margin: 0;
        }

        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 30px;
            padding: 10px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        /* ============================================
           CARD DE FORMULÁRIO
        ============================================ */
        .form-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .form-card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .form-card-header i {
            margin-right: 10px;
        }

        .form-card-body {
            padding: 25px;
        }

        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        /* ============================================
           EDITOR RICH TEXT
        ============================================ */
        .editor-container {
            border: 2px solid #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            background: white;
            transition: all 0.3s ease;
        }

        .editor-container:focus-within {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        .editor-toolbar {
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
            padding: 8px 12px;
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .toolbar-group {
            display: flex;
            gap: 3px;
            border-right: 1px solid #dee2e6;
            padding-right: 8px;
            margin-right: 8px;
        }

        .toolbar-btn {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
            color: #495057;
        }

        .toolbar-btn:hover {
            background: #006B3E;
            border-color: #006B3E;
            color: white;
        }

        .editor-content {
            min-height: 250px;
            padding: 15px;
            outline: none;
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
        }

        .editor-content th {
            background: #f2f2f2;
        }

        .editor-content img {
            max-width: 100%;
            height: auto;
        }

        .math-display {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            margin: 10px 0;
            overflow-x: auto;
        }

        /* ============================================
           ALTERNATIVAS
        ============================================ */
        .alternativas-container {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
        }

        .alternativa-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            padding: 8px;
            background: white;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .alternativa-item:hover {
            border-color: #006B3E;
            box-shadow: 0 2px 8px rgba(0, 107, 62, 0.1);
        }

        .alternativa-item input[type="radio"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
            accent-color: #006B3E;
        }

        .alternativa-texto {
            flex: 1;
        }

        .btn-add-alternativa {
            background: transparent;
            border: 2px dashed #006B3E;
            color: #006B3E;
            border-radius: 12px;
            padding: 10px;
            width: 100%;
            font-weight: 600;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-add-alternativa:hover {
            background: #006B3E;
            color: white;
        }

        .btn-danger-sm {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-danger-sm:hover {
            background: #c82333;
            transform: scale(1.05);
        }

        .btn-success-custom {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-success-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }

        /* ============================================
           LISTA DE QUESTÕES
        ============================================ */
        .questoes-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .questoes-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .questoes-header .badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
        }

        .questoes-list {
            padding: 20px;
        }

        .questao-item {
            background: white;
            border-radius: 16px;
            margin-bottom: 15px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .questao-item:hover {
            border-color: #006B3E;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .questao-header {
            background: #f8f9fa;
            padding: 12px 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .questao-badge {
            background: #006B3E;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .questao-tipo-badge {
            background: #17a2b8;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .questao-pontos {
            background: #28a745;
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .questao-body {
            padding: 20px;
        }

        .alternativas-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
            margin-top: 10px;
        }

        .alternativa-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .btn-editar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
        }

        .btn-editar:hover {
            background: #138496;
            transform: translateY(-1px);
        }

        .btn-excluir {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
        }

        .btn-excluir:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        .drag-handle {
            cursor: move;
            color: #adb5bd;
            font-size: 1.1rem;
        }

        .drag-handle:hover {
            color: #006B3E;
        }

        /* ============================================
           BOTÃO PUBLICAR
        ============================================ */
        .btn-publicar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 12px 35px;
            border-radius: 40px;
            font-weight: 700;
            font-size: 1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-publicar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 107, 62, 0.4);
            color: white;
        }

        /* ============================================
           MODAIS
        ============================================ */
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .color-picker-panel {
            position: absolute;
            background: white;
            border: 1px solid #ddd;
            border-radius: 12px;
            padding: 10px;
            display: none;
            grid-template-columns: repeat(6, 30px);
            gap: 8px;
            z-index: 1000;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.15);
        }

        .color-option {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
            border: 1px solid #ddd;
            transition: all 0.2s ease;
        }

        .color-option:hover {
            transform: scale(1.1);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert-info {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            border-radius: 20px;
            padding: 50px 20px;
            text-align: center;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .editor-toolbar {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            
            .alternativas-grid {
                grid-template-columns: 1fr;
            }
            
            .questao-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .form-card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="fas fa-plus-circle me-2"></i> Adicionar Questões</h4>
                        <p><strong><?php echo htmlspecialchars($prova['titulo']); ?></strong></p>
                        <p class="small mb-0">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?> | 
                            <i class="fas fa-users"></i> Turma: <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                        </p>
                    </div>
                    <div>
                        <a href="listar_provas.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>

            <!-- Cards de Resumo -->
            <div class="stats-grid fade-in">
                <div class="stat-card">
                    <h3 class="text-primary"><?php echo count($questoes); ?></h3>
                    <p>Questões</p>
                </div>
                <div class="stat-card">
                    <h3 class="text-success"><?php echo number_format($total_pontos, 1); ?></h3>
                    <p>Pontos Totais</p>
                </div>
                <div class="stat-card">
                    <h3 class="text-warning"><?php echo $prova['nota_maxima']; ?></h3>
                    <p>Nota Máxima</p>
                </div>
                <div class="stat-card">
                    <a href="previsualizar_prova.php?id=<?php echo $prova_id; ?>" class="btn btn-primary" target="_blank">
                        <i class="fas fa-eye"></i> Pré-visualizar
                    </a>
                </div>
            </div>

            <?php if ($erro): ?>
                <div class="alert alert-danger fade-in"><?php echo $erro; ?></div>
            <?php endif; ?>
            <?php if ($sucesso): ?>
                <div class="alert alert-success fade-in"><?php echo $sucesso; ?></div>
            <?php endif; ?>

            <!-- Formulário de Nova Questão -->
            <div class="form-card fade-in">
                <div class="form-card-header">
                    <i class="fas fa-<?php echo $modo_edicao ? 'edit' : 'plus-circle'; ?>"></i> 
                    <?php echo $modo_edicao ? 'Editar Questão' : 'Nova Questão'; ?>
                </div>
                <div class="form-card-body">
                    <form method="POST" id="formQuestao" onsubmit="return antesDeEnviar()">
                        <input type="hidden" name="action" value="salvar_questao">
                        <input type="hidden" name="questao_id" id="questao_id" value="<?php echo $questao_edit_id ?? 0; ?>">
                        <input type="hidden" name="enunciado" id="enunciado_original">
                        
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-question-circle"></i> Enunciado da Questão</label>
                            <div class="editor-container">
                                <div class="editor-toolbar">
                                    <div class="toolbar-group">
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('bold')" title="Negrito"><i class="fas fa-bold"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('italic')" title="Itálico"><i class="fas fa-italic"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('underline')" title="Sublinhado"><i class="fas fa-underline"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('strikethrough')" title="Tachado"><i class="fas fa-strikethrough"></i></button>
                                    </div>
                                    <div class="toolbar-group">
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyLeft')" title="Esquerda"><i class="fas fa-align-left"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyCenter')" title="Centro"><i class="fas fa-align-center"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyRight')" title="Direita"><i class="fas fa-align-right"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('justifyFull')" title="Justificar"><i class="fas fa-align-justify"></i></button>
                                    </div>
                                    <div class="toolbar-group">
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('insertUnorderedList')" title="Lista"><i class="fas fa-list-ul"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorFormatar('insertOrderedList')" title="Lista numerada"><i class="fas fa-list-ol"></i></button>
                                    </div>
                                    <div class="toolbar-group">
                                        <button type="button" class="toolbar-btn" id="btnCorTexto" onclick="mostrarSeletorCor('texto')" title="Cor do texto"><i class="fas fa-palette"></i></button>
                                        <button type="button" class="toolbar-btn" id="btnCorFundo" onclick="mostrarSeletorCor('fundo')" title="Cor de fundo"><i class="fas fa-highlighter"></i></button>
                                    </div>
                                    <div class="toolbar-group">
                                        <button type="button" class="toolbar-btn" onclick="inserirTabelaEditor()" title="Tabela"><i class="fas fa-table"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="inserirImagemEditor()" title="Imagem"><i class="fas fa-image"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="inserirLinkEditor()" title="Link"><i class="fas fa-link"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="abrirModalFormulaEditor()" title="Fórmula"><i class="fas fa-square-root-alt"></i></button>
                                    </div>
                                    <div class="toolbar-group">
                                        <button type="button" class="toolbar-btn" onclick="editorDesfazer()" title="Desfazer"><i class="fas fa-undo"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="editorRefazer()" title="Refazer"><i class="fas fa-redo"></i></button>
                                        <button type="button" class="toolbar-btn" onclick="limparFormatacaoEditor()" title="Limpar"><i class="fas fa-eraser"></i></button>
                                    </div>
                                </div>
                                <div id="editorContent" class="editor-content" contenteditable="true">
                                    <?php echo htmlspecialchars_decode($questao_edit['enunciado'] ?? '<p>Digite aqui o enunciado da questão...</p>'); ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-tag"></i> Tipo de Questão</label>
                                    <select name="tipo" id="tipo" class="form-select" onchange="mudarTipoQuestao()">
                                        <option value="multipla_escolha" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'multipla_escolha') ? 'selected' : ''; ?>>Múltipla Escolha</option>
                                        <option value="verdadeiro_falso" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'verdadeiro_falso') ? 'selected' : ''; ?>>Verdadeiro ou Falso</option>
                                        <option value="dissertativa" <?php echo (isset($questao_edit['tipo']) && $questao_edit['tipo'] == 'dissertativa') ? 'selected' : ''; ?>>Dissertativa</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-star"></i> Pontuação</label>
                                    <input type="number" name="pontuacao" id="pontuacao" class="form-control" step="0.5" value="<?php echo $questao_edit['pontuacao'] ?? 1.00; ?>" required>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Alternativas -->
                        <div id="alternativas_div">
                            <label class="form-label"><i class="fas fa-list"></i> Alternativas</label>
                            <div class="alternativas-container">
                                <div id="alternativas_container">
                                    <?php 
                                    $num_alt = 4;
                                    if ($modo_edicao && isset($alternativas_edit) && count($alternativas_edit) > 0) {
                                        foreach ($alternativas_edit as $idx => $alt) {
                                            echo '<div class="alternativa-item">';
                                            echo '<input type="radio" name="correta" value="' . $idx . '" ' . ($alt['correta'] ? 'checked' : '') . '>';
                                            echo '<input type="text" name="alternativas[]" class="form-control alternativa-texto" value="' . htmlspecialchars($alt['texto']) . '" placeholder="Digite a alternativa...">';
                                            echo '<button type="button" class="btn-danger-sm" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>';
                                            echo '</div>';
                                        }
                                        $num_alt = count($alternativas_edit);
                                    } else {
                                        for ($i = 0; $i < 4; $i++) {
                                            echo '<div class="alternativa-item">';
                                            echo '<input type="radio" name="correta" value="' . $i . '">';
                                            echo '<input type="text" name="alternativas[]" class="form-control alternativa-texto" placeholder="Digite a alternativa...">';
                                            echo '<button type="button" class="btn-danger-sm" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>';
                                            echo '</div>';
                                        }
                                    }
                                    ?>
                                </div>
                                <button type="button" class="btn-add-alternativa" onclick="adicionarAlternativa()">
                                    <i class="fas fa-plus"></i> Adicionar Alternativa
                                </button>
                            </div>
                        </div>
                        
                        <div class="text-end mt-4">
                            <button type="submit" class="btn-success-custom">
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
            <div class="questoes-card fade-in">
                <div class="questoes-header">
                    <div><i class="fas fa-list"></i> Questões da Prova</div>
                    <div><span class="badge"><?php echo count($questoes); ?> questões | Total: <?php echo number_format($total_pontos, 1); ?> pts</span></div>
                </div>
                <div class="questoes-list">
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
                            <div class="questao-item" data-id="<?php echo $q['id']; ?>">
                                <div class="questao-header">
                                    <div class="d-flex flex-wrap gap-2 align-items-center">
                                        <span class="questao-badge">Q<?php echo $index + 1; ?></span>
                                        <span class="questao-tipo-badge"><?php echo ucfirst(str_replace('_', ' ', $q['tipo'])); ?></span>
                                        <span class="questao-pontos"><i class="fas fa-star"></i> <?php echo $q['pontuacao']; ?> pts</span>
                                    </div>
                                    <div class="d-flex gap-2 align-items-center">
                                        <button type="button" class="btn-editar" onclick="editarQuestao(<?php echo $q['id']; ?>)">
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button type="button" class="btn-excluir" onclick="excluirQuestao(<?php echo $q['id']; ?>)">
                                            <i class="fas fa-trash"></i> Excluir
                                        </button>
                                        <i class="fas fa-grip-vertical drag-handle"></i>
                                        <input type="hidden" name="ordem[<?php echo $q['id']; ?>]" value="<?php echo $q['ordem']; ?>">
                                    </div>
                                </div>
                                <div class="questao-body">
                                    <div class="mb-3"><?php echo htmlspecialchars_decode(substr($q['enunciado'], 0, 200)) . (strlen($q['enunciado']) > 200 ? '...' : ''); ?></div>
                                    <?php if (!empty($alternativas_q)): ?>
                                        <div class="alternativas-grid">
                                            <?php foreach ($alternativas_q as $alt): ?>
                                            <div class="alternativa-badge">
                                                <span class="badge <?php echo $alt['correta'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                    <?php echo $alt['correta'] ? '✓' : '○'; ?>
                                                </span>
                                                <span><?php echo htmlspecialchars(substr($alt['texto'], 0, 50)); ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php elseif ($q['tipo'] == 'dissertativa'): ?>
                                        <p class="text-muted mb-0"><i class="fas fa-pen"></i> Questão dissertativa - resposta livre</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (count($questoes) > 1): ?>
                        <div class="text-center mt-3">
                            <button type="submit" class="btn btn-primary" form="formReordenar">
                                <i class="fas fa-save"></i> Salvar Ordem
                            </button>
                        </div>
                        <?php endif; ?>
                    </form>
                    
                    <?php if (empty($questoes)): ?>
                        <div class="alert-info">
                            <i class="fas fa-info-circle fa-3x mb-3" style="color: #006B3E; opacity: 0.5;"></i>
                            <h5>Nenhuma questão adicionada ainda</h5>
                            <p>Comece criando uma questão acima!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Botão Publicar -->
            <?php if (!empty($questoes)): ?>
            <div class="text-center mt-4 fade-in">
                <a href="publicar_prova.php?id=<?php echo $prova_id; ?>" class="btn-publicar" onclick="return confirm('Tem certeza que deseja publicar esta prova? Após publicada, os alunos poderão visualizá-la.')">
                    <i class="fas fa-check-circle"></i> Publicar Prova
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modais -->
    <div class="modal fade" id="formulaModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-square-root-alt"></i> Inserir Fórmula Matemática</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Expressão LaTeX</label>
                        <textarea id="formulaInput" class="form-control" rows="3" placeholder="Ex: E = mc^2"></textarea>
                        <small class="text-muted">Use sintaxe LaTeX. Ex: \frac{a}{b}, \sqrt{x}, \sum_{i=1}^{n} x_i</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pré-visualização</label>
                        <div id="previewFormula" class="p-3 bg-light rounded text-center"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" onclick="inserirFormulaEditor()">Inserir</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="imagemModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
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

    <div class="modal fade" id="linkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
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
        
        function inserirTabelaEditor() {
            salvarEstadoEditor();
            let linhas = prompt('Número de linhas:', '3');
            let colunas = prompt('Número de colunas:', '3');
            if (linhas && colunas) {
                let tabela = '<table class="table table-bordered"><thead><tr>';
                for (let j = 0; j < colunas; j++) {
                    tabela += '<th>Coluna ' + (j+1) + '</th>';
                }
                tabela += '</tr></thead><tbody>';
                for (let i = 1; i < linhas; i++) {
                    tabela += '<tr>';
                    for (let j = 0; j < colunas; j++) {
                        tabela += '<td>Linha ' + (i+1) + '</td>';
                    }
                    tabela += '</tr>';
                }
                tabela += '</tbody></table><br>';
                document.execCommand('insertHTML', false, tabela);
            }
            editorElement.focus();
        }
        
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
        
        function abrirModalFormulaEditor() {
            document.getElementById('formulaInput').value = '';
            document.getElementById('previewFormula').innerHTML = '';
            $('#formulaModal').modal('show');
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
        
        let contadorAlternativas = <?php echo $num_alt ?? 4; ?>;
        
        function adicionarAlternativa() {
            const container = document.getElementById('alternativas_container');
            const newDiv = document.createElement('div');
            newDiv.className = 'alternativa-item';
            newDiv.innerHTML = `
                <input type="radio" name="correta" value="${contadorAlternativas}">
                <input type="text" name="alternativas[]" class="form-control alternativa-texto" placeholder="Digite a alternativa...">
                <button type="button" class="btn-danger-sm" onclick="removerAlternativa(this)"><i class="fas fa-trash"></i></button>
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
                        <input type="text" name="alternativas[]" class="form-control alternativa-texto" value="Verdadeiro" readonly style="background:#e9ecef;">
                        <button type="button" class="btn-danger-sm" onclick="removerAlternativa(this)" style="visibility: hidden;"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="alternativa-item">
                        <input type="radio" name="correta" value="1">
                        <input type="text" name="alternativas[]" class="form-control alternativa-texto" value="Falso" readonly style="background:#e9ecef;">
                        <button type="button" class="btn-danger-sm" onclick="removerAlternativa(this)" style="visibility: hidden;"><i class="fas fa-trash"></i></button>
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
        
        function antesDeEnviar() {
            document.getElementById('enunciado_original').value = editorElement.innerHTML;
            return true;
        }
        
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
        
        document.getElementById('formulaInput').addEventListener('input', previewFormulaEditor);
        
        editorElement.addEventListener('input', function() {
            salvarEstadoEditor();
        });
        
        salvarEstadoEditor();
        
        const list = document.getElementById('questoes-list');
        if (list) {
            new Sortable(list, {
                handle: '.drag-handle',
                animation: 150,
                onEnd: function() {
                    const items = document.querySelectorAll('#questoes-list .questao-item');
                    items.forEach((item, index) => {
                        const input = item.querySelector('input[name^="ordem"]');
                        if (input) input.value = index + 1;
                    });
                }
            });
        }
        
        mudarTipoQuestao();
        
        // Animações
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.form-card, .questoes-card, .stat-card').forEach(card => {
            card.classList.remove('fade-in');
            observer.observe(card);
        });
    </script>
</body>
</html>