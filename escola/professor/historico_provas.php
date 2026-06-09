<?php
// escola/professor/provas/historico_provas.php - Histórico de Provas do Professor

require_once __DIR__ . '/../../config/database.php';
session_start();

$db = Database::getInstance();
$conn = $db->getConnection();
$professor_id = $_SESSION['professor_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['professor_nome'] ?? 'Professor';

// Definir título da página
$titulo_pagina = 'Histórico de Provas';

// Filtros
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ==============================================
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome 
                    FROM disciplinas d
                    JOIN professor_disciplina_turma pd ON pd.disciplina_id = d.id
                    WHERE pd.professor_id = :professor_id AND d.escola_id = :escola_id
                    ORDER BY d.nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id, ':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR TURMAS DO PROFESSOR
// ==============================================
$sql_turmas = "SELECT DISTINCT t.id, t.nome, t.ano 
               FROM turmas t
               JOIN professor_disciplina_turma pt ON pt.turma_id = t.id
               WHERE pt.professor_id = :professor_id AND t.escola_id = :escola_id
               ORDER BY t.ano DESC, t.nome ASC";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id, ':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR HISTÓRICO DE PROVAS
// ==============================================
$sql_provas = "SELECT 
                    p.id,
                    p.titulo,
                    p.descricao,
                    p.tipo,
                    p.duracao_minutos,
                    p.data_inicio,
                    p.data_fim,
                    p.tentativas_permitidas,
                    p.nota_maxima,
                    p.nota_minima_aprovacao,
                    p.status,
                    p.created_at,
                    d.id as disciplina_id,
                    d.nome as disciplina_nome,
                    t.id as turma_id,
                    t.nome as turma_nome,
                    t.ano as turma_ano,
                    (SELECT COUNT(*) FROM online_provas_questoes WHERE prova_id = p.id) as total_questoes,
                    (SELECT COUNT(DISTINCT aluno_id) FROM online_provas_tentativas WHERE prova_id = p.id) as total_alunos,
                    (SELECT COUNT(*) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as total_finalizadas,
                    (SELECT AVG(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as media_notas,
                    (SELECT MAX(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as maior_nota,
                    (SELECT MIN(pontuacao_total) FROM online_provas_tentativas WHERE prova_id = p.id AND status = 'finalizada') as menor_nota
                FROM online_provas p
                JOIN disciplinas d ON d.id = p.disciplina_id
                JOIN turmas t ON t.id = p.turma_id
                WHERE p.professor_id = :professor_id 
                AND p.escola_id = :escola_id";

if ($disciplina_filtro > 0) {
    $sql_provas .= " AND p.disciplina_id = :disciplina_id";
}
if ($turma_filtro > 0) {
    $sql_provas .= " AND p.turma_id = :turma_id";
}
if ($status_filtro != 'todas') {
    $sql_provas .= " AND p.status = :status";
}
if (!empty($busca)) {
    $sql_provas .= " AND (p.titulo LIKE :busca OR p.descricao LIKE :busca)";
}

$sql_provas .= " ORDER BY p.created_at DESC, p.data_inicio DESC";

$stmt_provas = $conn->prepare($sql_provas);
$params = [
    ':professor_id' => $professor_id,
    ':escola_id' => $escola_id
];
if ($disciplina_filtro > 0) $params[':disciplina_id'] = $disciplina_filtro;
if ($turma_filtro > 0) $params[':turma_id'] = $turma_filtro;
if ($status_filtro != 'todas') $params[':status'] = $status_filtro;
if (!empty($busca)) $params[':busca'] = "%$busca%";
$stmt_provas->execute($params);
$provas = $stmt_provas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_provas = count($provas);
$total_ativas = 0;
$total_agendadas = 0;
$total_finalizadas = 0;
$total_canceladas = 0;
$total_alunos_atingidos = 0;
$soma_medias = 0;

foreach ($provas as $prova) {
    if ($prova['status'] == 'em_andamento') $total_ativas++;
    elseif ($prova['status'] == 'agendada') $total_agendadas++;
    elseif ($prova['status'] == 'finalizada') $total_finalizadas++;
    elseif ($prova['status'] == 'cancelada') $total_canceladas++;
    
    $total_alunos_atingidos += $prova['total_alunos'];
    if ($prova['media_notas']) $soma_medias += $prova['media_notas'];
}

$media_geral_notas = $total_finalizadas > 0 ? round($soma_medias / $total_finalizadas, 1) : 0;

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'agendada':
            return '<span class="badge-status badge-agendada"><i class="fas fa-calendar-alt"></i> Agendada</span>';
        case 'em_andamento':
            return '<span class="badge-status badge-ativa"><i class="fas fa-play-circle"></i> Em andamento</span>';
        case 'finalizada':
            return '<span class="badge-status badge-finalizada"><i class="fas fa-check-circle"></i> Finalizada</span>';
        case 'cancelada':
            return '<span class="badge-status badge-cancelada"><i class="fas fa-times-circle"></i> Cancelada</span>';
        default:
            return '<span class="badge-status">' . $status . '</span>';
    }
}

function getTipoProvaLabel($tipo) {
    $tipos = [
        'prova' => '📝 Prova',
        'teste' => '📋 Teste',
        'quiz' => '🎯 Quiz',
        'simulado' => '📚 Simulado'
    ];
    return $tipos[$tipo] ?? ucfirst($tipo);
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function getNotaClass($nota) {
    if ($nota === null) return 'text-secondary';
    if ($nota >= 14) return 'text-success fw-bold';
    if ($nota >= 10) return 'text-warning fw-bold';
    return 'text-danger fw-bold';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $titulo_pagina; ?> | Professor | SIGE Angola</title>
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
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content-professor {
            margin-left: 280px;
            margin-top: 60px;
            padding: 20px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content-professor {
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
            margin: 8px 0 0;
            opacity: 0.85;
            font-size: 0.9rem;
        }

        /* ============================================
           CARDS DE INFORMAÇÃO DO PROFESSOR
        ============================================ */
        .professor-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .professor-card .card-body {
            padding: 20px 25px;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 700;
            color: #333;
            margin-top: 5px;
        }

        /* ============================================
           CARDS DE ESTATÍSTICAS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

        .stat-icon {
            width: 55px;
            height: 55px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
        }

        .stat-icon i {
            font-size: 24px;
            color: #006B3E;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           CARD DE FILTROS
        ============================================ */
        .filter-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
        }

        .filter-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
        }

        .filter-header i {
            margin-right: 10px;
        }

        .filter-body {
            padding: 25px;
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

        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* ============================================
           CARDS DE PROVAS
        ============================================ */
        .prova-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .prova-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .prova-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 15px 25px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .disciplina-badge {
            background: rgba(0, 107, 62, 0.1);
            color: #006B3E;
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .badge-status {
            padding: 5px 15px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-agendada {
            background: #6c757d;
            color: white;
        }

        .badge-ativa {
            background: #28a745;
            color: white;
        }

        .badge-finalizada {
            background: #17a2b8;
            color: white;
        }

        .badge-cancelada {
            background: #dc3545;
            color: white;
        }

        .prova-body {
            padding: 25px;
        }

        .prova-title {
            font-size: 1.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #333;
        }

        .prova-description {
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.8rem;
            color: #555;
        }

        .info-item i {
            width: 22px;
            color: #006B3E;
        }

        .prova-footer {
            background: #f8f9fa;
            padding: 15px 25px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .progress-custom {
            height: 6px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        .btn-detalhes {
            background: #006B3E;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-detalhes:hover {
            background: #004d2e;
            transform: translateY(-1px);
            color: white;
        }

        .btn-resultados {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-resultados:hover {
            background: #138496;
            transform: translateY(-1px);
            color: white;
        }

        .btn-cancelar {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-cancelar:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        /* ============================================
           BOTÃO DE AJUDA FLUTUANTE
        ============================================ */
        .btn-ajuda {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        /* ============================================
           MODAL DE AJUDA
        ============================================ */
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }

        .modal-ajuda.show {
            display: flex;
        }

        .modal-ajuda-content {
            background: white;
            border-radius: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }

        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-ajuda-body {
            padding: 20px;
        }

        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .modal-ajuda-close:hover {
            transform: scale(1.1);
        }

        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }

        .ajuda-item:last-child {
            border-bottom: none;
        }

        .ajuda-titulo {
            font-weight: 700;
            color: #006B3E;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ajuda-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            background: #e8f5e9;
            border-radius: 50%;
            color: #006B3E;
            font-weight: 700;
            font-size: 0.8rem;
        }

        .ajuda-texto {
            color: #666;
            font-size: 0.85rem;
            line-height: 1.5;
            margin-left: 38px;
        }

        /* ============================================
           ALERTA VAZIO
        ============================================ */
        .empty-alert {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border: none;
            border-radius: 20px;
            padding: 50px 20px;
            text-align: center;
        }

        .empty-alert i {
            font-size: 4rem;
            color: #006B3E;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-alert h5 {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 10px;
            color: #333;
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
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 15px;
            }
            
            .prova-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .prova-footer {
                flex-direction: column;
            }
            
            .professor-card .row {
                gap: 15px;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-body .row {
                gap: 10px;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .btn-ajuda, .filter-card, .btn-secondary, .menu-professor {
                display: none !important;
            }
            
            .main-content-professor {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            
            .prova-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

    <div class="modal-ajuda" id="modalAjuda">
        <div class="modal-ajuda-content">
            <div class="modal-ajuda-header">
                <h5 class="mb-0"><i class="fas fa-question-circle me-2"></i> Ajuda - Histórico de Provas</h5>
                <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
            </div>
            <div class="modal-ajuda-body">
                <div class="ajuda-item">
                    <div class="ajuda-titulo">
                        <span class="ajuda-badge">1</span> Sobre esta página
                    </div>
                    <div class="ajuda-texto">Esta página exibe o histórico completo de todas as provas criadas por você, com detalhes e estatísticas.</div>
                </div>
                <div class="ajuda-item">
                    <div class="ajuda-titulo">
                        <span class="ajuda-badge">2</span> Status das Provas
                    </div>
                    <div class="ajuda-texto">
                        <span class="badge-status badge-agendada me-1">Agendada</span> - Prova agendada para futuro<br>
                        <span class="badge-status badge-ativa me-1">Em andamento</span> - Prova disponível para alunos<br>
                        <span class="badge-status badge-finalizada me-1">Finalizada</span> - Prova encerrada<br>
                        <span class="badge-status badge-cancelada me-1">Cancelada</span> - Prova cancelada
                    </div>
                </div>
                <div class="ajuda-item">
                    <div class="ajuda-titulo">
                        <span class="ajuda-badge">3</span> Estatísticas
                    </div>
                    <div class="ajuda-texto">Visualize médias, maior e menor nota, participação dos alunos e percentual de conclusão.</div>
                </div>
                <div class="ajuda-item">
                    <div class="ajuda-titulo">
                        <span class="ajuda-badge">4</span> Ações Disponíveis
                    </div>
                    <div class="ajuda-texto">
                        • <strong>Ver Detalhes</strong> - Informações completas da prova<br>
                        • <strong>Ver Resultados</strong> - Desempenho dos alunos<br>
                        • <strong>Cancelar</strong> - Cancelar prova agendada
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="main-content-professor">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-history me-2"></i> Histórico de Provas</h4>
                <p class="text-muted mb-0">Todas as provas criadas por você</p>
            </div>
            <div>
                <a href="criar_prova.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Nova Prova
                </a>
                <button class="btn btn-secondary ms-2" onclick="window.print();">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Informações do Professor -->
        <div class="professor-card">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="info-label"><i class="fas fa-chalkboard-user me-1"></i> Professor</div>
                        <div class="info-value"><?php echo htmlspecialchars($professor_nome); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-label"><i class="fas fa-book me-1"></i> Disciplinas</div>
                        <div class="info-value"><?php echo count($disciplinas); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-label"><i class="fas fa-users me-1"></i> Turmas</div>
                        <div class="info-value"><?php echo count($turmas); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="info-label"><i class="fas fa-file-alt me-1"></i> Total Provas</div>
                        <div class="info-value"><?php echo $total_provas; ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="stat-value text-secondary"><?php echo $total_agendadas; ?></div>
                <div class="stat-label">Agendadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-play-circle"></i></div>
                <div class="stat-value text-success"><?php echo $total_ativas; ?></div>
                <div class="stat-label">Em andamento</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-value text-info"><?php echo $total_finalizadas; ?></div>
                <div class="stat-label">Finalizadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
                <div class="stat-value text-danger"><?php echo $total_canceladas; ?></div>
                <div class="stat-label">Canceladas</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-users"></i></div>
                <div class="stat-value text-primary"><?php echo $total_alunos_atingidos; ?></div>
                <div class="stat-label">Alunos Atingidos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <div class="stat-value text-warning"><?php echo number_format($media_geral_notas, 1); ?></div>
                <div class="stat-label">Média Geral</div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="filter-header">
                <i class="fas fa-filter"></i> Filtros de Busca
            </div>
            <div class="filter-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label">Disciplina</label>
                        <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">Todas as disciplinas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                            <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disc['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Turma</label>
                        <select name="turma_id" class="form-select" onchange="this.form.submit()">
                            <option value="0">Todas as turmas</option>
                            <?php foreach ($turmas as $tur): ?>
                            <option value="<?php echo $tur['id']; ?>" <?php echo $turma_filtro == $tur['id'] ? 'selected' : ''; ?>>
                                <?php echo $tur['ano'] . 'ª - ' . htmlspecialchars($tur['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="todas">Todas</option>
                            <option value="agendada" <?php echo $status_filtro == 'agendada' ? 'selected' : ''; ?>>Agendadas</option>
                            <option value="em_andamento" <?php echo $status_filtro == 'em_andamento' ? 'selected' : ''; ?>>Em andamento</option>
                            <option value="finalizada" <?php echo $status_filtro == 'finalizada' ? 'selected' : ''; ?>>Finalizadas</option>
                            <option value="cancelada" <?php echo $status_filtro == 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <a href="historico_provas.php" class="btn btn-outline-secondary w-100">
                            <i class="fas fa-eraser"></i> Limpar
                        </a>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Buscar por título ou descrição</label>
                        <input type="text" name="busca" class="form-control" placeholder="Digite o título ou descrição da prova..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Provas -->
        <?php if (empty($provas)): ?>
            <div class="empty-alert fade-in">
                <i class="fas fa-info-circle"></i>
                <h5>Nenhuma prova encontrada</h5>
                <p>Você ainda não criou nenhuma prova ou não há provas com os filtros selecionados.</p>
                <a href="criar_prova.php" class="btn btn-primary mt-2">
                    <i class="fas fa-plus-circle"></i> Criar Nova Prova
                </a>
            </div>
        <?php else: ?>
            <div class="provas-list">
                <?php foreach ($provas as $prova): 
                    $percentual_participacao = $prova['total_alunos'] > 0 ? round(($prova['total_finalizadas'] / $prova['total_alunos']) * 100, 1) : 0;
                    $media_nota_class = getNotaClass($prova['media_notas']);
                    $percentual_media = $prova['nota_maxima'] > 0 ? ($prova['media_notas'] / $prova['nota_maxima']) * 100 : 0;
                ?>
                <div class="prova-card fade-in" id="prova-<?php echo $prova['id']; ?>">
                    <div class="prova-header">
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <span class="disciplina-badge">
                                <i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?>
                            </span>
                            <span class="badge-status bg-secondary">
                                <?php echo getTipoProvaLabel($prova['tipo']); ?>
                            </span>
                            <?php echo getStatusBadge($prova['status']); ?>
                        </div>
                        <div class="text-end">
                            <small class="text-muted">
                                <i class="fas fa-users"></i> Turma: <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="prova-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="prova-title"><?php echo htmlspecialchars($prova['titulo']); ?></h5>
                                <p class="prova-description">
                                    <?php echo nl2br(htmlspecialchars(substr($prova['descricao'] ?? '', 0, 150))); ?>
                                    <?php if (strlen($prova['descricao'] ?? '') > 150) echo '...'; ?>
                                </p>
                                
                                <div class="info-grid">
                                    <div class="info-item">
                                        <i class="fas fa-calendar-alt"></i>
                                        <span>Início: <strong><?php echo formatarData($prova['data_inicio']); ?></strong></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-calendar-times"></i>
                                        <span>Término: <strong><?php echo formatarData($prova['data_fim']); ?></strong></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-clock"></i>
                                        <span>Duração: <strong><?php echo $prova['duracao_minutos']; ?> minutos</strong></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-question-circle"></i>
                                        <span>Questões: <strong><?php echo $prova['total_questoes']; ?></strong></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-star"></i>
                                        <span>Nota Máxima: <strong><?php echo $prova['nota_maxima']; ?></strong></span>
                                    </div>
                                    <div class="info-item">
                                        <i class="fas fa-repeat"></i>
                                        <span>Tentativas: <strong><?php echo $prova['tentativas_permitidas']; ?></strong></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small><i class="fas fa-users"></i> Participação</small>
                                        <small class="fw-bold"><?php echo $percentual_participacao; ?>%</small>
                                    </div>
                                    <div class="progress-custom">
                                        <div class="progress-fill" style="width: <?php echo $percentual_participacao; ?>%; background: #17a2b8;"></div>
                                    </div>
                                    <div class="text-center mt-2">
                                        <small><?php echo $prova['total_finalizadas']; ?> / <?php echo $prova['total_alunos']; ?> alunos concluíram</small>
                                    </div>
                                </div>
                                
                                <?php if ($prova['status'] == 'finalizada' && $prova['media_notas']): ?>
                                <div class="mt-3">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small><i class="fas fa-chart-line"></i> Média das Notas</small>
                                        <small class="<?php echo $media_nota_class; ?>"><?php echo number_format($prova['media_notas'], 1); ?></small>
                                    </div>
                                    <div class="progress-custom">
                                        <div class="progress-fill" style="width: <?php echo $percentual_media; ?>%; background: #006B3E;"></div>
                                    </div>
                                    <div class="row mt-2 text-center">
                                        <div class="col-6">
                                            <small class="text-success">
                                                <i class="fas fa-arrow-up"></i> Maior: <?php echo number_format($prova['maior_nota'], 1); ?>
                                            </small>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-danger">
                                                <i class="fas fa-arrow-down"></i> Menor: <?php echo number_format($prova['menor_nota'], 1); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="prova-footer">
                        <div>
                            <small class="text-muted">
                                <i class="fas fa-calendar-plus"></i> Criada em: <?php echo formatarData($prova['created_at'], 'd/m/Y H:i'); ?>
                            </small>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="detalhes_prova.php?id=<?php echo $prova['id']; ?>" class="btn-detalhes">
                                <i class="fas fa-info-circle"></i> Ver Detalhes
                            </a>
                            <?php if ($prova['status'] == 'finalizada'): ?>
                            <a href="resultados_prova.php?id=<?php echo $prova['id']; ?>" class="btn-resultados">
                                <i class="fas fa-chart-line"></i> Ver Resultados
                            </a>
                            <?php endif; ?>
                            <?php if ($prova['status'] == 'agendada'): ?>
                            <button class="btn-cancelar" onclick="cancelarProva(<?php echo $prova['id']; ?>)">
                                <i class="fas fa-ban"></i> Cancelar
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Botão de ajuda
        const btnAjuda = document.getElementById('btnAjuda');
        const modalAjuda = document.getElementById('modalAjuda');
        const closeAjuda = document.getElementById('closeAjuda');
        
        btnAjuda.addEventListener('click', function() { 
            modalAjuda.classList.add('show'); 
        });
        
        closeAjuda.addEventListener('click', function() { 
            modalAjuda.classList.remove('show'); 
        });
        
        modalAjuda.addEventListener('click', function(e) { 
            if (e.target === modalAjuda) modalAjuda.classList.remove('show'); 
        });
        
        // Função para cancelar prova
        function cancelarProva(id) {
            if (confirm('⚠️ Tem certeza que deseja cancelar esta prova?\n\nEsta ação não pode ser desfeita.')) {
                $.ajax({
                    url: 'cancelar_prova.php',
                    method: 'POST',
                    data: { id: id },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            alert('✅ Prova cancelada com sucesso!');
                            location.reload();
                        } else {
                            alert('❌ Erro: ' + response.message);
                        }
                    },
                    error: function() {
                        alert('❌ Erro ao cancelar prova. Tente novamente.');
                    }
                });
            }
        }
        
        // Auto-submit ao pressionar Enter na busca
        document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.submit();
            }
        });
        
        // Animações ao rolar
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.prova-card, .stat-card, .filter-card, .professor-card').forEach(card => {
            card.classList.remove('fade-in');
            observer.observe(card);
        });
    </script>
</body>
</html>