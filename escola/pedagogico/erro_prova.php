<?php
// escola/aluno/provas/erro_prova.php - Página de erro bonita

$codigo = isset($_GET['codigo']) ? htmlspecialchars($_GET['codigo']) : '404';
$mensagem = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : 'erro';
$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($mensagem == 'prova_nao_encontrada') {
    $titulo = 'Prova não encontrada!';
    $descricao = 'A prova que você está procurando não existe ou não está disponível no momento.';
    $icone = 'fa-file-alt';
    $cor_icone = '#dc3545';
} elseif ($mensagem == 'nao_disponivel') {
    $titulo = 'Prova não disponível!';
    $descricao = 'Esta prova não está disponível para realização no momento. Verifique a data de início.';
    $icone = 'fa-clock';
    $cor_icone = '#ffc107';
} elseif ($mensagem == 'sem_permissao') {
    $titulo = 'Acesso negado!';
    $descricao = 'Você não tem permissão para acessar esta prova.';
    $icone = 'fa-lock';
    $cor_icone = '#dc3545';
} elseif ($mensagem == 'prova_expirada') {
    $titulo = 'Prova expirada!';
    $descricao = 'O prazo para realização desta prova já expirou.';
    $icone = 'fa-hourglass-end';
    $cor_icone = '#6c757d';
} elseif ($mensagem == 'tentativas_esgotadas') {
    $titulo = 'Tentativas esgotadas!';
    $descricao = 'Você já utilizou todas as tentativas permitidas para esta prova.';
    $icone = 'fa-ban';
    $cor_icone = '#dc3545';
} else {
    $titulo = 'Erro!';
    $descricao = 'Ocorreu um erro ao carregar a prova. Tente novamente mais tarde.';
    $icone = 'fa-exclamation-triangle';
    $cor_icone = '#6c757d';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo $titulo; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        
        .error-card {
            background: white;
            border-radius: 32px;
            padding: 50px 40px;
            text-align: center;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 30px 60px rgba(0,0,0,0.2);
            animation: fadeInUp 0.5s ease;
            position: relative;
            overflow: hidden;
        }
        
        .error-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 200px;
            height: 200px;
            background: linear-gradient(135deg, #667eea10, #764ba210);
            border-radius: 50%;
        }
        
        .error-card::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 150px;
            height: 150px;
            background: linear-gradient(135deg, #667eea10, #764ba210);
            border-radius: 50%;
        }
        
        .error-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #667eea20, #764ba220);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            position: relative;
            z-index: 1;
        }
        
        .error-icon i {
            font-size: 50px;
            color: <?php echo $cor_icone; ?>;
        }
        
        .error-code {
            font-size: 70px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 10px;
            position: relative;
            z-index: 1;
        }
        
        .error-title {
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
            position: relative;
            z-index: 1;
        }
        
        .error-message {
            color: #6c757d;
            margin-bottom: 20px;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }
        
        .info-box {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-left: 4px solid #764ba2;
            position: relative;
            z-index: 1;
        }
        
        .info-box-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 0.9rem;
        }
        
        .info-box-item:last-child {
            margin-bottom: 0;
        }
        
        .info-box-item i {
            width: 25px;
            color: #764ba2;
        }
        
        .info-label {
            font-weight: 600;
            color: #495057;
        }
        
        .info-value {
            color: #6c757d;
            font-family: monospace;
            font-size: 0.9rem;
        }
        
        .badge-id {
            background: #764ba2;
            color: white;
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .btn-voltar {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 40px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
        }
        
        .btn-voltar:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(102,126,234,0.4);
            color: white;
        }
        
        .btn-tentar {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
            padding: 12px 30px;
            border-radius: 40px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s;
            margin-left: 10px;
            position: relative;
            z-index: 1;
        }
        
        .btn-tentar:hover {
            background: #667eea;
            color: white;
            transform: translateY(-3px);
        }
        
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
        
        @media (max-width: 480px) {
            .error-card {
                padding: 35px 25px;
            }
            .error-code {
                font-size: 50px;
            }
            .error-title {
                font-size: 20px;
            }
            .btn-tentar {
                margin-left: 0;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="fas <?php echo $icone; ?>"></i>
        </div>
        <div class="error-code"><?php echo $codigo; ?></div>
        <h2 class="error-title"><?php echo $titulo; ?></h2>
        <p class="error-message"><?php echo $descricao; ?></p>
        
        <!-- Informações da Prova (se houver ID) -->
        <?php if ($prova_id > 0): ?>
        <div class="info-box">
            <div class="info-box-item">
                <i class="fas fa-hashtag"></i>
                <span class="info-label">ID da Prova:</span>
                <span class="info-value"><?php echo $prova_id; ?></span>
                <span class="badge-id ms-auto">Referência</span>
            </div>
            <div class="info-box-item">
                <i class="fas fa-calendar-alt"></i>
                <span class="info-label">Data do erro:</span>
                <span class="info-value"><?php echo date('d/m/Y H:i:s'); ?></span>
            </div>
            <div class="info-box-item">
                <i class="fas fa-info-circle"></i>
                <span class="info-label">Código do erro:</span>
                <span class="info-value"><?php echo $mensagem; ?></span>
            </div>
        </div>
        
        <!-- Sugestões -->
        <div class="info-box" style="background: #fff3cd; border-left-color: #ffc107;">
            <div class="info-box-item">
                <i class="fas fa-lightbulb text-warning"></i>
                <span class="info-label">Sugestões:</span>
            </div>
            <div class="info-box-item">
                <small>• Verifique se o link da prova está correto</small>
            </div>
            <div class="info-box-item">
                <small>• Entre em contato com o professor responsável</small>
            </div>
            <div class="info-box-item">
                <small>• Verifique se a prova está dentro do prazo</small>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="mt-4">
            <a href="javascript:history.back()" class="btn-tentar">
                <i class="fas fa-arrow-left"></i> Tentar novamente
            </a>
            <a href="provas_disponiveis.php" class="btn-voltar">
                <i class="fas fa-home"></i> Voltar para Provas
            </a>
        </div>
    </div>
</body>
</html>