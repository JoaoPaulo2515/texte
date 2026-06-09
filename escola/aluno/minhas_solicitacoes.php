<?php
// escola/aluno/financeiro/minhas_solicitacoes.php - Minhas Solicitações de Pagamento

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

$sql = "SELECT sp.*, fp.numero_fatura, fp.total as valor_fatura
        FROM solicitacoes_pagamento sp
        JOIN faturas_proforma fp ON fp.id = sp.fatura_id
        WHERE sp.aluno_id = :aluno_id AND sp.escola_id = :escola_id
        ORDER BY sp.created_at DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$solicitacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

include '../includes/menu_aluno.php';
?>

<div class="main-content-aluno">
    <h4><i class="fas fa-list-alt"></i> Minhas Solicitações de Pagamento</h4>
    
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <?php if (empty($solicitacoes)): ?>
                <div class="alert alert-info">Nenhuma solicitação de pagamento encontrada.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr><th>Código</th><th>Fatura</th><th>Data</th><th>Valor</th><th>Método</th><th>Status</th><th>Ação</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitacoes as $sol): ?>
                            <tr>
                                <td><?php echo $sol['codigo_solicitacao']; ?></td>
                                <td><?php echo $sol['numero_fatura']; ?></td>
                                <td><?php echo date('d/m/Y', strtotime($sol['data_solicitacao'])); ?></td>
                                <td><?php echo number_format($sol['valor_total'], 2, ',', '.'); ?> KZ</td>
                                <td><?php echo ucfirst($sol['metodo_pagamento'] ?: 'Não informado'); ?></td>
                                <td><?php echo getStatusBadge($sol['status']); ?></td>
                                <td><a href="ver_fatura_proforma.php?id=<?php echo $sol['fatura_id']; ?>" class="btn btn-sm btn-info">Ver</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
function getStatusBadge($status) {
    switch ($status) {
        case 'pendente': return '<span class="badge bg-warning text-dark">Pendente</span>';
        case 'aprovada': return '<span class="badge bg-info">Aprovada</span>';
        case 'concluida': return '<span class="badge bg-success">Concluída</span>';
        case 'cancelada': return '<span class="badge bg-danger">Cancelada</span>';
        default: return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>
</body>
</html>