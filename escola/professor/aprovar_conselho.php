<?php
// admin/aprovar_conselho.php - Aprovar solicitações do Conselho de Nota

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
            $parecer = $_POST['parecer'] ?? '';
            
            // Atualizar solicitação
            $sql_update = "UPDATE conselho_nota_solicitacoes 
                           SET status = 'aprovado', 
                               aprovado_por = :aprovado_por, 
                               data_aprovacao = CURDATE(),
                               parecer_coordenacao = :parecer
                           WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':aprovado_por' => $_SESSION['usuario_id'],
                ':parecer' => $parecer,
                ':id' => $solicitacao_id
            ]);
            
            // Atualizar a nota do aluno
            $sql_nota = "SELECT nota_sugerida, aluno_id, disciplina_id, bimestre FROM conselho_nota_solicitacoes WHERE id = :id";
            $stmt_nota = $conn->prepare($sql_nota);
            $stmt_nota->execute([':id' => $solicitacao_id]);
            $solicitacao = $stmt_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($solicitacao) {
                $sql_update_nota = "UPDATE notas 
                                    SET nota = :nota, 
                                        atualizado_por = 'conselho',
                                        data_atualizacao = NOW()
                                    WHERE aluno_id = :aluno_id 
                                    AND disciplina_id = :disciplina_id 
                                    AND bimestre = :bimestre";
                $stmt_update_nota = $conn->prepare($sql_update_nota);
                $stmt_update_nota->execute([
                    ':nota' => $solicitacao['nota_sugerida'],
                    ':aluno_id' => $solicitacao['aluno_id'],
                    ':disciplina_id' => $solicitacao['disciplina_id'],
                    ':bimestre' => $solicitacao['bimestre']
                ]);
            }
            
            // Registrar histórico
            $sql_hist = "INSERT INTO conselho_nota_historicos (solicitacao_id, acao, observacao) 
                         VALUES (:id, 'aprovado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([
                ':id' => $solicitacao_id,
                ':obs' => "Solicitação aprovada pelo Conselho. Parecer: " . $parecer
            ]);
            
            $conn->commit();
            $msg = "✅ Solicitação aprovada e nota atualizada com sucesso!";
            
        } elseif ($acao === 'reprovar') {
            $motivo = $_POST['motivo_reprovacao'];
            
            $sql_update = "UPDATE conselho_nota_solicitacoes SET status = 'reprovado', parecer_coordenacao = :motivo WHERE id = :id";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([':motivo' => $motivo, ':id' => $solicitacao_id]);
            
            $sql_hist = "INSERT INTO conselho_nota_historicos (solicitacao_id, acao, observacao) VALUES (:id, 'reprovado', :obs)";
            $stmt_hist = $conn->prepare($sql_hist);
            $stmt_hist->execute([':id' => $solicitacao_id, ':obs' => "Motivo: " . $motivo]);
            
            $conn->commit();
            $msg = "❌ Solicitação reprovada pelo Conselho.";
        }
        
        header("Location: aprovar_conselho.php?msg=" . urlencode($msg));
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Buscar solicitações pendentes
$sql = "SELECT s.*, 
               p.nome as professor_nome,
               t.nome as turma_nome, t.serie,
               d.nome as disciplina_nome,
               a.nome as aluno_nome, a.matricula
        FROM conselho_nota_solicitacoes s
        INNER JOIN professores prof ON prof.id = s.professor_id
        INNER JOIN usuarios p ON p.id = prof.usuario_id
        INNER JOIN turmas t ON t.id = s.turma_id
        INNER JOIN disciplinas d ON d.id = s.disciplina_id
        INNER JOIN alunos a ON a.id = s.aluno_id
        WHERE s.status = 'pendente'
        ORDER BY s.created_at ASC";
$solicitacoes = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Aprovar Conselho de Nota - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-4">
        <h2><i class="fas fa-chalkboard-teacher"></i> Aprovar Solicitações do Conselho de Nota</h2>
        
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
                            <p><strong>Professor:</strong> <?php echo htmlspecialchars($s['professor_nome']); ?></p>
                            <p><strong>Aluno:</strong> <?php echo htmlspecialchars($s['aluno_nome']); ?> (<?php echo $s['matricula']; ?>)</p>
                            <p><strong>Turma:</strong> <?php echo htmlspecialchars($s['turma_nome']); ?> - <?php echo $s['serie']; ?></p>
                            <p><strong>Disciplina:</strong> <?php echo htmlspecialchars($s['disciplina_nome']); ?></p>
                            <p><strong>Bimestre:</strong> <?php echo $s['bimestre']; ?>º Bimestre</p>
                            <p><strong>Nota Atual:</strong> <span class="text-danger"><?php echo number_format($s['nota_atual'], 1); ?></span></p>
                            <p><strong>Nota Sugerida:</strong> <span class="text-success"><?php echo number_format($s['nota_sugerida'], 1); ?></span></p>
                            <p><strong>Motivo:</strong> <?php echo htmlspecialchars($s['motivo']); ?></p>
                            <p><strong>Justificativa:</strong> <?php echo nl2br(htmlspecialchars($s['descricao'])); ?></p>
                            <?php if ($s['evidencias']): ?>
                            <p><strong>Evidências:</strong> <?php echo nl2br(htmlspecialchars($s['evidencias'])); ?></p>
                            <?php endif; ?>
                            <?php if ($s['documento_anexo']): ?>
                            <p><a href="../<?php echo $s['documento_anexo']; ?>" target="_blank" class="btn btn-sm btn-info"><i class="fas fa-download"></i> Ver Anexo</a></p>
                            <?php endif; ?>
                            
                            <form method="POST" class="mt-3">
                                <input type="hidden" name="solicitacao_id" value="<?php echo $s['id']; ?>">
                                
                                <div class="mb-2">
                                    <label>Parecer do Conselho:</label>
                                    <textarea name="parecer" class="form-control" rows="2" placeholder="Descreva o parecer do conselho..."></textarea>
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