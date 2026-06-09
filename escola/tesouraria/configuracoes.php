<?php
// escola/tesouraria/configuracoes.php - Configurações da Tesouraria

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões (apenas admin e financeiro)
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR CONFIGURAÇÕES
// ============================================
$success = '';
$error = '';
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'geral';

// Buscar configurações existentes
$sql_config = "SELECT * FROM config_tesouraria WHERE escola_id = :escola_id LIMIT 1";
$stmt_config = $conn->prepare($sql_config);
$stmt_config->execute([':escola_id' => $escola_id]);
$config = $stmt_config->fetch(PDO::FETCH_ASSOC);

// Se não existir, criar configurações padrão
if (!$config) {
    $sql_insert = "INSERT INTO config_tesouraria (escola_id, created_at) VALUES (:escola_id, NOW())";
    $stmt_insert = $conn->prepare($sql_insert);
    $stmt_insert->execute([':escola_id' => $escola_id]);
    
    $stmt_config->execute([':escola_id' => $escola_id]);
    $config = $stmt_config->fetch(PDO::FETCH_ASSOC);
}

// Processar formulário de Configurações Gerais
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Configurações Gerais
    if ($_POST['action'] == 'geral') {
        $moeda = $_POST['moeda'];
        $formato_data = $_POST['formato_data'];
        $casa_decimais = (int)$_POST['casa_decimais'];
        $separador_milhar = $_POST['separador_milhar'];
        $separador_decimal = $_POST['separador_decimal'];
        $notificacoes_email = isset($_POST['notificacoes_email']) ? 1 : 0;
        $email_notificacao = trim($_POST['email_notificacao']);
        
        try {
            $sql = "UPDATE config_tesouraria 
                    SET moeda = :moeda, formato_data = :formato_data, casa_decimais = :casa_decimais,
                        separador_milhar = :separador_milhar, separador_decimal = :separador_decimal,
                        notificacoes_email = :notificacoes_email, email_notificacao = :email_notificacao,
                        updated_at = NOW()
                    WHERE escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':moeda' => $moeda,
                ':formato_data' => $formato_data,
                ':casa_decimais' => $casa_decimais,
                ':separador_milhar' => $separador_milhar,
                ':separador_decimal' => $separador_decimal,
                ':notificacoes_email' => $notificacoes_email,
                ':email_notificacao' => $email_notificacao,
                ':escola_id' => $escola_id
            ]);
            $success = "Configurações gerais salvas com sucesso!";
            
            // Atualizar variável de configuração
            $config = array_merge($config, [
                'moeda' => $moeda,
                'formato_data' => $formato_data,
                'casa_decimais' => $casa_decimais,
                'separador_milhar' => $separador_milhar,
                'separador_decimal' => $separador_decimal,
                'notificacoes_email' => $notificacoes_email,
                'email_notificacao' => $email_notificacao
            ]);
        } catch (Exception $e) {
            $error = "Erro ao salvar: " . $e->getMessage();
        }
    }
    
    // Configurações de Juros e Multas
    elseif ($_POST['action'] == 'juros') {
        $juros_mensal = floatval(str_replace(',', '.', $_POST['juros_mensal']));
        $multa_diaria = floatval(str_replace(',', '.', $_POST['multa_diaria']));
        $dias_carencia = (int)$_POST['dias_carencia'];
        $taxa_desconto = floatval(str_replace(',', '.', $_POST['taxa_desconto']));
        $valor_minimo_parcela = floatval(str_replace(',', '.', $_POST['valor_minimo_parcela']));
        $max_parcelas = (int)$_POST['max_parcelas'];
        
        try {
            $sql = "UPDATE config_tesouraria 
                    SET juros_mensal = :juros_mensal, multa_diaria = :multa_diaria,
                        dias_carencia = :dias_carencia, taxa_desconto = :taxa_desconto,
                        valor_minimo_parcela = :valor_minimo_parcela, max_parcelas = :max_parcelas,
                        updated_at = NOW()
                    WHERE escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':juros_mensal' => $juros_mensal,
                ':multa_diaria' => $multa_diaria,
                ':dias_carencia' => $dias_carencia,
                ':taxa_desconto' => $taxa_desconto,
                ':valor_minimo_parcela' => $valor_minimo_parcela,
                ':max_parcelas' => $max_parcelas,
                ':escola_id' => $escola_id
            ]);
            $success = "Configurações de juros e multas salvas com sucesso!";
            
            $config = array_merge($config, [
                'juros_mensal' => $juros_mensal,
                'multa_diaria' => $multa_diaria,
                'dias_carencia' => $dias_carencia,
                'taxa_desconto' => $taxa_desconto,
                'valor_minimo_parcela' => $valor_minimo_parcela,
                'max_parcelas' => $max_parcelas
            ]);
        } catch (Exception $e) {
            $error = "Erro ao salvar: " . $e->getMessage();
        }
    }
    
    // Configurações de Recibo
    elseif ($_POST['action'] == 'recibo') {
        $cabecalho_recibo = trim($_POST['cabecalho_recibo']);
        $rodape_recibo = trim($_POST['rodape_recibo']);
        $mostrar_logo = isset($_POST['mostrar_logo']) ? 1 : 0;
        $mostrar_qrcode = isset($_POST['mostrar_qrcode']) ? 1 : 0;
        $recibo_copias = (int)$_POST['recibo_copias'];
        
        try {
            $sql = "UPDATE config_tesouraria 
                    SET cabecalho_recibo = :cabecalho_recibo, rodape_recibo = :rodape_recibo,
                        mostrar_logo = :mostrar_logo, mostrar_qrcode = :mostrar_qrcode,
                        recibo_copias = :recibo_copias, updated_at = NOW()
                    WHERE escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':cabecalho_recibo' => $cabecalho_recibo,
                ':rodape_recibo' => $rodape_recibo,
                ':mostrar_logo' => $mostrar_logo,
                ':mostrar_qrcode' => $mostrar_qrcode,
                ':recibo_copias' => $recibo_copias,
                ':escola_id' => $escola_id
            ]);
            $success = "Configurações de recibo salvas com sucesso!";
            
            $config = array_merge($config, [
                'cabecalho_recibo' => $cabecalho_recibo,
                'rodape_recibo' => $rodape_recibo,
                'mostrar_logo' => $mostrar_logo,
                'mostrar_qrcode' => $mostrar_qrcode,
                'recibo_copias' => $recibo_copias
            ]);
        } catch (Exception $e) {
            $error = "Erro ao salvar: " . $e->getMessage();
        }
    }
    
    // Configurações de Segurança
    elseif ($_POST['action'] == 'seguranca') {
        $requer_aprovacao = isset($_POST['requer_aprovacao']) ? 1 : 0;
        $limite_sem_aprovacao = floatval(str_replace(',', '.', $_POST['limite_sem_aprovacao']));
        $notificar_limite = isset($_POST['notificar_limite']) ? 1 : 0;
        $limite_notificacao = floatval(str_replace(',', '.', $_POST['limite_notificacao']));
        
        try {
            $sql = "UPDATE config_tesouraria 
                    SET requer_aprovacao = :requer_aprovacao, limite_sem_aprovacao = :limite_sem_aprovacao,
                        notificar_limite = :notificar_limite, limite_notificacao = :limite_notificacao,
                        updated_at = NOW()
                    WHERE escola_id = :escola_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':requer_aprovacao' => $requer_aprovacao,
                ':limite_sem_aprovacao' => $limite_sem_aprovacao,
                ':notificar_limite' => $notificar_limite,
                ':limite_notificacao' => $limite_notificacao,
                ':escola_id' => $escola_id
            ]);
            $success = "Configurações de segurança salvas com sucesso!";
            
            $config = array_merge($config, [
                'requer_aprovacao' => $requer_aprovacao,
                'limite_sem_aprovacao' => $limite_sem_aprovacao,
                'notificar_limite' => $notificar_limite,
                'limite_notificacao' => $limite_notificacao
            ]);
        } catch (Exception $e) {
            $error = "Erro ao salvar: " . $e->getMessage();
        }
    }
}

