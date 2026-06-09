<?php
// admin/aprovar_vale.php - Aprovar solicitações de vale

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
            $valor_aprovado = (float)$_POST['valor_aprovado'];
            
            // Buscar dados da solicitação
            $sql = "SELECT * FROM solicitacoes_vale WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':id' => $solicitacao_id]);
            $solicitacao = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$solicitacao) {
                throw new Exception("Solicitação não encontrada");
            }
            
            // Atualizar solicitação
            $sql_update = "UPDATE solicitacoes_vale SET status = 'aprovado', valor_aprovado = :valor WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':valor' => $valor_aprovado, ':id' => $solicitacao_id]);
            
            // Criar dívida
            $valor_parcela = $valor_aprovado / $solicitacao['parcelas'];
            $primeira_parcela = date('Y-m-d', strtotime('first day of next month'));
            
            $sql_divida = "INSERT INTO dividas_a_pagar (
                funcionario_id, escola_id, tipo, referencia_id,
                valor_total, valor_pago, valor_pendente,
                parcelas_total, parcelas_pagas, primeira_parcela, ultima_parcela, status
            ) VALUES (
                :funcionario_id, :escola_id, 'vale', :referencia_id,
                :valor_total, 0, :valor_total,
                :parcelas_total, 0, :primeira_parcela, :ultima_parcela, 'ativa'
            )";
            
            $stmt_divida = $conn->prepare($sql_divida);
            $stmt_divida->execute([
                ':funcionario_id' => $solicitacao['funcionario_id'],
                ':escola_id' => $solicitacao['escola_id'],
                ':referencia_id' => $solicitacao_id,
                ':valor_total' => $valor_aprovado,
                ':parcelas_total' => $solicitacao['parcelas'],
                ':primeira_parcela' => $primeira_parcela,
                ':ultima_parcela' => date('Y-m-d', strtotime("+" . $solicitacao['parcelas'] . " month", strtotime($primeira_parcela)))
            ]);
            
            $divida_id = $conn->lastInsertId();
            
            // Atualizar solicitação com ID da dívida
            $sql_update_divida = "UPDATE solicitacoes_vale SET divida_id = :divida_id WHERE id = :id";
            $stmt_update_divida = $conn->prepare($sql_update_divida);
            $stmt_update_divida->execute([':divida_id' => $divida_id, ':id' => $solicitacao_id]);
            
            // Criar parcelas
            for ($i = 1; $i <= $solicitacao['parcelas']; $i++) {
                $data_vencimento = date('Y-m-d', strtotime("+" . $i . " month", strtotime($primeira_parcela)));
                $sql_parcela = "INSERT INTO divida_parcelas (divida_id, numero_parcela, valor_parcela, data_vencimento, status) 
                                VALUES (:divida_id, :numero, :valor, :data, 'pendente')";
                $stmt_parcela = $conn->prepare($sql_parcela);
                $stmt_parcela->execute([
                    ':divida_id' => $divida_id,
                    ':numero' => $i,
                    ':valor' => $valor_parcela,
                    ':data' => $data_vencimento
                ]);
            }
            
            // Registrar histórico
            $sql_hist = "INSERT INTO vale_historico (solicitacao_id, divida_id, acao, observacao) 
                         VALUES (:id, :divida_id, 'aprovado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':id' => $solicitacao_id,
                ':divida_id' => $divida_id,
                ':obs' => "Vale aprovado no valor de KZ " . number_format($valor_aprovado, 2, ',', '.')
            ]);
            
            $conn->commit();
            $msg = "✅ Solicitação aprovada! Dívida #$divida_id gerada com sucesso.";
            
        } elseif ($acao === 'reprovar') {
            $motivo = $_POST['motivo_reprovacao'];
            
            $sql_update = "UPDATE solicitacoes_vale SET status = 'reprovado' WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':id' => $solicitacao_id]);
            
            $sql_hist = "INSERT INTO vale_historico (solicitacao_id, acao, observacao) VALUES (:id, 'reprovado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([':id' => $solicitacao_id, ':obs' => "Motivo: " . $motivo]);
            
            $conn->commit();
            $msg = "❌ Solicitação reprovada.";
        }
        
        header("Location: aprovar_vale.php?msg=" . urlencode($msg));
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Buscar solicitações pendentes
$sql = "SELECT s.*, f.nome as funcionario_nome, f.cargo, f.salario_base, e.nome as escola_nome
        FROM solicitacoes_vale s
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
    <title>Aprovar Vales - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2>Aprovar Solicitações de Vale</h2>
        
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
                            <p><strong>Valor Solicitado:</strong> KZ <?php echo number_format($s['valor_solicitado'], 2, ',', '.'); ?></p>
                            <p><strong>Parcelas:</strong> <?php echo $s['parcelas']; ?>x</p>
                            <p><strong>Motivo:</strong> <?php echo htmlspecialchars($s['motivo']); ?></p>
                            <p><strong>Descrição:</strong> <?php echo nl2br(htmlspecialchars($s['descricao'])); ?></p>
                            
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="solicitacao_id" value="<?php echo $s['id']; ?>">
                                
                                <div class="mb-2">
                                    <label>Valor Aprovado:</label>
                                    <input type="number" step="0.01" name="valor_aprovado" class="form-control" value="<?php echo $s['valor_solicitado']; ?>" required>
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