<?php
// escola/pedagogico/index.php - Dashboard Pedagógico

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO NA TABELA USUARIOS E FUNCIONARIOS
// ============================================
$sql_verifica = "
    SELECT f.*, 
           u.id as usuario_id,
           u.usuario,
           u.email,
           u.tipo as usuario_tipo,
           (SELECT COUNT(*) FROM conselho_nota_permissoes WHERE funcionario_id = f.id AND ativo = 1) as tem_permissao_conselho
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
    AND u.status = 'ativo'
    AND f.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);

if (!$ano_letivo) {
    $sql_ano = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo ORDER BY ano DESC LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute();
    $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
}

$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

// Obter filtros via GET para o gráfico
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 3;
$ano_letivo_filtro = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : $ano_letivo_id;

// Buscar todos os anos letivos para o filtro
$sql_anos_lista = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos_lista = $conn->prepare($sql_anos_lista);
$stmt_anos_lista->execute();
$anos_lista = $stmt_anos_lista->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS GERAIS
// ============================================

// Total de alunos matriculados
$sql_total_alunos = "
    SELECT COUNT(DISTINCT e.id) as total
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id
";
$stmt_total_alunos = $conn->prepare($sql_total_alunos);
$stmt_total_alunos->execute([':ano_letivo_id' => $ano_letivo_id]);
$total_alunos = $stmt_total_alunos->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$sql_total_professores = "
    SELECT COUNT(DISTINCT f.id) as total
    FROM funcionarios f
    WHERE f.escola_id = :escola_id AND f.tipo_funcionario IN ('professor', 'docente')
";
$stmt_total_professores = $conn->prepare($sql_total_professores);
$stmt_total_professores->execute([':escola_id' => $escola_id]);
$total_professores = $stmt_total_professores->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$sql_total_turmas = "
    SELECT COUNT(*) as total
    FROM turmas
    WHERE escola_id = :escola_id
";
$stmt_total_turmas = $conn->prepare($sql_total_turmas);
$stmt_total_turmas->execute([':escola_id' => $escola_id]);
$total_turmas = $stmt_total_turmas->fetch(PDO::FETCH_ASSOC)['total'];

// Total de disciplinas
$sql_total_disciplinas = "
    SELECT COUNT(*) as total
    FROM disciplinas
";
$stmt_total_disciplinas = $conn->prepare($sql_total_disciplinas);
$stmt_total_disciplinas->execute();
$total_disciplinas = $stmt_total_disciplinas->fetch(PDO::FETCH_ASSOC)['total'];

// ============================================
// ESTATÍSTICAS DE APROVEITAMENTO COM FILTROS
// ============================================

// Taxa de aprovação geral com filtros
$sql_aprovacao = "
    SELECT 
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados,
        COUNT(*) as total
    FROM notas n
    WHERE n.bimestre = :bimestre AND n.ano_letivo_id = :ano_letivo_id
";
$stmt_aprovacao = $conn->prepare($sql_aprovacao);
$stmt_aprovacao->execute([
    ':bimestre' => $bimestre_filtro,
    ':ano_letivo_id' => $ano_letivo_filtro
]);
$aprovacao = $stmt_aprovacao->fetch(PDO::FETCH_ASSOC);

$total_notas = $aprovacao['total'] ?: 1;
$percentual_aprovacao = round(($aprovacao['aprovados'] / $total_notas) * 100, 1);
$percentual_recuperacao = round(($aprovacao['recuperacao'] / $total_notas) * 100, 1);
$percentual_reprovacao = round(($aprovacao['reprovados'] / $total_notas) * 100, 1);

// Buscar dados para o gráfico de evolução por bimestre
$sql_evolucao = "
    SELECT 
        n.bimestre,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados,
        COUNT(*) as total
    FROM notas n
    WHERE n.ano_letivo_id = :ano_letivo_id
    GROUP BY n.bimestre
    ORDER BY n.bimestre ASC
";
$stmt_evolucao = $conn->prepare($sql_evolucao);
$stmt_evolucao->execute([':ano_letivo_id' => $ano_letivo_filtro]);
$evolucao = $stmt_evolucao->fetchAll(PDO::FETCH_ASSOC);

// Preparar arrays para o gráfico de evolução
$bimestres_evolucao = [1, 2, 3, 4];
$aprovados_evolucao = [0, 0, 0, 0];
$recuperacao_evolucao = [0, 0, 0, 0];
$reprovados_evolucao = [0, 0, 0, 0];

foreach ($evolucao as $ev) {
    $idx = $ev['bimestre'] - 1;
    $aprovados_evolucao[$idx] = $ev['aprovados'];
    $recuperacao_evolucao[$idx] = $ev['recuperacao'];
    $reprovados_evolucao[$idx] = $ev['reprovados'];
}