// Função para formatar valor com as configurações
function formatarMoedaConfig($valor, $config) {
    return number_format($valor, $config['casa_decimais'], $config['separador_decimal'], $config['separador_milhar']);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .nav-tabs .nav-link { color: #006B3E; font-weight: 500; }
        .nav-tabs .nav-link.active { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; }
        
        .form-label { font-weight: 500; color: #555; }
        .config-card { border-left: 4px solid #006B3E; }
        
        .preview-box { background: #f8f9fa; border: 1px solid #ddd; border-radius: 10px; padding: 15px; margin-top: 15px; }
        
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-cog"></i> Configurações da Tesouraria</h2>
                <p class="text-muted">Personalize as configurações financeiras da sua escola</p>
            </div>
            <div>
                <a href="index.php" class="btn-voltar no-print">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4 no-print" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab == 'geral' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#geral" type="button" role="tab">
                    <i class="fas fa-globe"></i> Gerais
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab == 'juros' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#juros" type="button" role="tab">
                    <i class="fas fa-percent"></i> Juros e Multas
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab == 'recibo' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#recibo" type="button" role="tab">
                    <i class="fas fa-receipt"></i> Recibo
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $active_tab == 'seguranca' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#seguranca" type="button" role="tab">
                    <i class="fas fa-shield-alt"></i> Segurança
                </button>
            </li>
        </ul>
        
        <!-- Conteúdo das Tabs -->
        <div class="tab-content">
            
            <!-- Tab Configurações Gerais -->
            <div class="tab-pane fade <?php echo $active_tab == 'geral' ? 'show active' : ''; ?>" id="geral" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-globe"></i> Configurações Gerais</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="geral">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Moeda</label>
                                    <select name="moeda" class="form-select">
                                        <option value="AOA" <?php echo ($config['moeda'] ?? 'AOA') == 'AOA' ? 'selected' : ''; ?>>Kz - Kwanza (AOA)</option>
                                        <option value="USD" <?php echo ($config['moeda'] ?? 'AOA') == 'USD' ? 'selected' : ''; ?>>USD - Dólar Americano</option>
                                        <option value="EUR" <?php echo ($config['moeda'] ?? 'AOA') == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Formato de Data</label>
                                    <select name="formato_data" class="form-select">
                                        <option value="d/m/Y" <?php echo ($config['formato_data'] ?? 'd/m/Y') == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/AAAA</option>
                                        <option value="m/d/Y" <?php echo ($config['formato_data'] ?? 'd/m/Y') == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/AAAA</option>
                                        <option value="Y-m-d" <?php echo ($config['formato_data'] ?? 'd/m/Y') == 'Y-m-d' ? 'selected' : ''; ?>>AAAA-MM-DD</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Casas Decimais</label>
                                    <select name="casa_decimais" class="form-select">
                                        <option value="0" <?php echo ($config['casa_decimais'] ?? 2) == 0 ? 'selected' : ''; ?>>0 (sem decimais)</option>
                                        <option value="2" <?php echo ($config['casa_decimais'] ?? 2) == 2 ? 'selected' : ''; ?>>2 decimais</option>
                                        <option value="3" <?php echo ($config['casa_decimais'] ?? 2) == 3 ? 'selected' : ''; ?>>3 decimais</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Separador de Milhar</label>
                                    <select name="separador_milhar" class="form-select">
                                        <option value="." <?php echo ($config['separador_milhar'] ?? '.') == '.' ? 'selected' : ''; ?>>Ponto (.)</option>
                                        <option value="," <?php echo ($config['separador_milhar'] ?? '.') == ',' ? 'selected' : ''; ?>>Vírgula (,)</option>
                                        <option value=" " <?php echo ($config['separador_milhar'] ?? '.') == ' ' ? 'selected' : ''; ?>>Espaço ( )</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Separador Decimal</label>
                                    <select name="separador_decimal" class="form-select">
                                        <option value="," <?php echo ($config['separador_decimal'] ?? ',') == ',' ? 'selected' : ''; ?>>Vírgula (,)</option>
                                        <option value="." <?php echo ($config['separador_decimal'] ?? ',') == '.' ? 'selected' : ''; ?>>Ponto (.)</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Exemplo de Formato</label>
                                    <input type="text" class="form-control" readonly value="<?php echo formatarMoedaConfig(1234567.89, $config); ?>">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="notificacoes_email" class="form-check-input" id="notificacoes_email" <?php echo ($config['notificacoes_email'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notificacoes_email">Receber notificações por email</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Email para Notificações</label>
                                    <input type="email" name="email_notificacao" class="form-control" value="<?php echo htmlspecialchars($config['email_notificacao'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configurações</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Tab Juros e Multas -->
            <div class="tab-pane fade <?php echo $active_tab == 'juros' ? 'show active' : ''; ?>" id="juros" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-percent"></i> Configurações de Juros e Multas</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="juros">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Juros Mensal (%)</label>
                                    <div class="input-group">
                                        <input type="text" name="juros_mensal" class="form-control" value="<?php echo number_format($config['juros_mensal'] ?? 0, 2, ',', '.'); ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">Juros aplicados por mês de atraso</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Multa Diária (%)</label>
                                    <div class="input-group">
                                        <input type="text" name="multa_diaria" class="form-control" value="<?php echo number_format($config['multa_diaria'] ?? 0, 2, ',', '.'); ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">Multa por dia de atraso</small>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Dias de Carência</label>
                                    <input type="number" name="dias_carencia" class="form-control" value="<?php echo $config['dias_carencia'] ?? 0; ?>">
                                    <small class="text-muted">Dias sem multa após vencimento</small>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Taxa de Desconto para Pagamento Antecipado (%)</label>
                                    <div class="input-group">
                                        <input type="text" name="taxa_desconto" class="form-control" value="<?php echo number_format($config['taxa_desconto'] ?? 0, 2, ',', '.'); ?>">
                                        <span class="input-group-text">%</span>
                                    </div>
                                    <small class="text-muted">Desconto para pagamento antes do vencimento</small>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Valor Mínimo para Parcelamento</label>
                                    <div class="input-group">
                                        <input type="text" name="valor_minimo_parcela" class="form-control" value="<?php echo number_format($config['valor_minimo_parcela'] ?? 0, 2, ',', '.'); ?>">
                                        <span class="input-group-text">Kz</span>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Número Máximo de Parcelas</label>
                                    <input type="number" name="max_parcelas" class="form-control" value="<?php echo $config['max_parcelas'] ?? 12; ?>">
                                </div>
                            </div>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i>
                                <strong>Exemplo de Cálculo:</strong><br>
                                Para uma mensalidade de 10.000 Kz com 30 dias de atraso:
                                <?php 
                                $juros = 10000 * (($config['juros_mensal'] ?? 0) / 100);
                                $multa = 10000 * (($config['multa_diaria'] ?? 0) / 100) * 30;
                                $total = 10000 + $juros + $multa;
                                ?>
                                <ul class="mt-2 mb-0">
                                    <li>Valor original: <strong><?php echo formatarMoedaConfig(10000, $config); ?></strong></li>
                                    <li>Juros (<?php echo number_format($config['juros_mensal'] ?? 0, 2, ',', '.'); ?>%): <strong><?php echo formatarMoedaConfig($juros, $config); ?></strong></li>
                                    <li>Multa (<?php echo number_format($config['multa_diaria'] ?? 0, 2, ',', '.'); ?>%/dia): <strong><?php echo formatarMoedaConfig($multa, $config); ?></strong></li>
                                    <li>Valor total: <strong class="text-danger"><?php echo formatarMoedaConfig($total, $config); ?></strong></li>
                                </ul>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configurações</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Tab Recibo -->
            <div class="tab-pane fade <?php echo $active_tab == 'recibo' ? 'show active' : ''; ?>" id="recibo" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-receipt"></i> Configurações do Recibo</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="recibo">
                            <div class="mb-3">
                                <label class="form-label">Cabeçalho do Recibo</label>
                                <textarea name="cabecalho_recibo" class="form-control" rows="3"><?php echo htmlspecialchars($config['cabecalho_recibo'] ?? ''); ?></textarea>
                                <small class="text-muted">Texto que aparecerá no topo do recibo</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Rodapé do Recibo</label>
                                <textarea name="rodape_recibo" class="form-control" rows="3"><?php echo htmlspecialchars($config['rodape_recibo'] ?? ''); ?></textarea>
                                <small class="text-muted">Texto que aparecerá no rodapé do recibo</small>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="mostrar_logo" class="form-check-input" id="mostrar_logo" <?php echo ($config['mostrar_logo'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="mostrar_logo">Mostrar Logo da Escola no Recibo</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="mostrar_qrcode" class="form-check-input" id="mostrar_qrcode" <?php echo ($config['mostrar_qrcode'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="mostrar_qrcode">Mostrar QR Code no Recibo</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Número de Cópias do Recibo</label>
                                <select name="recibo_copias" class="form-select">
                                    <option value="1" <?php echo ($config['recibo_copias'] ?? 1) == 1 ? 'selected' : ''; ?>>1 cópia (cliente)</option>
                                    <option value="2" <?php echo ($config['recibo_copias'] ?? 1) == 2 ? 'selected' : ''; ?>>2 cópias (cliente + escola)</option>
                                    <option value="3" <?php echo ($config['recibo_copias'] ?? 1) == 3 ? 'selected' : ''; ?>>3 cópias (cliente + escola + arquivo)</option>
                                </select>
                            </div>
                            
                            <!-- Preview do Recibo -->
                            <div class="preview-box">
                                <h6><i class="fas fa-eye"></i> Pré-visualização do Recibo</h6>
                                <hr>
                                <div class="text-center">
                                    <?php if ($config['mostrar_logo'] ?? 1): ?>
                                    <div style="font-size: 12pt; font-weight: bold;"><?php echo htmlspecialchars($config['cabecalho_recibo'] ?? 'SIGE Angola'); ?></div>
                                    <?php endif; ?>
                                    <div style="font-size: 9pt;">RECIBO DE PAGAMENTO</div>
                                    <div style="border: 1px solid #ddd; padding: 10px; margin: 10px 0;">
                                        <div>Cliente: [NOME DO ALUNO]</div>
                                        <div>Valor: <?php echo formatarMoedaConfig(5000, $config); ?></div>
                                        <div>Forma: DINHEIRO</div>
                                    </div>
                                    <?php if ($config['mostrar_qrcode'] ?? 1): ?>
                                    <div style="font-family: monospace;">
                                        ████████████████████████████████████████<br>
                                        ██                              ██<br>
                                        ██    QR CODE DO PAGAMENTO      ██<br>
                                        ██                              ██<br>
                                        ████████████████████████████████████████
                                    </div>
                                    <?php endif; ?>
                                    <div style="font-size: 7pt;"><?php echo htmlspecialchars($config['rodape_recibo'] ?? 'Documento emitido por computador'); ?></div>
                                </div>
                            </div>
                            
                            <div class="text-end mt-3">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configurações</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Tab Segurança -->
            <div class="tab-pane fade <?php echo $active_tab == 'seguranca' ? 'show active' : ''; ?>" id="seguranca" role="tabpanel">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-shield-alt"></i> Configurações de Segurança</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="seguranca">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="requer_aprovacao" class="form-check-input" id="requer_aprovacao" <?php echo ($config['requer_aprovacao'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="requer_aprovacao">Requer aprovação para pagamentos acima do limite</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Limite sem Aprovação</label>
                                    <div class="input-group">
                                        <input type="text" name="limite_sem_aprovacao" class="form-control" value="<?php echo number_format($config['limite_sem_aprovacao'] ?? 50000, 2, ',', '.'); ?>">
                                        <span class="input-group-text">Kz</span>
                                    </div>
                                    <small class="text-muted">Pagamentos acima deste valor requerem aprovação</small>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="form-check">
                                        <input type="checkbox" name="notificar_limite" class="form-check-input" id="notificar_limite" <?php echo ($config['notificar_limite'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notificar_limite">Notificar quando atingir limite de caixa</label>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Limite de Notificação</label>
                                    <div class="input-group">
                                        <input type="text" name="limite_notificacao" class="form-control" value="<?php echo number_format($config['limite_notificacao'] ?? 100000, 2, ',', '.'); ?>">
                                        <span class="input-group-text">Kz</span>
                                    </div>
                                    <small class="text-muted">Notificar quando o saldo em caixa atingir este valor</small>
                                </div>
                            </div>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Segurança:</strong>
                                <ul class="mt-2 mb-0">
                                    <li>Todas as transações são registradas com data, hora e usuário</li>
                                    <li>Não é possível excluir transações, apenas cancelar</li>
                                    <li>O fechamento de caixa não pode ser desfeito</li>
                                </ul>
                            </div>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Salvar Configurações</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Informações do Sistema -->
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações do Sistema</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><td><strong>Versão do Sistema</strong></td><td>2.5.0</td></tr>
                            <tr><td><strong>Última Atualização</strong></td><td><?php echo date('d/m/Y H:i:s', strtotime($config['updated_at'] ?? 'now')); ?></td></tr>
                            <tr><td><strong>Configurações Criadas em</strong></td><td><?php echo date('d/m/Y H:i:s', strtotime($config['created_at'] ?? 'now')); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> Sistema configurado para:
                            <ul class="mt-2 mb-0">
                                <li>Moeda: <strong><?php echo $config['moeda'] ?? 'AOA'; ?></strong></li>
                                <li>Formato: <strong><?php echo formatarMoedaConfig(1234567.89, $config); ?></strong></li>
                                <li>Juros mensal: <strong><?php echo number_format($config['juros_mensal'] ?? 0, 2, ',', '.'); ?>%</strong></li>
                                <li>Multa diária: <strong><?php echo number_format($config['multa_diaria'] ?? 0, 2, ',', '.'); ?>%</strong></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Formatar campos de valor
        $('input[name="juros_mensal"], input[name="multa_diaria"], input[name="taxa_desconto"], input[name="valor_minimo_parcela"], input[name="limite_sem_aprovacao"], input[name="limite_notificacao"]').on('input', function() {
            let v = $(this).val().replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            $(this).val(v);
        });
        
        // Salvar a aba ativa
        $('.nav-link').on('click', function() {
            let tab = $(this).data('bs-target').replace('#', '');
            window.history.replaceState(null, null, '?tab=' + tab);
        });
    </script>
</body>
</html>