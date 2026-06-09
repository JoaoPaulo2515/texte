<?php
// escola/aluno/financeiro/notas_fiscais.php - Notas Fiscais do Aluno

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
$titulo_pagina = 'Minhas Notas Fiscais';

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
$ano_filtro = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// NOTAS FISCAIS A PARTIR DOS PAGAMENTOS
// ==============================================

// Verificar se a tabela notas_fiscais existe
$sql_check = "SHOW TABLES LIKE 'notas_fiscais'";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->execute();
$tabela_notas_existe = $stmt_check->rowCount() > 0;

if ($tabela_notas_existe) {
    // Usar a tabela notas_fiscais se existir
    $sql_notas = "SELECT nf.*, p.referente, p.valor as pagamento_valor, p.numero_fatura
                  FROM notas_fiscais nf
                  LEFT JOIN pagamentos p ON p.id = nf.pagamento_id
                  WHERE nf.aluno_id = :aluno_id AND nf.escola_id = :escola_id";
    
    if ($ano_filtro > 0) {
        $sql_notas .= " AND YEAR(nf.data_emissao) = :ano";
    }
    if (!empty($busca)) {
        $sql_notas .= " AND (nf.numero_nota LIKE :busca OR nf.chave_acesso LIKE :busca)";
    }
    
    $sql_notas .= " ORDER BY nf.data_emissao DESC";
    
    $stmt_notas = $conn->prepare($sql_notas);
    $params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
    if ($ano_filtro > 0) $params[':ano'] = $ano_filtro;
    if (!empty($busca)) $params[':busca'] = "%$busca%";
    $stmt_notas->execute($params);
    $notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
} else {
    // Gerar notas fiscais virtuais a partir dos pagamentos
    $sql_pagamentos = "SELECT p.id, p.valor, p.referente, p.numero_fatura, p.data_pagamento, p.metodo_pagamento, p.status
                       FROM pagamentos p
                       WHERE p.assinatura_id = :aluno_id 
                       AND p.escola_id = :escola_id 
                       AND p.status IN ('pago', 'confirmado')";
    
    if ($ano_filtro > 0) {
        $sql_pagamentos .= " AND YEAR(p.data_pagamento) = :ano";
    }
    if (!empty($busca)) {
        $sql_pagamentos .= " AND (p.referente LIKE :busca OR p.numero_fatura LIKE :busca)";
    }
    
    $sql_pagamentos .= " ORDER BY p.data_pagamento DESC";
    
    $stmt_pagamentos = $conn->prepare($sql_pagamentos);
    $params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
    if ($ano_filtro > 0) $params[':ano'] = $ano_filtro;
    if (!empty($busca)) $params[':busca'] = "%$busca%";
    $stmt_pagamentos->execute($params);
    $pagamentos = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);
    
    // Converter pagamentos em notas fiscais virtuais
    $notas = [];
    foreach ($pagamentos as $index => $pag) {
        $notas[] = [
            'id' => $pag['id'],
            'numero_nota' => $pag['numero_fatura'] ?? ('NF-' . str_pad($pag['id'], 6, '0', STR_PAD_LEFT)),
            'serie' => '001',
            'modelo' => 'NF-e',
            'chave_acesso' => 'NFE-' . md5($pag['id'] . $pag['data_pagamento']),
            'data_emissao' => $pag['data_pagamento'],
            'valor' => $pag['valor'],
            'referente' => $pag['referente'],
            'status' => $pag['status'],
            'pdf_path' => null,
            'virtual' => true
        ];
    }
}

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_notas = count($notas);
$total_valor = array_sum(array_column($notas, 'valor'));
$total_autorizadas = 0;
$total_canceladas = 0;

foreach ($notas as $nota) {
    if (isset($nota['status']) && $nota['status'] == 'autorizada') {
        $total_autorizadas++;
    } elseif (isset($nota['status']) && $nota['status'] == 'cancelada') {
        $total_canceladas++;
    } else {
        $total_autorizadas++;
    }
}

// Anos disponíveis (a partir dos pagamentos)
$sql_anos = "SELECT DISTINCT YEAR(data_pagamento) as ano 
             FROM pagamentos 
             WHERE assinatura_id = :aluno_id AND escola_id = :escola_id 
             AND data_pagamento IS NOT NULL
             ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$anos_disponiveis = $stmt_anos->fetchAll(PDO::FETCH_COLUMN, 0);

