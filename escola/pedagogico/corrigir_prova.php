<?php
// escola/pedagogico/erro_prova.php - Página de Erro

$codigo = isset($_GET['codigo']) ? (int)$_GET['codigo'] : 404;
$mensagem = isset($_GET['msg']) ? $_GET['msg'] : 'erro_desconhecido';
$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$titulos = [
    400 => 'Requisição Inválida',
    403 => 'Acesso Negado',
    404 => 'Prova Não Encontrada',
    500 => 'Erro Interno do Servidor'
];

$descricoes = [
    'prova_nao_encontrada' => 'A prova que você está procurando não existe ou não está disponível no momento.',
    'acesso_negado' => 'Você não tem permissão para acessar esta prova.',
    'tentativa_nao_encontrada' => 'A tentativa de prova não foi encontrada.',
    'parametros_invalidos' => 'Os parâmetros fornecidos são inválidos.',
    'erro_desconhecido' => 'Ocorreu um erro desconhecido. Tente novamente mais tarde.'
];

$titulo = isset($titulos[$codigo]) ? $titulos[$codigo] : 'Erro';
$descricao = isset($descricoes[$mensagem]) ? $descricoes[$mensagem] : $descricoes['erro_desconhecido'];

// Ícone baseado no código
$icone = '';
$cor = '';
if ($codigo == 404) {
    $icone = 'fa-search';
    $cor = '#f39c12';
} elseif ($codigo == 403) {
    $icone = 'fa-lock';
    $cor = '#e74c3c';
} elseif ($codigo == 400) {
    $icone = 'fa-exclamation-triangle';
    $cor = '#e74c3c';
} else {
    $icone = 'fa-exclamation-circle';
    $cor = '#e74c3c';
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $codigo; ?> - <?php echo $titulo; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            text-align: center;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .error-code {
            font-size: 120px;
            font-weight: 800;
            color: <?php echo $cor; ?>;
            text-shadow: 5px 5px 0 rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .error-icon {
            font-size: 80px;
            color: <?php echo $cor; ?>;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 28px;
            font-weight: 700;
            color: #2c3e50;
            margin-bottom: 15px;
        }
        
        .error-message {
            font-size: 16px;
            color: #7f8c8d;
            margin-bottom: 30px;
        }
        
        .btn-home {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .btn-home:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
            color: white;
        }
        
        .btn-back {
            background: rgba(0,0,0,0.1);
            color: #2c3e50;
            padding: 12px 30px;
            border: none;
            border-radius: 40px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            transition: all 0.3s ease;
            margin-left: 10px;
        }
        
        .btn-back:hover {
            background: rgba(0,0,0,0.2);
            color: #2c3e50;
        }
        
        @media (max-width: 768px) {
            .error-code { font-size: 80px; }
            .error-title { font-size: 22px; }
            .btn-home, .btn-back { padding: 10px 20px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas <?php echo $icone; ?>"></i>
        </div>
        <div class="error-code">
            <?php echo $codigo; ?>
        </div>
        <div class="error-title">
            <?php echo $titulo; ?>
        </div>
        <div class="error-message">
            <p><?php echo $descricao; ?></p>
            <?php if ($prova_id > 0): ?>
                <p class="small text-muted mt-2">ID da Prova: <?php echo $prova_id; ?></p>
            <?php endif; ?>
        </div>
        <div>
            <a href="index.php" class="btn-home">
                <i class="fas fa-home"></i> Ir para o Dashboard
            </a>
            <a href="javascript:history.back()" class="btn-back">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
    </div>
</body>
</html>