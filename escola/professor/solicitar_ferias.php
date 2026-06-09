<?php
// escola/professor/solicitar_ferias.php - Solicitar Férias com Carta Modelo

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
           f.data_admissao, f.ferias_vencidas,
           f.banco, f.conta_bancaria, f.endereco, f.telefone, f.email,
           e.nome as escola_nome, e.endereco as escola_endereco,
           e.id as escola_id
    FROM funcionarios f
    INNER JOIN funcionarios p ON p.usuario_id = f.usuario_id
    LEFT JOIN escolas e ON e.id = f.escola_id
    WHERE p.id = :professor_id
";
$stmt_funcionario = $conn->prepare($sql_funcionario);
$stmt_funcionario->execute([':professor_id' => $professor_id]);
$funcionario = $stmt_funcionario->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die("Erro: Funcionário não encontrado.");
}

$funcionario_id = $funcionario['funcionario_id'];
$funcionario_nome = $funcionario['nome'];
$funcionario_cargo = $funcionario['cargo'] ?? 'Professor';
$funcionario_admissao = $funcionario['data_admissao'];
$escola_nome = $funcionario['escola_nome'] ?? '';
$escola_id = $funcionario['escola_id'] ?? 0;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_ano = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR SALDO DE FÉRIAS DO FUNCIONÁRIO
// ============================================
$ano_atual = date('Y');
$sql_saldo_ferias = "
    SELECT f.*, 
           (SELECT SUM(dias_solicitados) FROM solicitacoes_ferias 
            WHERE funcionario_id = :funcionario_id 
            AND status IN ('pendente', 'aprovado')
            AND YEAR(created_at) = :ano) as dias_solicitados_pendentes
    FROM ferias_funcionario f
    WHERE f.funcionario_id = :funcionario_id AND f.ano_referencia = :ano
";
$stmt_saldo = $conn->prepare($sql_saldo_ferias);
$stmt_saldo->execute([
    ':funcionario_id' => $funcionario_id,
    ':ano' => $ano_atual
]);
$saldo_ferias = $stmt_saldo->fetch(PDO::FETCH_ASSOC);

// Se não tiver registro, criar um novo com 30 dias
if (!$saldo_ferias) {
    $sql_insert = "INSERT INTO ferias_funcionario (funcionario_id, ano_referencia, dias_totais, dias_disponiveis) 
                   VALUES (:funcionario_id, :ano, 30, 30)";
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->execute([
        ':funcionario_id' => $funcionario_id,
        ':ano' => $ano_atual
    ]);
    
    $saldo_ferias = [
        'dias_totais' => 30,
        'dias_utilizados' => 0,
        'dias_disponiveis' => 30,
        'dias_pendentes' => 0
    ];
}

$dias_disponiveis = $saldo_ferias['dias_disponiveis'] - ($saldo_ferias['dias_pendentes'] ?? 0);
$dias_disponiveis = max(0, $dias_disponiveis);

