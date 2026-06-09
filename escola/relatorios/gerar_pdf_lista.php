<?php
// escola/relatorios/gerar_pdf_lista.php - Gerar PDF da lista nominal
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

// Gerar HTML para PDF
$html = gerarHTMLLista($dados['alunos'], $dados['turma_info'], $escola_info, $dados['estatisticas'], $tipo_lista);

// Carregar Dompdf
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);

$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream("lista_nominal_" . $dados['turma_info']['nome'] . "_" . date('Y-m-d') . ".pdf", ["Attachment" => true]);
exit;
?>