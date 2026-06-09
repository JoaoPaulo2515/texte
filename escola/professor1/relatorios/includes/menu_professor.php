<?php
// escola/professor/includes/menu_professor.php - Menu Lateral do Professor

// Este arquivo contém o menu completo do professor
// Para usar em qualquer página, basta incluir:
// <?php include 'includes/menu_professor.php'; ?>

// Detectar a página atual para destacar o item ativo
$pagina_atual = basename($_SERVER['PHP_SELF']);
?>

<style>
    /* Sidebar */
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        width: 280px;
        height: 100vh;
        background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
        color: white;
        transition: all 0.3s;
        z-index: 1000;
        overflow-y: auto;
        box-shadow: 2px 0 10px rgba(0,0,0,0.1);
    }
    
    .sidebar-header {
        padding: 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .sidebar-header .logo {
        font-size: 2.5em;
        margin-bottom: 10px;
    }
    
    .sidebar-header h3 {
        font-size: 1.3em;
        margin-bottom: 5px;
    }
    
    .sidebar-header p {
        font-size: 0.8em;
        opacity: 0.8;
    }
    
    .nav-menu {
        list-style: none;
        padding: 20px 0;
        margin: 0;
    }
    
    .nav-item {
        margin-bottom: 5px;
    }
    
    .nav-link {
        display: flex;
        align-items: center;
        padding: 12px 25px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        transition: all 0.3s;
        gap: 12px;
        font-size: 0.95em;
    }
    
    .nav-link:hover {
        background: rgba(255,255,255,0.1);
        color: white;
    }
    
    .nav-link.active {
        background: rgba(255,255,255,0.15);
        color: white;
        border-left: 4px solid #FFD700;
    }
    
    .nav-link i {
        width: 20px;
        text-align: center;
    }
    
    .has-submenu {
        position: relative;
    }
    
    .has-submenu > .nav-link {
        cursor: pointer;
    }
    
    .has-submenu > .nav-link::after {
        content: '\f078';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        margin-left: auto;
        transition: transform 0.3s;
    }
    
    .has-submenu.open > .nav-link::after {
        transform: rotate(180deg);
    }
    
    .nav-submenu {
        list-style: none;
        padding-left: 55px;
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.3s ease-out;
    }
    
    .has-submenu.open .nav-submenu {
        max-height: 500px;
    }
    
    .nav-submenu .nav-link {
        padding: 10px 25px;
        font-size: 0.85em;
    }
    
    .nav-submenu .nav-link i {
        font-size: 0.8em;
    }
    
    .main-content {
        margin-left: 280px;
        padding: 20px;
        background: #f5f7fb;
        min-height: 100vh;
    }
    
    .top-bar {
        background: white;
        border-radius: 15px;
        padding: 15px 25px;
        margin-bottom: 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    .welcome-text h2 {
        font-size: 1.5em;
        margin-bottom: 5px;
    }
    
    .welcome-text p {
        color: #666;
        margin: 0;
    }
    
    .user-info {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .user-avatar {
        width: 50px;
        height: 50px;
        background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5em;
    }
    
    .menu-toggle {
        display: none;
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1001;
        background: #006B3E;
        color: white;
        border: none;
        width: 40px;
        height: 40px;
        border-radius: 8px;
        cursor: pointer;
    }
    
    .btn-voltar {
        background: #6c757d;
        color: white;
        border-radius: 25px;
        padding: 8px 20px;
        text-decoration: none;
        border: none;
        margin-bottom: 15px;
        display: inline-block;
    }
    
    .btn-voltar:hover {
        background: #5a6268;
        color: white;
    }
    
    @media (max-width: 768px) {
        .sidebar {
            left: -280px;
        }
        .sidebar.open {
            left: 0;
        }
        .main-content {
            margin-left: 0;
        }
        .menu-toggle {
            display: block;
        }
        .top-bar {
            flex-direction: column;
            text-align: center;
            gap: 15px;
        }
    }
</style>

<button class="menu-toggle" id="menuToggle">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-chalkboard-user"></i>
        </div>
        <h3>SIGE Angola</h3>
        <p>Área do Professor</p>
    </div>
    
    <ul class="nav-menu">
        <!-- Dashboard -->
        <li class="nav-item">
            <a href="../dashboard.php" class="nav-link <?php echo $pagina_atual == 'dashboard.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- Menu Acadêmico -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-graduation-cap"></i> Menu Acadêmico
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="../minhas_turmas.php" class="nav-link <?php echo $pagina_atual == 'minhas_turmas.php' ? 'active' : ''; ?>"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                <li class="nav-item"><a href="../lancar_notas.php" class="nav-link <?php echo $pagina_atual == 'lancar_notas.php' ? 'active' : ''; ?>"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                <li class="nav-item"><a href="../registrar_chamada.php" class="nav-link <?php echo $pagina_atual == 'registrar_chamada.php' ? 'active' : ''; ?>"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                <li class="nav-item"><a href="../atividades.php" class="nav-link <?php echo $pagina_atual == 'atividades.php' ? 'active' : ''; ?>"><i class="fas fa-tasks"></i> Atividades</a></li>
                <li class="nav-item"><a href="../meus_alunos.php" class="nav-link <?php echo $pagina_atual == 'meus_alunos.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Meus Alunos</a></li>
            </ul>
        </li>
        
        <!-- Meu Horário -->
        <li class="nav-item">
            <a href="meu_horario.php" class="nav-link <?php echo $pagina_atual == 'meu_horario.php' ? 'active' : ''; ?>">
                <i class="fas fa-calendar-week"></i> Meu Horário
            </a>
        </li>
        
        <!-- Relatórios -->
        <li class="nav-item has-submenu" id="menuRelatorios">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-chart-line"></i> Relatórios
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="../relatorios/index.php" class="nav-link"><i class="fas fa-home"></i> Index</a></li>
                <li class="nav-item"><a href="../relatorios/mini_pautas.php" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                <li class="nav-item"><a href="../relatorios/pautas_gerais.php" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                <li class="nav-item"><a href="../relatorios/estatistica_turma.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                <li class="nav-item"><a href="../relatorios/estatistica_disciplina.php" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
            </ul>
        </li>
        
        <!-- Agenda -->
        <li class="nav-item has-submenu" id="menuAgenda">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-calendar-alt"></i> Agenda
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                <li class="nav-item"><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
            </ul>
        </li>
        
        <!-- Financeiro -->
        <li class="nav-item has-submenu" id="menuFinanceiro">
            <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                <i class="fas fa-coins"></i> Financeiro
            </a>
            <ul class="nav-submenu">
                <li class="nav-item"><a href="meu_perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                <li class="nav-item"><a href="meu_salario.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                <li class="nav-item"><a href="dividas_pagar.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                <li class="nav-item"><a href="dividas_receber.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                <li class="nav-item"><a href="solicitar_vale.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                <li class="nav-item"><a href="solicitar_ferias.php" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
            </ul>
        </li>
        
        <!-- Conselho de Nota -->
        <li class="nav-item">
            <a href="conselho_nota.php" class="nav-link <?php echo $pagina_atual == 'conselho_nota.php' ? 'active' : ''; ?>">
                <i class="fas fa-chalkboard-user"></i> Conselho de Nota
            </a>
        </li>
        
        <!-- Chamada -->
        <li class="nav-item">
            <a href="registrar_chamada.php" class="nav-link <?php echo $pagina_atual == 'registrar_chamada.php' ? 'active' : ''; ?>">
                <i class="fas fa-clipboard-list"></i> Chamada
            </a>
        </li>
        
        <!-- Lançamento de Nota -->
        <li class="nav-item">
            <a href="lancar_notas.php" class="nav-link <?php echo $pagina_atual == 'lancar_notas.php' ? 'active' : ''; ?>">
                <i class="fas fa-edit"></i> Lançamento de Nota
            </a>
        </li>
        
        <!-- Biblioteca -->
        <li class="nav-item">
            <a href="biblioteca.php" class="nav-link <?php echo $pagina_atual == 'biblioteca.php' ? 'active' : ''; ?>">
                <i class="fas fa-book"></i> Biblioteca
            </a>
        </li>
        
        <!-- Proposta de Prova -->
        <li class="nav-item">
            <a href="proposta_prova.php" class="nav-link <?php echo $pagina_atual == 'proposta_prova.php' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Proposta de Prova
            </a>
        </li>
        
        <!-- Sair -->
        <li class="nav-item">
            <a href="../../logout.php" class="nav-link">
                <i class="fas fa-sign-out-alt"></i> Sair
            </a>
        </li>
    </ul>
</div>

<script>
    // Toggle submenu
    function toggleSubmenu(event) {
        if (event) event.preventDefault();
        const parent = event.currentTarget.closest('.has-submenu');
        if (parent) {
            parent.classList.toggle('open');
        }
    }
    
    // Toggle sidebar mobile
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.getElementById('menuToggle');
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('open');
            });
        }
        
        // Fechar sidebar ao clicar em link (mobile)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                }
            });
        });
        
        // Manter submenus abertos baseado na URL atual
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.nav-submenu .nav-link').forEach(link => {
            if (currentUrl.includes(link.getAttribute('href'))) {
                const parent = link.closest('.has-submenu');
                if (parent) parent.classList.add('open');
            }
        });
    });
</script>