// ============================================
// PROCESSAR SOLICITAÇÃO
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_ferias'])) {
    $data_inicio = $_POST['data_inicio'] ?? '';
    $data_fim = $_POST['data_fim'] ?? '';
    $motivo = $_POST['motivo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $periodo_referencia = $_POST['periodo_referencia'] ?? date('Y');
    
    // Validações
    if (empty($data_inicio) || empty($data_fim)) {
        $error = "⚠️ Por favor, informe as datas de início e fim das férias.";
    } elseif (strtotime($data_inicio) > strtotime($data_fim)) {
        $error = "⚠️ A data de início deve ser anterior à data de fim.";
    } elseif (strtotime($data_inicio) < strtotime(date('Y-m-d'))) {
        $error = "⚠️ A data de início não pode ser anterior à data atual.";
    } else {
        // Calcular dias de férias
        $data_inicio_obj = new DateTime($data_inicio);
        $data_fim_obj = new DateTime($data_fim);
        $intervalo = $data_inicio_obj->diff($data_fim_obj);
        $dias_calendario = $intervalo->days + 1;
        
        // Calcular dias úteis (segunda a sexta)
        $dias_uteis = 0;
        $periodo = new DatePeriod($data_inicio_obj, new DateInterval('P1D'), $data_fim_obj->modify('+1 day'));
        foreach ($periodo as $date) {
            $dia_semana = $date->format('N');
            if ($dia_semana < 6) { // Segunda a Sexta
                $dias_uteis++;
            }
        }
        
        $dias_solicitados = $dias_uteis; // Usar dias úteis para contar férias
        
        if ($dias_solicitados <= 0) {
            $error = "⚠️ O período selecionado não contém dias úteis.";
        } elseif ($dias_solicitados > $dias_disponiveis) {
            $error = "⚠️ Você não tem dias de férias suficientes. Disponível: $dias_disponiveis dias.";
        } elseif (empty($motivo)) {
            $error = "⚠️ Por favor, informe o motivo da solicitação.";
        } else {
            try {
                $conn->beginTransaction();
                
                // Processar upload de arquivo
                $arquivo_path = null;
                if (isset($_FILES['documento_anexo']) && $_FILES['documento_anexo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../../uploads/ferias/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $extensao = strtolower(pathinfo($_FILES['documento_anexo']['name'], PATHINFO_EXTENSION));
                    $extensoes_permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    
                    if (in_array($extensao, $extensoes_permitidas)) {
                        $nome_arquivo = 'ferias_' . time() . '_' . uniqid() . '.' . $extensao;
                        $arquivo_path = 'uploads/ferias/' . $nome_arquivo;
                        $caminho_completo = $upload_dir . $nome_arquivo;
                        
                        if (!move_uploaded_file($_FILES['documento_anexo']['tmp_name'], $caminho_completo)) {
                            throw new Exception("Erro ao fazer upload do arquivo.");
                        }
                    } else {
                        throw new Exception("Tipo de arquivo não permitido. Use: PDF, JPG, PNG, DOC");
                    }
                }
                
                // INSERIR SOLICITAÇÃO
                $sql = "INSERT INTO solicitacoes_ferias (
                            funcionario_id, escola_id, ano_letivo_id,
                            data_inicio, data_fim, dias_solicitados, 
                            dias_uteis, dias_calendario, motivo, descricao, 
                            periodo_referencia, documento_anexo, status
                        ) VALUES (
                            :funcionario_id, :escola_id, :ano_letivo_id,
                            :data_inicio, :data_fim, :dias_solicitados,
                            :dias_uteis, :dias_calendario, :motivo, :descricao,
                            :periodo_referencia, :documento_anexo, 'pendente'
                        )";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':funcionario_id' => $funcionario_id,
                    ':escola_id' => $escola_id,
                    ':ano_letivo_id' => $ano_letivo_id,
                    ':data_inicio' => $data_inicio,
                    ':data_fim' => $data_fim,
                    ':dias_solicitados' => $dias_solicitados,
                    ':dias_uteis' => $dias_uteis,
                    ':dias_calendario' => $dias_calendario,
                    ':motivo' => $motivo,
                    ':descricao' => $descricao,
                    ':periodo_referencia' => $periodo_referencia,
                    ':documento_anexo' => $arquivo_path
                ]);
                
                $solicitacao_id = $conn->lastInsertId();
                
                // Atualizar dias pendentes no saldo
                $sql_update_saldo = "UPDATE ferias_funcionario 
                                     SET dias_pendentes = dias_pendentes + :dias
                                     WHERE funcionario_id = :funcionario_id 
                                     AND ano_referencia = :ano";
                $stmt_update = $conn->prepare($sql_update_saldo);
                $stmt_update->execute([
                    ':dias' => $dias_solicitados,
                    ':funcionario_id' => $funcionario_id,
                    ':ano' => $periodo_referencia
                ]);
                
                // REGISTRAR HISTÓRICO
                $sql_hist = "INSERT INTO ferias_historico (solicitacao_id, acao, observacao) 
                            VALUES (:id, 'solicitado', :obs)";
                $stmt_hist = $conn->prepare($sql_hist);
                $stmt_hist->execute([
                    ':id' => $solicitacao_id,
                    ':obs' => "Solicitação de férias de $dias_solicitados dias (de $data_inicio a $data_fim)"
                ]);
                
                $conn->commit();
                $success = "✅ Solicitação de férias enviada com sucesso! Aguarde a aprovação da administração.";
                
                // Gerar carta modelo automaticamente
                gerarCartaModeloFerias($solicitacao_id, $funcionario, $data_inicio, $data_fim, $dias_solicitados, $motivo, $descricao);
                
            } catch (PDOException $e) {
                $conn->rollBack();
                $error = "Erro ao solicitar férias: " . $e->getMessage();
                error_log("Erro PDO: " . $e->getMessage());
            } catch (Exception $e) {
                $conn->rollBack();
                $error = $e->getMessage();
                error_log("Erro: " . $e->getMessage());
            }
        }
    }
}

