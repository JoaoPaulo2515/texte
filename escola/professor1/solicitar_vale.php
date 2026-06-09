<?php
// escola/professor/solicitar_vale.php - Solicitar Vale com Carta Modelo

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR FUNCIONARIO_ID DO PROFESSOR LOGADO
// ============================================
$sql_funcionario = "
    SELECT f.id as funcionario_id, f.nome, f.cargo, f.salario_base, 
           f.banco, f.conta_bancaria, f.endereco, f.telefone, f.email,
           e.nome as escola_nome, e.endereco as escola_endereco
    FROM funcionarios f
    INNER JOIN funcionarios p ON p.usuario_id = f.usuario_id
    LEFT JOIN escolas e ON e.id = f.escola_id
    WHERE p.id = :professor_id
";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $funcionario['funcionario_id'] ?? $professor_id;
$funcionario_nome = $funcionario['nome'] ?? '';
$funcionario_salario = $funcionario['salario_base'] ?? 0;
$escola_nome = $funcionario['escola_nome'] ?? '';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR LIMITE DE VALE (30% do salário)
// ============================================
$limite_vale = $funcionario_salario * 0.3;
$salario_formatado = number_format($funcionario_salario, 2, ',', '.');
$limite_formatado = number_format($limite_vale, 2, ',', '.');

