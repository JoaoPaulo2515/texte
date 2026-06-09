<?php
// menu_escola.php - Menu lateral para o Dashboard da Escola (Design melhorado)

// ============================================
// SISTEMA DE REDIRECIONAMENTO INTELIGENTE
// ============================================

// Configuração de rotas - Mapeamento de todos os submenus para seus caminhos reais
$rotas = [
    'dashboard' => 'index.php',
    'alunos' => 'alunos/index.php',
    'professores' => 'professores/index.php',
    'turmas' => 'turmas/index.php',
    'disciplinas' => 'disciplinas/index.php',
    'notas' => 'notas/index.php',
    'chamada' => 'chamada/index.php',
    'provas' => 'avaliacao/provas/index.php',
    'pautas' => 'avaliacao/pautas/index.php',
    'tipos_prova' => 'avaliacao/tipos/index.php',
    'avaliacao_curso' => 'avaliacao/por_curso/index.php',
    'sistema_avaliacao' => 'avaliacao/sistema/index.php',
    'aproveitamento_aluno' => 'avaliacao/aproveitamento/index.php',
    'lista_nominal' => 'relatorios/lista_nominal.php',
    'estatistico_alunos' => 'relatorios/estatistico_alunos.php',
    'relatorio_professor' => 'relatorios/professor.php',
    'inscricoes' => 'relatorios/inscricoes.php',
    'estatistica_professor' => 'relatorios/estatistica_professor.php',
    'manipautas' => 'relatorios/manipautas.php',
    'boletim_nota' => 'relatorios/boletim_nota.php',
    'historico_notas' => 'relatorios/historico_notas.php',
    'historico_faltas' => 'relatorios/historico_faltas.php',
    'cadernetas' => 'relatorios/cadernetas.php',
    'tesouraria_dashboard' => 'tesouraria/index.php',
    'tesouraria_pagamentos' => 'tesouraria/pagamentos.php',
    'tesouraria_mensalidades' => 'tesouraria/mensalidades.php',
    'tesouraria_ver_pagamentos' => 'tesouraria/ver_pagamentos.php',
    'tesouraria_relatorios_financeiros' => 'tesouraria/relatorios_financeiros.php',
    'tesouraria_relatorios_diarios' => 'tesouraria/relatorios_diarios.php',
    'tesouraria_dividas' => 'tesouraria/dividas.php',
    'tesouraria_caixa' => 'tesouraria/caixa.php',
    'tesouraria_receitas' => 'tesouraria/receitas.php',
    'tesouraria_despesas' => 'tesouraria/despesas.php',
    'tesouraria_fluxo_caixa' => 'tesouraria/fluxo_caixa.php',
    'tesouraria_balancete' => 'tesouraria/balancete.php',
    'tesouraria_extrato' => 'tesouraria/extrato.php',
    'tesouraria_recibos' => 'tesouraria/recibos.php',
    'tesouraria_config' => 'tesouraria/config.php',
    'clientes' => 'fiscal/clientes/index.php',
    'fornecedores' => 'fiscal/fornecedores/index.php',
    'notas_fiscais' => 'fiscal/notas_fiscais.php',
    'impostos' => 'fiscal/impostos.php',
    'novo_produto' => 'produtos/novo.php',
    'artigos' => 'produtos/artigos/index.php',
    'estoque' => 'produtos/estoque/index.php',
    'categorias' => 'produtos/categorias.php',
    'rh_dashboard' => 'rh/index.php',
    'rh_funcionarios_listar' => 'rh/funcionarios/listar.php',
    'rh_funcionarios_cadastrar' => 'rh/funcionarios/cadastrar.php',
    'rh_funcionarios_visualizar' => 'rh/funcionarios/visualizar.php',
    'rh_vagas' => 'rh/recrutamento/vagas.php',
    'rh_candidatos' => 'rh/recrutamento/candidatos.php',
    'rh_avaliacao_periodos' => 'rh/avaliacao/periodos.php',
    'rh_avaliacao_resultados' => 'rh/avaliacao/resultados.php',
    'rh_formacao' => 'rh/formacao/planos.php',
    'rh_documentacao' => 'rh/documentacao/index.php',
    'rh_configurar' => 'rh/configurar.php',
    'rh_relatorios' => 'rh/relatorios.php',
    'secretaria_lista_alunos' => 'secretaria/lista_alunos.php',
    'secretaria_alunos_matriculados' => 'secretaria/alunos_matriculados.php',
    'secretaria_inscricoes' => 'secretaria/inscricoes.php',
    'secretaria_rematricula' => 'secretaria/rematricula.php',
    'secretaria_matricula' => 'secretaria/matricula.php',
    'secretaria_documentos' => 'secretaria/documentos.php',
    'secretaria_certificados' => 'secretaria/certificados.php',
    'secretaria_declaracao' => 'secretaria/declaracao.php',
    'secretaria_boletim' => 'secretaria/boletim.php',
    'secretaria_estatisticas_turma' => 'secretaria/estatisticas_turma.php',
    'secretaria_estatisticas_disciplina' => 'secretaria/estatisticas_disciplina.php',
    'secretaria_estatisticas_geral' => 'secretaria/estatisticas_geral.php',
    'financeiro_dashboard' => 'financeiro/index.php',
    'financeiro_tipos_pagamentos' => 'financeiro/tipos_pagamento.php',
    'financeiro_lancar_pagamentos_obrigatorio' => 'financeiro/pagamentos_obrigatorio.php',
    'financeiro_outros_pagamentos' => 'financeiro/outros_pagamentos.php',
    'financeiro_config' => 'financeiro/config.php',
    'contas_receber' => 'financeiro/contas_receber/index.php',
    'contas_pagar' => 'financeiro/contas_pagar/index.php',
    'fluxo_caixa' => 'financeiro/fluxo_caixa/index.php',
    'balancete' => 'financeiro/balancete/index.php',
    'orcamento' => 'financeiro/orcamento/index.php',
    'taxas' => 'financeiro/taxas/index.php',
    'parcelamentos' => 'financeiro/parcelamentos/index.php',
    'folha_pagamento' => 'financeiro/folha_pagamento/index.php',
    'financeiro_mensalidades' => 'financeiro/mensalidades.php',
    'extratos' => 'financeiro/extratos.php',
    'recibos' => 'financeiro/recibos.php',
    'servicos_gerais' => 'servicos_pedagogicos/gerais/index.php',
    'disciplina_turma' => 'servicos_pedagogicos/disciplina_turma/index.php',
    'disciplina_classe' => 'servicos_pedagogicos/disciplina_classe/index.php',
    'coordenacao' => 'servicos_pedagogicos/coordenacao/index.php',
    'biblioteca_acervo' => 'biblioteca/index.php',
    'biblioteca_cadastrar' => 'biblioteca/cadastrar.php',
    'biblioteca_emprestimos' => 'biblioteca/emprestimos.php',
    'biblioteca_reservas' => 'biblioteca/reservas.php',
    'config_geral' => 'config/geral/index.php',
    'config_banco' => 'config/banco/index.php',
    'config_pagamento' => 'config/pagamento/index.php',
    'config_sistema' => 'config/sistema/index.php',
    'config_email' => 'config/email/index.php',
    'config_backup' => 'config/backup/index.php',
    'config_permissoes' => 'config/permissoes.php',
    'suporte_chamados' => '../suporte/chamados.php',
    'suporte_faq' => '../suporte/faq.php',
    'suporte_manuais' => '../suporte/manuais.php',
    'suporte_tutoriais' => '../suporte/tutoriais.php',
    'perfil' => 'perfil.php',
];