// ============================================
// FUNÇÃO PARA GERAR CARTA MODELO DE FÉRIAS
// ============================================
function gerarCartaModeloFerias($solicitacao_id, $funcionario, $data_inicio, $data_fim, $dias, $motivo, $descricao) {
    global $conn;
    
    $data_atual = date('d/m/Y');
    $data_inicio_f = date('d/m/Y', strtotime($data_inicio));
    $data_fim_f = date('d/m/Y', strtotime($data_fim));
    
    // Calcular data de retorno
    $data_retorno = date('d/m/Y', strtotime($data_fim . ' +1 day'));
    
    $carta = '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Pedido de Férias - ' . htmlspecialchars($funcionario['nome']) . '</title>
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
            .info-table { width: 100%; border-collapse: collapse; }
            .info-table td { padding: 8px; border-bottom: 1px solid #ddd; }
            .info-table td:first-child { font-weight: bold; width: 40%; }
        </style>
    </head>
    <body>
        <div class="header">
            <div class="logo">' . htmlspecialchars($funcionario['escola_nome'] ?? 'SIGE') . '</div>
            <div>Pedido de Férias Anuais</div>
            <div><small>Protocolo: FERIAS-' . str_pad($solicitacao_id, 6, '0', STR_PAD_LEFT) . '</small></div>
        </div>
        
        <div class="titulo">REQUERIMENTO DE FÉRIAS</div>
        
        <div class="conteudo">
            <p><strong>À Direção da ' . htmlspecialchars($funcionario['escola_nome'] ?? 'Instituição') . ',</strong></p>
            <p>Eu, <strong>' . htmlspecialchars($funcionario['nome']) . '</strong>, portador(a) do cargo de <strong>' . htmlspecialchars($funcionario['cargo']) . '</strong>, venho por meio deste solicitar minhas férias anuais, conforme previsto na legislação trabalhista.</p>
            
            <div class="detalhes">
                <table class="info-table">
                    <tr><td>📅 Período Solicitado:</td><td><strong>' . $data_inicio_f . ' a ' . $data_fim_f . '</strong></td></tr>
                    <tr><td>📊 Total de Dias:</td><td><strong>' . $dias . ' dias úteis</strong></td></tr>
                    <tr><td>📌 Data de Retorno:</td><td><strong>' . $data_retorno . '</strong></td></tr>
                    <tr><td>🎯 Motivo:</td><td>' . htmlspecialchars($motivo) . '</td></tr>
                </table>
            </div>
            
            <p><strong>Descrição detalhada:</strong></p>
            <p>' . nl2br(htmlspecialchars($descricao)) . '</p>
            
            <p><strong>Declaração:</strong></p>
            <p>Declaro que estou ciente de que:</p>
            <ul>
                <li>As aulas serão cobertas por outro professor durante meu período de ausência;</li>
                <li>Devo entregar todo o material e planejamento das aulas antes do início das férias;</li>
                <li>Devo manter contato em caso de emergência durante o período;</li>
                <li>As atividades letivas serão retomadas normalmente no retorno.</li>
            </ul>
            
            <p>Atenciosamente,</p>
        </div>
        
        <div class="assinatura">
            <div class="linha-assinatura"></div>
            <div><strong>' . htmlspecialchars($funcionario['nome']) . '</strong></div>
            <div>' . htmlspecialchars($funcionario['cargo']) . '</div>
            <div>' . $data_atual . '</div>
        </div>
        
        <div class="footer">
            <p>Documento gerado eletronicamente pelo SIGE Angola.<br>
            Este documento tem validade mediante assinatura do funcionário e aprovação da direção.</p>
            <p><strong>Para uso da Administração:</strong><br>
            Aprovado em: ___/___/_____<br>
            Substituído por: ________________________<br>
            Assinatura Direção: ________________________</p>
        </div>
    </body>
    </html>';
    
    // Salvar carta em arquivo
    $diretorio = __DIR__ . '/../../uploads/cartas_ferias/';
    if (!file_exists($diretorio)) {
        mkdir($diretorio, 0777, true);
    }
    $nome_arquivo = 'carta_ferias_' . $solicitacao_id . '_' . date('Ymd_His') . '.html';
    $caminho_completo = $diretorio . $nome_arquivo;
    file_put_contents($caminho_completo, $carta);
    
    // Atualizar campo na tabela
    $carta_path = 'uploads/cartas_ferias/' . $nome_arquivo;
    $sql = "UPDATE solicitacoes_ferias SET carta_gerada = 1, carta_path = :path WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':path' => $carta_path, ':id' => $solicitacao_id]);
    
    return $carta_path;
}

// ============================================
// FUNÇÃO PARA VISUALIZAR CARTA MODELO
// ============================================
if (isset($_GET['visualizar_carta']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $sql = "SELECT carta_path FROM solicitacoes_ferias WHERE id = :id AND funcionario_id = :fid";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':fid' => $funcionario_id]);
    $carta = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($carta && !empty($carta['carta_path']) && file_exists(__DIR__ . '/../../' . $carta['carta_path'])) {
        $conteudo = file_get_contents(__DIR__ . '/../../' . $carta['carta_path']);
        echo $conteudo;
        exit;
    } else {
        echo "<h3>Carta não encontrada</h3>";
        exit;
    }
}

// ============================================
// BUSCAR SOLICITAÇÕES ANTERIORES
// ============================================
$sql_solicitacoes = "
    SELECT s.*,
           CASE 
               WHEN s.status = 'pendente' THEN 'Pendente'
               WHEN s.status = 'aprovado' THEN 'Aprovado'
               WHEN s.status = 'reprovado' THEN 'Reprovado'
               WHEN s.status = 'cancelado' THEN 'Cancelado'
               ELSE s.status
           END as status_texto
    FROM solicitacoes_ferias s
    WHERE s.funcionario_id = :funcionario_id
    ORDER BY s.created_at DESC
