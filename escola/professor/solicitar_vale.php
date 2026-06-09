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
    INNER JOIN professores p ON p.usuario_id = f.usuario_id
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
                    <tr><th>Parcela</th><th>Data Prevista</th><th>Valor</th></tr>
                </thead>
                <tbody>';
    
    for ($i = 1; $i <= $parcelas; $i++) {
        $data_parcela = date('d/m/Y', strtotime("+$i month"));
        $carta .= '<tr><td>' . $i . 'ª</td><td>' . $data_parcela . '</td><td>KZ ' . $valor_parcela_extenso . '</td></tr>';
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
function formatarMoeda($valor) { return number_format($valor, 2, ',', '.'); }
function formatarData($data) { if (empty($data)) return '-'; return date('d/m/Y', strtotime($data)); }

function getStatusBadge($status) {
    switch ($status) {
        case 'pendente': return '<span class="badge badge-pendente"><i class="fas fa-clock"></i> Pendente</span>';
        case 'aprovado': return '<span class="badge badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado': return '<span class="badge badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'pago': return '<span class="badge badge-pago"><i class="fas fa-money-bill-wave"></i> Pago</span>';
        case 'cancelado': return '<span class="badge badge-cancelado"><i class="fas fa-ban"></i> Cancelado</span>';
        default: return '<span class="badge badge-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Solicitar Vale | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
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
           PAGE HEADER
        ============================================ */
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
            content: '💰';
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
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar, .btn-ajuda, .btn-print {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-ajuda {
            background: linear-gradient(135deg, #fd7e14 0%, #e66a00 100%);
            color: white;
        }

        .btn-ajuda:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3);
            color: white;
        }

        .btn-print {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
            color: white;
        }

        .btn-solicitar {
            background: var(--primary-gradient);
            color: white;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            transition: var(--transition);
            border: none;
        }

        .btn-solicitar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0, 107, 62, 0.4);
            color: white;
        }

        /* ============================================
           CARDS
        ============================================ */
        .info-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 25px;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .info-title {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 18px 24px;
            font-weight: 700;
            color: var(--primary-green);
            border-bottom: 2px solid var(--primary-green);
            font-size: 1.1rem;
        }

        .info-title i {
            margin-right: 10px;
            color: var(--primary-green);
        }

        .info-body {
            padding: 24px;
        }

        /* ============================================
           STATS CARDS
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
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
        }

        .stat-card.success .stat-number { color: var(--success); }
        .stat-card.warning .stat-number { color: var(--warning); }
        .stat-card.info .stat-number { color: var(--info); }
        .stat-card.danger .stat-number { color: var(--danger); }

        /* ============================================
           ALERTA DE LIMITE
        ============================================ */
        .alerta-limite {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 4px solid var(--success);
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .alerta-limite::before {
            content: '💰';
            position: absolute;
            right: 10px;
            bottom: 10px;
            font-size: 40px;
            opacity: 0.2;
        }

        /* ============================================
           FORMULÁRIO
        ============================================ */
        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .form-control.is-invalid {
            border-color: var(--danger);
        }

        /* Upload Area */
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: #f8f9fa;
        }

        .upload-area:hover {
            border-color: var(--primary-green);
            background: #e8f5e9;
            transform: translateY(-2px);
        }

        .upload-area.dragover {
            border-color: var(--success);
            background: #d4edda;
        }

        /* ============================================
           SOLICITAÇÃO CARD
        ============================================ */
        .solicitacao-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 15px;
            padding: 18px;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .solicitacao-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .solicitacao-card.aprovado { border-left-color: var(--success); }
        .solicitacao-card.reprovado { border-left-color: var(--danger); }
        .solicitacao-card.pago { border-left-color: var(--info); }

        /* ============================================
           DIVIDA CARD
        ============================================ */
        .divida-card {
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
            border-radius: 16px;
            margin-bottom: 15px;
            padding: 18px;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
        }

        .divida-card:hover {
            transform: translateX(5px);
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-pendente { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-aprovado { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-reprovado { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-pago { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        .badge-cancelado { background: #6c757d; color: white; }
        .badge-secondary { background: #6c757d; color: white; }

        .badge-divida {
            background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.65rem;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        /* ============================================
           BOTÕES DE AÇÃO
        ============================================ */
        .btn-carta {
            background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
            color: white;
            border-radius: 30px;
            padding: 5px 15px;
            font-size: 0.7rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
        }

        .btn-carta:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.3);
            color: white;
        }

        /* ============================================
           HELP SECTION
        ============================================ */
        .help-step {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: var(--transition);
        }

        .help-step:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .help-number {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .help-content h6 {
            color: var(--primary-green);
            margin-bottom: 5px;
            font-weight: 700;
        }

        /* ============================================
           PROGRESS BAR
        ============================================ */
        .progress-custom {
            height: 8px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }

        .progress-bar-custom {
            height: 100%;
            border-radius: 10px;
            background: linear-gradient(90deg, var(--success), #20c997);
            transition: width 0.5s ease;
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
        }

        .modal-header-custom .btn-close-white {
            filter: brightness(0) invert(1);
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .fade-in-up { animation: fadeInUp 0.6s ease-out; }
        .slide-in-left { animation: slideInLeft 0.6s ease-out; }
        .slide-in-right { animation: slideInRight 0.6s ease-out; }
        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

        .animated {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           SCROLLBAR
        ============================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .info-row {
                flex-direction: column;
                gap: 5px;
            }
            
            .btn-voltar, .btn-ajuda, .btn-print {
                padding: 8px 16px;
                font-size: 0.75rem;
            }
            
            .page-header h2 {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-ajuda, .btn-print, .filter-card {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .info-card, .stat-card, .solicitacao-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content" id="mainContent">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-hand-holding-usd me-2"></i> Solicitar Vale</h2>
                    <p>Solicite adiantamento salarial (Vale) - Até 30% do seu salário</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button type="button" class="btn-ajuda" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                        <i class="fas fa-question-circle"></i> Como Funciona
                    </button>
                    <button type="button" class="btn-print" data-bs-toggle="modal" data-bs-target="#modalModeloCarta">
                        <i class="fas fa-file-alt"></i> Ver Modelo
                    </button>
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Alerta de Limite -->
        <div class="alerta-limite fade-in-up">
            <i class="fas fa-info-circle text-success me-2"></i>
            <strong>📌 Informação Importante:</strong><br>
            Seu salário base é de <strong>KZ <?php echo $salario_formatado; ?></strong>. 
            Você pode solicitar até <strong>30% (KZ <?php echo $limite_formatado; ?>)</strong> de adiantamento.
            O valor será descontado em até <strong>3 parcelas</strong> na sua folha de pagamento.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Formulário de Solicitação -->
            <div class="col-md-5">
                <div class="info-card slide-in-left">
                    <div class="info-title"><i class="fas fa-edit"></i> Nova Solicitação</div>
                    <div class="info-body">
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
                                    <option value="transferencia">🏦 Transferência Bancária</option>
                                    <option value="deposito">💰 Depósito</option>
                                    <option value="dinheiro">💵 Dinheiro</option>
                                    <option value="cheque">📝 Cheque</option>
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
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Como funciona?</strong> Após enviar a solicitação, a administração irá analisar.
                                Se aprovado, uma dívida será gerada automaticamente e uma carta modelo será criada.
                            </div>
                            
                            <button type="button" class="btn-solicitar w-100" onclick="confirmarSolicitacao()">
                                <i class="fas fa-paper-plane"></i> Solicitar Vale
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Estatísticas e Histórico -->
            <div class="col-md-7">
                <div class="stats-grid">
                    <div class="stat-card slide-in-right delay-1">
                        <div class="stat-number">KZ <?php echo formatarMoeda($total_solicitado); ?></div>
                        <div class="stat-label">Total Solicitado</div>
                    </div>
                    <div class="stat-card success slide-in-right delay-2">
                        <div class="stat-number">KZ <?php echo formatarMoeda($total_aprovado); ?></div>
                        <div class="stat-label">Total Aprovado</div>
                    </div>
                    <div class="stat-card warning slide-in-right delay-3">
                        <div class="stat-number"><?php echo $total_pendente; ?></div>
                        <div class="stat-label">Solicitações Pendentes</div>
                    </div>
                </div>
                
                <!-- Dívidas Ativas -->
                <?php if (!empty($dividas_ativas)): ?>
                <div class="info-card fade-in-up">
                    <div class="info-title"><i class="fas fa-exclamation-triangle"></i> Dívidas em Aberto</div>
                    <div class="info-body">
                        <?php foreach ($dividas_ativas as $divida): ?>
                        <div class="divida-card">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div>
                                    <strong>Dívida #<?php echo $divida['id']; ?></strong>
                                </div>
                                <div>
                                    <span class="badge bg-primary"><i class="fas fa-exclamation-triangle"></i> Em Aberto</span>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <small>Total:</small>
                                <strong>KZ <?php echo formatarMoeda($divida['valor_total']); ?></strong>
                            </div>
                            <div class="progress-custom mb-2">
                                <?php 
                                $percentual = ($divida['valor_total'] - $divida['valor_pendente']) / $divida['valor_total'] * 100;
                                ?>
                                <div class="progress-bar-custom" style="width: <?php echo $percentual; ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between small">
                                <span><i class="fas fa-chart-line"></i> Parcelas: <?php echo $divida['parcelas_pagas']; ?>/<?php echo $divida['parcelas_total']; ?></span>
                                <span><i class="fas fa-clock"></i> Restante: KZ <?php echo formatarMoeda($divida['valor_pendente']); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Histórico de Solicitações -->
                <div class="info-card fade-in-up">
                    <div class="info-title">
                        <i class="fas fa-history"></i> Histórico de Solicitações
                        <button class="btn btn-sm btn-outline-secondary float-end" onclick="atualizarStatus()">
                            <i class="fas fa-sync-alt"></i> Atualizar
                        </button>
                    </div>
                    <div class="info-body" id="historicoContainer">
                        <?php if (empty($solicitacoes)): ?>
                            <p class="text-muted text-center py-4">Nenhuma solicitação encontrada.</p>
                        <?php else: ?>
                            <?php foreach ($solicitacoes as $s): ?>
                            <div class="solicitacao-card <?php echo $s['status']; ?>" data-id="<?php echo $s['id']; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong class="fs-5">KZ <?php echo formatarMoeda($s['valor_solicitado']); ?></strong>
                                        <br>
                                        <small class="text-muted"><i class="fas fa-calendar"></i> <?php echo formatarData($s['created_at']); ?></small>
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
                                    <br><small class="badge-divida mt-1"><i class="fas fa-link"></i> Dívida: #<?php echo $s['divida_id']; ?> (<?php echo $s['divida_status']; ?>)</small>
                                    <br><small><i class="fas fa-credit-card"></i> Total: KZ <?php echo formatarMoeda($s['divida_total']); ?> | Pago: KZ <?php echo formatarMoeda($s['divida_pago']); ?> | Pendente: KZ <?php echo formatarMoeda($s['divida_pendente']); ?></small>
                                    <?php endif; ?>
                                    <?php if ($s['carta_gerada']): ?>
                                    <br><button class="btn-carta mt-2" onclick="visualizarCarta(<?php echo $s['id']; ?>)"><i class="fas fa-file-alt"></i> Ver Carta</button>
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
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Como Solicitar Vale?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-hand-holding-usd fa-4x mb-3" style="color: #006B3E;"></i>
                        <h4>Sistema de Solicitação de Vale</h4>
                        <p class="text-muted">Entenda como funciona o processo</p>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6><i class="fas fa-edit"></i> Preencher Solicitação</h6>
                            <p>Informe o valor desejado (até 30% do seu salário), motivo e descrição detalhada.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6><i class="fas fa-paper-plane"></i> Enviar para Análise</h6>
                            <p>A solicitação é enviada para a administração da escola para análise e aprovação.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6><i class="fas fa-clock"></i> Aguardar Aprovação</h6>
                            <p>A administração tem até 3 dias úteis para responder sua solicitação.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">4</div>
                        <div class="help-content">
                            <h6><i class="fas fa-link"></i> Geração de Dívida</h6>
                            <p>Se aprovado, uma dívida é gerada automaticamente no sistema "Dívidas a Pagar".</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">5</div>
                        <div class="help-content">
                            <h6><i class="fas fa-calculator"></i> Desconto em Folha</h6>
                            <p>O valor será descontado da sua folha de pagamento no número de parcelas escolhido.</p>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i> <strong>Dicas Importantes:</strong>
                        <ul class="mb-0 mt-2">
                            <li>✅ O valor máximo é 30% do seu salário base</li>
                            <li>✅ O desconto é feito automaticamente na folha</li>
                            <li>✅ Solicitações urgentes têm prioridade na análise</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Modelo de Carta -->
    <div class="modal fade" id="modalModeloCarta" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i> Modelo de Carta de Solicitação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Este é o modelo de carta que será gerado automaticamente após sua solicitação.
                    </div>
                    <div class="modelo-carta">
                        <div style="font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; background: #f9f9f9;">
                            <div class="text-center mb-4">
                                <h3><?php echo htmlspecialchars($escola_nome); ?></h3>
                                <p><strong>Pedido de Adiantamento Salarial (Vale)</strong></p>
                            </div>
                            <p><strong>À Direção,</strong></p>
                            <p>Eu, <strong><?php echo htmlspecialchars($funcionario_nome); ?></strong>, venho solicitar um adiantamento salarial no valor de <strong>KZ [VALOR]</strong>.</p>
                            <p><strong>Motivo:</strong> [MOTIVO]</p>
                            <p><strong>Forma de Pagamento:</strong> Desconto em [N] parcela(s) na folha.</p>
                            <div class="text-center mt-5">
                                <div style="border-top: 1px solid #000; width: 250px; margin: 0 auto; padding-top: 10px;">
                                    <strong><?php echo htmlspecialchars($funcionario_nome); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: var(--success); color: white;">
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
        let limiteMaximo = <?php echo $limite_vale; ?>;
        
        document.getElementById('valor_solicitado').addEventListener('input', function() {
            let valor = parseFloat(this.value);
            let alerta = document.getElementById('valor_alerta');
            if (valor > limiteMaximo) {
                alerta.style.display = 'block';
                alerta.innerHTML = '⚠️ O valor solicitado excede o limite máximo de KZ <?php echo $limite_formatado; ?>';
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
                let maxSize = 5 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert('Arquivo muito grande! O tamanho máximo é 5MB.');
                    this.value = '';
                    return;
                }
                document.getElementById('fileName').innerText = file.name;
                document.getElementById('fileInfo').style.display = 'block';
            }
        });
        
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
            document.getElementById('confirm_parcelas').innerHTML = '📅 Parcelas: ' + parcelas + 'x';
            document.getElementById('confirm_valor_parcela').innerHTML = '💵 Valor parcela: KZ ' + valorParcela.toFixed(2).replace('.', ',');
            
            new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
        }
        
        document.getElementById('btnConfirmarEnvio').addEventListener('click', function() {
            document.getElementById('formSolicitacao').submit();
        });
        
        function visualizarCarta(id) {
            window.open('solicitar_vale.php?visualizar_carta=1&id=' + id, '_blank', 'width=800,height=600');
        }
        
        function atualizarStatus() {
            fetch(window.location.href + '?ajax=1')
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const novoHistorico = doc.getElementById('historicoContainer').innerHTML;
                    if (novoHistorico) {
                        document.getElementById('historicoContainer').innerHTML = novoHistorico;
                        const container = document.getElementById('historicoContainer');
                        container.classList.add('animated');
                        setTimeout(() => container.classList.remove('animated'), 500);
                    }
                })
                .catch(error => console.error('Erro ao atualizar:', error));
        }
        
        setInterval(atualizarStatus, 30000);
        
        // Animações ao scroll
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.info-card, .stat-card, .alerta-limite, .solicitacao-card').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>