// ============================================
// SESSÕES DO CONSELHO ATIVAS
// ============================================
$sql_sessoes_conselho = "
    SELECT 
        cns.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        d.nome as disciplina_nome,
        COUNT(DISTINCT cnp.funcionario_id) as participantes,
        COUNT(DISTINCT cnsol.id) as solicitacoes
    FROM conselho_nota_sessoes cns
    INNER JOIN turmas t ON t.id = cns.turma_id
    INNER JOIN disciplinas d ON d.id = cns.disciplina_id
    LEFT JOIN conselho_nota_participantes cnp ON cnp.sessao_id = cns.id
    LEFT JOIN conselho_nota_solicitacoes cnsol ON cnsol.sessao_id = cns.id AND cnsol.status = 'pendente'
    WHERE cns.ano_letivo_id = :ano_letivo_id AND cns.status IN ('agendado', 'em_andamento')
    GROUP BY cns.id
    ORDER BY cns.data_sessao ASC, cns.hora_inicio ASC
    LIMIT 5
";
$stmt_sessoes_conselho = $conn->prepare($sql_sessoes_conselho);
$stmt_sessoes_conselho->execute([':ano_letivo_id' => $ano_letivo_id]);
$sessoes_conselho = $stmt_sessoes_conselho->fetchAll(PDO::FETCH_ASSOC);
$total_sessoes = count($sessoes_conselho);

// ============================================
// SOLICITAÇÕES PENDENTES DO CONSELHO
// ============================================
$sql_solicitacoes_pendentes = "
    SELECT 
        cnsol.*,
        e.nome as aluno_nome,
        d.nome as disciplina_nome,
        t.nome as turma_nome,
        t.ano as turma_ano,
        COUNT(cnv.id) as total_votos,
        SUM(CASE WHEN cnv.voto = 'favoravel' THEN 1 ELSE 0 END) as votos_favoraveis
    FROM conselho_nota_solicitacoes cnsol
    INNER JOIN estudantes e ON e.id = cnsol.estudante_id
    INNER JOIN disciplinas d ON d.id = cnsol.disciplina_id
    INNER JOIN turmas t ON t.id = cnsol.turma_id
    LEFT JOIN conselho_nota_votos cnv ON cnv.solicitacao_id = cnsol.id
    WHERE cnsol.status IN ('pendente', 'em_votacao')
    AND cnsol.ano_letivo_id = :ano_letivo_id
    GROUP BY cnsol.id
    ORDER BY cnsol.created_at ASC
    LIMIT 10
";
$stmt_solicitacoes_pendentes = $conn->prepare($sql_solicitacoes_pendentes);
$stmt_solicitacoes_pendentes->execute([':ano_letivo_id' => $ano_letivo_id]);
$solicitacoes_pendentes = $stmt_solicitacoes_pendentes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ALUNOS COM MAIOR NÚMERO DE NEGATIVAS
// ============================================
$sql_alunos_negativas = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        t.nome as turma_nome,
        t.ano as turma_ano,
        COUNT(CASE WHEN n.media_final < (CASE WHEN t.ano <= 6 THEN 5 ELSE 10 END) THEN 1 END) as total_negativas,
        AVG(CASE WHEN n.media_final > 0 THEN n.media_final ELSE NULL END) as media_geral
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    INNER JOIN turmas t ON t.id = m.turma_id
    LEFT JOIN notas n ON n.estudante_id = e.id AND n.bimestre = :bimestre AND n.ano_letivo_id = :ano_letivo_id
    WHERE m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id1
    GROUP BY e.id
    HAVING total_negativas > 0
    ORDER BY total_negativas DESC, media_geral ASC
    LIMIT 10