if (empty($anos_disponiveis)) {
    $anos_disponiveis = [date('Y')];
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function getStatusNotaBadge($status) {
    if ($status == 'autorizada' || $status == 'pago' || $status == 'confirmado') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Autorizada</span>';
    } elseif ($status == 'cancelada') {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelada</span>';
    } else {
        return '<span class="badge bg-warning">Pendente</span>';
    }
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
        
        .nota-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .nota-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            background: #f8f9fa;
        }
        .nota-body {
            padding: 20px;
        }
        .nota-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .codigo-acesso {
            font-family: monospace;
            font-size: 0.8rem;
            background: #f0f0f0;
            padding: 5px 10px;
            border-radius: 5px;
            display: inline-block;
            word-break: break-all;
        }
        
        .btn-nota {
            background: #006B3E;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
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
        
        .badge-virtual {
            background: #17a2b8;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
    </style>
</head>
<body>

   <?php include 'includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Notas Fiscais</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todas as notas fiscais emitidas para seus pagamentos.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status</div>
                <div class="ajuda-texto">
                    <span class="badge bg-success">Autorizada</span> - Nota fiscal válida<br>
                    <span class="badge bg-danger">Cancelada</span> - Nota fiscal cancelada
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Chave de Acesso</div>
                <div class="ajuda-texto">Código único para consulta e verificação da autenticidade da nota fiscal.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Download</div>
                <div class="ajuda-texto">Clique em "Baixar PDF" para fazer o download da nota fiscal.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-file-invoice-dollar"></i> Minhas Notas Fiscais</h4>
            <p class="text-muted mb-0">Consulte e baixe suas notas fiscais</p>
        </div>
        <div>
            <button class="btn btn-secondary" onclick="window.print();">
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
                    <small class="text-muted"><i class="fas fa-file-invoice"></i> Total</small>
                    <h6 class="mb-0"><?php echo $total_notas; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_autorizadas; ?></div>
                <div class="stat-label">Notas Autorizadas</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-primary"><?php echo formatarMoeda($total_valor); ?> KZ</div>
                <div class="stat-label">Valor Total</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-value text-info"><?php echo $total_notas; ?></div>
                <div class="stat-label">Notas Emitidas</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano</label>
                    <select name="ano" class="form-select" onchange="this.form.submit()">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_disponiveis as $ano): ?>
                        <option value="<?php echo $ano; ?>" <?php echo $ano_filtro == $ano ? 'selected' : ''; ?>><?php echo $ano; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Nº Nota, referente ou chave de acesso..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="notas_fiscais.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Notas Fiscais -->
    <?php if (empty($notas)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma nota fiscal encontrada</h5>
            <p>Não foram encontradas notas fiscais para os filtros selecionados.</p>
        </div>
    <?php else: ?>
        <div class="notas-list">
            <?php foreach ($notas as $nota): ?>
            <div class="nota-card fade-in">
                <div class="nota-header">
                    <div>
                        <strong>Nota Fiscal <?php echo $nota['modelo'] ?? 'NF-e'; ?> Nº <?php echo $nota['numero_nota']; ?></strong>
                        <span class="ms-2 badge bg-secondary">Série: <?php echo $nota['serie'] ?? '001'; ?></span>
                        <?php if (isset($nota['virtual']) && $nota['virtual']): ?>
                        <span class="badge-virtual ms-2"><i class="fas fa-cloud"></i> Virtual</span>
                        <?php endif; ?>
                    </div>
                    <div><?php echo getStatusNotaBadge($nota['status'] ?? 'autorizada'); ?></div>
                </div>
                <div class="nota-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Data de Emissão:</strong> <?php echo date('d/m/Y H:i', strtotime($nota['data_emissao'])); ?>
                            </div>
                            <div class="mb-2">
                                <strong>Valor:</strong> 
                                <span class="text-success fw-bold"><?php echo formatarMoeda($nota['valor']); ?> KZ</span>
                            </div>
                            <?php if (isset($nota['referente'])): ?>
                            <div class="mb-2">
                                <strong>Referente:</strong> <?php echo htmlspecialchars($nota['referente']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <strong>Chave de Acesso:</strong><br>
                                <span class="codigo-acesso"><?php echo $nota['chave_acesso']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="nota-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">
                                <i class="fas fa-qrcode"></i> Consultar autenticidade no site da AT
                            </small>
                        </div>
                        <div>
                            <button class="btn-nota" onclick="visualizarNota(<?php echo $nota['id']; ?>, <?php echo isset($nota['virtual']) && $nota['virtual'] ? 'true' : 'false'; ?>)">
    <i class="fas fa-eye"></i> Visualizar
</button>
<button class="btn-nota" onclick="baixarNota(<?php echo $nota['id']; ?>, <?php echo isset($nota['virtual']) && $nota['virtual'] ? 'true' : 'false'; ?>)" style="background: #17a2b8;">
    <i class="fas fa-download"></i> Baixar PDF
</button>
                            <button class="btn btn-sm btn-outline-secondary ms-2" onclick="consultarNota('<?php echo $nota['chave_acesso']; ?>')">
                                <i class="fas fa-search"></i> Consultar
                            </button>
                        </div>
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
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    /*
    // Função para baixar nota
function baixarNota(id, isVirtual = false) {
    if (isVirtual) {
        if (confirm('Esta é uma nota fiscal virtual gerada a partir do pagamento. Deseja prosseguir com a geração do PDF?')) {
            window.open('gerar_nota_fiscal.php?id=' + id + '&tipo=virtual', '_blank');
        }
    } else {
        window.open('gerar_nota_fiscal.php?id=' + id, '_blank');
    }
}*/
// Função para baixar nota
function baixarNota(id, isVirtual = false) {
    if (isVirtual) {
        if (confirm('Esta é uma nota fiscal virtual gerada a partir do pagamento. Deseja prosseguir com a geração do PDF?')) {
            window.open('gerar_nota_fiscal.php?id=' + id + '&tipo=virtual&acao=baixar', '_blank');
        }
    } else {
        window.open('gerar_nota_fiscal.php?id=' + id + '&acao=baixar', '_blank');
    }
}

// Função para visualizar nota
function visualizarNota(id, isVirtual = false) {
    if (isVirtual) {
        window.open('gerar_nota_fiscal.php?id=' + id + '&tipo=virtual&acao=visualizar', '_blank');
    } else {
        window.open('gerar_nota_fiscal.php?id=' + id + '&acao=visualizar', '_blank');
    }
}

// Função para consultar nota
function consultarNota(chave) {
    window.open('https://www.portaldasfinancas.gov.ao/consultar/' + chave, '_blank');
}
    
    // Função para consultar nota
    function consultarNota(chave) {
        window.open('https://www.portaldasfinancas.gov.ao/consultar/' + chave, '_blank');
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