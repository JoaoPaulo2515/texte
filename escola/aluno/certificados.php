<?php
// escola/aluno/documentos/certificados.php - Certificados do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Meus Certificados';

// Buscar dados do aluno
$sql_aluno = "SELECT nome, matricula, data_nascimento, bi FROM estudantes WHERE id = :id AND escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Filtros
$tipo_filtro = isset($_GET['tipo']) ? $_GET['tipo'] : 'todos';
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : 0;
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR CERTIFICADOS DO ALUNO
// ==============================================
$sql_certificados = "SELECT 
                        c.id,
                        c.titulo,
                        c.descricao,
                        c.tipo,
                        c.ano_letivo,
                        c.data_emissao,
                        c.arquivo_path,
                        c.codigo_verificacao,
                        c.status,
                        c.assinado_por,
                        c.created_at,
                        u.nome as emitido_por_nome,
                        (SELECT COUNT(*) FROM certificados_visualizacoes WHERE certificado_id = c.id AND aluno_id = :aluno_id1) as visualizado
                    FROM certificados c
                    LEFT JOIN usuarios u ON u.id = c.emitido_por
                    WHERE c.aluno_id = :aluno_id2
                    AND c.escola_id = :escola_id";

if ($tipo_filtro != 'todos') {
    $sql_certificados .= " AND c.tipo = :tipo";
}
if ($ano_filtro > 0) {
    $sql_certificados .= " AND c.ano_letivo = :ano";
}
if (!empty($busca)) {
    $sql_certificados .= " AND (c.titulo LIKE :busca OR c.descricao LIKE :busca OR c.codigo_verificacao LIKE :busca)";
}

$sql_certificados .= " ORDER BY c.data_emissao DESC, c.created_at DESC";

$stmt_certificados = $conn->prepare($sql_certificados);
$params = [
    ':aluno_id1' => $aluno_id,
    ':aluno_id2' => $aluno_id,
    ':escola_id' => $escola_id
];
if ($tipo_filtro != 'todos') {
    $params[':tipo'] = $tipo_filtro;
}
if ($ano_filtro > 0) {
    $params[':ano'] = $ano_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_certificados->execute($params);
$certificados = $stmt_certificados->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR TIPOS DE CERTIFICADOS DISPONÍVEIS
// ==============================================
$sql_tipos = "SELECT DISTINCT tipo, COUNT(*) as total 
              FROM certificados 
              WHERE aluno_id = :aluno_id AND escola_id = :escola_id 
              GROUP BY tipo";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$tipos_disponiveis = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR ANOS DISPONÍVEIS
// ==============================================
$sql_anos = "SELECT DISTINCT ano_letivo 
             FROM certificados 
             WHERE aluno_id = :aluno_id AND escola_id = :escola_id 
             ORDER BY ano_letivo DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_certificados = count($certificados);
$total_nao_visualizados = 0;
$certificados_por_tipo = [];

foreach ($certificados as $cert) {
    if ($cert['visualizado'] == 0) {
        $total_nao_visualizados++;
    }
    if (!isset($certificados_por_tipo[$cert['tipo']])) {
        $certificados_por_tipo[$cert['tipo']] = 0;
    }
    $certificados_por_tipo[$cert['tipo']]++;
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getTipoCertificadoLabel($tipo) {
    $tipos = [
        'conclusao' => 'Certificado de Conclusão',
        'aproveitamento' => 'Certificado de Aproveitamento',
        'frequencia' => 'Certificado de Frequência',
        'curso' => 'Certificado de Curso',
        'workshop' => 'Certificado de Workshop',
        'seminario' => 'Certificado de Seminário',
        'palestra' => 'Certificado de Palestra',
        'merito' => 'Certificado de Mérito',
        'participacao' => 'Certificado de Participação',
        'estagio' => 'Certificado de Estágio'
    ];
    return $tipos[$tipo] ?? ucfirst($tipo);
}

function getTipoCertificadoIcone($tipo) {
    $icones = [
        'conclusao' => '<i class="fas fa-graduation-cap"></i>',
        'aproveitamento' => '<i class="fas fa-star"></i>',
        'frequencia' => '<i class="fas fa-calendar-check"></i>',
        'curso' => '<i class="fas fa-chalkboard-user"></i>',
        'workshop' => '<i class="fas fa-laptop-code"></i>',
        'seminario' => '<i class="fas fa-users"></i>',
        'palestra' => '<i class="fas fa-microphone-alt"></i>',
        'merito' => '<i class="fas fa-trophy"></i>',
        'participacao' => '<i class="fas fa-hand-peace"></i>',
        'estagio' => '<i class="fas fa-briefcase"></i>'
    ];
    return $icones[$tipo] ?? '<i class="fas fa-certificate"></i>';
}

function getStatusCertificadoBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Ativo</span>';
    } elseif ($status == 'pendente') {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
    } else {
        return '<span class="badge bg-danger"><i class="fas fa-ban"></i> Cancelado</span>';
    }
}

