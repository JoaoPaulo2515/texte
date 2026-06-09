<?php
// aluno/includes/menu_aluno.php - Menu Lateral do Aluno
?>

<style>
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
    }
    
    .sidebar-header {
        padding: 20px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .sidebar-header .logo {
        font-size: 2em;
        margin-bottom: 10px;
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
        gap: 12px;
    }
    
    .nav-link:hover,
    .nav-link.active {
        background: rgba(255,255,255,0.1);
        color: white;
    }
    
    .nav-link i { width: 20px; text-align: center; }
    
    .has-submenu .nav-link::after {
        content: '\f078';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        margin-left: auto;
        transition: transform 0.3s;
    }
    
    .has-submenu.open .nav-link::after {
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
</style>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-graduation-cap"></i></div>
        <h5>SIGE Angola</h5>
        <small>Área do Aluno</small>
        <div class="mt-3">
            <small><i class="fas fa-user"></i> <?php echo $_SESSION['aluno_nome'] ?? 'Aluno'; ?></small><br>
            <small><i class="fas fa-id-card"></i> <?php echo $_SESSION['aluno_matricula'] ?? '---'; ?></small>
        </div>
    </div>
    
    <ul class="nav-menu">
        <li class="nav-item">
            <a href="../aluno/index.php" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- Académico -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link"><i class="fas fa-graduation-cap"></i> Académico</a>
            <ul class="nav-submenu">
                <li><a href="academico/minhas_notas.php" class="nav-link">📊 Minhas Notas</a></li>
                <li><a href="academico/boletim.php" class="nav-link">📋 Boletim</a></li>
                <li><a href="academico/historico.php" class="nav-link">📜 Histórico Escolar</a></li>
                <li><a href="academico/frequencia.php" class="nav-link">📆 Frequência</a></li>
                <li><a href="academico/horario.php" class="nav-link">🕐 Horário de Aulas</a></li>
                <li><a href="academico/calendario.php" class="nav-link">📅 Calendário Académico</a></li>
            </ul>
        </li>
        
        <!-- Financeiro -->
        <li class="nav-item has-submenu" id="menuFinanceiro">
            <a href="#" class="nav-link"><i class="fas fa-coins"></i> Financeiro</a>
            <ul class="nav-submenu">
                <li><a href="financeiro/mensalidades.php" class="nav-link">💰 Mensalidades</a></li>
                <li><a href="financeiro/pagamentos.php" class="nav-link">💳 Histórico de Pagamentos</a></li>
                <li><a href="financeiro/extrato.php" class="nav-link">📊 Extrato Financeiro</a></li>
                <li><a href="financeiro/recibos.php" class="nav-link">🧾 Recibos</a></li>
            </ul>
        </li>
        
        <!-- Documentos -->
        <li class="nav-item has-submenu" id="menuDocumentos">
            <a href="#" class="nav-link"><i class="fas fa-file-alt"></i> Documentos</a>
            <ul class="nav-submenu">
                <li><a href="documentos/certificados.php" class="nav-link">🎓 Certificados</a></li>
                <li><a href="documentos/declaracoes.php" class="nav-link">📄 Declarações</a></li>
                <li><a href="documentos/comprovativos.php" class="nav-link">📎 Comprovativos</a></li>
            </ul>
        </li>
        
        <!-- Biblioteca -->
        <li class="nav-item has-submenu" id="menuBiblioteca">
            <a href="#" class="nav-link"><i class="fas fa-book-open"></i> Biblioteca</a>
            <ul class="nav-submenu">
                <li><a href="biblioteca/acervo.php" class="nav-link">📚 Consultar Acervo</a></li>
                <li><a href="biblioteca/emprestimos.php" class="nav-link">📖 Meus Empréstimos</a></li>
                <li><a href="biblioteca/reservas.php" class="nav-link">🔖 Reservas</a></li>
            </ul>
        </li>
        
        <!-- Comunicação -->
        <li class="nav-item has-submenu" id="menuComunicacao">
            <a href="#" class="nav-link"><i class="fas fa-envelope"></i> Comunicação</a>
            <ul class="nav-submenu">
                <li><a href="comunicacao/avisos.php" class="nav-link">📢 Avisos</a></li>
                <li><a href="comunicacao/notificacoes.php" class="nav-link">🔔 Notificações</a></li>
                <li><a href="comunicacao/contato.php" class="nav-link">📞 Contactar a Escola</a></li>
            </ul>
        </li>
        
        <li class="nav-item"><hr style="margin: 10px 25px; border-color: rgba(255,255,255,0.1);"></li>
        
        <li class="nav-item">
            <a href="perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a>
        </li>
        <li class="nav-item">
            <a href="alterar_senha.php" class="nav-link"><i class="fas fa-key"></i> Alterar Senha</a>
        </li>
        <li class="nav-item">
            <a href="../logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </li>
    </ul>
</div>

<script>
    // Manter submenu aberto baseado na URL atual
    document.querySelectorAll('.has-submenu').forEach(menu => {
        const submenu = menu.querySelector('.nav-submenu');
        const links = submenu.querySelectorAll('a');
        if (Array.from(links).some(link => link.href === window.location.href)) {
            menu.classList.add('open');
        }
    });
    
    // Toggle submenu
    document.querySelectorAll('.has-submenu > a').forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const parent = link.closest('.has-submenu');
            parent.classList.toggle('open');
        });
    });
</script>