function getLink($destino, $rotas) {
    if (isset($rotas[$destino])) {
        return $rotas[$destino];
    }
    if (strpos($destino, 'http') === 0 || strpos($destino, '//') === 0) {
        return $destino;
    }
    return $destino;
}

function getEscolaInfo($conn, $escola_id) {
    try {
        $sql = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $escola_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return ['nome' => 'SIGE Angola', 'endereco' => '', 'telefone' => '', 'email' => '', 'nif' => ''];
    }
}

function getVersaoSistema() { return '2.5.0'; }
function getDataValidadeLicenca() { return '2025-12-31'; }

function getNotificacoesNaoLidas($conn, $usuario_id) {
    try {
        $sql = "SELECT COUNT(*) as total FROM notificacoes WHERE usuario_id = :usuario_id AND lida = 0";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':usuario_id' => $usuario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    } catch (PDOException $e) { return 0; }
}

function getUltimoAcesso($conn, $usuario_id) {
    try {
        $sql = "SELECT ultimo_acesso FROM usuarios WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $usuario_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC)['ultimo_acesso'] ?? date('Y-m-d H:i:s');
    } catch (PDOException $e) { return date('Y-m-d H:i:s'); }
}

function atualizarUltimoAcesso($conn, $usuario_id) {
    try {
        $sql = "UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $usuario_id]);
    } catch (PDOException $e) {}
}

function isLicencaExpirada() {
    $data_validade = getDataValidadeLicenca();
    $hoje = new DateTime();
    $validade = new DateTime($data_validade);
    return $hoje > $validade;
}

function getDiasRestantesLicenca() {
    $data_validade = getDataValidadeLicenca();
    $hoje = new DateTime();
    $validade = new DateTime($data_validade);
    return $hoje->diff($validade)->days;
}

if (isset($_SESSION['usuario_id']) && isset($conn)) {
    atualizarUltimoAcesso($conn, $_SESSION['usuario_id']);
}

$escola_info = [];
if (isset($_SESSION['escola_id']) && isset($conn)) {
    $escola_info = getEscolaInfo($conn, $_SESSION['escola_id']);
}

$total_notificacoes = 0;
if (isset($_SESSION['usuario_id']) && isset($conn)) {
    $total_notificacoes = getNotificacoesNaoLidas($conn, $_SESSION['usuario_id']);
}

$ultimo_acesso = '';
if (isset($_SESSION['usuario_id']) && isset($conn)) {
    $ultimo_acesso = getUltimoAcesso($conn, $_SESSION['usuario_id']);
}

