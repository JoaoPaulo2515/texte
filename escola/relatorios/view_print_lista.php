<?php
// escola/relatorios/view_print_lista.php - Visualização para impressão


require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];

// Verificar se o usuário tem permissão de administrador
$tipos_permitidos = ['super_admin', 'admin_escola', 'administrador', 'diretor'];
if (!in_array($_SESSION['usuario_tipo'], $tipos_permitidos)) {
    die("Acesso negado. Apenas administradores podem acessar esta página.");
}

require_once 'funcoes_lista.php';

$escola_id = $_SESSION['escola_id'];
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$tipo_lista = isset($_GET['tipo_lista']) ? $_GET['tipo_lista'] : 'completa';

if ($turma_id == 0) {
    die('Turma não selecionada');
}

// Buscar dados
$dados = buscarDadosLista($conn, $escola_id, $turma_id);
$escola_info = buscarDadosEscola($conn, $escola_id);

if (empty($dados['alunos'])) {
    die('Nenhum aluno encontrado nesta turma');
}

// Gerar HTML para impressão
$html = gerarHTMLLista($dados['alunos'], $dados['turma_info'], $escola_info, $dados['estatisticas'], $tipo_lista);

// Adicionar botão de impressão
$html = str_replace('</body>', '
<button onclick="window.print();" style="position: fixed; bottom: 20px; right: 20px; background: #006B3E; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; z-index: 1000;">
    🖨️ Imprimir / Salvar como PDF
</button>
<script>
    // Opcional: imprimir automaticamente após carregar
    // window.onload = function() { setTimeout(function() { window.print(); }, 500); }
</script>
</body>', $html);

echo $html;
exit;
?>