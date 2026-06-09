<?php
// escola/tesouraria/mensalidades.php - Gestão de Mensalidades

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// FUNÇÃO PARA ATUALIZAR MENSALIDADES VENCIDAS
// ============================================
function atualizarMensalidadesVencidas($conn, $escola_id) {
    $sql = "UPDATE mensalidades 
            SET status = 'atrasado' 
            WHERE escola_id = :escola_id 
            AND status IN ('pendente', 'parcial') 
            AND data_vencimento < CURDATE()";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':escola_id' => $escola_id]);
    return $stmt->rowCount();
}

// Executar atualização automática ao carregar a página
$vencidos_atualizados = atualizarMensalidadesVencidas($conn, $escola_id);
if ($vencidos_atualizados > 0) {
    error_log("$vencidos_atualizados mensalidades marcadas como atrasadas");
}

// ============================================
// BUSCAR ANOS LETIVOS DO BANCO DE DADOS
// ============================================
$sql_anos = "SELECT id, ano, ativo FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// CONFIGS
// ============================================
$valor_mensalidade_padrao = 0;
$ano_atual = date('Y');
$mes_atual = date('m');

// Buscar ano letivo ativo
$ano_letivo_ativo = null;
foreach ($anos_letivos as $al) {
    if ($al['ativo'] == 1) {
        $ano_letivo_ativo = $al['ano'];
        break;
    }
}
if (!$ano_letivo_ativo && !empty($anos_letivos)) {
    $ano_letivo_ativo = $anos_letivos[0]['ano'];
}