// ============================================
// PROCESSAR SOLICITAÇÃO
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_vale'])) {
    $valor_solicitado = (float)$_POST['valor_solicitado'];
    $motivo = $_POST['motivo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $data_necessidade = $_POST['data_necessidade'] ?? date('Y-m-d');
    $parcelas = (int)$_POST['parcelas'] ?? 1;
    $forma_recebimento = $_POST['forma_recebimento'] ?? 'transferencia';
    
    if ($valor_solicitado <= 0) {
        $error = "⚠️ O valor solicitado deve ser maior que zero.";
    } elseif ($valor_solicitado > $limite_vale && $limite_vale > 0) {
        $error = "⚠️ O valor solicitado excede o limite disponível de KZ $limite_formatado (30% do seu salário).";
    } elseif (empty($motivo)) {
        $error = "⚠️ Por favor, informe o motivo da solicitação.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Processar upload de arquivo
            $arquivo_path = null;
            if (isset($_FILES['documento_anexo']) && $_FILES['documento_anexo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = __DIR__ . '/../../uploads/solicitacoes/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $extensao = strtolower(pathinfo($_FILES['documento_anexo']['name'], PATHINFO_EXTENSION));
                $extensoes_permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                
                if (in_array($extensao, $extensoes_permitidas)) {
                    $nome_arquivo = 'solicitacao_' . time() . '_' . uniqid() . '.' . $extensao;
                    $arquivo_path = 'uploads/solicitacoes/' . $nome_arquivo;
                    $caminho_completo = $upload_dir . $nome_arquivo;
                    
                    if (move_uploaded_file($_FILES['documento_anexo']['tmp_name'], $caminho_completo)) {
                        // Arquivo salvo com sucesso
                    } else {
                        throw new Exception("Erro ao fazer upload do arquivo.");
                    }
                } else {
                    throw new Exception("Tipo de arquivo não permitido. Use: PDF, JPG, PNG, DOC");
                }
            }
            
            // INSERIR SOLICITAÇÃO
            $sql = "INSERT INTO solicitacoes_vale (
                        funcionario_id, escola_id, ano_letivo_id, 
                        valor_solicitado, motivo, descricao, data_necessidade, 
                        parcelas, forma_recebimento, documento_anexo, status
                    ) VALUES (
                        :funcionario_id, :escola_id, :ano_letivo_id,
                        :valor_solicitado, :motivo, :descricao, :data_necessidade,
                        :parcelas, :forma_recebimento, :documento_anexo, 'pendente'
                    )";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':funcionario_id' => $funcionario_id,
                ':escola_id' => $escola_id,
                ':ano_letivo_id' => $ano_letivo_id,
                ':valor_solicitado' => $valor_solicitado,
                ':motivo' => $motivo,
                ':descricao' => $descricao,
                ':data_necessidade' => $data_necessidade,
                ':parcelas' => $parcelas,
                ':forma_recebimento' => $forma_recebimento,
                ':documento_anexo' => $arquivo_path
            ]);
            
            $solicitacao_id = $conn->lastInsertId();
            
            // REGISTRAR HISTÓRICO
            $sql_hist = "INSERT INTO vale_historico (solicitacao_id, acao, observacao) 
                        VALUES (:id, 'solicitado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':id' => $solicitacao_id,
                ':obs' => "Solicitação de vale no valor de KZ " . number_format($valor_solicitado, 2, ',', '.')
            ]);
            
            $conn->commit();
            $success = "✅ Solicitação de vale enviada com sucesso! Aguarde a aprovação da administração.";
            
            // Gerar carta modelo automaticamente
            gerarCartaModelo($solicitacao_id, $funcionario, $valor_solicitado, $motivo, $descricao, $parcelas);
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Erro ao solicitar vale: " . $e->getMessage();
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ============================================
// FUNÇÃO PARA GERAR CARTA MODELO
// ============================================
function gerarCartaModelo($solicitacao_id, $funcionario, $valor, $motivo, $descricao, $parcelas) {
    $data_atual = date('d/m/Y');
    $valor_extenso = number_format($valor, 2, ',', '.');
    $parcelas_extenso = $parcelas == 1 ? 'única parcela' : $parcelas . ' parcelas';
    $valor_parcela = $valor / $parcelas;
    $valor_parcela_extenso = number_format($valor_parcela, 2, ',', '.');
    
    $carta = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Pedido de Vale - ' . htmlspecialchars($funcionario['nome']) . '</title>
        <style>
            body { font-family: "DejaVu Sans", Arial, sans-serif; margin: 40px; line-height: 1.5; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #006B3E; padding-bottom: 15px; }
            .logo { font-size: 24px; font-weight: bold; color: #006B3E; }
            .titulo { text-align: center; font-size: 18px; font-weight: bold; margin: 30px 0; text-decoration: underline; }
            .conteudo { margin: 20px 0; text-align: justify; }
            .assinatura { margin-top: 50px; text-align: center; }
            .linha-assinatura { width: 250px; border-top: 1px solid #000; margin: 30px auto 0; }
            .footer { margin-top: 50px; text-align: center; font-size: 10px; color: #666; border-top: 1px solid #ddd; padding-top: 20px; }
            .detalhes { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
            .tabela-parcelas { width: 100%; border-collapse: collapse; margin: 20px 0; }
            .tabela-parcelas th, .tabela-parcelas td { border: 1px solid #ddd; padding: 8px; text-align: center; }
            .tabela-parcelas th { background: #006B3E; color: white; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo">' . htmlspecialchars($funcionario['escola_nome'] ?? 'SIGE') . '</div>
            <div>Pedido de Adiantamento Salarial (Vale)</div>
            <div><small>Protocolo: VALE-' . str_pad($solicitacao_id, 6, '0', STR_PAD_LEFT) . '</small></div>
        </div>
        
        <div class="titulo">PEDIDO DE VALE SALARIAL</div>
        
        <div class="conteudo">
            <p><strong>À Direção da ' . htmlspecialchars($funcionario['escola_nome'] ?? 'Instituição') . ',</strong></p>
            <p>Eu, <strong>' . htmlspecialchars($funcionario['nome']) . '</strong>, portador(a) do cargo de <strong>' . htmlspecialchars($funcionario['cargo']) . '</strong>, venho por meio deste solicitar um adiantamento salarial (VALE) no valor de <strong>KZ ' . $valor_extenso . '</strong>.</p>
            
            <div class="detalhes">
                <p><strong>📅 Data da Solicitação:</strong> ' . $data_atual . '</p>
                <p><strong>💰 Valor Total:</strong> KZ ' . $valor_extenso . '</p>
                <p><strong>📊 Número de Parcelas:</strong> ' . $parcelas_extenso . '</p>
                <p><strong>💵 Valor por Parcela:</strong> KZ ' . $valor_parcela_extenso . '</p>
                <p><strong>📝 Motivo:</strong> ' . htmlspecialchars($motivo) . '</p>
            </div>
            
            <p><strong>Descrição detalhada:</strong></p>
            <p>' . nl2br(htmlspecialchars($descricao)) . '</p>
            
            <p><strong>Declaração:</strong></p>
            <p>Declaro estar ciente de que o valor solicitado será descontado diretamente do meu salário em <strong>' . $parcelas_extenso . '</strong>, conforme tabela de amortização abaixo:</p>
            
            <table class="tabela-parcelas">
                <thead>
                    <tr>
                        <th>Parcela</th>
                        <th>Data Prevista</th>
                        <th>Valor</th>
                    </tr>
                </thead>
                <tbody>';
    
    for ($i = 1; $i <= $parcelas; $i++) {
        $data_parcela = date('d/m/Y', strtotime("+$i month"));
        $carta .= '
                    <tr>
                        <td>' . $i . 'ª</td>
                        <td>' . $data_parcela . '</td>
                        <td>KZ ' . $valor_parcela_extenso . '</td>
                    </tr>';
    }
    
    $carta .= '
                </tbody>
            </table>
            
            <p>Atenciosamente,</p>
        </div>
        
        <div class="assinatura">
            <div class="linha-assinatura"></div>
            <div><strong>' . htmlspecialchars($funcionario['nome']) . '</strong></div>
            <div>Funcionário - ' . htmlspecialchars($funcionario['cargo']) . '</div>
            <div>' . $data_atual . '</div>
        </div>
        
        <div class="footer">
            <p>Documento gerado eletronicamente pelo SIGE Angola.<br>
            Este documento tem validade mediante assinatura do funcionário e aprovação da direção.</p>
            <p><strong>Para uso da Administração:</strong><br>
            Aprovado em: ___/___/_____<br>
            Valor Aprovado: ___________<br>
            Assinatura Direção: ________________________</p>
        </div>
    </body>
    </html>';
    
    // Salvar carta em arquivo
    $diretorio = __DIR__ . '/../../uploads/cartas/';
    if (!file_exists($diretorio)) {
        mkdir($diretorio, 0777, true);
    }
    $nome_arquivo = 'carta_vale_' . $solicitacao_id . '_' . date('Ymd_His') . '.html';
    file_put_contents($diretorio . $nome_arquivo, $carta);
    
    // Atualizar campo na tabela
    global $conn;
    $carta_path = 'uploads/cartas/' . $nome_arquivo;
    $sql = "UPDATE solicitacoes_vale SET carta_gerada = 1, carta_path = :path WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':path' => $carta_path, ':id' => $solicitacao_id]);
    
    return $carta_path;
}

// ============================================
// FUNÇÃO PARA VISUALIZAR CARTA MODELO
// ============================================
if (isset($_GET['visualizar_carta']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sql = "SELECT carta_path FROM solicitacoes_vale WHERE id = :id AND funcionario_id = :fid";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':fid' => $funcionario_id]);
    $carta = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($carta && file_exists(__DIR__ . '/../../' . $carta['carta_path'])) {
        $conteudo = file_get_contents(__DIR__ . '/../../' . $carta['carta_path']);
        echo $conteudo;
        exit;
    } else {
        echo "<h3>Carta não encontrada</h3>";
        exit;
    }
}

// ============================================
// BUSCAR SOLICITAÇÕES ANTERIORES COM ASSOCIAÇÃO
// ============================================
$sql_solicitacoes = "
    SELECT s.*,
           CASE 
               WHEN s.status = 'pendente' THEN 'Pendente'
               WHEN s.status = 'aprovado' THEN 'Aprovado'
               WHEN s.status = 'reprovado' THEN 'Reprovado'
               WHEN s.status = 'pago' THEN 'Pago'
               WHEN s.status = 'cancelado' THEN 'Cancelado'
               ELSE s.status
           END as status_texto,
           d.id as divida_id,
           d.status as divida_status,
           d.valor_total as divida_total,
           d.valor_pago as divida_pago,
           d.valor_pendente as divida_pendente,
           d.parcelas_total,
           d.parcelas_pagas
    FROM solicitacoes_vale s
    LEFT JOIN dividas_a_pagar d ON d.referencia_id = s.id AND d.tipo = 'vale'
    WHERE s.funcionario_id = :funcionario_id
    ORDER BY s.created_at DESC
";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([':funcionario_id' => $funcionario_id]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DÍVIDAS ATIVAS DO FUNCIONÁRIO
// ============================================
$sql_dividas_ativas = "
    SELECT d.*, 
           (SELECT COUNT(*) FROM divida_parcelas WHERE divida_id = d.id AND status = 'pendente') as parcelas_restantes
    FROM dividas_a_pagar d
    WHERE d.funcionario_id = :funcionario_id AND d.status = 'ativa'
    ORDER BY d.created_at DESC
";
$stmt_dividas = $conn->prepare($sql_dividas_ativas);
$stmt_dividas->execute([':funcionario_id' => $funcionario_id]);
$dividas_ativas = $stmt_dividas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_solicitado = 0;
$total_aprovado = 0;
$total_pendente = 0;
$total_pago = 0;
$total_dividas_ativas = 0;
$total_valor_dividas = 0;

foreach ($solicitacoes as $s) {
    $total_solicitado += $s['valor_solicitado'];
    if ($s['status'] == 'aprovado') $total_aprovado += $s['valor_solicitado'];
    if ($s['status'] == 'pendente') $total_pendente++;
    if ($s['status'] == 'pago') $total_pago++;
}

foreach ($dividas_ativas as $d) {
    $total_dividas_ativas++;
    $total_valor_dividas += $d['valor_pendente'];
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.');
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'aprovado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'pago':
            return '<span class="badge bg-info"><i class="fas fa-money-bill-wave"></i> Pago</span>';
        case 'cancelado':
            return '<span class="badge bg-secondary"><i class="fas fa-ban"></i> Cancelado</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Vale | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .info-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .info-title { font-size: 1.1em; font-weight: bold; color: #006B3E; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #006B3E; }
        .info-row { margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .info-label { font-weight: bold; color: #666; }
        .info-value { font-weight: 500; color: #333; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 28px; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 12px; color: #666; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; text-decoration: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .btn-solicitar { background: #28a745; color: white; border-radius: 25px; padding: 10px 30px; border: none; font-weight: bold; }
        .btn-solicitar:hover { background: #1e7e34; color: white; }
        .btn-ajuda { background: #fd7e14; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-ajuda:hover { background: #e66a00; color: white; }
        .btn-carta { background: #17a2b8; color: white; border-radius: 20px; padding: 5px 15px; font-size: 12px; border: none; }
        .btn-carta:hover { background: #138496; color: white; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .solicitacao-card { background: white; border-radius: 12px; margin-bottom: 15px; padding: 15px; border-left: 4px solid #ffc107; transition: transform 0.2s; }
        .solicitacao-card:hover { transform: translateX(5px); }
        .solicitacao-card.aprovado { border-left-color: #28a745; }
        .solicitacao-card.reprovado { border-left-color: #dc3545; }
        .solicitacao-card.pago { border-left-color: #17a2b8; }
        .divida-card { background: #fff3cd; border-radius: 12px; margin-bottom: 15px; padding: 15px; border-left: 4px solid #ffc107; }
        .upload-area { border: 2px dashed #ddd; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { border-color: #006B3E; background: #f5f5f5; }
        .upload-area.dragover { border-color: #28a745; background: #e8f5e9; }
        .help-step { display: flex; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        .help-number { width: 40px; height: 40px; background: #006B3E; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; margin-right: 15px; }
        .help-content { flex: 1; }
        .help-content h6 { margin-bottom: 5px; color: #006B3E; }
        .help-content p { margin-bottom: 0; font-size: 13px; color: #666; }
        .alerta-limite { background: #e8f5e9; border-left: 4px solid #28a745; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .badge-divida { background: #6f42c1; color: white; padding: 2px 8px; border-radius: 15px; font-size: 10px; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animated { animation: fadeInUp 0.5s ease-out; }
        .preview-modal { max-width: 800px; }
        .preview-iframe { width: 100%; height: 500px; border: none; }
        .btn-preview { background: #6c757d; color: white; border-radius: 20px; padding: 3px 12px; font-size: 11px; }
        .btn-preview:hover { background: #5a6268; color: white; }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-hand-holding-usd"></i> Solicitar Vale</h2>
                    <p>Solicite adiantamento salarial (Vale) - Até 30% do seu salário</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <button type="button" class="btn-ajuda btn me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda"><i class="fas fa-question-circle"></i> Como Funciona</button>
                    <button type="button" class="btn-ajuda btn" style="background: #17a2b8;" data-bs-toggle="modal" data-bs-target="#modalModeloCarta"><i class="fas fa-file-alt"></i> Ver Modelo de Carta</button>
                    <button onclick="window.print()" class="btn-voltar btn ms-2" style="background: #17a2b8;"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
        
        <!-- Alerta de Limite -->
        <div class="alerta-limite">
            <i class="fas fa-info-circle text-success"></i> 
            <strong>📌 Informação Importante:</strong><br>
            Seu salário base é de <strong>KZ <?php echo $salario_formatado; ?></strong>. 
            Você pode solicitar até <strong>30% (KZ <?php echo $limite_formatado; ?>)</strong> de adiantamento.
            O valor será descontado em até <strong>3 parcelas</strong> na sua folha de pagamento.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Formulário de Solicitação -->
            <div class="col-md-5">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-edit"></i> Nova Solicitação</div>
                    <form method="POST" id="formSolicitacao" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Valor Solicitado (KZ) *</label>
                            <input type="number" step="0.01" name="valor_solicitado" id="valor_solicitado" class="form-control" 
                                   max="<?php echo $limite_vale; ?>" min="1" required>
                            <small class="text-muted">Máximo: KZ <?php echo $limite_formatado; ?></small>
                            <div id="valor_alerta" class="text-danger small mt-1" style="display: none;"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Número de Parcelas *</label>
                            <select name="parcelas" id="parcelas" class="form-select" required>
                                <option value="1">1x (Desconto total no próximo salário)</option>
                                <option value="2">2x (Desconto em 2 meses)</option>
                                <option value="3">3x (Desconto em 3 meses)</option>
                            </select>
                            <small class="text-muted">O valor da parcela será calculado automaticamente</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Forma de Recebimento *</label>
                            <select name="forma_recebimento" class="form-select" required>
                                <option value="transferencia">Transferência Bancária</option>
                                <option value="deposito">Depósito</option>
                                <option value="dinheiro">Dinheiro</option>
                                <option value="cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Motivo da Solicitação *</label>
                            <select name="motivo" id="motivo" class="form-select" required>
                                <option value="">Selecione...</option>
                                <option value="Emergência Médica">🩺 Emergência Médica</option>
                                <option value="Despesas Familiares">👨‍👩‍👧‍👦 Despesas Familiares</option>
                                <option value="Educação">📚 Educação</option>
                                <option value="Habitação">🏠 Habitação</option>
                                <option value="Transporte">🚗 Transporte</option>
                                <option value="Outros">📌 Outros</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição Detalhada</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="3" placeholder="Descreva detalhadamente o motivo da solicitação..."></textarea>
                        </div>
                        
                        <!-- Upload de Documento -->
                        <div class="mb-3">
                            <label class="form-label">Documentos de Apoio (Opcional)</label>
                            <div class="upload-area" id="uploadArea" onclick="document.getElementById('documento_anexo').click()">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                <p class="mb-0">Clique para fazer upload ou arraste arquivos aqui</p>
                                <small class="text-muted">Formatos permitidos: PDF, JPG, PNG, DOC (Max: 5MB)</small>
                                <input type="file" name="documento_anexo" id="documento_anexo" style="display: none;" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            </div>
                            <div id="fileInfo" class="mt-2" style="display: none;">
                                <div class="alert alert-info">
                                    <i class="fas fa-file"></i> <span id="fileName"></span>
                                    <button type="button" class="btn-close float-end" onclick="removerArquivo()"></button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Data em que precisa do valor</label>
                            <input type="date" name="data_necessidade" class="form-control" value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>">
                        </div>
                        
                        <div class="alert alert-info small">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Como funciona?</strong> Após enviar a solicitação, a administração irá analisar.
                            Se aprovado, uma dívida será gerada automaticamente em "Dívidas a Pagar" e uma carta modelo será criada.
                        </div>
                        
                        <button type="button" class="btn btn-solicitar w-100" onclick="confirmarSolicitacao()">
                            <i class="fas fa-paper-plane"></i> Solicitar Vale
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Estatísticas e Histórico -->
            <div class="col-md-7">
                <div class="row">
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number" id="totalSolicitado">KZ <?php echo formatarMoeda($total_solicitado); ?></div><div class="stat-label">Total Solicitado</div></div></div>
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number text-success" id="totalAprovado">KZ <?php echo formatarMoeda($total_aprovado); ?></div><div class="stat-label">Total Aprovado</div></div></div>
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number text-warning" id="totalPendente"><?php echo $total_pendente; ?></div><div class="stat-label">Solicitações Pendentes</div></div></div>
                </div>
                
                <!-- Dívidas Ativas -->
                <?php if (!empty($dividas_ativas)): ?>
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-exclamation-triangle"></i> Dívidas em Aberto</div>
                    <?php foreach ($dividas_ativas as $divida): ?>
                    <div class="divida-card">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>Dívida #<?php echo $divida['id']; ?></strong>
                                <br>
                                <small>Total: KZ <?php echo formatarMoeda($divida['valor_total']); ?></small>
                            </div>
                            <div>
                                <?php if ($divida['status'] == 'ativa'): ?>
                                    <span class="badge bg-primary"><i class="fas fa-exclamation-triangle"></i> Em Aberto</span>
                                <?php elseif ($divida['status'] == 'paga'): ?>
                                    <span class="badge bg-success"><i class="fas fa-check-circle"></i> Quitada</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $divida['status']; ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="progress mb-2" style="height: 8px;">
                                <?php 
                                $percentual = ($divida['valor_total'] - $divida['valor_pendente']) / $divida['valor_total'] * 100;
                                ?>
                                <div class="progress-bar bg-success" style="width: <?php echo $percentual; ?>%"></div>
                            </div>
                            <small>
                                <i class="fas fa-chart-line"></i> Parcelas: <?php echo $divida['parcelas_pagas']; ?>/<?php echo $divida['parcelas_total']; ?> pagas
                                <br>
                                <i class="fas fa-clock"></i> Restante: KZ <?php echo formatarMoeda($divida['valor_pendente']); ?>
                                <br>
                                <i class="fas fa-calendar"></i> Próxima parcela: <?php echo $divida['parcelas_restantes'] > 0 ? formatarData($divida['primeira_parcela']) : '-'; ?>
                            </small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Histórico de Solicitações com Atualização Automática -->
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-history"></i> Histórico de Solicitações
                        <button class="btn btn-sm btn-outline-secondary float-end" onclick="atualizarStatus()"><i class="fas fa-sync-alt"></i> Atualizar</button>
                    </div>
                    <div id="historicoContainer">
                        <?php if (empty($solicitacoes)): ?>
                            <p class="text-muted text-center">Nenhuma solicitação encontrada.</p>
                        <?php else: ?>
                            <?php foreach ($solicitacoes as $s): ?>
                            <div class="solicitacao-card <?php echo $s['status']; ?>" data-id="<?php echo $s['id']; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>KZ <?php echo formatarMoeda($s['valor_solicitado']); ?></strong>
                                        <br>
                                        <small class="text-muted"><?php echo formatarData($s['created_at']); ?></small>
                                    </div>
                                    <div>
                                        <?php echo getStatusBadge($s['status']); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small><i class="fas fa-tag"></i> Motivo: <?php echo htmlspecialchars($s['motivo']); ?></small><br>
                                    <small><i class="fas fa-chart-line"></i> Parcelas: <?php echo $s['parcelas']; ?>x</small>
                                    <?php if ($s['documento_anexo']): ?>
                                    <br><small><i class="fas fa-paperclip"></i> <a href="<?php echo $s['documento_anexo']; ?>" target="_blank">Documento anexado</a></small>
                                    <?php endif; ?>
                                    <?php if ($s['divida_id']): ?>
                                    <br><small class="badge-divida"><i class="fas fa-link"></i> Dívida: #<?php echo $s['divida_id']; ?> (<?php echo $s['divida_status']; ?>)</small>
                                    <br><small><i class="fas fa-credit-card"></i> Total: KZ <?php echo formatarMoeda($s['divida_total']); ?> | Pago: KZ <?php echo formatarMoeda($s['divida_pago']); ?> | Pendente: KZ <?php echo formatarMoeda($s['divida_pendente']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($s['carta_gerada']): ?>
                                    <br><button class="btn btn-carta btn-sm mt-1" onclick="visualizarCarta(<?php echo $s['id']; ?>)"><i class="fas fa-file-alt"></i> Ver Carta</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Como Solicitar Vale?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4"><i class="fas fa-hand-holding-usd fa-4x text-primary mb-3"></i><h4>Sistema de Solicitação de Vale</h4><p class="text-muted">Entenda como funciona o processo</p></div>
                    
                    <div class="help-step"><div class="help-number">1</div><div class="help-content"><h6><i class="fas fa-edit text-primary"></i> Preencher Solicitação</h6><p>Informe o valor desejado (até 30% do seu salário), motivo e descrição detalhada.</p></div></div>
                    <div class="help-step"><div class="help-number">2</div><div class="help-content"><h6><i class="fas fa-paper-plane text-primary"></i> Enviar para Análise</h6><p>A solicitação é enviada para a administração da escola para análise e aprovação. Uma carta modelo é gerada automaticamente.</p></div></div>
                    <div class="help-step"><div class="help-number">3</div><div class="help-content"><h6><i class="fas fa-clock text-primary"></i> Aguardar Aprovação</h6><p>A administração tem até 3 dias úteis para responder sua solicitação.</p></div></div>
                    <div class="help-step"><div class="help-number">4</div><div class="help-content"><h6><i class="fas fa-link text-primary"></i> Geração de Dívida</h6><p>Se aprovado, uma dívida é gerada automaticamente no sistema "Dívidas a Pagar" para controle do desconto.</p></div></div>
                    <div class="help-step"><div class="help-number">5</div><div class="help-content"><h6><i class="fas fa-calculator text-primary"></i> Desconto em Folha</h6><p>O valor será descontado da sua folha de pagamento no número de parcelas escolhido.</p></div></div>
                    <div class="alert alert-info mt-3"><i class="fas fa-lightbulb"></i> <strong>Dicas Importantes:</strong><ul class="mb-0 mt-2"><li>✅ O valor máximo é 30% do seu salário base</li><li>✅ O desconto é feito automaticamente na folha</li><li>✅ Solicitações urgentes têm prioridade na análise</li><li>✅ A carta modelo pode ser impressa e entregue na administração</li></ul></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal"><i class="fas fa-check"></i> Entendi</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal Modelo de Carta -->
    <div class="modal fade" id="modalModeloCarta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> Modelo de Carta de Solicitação de Vale</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Este é o modelo de carta que será gerado automaticamente após sua solicitação.
                        Você pode visualizar e imprimir para suas referências.
                    </div>
                    
                    <div class="modelo-carta">
                        <div style="font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; background: #f9f9f9;">
                            <div class="text-center mb-4">
                                <h3><?php echo htmlspecialchars($escola_nome); ?></h3>
                                <p><strong>Pedido de Adiantamento Salarial (Vale)</strong></p>
                                <p><small>Protocolo: VALE-XXXXXX</small></p>
                            </div>
                            
                            <p><strong>À Direção,</strong></p>
                            
                            <p>Eu, <strong><?php echo htmlspecialchars($funcionario_nome); ?></strong>, portador(a) do cargo de <strong><?php echo htmlspecialchars($funcionario['cargo'] ?? 'Professor'); ?></strong>, venho por meio deste solicitar um adiantamento salarial (VALE) no valor de <strong>KZ [VALOR_SOLICITADO]</strong>.</p>
                            
                            <p><strong>Motivo:</strong> [MOTIVO_DESCRITO]</p>
                            
                            <p><strong>Descrição:</strong> [DESCRICAO_DETALHADA]</p>
                            
                            <p><strong>Forma de Pagamento:</strong> O valor será descontado da folha de pagamento em [NUMERO_PARCELAS] parcela(s).</p>
                            
                            <p>Declaro estar ciente de que o valor solicitado será descontado diretamente do meu salário nos meses subsequentes, conforme acordado com a instituição.</p>
                            
                            <div class="text-center mt-5">
                                <div style="border-top: 1px solid #000; width: 250px; margin: 0 auto; padding-top: 10px;">
                                    <strong><?php echo htmlspecialchars($funcionario_nome); ?></strong><br>
                                    Funcionário<br>
                                    [DATA_ATUAL]
                                </div>
                            </div>
                            
                            <div class="text-center mt-5">
                                <p><small>Documento gerado automaticamente pelo SIGE Angola</small></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Modelo</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #28a745; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Confirmar Solicitação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja solicitar este vale?</p>
                    <div class="alert alert-info">
                        <strong>Detalhes da Solicitação:</strong><br>
                        <span id="confirm_valor"></span><br>
                        <span id="confirm_parcelas"></span><br>
                        <span id="confirm_valor_parcela"></span>
                    </div>
                    <p class="text-muted small">Após confirmar, sua solicitação será enviada para análise da administração. Uma carta modelo será gerada automaticamente.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarEnvio">Sim, Solicitar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let valorSolicitado = 0;
        let parcelas = 1;
        let limiteMaximo = <?php echo $limite_vale; ?>;
        let arquivoSelecionado = null;
        
        document.getElementById('valor_solicitado').addEventListener('input', function() {
            let valor = parseFloat(this.value);
            let alerta = document.getElementById('valor_alerta');
            if (valor > limiteMaximo) {
                alerta.style.display = 'block';
                alerta.innerHTML = '⚠️ O valor solicitado excede o limite máximo de KZ <?php echo $limite_formatado; ?> (30% do seu salário)';
                this.classList.add('is-invalid');
            } else {
                alerta.style.display = 'none';
                this.classList.remove('is-invalid');
            }
        });
        
        // Upload de arquivo
        document.getElementById('documento_anexo').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                let file = this.files[0];
                let maxSize = 5 * 1024 * 1024; // 5MB
                
                if (file.size > maxSize) {
                    alert('Arquivo muito grande! O tamanho máximo é 5MB.');
                    this.value = '';
                    return;
                }
                
                arquivoSelecionado = file;
                document.getElementById('fileName').innerText = file.name;
                document.getElementById('fileInfo').style.display = 'block';
            }
        });
        
        // Drag and drop
        const uploadArea = document.getElementById('uploadArea');
        
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                document.getElementById('documento_anexo').files = files;
                const event = new Event('change');
                document.getElementById('documento_anexo').dispatchEvent(event);
            }
        });
        
        function removerArquivo() {
            document.getElementById('documento_anexo').value = '';
            document.getElementById('fileInfo').style.display = 'none';
            arquivoSelecionado = null;
        }
        
        function confirmarSolicitacao() {
            let valor = parseFloat(document.getElementById('valor_solicitado').value);
            let parcelas = parseInt(document.getElementById('parcelas').value);
            let motivo = document.getElementById('motivo').value;
            
            if (!valor || valor <= 0) {
                alert('Por favor, informe o valor solicitado.');
                return;
            }
            if (valor > limiteMaximo) {
                alert('O valor solicitado excede o limite máximo permitido.');
                return;
            }
            if (!motivo) {
                alert('Por favor, selecione o motivo da solicitação.');
                return;
            }
            
            let valorParcela = valor / parcelas;
            
            document.getElementById('confirm_valor').innerHTML = '💰 Valor: KZ ' + valor.toFixed(2).replace('.', ',');
            document.getElementById('confirm_parcelas').innerHTML = '📅 Número de parcelas: ' + parcelas + 'x';
            document.getElementById('confirm_valor_parcela').innerHTML = '💵 Valor da parcela: KZ ' + valorParcela.toFixed(2).replace('.', ',');
            
            valorSolicitado = valor;
            
            new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
        }
        
        document.getElementById('btnConfirmarEnvio').addEventListener('click', function() {
            document.getElementById('formSolicitacao').submit();
        });
        
        function visualizarCarta(id) {
            window.open('solicitar_vale.php?visualizar_carta=1&id=' + id, '_blank', 'width=800,height=600');
        }
        
        // Atualização automática a cada 30 segundos
        function atualizarStatus() {
            fetch(window.location.href + '?ajax=1')
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const novoHistorico = doc.getElementById('historicoContainer').innerHTML;
                    const novasStats = {
                        totalSolicitado: doc.querySelector('#totalSolicitado')?.innerText || 'KZ 0',
                        totalAprovado: doc.querySelector('#totalAprovado')?.innerText || 'KZ 0',
                        totalPendente: doc.querySelector('#totalPendente')?.innerText || '0'
                    };
                    
                    document.getElementById('historicoContainer').innerHTML = novoHistorico;
                    document.getElementById('totalSolicitado').innerText = novasStats.totalSolicitado;
                    document.getElementById('totalAprovado').innerText = novasStats.totalAprovado;
                    document.getElementById('totalPendente').innerText = novasStats.totalPendente;
                    
                    // Adicionar animação
                    const container = document.getElementById('historicoContainer');
                    container.classList.add('animated');
                    setTimeout(() => container.classList.remove('animated'), 500);
                })
                .catch(error => console.error('Erro ao atualizar:', error));
        }
        
        // Atualizar a cada 30 segundos
        setInterval(atualizarStatus, 30000);
    </script>
</body>
</html>