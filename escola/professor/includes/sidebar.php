<?php
// escola/professor/includes/sidebar.php - Sidebar do Professor

$current_page = basename($_SERVER['PHP_SELF']);
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
        padding: 25px;
        text-align: center;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    .sidebar-header .logo {
        font-size: 2.5em;
        margin-bottom: 10px;
    }
    .nav-menu {
        list-style: none;
        padding: 20px 0;
        margin: 0;
    }
    .nav-link {
        display: flex;
        align-items: center;
        padding: 12px 25px;
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        gap: 12px;
        transition: all 0.3s;
    }
    .nav-link:hover,
    .nav-link.active {
        background: rgba(255,255,255,0.1);
        color: white;
    }
    .nav-link i {
        width: 24px;
        text-align: center;
    }
    .nav-submenu {
        list-style: none;
        padding-left: 50px;
        margin: 0;
        display: none;
    }
    .nav-submenu.show {
        display: block;
    }
    .nav-item.has-submenu > .nav-link {
        position: relative;
    }
    .nav-item.has-submenu > .nav-link:after {
        content: '\f107';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        right: 25px;
        transition: transform 0.3s;
    }
    .nav-item.has-submenu.open > .nav-link:after {
        transform: rotate(180deg);
    }
    .main-content {
        margin-left: 280px;
        padding: 20px;
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
    @media (max-width: 768px) {
        .sidebar { left: -280px; }
        .sidebar.open { left: 0; }
        .main-content { margin-left: 0; }
        .menu-toggle { display: block; }
    }
</style>

    <?php include 'menu_professor.php'; ?>


<script>
    $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
    
    function toggleSubmenu(event) {
        event.preventDefault();
        const parentLi = $(event.currentTarget).closest('.has-submenu');
        const submenu = parentLi.find('.nav-submenu');
        $('.has-submenu').not(parentLi).removeClass('open');
        $('.nav-submenu').not(submenu).removeClass('show');
        parentLi.toggleClass('open');
        submenu.toggleClass('show');
    }
    
    $('.has-submenu > .nav-link').on('click', toggleSubmenu);
    
    const currentPage = window.location.pathname;
    if (currentPage.includes('lancar_notas') || currentPage.includes('registrar_chamada') || currentPage.includes('atividades')) {
        $('#menuAcademico').addClass('open');
        $('#submenuAcademico').addClass('show');
    }
</script>