// ============================================
// FILTROS
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : $ano_letivo_ativo;
$mes_filtro = isset($_GET['mes']) ? (int)$_GET['mes'] : 0;
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// ============================================
// PROCESSAR LANÇAMENTO DE MENSALIDADES (INDIVIDUAL OU EM MASSA)
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lancar_mensalidades'])) {
    $tipo_lancamento = $_POST['tipo_lancamento'] ?? 'turma';
    $turma_id = isset($_POST['turma_id']) ? (int)$_POST['turma_id'] : 0;
    $aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
    $mes_referencia = (int)$_POST['mes_referencia'];
    $ano_referencia = (int)$_POST['ano_referencia'];
    $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? $valor_mensalidade_padrao)));
    $data_vencimento = $_POST['data_vencimento'] ?? date('Y-m-d', strtotime("$ano_referencia-$mes_referencia-10"));
    
    if ($tipo_lancamento == 'turma' && $turma_id <= 0) {
        $error = "Selecione uma turma.";
    } elseif ($tipo_lancamento == 'individual' && $aluno_id <= 0) {
        $error = "Selecione um aluno.";
    } else {
        try {
            $conn->beginTransaction();
            
            $sql_ano_id = "SELECT id FROM ano_letivo WHERE escola_id = :escola_id AND ano = :ano LIMIT 1";
            $stmt_ano_id = $conn->prepare($sql_ano_id);
            $stmt_ano_id->execute([':escola_id' => $escola_id, ':ano' => $ano_referencia]);
            $ano_letivo = $stmt_ano_id->fetch(PDO::FETCH_ASSOC);
            
            if (!$ano_letivo) {
                throw new Exception("Ano letivo $ano_referencia não encontrado.");
            }
            $ano_letivo_id = $ano_letivo['id'];
            
            if ($tipo_lancamento == 'individual') {
                $alunos = [['id' => $aluno_id]];
            } else {
                $sql_alunos = "SELECT DISTINCT e.id, e.nome, e.matricula 
                               FROM estudantes e
                               INNER JOIN matriculas m ON m.estudante_id = e.id
                               WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND e.status = 'ativo'";
                $stmt_alunos = $conn->prepare($sql_alunos);
                $stmt_alunos->execute([':turma_id' => $turma_id]);
                $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
            }
            
            if (empty($alunos)) {
                $error = $tipo_lancamento == 'individual' ? "Aluno não encontrado." : "Nenhum aluno encontrado nesta turma.";
            } else {
                $contador = 0;
                foreach ($alunos as $aluno) {
                    $sql_check = "SELECT id FROM mensalidades 
                                  WHERE escola_id = :escola_id 
                                  AND aluno_id = :aluno_id 
                                  AND mes_referencia = :mes 
                                  AND ano_letivo_id = :ano_letivo_id";
                    $stmt_check = $conn->prepare($sql_check);
                    $stmt_check->execute([
                        ':escola_id' => $escola_id,
                        ':aluno_id' => $aluno['id'],
                        ':mes' => $mes_referencia,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    
                    if (!$stmt_check->fetch()) {
                        $sql_insert = "INSERT INTO mensalidades (
                                            escola_id, aluno_id, turma_id, mes_referencia, ano_referencia, ano_letivo_id, 
                                            valor_total, valor_pago, status, data_vencimento, created_at
                                        ) VALUES (
                                            :escola_id, :aluno_id, :turma_id, :mes, :ano, :ano_letivo_id, 
                                            :valor, 0, 'pendente', :data_vencimento, NOW()
                                        )";
                        $stmt_insert = $conn->prepare($sql_insert);
                        $stmt_insert->execute([
                            ':escola_id' => $escola_id,
                            ':aluno_id' => $aluno['id'],
                            ':turma_id' => $turma_id > 0 ? $turma_id : null,
                            ':mes' => $mes_referencia,
                            ':ano' => $ano_referencia,
                            ':ano_letivo_id' => $ano_letivo_id,
                            ':valor' => $valor,
                            ':data_vencimento' => $data_vencimento
                        ]);
                        $contador++;
                    }
                }
                $conn->commit();
                
                if ($contador == 0) {
                    $error = "Nenhuma mensalidade nova foi lançada. Este aluno/turma já possui mensalidade para este período.";
                } else {
                    $msg_tipo = $tipo_lancamento == 'individual' ? "para o aluno" : "para $contador alunos da turma";
                    $success = "$contador mensalidade(s) lançada(s) com sucesso $msg_tipo no mês de " . getMesNome($mes_referencia) . " do ano letivo $ano_referencia!";
                }
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao lançar mensalidades: " . $e->getMessage();
        }
    }
}

// ============================================
// PROCESSAR DESCONTO (INDIVIDUAL OU EM MASSA)
// ============================================

// ============================================
// PROCESSAR DESCONTO - VERSÃO COM CÁLCULO PRÉVIO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aplicar_desconto'])) {
    $tipo_desconto = $_POST['tipo_desconto'] ?? 'turma';
    $turma_id = isset($_POST['turma_id_desconto']) ? (int)$_POST['turma_id_desconto'] : 0;
    $aluno_id = isset($_POST['aluno_id_desconto']) ? (int)$_POST['aluno_id_desconto'] : 0;
    $mes_referencia = (int)$_POST['mes_referencia_desconto'];
    $ano_referencia = (int)$_POST['ano_referencia_desconto'];
    $percentual_desconto = floatval($_POST['percentual_desconto'] ?? 0);
    
    if ($tipo_desconto == 'turma' && $turma_id <= 0) {
        $error = "Selecione uma turma para aplicar o desconto.";
    } elseif ($tipo_desconto == 'individual' && $aluno_id <= 0) {
        $error = "Selecione um aluno para aplicar o desconto.";
    } elseif ($percentual_desconto <= 0 || $percentual_desconto > 100) {
        $error = "Percentual de desconto inválido (1-100%).";
    } else {
        try {
            $conn->beginTransaction();
            $contador = 0;
            
            if ($tipo_desconto == 'individual') {
                // Buscar a mensalidade primeiro
                $sql_busca = "SELECT id, valor_total FROM mensalidades 
                              WHERE aluno_id = :aluno_id 
                              AND mes_referencia = :mes 
                              AND ano_referencia = :ano
                              AND escola_id = :escola_id
                              AND status IN ('pendente', 'parcial')";
                $stmt_busca = $conn->prepare($sql_busca);
                $stmt_busca->execute([
                    ':aluno_id' => $aluno_id,
                    ':mes' => $mes_referencia,
                    ':ano' => $ano_referencia,
                    ':escola_id' => $escola_id
                ]);
                $mensalidade = $stmt_busca->fetch(PDO::FETCH_ASSOC);
                
                if ($mensalidade) {
                    $valor_desconto = ($mensalidade['valor_total'] * $percentual_desconto) / 100;
                    $novo_valor_total = $mensalidade['valor_total'] - $valor_desconto;
                    
                    $sql_update = "UPDATE mensalidades 
                                   SET desconto = :desconto,
                                       valor_total = :novo_valor
                                   WHERE id = :id";
                    $stmt_update = $conn->prepare($sql_update);
                    $stmt_update->execute([
                        ':desconto' => $valor_desconto,
                        ':novo_valor' => $novo_valor_total,
                        ':id' => $mensalidade['id']
                    ]);
                    $contador = 1;
                    $success = "Desconto de $percentual_desconto% aplicado com sucesso na mensalidade do aluno!";
                } else {
                    $error = "Nenhuma mensalidade pendente encontrada para este aluno.";
                }
            } else {
                // Para turma, buscar todas as mensalidades
                $sql_busca = "SELECT m.id, m.valor_total 
                              FROM mensalidades m
                              INNER JOIN matriculas mat ON mat.estudante_id = m.aluno_id
                              WHERE mat.turma_id = :turma_id 
                              AND m.mes_referencia = :mes 
                              AND m.ano_referencia = :ano
                              AND m.escola_id = :escola_id
                              AND m.status IN ('pendente', 'parcial')";
                $stmt_busca = $conn->prepare($sql_busca);
                $stmt_busca->execute([
                    ':turma_id' => $turma_id,
                    ':mes' => $mes_referencia,
                    ':ano' => $ano_referencia,
                    ':escola_id' => $escola_id
                ]);
                $mensalidades = $stmt_busca->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($mensalidades)) {
                    foreach ($mensalidades as $mensalidade) {
                        $valor_desconto = ($mensalidade['valor_total'] * $percentual_desconto) / 100;
                        $novo_valor_total = $mensalidade['valor_total'] - $valor_desconto;
                        
                        $sql_update = "UPDATE mensalidades 
                                       SET desconto = :desconto,
                                           valor_total = :novo_valor
                                       WHERE id = :id";
                        $stmt_update = $conn->prepare($sql_update);
                        $stmt_update->execute([
                            ':desconto' => $valor_desconto,
                            ':novo_valor' => $novo_valor_total,
                            ':id' => $mensalidade['id']
                        ]);
                        $contador++;
                    }
                    $success = "Desconto de $percentual_desconto% aplicado com sucesso em $contador mensalidade(s) da turma!";
                } else {
                    $error = "Nenhuma mensalidade pendente encontrada para esta turma.";
                }
            }
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao aplicar desconto: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR TURMAS
// ============================================
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos para lista
$sql_alunos_lista = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos_lista = $conn->prepare($sql_alunos_lista);
$stmt_alunos_lista->execute([':escola_id' => $escola_id]);
$alunos_lista = $stmt_alunos_lista->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// MÉTODO SIMPLIFICADO PARA BUSCAR MENSALIDADES
// ============================================
$where = "m.escola_id = $escola_id";

if ($status_filtro != 'todos') {
    $where .= " AND m.status = '$status_filtro'";
}

if ($turma_filtro > 0) {
    $where .= " AND mat.turma_id = $turma_filtro";
}

if ($ano_filtro > 0) {
    $where .= " AND m.ano_referencia = $ano_filtro";
}

if ($mes_filtro > 0) {
    $where .= " AND m.mes_referencia = $mes_filtro";
}

if (!empty($busca)) {
    $where .= " AND (e.nome LIKE '%$busca%' OR e.matricula LIKE '%$busca%')";
}

$sql_mensalidades = "
    SELECT m.*, e.nome as aluno_nome, e.matricula, t.nome as turma_nome, t.ano as turma_ano
    FROM mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    WHERE $where
    GROUP BY m.id
    ORDER BY e.nome ASC, m.ano_referencia DESC, m.mes_referencia ASC
    LIMIT $limit OFFSET $offset
";

$mensalidades = $conn->query($sql_mensalidades)->fetchAll(PDO::FETCH_ASSOC);

$sql_total = "
    SELECT COUNT(DISTINCT m.id) as total 
    FROM mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    LEFT JOIN matriculas mat ON mat.estudante_id = e.id AND mat.status = 'ativa'
    LEFT JOIN turmas t ON t.id = mat.turma_id
    WHERE $where
";

$total_mensalidades = $conn->query($sql_total)->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$total_pages = ceil($total_mensalidades / $limit);

// ============================================
// ESTATÍSTICAS DE ACOMPANHAMENTO
// ============================================

// Totais gerais
$stmt = $conn->prepare("SELECT 
    COUNT(*) as total_mensalidades,
    SUM(valor_total) as total_valor,
    SUM(valor_pago) as total_pago,
    SUM(valor_total - valor_pago) as total_devedor
FROM mensalidades WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$totais = $stmt->fetch(PDO::FETCH_ASSOC);

// Por status
$stmt = $conn->prepare("SELECT 
    COUNT(CASE WHEN status = 'pendente' THEN 1 END) as pendentes,
    COUNT(CASE WHEN status = 'parcial' THEN 1 END) as parciais,
    COUNT(CASE WHEN status = 'pago' THEN 1 END) as pagos,
    COUNT(CASE WHEN status = 'atrasado' THEN 1 END) as atrasados,
    SUM(CASE WHEN status = 'pendente' THEN valor_total - valor_pago ELSE 0 END) as valor_pendente,
    SUM(CASE WHEN status = 'atrasado' THEN valor_total - valor_pago ELSE 0 END) as valor_atrasado
FROM mensalidades WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Adimplência
$total_geral = $totais['total_valor'] ?? 0;
$total_pago = $totais['total_pago'] ?? 0;
$taxa_adimplencia = $total_geral > 0 ? ($total_pago / $total_geral) * 100 : 0;

// Top 5 alunos com mais débitos
$stmt = $conn->prepare("
    SELECT 
        e.nome as aluno_nome,
        e.matricula,
        SUM(m.valor_total - m.valor_pago) as total_devedor,
        COUNT(CASE WHEN m.status IN ('pendente', 'atrasado') THEN 1 END) as meses_atraso
    FROM mensalidades m
    JOIN estudantes e ON e.id = m.aluno_id
    WHERE m.escola_id = :escola_id AND m.valor_total - m.valor_pago > 0
    GROUP BY m.aluno_id
    ORDER BY total_devedor DESC
    LIMIT 5
");
$stmt->execute([':escola_id' => $escola_id]);
$top_devedores = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusMensalidadeBadge($status) {
    switch ($status) {
        case 'pago':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Pago</span>';
        case 'parcial':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Parcial</span>';
        case 'pendente':
            return '<span class="badge bg-secondary"><i class="fas fa-hourglass-half"></i> Pendente</span>';
        case 'atrasado':
            return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mensalidades | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content-tesouraria {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content-tesouraria { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .stat-value { font-size: 1.3em; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 0.75rem; color: #6c757d; }
        
        .filter-label { font-weight: 600; font-size: 0.85rem; margin-bottom: 5px; color: #555; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .mensalidade-row.atrasado { background-color: #fff3cd; }
        .mensalidade-row.pendente { background-color: #f8f9fa; }
        .mensalidade-row.pago { background-color: #d4edda; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .progress { background-color: #e9ecef; border-radius: 10px; }
        
        .btn-group-custom { display: flex; gap: 10px; margin-bottom: 15px; }
        .btn-group-custom .btn { flex: 1; }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content-tesouraria">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-calendar-dollar"></i> Gestão de Mensalidades</h2>
                <p class="text-muted">Controle de mensalidades dos alunos</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalLancarMensalidades">
                    <i class="fas fa-plus"></i> Lançar Mensalidades
                </button>
                <button class="btn btn-info ms-2" data-bs-toggle="modal" data-bs-target="#modalDescontoLote">
                    <i class="fas fa-percent"></i> Aplicar Desconto
                </button>
                <a href="index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Estatísticas de Acompanhamento -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($totais['total_mensalidades'] ?? 0); ?></div>
                <div class="stat-label">Total de Mensalidades</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo formatarMoeda($totais['total_valor'] ?? 0); ?></div>
                <div class="stat-label">Valor Total Faturado</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo formatarMoeda($totais['total_pago'] ?? 0); ?></div>
                <div class="stat-label">Valor Arrecadado</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo formatarMoeda($totais['total_devedor'] ?? 0); ?></div>
                <div class="stat-label">Valor em Aberto</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($taxa_adimplencia, 1); ?>%</div>
                <div class="stat-label">Taxa de Adimplência</div>
                <div class="progress mt-2" style="height: 5px;">
                    <div class="progress-bar bg-success" style="width: <?php echo $taxa_adimplencia; ?>%"></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['pendentes'] ?? 0); ?></div>
                <div class="stat-label">Mensalidades Pendentes</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['atrasados'] ?? 0); ?></div>
                <div class="stat-label">Mensalidades Atrasadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo formatarMoeda($stats['valor_atrasado'] ?? 0); ?></div>
                <div class="stat-label">Valor em Atraso</div>
            </div>
        </div>
        
        <!-- Top Devedores -->
        <?php if (!empty($top_devedores)): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fas fa-exclamation-triangle"></i> Top 5 Alunos com Maior Débito</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="table-light">
                            <tr><th>Aluno</th><th>Matrícula</th><th>Meses em Atraso</th><th>Valor Devedor</th><th>% do Total</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_devedores as $devedor): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($devedor['aluno_nome']); ?></td>
                                <td><?php echo $devedor['matricula']; ?></td>
                                <td><?php echo $devedor['meses_atraso']; ?></td>
                                <td class="text-danger fw-bold"><?php echo formatarMoeda($devedor['total_devedor']); ?></td>
                                <td>
                                    <div class="progress" style="height: 8px;">
                                        <div class="progress-bar bg-danger" style="width: <?php echo ($totais['total_devedor'] > 0) ? ($devedor['total_devedor'] / $totais['total_devedor']) * 100 : 0; ?>%"></div>
                                    </div>
                                    <small><?php echo number_format(($totais['total_devedor'] > 0) ? ($devedor['total_devedor'] / $totais['total_devedor']) * 100 : 0, 1); ?>% do total</small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5></div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-2"><label class="filter-label">Status</label><select name="status" class="form-select"><option value="todos" <?php echo $status_filtro=='todos'?'selected':''; ?>>Todos</option><option value="pago" <?php echo $status_filtro=='pago'?'selected':''; ?>>Pago</option><option value="pendente" <?php echo $status_filtro=='pendente'?'selected':''; ?>>Pendente</option><option value="parcial" <?php echo $status_filtro=='parcial'?'selected':''; ?>>Parcial</option><option value="atrasado" <?php echo $status_filtro=='atrasado'?'selected':''; ?>>Atrasado</option></select></div>
                    <div class="col-md-2"><label class="filter-label">Turma</label><select name="turma_id" class="form-select"><option value="0">Todas</option><?php foreach($turmas as $t): ?><option value="<?php echo $t['id']; ?>" <?php echo $turma_filtro==$t['id']?'selected':''; ?>><?php echo $t['ano'].'ª - '.htmlspecialchars($t['nome']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-2">
                        <label class="filter-label">Ano Letivo</label>
                        <select name="ano" class="form-select">
                            <option value="0">Todos os anos</option>
                            <?php foreach ($anos_letivos as $al): ?>
                            <option value="<?php echo $al['ano']; ?>" <?php echo $ano_filtro == $al['ano'] ? 'selected' : ''; ?>>
                                <?php echo $al['ano']; ?> <?php echo $al['ativo'] == 1 ? '(Ativo)' : ''; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2"><label class="filter-label">Mês</label><select name="mes" class="form-select"><option value="0">Todos</option><?php for($m=1;$m<=12;$m++): ?><option value="<?php echo $m; ?>" <?php echo $mes_filtro==$m?'selected':''; ?>><?php echo getMesNome($m); ?></option><?php endfor; ?></select></div>
                    <div class="col-md-3"><label class="filter-label">Buscar</label><input type="text" name="busca" class="form-control" placeholder="Nome ou matrícula..." value="<?php echo htmlspecialchars($busca); ?>"></div>
                    <div class="col-md-1"><label class="filter-label">&nbsp;</label><button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button></div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Mensalidades -->
        <div class="card">
            <div class="card-header"><h5 class="mb-0"><i class="fas fa-list"></i> Mensalidades</h5></div>
            <div class="card-body">
                <?php if (empty($mensalidades)): ?>
                    <div class="alert alert-info text-center">Nenhuma mensalidade encontrada.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Aluno</th><th>Turma</th><th>Mês/Ano</th><th>Valor Total</th><th>Valor Pago</th><th>Saldo</th><th>Vencimento</th><th>Status</th><th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mensalidades as $row): ?>
                                <tr class="mensalidade-row <?php echo $row['status']; ?>">
                                    <td><strong><?php echo htmlspecialchars($row['aluno_nome']); ?></strong><br><small><?php echo $row['matricula']; ?></small></td>
                                    <td><?php echo $row['turma_ano'] . 'ª - ' . htmlspecialchars($row['turma_nome']); ?></td>
                                    <td><?php echo getMesNome($row['mes_referencia']) . '/' . $row['ano_referencia']; ?></td>
                                    <td class="text-end"><?php echo formatarMoeda($row['valor_total']); ?></td>
                                    <td class="text-end text-success"><?php echo formatarMoeda($row['valor_pago']); ?></td>
                                    <td class="text-end text-danger"><?php echo formatarMoeda($row['valor_total'] - $row['valor_pago']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($row['data_vencimento'])); ?></td>
                                    <td><?php echo getStatusMensalidadeBadge($row['status']); ?></td>
                                    <td>
                                        <a href="ver_pagamentos.php?aluno_id=<?php echo $row['aluno_id']; ?>" class="btn btn-sm btn-info" title="Ver Pagamentos">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="pagamentos.php?aluno_id=<?php echo $row['aluno_id']; ?>&mensalidade_id=<?php echo $row['id']; ?>" class="btn btn-sm btn-success" title="Registrar Pagamento">
                                            <i class="fas fa-money-bill"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr class="fw-bold">
                                    <td colspan="3" class="text-end">TOTAIS:</td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total'))); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_pago'))); ?></td>
                                    <td class="text-end"><?php echo formatarMoeda(array_sum(array_column($mensalidades, 'valor_total')) - array_sum(array_column($mensalidades, 'valor_pago'))); ?></td>
                                    <td colspan="3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <nav><ul class="pagination justify-content-center"><?php for($i=1;$i<=$total_pages;$i++): ?><li class="page-item <?php echo $page==$i?'active':''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($status_filtro); ?>&turma_id=<?php echo $turma_filtro; ?>&ano=<?php echo $ano_filtro; ?>&mes=<?php echo $mes_filtro; ?>&busca=<?php echo urlencode($busca); ?>"><?php echo $i; ?></a></li><?php endfor; ?></ul></nav>
        <?php endif; ?>
    </div>
    
    <!-- Modal Lançar Mensalidades (Individual ou Turma) -->
    <div class="modal fade" id="modalLancarMensalidades" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Lançar Mensalidades</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tipo de Lançamento <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="tipo_lancamento" id="lancar_turma" value="turma" checked autocomplete="off">
                                <label class="btn btn-outline-primary" for="lancar_turma">
                                    <i class="fas fa-users"></i> Para uma Turma
                                </label>
                                <input type="radio" class="btn-check" name="tipo_lancamento" id="lancar_individual" value="individual" autocomplete="off">
                                <label class="btn btn-outline-primary" for="lancar_individual">
                                    <i class="fas fa-user"></i> Para um Aluno
                                </label>
                            </div>
                        </div>
                        
                        <div id="div_selecao_turma">
                            <div class="mb-3">
                                <label class="form-label">Turma <span class="text-danger">*</span></label>
                                <select name="turma_id" class="form-select">
                                    <option value="">Selecione uma turma</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Serão lançadas mensalidades para TODOS os alunos ativos da turma.</small>
                            </div>
                        </div>
                        
                        <div id="div_selecao_aluno" style="display:none;">
                            <div class="mb-3">
                                <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                <select name="aluno_id" class="form-select">
                                    <option value="">Selecione um aluno</option>
                                    <?php foreach ($alunos_lista as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mês <span class="text-danger">*</span></label>
                                <select name="mes_referencia" class="form-select" required>
                                    <?php for($m=1;$m<=12;$m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $m == $mes_atual ? 'selected' : ''; ?>><?php echo getMesNome($m); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ano Letivo <span class="text-danger">*</span></label>
                                <select name="ano_referencia" class="form-select" required>
                                    <?php foreach ($anos_letivos as $al): ?>
                                    <option value="<?php echo $al['ano']; ?>" <?php echo $al['ativo'] == 1 ? 'selected' : ''; ?>>
                                        <?php echo $al['ano']; ?> <?php echo $al['ativo'] == 1 ? '(Ativo)' : ''; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Valor da Mensalidade</label>
                            <input type="text" name="valor" class="form-control" value="<?php echo number_format($valor_mensalidade_padrao, 2, ',', '.'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Data de Vencimento</label>
                            <input type="date" name="data_vencimento" class="form-control" value="<?php echo date('Y-m-d', strtotime("$ano_letivo_ativo-$mes_atual-10")); ?>">
                        </div>
                        <div class="alert alert-info" id="msg_lancamento">
                            <i class="fas fa-info-circle"></i> Serão lançadas mensalidades para todos os alunos ativos da turma selecionada.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="lancar_mensalidades" class="btn btn-primary">Lançar Mensalidades</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Desconto (Individual ou Turma) -->
    <div class="modal fade" id="modalDescontoLote" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-percent"></i> Aplicar Desconto</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tipo de Desconto <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="tipo_desconto" id="desconto_turma" value="turma" checked autocomplete="off">
                                <label class="btn btn-outline-primary" for="desconto_turma">
                                    <i class="fas fa-users"></i> Para uma Turma
                                </label>
                                <input type="radio" class="btn-check" name="tipo_desconto" id="desconto_individual" value="individual" autocomplete="off">
                                <label class="btn btn-outline-primary" for="desconto_individual">
                                    <i class="fas fa-user"></i> Para um Aluno
                                </label>
                            </div>
                        </div>
                        
                        <div id="div_desconto_turma">
                            <div class="mb-3">
                                <label class="form-label">Turma <span class="text-danger">*</span></label>
                                <select name="turma_id_desconto" class="form-select">
                                    <option value="">Selecione uma turma</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">O desconto será aplicado a todas as mensalidades pendentes da turma.</small>
                            </div>
                        </div>
                        
                        <div id="div_desconto_aluno" style="display:none;">
                            <div class="mb-3">
                                <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                <select name="aluno_id_desconto" class="form-select">
                                    <option value="">Selecione um aluno</option>
                                    <?php foreach ($alunos_lista as $a): ?>
                                    <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">O desconto será aplicado apenas à mensalidade do aluno selecionado.</small>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mês <span class="text-danger">*</span></label>
                                <select name="mes_referencia_desconto" class="form-select" required>
                                    <?php for($m=1;$m<=12;$m++): ?>
                                    <option value="<?php echo $m; ?>"><?php echo getMesNome($m); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ano Letivo <span class="text-danger">*</span></label>
                                <select name="ano_referencia_desconto" class="form-select" required>
                                    <?php foreach ($anos_letivos as $al): ?>
                                    <option value="<?php echo $al['ano']; ?>"><?php echo $al['ano']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Percentual de Desconto (%) <span class="text-danger">*</span></label>
                            <input type="number" name="percentual_desconto" class="form-control" step="1" min="1" max="100" required placeholder="Ex: 10">
                            <small class="text-muted">Digite o percentual de desconto (1% a 100%)</small>
                        </div>
                        
                        <div class="alert alert-warning" id="msg_desconto">
                            <i class="fas fa-info-circle"></i> O desconto será aplicado apenas nas mensalidades pendentes.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="aplicar_desconto" class="btn btn-primary">Aplicar Desconto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Alternar entre lançamento por turma e individual
        $('input[name="tipo_lancamento"]').on('change', function() {
            if ($(this).val() == 'turma') {
                $('#div_selecao_turma').show();
                $('#div_selecao_aluno').hide();
                $('#msg_lancamento').html('<i class="fas fa-info-circle"></i> Serão lançadas mensalidades para TODOS os alunos ativos da turma selecionada.');
                $('select[name="turma_id"]').prop('required', true);
                $('select[name="aluno_id"]').prop('required', false);
            } else {
                $('#div_selecao_turma').hide();
                $('#div_selecao_aluno').show();
                $('#msg_lancamento').html('<i class="fas fa-info-circle"></i> Será lançada uma mensalidade apenas para o aluno selecionado.');
                $('select[name="turma_id"]').prop('required', false);
                $('select[name="aluno_id"]').prop('required', true);
            }
        });
        
        // Alternar entre desconto por turma e individual
        $('input[name="tipo_desconto"]').on('change', function() {
            if ($(this).val() == 'turma') {
                $('#div_desconto_turma').show();
                $('#div_desconto_aluno').hide();
                $('#msg_desconto').html('<i class="fas fa-info-circle"></i> O desconto será aplicado a TODAS as mensalidades pendentes da turma selecionada.');
                $('select[name="turma_id_desconto"]').prop('required', true);
                $('select[name="aluno_id_desconto"]').prop('required', false);
            } else {
                $('#div_desconto_turma').hide();
                $('#div_desconto_aluno').show();
                $('#msg_desconto').html('<i class="fas fa-info-circle"></i> O desconto será aplicado apenas à mensalidade do aluno selecionado.');
                $('select[name="turma_id_desconto"]').prop('required', false);
                $('select[name="aluno_id_desconto"]').prop('required', true);
            }
        });
        
        // Formatar valor
        function formatarMoedaInput(valor) {
            let v = valor.toString().replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('input[name="valor"]').on('input', function() {
            $(this).val(formatarMoedaInput($(this).val()));
        });
    </script>
</body>
</html>