";
$stmt_alunos_negativas = $conn->prepare($sql_alunos_negativas);
$stmt_alunos_negativas->execute([
    ':bimestre' => $bimestre_filtro,
    ':ano_letivo_id' => $ano_letivo_filtro,
    ':ano_letivo_id1' => $ano_letivo_filtro
]);
$alunos_negativas = $stmt_alunos_negativas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// DESEMPENHO POR TURMA
// ============================================
$sql_desempenho_turmas = "
    SELECT 
        t.id,
        t.nome,
        t.ano,
        COUNT(DISTINCT e.id) as total_alunos,
        AVG(CASE WHEN n.media_final > 0 THEN n.media_final ELSE NULL END) as media_turma,
        COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
        COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id
    LEFT JOIN estudantes e ON e.id = m.estudante_id
    LEFT JOIN notas n ON n.estudante_id = e.id AND n.bimestre = :bimestre AND n.ano_letivo_id = :ano_letivo_id1
    WHERE t.escola_id = :escola_id
    GROUP BY t.id
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_desempenho_turmas = $conn->prepare($sql_desempenho_turmas);
$stmt_desempenho_turmas->execute([
    ':ano_letivo_id' => $ano_letivo_filtro,
    ':bimestre' => $bimestre_filtro,
    ':ano_letivo_id1' => $ano_letivo_filtro,
    ':escola_id' => $escola_id
]);
$desempenho_turmas = $stmt_desempenho_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CALENDÁRIO DE EVENTOS PEDAGÓGICOS
// ============================================
$sql_eventos = "
    SELECT 
        ce.id,
        ce.escola_id,
        ce.titulo,
        ce.descricao,
        ce.data_inicio,
        ce.data_fim,
        ce.tipo,
        ce.bimestre,
        ce.cor,
        ce.created_at,
        ce.updated_at,
        DATE(ce.data_inicio) as data_evento,
        TIME(ce.data_inicio) as hora_inicio,
        TIME(ce.data_fim) as hora_fim,
        DATEDIFF(ce.data_inicio, CURDATE()) as dias_restantes,
        CASE 
            WHEN DATEDIFF(ce.data_inicio, CURDATE()) = 0 THEN 'Hoje'
            WHEN DATEDIFF(ce.data_inicio, CURDATE()) = 1 THEN 'Amanhã'
            WHEN DATEDIFF(ce.data_inicio, CURDATE()) BETWEEN 2 AND 7 THEN 'Esta Semana'
            WHEN DATEDIFF(ce.data_inicio, CURDATE()) BETWEEN 8 AND 14 THEN 'Próxima Semana'
            ELSE CONCAT('Daqui ', DATEDIFF(ce.data_inicio, CURDATE()), ' dias')
        END as periodo,
        CASE 
            WHEN ce.tipo = 'reuniao' THEN '<i class=\"fas fa-users\"></i> Reunião'
            WHEN ce.tipo = 'conselho' THEN '<i class=\"fas fa-gavel\"></i> Conselho'
            WHEN ce.tipo = 'prova' THEN '<i class=\"fas fa-pen-alt\"></i> Prova'
            WHEN ce.tipo = 'feriado' THEN '<i class=\"fas fa-calendar-day\"></i> Feriado'
            WHEN ce.tipo = 'evento' THEN '<i class=\"fas fa-calendar-alt\"></i> Evento'
            WHEN ce.tipo = 'workshop' THEN '<i class=\"fas fa-chalkboard-user\"></i> Workshop'
            WHEN ce.tipo = 'formacao' THEN '<i class=\"fas fa-graduation-cap\"></i> Formação'
            ELSE '<i class=\"fas fa-info-circle\"></i> Geral'
        END as tipo_icone,
        IFNULL(ce.cor, 
            CASE 
                WHEN ce.tipo = 'reuniao' THEN '#17a2b8'
                WHEN ce.tipo = 'conselho' THEN '#6f42c1'
                WHEN ce.tipo = 'prova' THEN '#fd7e14'
                WHEN ce.tipo = 'feriado' THEN '#dc3545'
                WHEN ce.tipo = 'evento' THEN '#28a745'
                WHEN ce.tipo = 'workshop' THEN '#ffc107'
                WHEN ce.tipo = 'formacao' THEN '#20c997'
                ELSE '#6c757d'
            END
        ) as cor_evento
    FROM calendario_escolar ce
    WHERE ce.escola_id = :escola_id 
    AND ce.data_inicio >= CURDATE()
    AND (ce.status = 'ativo' OR ce.status IS NULL)
    ORDER BY ce.data_inicio ASC
    LIMIT 5
