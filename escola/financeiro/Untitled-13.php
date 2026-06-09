<?php
// admin/aprovar_solicitacao.php - Script para administrador aprovar solicitações

require_once '../includes/auth.php';
checkAdminAuth();

$conn = getConnection();

// Buscar solicitações pendentes
$sql = "SELECT s.*, f.nome as funcionario_nome, f.cargo, f.salario_base,
        e.nome as escola_nome
        FROM solicitacoes_vale s
        INNER JOIN funcionarios f ON f.id = s.funcionario_id
        INNER JOIN escolas e ON e.id = s.escola_id
        WHERE s.status = 'pendente'
        ORDER BY s.created_at ASC";
$solicitacoes = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $solicitacao_id = $_POST['solicitacao_id'];
    $acao = $_POST['acao'];
    
    if ($acao === 'aprovar') {
        $valor_aprovado = $_POST['valor_aprovado'] ?? null;
        $result = aprovarSolicitacaoVale($solicitacao_id, $valor_aprovado);
        
        if ($result['success']) {
            $msg = "Solicitação aprovada! Dívida #{$result['divida_id']} criada.";
        } else {
            $error = $result['error'];
        }
    } elseif ($acao === 'reprovar') {
        $motivo = $_POST['motivo_reprovacao'];
        $result = reprovarSolicitacaoVale($solicitacao_id, $motivo);
        
        if ($result['success']) {
            $msg = "Solicitação reprovada.";
        } else {
            $error = $result['error'];
        }
    }
    
    header("Location: aprovar_solicitacao.php?msg=" . urlencode($msg ?? ''));
    exit;
}