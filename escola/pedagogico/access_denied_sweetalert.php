<?php
// escola/pedagogico/access_denied_sweetalert.php
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Negado | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .hidden-content {
            display: none;
        }
    </style>
</head>
<body>
    <div class="hidden-content" id="accessDeniedContent">
        <div class="text-center">
            <i class="fas fa-lock fa-4x text-danger mb-3"></i>
            <h2 class="text-danger">Acesso Negado!</h2>
            <p>Você não tem permissão para acessar esta área.</p>
            <p>Esta área é restrita para pedagogos, coordenadores, diretores e administradores.</p>
            <div class="mt-3">
                <span class="badge bg-success">Pedagogos</span>
                <span class="badge bg-info">Coordenadores</span>
                <span class="badge bg-warning">Diretores</span>
                <span class="badge bg-danger">Administradores</span>
            </div>
            <a href="../dashboard.php" class="btn btn-primary mt-4">
                <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
            </a>
        </div>
    </div>

    <script>
        Swal.fire({
            title: '<i class="fas fa-lock" style="color: #dc3545;"></i> Acesso Negado!',
            html: `
                <div style="text-align: center;">
                    <p style="font-size: 1.1rem; color: #666;">Você não tem permissão para acessar esta área.</p>
                    <p style="color: #888;">Esta área é restrita para:</p>
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin: 15px 0;">
                        <span style="background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 8px 16px; border-radius: 30px;"><i class="fas fa-chalkboard-user"></i> Pedagogos</span>
                        <span style="background: linear-gradient(135deg, #17a2b8, #0dcaf0); color: white; padding: 8px 16px; border-radius: 30px;"><i class="fas fa-users"></i> Coordenadores</span>
                        <span style="background: linear-gradient(135deg, #ffc107, #ff9800); color: #212529; padding: 8px 16px; border-radius: 30px;"><i class="fas fa-building"></i> Diretores</span>
                        <span style="background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 8px 16px; border-radius: 30px;"><i class="fas fa-user-shield"></i> Administradores</span>
                    </div>
                    <hr>
                    <p><i class="fas fa-info-circle"></i> Se você acredita que esta mensagem é um erro, contacte o administrador.</p>
                </div>
            `,
            icon: 'error',
            confirmButtonText: '<i class="fas fa-arrow-left"></i> Voltar ao Dashboard',
            confirmButtonColor: '#006B3E',
            background: 'white',
            allowOutsideClick: false,
            allowEscapeKey: true,
            backdrop: `
                linear-gradient(135deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.7) 100%)
            `
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = '../dashboard.php';
            } else {
                window.location.href = '../dashboard.php';
            }
        });
    </script>
</body>
</html>