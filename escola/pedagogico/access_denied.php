<?php
// escola/pedagogico/access_denied.php - Página de Acesso Negado (Pode ser usado como include)
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acesso Negado | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            background: linear-gradient(135deg, rgba(0,0,0,0.8) 0%, rgba(0,0,0,0.7) 100%),
                        url('https://images.pexels.com/photos/5428836/pexels-photo-5428836.jpeg?auto=compress&cs=tinysrgb&w=1260&h=750&dpr=2');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .access-denied-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .modal-glass {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 40px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3), 0 0 0 1px rgba(255, 255, 255, 0.2);
            max-width: 550px;
            width: 100%;
            overflow: hidden;
            animation: modalSlideIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.9);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header-custom {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            padding: 30px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .modal-header-custom::before {
            content: '🚫';
            position: absolute;
            top: -20px;
            right: -20px;
            font-size: 120px;
            opacity: 0.15;
            transform: rotate(15deg);
        }

        .icon-circle {
            width: 100px;
            height: 100px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4);
            }
            70% {
                box-shadow: 0 0 0 20px rgba(255, 255, 255, 0);
            }
            100% {
                box-shadow: 0 0 0 0 rgba(255, 255, 255, 0);
            }
        }

        .icon-circle i {
            font-size: 48px;
            color: white;
        }

        .modal-header-custom h2 {
            color: white;
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 5px;
            letter-spacing: -0.5px;
        }

        .modal-header-custom p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.9rem;
            margin: 0;
        }

        .modal-body-custom {
            padding: 35px;
        }

        .warning-icon {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69b 100%);
            border-radius: 20px;
            padding: 15px;
            text-align: center;
            margin-bottom: 25px;
            border-left: 4px solid #ffc107;
        }

        .warning-icon i {
            font-size: 30px;
            color: #856404;
        }

        .warning-icon span {
            color: #856404;
            font-weight: 600;
        }

        .info-card {
            background: #f8f9fa;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            transition: transform 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
        }

        .info-card i {
            font-size: 28px;
            color: #006B3E;
            margin-bottom: 10px;
        }

        .info-card h4 {
            font-size: 1rem;
            font-weight: 700;
            color: #1A2A6C;
            margin-bottom: 5px;
        }

        .info-card p {
            font-size: 0.8rem;
            color: #6c757d;
            margin: 0;
        }

        .cargos-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 25px;
            justify-content: center;
        }

        .cargo-badge {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            color: #006B3E;
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .cargo-badge i {
            font-size: 0.9rem;
        }

        .btn-back {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 30px;
            font-weight: 700;
            font-size: 1rem;
            transition: all 0.3s ease;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .btn-back:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 107, 62, 0.3);
            color: white;
        }

        .btn-secondary-custom {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary-custom:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
        }

        .footer-note {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .footer-note p {
            font-size: 0.75rem;
            color: #999;
            margin: 0;
        }

        /* Animações de entrada */
        .animate-delay-1 { animation-delay: 0.1s; }
        .animate-delay-2 { animation-delay: 0.2s; }
        .animate-delay-3 { animation-delay: 0.3s; }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.5s ease-out forwards;
            opacity: 0;
        }
    </style>
</head>
<body>
    <div class="access-denied-wrapper">
        <div class="modal-glass">
            <div class="modal-header-custom">
                <div class="icon-circle">
                    <i class="fas fa-lock"></i>
                </div>
                <h2><i class="fas fa-exclamation-triangle me-2"></i> Acesso Negado!</h2>
                <p>Verifique suas credenciais ou contacte o administrador</p>
            </div>
            
            <div class="modal-body-custom">
                <div class="warning-icon fade-in-up animate-delay-1">
                    <i class="fas fa-shield-alt me-2"></i>
                    <span>Você não tem permissão para acessar esta área!</span>
                </div>

                <div class="info-card text-center fade-in-up animate-delay-2">
                    <i class="fas fa-user-tie"></i>
                    <h4>Esta área é restrita para:</h4>
                    <div class="cargos-list mt-3">
                        <span class="cargo-badge"><i class="fas fa-chalkboard-user"></i> Pedagogos</span>
                        <span class="cargo-badge"><i class="fas fa-users"></i> Coordenadores</span>
                        <span class="cargo-badge"><i class="fas fa-building"></i> Diretores</span>
                        <span class="cargo-badge"><i class="fas fa-user-shield"></i> Administradores</span>
                    </div>
                </div>

                <div class="row fade-in-up animate-delay-3">
                    <div class="col-12">
                        <a href="logout.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                        </a>
                    </div>
                </div>

                <div class="footer-note">
                    <p>
                        <i class="fas fa-info-circle"></i> Se você acredita que esta mensagem é um erro, 
                        entre em contato com o administrador do sistema.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Adicionar classe de animação aos elementos
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.fade-in-up');
            elements.forEach((el, index) => {
                setTimeout(() => {
                    el.style.opacity = '1';
                }, index * 150);
            });
        });
    </script>
</body>
</html>