$versao_sistema = getVersaoSistema();
$licenca_expirada = isLicencaExpirada();
$dias_restantes = getDiasRestantesLicenca();
$ano_atual = date('Y');
$current_file = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title><?php echo $titulo_pagina ?? 'Área da Escola'; ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ==============================================
           DESIGN MODERNO MELHORADO - BORDAS ARREDONDADAS
           ============================================== */
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* ==============================================
           SIDEBAR MODERNO
           ============================================== */
        .sidebar-escola {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #0d2a3e 0%, #0a1a2e 50%, #0d2a3e 100%);
            color: white;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(0, 0, 0, 0.2);
            border-radius: 0 24px 24px 0;
        }
        
        .sidebar-escola::-webkit-scrollbar { width: 5px; }
        .sidebar-escola::-webkit-scrollbar-track { background: rgba(255,255,255,0.08); border-radius: 10px; }
        .sidebar-escola::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 10px; }
        .sidebar-escola::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.4); }
        
        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            position: relative;
            z-index: 2;
            background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0));
        }
        
        .sidebar-header .logo {
            font-size: 3em;
            margin-bottom: 12px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
            transition: transform 0.3s ease;
        }
        
        .sidebar-header .logo:hover {
            transform: scale(1.05);
        }
        
        .sidebar-header h3 {
            font-size: 1.4em;
            margin-bottom: 5px;
            font-weight: 700;
            letter-spacing: 1px;
        }
        
        .sidebar-header p {
            font-size: 0.75em;
            opacity: 0.7;
            letter-spacing: 1px;
        }
        
        .user-info-sidebar {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-size: 0.8em;
            line-height: 1.6;
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        
        .user-info-sidebar:hover {
            background: rgba(255,255,255,0.08);
        }
        
        .user-info-sidebar i {
            width: 24px;
            margin-right: 8px;
            opacity: 0.7;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 12px;
            margin: 0;
            position: relative;
            z-index: 2;
        }
        
        .nav-item { margin-bottom: 6px; }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            gap: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
            border-radius: 14px;
            font-weight: 500;
            font-size: 0.95rem;
            position: relative;
            overflow: hidden;
        }
        
        .nav-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: rgba(255,255,255,0.05);
            transition: width 0.3s ease;
            z-index: -1;
        }
        
        .nav-link:hover::before {
            width: 100%;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link.active {
            background: linear-gradient(90deg, rgba(255,215,0,0.2), rgba(255,215,0,0.05));
            border-left: 3px solid #FFD700;
        }
        
        .nav-link i {
            width: 24px;
            text-align: center;
            font-size: 1.2em;
            transition: transform 0.3s ease;
        }
        
        .nav-link:hover i {
            transform: scale(1.1);
        }
        
        .has-submenu { position: relative; }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s ease;
            font-size: 0.75rem;
            opacity: 0.7;
        }
        
        .has-submenu.open > .nav-link::after { transform: rotate(180deg); }
        .has-submenu.open > .nav-link { 
            background: rgba(255,255,255,0.1); 
            border-radius: 14px 14px 12px 12px;
            margin-bottom: 5px;
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .has-submenu.open .nav-submenu { 
            max-height: 800px; 
            overflow-y: auto;
            margin-bottom: 8px;
        }
        
        .nav-submenu .nav-link {
            padding: 10px 18px;
            font-size: 0.85em;
            border-radius: 12px;
            margin: 3px 0;
        }
        
        .nav-submenu .nav-link:hover { 
            background: rgba(255,255,255,0.08); 
            transform: translateX(5px);
        }
        
        .nav-submenu .nav-link i { 
            font-size: 0.9em; 
            width: 20px;
        }
        
        /* ==============================================
           TOP HEADER GLASSMORPHISM
           ============================================== */
        .top-header-escola {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(12px);
            box-shadow: 0 4px 25px rgba(0,0,0,0.08);
            position: fixed;
            top: 0;
            right: 0;
            left: 280px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 35px;
            z-index: 999;
            transition: all 0.3s;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            border-radius: 0 0 0 24px;
        }
        
        .header-left { display: flex; align-items: center; gap: 25px; }
        
        .page-title {
            font-size: 1.4em;
            font-weight: 700;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            letter-spacing: -0.5px;
        }
        
        .date-time {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.85em;
            color: #2c3e50;
            font-weight: 500;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02), 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .date-time i { margin-right: 5px; color: #006B3E; }
        .realtime-badge { 
            font-size: 0.65em; 
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white; 
            padding: 2px 8px; 
            border-radius: 20px; 
            margin-left: 8px;
        }
        
        .header-right { display: flex; align-items: center; gap: 20px; }
        
        .notifications-btn, .chat-btn {
            background: #f8f9fa;
            border: none;
            font-size: 1.2em;
            color: #555;
            cursor: pointer;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .notifications-btn:hover, .chat-btn:hover { 
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            transform: translateY(-2px);
        }
        
        .notification-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc3545;
            color: white;
            font-size: 0.65em;
            padding: 2px 6px;
            border-radius: 20px;
            min-width: 18px;
            height: 18px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .notifications-dropdown {
            position: absolute;
            top: 65px;
            right: 80px;
            width: 380px;
            max-height: 500px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            display: none;
            z-index: 1001;
            overflow: hidden;
            animation: fadeInDown 0.3s ease;
            border: 1px solid rgba(0,0,0,0.05);
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .notifications-dropdown.show { display: block; }
        
        .notifications-header {
            padding: 15px 20px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .marcar-todas {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 0.7rem;
            padding: 5px 12px;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .marcar-todas:hover { 
            background: rgba(255,255,255,0.3);
            transform: translateY(-1px);
        }
        
        .notifications-list { max-height: 400px; overflow-y: auto; }
        
        .notification-item {
            padding: 15px 20px;
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.2s;
            cursor: pointer;
            display: flex;
            gap: 12px;
        }
        
        .notification-item:hover { background: #f8f9fa; }
        .notification-item.nao-lida { 
            background: linear-gradient(135deg, #e8f5e9, #f0f7ff);
            border-left: 3px solid #28a745;
        }
        
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .notification-icon.primary { background: #006B3E20; color: #006B3E; }
        .notification-icon.warning { background: #ffc10720; color: #ffc107; }
        .notification-icon.success { background: #28a74520; color: #28a745; }
        .notification-icon.info { background: #17a2b820; color: #17a2b8; }
        .notification-icon.danger { background: #dc354520; color: #dc3545; }
        
        .notification-title { font-weight: 600; color: #333; margin-bottom: 4px; font-size: 0.9rem; }
        .notification-message { font-size: 0.8rem; color: #666; margin-bottom: 4px; }
        .notification-time { font-size: 0.7rem; color: #999; }
        
        .notifications-footer { 
            padding: 12px 20px; 
            text-align: center; 
            border-top: 1px solid #eee; 
            background: #f8f9fa;
        }
        
        .notifications-footer a {
            color: #006B3E;
            text-decoration: none;
            font-weight: 500;
        }
        
        .notifications-footer a:hover {
            text-decoration: underline;
        }
        
        .user-dropdown { position: relative; cursor: pointer; }
        
        .user-info-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 15px 6px 10px;
            border-radius: 50px;
            background: #f8f9fa;
            transition: all 0.3s;
        }
        
        .user-info-header:hover { 
            background: #e9ecef;
            transform: translateY(-2px);
        }
        
        .user-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            transition: transform 0.3s;
        }
        
        .user-info-header:hover .user-avatar {
            transform: scale(1.05);
        }
        
        .user-name { font-weight: 600; color: #333; }
        .user-role { font-size: 0.7em; color: #999; margin-top: 2px; }
        
        .dropdown-menu-custom {
            position: absolute;
            top: 60px;
            right: 0;
            width: 260px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
            display: none;
            z-index: 1000;
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.05);
            animation: fadeInDown 0.2s ease;
        }
        
        .dropdown-menu-custom.show { display: block; }
        
        .dropdown-item-custom {
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #333;
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .dropdown-item-custom:hover { 
            background: #f8f9fa;
            padding-left: 25px;
        }
        
        .dropdown-item-custom i { width: 22px; color: #006B3E; }
        .dropdown-divider { height: 1px; background: #eee; margin: 5px 0; }
        
        .last-access {
            padding: 10px 15px;
            font-size: 0.7em;
            color: #999;
            border-top: 1px solid #eee;
        }
        
        /* ==============================================
           MAIN CONTENT
           ============================================== */
        .main-content-escola {
            margin-left: 280px;
            margin-top: 70px;
            margin-bottom: 45px;
            padding: 25px 30px;
            background: #f5f7fb;
            min-height: calc(100vh - 115px);
        }
        
        .card {
            background: white;
            border-radius: 24px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 25px 35px -12px rgba(0,0,0,0.15);
        }
        
        /* ==============================================
           FOOTER
           ============================================== */
        .footer-escola {
            position: fixed;
            bottom: 0;
            right: 0;
            left: 280px;
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(10px);
            padding: 12px 35px;
            font-size: 0.7em;
            color: #666;
            border-top: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 998;
            transition: all 0.3s;
        }
        
        .footer-left, .footer-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .footer-left span:hover, .footer-right span:hover {
            color: #006B3E;
        }
        
        .menu-toggle-escola {
            display: none;
            position: fixed;
            top: 18px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 1.2em;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            transition: all 0.3s;
        }
        
        .menu-toggle-escola:hover {
            transform: scale(1.05);
        }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .sidebar-escola { left: -280px; }
            .sidebar-escola.open { left: 0; border-radius: 0; }
            .top-header-escola { left: 0; border-radius: 0; padding: 0 20px; }
            .footer-escola { left: 0; padding: 10px 20px; flex-direction: column; gap: 8px; }
            .main-content-escola { margin-left: 0; margin-top: 70px; padding: 20px; }
            .menu-toggle-escola { display: block; }
            .page-title { margin-left: 55px; font-size: 1.1em; }
            .user-name { display: none; }
            .notifications-dropdown { width: 320px; right: 20px; top: 60px; }
            .date-time .realtime-badge { display: none; }
            .date-time { font-size: 0.7em; padding: 5px 12px; }
            .footer-left, .footer-right { justify-content: center; width: 100%; }
        }
        
        @media (max-width: 480px) {
            .main-content-escola { padding: 15px; }
            .top-header-escola { height: 60px; }
            .notifications-btn, .chat-btn { width: 36px; height: 36px; }
            .user-avatar { width: 36px; height: 36px; }
        }
    </style>
</head>
<body>

<button class="menu-toggle-escola" id="menuToggle"><i class="fas fa-bars"></i></button>

<!-- HEADER SUPERIOR -->
<div class="top-header-escola">
    <div class="header-left">
        <div class="page-title" id="pageTitle"><?php echo $titulo_pagina ?? 'Dashboard Escola'; ?></div>
        <div class="date-time" id="dateTime">
            <i class="fas fa-calendar-alt"></i>
            <span id="currentDate"></span>
            <i class="fas fa-clock ms-2"></i>
            <span id="currentTime"></span>
            <span class="realtime-badge">🇦🇴 AO</span>
        </div>
    </div>
    <div class="header-right">
        <div class="notifications-container" style="position: relative;">
            <button class="notifications-btn" id="notificationsBtn">
                <i class="fas fa-bell"></i>
                <?php if ($total_notificacoes > 0): ?>
                <span class="notification-badge"><?php echo $total_notificacoes > 99 ? '99+' : $total_notificacoes; ?></span>
                <?php endif; ?>
            </button>
            
            <div class="notifications-dropdown" id="notificationsDropdown">
                <div class="notifications-header">
                    <h6><i class="fas fa-bell"></i> Notificações</h6>
                    <?php if ($total_notificacoes > 0): ?>
                    <button class="marcar-todas" id="marcarTodasBtn">Marcar todas como lidas</button>
                    <?php endif; ?>
                </div>
                <div class="notifications-list" id="notificationsList">
                    <div class="sem-notificacoes py-5 text-center">
                        <i class="fas fa-bell-slash fa-2x text-muted mb-2"></i>
                        <p class="text-muted">Nenhuma notificação</p>
                        <small class="text-muted">Você não tem notificações no momento.</small>
                    </div>
                </div>
                <div class="notifications-footer">
                    <a href="notificacoes.php" class="text-decoration-none">Ver todas as notificações</a>
                </div>
            </div>
        </div>
        
        <div class="user-dropdown" id="userDropdown">
            <div class="user-info-header">
                <div class="user-avatar">
                    <i class="fas fa-building"></i>
                </div>
                <div class="user-info-text">
                    <div class="user-name"><?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></div>
                    <div class="user-role"><?php echo ucfirst($_SESSION['user_role'] ?? 'Administrador'); ?></div>
                </div>
                <i class="fas fa-chevron-down" style="color:#999; font-size:0.75em;"></i>
            </div>
            <div class="dropdown-menu-custom" id="userDropdownMenu">
                <a href="<?php echo getLink('perfil', $rotas); ?>" class="dropdown-item-custom"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                <a href="<?php echo getLink('config_geral', $rotas); ?>" class="dropdown-item-custom"><i class="fas fa-cog"></i> Configurações</a>
                <div class="dropdown-divider"></div>
                <div class="last-access">
                    <i class="fas fa-history"></i> Último acesso: <?php echo date('d/m/Y H:i:s', strtotime($ultimo_acesso)); ?>
                </div>
                <div class="dropdown-divider"></div>
                <a href="../../logout.php" class="dropdown-item-custom text-danger"><i class="fas fa-sign-out-alt"></i> Sair</a>
            </div>
        </div>
    </div>
</div>

<!-- Sidebar -->
<div class="sidebar-escola" id="sidebar">
    <div class="sidebar-header">
        <div class="logo"><i class="fas fa-building"></i></div>
        <h3>SIGE Angola</h3>
        <p>Área da Escola</p>
        
        <div class="user-info-sidebar">
            <div><i class="fas fa-user"></i> <?php echo $_SESSION['usuario_nome'] ?? 'Usuário'; ?></div>
            <div><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'SIGE Angola'); ?></div>
            <div><i class="fas fa-chart-line"></i> Dashboard Geral</div>
        </div>
    </div>
    
    <ul class="nav-menu">
        <!-- DASHBOARD -->
        <li class="nav-item">
            <a href="<?php echo getLink('dashboard', $rotas); ?>" class="nav-link <?php echo $current_file == 'index.php' ? 'active' : ''; ?>">
                <i class="fas fa-tachometer-alt"></i> Dashboard
            </a>
        </li>
        
        <!-- ACADÉMICO -->
        <li class="nav-item has-submenu" id="menuAcademico">
            <a href="#" class="nav-link" id="academicoLink"><i class="fas fa-graduation-cap"></i> Académico</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('alunos', $rotas); ?>" class="nav-link"><i class="fas fa-users"></i> Alunos</a></li>
                <li><a href="<?php echo getLink('professores', $rotas); ?>" class="nav-link"><i class="fas fa-chalkboard-user"></i> Professores</a></li>
                <li><a href="<?php echo getLink('turmas', $rotas); ?>" class="nav-link"><i class="fas fa-users-group"></i> Turmas</a></li>
                <li><a href="<?php echo getLink('disciplinas', $rotas); ?>" class="nav-link"><i class="fas fa-book"></i> Disciplinas</a></li>
                <li><a href="<?php echo getLink('notas', $rotas); ?>" class="nav-link"><i class="fas fa-edit"></i> Notas</a></li>
                <li><a href="<?php echo getLink('chamada', $rotas); ?>" class="nav-link"><i class="fas fa-calendar-check"></i> Chamada</a></li>
            </ul>
        </li>
        
        <!-- SISTEMA DE AVALIAÇÃO -->
        <li class="nav-item has-submenu" id="menuSistemaAvaliacao">
            <a href="#" class="nav-link" id="sistemaAvaliacaoLink"><i class="fas fa-chart-line"></i> Sistema de Avaliação</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('provas', $rotas); ?>" class="nav-link"><i class="fas fa-file-alt"></i> Provas</a></li>
                <li><a href="<?php echo getLink('pautas', $rotas); ?>" class="nav-link"><i class="fas fa-list-alt"></i> Pautas</a></li>
                <li><a href="<?php echo getLink('tipos_prova', $rotas); ?>" class="nav-link"><i class="fas fa-tags"></i> Tipos de Prova</a></li>
                <li><a href="<?php echo getLink('avaliacao_curso', $rotas); ?>" class="nav-link"><i class="fas fa-graduation-cap"></i> Avaliação por Curso</a></li>
                <li><a href="<?php echo getLink('sistema_avaliacao', $rotas); ?>" class="nav-link"><i class="fas fa-cog"></i> Sistema de Avaliações</a></li>
                <li><a href="<?php echo getLink('aproveitamento_aluno', $rotas); ?>" class="nav-link"><i class="fas fa-chart-simple"></i> Aproveitamento do Aluno</a></li>
            </ul>
        </li>
        
        <!-- RELATÓRIOS PEDAGÓGICOS -->
        <li class="nav-item has-submenu" id="menuRelatoriosPedagogicos">
            <a href="#" class="nav-link" id="relatoriosPedagogicosLink"><i class="fas fa-file-alt"></i> Relatórios Pedagógicos</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('lista_nominal', $rotas); ?>" class="nav-link"><i class="fas fa-list"></i> Lista Nominal de Alunos</a></li>
                <li><a href="<?php echo getLink('estatistico_alunos', $rotas); ?>" class="nav-link"><i class="fas fa-chart-bar"></i> Relatório Estatístico de Alunos</a></li>
                <li><a href="<?php echo getLink('relatorio_professor', $rotas); ?>" class="nav-link"><i class="fas fa-chalkboard-user"></i> Relatório Professor</a></li>
                <li><a href="<?php echo getLink('inscricoes', $rotas); ?>" class="nav-link"><i class="fas fa-file-signature"></i> Inscrições de Estudantes</a></li>
                <li><a href="<?php echo getLink('estatistica_professor', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Estatística Professor</a></li>
                <li><a href="<?php echo getLink('manipautas', $rotas); ?>" class="nav-link"><i class="fas fa-table"></i> Manipautas</a></li>
                <li><a href="<?php echo getLink('boletim_nota', $rotas); ?>" class="nav-link"><i class="fas fa-file-pdf"></i> Boletim de Nota</a></li>
                <li><a href="<?php echo getLink('historico_notas', $rotas); ?>" class="nav-link"><i class="fas fa-history"></i> Histórico de Notas</a></li>
                <li><a href="<?php echo getLink('historico_faltas', $rotas); ?>" class="nav-link"><i class="fas fa-calendar-times"></i> Histórico de Faltas</a></li>
                <li><a href="<?php echo getLink('cadernetas', $rotas); ?>" class="nav-link"><i class="fas fa-book"></i> Cadernetas</a></li>
            </ul>
        </li>
        
        <!-- TESOURARIA -->
        <li class="nav-item has-submenu" id="menuTesouraria">
            <a href="#" class="nav-link" id="tesourariaLink"><i class="fas fa-coins"></i> Tesouraria</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('tesouraria_dashboard', $rotas); ?>" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
                <li><a href="<?php echo getLink('tesouraria_pagamentos', $rotas); ?>" class="nav-link"><i class="fas fa-credit-card"></i> Pagamentos</a></li>
                <li><a href="<?php echo getLink('tesouraria_mensalidades', $rotas); ?>" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
                <li><a href="<?php echo getLink('tesouraria_dividas', $rotas); ?>" class="nav-link"><i class="fas fa-exclamation-triangle"></i> Dívidas</a></li>
                <li><a href="<?php echo getLink('tesouraria_caixa', $rotas); ?>" class="nav-link"><i class="fas fa-cash-register"></i> Caixa Diário</a></li>
                <li><a href="<?php echo getLink('tesouraria_receitas', $rotas); ?>" class="nav-link"><i class="fas fa-arrow-up"></i> Receitas</a></li>
                <li><a href="<?php echo getLink('tesouraria_despesas', $rotas); ?>" class="nav-link"><i class="fas fa-arrow-down"></i> Despesas</a></li>
                <li><a href="<?php echo getLink('tesouraria_fluxo_caixa', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                <li><a href="<?php echo getLink('tesouraria_balancete', $rotas); ?>" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                <li><a href="<?php echo getLink('tesouraria_extrato', $rotas); ?>" class="nav-link"><i class="fas fa-file-invoice"></i> Extrato</a></li>
                <li><a href="<?php echo getLink('tesouraria_recibos', $rotas); ?>" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
                <li><a href="<?php echo getLink('tesouraria_relatorios_financeiros', $rotas); ?>" class="nav-link"><i class="fas fa-chart-pie"></i> Relatórios Financeiros</a></li>
                <li><a href="<?php echo getLink('tesouraria_config', $rotas); ?>" class="nav-link"><i class="fas fa-cog"></i> Configurações</a></li>
            </ul>
        </li>
        
        <!-- FINANCEIRO -->
        <li class="nav-item has-submenu" id="menuFinanceiro">
            <a href="#" class="nav-link" id="financeiroLink"><i class="fas fa-coins"></i> Financeiro</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('financeiro_dashboard', $rotas); ?>" class="nav-link"><i class="fas fa-tachometer-alt"></i> Dashboard Financeiro</a></li>
                <li><a href="<?php echo getLink('contas_receber', $rotas); ?>" class="nav-link"><i class="fas fa-arrow-up text-success"></i> Contas a Receber</a></li>
                <li><a href="<?php echo getLink('contas_pagar', $rotas); ?>" class="nav-link"><i class="fas fa-arrow-down text-danger"></i> Contas a Pagar</a></li>
                <li><a href="<?php echo getLink('fluxo_caixa', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Fluxo de Caixa</a></li>
                <li><a href="<?php echo getLink('balancete', $rotas); ?>" class="nav-link"><i class="fas fa-balance-scale"></i> Balancete</a></li>
                <li><a href="<?php echo getLink('orcamento', $rotas); ?>" class="nav-link"><i class="fas fa-chart-pie"></i> Orçamento</a></li>
                <li><a href="<?php echo getLink('extratos', $rotas); ?>" class="nav-link"><i class="fas fa-file-invoice"></i> Extratos</a></li>
                <li><a href="<?php echo getLink('recibos', $rotas); ?>" class="nav-link"><i class="fas fa-receipt"></i> Recibos</a></li>
                <li><a href="<?php echo getLink('taxas', $rotas); ?>" class="nav-link"><i class="fas fa-percent"></i> Taxas e Multas</a></li>
                <li><a href="<?php echo getLink('parcelamentos', $rotas); ?>" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Parcelamentos</a></li>
                <li><a href="<?php echo getLink('folha_pagamento', $rotas); ?>" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Folha de Pagamento</a></li>
                <li><a href="<?php echo getLink('financeiro_mensalidades', $rotas); ?>" class="nav-link"><i class="fas fa-calendar-dollar"></i> Mensalidades</a></li>
                <li><a href="<?php echo getLink('financeiro_config', $rotas); ?>" class="nav-link"><i class="fas fa-cog"></i> Configurações Financeiras</a></li>
            </ul>
        </li>
        
        <!-- RECURSOS HUMANOS (RH) -->
        <li class="nav-item has-submenu" id="menuRH">
            <a href="#" class="nav-link" id="rhLink"><i class="fas fa-users"></i> Recursos Humanos</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('rh_dashboard', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Dashboard RH</a></li>
                <li><a href="<?php echo getLink('rh_funcionarios_listar', $rotas); ?>" class="nav-link"><i class="fas fa-user-tie"></i> Funcionários</a></li>
                <li><a href="<?php echo getLink('rh_vagas', $rotas); ?>" class="nav-link"><i class="fas fa-file-signature"></i> Vagas</a></li>
                <li><a href="<?php echo getLink('rh_candidatos', $rotas); ?>" class="nav-link"><i class="fas fa-users-viewfinder"></i> Candidatos</a></li>
                <li><a href="<?php echo getLink('rh_avaliacao_periodos', $rotas); ?>" class="nav-link"><i class="fas fa-star"></i> Avaliação</a></li>
                <li><a href="<?php echo getLink('rh_formacao', $rotas); ?>" class="nav-link"><i class="fas fa-graduation-cap"></i> Formação</a></li>
                <li><a href="<?php echo getLink('rh_documentacao', $rotas); ?>" class="nav-link"><i class="fas fa-folder-open"></i> Documentação</a></li>
                <li><a href="<?php echo getLink('rh_relatorios', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Relatórios RH</a></li>
                <li><a href="<?php echo getLink('rh_configurar', $rotas); ?>" class="nav-link"><i class="fas fa-cog"></i> Configurações RH</a></li>
            </ul>
        </li>
        
        <!-- SECRETARIA -->
        <li class="nav-item has-submenu" id="menuSecretaria">
            <a href="#" class="nav-link" id="secretariaLink"><i class="fas fa-building"></i> Secretaria</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('secretaria_lista_alunos', $rotas); ?>" class="nav-link"><i class="fas fa-list"></i> Lista de Alunos</a></li>
                <li><a href="<?php echo getLink('secretaria_alunos_matriculados', $rotas); ?>" class="nav-link"><i class="fas fa-check-circle"></i> Alunos Matriculados</a></li>
                <li><a href="<?php echo getLink('secretaria_inscricoes', $rotas); ?>" class="nav-link"><i class="fas fa-file-signature"></i> Inscrições</a></li>
                <li><a href="<?php echo getLink('secretaria_rematricula', $rotas); ?>" class="nav-link"><i class="fas fa-sync-alt"></i> Rematrícula</a></li>
                <li><a href="<?php echo getLink('secretaria_matricula', $rotas); ?>" class="nav-link"><i class="fas fa-user-plus"></i> Matrícula</a></li>
                <li><a href="<?php echo getLink('secretaria_documentos', $rotas); ?>" class="nav-link"><i class="fas fa-file-pdf"></i> Documentos</a></li>
                <li><a href="<?php echo getLink('secretaria_certificados', $rotas); ?>" class="nav-link"><i class="fas fa-certificate"></i> Certificados</a></li>
                <li><a href="<?php echo getLink('secretaria_declaracao', $rotas); ?>" class="nav-link"><i class="fas fa-file-signature"></i> Declaração</a></li>
                <li><a href="<?php echo getLink('secretaria_boletim', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Boletim</a></li>
                <li><a href="<?php echo getLink('secretaria_estatisticas_turma', $rotas); ?>" class="nav-link"><i class="fas fa-chart-bar"></i> Estatísticas por Turma</a></li>
                <li><a href="<?php echo getLink('secretaria_estatisticas_disciplina', $rotas); ?>" class="nav-link"><i class="fas fa-chart-pie"></i> Estatísticas por Disciplina</a></li>
                <li><a href="<?php echo getLink('secretaria_estatisticas_geral', $rotas); ?>" class="nav-link"><i class="fas fa-chart-line"></i> Estatísticas Geral</a></li>
            </ul>
        </li>
        
        <!-- BIBLIOTECA -->
        <li class="nav-item has-submenu" id="menuBiblioteca">
            <a href="#" class="nav-link" id="bibliotecaLink"><i class="fas fa-book-open"></i> Biblioteca</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('biblioteca_acervo', $rotas); ?>" class="nav-link"><i class="fas fa-search"></i> Visualizar Acervo</a></li>
                <li><a href="<?php echo getLink('biblioteca_cadastrar', $rotas); ?>" class="nav-link"><i class="fas fa-plus-circle"></i> Cadastrar Livro</a></li>
                <li><a href="<?php echo getLink('biblioteca_emprestimos', $rotas); ?>" class="nav-link"><i class="fas fa-hand-holding"></i> Empréstimos</a></li>
                <li><a href="<?php echo getLink('biblioteca_reservas', $rotas); ?>" class="nav-link"><i class="fas fa-calendar-check"></i> Reservas</a></li>
            </ul>
        </li>
        
        <!-- CONFIGURAÇÕES -->
        <li class="nav-item has-submenu" id="menuConfiguracoes">
            <a href="#" class="nav-link" id="configuracoesLink"><i class="fas fa-cogs"></i> Configurações</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('config_geral', $rotas); ?>" class="nav-link"><i class="fas fa-globe"></i> Geral</a></li>
                <li><a href="<?php echo getLink('config_sistema', $rotas); ?>" class="nav-link"><i class="fas fa-chalkboard"></i> Sistema</a></li>
                <li><a href="<?php echo getLink('config_email', $rotas); ?>" class="nav-link"><i class="fas fa-envelope"></i> Email</a></li>
                <li><a href="<?php echo getLink('config_backup', $rotas); ?>" class="nav-link"><i class="fas fa-database"></i> Backup</a></li>
                <li><a href="<?php echo getLink('config_permissoes', $rotas); ?>" class="nav-link"><i class="fas fa-lock"></i> Permissões</a></li>
            </ul>
        </li>
        
        <!-- SUPORTE -->
        <li class="nav-item has-submenu" id="menuSuporte">
            <a href="#" class="nav-link" id="suporteLink"><i class="fas fa-headset"></i> Suporte</a>
            <ul class="nav-submenu">
                <li><a href="<?php echo getLink('suporte_chamados', $rotas); ?>" class="nav-link"><i class="fas fa-ticket-alt"></i> Chamados</a></li>
                <li><a href="<?php echo getLink('suporte_faq', $rotas); ?>" class="nav-link"><i class="fas fa-question-circle"></i> FAQ</a></li>
                <li><a href="<?php echo getLink('suporte_manuais', $rotas); ?>" class="nav-link"><i class="fas fa-book"></i> Manuais</a></li>
                <li><a href="<?php echo getLink('suporte_tutoriais', $rotas); ?>" class="nav-link"><i class="fas fa-video"></i> Tutoriais</a></li>
            </ul>
        </li>
        
        <li class="nav-item"><hr style="margin: 15px 25px; border-color: rgba(255,255,255,0.08);"></li>
        
        <!-- PERFIL -->
        <li class="nav-item">
            <a href="<?php echo getLink('perfil', $rotas); ?>" class="nav-link <?php echo $current_file == 'perfil.php' ? 'active' : ''; ?>">
                <i class="fas fa-user-circle"></i> Meu Perfil
            </a>
        </li>
        
        <!-- SAIR -->
        <li class="nav-item">
            <a href="../../logout.php" class="nav-link text-danger"><i class="fas fa-sign-out-alt"></i> Sair</a>
        </li>
    </ul>
</div>

<!-- RODAPÉ -->
<div class="footer-escola">
    <div class="footer-left">
        <span><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola_info['nome'] ?? 'SIGE Angola'); ?></span>
        <?php if (!empty($escola_info['endereco'])): ?>
        <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($escola_info['endereco']); ?></span>
        <?php endif; ?>
        <?php if (!empty($escola_info['telefone'])): ?>
        <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($escola_info['telefone']); ?></span>
        <?php endif; ?>
        <?php if (!empty($escola_info['email'])): ?>
        <span><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($escola_info['email']); ?></span>
        <?php endif; ?>
    </div>
    <div class="footer-right">
        <span><i class="fas fa-code-branch"></i> Versão <?php echo $versao_sistema; ?></span>
        <span><i class="fas fa-building"></i> Área da Escola</span>
        <span><i class="fas fa-copyright"></i> <?php echo $ano_atual; ?> SIGE Angola</span>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Atualizar data e hora em tempo real
    function atualizarDataHora() {
        const agora = new Date();
        const dia = String(agora.getDate()).padStart(2, '0');
        const mes = String(agora.getMonth() + 1).padStart(2, '0');
        const ano = agora.getFullYear();
        const dataFormatada = `${dia}/${mes}/${ano}`;
        const horas = String(agora.getHours()).padStart(2, '0');
        const minutos = String(agora.getMinutes()).padStart(2, '0');
        const segundos = String(agora.getSeconds()).padStart(2, '0');
        const horaFormatada = `${horas}:${minutos}:${segundos}`;
        
        const dateElement = document.getElementById('currentDate');
        const timeElement = document.getElementById('currentTime');
        if (dateElement) dateElement.textContent = dataFormatada;
        if (timeElement) timeElement.textContent = horaFormatada;
    }
    
    setInterval(atualizarDataHora, 1000);
    atualizarDataHora();
    
    // Menu Toggle
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    
    if (menuToggle) {
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
    }
    
    // Função para toggle dos submenus - CORRIGIDA
    function toggleSubmenu(element) {
        if (!element) return;
        
        const parentLi = element.closest('.has-submenu');
        if (parentLi) {
            parentLi.classList.toggle('open');
            
            // Prevenir que o clique propague para elementos pai
            event.preventDefault();
            event.stopPropagation();
        }
    }
    
    // Adicionar event listeners para todos os links com submenu
    document.querySelectorAll('.has-submenu > a').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const parentLi = this.closest('.has-submenu');
            if (parentLi) {
                parentLi.classList.toggle('open');
            }
        });
    });
    
    // Toggle User Dropdown
    const userDropdown = document.getElementById('userDropdown');
    const userDropdownMenu = document.getElementById('userDropdownMenu');
    if (userDropdown) {
        userDropdown.addEventListener('click', function(e) {
            e.stopPropagation();
            userDropdownMenu.classList.toggle('show');
        });
    }
    
    // Toggle Notificações
    const notificationsBtn = document.getElementById('notificationsBtn');
    const notificationsDropdown = document.getElementById('notificationsDropdown');
    
    if (notificationsBtn) {
        notificationsBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            notificationsDropdown.classList.toggle('show');
        });
    }
    
    // Marcar todas como lidas
    const marcarTodasBtn = document.getElementById('marcarTodasBtn');
    if (marcarTodasBtn) {
        marcarTodasBtn.addEventListener('click', function() {
            document.querySelectorAll('.notification-item').forEach(item => {
                item.classList.remove('nao-lida');
            });
            const badge = document.querySelector('.notification-badge');
            if (badge) badge.style.display = 'none';
        });
    }
    
    // Fechar dropdowns ao clicar fora
    document.addEventListener('click', function(event) {
        if (userDropdownMenu && userDropdown && !userDropdown.contains(event.target)) {
            userDropdownMenu.classList.remove('show');
        }
        if (notificationsDropdown && notificationsBtn && !notificationsBtn.contains(event.target) && !notificationsDropdown.contains(event.target)) {
            notificationsDropdown.classList.remove('show');
        }
    });
    
    // Manter submenus abertos baseado na URL atual
    document.addEventListener('DOMContentLoaded', function() {
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.has-submenu').forEach(menu => {
            const links = menu.querySelectorAll('.nav-submenu a');
            if (Array.from(links).some(link => currentUrl.includes(link.getAttribute('href')))) {
                menu.classList.add('open');
            }
        });
    });
</script>
</body>
</html>