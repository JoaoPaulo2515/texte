<?php
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

$tarefa_id = (int)$_GET['id'];
$aluno_id = $_SESSION['aluno_id'];

$db = Database::getInstance();
$conn = $db->getConnection();

// Buscar detalhes da tarefa
$sql = "SELECT t.*, 
               d.nome as disciplina_nome,
               d.cor as disciplina_cor,
               p.nome as professor_nome,
               r.id as resposta_id,
               r.resposta_texto,
               r.anexo_path,
               r.status as resposta_status,
               r.nota,
               r.comentario_professor,
               r.data_entrega as data_resposta
        FROM tarefas t
        JOIN disciplinas d ON d.id = t.disciplina_id
        JOIN professores p ON p.id = t.professor_id
        LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = :aluno_id
        WHERE t.id = :tarefa_id";

$stmt = $conn->prepare($sql);
$stmt->execute([
    ':aluno_id' => $aluno_id,
    ':tarefa_id' => $tarefa_id
]);
$tarefa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tarefa) {
    echo json_encode(['success' => false, 'message' => 'Tarefa não encontrada']);
    exit;
}

// Gerar HTML
ob_start();
?>

<div class="row">
    <div class="col-md-8">
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <h4><?php echo htmlspecialchars($tarefa['titulo']); ?></h4>
                <span class="badge bg-secondary"><?php echo htmlspecialchars($tarefa['disciplina_nome']); ?></span>
            </div>
            
            <div class="alert alert-info">
                <div class="row">
                    <div class="col-md-6">
                        <i class="fas fa-user-chalk"></i> <strong>Professor:</strong> <?php echo htmlspecialchars($tarefa['professor_nome']); ?>
                    </div>
                    <div class="col-md-6">
                        <i class="fas fa-calendar-alt"></i> <strong>Data de Entrega:</strong> <?php echo date('d/m/Y H:i', strtotime($tarefa['data_entrega'])); ?>
                    </div>
                    <?php if ($tarefa['max_pontos']): ?>
                    <div class="col-md-6 mt-2">
                        <i class="fas fa-star"></i> <strong>Pontuação Máxima:</strong> <?php echo $tarefa['max_pontos']; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="mb-4">
                <h5><i class="fas fa-align-left"></i> Descrição</h5>
                <div class="p-3 bg-light rounded">
                    <?php echo nl2br(htmlspecialchars($tarefa['descricao'])); ?>
                </div>
            </div>
            
            <?php if ($tarefa['material_apoio']): ?>
            <div class="mb-4">
                <h5><i class="fas fa-paperclip"></i> Material de Apoio</h5>
                <a href="<?php echo $tarefa['material_apoio']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-download"></i> Baixar Material
                </a>
            </div>
            <?php endif; ?>
            
            <!-- Área de Resposta -->
            <div class="mt-4">
                <h5><i class="fas fa-reply"></i> Sua Resposta</h5>
                
                <?php if ($tarefa['resposta_status'] == 'corrigido'): ?>
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle"></i> Tarefa Corrigida</h6>
                        <p><strong>Nota:</strong> <?php echo number_format($tarefa['nota'], 1); ?> / <?php echo $tarefa['max_pontos']; ?></p>
                        <?php if ($tarefa['comentario_professor']): ?>
                        <p><strong>Comentário do Professor:</strong><br><?php echo nl2br(htmlspecialchars($tarefa['comentario_professor'])); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <form id="formResposta" enctype="multipart/form-data">
                    <?php if ($tarefa['resposta_status'] != 'corrigido'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Sua Resposta</label>
                            <div id="resposta-editor" style="height: 200px;"></div>
                            <textarea name="resposta_texto" id="resposta_texto" style="display:none;"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Anexar Arquivo (opcional)</label>
                            <input type="file" class="form-control" id="anexo_file" name="anexo" accept=".pdf,.doc,.docx,.jpg,.png,.zip">
                            <small class="text-muted">Formatos permitidos: PDF, DOC, DOCX, JPG, PNG, ZIP. Máx: 10MB</small>
                        </div>
                        
                        <?php if ($tarefa['resposta_id']): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Você já enviou uma resposta para esta tarefa. Pode enviar novamente para atualizar.
                                <?php if ($tarefa['anexo_path']): ?>
                                <br><i class="fas fa-paperclip"></i> <a href="<?php echo $tarefa['anexo_path']; ?>" target="_blank">Ver anexo anterior</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-paper-plane"></i> Enviar Resposta
                        </button>
                    <?php else: ?>
                        <div class="resposta-box">
                            <h6>Sua Resposta Enviada:</h6>
                            <div><?php echo nl2br(htmlspecialchars($tarefa['resposta_texto'])); ?></div>
                            <?php if ($tarefa['anexo_path']): ?>
                            <div class="mt-2">
                                <a href="<?php echo $tarefa['anexo_path']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-download"></i> Baixar Anexo
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <div class="card">
            <div class="card-body">
                <h6><i class="fas fa-info-circle"></i> Informações</h6>
                <hr>
                <p><strong>Status:</strong><br>
                <?php 
                if ($tarefa['resposta_status'] == 'corrigido') {
                    echo '<span class="badge bg-success">Corrigida</span>';
                } elseif ($tarefa['resposta_id']) {
                    echo '<span class="badge bg-info">Entregue</span>';
                } else {
                    echo '<span class="badge bg-warning">Pendente</span>';
                }
                ?>
                </p>
                
                <?php if ($tarefa['data_entrega'] < date('Y-m-d H:i:s') && !$tarefa['resposta_id']): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-triangle"></i> Esta tarefa está atrasada!
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$html = ob_get_clean();

echo json_encode([
    'success' => true,
    'html' => $html,
    'resposta_texto' => $tarefa['resposta_texto']
]);
?>