";
$stmt_eventos = $conn->prepare($sql_eventos);
$stmt_eventos->execute([':escola_id' => $escola_id]);
$eventos = $stmt_eventos->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos para o ranking
$sql_anos_ranking = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos_ranking = $conn->prepare($sql_anos_ranking);
$stmt_anos_ranking->execute();
$anos_ranking = $stmt_anos_ranking->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Dashboard Pedagógico | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --purple: #6f42c1;
            --orange: #fd7e14;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

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

        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '📚';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            position: relative;
            z-index: 1;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            border-left: 4px solid var(--primary-green);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            color: var(--primary-green);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1A2A6C;
        }

        .stat-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .card-custom {
            background: white;
            border-radius: 20px;
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin-bottom: 25px;
        }

        .card-header-custom {
            background: var(--primary-gradient);
            color: white;
            padding: 15px 20px;
            border: none;
        }

        .card-header-custom h5 {
            margin: 0;
            font-weight: 600;
        }

        .full-width-card {
            width: 100%;
            margin-left: 0;
            margin-right: 0;
        }

        .badge-aprovado { background: #d4edda; color: #155724; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-recuperacao { background: #fff3cd; color: #856404; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-reprovado { background: #f8d7da; color: #721c24; padding: 5px 12px; border-radius: 20px; font-size: 12px; }
        .badge-agendado { background: #cfe2ff; color: #084298; padding: 5px 12px; border-radius: 20px; font-size: 12px; }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 10px 24px;
            font-weight: 600;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-primary-custom {
            background: var(--primary-gradient);
            color: white;
            border: none;
            border-radius: 40px;
            padding: 8px 20px;
            font-weight: 600;
            transition: var(--transition);
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
            color: white;
        }

        .filter-group {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .filter-group label {
            font-weight: 600;
            color: #1A2A6C;
            margin-bottom: 5px;
        }

        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }

        .table-custom th {
            background: #f8f9fa;
            padding: 12px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            color: #495057;
        }

        .table-custom td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            vertical-align: middle;
        }

        .table-custom tr:hover {
            background: #f8f9fa;
        }

        .progress {
            height: 8px;
            border-radius: 10px;
        }

        /* Ranking Styles */
        .ranking-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
        }
        .ranking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        .ranking-header {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .ranking-table {
            width: 100%;
        }
        .ranking-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 700;
            color: #1A2A6C;
        }
        .ranking-table td {
            padding: 12px;
            text-align: center;
            border-bottom: 1px solid #ecf0f1;
        }
        .ranking-table tr:hover {
            background: #f8f9fa;
            cursor: pointer;
        }
        .medalha-ouro { color: #ffd700; font-size: 20px; }
        .medalha-prata { color: #c0c0c0; font-size: 18px; }
        .medalha-bronze { color: #cd7f32; font-size: 16px; }
        .media-destaque {
            font-size: 18px;
            font-weight: bold;
            color: #006B3E;
        }

        .foto-aluno-modal {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #006B3E;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .disciplina-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }
        .disciplina-card:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        .nota-item {
            display: inline-block;
            width: 50px;
            text-align: center;
            padding: 4px;
            margin: 2px;
            border-radius: 8px;
            font-weight: bold;
        }
        .nota-alta {
            background: #d5f4e6;
            color: #27ae60;
        }
        .nota-media {
            background: #fef9e7;
            color: #f39c12;
        }
        .nota-baixa {
            background: #fadbd8;
            color: #c0392b;
        }

        .fade-in-up {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity 0.6s ease-out, transform 0.6s ease-out;
        }
        
        .fade-in-up.visible {
            opacity: 1;
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .ranking-table th, .ranking-table td {
                font-size: 11px;
                padding: 8px;
            }
            .stat-value {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
   
<?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-chalkboard-user me-2"></i> Dashboard Pedagógico</h2>
                    <p>Visão geral do desempenho académico e gestão pedagógica</p>
                    <small><i class="fas fa-calendar-alt me-1"></i> Ano Letivo: <?php echo $ano_letivo_ano; ?></small>
                </div>
                <div>
                    <a href="../dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
        </div>

        <!-- Cards de Estatísticas -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Alunos</div>
                            <div class="stat-value"><?php echo number_format($total_alunos, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-user-graduate"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Professores</div>
                            <div class="stat-value"><?php echo number_format($total_professores, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Turmas</div>
                            <div class="stat-value"><?php echo number_format($total_turmas, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-building"></i></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card fade-in-up">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="stat-label">Total de Disciplinas</div>
                            <div class="stat-value"><?php echo number_format($total_disciplinas, 0, ',', '.'); ?></div>
                        </div>
                        <div class="stat-icon"><i class="fas fa-book"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gráfico de Aproveitamento com Filtros -->
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-chart-pie me-2"></i> Aproveitamento Geral</h5>
            </div>
            <div class="card-body p-4">
                <!-- Filtros -->
                <div class="filter-group">
                    <form method="GET" action="" id="formFiltrosAprovacao">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">📅 Ano Letivo</label>
                                <select name="ano_letivo_id" class="form-select">
                                    <?php foreach ($anos_lista as $ano): ?>
                                        <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_filtro == $ano['id']) ? 'selected' : ''; ?>>
                                            <?php echo $ano['ano']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">📊 Bimestre</label>
                                <select name="bimestre" class="form-select">
                                    <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                                    <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                                    <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                                    <option value="4" <?php echo ($bimestre_filtro == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary-custom w-100">
                                    <i class="fas fa-filter"></i> Filtrar
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <canvas id="graficoAprovacao" style="max-height: 250px;"></canvas>
                        <div class="row text-center mt-3">
                            <div class="col-4">
                                <span class="badge-aprovado"><i class="fas fa-check-circle"></i> Aprovados: <?php echo $aprovacao['aprovados']; ?></span>
                            </div>
                            <div class="col-4">
                                <span class="badge-recuperacao"><i class="fas fa-sync-alt"></i> Recuperação: <?php echo $aprovacao['recuperacao']; ?></span>
                            </div>
                            <div class="col-4">
                                <span class="badge-reprovado"><i class="fas fa-times-circle"></i> Reprovados: <?php echo $aprovacao['reprovados']; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <canvas id="graficoEvolucao" style="max-height: 250px;"></canvas>
                        <div class="text-center mt-2">
                            <small class="text-muted">Evolução do desempenho ao longo do ano letivo</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sessões do Conselho -->
        <div class="row">
            <div class="col-md-12">
                <div class="card-custom fade-in-up">
                    <div class="card-header-custom">
                        <h5><i class="fas fa-gavel me-2"></i> Sessões do Conselho de Nota</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($sessoes_conselho)): ?>
                            <div class="text-center p-5 text-muted">
                                <i class="fas fa-calendar-alt fa-3x mb-2"></i>
                                <p>Nenhuma sessão do conselho agendada</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($sessoes_conselho as $sessao): ?>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><i class="fas fa-building me-1"></i> <?php echo $sessao['turma_ano']; ?>ª - <?php echo htmlspecialchars($sessao['turma_nome']); ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-book me-1"></i> <?php echo htmlspecialchars($sessao['disciplina_nome']); ?> |
                                            <i class="fas fa-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($sessao['data_sessao'] . ' ' . $sessao['hora_inicio'])); ?>
                                        </small>
                                    </div>
                                    <div>
                                        <span class="badge <?php echo $sessao['status'] == 'agendado' ? 'badge-agendado' : 'badge-recuperacao'; ?>">
                                            <?php echo $sessao['status'] == 'agendado' ? 'Agendado' : 'Em Andamento'; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Solicitações Pendentes do Conselho -->
        <?php if (!empty($solicitacoes_pendentes)): ?>
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-clock me-2"></i> Solicitações Pendentes de Votação</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Turma</th>
                                <th>Disciplina</th>
                                <th>Nota Atual</th>
                                <th>Nota Sugerida</th>
                                <th>Votos</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitacoes_pendentes as $solic): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($solic['aluno_nome']); ?></strong></td>
                                <td><?php echo $solic['turma_ano']; ?>ª - <?php echo htmlspecialchars($solic['turma_nome']); ?></td>
                                <td><?php echo htmlspecialchars($solic['disciplina_nome']); ?></td>
                                <td><?php echo $solic['nota_atual']; ?></td>
                                <td class="text-warning fw-bold"><?php echo $solic['nota_sugerida']; ?></td>
                                <td>
                                    <span class="text-success">✅ <?php echo $solic['votos_favoraveis']; ?></span> / 
                                    <span class="text-danger">❌ <?php echo $solic['total_votos'] - $solic['votos_favoraveis']; ?></span>
                                </td>
                                <td>
                                    <a href="conselho_nota.php" class="btn btn-primary-custom btn-sm">
                                        <i class="fas fa-gavel"></i> Votar
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alunos com Maior Número de Negativas -->
        <div class="card-custom fade-in-up">
            <div class="card-header-custom">
                <h5><i class="fas fa-exclamation-triangle me-2"></i> Alunos em Risco (Maior Número de Negativas)</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>Turma</th>
                                <th>Disciplinas Negativas</th>
                                <th>Média Geral</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($alunos_negativas)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="fas fa-check-circle text-success fa-2x mb-2"></i>
                                    <p>Nenhum aluno com disciplinas negativas encontrado.</p>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($alunos_negativas as $index => $aluno): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td><?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?></td>
                                    <td><span class="badge-reprovado"><?php echo $aluno['total_negativas']; ?> negativa(s)</span></td>
                                    <td><?php echo number_format($aluno['media_geral'], 1); ?></td>
                                    <td>
                                        <a href="../professor/ver_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-primary-custom btn-sm">
                                            <i class="fas fa-eye"></i> Ver Ficha
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Desempenho por Turma -->
        <div class="card-custom fade-in-up full-width-card">
            <div class="card-header-custom">
                <h5><i class="fas fa-chart-line me-2"></i> Desempenho por Turma - <?php echo $bimestre_filtro; ?>º Bimestre</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>Turma</th>
                                <th>Total Alunos</th>
                                <th>Média Turma</th>
                                <th>Aprovados</th>
                                <th>Recuperação</th>
                                <th>Reprovados</th>
                                <th>Taxa Aprovação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($desempenho_turmas as $turma): 
                                $total = $turma['total_alunos'] ?: 1;
                                $taxa = $turma['total_alunos'] > 0 ? round(($turma['aprovados'] / $turma['total_alunos']) * 100, 1) : 0;
                                $barra_cor = $taxa >= 75 ? 'success' : ($taxa >= 50 ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><strong><?php echo $turma['ano']; ?>ª - <?php echo htmlspecialchars($turma['nome']); ?></strong></td>
                                <td><?php echo $turma['total_alunos']; ?></td>
                                <td><?php echo number_format($turma['media_turma'], 1); ?></td>
                                <td class="text-success"><?php echo $turma['aprovados']; ?></td>
                                <td class="text-warning"><?php echo $turma['recuperacao']; ?></td>
                                <td class="text-danger"><?php echo $turma['reprovados']; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?php echo $taxa; ?>%</span>
                                        <div class="progress flex-grow-1">
                                            <div class="progress-bar bg-<?php echo $barra_cor; ?>" style="width: <?php echo $taxa; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Próximos Eventos Pedagógicos -->
        <div class="card-custom fade-in-up full-width-card">
            <div class="card-header-custom">
                <h5><i class="fas fa-calendar-alt me-2"></i> Próximos Eventos Pedagógicos</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($eventos)): ?>
                    <div class="text-center p-5 text-muted">
                        <i class="fas fa-calendar-day fa-3x mb-2"></i>
                        <p>Nenhum evento agendado</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($eventos as $evento): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center" style="border-left: 4px solid <?php echo $evento['cor_evento']; ?>;">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <?php echo $evento['tipo_icone']; ?>
                                    <strong><?php echo htmlspecialchars($evento['titulo']); ?></strong>
                                    <?php if ($evento['bimestre']): ?>
                                        <span class="badge bg-secondary"><?php echo $evento['bimestre']; ?>º Bimestre</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <i class="fas fa-calendar-day me-1"></i> 
                                        <?php echo date('d/m/Y', strtotime($evento['data_inicio'])); ?>
                                        <?php if ($evento['hora_inicio'] && $evento['hora_inicio'] != '00:00:00'): ?>
                                            <i class="fas fa-clock ms-2 me-1"></i> 
                                            <?php echo date('H:i', strtotime($evento['hora_inicio'])); ?>
                                            <?php if ($evento['hora_fim'] && $evento['hora_fim'] != '00:00:00'): ?>
                                                - <?php echo date('H:i', strtotime($evento['hora_fim'])); ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <?php if (!empty($evento['descricao'])): ?>
                                    <div class="mt-1">
                                        <small class="text-muted"><?php echo htmlspecialchars(substr($evento['descricao'], 0, 100)); ?></small>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="badge" style="background: <?php echo $evento['cor_evento']; ?>; color: white;">
                                    <?php echo $evento['periodo']; ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ranking dos Melhores Alunos por Classe -->
        <div class="card-custom fade-in-up full-width-card">
            <div class="card-header-custom">
                <h5><i class="fas fa-trophy me-2"></i> 🏆 Ranking dos Melhores Alunos por Classe</h5>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Ano Letivo</label>
                        <select id="ranking_ano_letivo" class="form-select">
                            <?php foreach ($anos_ranking as $ano_r): ?>
                            <option value="<?php echo $ano_r['id']; ?>" <?php echo ($ano_letivo_id == $ano_r['id']) ? 'selected' : ''; ?>>
                                <?php echo $ano_r['ano']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Bimestre</label>
                        <select id="ranking_bimestre" class="form-select">
                            <option value="1">1º Bimestre</option>
                            <option value="2">2º Bimestre</option>
                            <option value="3" selected>3º Bimestre</option>
                            <option value="4">4º Bimestre</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Classe</label>
                        <select id="ranking_classe" class="form-select">
                            <option value="0">Todas as Classes</option>
                            <?php for ($c = 1; $c <= 13; $c++): ?>
                            <option value="<?php echo $c; ?>"><?php echo $c; ?>ª Classe</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">&nbsp;</label>
                        <button id="btn_atualizar_ranking" class="btn btn-primary-custom w-100">
                            <i class="fas fa-chart-line"></i> Atualizar Ranking
                        </button>
                    </div>
                </div>

                <div id="ranking_container">
                    <div class="text-center py-5">
                        <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
                        <p class="mt-2">Carregando ranking...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal para Visualização Detalhada do Aluno -->
    <div class="modal fade" id="modalAlunoDetalhes" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E, #1A2A6C); color: white;">
                    <h5 class="modal-title"><i class="fas fa-user-graduate me-2"></i> Ficha do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalAlunoBody">
                    <div class="text-center py-4">
                        <i class="fas fa-spinner fa-spin fa-2x"></i>
                        <p>Carregando dados do aluno...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Gráfico de Aprovação
        const ctx = document.getElementById('graficoAprovacao').getContext('2d');
        let graficoAprovacao = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [<?php echo $aprovacao['aprovados']; ?>, <?php echo $aprovacao['recuperacao']; ?>, <?php echo $aprovacao['reprovados']; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Gráfico de Evolução
        const ctxEvolucao = document.getElementById('graficoEvolucao').getContext('2d');
        let graficoEvolucao = new Chart(ctxEvolucao, {
            type: 'line',
            data: {
                labels: ['1º Bim', '2º Bim', '3º Bim', '4º Bim'],
                datasets: [
                    {
                        label: 'Aprovados',
                        data: [<?php echo implode(',', $aprovados_evolucao); ?>],
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Recuperação',
                        data: [<?php echo implode(',', $recuperacao_evolucao); ?>],
                        borderColor: '#ffc107',
                        backgroundColor: 'rgba(255, 193, 7, 0.1)',
                        fill: true,
                        tension: 0.4
                    },
                    {
                        label: 'Reprovados',
                        data: [<?php echo implode(',', $reprovados_evolucao); ?>],
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Número de Alunos'
                        }
                    }
                }
            }
        });

        // Animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = { 
                threshold: 0.1, 
                rootMargin: '0px 0px -50px 0px' 
            };
            
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.fade-in-up').forEach(el => {
                observer.observe(el);
            });
        });

        // ============================================
        // RANKING E MODAL
        // ============================================
        
        let rankingAtual = [];
        
        function carregarRanking() {
            const anoLetivo = $('#ranking_ano_letivo').val();
            const bimestre = $('#ranking_bimestre').val();
            const classe = $('#ranking_classe').val();
            
            $('#ranking_container').html(`
                <div class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-3x text-muted"></i>
                    <p class="mt-2">Carregando ranking...</p>
                </div>
            `);
            
            $.ajax({
                url: 'ajax_get_ranking.php',
                method: 'POST',
                data: {
                    action: 'get_ranking',
                    ano_letivo_id: anoLetivo,
                    bimestre: bimestre,
                    classe: classe
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        rankingAtual = response.data;
                        renderizarRanking(rankingAtual, bimestre);
                    } else {
                        $('#ranking_container').html(`
                            <div class="text-center py-5">
                                <i class="fas fa-info-circle fa-3x text-muted"></i>
                                <p class="mt-2">Nenhum aluno encontrado para os filtros selecionados.</p>
                            </div>
                        `);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erro:', error);
                    $('#ranking_container').html(`
                        <div class="text-center py-5">
                            <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                            <p class="mt-2">Erro ao carregar ranking. Tente novamente.</p>
                        </div>
                    `);
                }
            });
        }
        
        function renderizarRanking(alunos, bimestre) {
            const classes = {};
            alunos.forEach(aluno => {
                if (!classes[aluno.classe]) {
                    classes[aluno.classe] = [];
                }
                classes[aluno.classe].push(aluno);
            });
            
            const classesOrdenadas = Object.keys(classes).sort((a,b) => a - b);
            let html = '';
            
            classesOrdenadas.forEach(classeNum => {
                const alunosClasse = classes[classeNum];
                
                html += `
                    <div class="ranking-card">
                        <div class="ranking-header">
                            <h5 class="mb-0"><i class="fas fa-chalkboard me-2"></i> ${classeNum}ª Classe</h5>
                            <small>Top ${alunosClasse.length} alunos</small>
                        </div>
                        <div class="table-responsive">
                            <table class="ranking-table">
                                <thead>
                                    <tr>
                                        <th>Pos</th>
                                        <th>Aluno</th>
                                        <th>Matrícula</th>
                                        <th>Média ${bimestre}º Bim</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                alunosClasse.forEach((aluno, idx) => {
                    let medalha = '';
                    if (idx === 0) medalha = '<i class="fas fa-crown medalha-ouro"></i> ';
                    else if (idx === 1) medalha = '<i class="fas fa-medal medalha-prata"></i> ';
                    else if (idx === 2) medalha = '<i class="fas fa-medal medalha-bronze"></i> ';
                    
                    let statusClass = '';
                    let statusText = '';
                    if (aluno.media >= 14) {
                        statusClass = 'badge-aprovado';
                        statusText = 'Excelente';
                    } else if (aluno.media >= 10) {
                        statusClass = 'badge-aprovado';
                        statusText = 'Aprovado';
                    } else if (aluno.media >= 7) {
                        statusClass = 'badge-recuperacao';
                        statusText = 'Recuperação';
                    } else if (aluno.media > 0) {
                        statusClass = 'badge-reprovado';
                        statusText = 'Reprovado';
                    } else {
                        statusClass = 'badge-reprovado';
                        statusText = 'Sem nota';
                    }
                    
                    html += `
                        <tr onclick="verDetalhesAluno(${aluno.id}, ${bimestre}, $('#ranking_ano_letivo').val())" style="cursor: pointer;">
                            <td><strong>${medalha}${idx + 1}º</strong></td>
                            <td class="text-start"><strong>${escapeHtml(aluno.nome)}</strong><br><small>${escapeHtml(aluno.turma_nome)}</small></td>
                            <td>${escapeHtml(aluno.matricula)}</td>
                            <td><span class="media-destaque">${aluno.media}</span></td>
                            <td><span class="${statusClass}">${statusText}</span></td>
                        </tr>
                    `;
                });
                
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            });
            
            $('#ranking_container').html(html);
        }
        
        function verDetalhesAluno(alunoId, bimestre, anoLetivoId) {
            const modal = new bootstrap.Modal(document.getElementById('modalAlunoDetalhes'));
            const modalBody = document.getElementById('modalAlunoBody');
            
            modalBody.innerHTML = `
                <div class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Carregando dados do aluno...</p>
                </div>
            `;
            
            modal.show();
            
            $.ajax({
                url: 'ajax_get_ranking.php',
                method: 'POST',
                data: {
                    action: 'get_aluno_detalhes',
                    aluno_id: alunoId,
                    bimestre: bimestre,
                    ano_letivo_id: anoLetivoId
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        const aluno = response.data;
                        
                        let notasHtml = '';
                        let disciplinasComNota = 0;
                        
                        if (aluno.disciplinas && aluno.disciplinas.length > 0) {
                            aluno.disciplinas.forEach(disciplina => {
                                if (disciplina.media_final > 0) {
                                    disciplinasComNota++;
                                }
                                let notaClass = '';
                                if (disciplina.media_final >= (aluno.escala === '0-10' ? 4.5 : 9.5)) notaClass = 'nota-alta';
                                else if (disciplina.media_final > 0) notaClass = 'nota-baixa';
                                
                                notasHtml += `
                                    <div class="disciplina-card">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong>${escapeHtml(disciplina.nome)}</strong><br>
                                                <small class="text-muted">${escapeHtml(disciplina.codigo)}</small>
                                            </div>
                                            <div class="text-end">
                                                <span class="nota-item ${notaClass}">${disciplina.media_final}</span>
                                                <br>
                                                <small class="text-muted">Status: ${disciplina.status}</small>
                                            </div>
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            notasHtml = '<p class="text-center text-muted">Nenhuma disciplina encontrada para esta turma.</p>';
                        }
                        
                        let statusClass = '';
                        if (aluno.status_geral === 'Aprovado') statusClass = 'badge-aprovado';
                        else if (aluno.status_geral === 'Recuperação') statusClass = 'badge-recuperacao';
                        else if (aluno.status_geral === 'Reprovado') statusClass = 'badge-reprovado';
                        else statusClass = 'badge-reprovado';
                        
                        modalBody.innerHTML = `
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <div class="mb-3">
                                        <i class="fas fa-user-circle fa-5x text-secondary"></i>
                                    </div>
                                    <h5>${escapeHtml(aluno.nome)}</h5>
                                    <p class="text-muted mb-1"><i class="fas fa-id-card"></i> ${escapeHtml(aluno.matricula)}</p>
                                    <p class="text-muted"><i class="fas fa-building"></i> ${aluno.turma_ano}ª - ${escapeHtml(aluno.turma_nome)}</p>
                                    <hr>
                                    <div class="mt-3">
                                        <div class="alert alert-info">
                                            <strong>📊 Escala de Avaliação</strong><br>
                                            ${aluno.escala}
                                        </div>
                                        <div class="alert alert-success">
                                            <strong>📊 Média Geral do ${bimestre}º Bimestre</strong><br>
                                            <span style="font-size: 28px; font-weight: bold;">${aluno.media_geral}</span>
                                            <br>
                                            <small>(${disciplinasComNota} de ${aluno.total_disciplinas} disciplinas com nota)</small>
                                        </div>
                                        <div class="mt-2">
                                            <span class="${statusClass}">${aluno.status_geral}</span>
                                        </div>
                                        <div class="mt-2 text-muted small">
                                            <i class="fas fa-info-circle"></i> Critério de aprovação: ${aluno.limite_aprovacao} pontos
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <h6><i class="fas fa-book-open me-2"></i> Desempenho por Disciplina</h6>
                                    <div class="mb-3">
                                        <div class="row g-2 mb-2">
                                            <div class="col-3"><small class="text-muted">Legenda:</small></div>
                                            <div class="col-3"><span class="nota-item nota-alta">≥ ${aluno.escala === '0-10' ? 4.5 : 9.5}</span> Positivo</div>
                                            <div class="col-3"><span class="nota-item nota-baixa">&lt; ${aluno.escala === '0-10' ? 4.5 : 9.5}</span> Atenção</div>
                                            <div class="col-3"><span class="nota-item">0</span> Sem nota</div>
                                        </div>
                                    </div>
                                    <div style="max-height: 400px; overflow-y: auto;">
                                        ${notasHtml}
                                    </div>
                                </div>
                            </div>
                        `;
                    } else {
                        modalBody.innerHTML = `
                            <div class="text-center py-4">
                                <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                                <p class="mt-2">Erro ao carregar dados do aluno.</p>
                            </div>
                        `;
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Erro:', error);
                    modalBody.innerHTML = `
                        <div class="text-center py-4">
                            <i class="fas fa-exclamation-triangle fa-3x text-danger"></i>
                            <p class="mt-2">Erro de conexão. Tente novamente.</p>
                        </div>
                    `;
                }
            });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Eventos do Ranking
        $('#btn_atualizar_ranking').on('click', function() {
            carregarRanking();
        });
        
        let intervaloRanking;
        function iniciarAtualizacaoAutomatica() {
            if (intervaloRanking) clearInterval(intervaloRanking);
            intervaloRanking = setInterval(() => {
                carregarRanking();
            }, 30000);
        }
        
        $(document).ready(function() {
            carregarRanking();
            iniciarAtualizacaoAutomatica();
            
            $('#ranking_ano_letivo, #ranking_bimestre, #ranking_classe').on('change', function() {
                carregarRanking();
            });
        });
    </script>
</body>
</html>