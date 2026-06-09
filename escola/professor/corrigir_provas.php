<?php
// escola/professor/corrigir_provas.php - Corrigir Provas Online

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// FILTROS
// ============================================
$prova_id = isset($_GET['prova_id']) ? (int)$_GET['prova_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$status = isset($_GET['status']) ? $_GET['status'] : 'pendentes';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT t.id, t.nome, t.ano, t.turno
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano DESC, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PROVAS DISPONÍVEIS PARA CORREÇÃO
// ============================================
$sql_provas = "
    SELECT 
        p.id,
        p.titulo,
        p.tipo,
        p.data_inicio,
        p.data_fim,
        p.status as prova_status,
        d.id as disciplina_id,
        d.nome as disciplina_nome,
        t.id as turma_id,
        t.nome as turma_nome,
        t.ano as turma_ano,
        (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as total_tentativas,
        (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada' AND corrigida = 0) as pendentes_corrigir
    FROM online_provas p
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    INNER JOIN turmas t ON t.id = p.turma_id
    WHERE p.professor_id = :professor_id
    AND p.escola_id = :escola_id
    AND p.status IN ('finalizada', 'realizada')
";

if ($prova_id > 0) {
    $sql_provas .= " AND p.id = :prova_id";
}
if ($turma_id > 0) {
    $sql_provas .= " AND p.turma_id = :turma_id";
}
if ($disciplina_id > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}

$sql_provas .= " ORDER BY p.data_fim DESC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
];
if ($prova_id > 0) $params[':prova_id'] = $prova_id;
if ($turma_id > 0) $params[':turma_id'] = $turma_id;
if ($disciplina_id > 0) $params[':disciplina_id'] = $disciplina_id;
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TENTATIVAS PARA CORREÇÃO
// ============================================
$tentativas = [];
if ($prova_id > 0) {
    $sql_tentativas = "
        SELECT 
            t.id as tentativa_id,
            t.tentativa_numero,
            t.data_entrega,
            t.tempo_gasto_segundos,
            t.pontuacao_total,
            t.porcentagem,
            t.status,
            t.corrigida,
            e.id as aluno_id,
            e.nome as aluno_nome,
            e.matricula as aluno_matricula,
            e.foto
        FROM online_provas_tentativas t
        INNER JOIN estudantes e ON e.id = t.aluno_id
        WHERE t.prova_id = :prova_id
    ";
    
    if ($status == 'pendentes') {
        $sql_tentativas .= " AND t.corrigida = 0";
    } elseif ($status == 'corrigidas') {
        $sql_tentativas .= " AND t.corrigida = 1";
    }
    
    $sql_tentativas .= " ORDER BY t.data_entrega ASC";
    
    $stmt_tentativas = $conn->prepare($sql_tentativas);
    $stmt_tentativas->execute([':prova_id' => $prova_id]);
    $tentativas = $stmt_tentativas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// PROCESSAR CORREÇÃO
// ============================================
$mensagem = '';
$tipo_mensagem = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $tentativa_id = (int)$_POST['tentativa_id'];
    $nota = (float)$_POST['nota'];
    $comentario = trim($_POST['comentario'] ?? '');
    
    // Buscar dados da prova
    $sql_prova_info = "SELECT nota_maxima, nota_minima_aprovacao FROM online_provas WHERE id = (SELECT prova_id FROM online_provas_tentativas WHERE id = :tentativa_id)";
    $stmt_prova_info = $conn->prepare($sql_prova_info);
    $stmt_prova_info->execute([':tentativa_id' => $tentativa_id]);
    $prova_info = $stmt_prova_info->fetch(PDO::FETCH_ASSOC);
    
    $nota_maxima = $prova_info['nota_maxima'] ?? 20;
    $nota_minima = $prova_info['nota_minima_aprovacao'] ?? 10;
    
    // Validar nota
    if ($nota < 0) $nota = 0;
    if ($nota > $nota_maxima) $nota = $nota_maxima;
    
    $porcentagem = ($nota / $nota_maxima) * 100;
    $aprovado = ($nota >= $nota_minima) ? 1 : 0;
    
    try {
        $sql_update = "UPDATE online_provas_tentativas 
                       SET pontuacao_total = :nota, 
                           porcentagem = :porcentagem,
                           aprovado = :aprovado,
                           corrigida = 1,
                           comentario = :comentario,
                           created_at = NOW()
                       WHERE id = :tentativa_id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([
            ':nota' => $nota,
            ':porcentagem' => $porcentagem,
            ':aprovado' => $aprovado,
            ':comentario' => $comentario,
            ':tentativa_id' => $tentativa_id
        ]);
        
        $mensagem = 'Prova corrigida com sucesso!';
        $tipo_mensagem = 'success';
        
        // Recarregar tentativas
        $stmt_tentativas->execute([':prova_id' => $prova_id]);
        $tentativas = $stmt_tentativas->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $mensagem = 'Erro ao corrigir prova: ' . $e->getMessage();
        $tipo_mensagem = 'danger';
    }
}

// ============================================
// BUSCAR DETALHES DA PROVA SELECIONADA
// ============================================
$prova_selecionada = null;
if ($prova_id > 0) {
    foreach ($provas as $p) {
        if ($p['id'] == $prova_id) {
            $prova_selecionada = $p;
            break;
        }
    }
}

$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];

// ============================================
// PROCESSAR SALVAR CORREÇÃO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'salvar_correcao') {
    
    // Forçar cabeçalho JSON
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $tentativa_id = isset($_POST['tentativa_id']) ? (int)$_POST['tentativa_id'] : 0;
        $nota = isset($_POST['nota']) ? (float)$_POST['nota'] : 0;
        $comentario = isset($_POST['comentario']) ? trim($_POST['comentario']) : '';
        
        if ($tentativa_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID da tentativa inválido.']);
            exit;
        }
        
        // Verificar se a tentativa existe e pertence ao professor
        $sql_check = "SELECT t.id, p.nota_maxima, p.nota_minima_aprovacao 
                      FROM online_provas_tentativas t
                      JOIN online_provas p ON p.id = t.prova_id
                      WHERE t.id = :tentativa_id AND p.professor_id = :funcionario_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':tentativa_id' => $tentativa_id,
            ':funcionario_id' => $funcionario_id
        ]);
        $prova_data = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if (!$prova_data) {
            echo json_encode(['success' => false, 'message' => 'Tentativa não encontrada ou sem permissão.']);
            exit;
        }
        
        $nota_maxima = $prova_data['nota_maxima'];
        $nota_minima = $prova_data['nota_minima_aprovacao'];
        
        // Validar nota
        if ($nota < 0) $nota = 0;
        if ($nota > $nota_maxima) $nota = $nota_maxima;
        
        $porcentagem = ($nota / $nota_maxima) * 100;
        $aprovado = ($nota >= $nota_minima) ? 1 : 0;
        
        // Atualizar
        $sql_update = "UPDATE online_provas_tentativas 
                       SET pontuacao_total = :nota,
                           porcentagem = :porcentagem,
                           aprovado = :aprovado,
                           corrigida = 1,
                           comentario = :comentario,
                           created_at = NOW()
                       WHERE id = :tentativa_id";
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([
            ':nota' => $nota,
            ':porcentagem' => $porcentagem,
            ':aprovado' => $aprovado,
            ':comentario' => $comentario,
            ':tentativa_id' => $tentativa_id
        ]);
        
        echo json_encode(['success' => true, 'message' => 'Correção salva com sucesso!']);
        exit;
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro no banco: ' . $e->getMessage()]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
        exit;
    }
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Corrigir Provas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
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
           PAGE HEADER
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
            margin: 8px 0 0;
            opacity: 0.85;
            font-size: 0.9rem;
        }

        /* ============================================
           FILTER CARD
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
        }

        .form-label {
            font-weight: 600;
            font-size: 0.7rem;
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

        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        .btn-outline-secondary {
            border-radius: 12px;
            padding: 10px 20px;
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
           PROVAS CARD
        ============================================ */
        .provas-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .provas-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .prova-item {
            background: white;
            border-radius: 16px;
            margin-bottom: 15px;
            padding: 15px 20px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .prova-item:hover {
            transform: translateX(5px);
            border-color: #006B3E;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .prova-item.active {
            border-left: 4px solid #006B3E;
            background: #f8f9fa;
        }

        .badge-pendente {
            background: #dc3545;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        /* ============================================
           TENTATIVAS CARD
        ============================================ */
        .tentativas-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .tentativa-item {
            background: #f8f9fa;
            border-radius: 16px;
            margin-bottom: 15px;
            padding: 15px;
            border: 1px solid #e9ecef;
            transition: all 0.3s ease;
        }

        .tentativa-item:hover {
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .aluno-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 1.2rem;
        }

        .btn-corrigir {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-corrigir:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        /* ============================================
           MODAL CORREÇÃO
        ============================================ */
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .modal-resposta {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #006B3E;
        }

        .alternativa-aluno {
            padding: 10px;
            margin: 5px 0;
            border-radius: 8px;
        }

        .alternativa-correta {
            background: #d4edda;
            border-left: 3px solid #28a745;
        }

        .alternativa-errada {
            background: #f8d7da;
            border-left: 3px solid #dc3545;
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
            
            .prova-item .row {
                gap: 10px;
            }
            
            .tentativa-item .row {
                gap: 15px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4><i class="fas fa-check-double me-2"></i> Corrigir Provas</h4>
                    <p>Corrija as provas dissertativas dos alunos e atribua notas</p>
                </div>
                <div>
                    <a href="dashboard.php" class="btn btn-outline-light">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                </div>
            </div>
        </div>

        <!-- Filter Card -->
        <div class="filter-card fade-in">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-users"></i> Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><i class="fas fa-book"></i> Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Buscar Provas
                    </button>
                </div>
            </form>
        </div>

        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show fade-in" role="alert">
                <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <?php echo $mensagem; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Coluna de Provas -->
            <div class="col-md-4">
                <div class="provas-card fade-in">
                    <div class="provas-header">
                        <i class="fas fa-list me-2"></i> Provas Disponíveis
                        <span class="badge bg-light text-dark ms-2"><?php echo count($provas); ?></span>
                    </div>
                    <div class="p-3">
                        <?php if (empty($provas)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
                                <p class="text-muted">Nenhuma prova disponível para correção.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($provas as $prova): ?>
                                <div class="prova-item <?php echo $prova_id == $prova['id'] ? 'active' : ''; ?>" onclick="window.location.href='?prova_id=<?php echo $prova['id']; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>'">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <h6 class="mb-0"><?php echo htmlspecialchars($prova['titulo']); ?></h6>
                                        <?php if ($prova['pendentes_corrigir'] > 0): ?>
                                            <span class="badge-pendente"><?php echo $prova['pendentes_corrigir']; ?> pendente(s)</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small text-muted">
                                        <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?> |
                                        <i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª ' . $prova['turma_nome']; ?>
                                    </div>
                                    <div class="small text-muted mt-1">
                                        <i class="fas fa-calendar-alt"></i> Finalizada em: <?php echo date('d/m/Y', strtotime($prova['data_fim'])); ?>
                                    </div>
                                    <div class="mt-2">
                                        <div class="d-flex justify-content-between small">
                                            <span><i class="fas fa-check-circle text-success"></i> Corrigidas: <?php echo $prova['total_tentativas'] - $prova['pendentes_corrigir']; ?></span>
                                            <span><i class="fas fa-hourglass-half text-warning"></i> Pendentes: <?php echo $prova['pendentes_corrigir']; ?></span>
                                        </div>
                                        <div class="progress mt-1" style="height: 4px;">
                                            <?php $percentual = $prova['total_tentativas'] > 0 ? (($prova['total_tentativas'] - $prova['pendentes_corrigir']) / $prova['total_tentativas']) * 100 : 0; ?>
                                            <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%;"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Coluna de Tentativas -->
            <div class="col-md-8">
                <?php if ($prova_id > 0 && $prova_selecionada): ?>
                    <div class="tentativas-card fade-in">
                        <div class="provas-header">
                            <i class="fas fa-users me-2"></i> Respostas dos Alunos
                            <span class="badge bg-light text-dark ms-2">
                                <?php echo $status == 'pendentes' ? 'Pendentes' : ($status == 'corrigidas' ? 'Corrigidas' : 'Todas'); ?>
                            </span>
                            <div class="float-end">
                                <div class="btn-group btn-group-sm">
                                    <a href="?prova_id=<?php echo $prova_id; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=pendentes" 
                                       class="btn btn-outline-light <?php echo $status == 'pendentes' ? 'active' : ''; ?>">
                                        Pendentes
                                    </a>
                                    <a href="?prova_id=<?php echo $prova_id; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=corrigidas" 
                                       class="btn btn-outline-light <?php echo $status == 'corrigidas' ? 'active' : ''; ?>">
                                        Corrigidas
                                    </a>
                                    <a href="?prova_id=<?php echo $prova_id; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=todas" 
                                       class="btn btn-outline-light <?php echo $status == 'todas' ? 'active' : ''; ?>">
                                        Todas
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="p-3">
                            <?php if (empty($tentativas)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h5>Todas as provas estão corrigidas!</h5>
                                    <p class="text-muted">Não há provas pendentes de correção para esta prova.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($tentativas as $tentativa): ?>
                                    <div class="tentativa-item">
                                        <div class="row align-items-center">
                                            <div class="col-md-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <div class="aluno-avatar">
                                                        <?php echo strtoupper(substr($tentativa['aluno_nome'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($tentativa['aluno_nome']); ?></strong>
                                                        <br>
                                                        <small class="text-muted"><?php echo $tentativa['aluno_matricula']; ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">Data de Entrega</small>
                                                <div class="fw-bold"><?php echo date('d/m/Y H:i', strtotime($tentativa['data_entrega'])); ?></div>
                                                <small>Tentativa #<?php echo $tentativa['tentativa_numero']; ?></small>
                                            </div>
                                            <div class="col-md-3">
                                                <small class="text-muted">Status</small>
                                                <div>
                                                    <?php if ($tentativa['corrigida']): ?>
                                                        <span class="badge bg-success"><i class="fas fa-check-circle"></i> Corrigida</span>
                                                        <div class="mt-1">
                                                            <strong>Nota: <?php echo number_format($tentativa['pontuacao_total'], 1); ?></strong>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning"><i class="fas fa-hourglass-half"></i> Aguardando</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="col-md-3 text-end">
                                                <?php if ($tentativa['corrigida']): ?>
                                                    <button class="btn btn-outline-info btn-sm" onclick="verCorrecao(<?php echo $tentativa['tentativa_id']; ?>)">
                                                        <i class="fas fa-eye"></i> Ver Correção
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-corrigir btn-sm" onclick="abrirModalCorrecao(<?php echo $tentativa['tentativa_id']; ?>, <?php echo $prova_id; ?>, '<?php echo addslashes($tentativa['aluno_nome']); ?>')">
                                                        <i class="fas fa-edit"></i> Corrigir Prova
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($prova_id == 0): ?>
                    <div class="tentativas-card fade-in">
                        <div class="provas-header">
                            <i class="fas fa-info-circle me-2"></i> Selecione uma Prova
                        </div>
                        <div class="p-4 text-center">
                            <i class="fas fa-hand-pointer fa-3x text-muted mb-3"></i>
                            <h5>Nenhuma prova selecionada</h5>
                            <p class="text-muted">Selecione uma prova no menu ao lado para começar a corrigir.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal de Correção -->
    <div class="modal fade" id="modalCorrecao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> Corrigir Prova</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalCorrecaoBody">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x text-primary"></i>
                        <p class="mt-2">Carregando respostas do aluno...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="salvarCorrecao()" id="btnSalvarCorrecao">
                        <i class="fas fa-save"></i> Salvar Correção
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
   
   <script>
        let tentativaAtual = null;
        let provaAtual = null;
        
        function abrirModalCorrecao(tentativaId, provaId, alunoNome) {
            tentativaAtual = tentativaId;
            provaAtual = provaId;
            
            $('#modalCorrecaoBody').html('<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Carregando respostas do aluno...</p></div>');
            $('#modalCorrecao').modal('show');
            $('#modalCorrecao .modal-title').html('<i class="fas fa-edit me-2"></i> Corrigir Prova - ' + alunoNome);
            
            $.ajax({
                url: 'ajax_buscar_respostas.php',
                method: 'POST',
                data: { tentativa_id: tentativaId, prova_id: provaId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        let html = `
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> <strong>Aluno:</strong> ${data.aluno_nome} (${data.aluno_matricula})
                                <br><i class="fas fa-clock"></i> <strong>Data de Entrega:</strong> ${data.data_entrega}
                                <br><i class="fas fa-clock"></i> <strong>Tempo Gasto:</strong> ${data.tempo_gasto}
                            </div>
                        `;
                        
                        if (data.respostas && data.respostas.length > 0) {
                            html += `<h6 class="mt-3"><i class="fas fa-question-circle"></i> Respostas do Aluno</h6>`;
                            
                            data.respostas.forEach((resposta, index) => {
                                html += `
                                    <div class="modal-resposta">
                                        <div class="fw-bold mb-2">Questão ${index + 1}: ${resposta.enunciado}</div>
                                        <div class="mt-2">
                                            <strong>Resposta do Aluno:</strong>
                                            <div class="bg-white p-2 rounded mt-1">${resposta.resposta || '<em class="text-muted">Nenhuma resposta fornecida</em>'}</div>
                                        </div>
                                    </div>
                                `;
                            });
                        }
                        
                        html += `
                            <div class="mt-3">
                                <label class="form-label"><strong>Nota (0 - ${data.nota_maxima})</strong></label>
                                <input type="number" step="0.5" min="0" max="${data.nota_maxima}" class="form-control" id="nota_correcao" value="${data.pontuacao_atual || '0'}">
                            </div>
                            <div class="mt-3">
                                <label class="form-label"><strong>Comentários do Professor</strong></label>
                                <textarea class="form-control" rows="3" id="comentario_correcao" placeholder="Adicione comentários sobre a correção...">${data.comentario_atual || ''}</textarea>
                            </div>
                            <input type="hidden" id="tentativa_id" value="${tentativaId}">
                            <input type="hidden" id="prova_id" value="${provaId}">
                        `;
                        
                        $('#modalCorrecaoBody').html(html);
                    } else {
                        $('#modalCorrecaoBody').html('<div class="alert alert-danger">Erro ao carregar respostas: ' + (data.message || 'Erro desconhecido') + '</div>');
                    }
                },
                error: function() {
                    $('#modalCorrecaoBody').html('<div class="alert alert-danger">Erro de conexão. Tente novamente.</div>');
                }
            });
        }
        
        function salvarCorrecao() {
            let nota = $('#nota_correcao').val();
            let comentario = $('#comentario_correcao').val();
            let tentativaId = $('#tentativa_id').val();
            let provaId = $('#prova_id').val();
            
            if (nota === '' || nota < 0) {
                alert('Por favor, insira uma nota válida.');
                return;
            }
            
            $('#btnSalvarCorrecao').html('<i class="fas fa-spinner fa-spin"></i> Salvando...');
            $('#btnSalvarCorrecao').prop('disabled', true);
            
            $.ajax({
                url: window.location.href,
                method: 'POST',
                data: {
                    acao: 'salvar_correcao',
                    tentativa_id: tentativaId,
                    nota: nota,
                    comentario: comentario,
                    prova_id: provaId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#modalCorrecao').modal('hide');
                        location.reload();
                    } else {
                        alert('Erro ao salvar correção: ' + response.message);
                        $('#btnSalvarCorrecao').html('<i class="fas fa-save"></i> Salvar Correção');
                        $('#btnSalvarCorrecao').prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Erro de conexão. Tente novamente.');
                    $('#btnSalvarCorrecao').html('<i class="fas fa-save"></i> Salvar Correção');
                    $('#btnSalvarCorrecao').prop('disabled', false);
                }
            });
        }
        
        function verCorrecao(tentativaId) {
            window.location.href = 'ver_correcao.php?tentativa_id=' + tentativaId;
        }
        
        $(document).ready(function() {
            $('.alert').delay(5000).fadeOut('slow');
        });
    </script>
</body>
</html>