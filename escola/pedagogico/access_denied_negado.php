<?php
// escola/pedagogico/access_denied.php - Página de Acesso Negado com Modal Moderno
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Acesso Negado | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Efeito de fundo animado */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1440 320"><path fill="rgba(255,255,255,0.05)" fill-opacity="1" d="M0,96L48,112C96,128,192,160,288,160C384,160,480,128,576,122.7C672,117,768,139,864,154.7C960,171,1056,181,1152,165.3C1248,149,1344,107,1392,85.3L1440,64L1440,320L1392,320C1344,320,1248,320,1152,320C1056,320,960,320,864,320C768,320,672,320,576,320C480,320,384,320,288,320C192,320,96,320,48,320L0,320Z"></path></svg>') no-repeat bottom;
            background-size: cover;
            opacity: 0.5;
        }

        .access-denied-container {
            max-width: 500px;
            width: 100%;
            z-index: 10;
            animation: slideIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 40px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            border: 1px solid rgba(255, 255, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(0,107,62,0.05) 0%, rgba(0,0,0,0) 70%);
            transform: rotate(45deg);
        }

        .icon-circle {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            animation: pulse 2s infinite;
            box-shadow: 0 10px 25px rgba(220, 53, 69, 0.3);
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.4);
                transform: scale(1);
            }
            50% {
                box-shadow: 0 0 0 20px rgba(220, 53, 69, 0);
                transform: scale(1.05);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
                transform: scale(1);
            }
        }

        .icon-circle i {
            font-size: 48px;
            color: white;
        }

        h2 {
            color: #dc3545;
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 15px;
            letter-spacing: -0.5px;
        }

        .warning-text {
            color: #666;
            margin-bottom: 20px;
            font-size: 1rem;
            line-height: 1.5;
        }

        .cargos-list {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: center;
            margin: 25px 0;
        }

        .cargo-badge {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #006B3E;
            padding: 10px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .cargo-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.2);
        }

        .cargo-badge i {
            font-size: 1rem;
        }

        .btn-dashboard {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 30px;
            font-weight: 700;
            font-size: 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
            width: 100%;
            margin-top: 10px;
            cursor: pointer;
        }

        .btn-dashboard:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 107, 62, 0.3);
            color: white;
        }

        .footer-note {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .footer-note p {
            font-size: 0.75rem;
            color: #999;
            margin: 0;
        }

        .contact-link {
            color: #006B3E;
            text-decoration: none;
            font-weight: 600;
        }

        .contact-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .glass-card {
                padding: 30px 20px;
            }
            h2 {
                font-size: 1.5rem;
            }
            .cargo-badge {
                padding: 6px 15px;
                font-size: 0.75rem;
            }
            .icon-circle {
                width: 80px;
                height: 80px;
            }
            .icon-circle i {
                font-size: 38px;
            }
        }
    </style>
</head>
<body>
    <div class="access-denied-container">
        <div class="glass-card">
            <div class="icon-circle">
                <i class="fas fa-lock"></i>
            </div>
            <h2><i class="fas fa-exclamation-triangle me-2"></i> Acesso Negado!</h2>
            <p class="warning-text">Você não tem permissão para acessar esta área.</p>
            <p class="warning-text">Esta área é restrita para:</p>
            
            <div class="cargos-list">
                <span class="cargo-badge"><i class="fas fa-chalkboard-user"></i> Pedagogos</span>
                <span class="cargo-badge"><i class="fas fa-users"></i> Coordenadores</span>
                <span class="cargo-badge"><i class="fas fa-building"></i> Diretores</span>
                <span class="cargo-badge"><i class="fas fa-user-shield"></i> Administradores</span>
            </div>

            <a href="../dashboard.php" class="btn-dashboard">
                <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
            </a>

            <div class="footer-note">
                <p>
                    <i class="fas fa-info-circle"></i> Se você acredita que esta mensagem é um erro, 
                    entre em contato com o <a href="#" class="contact-link">administrador do sistema</a>.
                </p>
                <p class="mt-2">
                    <i class="fas fa-shield-alt"></i> Sistema Seguro | SIGE Angola
                </p>
            </div>
        </div>
    </div>

    <script>
        // Animação adicional ao carregar
        document.addEventListener('DOMContentLoaded', function() {
            // Efeito de confete/partículas (opcional)
            const colors = ['#006B3E', '#1A2A6C', '#28a745', '#dc3545'];
            for(let i = 0; i < 50; i++) {
                let particle = document.createElement('div');
                particle.style.position = 'absolute';
                particle.style.width = '4px';
                particle.style.height = '4px';
                particle.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                particle.style.borderRadius = '50%';
                particle.style.left = Math.random() * 100 + '%';
                particle.style.top = Math.random() * 100 + '%';
                particle.style.opacity = Math.random() * 0.5;
                particle.style.pointerEvents = 'none';
                document.body.appendChild(particle);
            }
        });
    </script>
</body>
</html>