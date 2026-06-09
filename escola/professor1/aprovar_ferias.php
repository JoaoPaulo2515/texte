<?php
// admin/aprovar_ferias.php - Aprovar solicitações de férias

require_once '../includes/auth.php';
checkAdminAuth();

$conn = getConnection();

// Processar aprovação
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $solicitacao_id = (int)$_POST['solicitacao_id'];
    $acao = $_POST['acao'];
    
    try {
        $conn->beginTransaction();
        
        if ($acao === 'aprovar') {
            $observacao = $_POST['observacao'] ?? '';
            
            // Buscar dados da solicitação
            $sql = "SELECT * FROM solicitacoes_ferias WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $solicitacao_id]);
            $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$solicitacao) {
                throw new Exception("Solicitação não encontrada");
            }
            
            // Atualizar solicitação
            $sql_update = "UPDATE solicitacoes_ferias 
                           SET status = 'aprovado', 
                               aprovado_por = :aprovado_por, 
                               data_aprovacao = CURDATE(),
                               observacao_admin = :observacao
                           WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':aprovado_por' => $_SESSION['usuario_id'],
                ':observacao' => $observacao,
                ':id' => $solicitacao_id
            ]);
            
            // Atualizar saldo de férias do funcionário
            $sql_update_saldo = "UPDATE ferias_funcionario 
                                 SET dias_utilizados = dias_utilizados + :dias,
                                     dias_disponiveis = dias_totais - (dias_utilizados + :dias),
                                     dias_pendentes = dias_pendentes - :dias,
                                     ultima_atualizacao = CURDATE()
                                 WHERE funcionario_id = :funcionario_id 
                                 AND ano_referencia = :ano";
            $stmt_saldo = $conn->prepare($sql_update_saldo);
            $stmt_saldo->execute([
                ':dias' => $solicitacao['dias_solicitados'],
                ':funcionario_id' => $solicitacao['funcionario_id'],
                ':ano' => $solicitacao['periodo_referencia']
            ]);
            
            // Registrar histórico
            $sql_hist = "INSERT INTO ferias_historico (solicitacao_id, acao, observacao) 
                         VALUES (:id, 'aprovado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':id' => $solicitacao_id,
                ':obs' => "Férias aprovadas para o período de {$solicitacao['data_inicio']} a {$solicitacao['data_fim']}"
            ]);
            
            $conn->commit();
            $msg = "✅ Solicitação de férias aprovada com sucesso!";
            
        } elseif ($acao === 'reprovar') {
            $motivo = $_POST['motivo_reprovacao'];
            
            $sql_update = "UPDATE solicitacoes_ferias SET status = 'reprovado', observacao_admin = :motivo WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':motivo' => $motivo, ':id' => $solicitacao_id]);
            
            // Atualizar dias pendentes
            $sql_saldo = "UPDATE ferias_funcionario 
                          SET dias_pendentes = dias_pendentes - :dias
                          WHERE funcionario_id = (SELECT funcionario_id FROM solicitacoes_ferias WHERE id = :id)";
            $stmt_saldo = $conn->prepare($sql_saldo);
            $stmt_saldo->execute([':dias' => $solicitacao['dias_solicitados'], ':id' => $solicitacao_id]);
            
            $sql_hist = "INSERT INTO ferias_historico (solicitacao_id, acao, observacao) VALUES (:id, 'reprovado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([':id' => $solicitacao_id, ':obs' => "Motivo: " . $motivo]);
            
            $conn->commit();
            $msg = "❌ Solicitação de férias reprovada.";
        }
        
        header("Location: aprovar_ferias.php?msg=" . urlencode($msg));
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Buscar solicitações pendentes
$sql = "SELECT s.*, f.nome as funcionario_nome, f.cargo, e.nome as escola_nome
        FROM solicitacoes_ferias s
        INNER JOIN funcionarios f ON f.id = s.funcionario_id
        INNER JOIN escolas e ON e.id = s.escola_id
        WHERE s.status = 'pendente'
        ORDER BY s.created_at ASC";
$solicitacoes = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Aprovar Férias - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2><i class="fas fa-umbrella-beach"></i> Aprovar Solicitações de Férias</h2>
        
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_GET['msg']); ?></div>
        <?php endif; ?>
        
        <?php if (empty($solicitacoes)): ?>
            <div class="alert alert-info">Nenhuma solicitação pendente.</div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($solicitacoes as $s): ?>
                <div class="col-md-6 mb-3">
                    <div class="card">
                        <div class="card-header bg-warning">
                            <strong>Solicitação #<?php echo $s['id']; ?></strong>
                        </div>
                        <div class="card-body">
                            <p><strong>Funcionário:</strong> <?php echo htmlspecialchars($s['funcionario_nome']); ?></p>
                            <p><strong>Cargo:</strong> <?php echo htmlspecialchars($s['cargo']); ?></p>
                            <p><strong>Escola:</strong> <?php echo htmlspecialchars($s['escola_nome']); ?></p>
                            <p><strong>Período:</strong> <?php echo date('d/m/Y', strtotime($s['data_inicio'])); ?> a <?php echo date('d/m/Y', strtotime($s['data_fim'])); ?></p>
                            <p><strong>Dias úteis:</strong> <?php echo $s['dias_solicitados']; ?> dias</p>
                            <p><strong>Motivo:</strong> <?php echo htmlspecialchars($s['motivo']); ?></p>
                            <p><strong>Descrição:</strong> <?php echo nl2br(htmlspecialchars($s['descricao'])); ?></p>
                            
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="solicitacao_id" value="<?php echo $s['id']; ?>">
                                
                                <div class="mb-2">
                                    <label>Observação (opcional):</label>
                                    <textarea name="observacao" class="form-control" rows="2" placeholder="Adicione uma observação..."></textarea>
                                </div>
                                
                                <div class="btn-group w-100">
                                    <button type="submit" name="acao" value="aprovar" class="btn btn-success">Aprovar</button>
                                    <button type="button" class="btn btn-danger" onclick="reprovar(<?php echo $s['id']; ?>)">Reprovar</button>
                                </div>
                                
                                <div id="motivo_<?php echo $s['id']; ?>" style="display: none; margin-top: 10px;">
                                    <textarea name="motivo_reprovacao" class="form-control" placeholder="Motivo da reprovação..." required></textarea>
                                    <button type="submit" name="acao" value="reprovar" class="btn btn-danger btn-sm mt-2">Confirmar Reprovação</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function reprovar(id) {
            document.getElementById('motivo_' + id).style.display = 'block';
        }
    </script>
</body>
</html>