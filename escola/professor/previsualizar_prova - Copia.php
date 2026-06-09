<?php
// escola/professor/provas/previsualizar_prova.php - Pré-visualizar Prova Online

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
    die('Prova não encontrada ou você não tem permissão para visualizá-la.');
}

// Buscar questões da prova
$sql_questoes = "SELECT q.* 
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar alternativas para cada questão
foreach ($questoes as &$questao) {
    $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem ASC";
    $stmt_alt = $conn->prepare($sql_alt);
    $stmt_alt->execute([':questao_id' => $questao['id']]);
    $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
}

$total_pontos = array_sum(array_column($questoes, 'pontuacao'));

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Pré-visualizar Prova - <?php echo htmlspecialchars($prova['titulo']); ?> | Professor</title>
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
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           CONTAINER PRÉ-VISUALIZAÇÃO
        ============================================ */
        .preview-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        /* ============================================
           CABEÇALHO DA PROVA
        ============================================ */
        .prova-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 24px;
            padding: 35px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .prova-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .prova-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 50%;
        }

        .prova-header h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }

        .prova-header p {
            margin-bottom: 8px;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .prova-header .badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 18px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .prova-header small {
            opacity: 0.8;
        }

        /* ============================================
           CARD DE INFORMAÇÕES
        ============================================ */
        .info-card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
            transform: translateY(-2px);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-item {
            text-align: center;
            padding: 20px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 20px;
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .stat-item:hover {
            transform: translateY(-5px);
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .stat-number {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 700;
        }

        .descricao-box, .instrucoes-box {
            border-radius: 18px;
            padding: 18px 22px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .descricao-box {
            background: linear-gradient(135deg, #f0f7ff 0%, #e8f0fe 100%);
            border-left: 4px solid #006B3E;
        }

        .instrucoes-box {
            background: linear-gradient(135deg, #fff8e7 0%, #fff3d4 100%);
            border-left: 4px solid #ffc107;
        }

        .info-detalhes {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            padding: 20px 0;
            border-top: 2px solid #e9ecef;
            margin-top: 15px;
        }

        .info-detalhe-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.85rem;
            color: #495057;
            padding: 8px 15px;
            background: #f8f9fa;
            border-radius: 40px;
            transition: all 0.3s ease;
        }

        .info-detalhe-item:hover {
            background: #e9ecef;
            transform: translateX(3px);
        }

        .info-detalhe-item i {
            width: 22px;
            color: #006B3E;
            font-size: 1rem;
        }

        .config-switches {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            padding: 15px 0 5px;
        }

        .form-check-switch-custom {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 8px 18px;
            background: #f8f9fa;
            border-radius: 40px;
            transition: all 0.3s ease;
        }

        .form-check-switch-custom:hover {
            background: #e9ecef;
        }

        .form-check-input {
            width: 40px;
            height: 20px;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #006B3E;
            border-color: #006B3E;
        }

        .form-check-label {
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
        }

        /* ============================================
           QUESTÕES
        ============================================ */
        .questoes-title {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 25px;
            color: #1A2A6C;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .questoes-title i {
            color: #006B3E;
            font-size: 1.6rem;
        }

        .questao-card {
            background: white;
            border-radius: 24px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .questao-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
        }

        .questao-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            padding: 18px 25px;
            border-bottom: 2px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }

        .badge-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .questao-badge {
            background: linear-gradient(135deg, #006B3E 0%, #008B4A 100%);
            color: white;
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .questao-tipo-badge {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .questao-pontos-badge {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 5px 14px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .questao-body {
            padding: 25px;
        }

        .questao-enunciado {
            font-size: 1rem;
            line-height: 1.7;
            color: #2c3e50;
            margin-bottom: 25px;
            background: #fafbfc;
            padding: 20px;
            border-radius: 16px;
            border-left: 3px solid #006B3E;
        }

        /* ============================================
           ALTERNATIVAS
        ============================================ */
        .alternativas-container {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .alternativa-item {
            padding: 14px 18px;
            border: 2px solid #e9ecef;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .alternativa-item:hover {
            border-color: #006B3E;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            transform: translateX(5px);
        }

        .alternativa-correta {
            background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%);
            border-color: #28a745;
        }

        .alternativa-correta:hover {
            background: linear-gradient(135deg, #c8e6c9 0%, #b9dfbe 100%);
        }

        .alternativa-badge {
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.5px;
            min-width: 90px;
            text-align: center;
        }

        .alternativa-badge-correta {
            background: #28a745;
            color: white;
            box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        }

        .alternativa-badge-normal {
            background: #6c757d;
            color: white;
        }

        .alternativa-texto {
            flex: 1;
            font-size: 0.9rem;
            color: #495057;
            line-height: 1.5;
        }

        /* ============================================
           TIPOS ESPECIAIS DE QUESTÃO
        ============================================ */
        .alert-dissertativa {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border: none;
            border-radius: 16px;
            padding: 20px;
        }

        .alert-dissertativa i {
            font-size: 1.2rem;
            margin-right: 8px;
        }

        .alert-completar {
            background: linear-gradient(135deg, #e3f2fd 0%, #bbdef5 100%);
            border: none;
            border-radius: 16px;
            padding: 20px;
        }

        /* ============================================
           BOTÕES DE AÇÃO
        ============================================ */
        .acoes-container {
            text-align: center;
            margin: 40px 0;
        }

        .btn-custom {
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            margin: 0 8px;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-voltar {
            background: #6c757d;
            color: white;
        }

        .btn-voltar:hover {
            background: #5a6268;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
        }

        .btn-editar {
            background: #17a2b8;
            color: white;
        }

        .btn-editar:hover {
            background: #138496;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
        }

        .btn-publicar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .btn-publicar:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 107, 62, 0.4);
        }

        /* ============================================
           RESUMO DA PROVA (CARD)
        ============================================ */
        .resumo-card {
            background: white;
            border-radius: 24px;
            margin-top: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .resumo-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 18px 28px;
            font-weight: 700;
            font-size: 1.1rem;
        }

        .resumo-body {
            padding: 25px;
        }

        .resumo-table {
            width: 100%;
        }

        .resumo-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
        }

        .resumo-table tr:last-child td {
            border-bottom: none;
        }

        .resumo-table td:first-child {
            font-weight: 700;
            color: #495057;
            width: 220px;
            background: #f8f9fa;
            border-radius: 12px 0 0 12px;
        }

        .resumo-table td:last-child {
            color: #2c3e50;
            font-weight: 500;
        }

        /* ============================================
           ALERTA VAZIO
        ============================================ */
        .empty-alert {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 24px;
            padding: 60px 30px;
            text-align: center;
            border: 2px dashed #006B3E;
        }

        .empty-alert i {
            font-size: 4.5rem;
            color: #006B3E;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-alert h5 {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: #2c3e50;
        }

        .empty-alert p {
            color: #6c757d;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(40px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .animate-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .animate-left {
            animation: slideInLeft 0.5s ease-out;
        }

        .animate-scale {
            animation: scaleIn 0.4s ease-out;
        }

        .questao-card {
            animation: slideInLeft 0.5s ease-out;
            animation-fill-mode: backwards;
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .preview-container {
                max-width: 100%;
                padding: 0;
                margin: 0;
            }
            
            .prova-header {
                background: #006B3E;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            
            .info-card, .questao-card, .resumo-card {
                box-shadow: none;
                border: 1px solid #ddd;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            
            .btn-voltar, .btn-editar, .btn-publicar, .menu-professor, .sidebar, .top-header {
                display: none !important;
            }
            
            .stats-grid {
                break-inside: avoid;
            }
            
            .questao-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .prova-header {
                padding: 25px;
            }
            
            .prova-header h2 {
                font-size: 1.5rem;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .stat-item {
                padding: 15px;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
            
            .info-detalhes {
                gap: 12px;
            }
            
            .info-detalhe-item {
                font-size: 0.75rem;
                padding: 6px 12px;
            }
            
            .resumo-table td:first-child {
                width: 140px;
            }
            
            .acoes-container .btn-custom {
                margin: 5px;
                padding: 10px 20px;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .questao-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .badge-group {
                width: 100%;
            }
            
            .info-detalhe-item {
                width: 100%;
            }
            
            .config-switches {
                flex-direction: column;
            }
            
            .form-check-switch-custom {
                width: 100%;
                justify-content: space-between;
            }
            
            .resumo-table td {
                display: block;
                width: 100%;
            }
            
            .resumo-table td:first-child {
                border-radius: 12px 12px 0 0;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="preview-container">
            <!-- Cabeçalho da Prova -->
            <div class="prova-header animate-up">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div class="flex-grow-1">
                        <h2><i class="fas fa-file-alt me-3"></i><?php echo htmlspecialchars($prova['titulo']); ?></h2>
                        <p><i class="fas fa-book me-2"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?></p>
                        <p><i class="fas fa-users me-2"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></p>
                    </div>
                    <div class="text-end">
                        <span class="badge d-block mb-2"><i class="fas fa-tag me-1"></i> <?php echo ucfirst($prova['tipo']); ?></span>
                        <small><i class="fas fa-clock me-1"></i> Duração: <?php echo $prova['duracao_minutos']; ?> min</small>
                    </div>
                </div>
            </div>
            
            <!-- Informações da Prova -->
            <div class="info-card animate-up">
                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number text-primary"><?php echo count($questoes); ?></div>
                        <div class="stat-label">Questões</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number text-success"><?php echo number_format($total_pontos, 1); ?></div>
                        <div class="stat-label">Pontos Totais</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number text-warning"><?php echo $prova['nota_maxima']; ?></div>
                        <div class="stat-label">Nota Máxima</div>
                    </div>
                </div>
                
                <?php if ($prova['descricao']): ?>
                <div class="descricao-box">
                    <strong><i class="fas fa-info-circle text-primary me-2"></i> Descrição:</strong>
                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($prova['descricao'])); ?></p>
                </div>
                <?php endif; ?>
                
                <?php if ($prova['instrucoes']): ?>
                <div class="instrucoes-box">
                    <strong><i class="fas fa-exclamation-triangle text-warning me-2"></i> Instruções:</strong>
                    <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($prova['instrucoes'])); ?></p>
                </div>
                <?php endif; ?>
                
                <div class="info-detalhes">
                    <div class="info-detalhe-item">
                        <i class="fas fa-calendar-alt"></i>
                        <span><strong>Início:</strong> <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></span>
                    </div>
                    <div class="info-detalhe-item">
                        <i class="fas fa-calendar-times"></i>
                        <span><strong>Término:</strong> <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></span>
                    </div>
                    <div class="info-detalhe-item">
                        <i class="fas fa-repeat"></i>
                        <span><strong>Tentativas:</strong> <?php echo $prova['tentativas_permitidas']; ?></span>
                    </div>
                    <div class="info-detalhe-item">
                        <i class="fas fa-star"></i>
                        <span><strong>Aprovação:</strong> <?php echo $prova['nota_minima_aprovacao']; ?> pontos</span>
                    </div>
                </div>
                
                <div class="config-switches">
                    <div class="form-check-switch-custom">
                        <input class="form-check-input" type="checkbox" id="embaralhar_questoes" <?php echo $prova['embaralhar_questoes'] ? 'checked' : ''; ?> disabled>
                        <label class="form-check-label" for="embaralhar_questoes">🎲 Embaralhar questões</label>
                    </div>
                    <div class="form-check-switch-custom">
                        <input class="form-check-input" type="checkbox" id="embaralhar_alternativas" <?php echo $prova['embaralhar_alternativas'] ? 'checked' : ''; ?> disabled>
                        <label class="form-check-label" for="embaralhar_alternativas">🔄 Embaralhar alternativas</label>
                    </div>
                    <div class="form-check-switch-custom">
                        <input class="form-check-input" type="checkbox" id="mostrar_gabarito" <?php echo $prova['mostrar_gabarito'] ? 'checked' : ''; ?> disabled>
                        <label class="form-check-label" for="mostrar_gabarito">📖 Mostrar gabarito após correção</label>
                    </div>
                </div>
            </div>
            
            <!-- Questões -->
            <div class="questoes-title animate-up">
                <i class="fas fa-question-circle"></i>
                <span>Questões da Prova</span>
                <div class="flex-grow-1"></div>
                <span class="badge bg-primary rounded-pill"><?php echo count($questoes); ?> questões</span>
            </div>
            
            <?php if (empty($questoes)): ?>
                <div class="empty-alert animate-scale">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h5>Nenhuma questão adicionada ainda</h5>
                    <p>Adicione questões para completar sua prova.</p>
                    <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn btn-primary mt-3">
                        <i class="fas fa-plus"></i> Adicionar Questões
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($questoes as $index => $questao): ?>
                <div class="questao-card" style="animation-delay: <?php echo $index * 0.08; ?>s">
                    <div class="questao-header">
                        <div class="badge-group">
                            <span class="questao-badge"><i class="fas fa-hashtag me-1"></i> Questão <?php echo $index + 1; ?></span>
                            <span class="questao-tipo-badge"><i class="fas fa-tag me-1"></i> <?php echo ucfirst(str_replace('_', ' ', $questao['tipo'])); ?></span>
                            <span class="questao-pontos-badge"><i class="fas fa-star me-1"></i> <?php echo $questao['pontuacao']; ?> pontos</span>
                        </div>
                    </div>
                    <div class="questao-body">
                        <div class="questao-enunciado">
                            <?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?>
                        </div>
                        
                        <?php if ($questao['tipo'] == 'multipla_escolha' && !empty($questao['alternativas'])): ?>
                            <div class="alternativas-container">
                                <?php foreach ($questao['alternativas'] as $alt): ?>
                                <div class="alternativa-item <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                                    <span class="alternativa-badge <?php echo $alt['correta'] ? 'alternativa-badge-correta' : 'alternativa-badge-normal'; ?>">
                                        <?php echo $alt['correta'] ? '<i class="fas fa-check-circle"></i> Correta' : '<i class="fas fa-circle"></i> Alternativa'; ?>
                                    </span>
                                    <span class="alternativa-texto"><?php echo htmlspecialchars($alt['texto']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                        <?php elseif ($questao['tipo'] == 'verdadeiro_falso' && !empty($questao['alternativas'])): ?>
                            <div class="alternativas-container">
                                <?php foreach ($questao['alternativas'] as $alt): ?>
                                <div class="alternativa-item <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                                    <span class="alternativa-badge <?php echo $alt['correta'] ? 'alternativa-badge-correta' : 'alternativa-badge-normal'; ?>">
                                        <?php echo $alt['correta'] ? '<i class="fas fa-check-circle"></i> Resposta Correta' : '<i class="fas fa-circle"></i> Alternativa'; ?>
                                    </span>
                                    <span class="alternativa-texto"><?php echo htmlspecialchars($alt['texto']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                        <?php elseif ($questao['tipo'] == 'dissertativa'): ?>
                            <div class="alert-dissertativa">
                                <i class="fas fa-pen"></i> <strong>Questão dissertativa</strong>
                                <p class="mb-2 mt-2">O aluno deverá escrever uma resposta textual.</p>
                                <div class="mt-3">
                                    <label class="form-label fw-bold">📝 Resposta esperada (gabarito):</label>
                                    <textarea class="form-control" rows="3" placeholder="Resposta do aluno..." disabled style="background: #f8f9fa; border-radius: 12px;"></textarea>
                                    <small class="text-muted mt-2 d-block">A resposta será corrigida pelo professor manualmente.</small>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Botões de Ação -->
            <div class="acoes-container animate-up">
                <button class="btn-custom btn-voltar" onclick="window.location.href='adicionar_questoes.php?id=<?php echo $prova_id; ?>'">
                    <i class="fas fa-arrow-left"></i> Voltar para Edição
                </button>
                <button class="btn-custom btn-editar" onclick="window.print();">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <?php if (!empty($questoes)): ?>
                <a href="publicar_prova.php?id=<?php echo $prova_id; ?>" class="btn-custom btn-publicar" onclick="return confirm('Tem certeza que deseja publicar esta prova? Após publicada, os alunos poderão visualizá-la.')">
                    <i class="fas fa-check-circle"></i> Publicar Prova
                </a>
                <?php endif; ?>
            </div>
            
            <!-- Resumo para o Professor -->
            <div class="resumo-card animate-up">
                <div class="resumo-header">
                    <i class="fas fa-chart-line me-2"></i> Resumo da Prova
                </div>
                <div class="resumo-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="resumo-table">
                                <tr><td><i class="fas fa-tasks me-2"></i> Total de Questões:</td><td><strong><?php echo count($questoes); ?></strong></td</tr>
                                <tr><td><i class="fas fa-star me-2"></i> Pontuação Total:</td><td><strong><?php echo number_format($total_pontos, 1); ?> pontos</strong></td</tr>
                                <tr><td><i class="fas fa-trophy me-2"></i> Nota Máxima:</td><td><strong><?php echo $prova['nota_maxima']; ?></strong></td</tr>
                                <tr><td><i class="fas fa-graduation-cap me-2"></i> Nota Mínima para Aprovação:</td><td><strong><?php echo $prova['nota_minima_aprovacao']; ?></strong></td</tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="resumo-table">
                                <tr><td><i class="fas fa-hourglass-half me-2"></i> Duração:</td><td><strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></td</tr>
                                <tr><td><i class="fas fa-redo-alt me-2"></i> Tentativas Permitidas:</td><td><strong><?php echo $prova['tentativas_permitidas']; ?></strong></td</tr>
                                <tr><td><i class="fas fa-calendar-plus me-2"></i> Data de Início:</td><td><strong><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></strong></td</tr>
                                <tr><td><i class="fas fa-calendar-minus me-2"></i> Data de Término:</td><td><strong><?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></strong></td</tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Adicionar animações suaves ao scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.info-card, .questao-card, .resumo-card').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'all 0.6s ease-out';
            observer.observe(el);
        });
    </script>
</body>
</html>