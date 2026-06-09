<?php
// 404.php - Página não encontrada
header("HTTP/1.0 404 Not Found");
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Página não encontrada - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .error-container {
            text-align: center;
            color: white;
        }
        .error-code {
            font-size: 120px;
            font-weight: bold;
            text-shadow: 4px 4px 0 rgba(0,0,0,0.2);
        }
        .error-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        .btn-home {
            background: white;
            color: #006B3E;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            font-weight: bold;
            margin-top: 20px;
        }
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-map-signs"></i>
        </div>
        <div class="error-code">404</div>
        <h2>Página não encontrada</h2>
        <p>A página que você está procurando não existe ou foi movida.</p>
        <a href="index.php" class="btn btn-home">
            <i class="fas fa-home"></i> Voltar para o início
        </a>
    </div>
</body>
</html>