function formatarData($data, $formato = 'd/m/Y') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .certificado-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .certificado-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .certificado-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            background: #f8f9fa;
        }
        .certificado-body {
            padding: 20px;
        }
        .certificado-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .tipo-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }
        .tipo-conclusao { background: #006B3E20; color: #006B3E; }
        .tipo-aproveitamento { background: #28a74520; color: #28a745; }
        .tipo-frequencia { background: #17a2b820; color: #17a2b8; }
        .tipo-curso { background: #6f42c120; color: #6f42c1; }
        .tipo-workshop { background: #fd7e1420; color: #fd7e14; }
        .tipo-seminario { background: #20c99720; color: #20c997; }
        .tipo-palestra { background: #e83e8c20; color: #e83e8c; }
        .tipo-merito { background: #ffc10720; color: #ffc107; }
        .tipo-participacao { background: #6c757d20; color: #6c757d; }
        .tipo-estagio { background: #007bff20; color: #007bff; }
        
        .codigo-verificacao {
            font-family: monospace;
            font-size: 0.8rem;
            background: #f8f9fa;
            padding: 3px 8px;
            border-radius: 5px;
            display: inline-block;
        }
        
        .btn-visualizar {
            background: #006B3E;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
        }
        .btn-visualizar:hover {
            background: #004d2e;
        }
        
        .btn-baixar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
        }
        .btn-baixar:hover {
            background: #138496;
        }
        
        .btn-solicitar {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
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
            border-radius: 20px;
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
            border-radius: 20px 20px 0 0;
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
            font-size: 24px;
            cursor: pointer;
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
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno { display: none; }
        }
        
        .visualizado-badge {
            background: #e8f5e9;
            color: #006B3E;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        .nao-visualizado-badge {
            background: #ffc10720;
            color: #ffc107;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.6; }
            100% { opacity: 1; }
        }
    </style>
</head>
<body>

   <?php include 'includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Meus Certificados</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todos os seus certificados emitidos pela escola.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Tipos de Certificados</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Conclusão</span> - Curso concluído com aproveitamento<br>
                    <span class="badge bg-info">Frequência</span> - Comprovação de presença<br>
                    <span class="badge bg-warning">Mérito</span> - Reconhecimento por destaque<br>
                    <span class="badge bg-primary">Participação</span> - Eventos e atividades
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Código de Verificação</div>
                <div class="ajuda-texto">Cada certificado possui um código único que pode ser usado para verificar a autenticidade.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Solicitar Certificado</div>
                <div class="ajuda-texto">Caso não encontre um certificado, clique em "Solicitar Certificado" para fazer o pedido.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-certificate"></i> Meus Certificados</h4>
            <p class="text-muted mb-0">Visualize e baixe seus certificados</p>
        </div>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#solicitarCertificadoModal">
                <i class="fas fa-plus-circle"></i> Solicitar Certificado
            </button>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-certificate"></i> Total Certificados</small>
                    <h6 class="mb-0"><?php echo $total_certificados; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_certificados; ?></div>
                <div class="stat-label"><i class="fas fa-certificate text-success"></i> Total de Certificados</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_nao_visualizados; ?></div>
                <div class="stat-label"><i class="fas fa-eye-slash text-warning"></i> Não Visualizados</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo count($certificados_por_tipo); ?></div>
                <div class="stat-label"><i class="fas fa-tags text-info"></i> Tipos de Certificados</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Tipo</label>
                    <select name="tipo" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $tipo_filtro == 'todos' ? 'selected' : ''; ?>>Todos os tipos</option>
                        <option value="conclusao" <?php echo $tipo_filtro == 'conclusao' ? 'selected' : ''; ?>>Certificado de Conclusão</option>
                        <option value="aproveitamento" <?php echo $tipo_filtro == 'aproveitamento' ? 'selected' : ''; ?>>Certificado de Aproveitamento</option>
                        <option value="frequencia" <?php echo $tipo_filtro == 'frequencia' ? 'selected' : ''; ?>>Certificado de Frequência</option>
                        <option value="curso" <?php echo $tipo_filtro == 'curso' ? 'selected' : ''; ?>>Certificado de Curso</option>
                        <option value="merito" <?php echo $tipo_filtro == 'merito' ? 'selected' : ''; ?>>Certificado de Mérito</option>
                        <option value="participacao" <?php echo $tipo_filtro == 'participacao' ? 'selected' : ''; ?>>Certificado de Participação</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título, descrição ou código..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="certificados.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Certificados -->
    <?php if (empty($certificados)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhum certificado encontrado</h5>
            <p>Você ainda não possui certificados emitidos ou não há certificados com os filtros selecionados.</p>
            <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#solicitarCertificadoModal">
                <i class="fas fa-plus-circle"></i> Solicitar Certificado
            </button>
        </div>
    <?php else: ?>
        <div class="certificados-list">
            <?php foreach ($certificados as $cert): 
                $classe_tipo = 'tipo-' . $cert['tipo'];
                $icone_tipo = getTipoCertificadoIcone($cert['tipo']);
            ?>
            <div class="certificado-card fade-in">
                <div class="certificado-header">
                    <div>
                        <span class="tipo-badge <?php echo $classe_tipo; ?>">
                            <?php echo $icone_tipo; ?> <?php echo getTipoCertificadoLabel($cert['tipo']); ?>
                        </span>
                        <?php if ($cert['visualizado'] == 0): ?>
                        <span class="nao-visualizado-badge ms-2"><i class="fas fa-eye-slash"></i> Novo</span>
                        <?php else: ?>
                        <span class="visualizado-badge ms-2"><i class="fas fa-eye"></i> Visualizado</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php echo getStatusCertificadoBadge($cert['status']); ?>
                    </div>
                </div>
                
                <div class="certificado-body">
                    <div class="row">
                        <div class="col-md-8">
                            <h5 class="mb-2"><?php echo htmlspecialchars($cert['titulo']); ?></h5>
                            <p class="text-muted mb-3"><?php echo nl2br(htmlspecialchars($cert['descricao'] ?? 'Sem descrição')); ?></p>
                            
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-calendar-alt text-primary"></i>
                                        <span>Data de Emissão: <strong><?php echo formatarData($cert['data_emissao']); ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-calendar text-primary"></i>
                                        <span>Ano Letivo: <strong><?php echo $cert['ano_letivo']; ?></strong></span>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-qrcode text-info"></i>
                                        <span>Código de Verificação: <code class="codigo-verificacao"><?php echo $cert['codigo_verificacao']; ?></code></span>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="copiarCodigo('<?php echo $cert['codigo_verificacao']; ?>')">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                </div>
                                <?php if ($cert['assinado_por']): ?>
                                <div class="col-md-12">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="fas fa-signature text-secondary"></i>
                                        <span>Assinado por: <strong><?php echo htmlspecialchars($cert['assinado_por']); ?></strong></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="col-md-4 text-center">
                            <div class="mb-3">
                                <div style="font-size: 3rem; color: #006B3E;">
                                    <i class="fas fa-certificate"></i>
                                </div>
                                <?php if ($cert['arquivo_path']): ?>
                                <div class="mt-2">
                                    <a href="<?php echo $cert['arquivo_path']; ?>" target="_blank" class="btn-visualizar" onclick="marcarVisualizado(<?php echo $cert['id']; ?>)">
                                        <i class="fas fa-eye"></i> Visualizar
                                    </a>
                                    <a href="<?php echo $cert['arquivo_path']; ?>" download class="btn-baixar mt-2 d-block">
                                        <i class="fas fa-download"></i> Baixar PDF
                                    </a>
                                </div>
                                <?php else: ?>
                                <div class="mt-2">
                                    <span class="text-muted">Documento em processamento</span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="certificado-footer">
                    <div>
                        <small class="text-muted">
                            <i class="fas fa-clock"></i> Emitido em: <?php echo formatarData($cert['created_at'], 'd/m/Y H:i'); ?>
                            <?php if ($cert['emitido_por_nome']): ?>
                            | Por: <?php echo htmlspecialchars($cert['emitido_por_nome']); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div>
                        <?php if ($cert['arquivo_path']): ?>
                        <button class="btn btn-sm btn-outline-secondary" onclick="verificarAutenticidade('<?php echo $cert['codigo_verificacao']; ?>')">
                            <i class="fas fa-shield-alt"></i> Verificar Autenticidade
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Solicitar Certificado -->
<div class="modal fade" id="solicitarCertificadoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Solicitar Certificado</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form id="solicitarCertificadoForm" method="POST" action="solicitar_certificado.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo de Certificado *</label>
                        <select name="tipo" class="form-select" required>
                            <option value="">Selecione...</option>
                            <option value="conclusao">Certificado de Conclusão</option>
                            <option value="aproveitamento">Certificado de Aproveitamento</option>
                            <option value="frequencia">Certificado de Frequência</option>
                            <option value="participacao">Certificado de Participação</option>
                            <option value="merito">Certificado de Mérito</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Título</label>
                        <input type="text" name="titulo" class="form-control" placeholder="Ex: Certificado de Conclusão do 10º Ano">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição / Motivo</label>
                        <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva o motivo da solicitação..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ano Letivo</label>
                        <select name="ano_letivo" class="form-select">
                            <option value="<?php echo date('Y'); ?>"><?php echo date('Y'); ?></option>
                            <option value="<?php echo date('Y') - 1; ?>"><?php echo date('Y') - 1; ?></option>
                            <option value="<?php echo date('Y') - 2; ?>"><?php echo date('Y') - 2; ?></option>
                        </select>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> O certificado será analisado pela secretaria e você receberá uma notificação quando estiver disponível.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Enviar Solicitação</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    // Função para copiar código
    function copiarCodigo(codigo) {
        navigator.clipboard.writeText(codigo);
        alert('Código copiado: ' + codigo);
    }
    
    // Função para verificar autenticidade
    function verificarAutenticidade(codigo) {
        window.open('verificar_certificado.php?codigo=' + codigo, '_blank', 'width=600,height=500');
    }
    
    // Função para marcar como visualizado
    function marcarVisualizado(id) {
        $.ajax({
            url: 'marcar_visualizado.php',
            method: 'POST',
            data: { id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    location.reload();
                }
            }
        });
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
</script>
</body>
</html>