";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([':funcionario_id' => $funcionario_id]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_solicitado = 0;
$total_aprovado = 0;
$total_pendente = 0;
$total_dias_utilizados = 0;

foreach ($solicitacoes as $s) {
    $total_solicitado += $s['dias_solicitados'];
    if ($s['status'] == 'aprovado') {
        $total_aprovado += $s['dias_solicitados'];
        $total_dias_utilizados += $s['dias_solicitados'];
    }
    if ($s['status'] == 'pendente') $total_pendente++;
}

$dias_restantes = $dias_disponiveis - $total_dias_utilizados;
$dias_restantes = max(0, $dias_restantes);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadgeFerias($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge-pendente"><i class="fas fa-clock"></i> Pendente</span>';
        case 'aprovado':
            return '<span class="badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'cancelado':
            return '<span class="badge-cancelado"><i class="fas fa-ban"></i> Cancelado</span>';
        default:
            return '<span class="badge-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Férias | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           VARIÁVEIS E RESET
        ============================================ */
        :root {
            --primary-color: #006B3E;
            --primary-dark: #004d2b;
            --primary-light: #e8f5e9;
            --secondary-color: #1A2A6C;
            --accent-color: #FF6B35;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
            --dark: #2c3e50;
            --gray: #6c757d;
            --light-gray: #f8f9fa;
            --border-radius: 16px;
            --box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px 15px;
            }
        }

        /* ============================================
           PAGE HEADER MODERN
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            border-radius: var(--border-radius);
            padding: 30px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            pointer-events: none;
        }

        .page-header h2 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .page-header p {
            opacity: 0.9;
            margin-bottom: 0;
            font-size: 0.95rem;
        }

        /* Botões modernos */
        .btn-modern {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: var(--transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-modern i {
            font-size: 1rem;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-ajuda {
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            color: white;
        }

        .btn-ajuda:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-solicitar {
            background: linear-gradient(135deg, var(--success), #1e7e34);
            color: white;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            width: 100%;
        }

        .btn-solicitar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.4);
        }

        /* ============================================
           CARDS MODERNOS
        ============================================ */
        .info-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
        }

        .info-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 3px solid var(--primary-color);
            display: inline-block;
        }

        /* ============================================
           STAT CARDS
        ============================================ */
        .stat-card {
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            transition: var(--transition);
            box-shadow: var(--box-shadow);
            border: 1px solid rgba(0, 0, 0, 0.05);
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
            background: linear-gradient(90deg, var(--primary-color), var(--secondary-color));
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--gray);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ============================================
           FORMULÁRIO MODERNO
        ============================================ */
        .form-label {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 12px 16px;
            transition: var(--transition);
            font-size: 0.95rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        /* Upload Area Moderna */
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 30px 20px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: var(--light-gray);
        }

        .upload-area:hover {
            border-color: var(--primary-color);
            background: var(--primary-light);
            transform: scale(1.02);
        }

        .upload-area.dragover {
            border-color: var(--success);
            background: rgba(40, 167, 69, 0.05);
        }

        /* ============================================
           SOLICITAÇÃO CARD
        ============================================ */
        .solicitacao-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 15px;
            padding: 20px;
            transition: var(--transition);
            border-left: 4px solid var(--warning);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .solicitacao-card:hover {
            transform: translateX(8px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .solicitacao-card.aprovado {
            border-left-color: var(--success);
        }

        .solicitacao-card.reprovado {
            border-left-color: var(--danger);
        }

        /* Badges Modernos */
        .badge-pendente, .badge-aprovado, .badge-reprovado, .badge-cancelado, .badge-secondary {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-pendente {
            background: linear-gradient(135deg, #ffc107, #ff9800);
            color: #fff;
        }

        .badge-aprovado {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: #fff;
        }

        .badge-reprovado {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: #fff;
        }

        .badge-cancelado {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: #fff;
        }

        .badge-secondary {
            background: linear-gradient(135deg, #6c757d, #5a6268);
            color: #fff;
        }

        /* Botão Carta */
        .btn-carta {
            background: linear-gradient(135deg, var(--info), #138496);
            color: white;
            border-radius: 50px;
            padding: 6px 16px;
            font-size: 0.75rem;
            font-weight: 600;
            border: none;
            transition: var(--transition);
        }

        .btn-carta:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.3);
        }

        /* ============================================
           SALDO ALERTA
        ============================================ */
        .alerta-saldo {
            background: linear-gradient(135deg, #e8f5e9, #c8e6d9);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 4px solid var(--success);
        }

        .saldo-destaque {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-color);
        }

        /* ============================================
           MODAL MODERNO
        ============================================ */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border: none;
            padding: 20px 25px;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            border-top: 1px solid #e9ecef;
            padding: 20px 25px;
        }

        /* ============================================
           HELP STEPS
        ============================================ */
        .help-step {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: var(--light-gray);
            border-radius: 12px;
            transition: var(--transition);
        }

        .help-step:hover {
            transform: translateX(5px);
            background: #fff;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        }

        .help-number {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .help-content h6 {
            font-weight: 700;
            margin-bottom: 5px;
            color: var(--dark);
        }

        .help-content p {
            margin-bottom: 0;
            font-size: 0.85rem;
            color: var(--gray);
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

        .animated {
            animation: fadeInUp 0.6s ease-out;
        }

        /* ============================================
           SCROLLBAR PERSONALIZADA
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
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .page-header {
                padding: 20px;
            }
            
            .page-header h2 {
                font-size: 1.3rem;
            }
            
            .stat-number {
                font-size: 1.8rem;
            }
            
            .btn-modern {
                padding: 6px 12px;
                font-size: 0.8rem;
            }
            
            .info-card {
                padding: 20px;
            }
        }

        /* ============================================
           PRINT STYLES
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-ajuda, .btn-solicitar, .modal, .alerta-saldo {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .info-card, .stat-card {
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
        </br></br>
    <div class="main-content" id="mainContent">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-umbrella-beach me-2"></i>Solicitar Férias</h2>
                    <p class="mb-0">Solicite suas férias anuais - Até 30 dias por ano</p>
                </div>
                <div class="no-print d-flex gap-2 flex-wrap">
                    <a href="dashboard.php" class="btn-voltar btn-modern"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <button type="button" class="btn-ajuda btn-modern" data-bs-toggle="modal" data-bs-target="#modalAjuda"><i class="fas fa-question-circle"></i> Como Funciona</button>
                    <button type="button" class="btn-ajuda btn-modern" style="background: rgba(23, 162, 184, 0.2);" data-bs-toggle="modal" data-bs-target="#modalModeloCarta"><i class="fas fa-file-alt"></i> Ver Modelo</button>
                    <button onclick="window.print()" class="btn-voltar btn-modern"><i class="fas fa-print"></i> Imprimir</button>
                </div>
            </div>
        </div>
        
        <!-- Alerta de Saldo de Férias -->
        <div class="alerta-saldo animated">
            <div class="row align-items-center">
                <div class="col-md-7">
                    <div class="d-flex align-items-center gap-3">
                        <div class="flex-shrink-0">
                            <i class="fas fa-calendar-alt fa-3x text-success"></i>
                        </div>
                        <div>
                            <strong class="fs-5">📌 Seu Saldo de Férias</strong><br>
                            <span class="text-muted">Ano referência: <strong><?php echo $ano_atual; ?></strong></span>
                        </div>
                    </div>
                </div>
                <div class="col-md-5 text-md-end mt-3 mt-md-0">
                    <span class="saldo-destaque"><?php echo $dias_restantes; ?></span>
                    <div class="text-muted small">dias disponíveis</div>
                    <small>Total: <?php echo $saldo_ferias['dias_totais']; ?> dias | Utilizados: <?php echo $total_dias_utilizados; ?> dias</small>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show animated" role="alert" style="border-radius: 12px;">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show animated" role="alert" style="border-radius: 12px;">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row g-4">
            <!-- Formulário de Solicitação -->
            <div class="col-lg-5">
                <div class="info-card animated" style="animation-delay: 0.1s;">
                    <div class="info-title"><i class="fas fa-edit me-2"></i> Nova Solicitação de Férias</div>
                    <form method="POST" id="formSolicitacao" enctype="multipart/form-data">
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-calendar-alt me-1 text-primary"></i> Data de Início *</label>
                            <input type="date" name="data_inicio" id="data_inicio" class="form-control" 
                                   min="<?php echo date('Y-m-d', strtotime('+1 days')); ?>" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-calendar-check me-1 text-primary"></i> Data de Fim *</label>
                            <input type="date" name="data_fim" id="data_fim" class="form-control" required>
                            <small class="text-muted mt-2 d-block" id="info_dias"></small>
                        </div>
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-chart-line me-1 text-primary"></i> Ano de Referência *</label>
                            <select name="periodo_referencia" class="form-select" required>
                                <option value="<?php echo $ano_atual; ?>"><?php echo $ano_atual; ?></option>
                                <option value="<?php echo $ano_atual - 1; ?>"><?php echo $ano_atual - 1; ?> (Férias vencidas)</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-tag me-1 text-primary"></i> Motivo da Solicitação *</label>
                            <select name="motivo" id="motivo" class="form-select" required>
                                <option value="">Selecione...</option>
                                <option value="Férias Anuais Regulares">🏖️ Férias Anuais Regulares</option>
                                <option value="Descanso e Lazer">🌴 Descanso e Lazer</option>
                                <option value="Viagem">✈️ Viagem</option>
                                <option value="Assuntos Pessoais">🏠 Assuntos Pessoais</option>
                                <option value="Saúde">🩺 Saúde e Descanso Médico</option>
                                <option value="Estudos">📚 Capacitação / Estudos</option>
                                <option value="Outros">📌 Outros</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-align-left me-1 text-primary"></i> Descrição Detalhada</label>
                            <textarea name="descricao" class="form-control" rows="4" 
                                      placeholder="Descreva detalhadamente o planejamento para o período de férias..."></textarea>
                        </div>
                        
                        <!-- Upload de Documento -->
                        <div class="mb-4">
                            <label class="form-label"><i class="fas fa-paperclip me-1 text-primary"></i> Documentos de Apoio (Opcional)</label>
                            <div class="upload-area" id="uploadArea" onclick="document.getElementById('documento_anexo').click()">
                                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                <p class="mb-1 fw-semibold">Clique para fazer upload</p>
                                <p class="small text-muted mb-0">ou arraste arquivos aqui</p>
                                <small class="text-muted">Formatos: PDF, JPG, PNG, DOC (Max: 5MB)</small>
                                <input type="file" name="documento_anexo" id="documento_anexo" style="display: none;" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            </div>
                            <div id="fileInfo" class="mt-3" style="display: none;">
                                <div class="alert alert-info d-flex align-items-center justify-content-between mb-0" style="border-radius: 12px;">
                                    <div>
                                        <i class="fas fa-file me-2"></i> <span id="fileName"></span>
                                    </div>
                                    <button type="button" class="btn-close" onclick="removerArquivo()"></button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info small mb-4" style="border-radius: 12px; background: #e8f4fd; border: none;">
                            <i class="fas fa-info-circle me-2"></i> 
                            <strong>Como funciona?</strong> Após enviar a solicitação, a administração irá analisar e 
                            confirmar a disponibilidade. Uma carta modelo será gerada automaticamente.
                        </div>
                        
                        <button type="button" class="btn-solicitar" onclick="confirmarSolicitacao()">
                            <i class="fas fa-paper-plane me-2"></i> Solicitar Férias
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Estatísticas e Histórico -->
            <div class="col-lg-7">
                <div class="row g-4">
                    <div class="col-md-4">
                        <div class="stat-card animated" style="animation-delay: 0.2s;">
                            <div class="stat-number"><?php echo $dias_restantes; ?></div>
                            <div class="stat-label"><i class="fas fa-calendar-day me-1"></i> Dias Disponíveis</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card animated" style="animation-delay: 0.3s;">
                            <div class="stat-number text-success"><?php echo $total_aprovado; ?></div>
                            <div class="stat-label"><i class="fas fa-check-circle me-1"></i> Dias Aprovados</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="stat-card animated" style="animation-delay: 0.4s;">
                            <div class="stat-number text-warning"><?php echo $total_pendente; ?></div>
                            <div class="stat-label"><i class="fas fa-clock me-1"></i> Solicitações Pendentes</div>
                        </div>
                    </div>
                </div>
                
                <!-- Histórico de Solicitações -->
                <div class="info-card animated" style="animation-delay: 0.5s;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="info-title mb-0"><i class="fas fa-history me-2"></i> Histórico de Solicitações</div>
                        <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="atualizarStatus()">
                            <i class="fas fa-sync-alt me-1"></i> Atualizar
                        </button>
                    </div>
                    <div id="historicoContainer">
                        <?php if (empty($solicitacoes)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">Nenhuma solicitação encontrada.</p>
                                <small class="text-muted">Suas solicitações aparecerão aqui</small>
                            </div>
                        <?php else: ?>
                            <?php foreach ($solicitacoes as $s): ?>
                            <div class="solicitacao-card <?php echo $s['status']; ?>" data-id="<?php echo $s['id']; ?>">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <strong class="fs-6"><i class="fas fa-calendar-alt me-2 text-primary"></i><?php echo formatarData($s['data_inicio']); ?> a <?php echo formatarData($s['data_fim']); ?></strong>
                                        <br>
                                        <small class="text-muted"><i class="fas fa-sun me-1"></i><?php echo $s['dias_solicitados']; ?> dias úteis</small>
                                    </div>
                                    <div>
                                        <?php echo getStatusBadgeFerias($s['status']); ?>
                                    </div>
                                </div>
                                <div class="mt-3 pt-2 border-top">
                                    <small class="d-block mb-1"><i class="fas fa-tag me-1 text-muted"></i> Motivo: <?php echo htmlspecialchars($s['motivo']); ?></small>
                                    <small class="d-block mb-1"><i class="fas fa-calendar me-1 text-muted"></i> Solicitado em: <?php echo formatarData($s['created_at']); ?></small>
                                    <?php if ($s['documento_anexo']): ?>
                                    <small class="d-block mb-1"><i class="fas fa-paperclip me-1 text-muted"></i> <a href="<?php echo $s['documento_anexo']; ?>" target="_blank" class="text-decoration-none">Documento anexado <i class="fas fa-external-link-alt fa-xs"></i></a></small>
                                    <?php endif; ?>
                                    <?php if ($s['carta_gerada']): ?>
                                    <div class="mt-2">
                                        <button class="btn-carta" onclick="visualizarCarta(<?php echo $s['id']; ?>)">
                                            <i class="fas fa-file-alt me-1"></i> Ver Carta Gerada
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($s['observacao_admin']): ?>
                                    <small class="d-block mt-2 text-muted"><i class="fas fa-comment me-1"></i> Obs: <?php echo htmlspecialchars($s['observacao_admin']); ?></small>
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
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Como Solicitar Férias?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-umbrella-beach fa-4x" style="color: var(--primary-color);"></i>
                        <h4 class="mt-3">Sistema de Solicitação de Férias</h4>
                        <p class="text-muted">Entenda como funciona o processo</p>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6><i class="fas fa-calendar-alt text-primary me-1"></i> Verificar Saldo</h6>
                            <p>Consulte seu saldo de dias disponíveis para férias no início da página.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6><i class="fas fa-calendar-week text-primary me-1"></i> Escolher Período</h6>
                            <p>Selecione as datas de início e fim das suas férias (mínimo 5 dias, máximo 30 dias).</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6><i class="fas fa-file-alt text-primary me-1"></i> Preencher Solicitação</h6>
                            <p>Informe o motivo e uma descrição detalhada do planejamento para o período.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">4</div>
                        <div class="help-content">
                            <h6><i class="fas fa-paper-plane text-primary me-1"></i> Enviar para Análise</h6>
                            <p>A solicitação é enviada para a administração para análise e aprovação.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">5</div>
                        <div class="help-content">
                            <h6><i class="fas fa-check-circle text-primary me-1"></i> Aprovação</h6>
                            <p>Se aprovado, você será notificado e a carta de concessão será gerada.</p>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-4" style="border-radius: 12px;">
                        <i class="fas fa-lightbulb me-2"></i> <strong>Dicas Importantes:</strong>
                        <ul class="mb-0 mt-2">
                            <li>✅ Solicite com pelo menos 30 dias de antecedência</li>
                            <li>✅ As férias podem ser divididas em até 3 períodos</li>
                            <li>✅ Um período não pode ser inferior a 5 dias corridos</li>
                            <li>✅ O funcionário tem direito a 30 dias de férias por ano trabalhado</li>
                            <li>✅ A carta de concessão será gerada automaticamente após aprovação</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary rounded-pill px-4" data-bs-dismiss="modal"><i class="fas fa-check me-2"></i> Entendi</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Modelo de Carta -->
    <div class="modal fade" id="modalModeloCarta" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i> Modelo de Carta de Solicitação de Férias</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info" style="border-radius: 12px;">
                        <i class="fas fa-info-circle me-2"></i> Este é o modelo de carta que será gerado automaticamente após sua solicitação.
                    </div>
                    
                    <div class="modelo-carta p-4" style="background: #f9f9f9; border-radius: 12px; border: 1px solid #e9ecef;">
                        <div class="text-center mb-4">
                            <h3 class="text-primary"><?php echo htmlspecialchars($escola_nome); ?></h3>
                            <p class="fw-bold mb-1">Requerimento de Férias Anuais</p>
                            <p><small class="text-muted">Protocolo: FERIAS-XXXXXX</small></p>
                        </div>
                        
                        <p><strong>À Direção,</strong></p>
                        
                        <p>Eu, <strong><?php echo htmlspecialchars($funcionario_nome); ?></strong>, portador(a) do cargo de <strong><?php echo htmlspecialchars($funcionario_cargo); ?></strong>, venho por meio deste solicitar minhas férias anuais referentes ao período aquisitivo.</p>
                        
                        <div class="p-3 mb-3" style="background: white; border-radius: 8px;">
                            <p class="mb-1"><strong>📅 Período solicitado:</strong> [DATA_INICIO] a [DATA_FIM] ([DIAS] dias úteis)</p>
                            <p class="mb-1"><strong>🎯 Motivo:</strong> [MOTIVO]</p>
                            <p class="mb-0"><strong>📝 Descrição:</strong> [DESCRICAO]</p>
                        </div>
                        
                        <p>Declaro estar ciente de que durante o período de férias, minha ausência será coberta conforme planejamento pedagógico da instituição.</p>
                        
                        <div class="text-center mt-5 pt-4">
                            <div style="border-top: 1px solid #000; width: 250px; margin: 0 auto; padding-top: 12px;">
                                <strong><?php echo htmlspecialchars($funcionario_nome); ?></strong><br>
                                Funcionário<br>
                                [DATA_ATUAL]
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary rounded-pill px-4" onclick="window.print()"><i class="fas fa-print me-2"></i> Imprimir Modelo</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Confirmar Solicitação de Férias</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Tem certeza que deseja solicitar estas férias?</p>
                    <div class="alert alert-info" style="border-radius: 12px;">
                        <strong class="d-block mb-2">📋 Detalhes da Solicitação:</strong>
                        <span id="confirm_periodo" class="d-block mb-1"></span>
                        <span id="confirm_dias" class="d-block mb-1"></span>
                        <span id="confirm_motivo" class="d-block"></span>
                    </div>
                    <p class="text-muted small mb-0"><i class="fas fa-info-circle me-1"></i> Após confirmar, sua solicitação será enviada para análise da administração. Uma carta modelo será gerada automaticamente.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success rounded-pill px-4" id="btnConfirmarEnvio"><i class="fas fa-check me-2"></i> Sim, Solicitar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let diasSelecionados = 0;
        let limiteDisponivel = <?php echo $dias_restantes; ?>;
        
        // Calcular dias úteis automaticamente
        function calcularDiasUteis() {
            let dataInicio = document.getElementById('data_inicio').value;
            let dataFim = document.getElementById('data_fim').value;
            
            if (dataInicio && dataFim) {
                let inicio = new Date(dataInicio);
                let fim = new Date(dataFim);
                
                if (inicio > fim) {
                    document.getElementById('info_dias').innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i>A data de fim deve ser posterior à data de início</span>';
                    return;
                }
                
                let diasUteis = 0;
                let currentDate = new Date(inicio);
                
                while (currentDate <= fim) {
                    let diaSemana = currentDate.getDay();
                    if (diaSemana !== 0 && diaSemana !== 6) {
                        diasUteis++;
                    }
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                
                diasSelecionados = diasUteis;
                let infoDiv = document.getElementById('info_dias');
                
                if (diasUteis > limiteDisponivel) {
                    infoDiv.innerHTML = '<span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>' + diasUteis + ' dias úteis. Excede seu saldo de ' + limiteDisponivel + ' dias!</span>';
                } else if (diasUteis < 5) {
                    infoDiv.innerHTML = '<span class="text-warning"><i class="fas fa-info-circle me-1"></i>' + diasUteis + ' dias úteis. Mínimo recomendado: 5 dias</span>';
                } else {
                    infoDiv.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i>' + diasUteis + ' dias úteis selecionados</span>';
                }
            }
        }
        
        document.getElementById('data_inicio').addEventListener('change', calcularDiasUteis);
        document.getElementById('data_fim').addEventListener('change', calcularDiasUteis);
        
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
            let dataInicio = document.getElementById('data_inicio').value;
            let dataFim = document.getElementById('data_fim').value;
            let motivo = document.getElementById('motivo').value;
            
            if (!dataInicio || !dataFim) {
                alert('⚠️ Por favor, informe as datas de início e fim das férias.');
                return;
            }
            if (diasSelecionados > limiteDisponivel) {
                alert('⚠️ Você não tem dias de férias suficientes disponíveis.');
                return;
            }
            if (diasSelecionados < 1) {
                alert('⚠️ O período selecionado não contém dias úteis.');
                return;
            }
            if (!motivo) {
                alert('⚠️ Por favor, selecione o motivo da solicitação.');
                return;
            }
            
            let dataInicioF = new Date(dataInicio).toLocaleDateString('pt-BR');
            let dataFimF = new Date(dataFim).toLocaleDateString('pt-BR');
            
            document.getElementById('confirm_periodo').innerHTML = '<i class="fas fa-calendar-alt me-2"></i>Período: ' + dataInicioF + ' a ' + dataFimF;
            document.getElementById('confirm_dias').innerHTML = '<i class="fas fa-chart-line me-2"></i>Dias úteis: ' + diasSelecionados + ' dias';
            document.getElementById('confirm_motivo').innerHTML = '<i class="fas fa-tag me-2"></i>Motivo: ' + motivo;
            
            new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
        }
        
        document.getElementById('btnConfirmarEnvio').addEventListener('click', function() {
            document.getElementById('formSolicitacao').submit();
        });
        
        function visualizarCarta(id) {
            window.open('solicitar_ferias.php?visualizar_carta=1&id=' + id, '_blank', 'width=900,height=700,scrollbars=yes');
        }
        
        function atualizarStatus() {
            location.reload();
        }
        
        // Auto-refresh a cada 30 segundos
        setInterval(atualizarStatus, 30000);
        
        // Adicionar animação de entrada aos elementos
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.animated');
            elements.forEach((el, index) => {
                el.style.animationDelay = (index * 0.1) + 's';
            });
        });
    </script>
</body>
</html>