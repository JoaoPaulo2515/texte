<?php
// aluno/financeiro/descontos.php - Descontos e Bolsas

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.nome, e.matricula, e.email, e.telefone,
                     tur.nome as turma_nome, tur.ano as turma_ano,
                     es.nome as escola_nome
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              LEFT JOIN escolas es ON es.id = e.escola_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR SOLICITAÇÃO DE DESCONTO
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar') {
    $tipo_desconto = $_POST['tipo_desconto'] ?? '';
    $justificativa = trim($_POST['justificativa'] ?? '');
    $documentos = [];
    
    // Processar upload de documentos
    if (isset($_FILES['documentos']) && !empty($_FILES['documentos']['name'][0])) {
        $upload_dir = __DIR__ . '/../../../uploads/descontos/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        foreach ($_FILES['documentos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['documentos']['error'][$key] === UPLOAD_ERR_OK) {
                $nome_original = $_FILES['documentos']['name'][$key];
                $extensao = pathinfo($nome_original, PATHINFO_EXTENSION);
                $nome_arquivo = 'desconto_' . $aluno_id . '_' . time() . '_' . $key . '.' . $extensao;
                $caminho = $upload_dir . $nome_arquivo;
                
                if (move_uploaded_file($tmp_name, $caminho)) {
                    $documentos[] = 'uploads/descontos/' . $nome_arquivo;
                }
            }
        }
    }
    
    // Gerar código da solicitação
    $codigo = 'DESC-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
    
    // Inserir solicitação
    $sql_insert = "INSERT INTO solicitacoes_desconto 
                   (escola_id, aluno_id, codigo, tipo_desconto, justificativa, documentos, status, data_solicitacao)
                   VALUES 
                   (:escola_id, :aluno_id, :codigo, :tipo_desconto, :justificativa, :documentos, 'pendente', NOW())";
    
    $stmt_insert = $conn->prepare($sql_insert);
    $result = $stmt_insert->execute([
        ':escola_id' => $escola_id,
        ':aluno_id' => $aluno_id,
        ':codigo' => $codigo,
        ':tipo_desconto' => $tipo_desconto,
        ':justificativa' => $justificativa,
        ':documentos' => implode('|', $documentos)
    ]);
    
    if ($result) {
        $mensagem_sucesso = "Solicitação de desconto enviada com sucesso! Código: $codigo";
    } else {
        $mensagem_erro = "Erro ao enviar solicitação. Tente novamente.";
    }
}

// Cancelar solicitação
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $solicitacao_id = (int)$_GET['cancelar'];
    
    $sql_cancelar = "UPDATE solicitacoes_desconto 
                     SET status = 'cancelado', data_resposta = NOW() 
                     WHERE id = :id AND aluno_id = :aluno_id AND escola_id = :escola_id
                     AND status = 'pendente'";
    $stmt_cancelar = $conn->prepare($sql_cancelar);
    $stmt_cancelar->execute([
        ':id' => $solicitacao_id,
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    
    if ($stmt_cancelar->rowCount() > 0) {
        $mensagem_sucesso = "Solicitação cancelada com sucesso!";
    } else {
        $mensagem_erro = "Não foi possível cancelar a solicitação.";
    }
}

// ============================================
// BUSCAR DESCONTOS ATIVOS DO ALUNO
// ============================================

$sql_descontos = "SELECT * FROM descontos_aluno 
                  WHERE aluno_id = :aluno_id AND escola_id = :escola_id 
                  AND status = 'ativo'
                  ORDER BY data_concessao DESC";
$stmt_descontos = $conn->prepare($sql_descontos);
$stmt_descontos->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$descontos_ativos = $stmt_descontos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR SOLICITAÇÕES DE DESCONTO
// ============================================

$sql_solicitacoes = "SELECT * FROM solicitacoes_desconto 
                     WHERE aluno_id = :aluno_id AND escola_id = :escola_id
                     ORDER BY data_solicitacao DESC";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadge($status) {
    $badges = [
        'pendente' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>',
        'aprovado' => '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>',
        'rejeitado' => '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejeitado</span>',
        'ativo' => '<span class="badge bg-info"><i class="fas fa-play-circle"></i> Ativo</span>',
        'cancelado' => '<span class="badge bg-secondary"><i class="fas fa-ban"></i> Cancelado</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
}

function getTipoDescontoLabel($tipo) {
    $labels = [
        'bolsa' => 'Bolsa de Estudos',
        'merito' => 'Desconto por Mérito',
        'social' => 'Desconto Social',
        'irmao' => 'Desconto Irmão',
        'pontualidade' => 'Desconto por Pontualidade',
        'outro' => 'Outro Tipo'
    ];
    return $labels[$tipo] ?? ucfirst($tipo);
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Descontos e Bolsas | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .desconto-card {
            transition: all 0.3s;
        }
        .desconto-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .solicitacao-item {
            transition: all 0.3s;
        }
        .solicitacao-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .btn-solicitar {
            transition: all 0.3s;
        }
        .btn-solicitar:hover {
            transform: scale(1.05);
        }
        
        .info-aluno {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 10px;
            padding: 15px;
        }
        
        .tipo-desconto-card {
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #e0e0e0;
        }
        .tipo-desconto-card:hover {
            border-color: #006B3E;
            background: #f0fdf4;
        }
        .tipo-desconto-card.selected {
            border-color: #006B3E;
            background: #e8f5e9;
        }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
     <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content-aluno">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-tags"></i> Descontos e Bolsas</h2>
                <p class="text-muted">Solicite e acompanhe descontos e bolsas de estudo</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="info-aluno mb-4">
            <div class="row">
                <div class="col-md-4">
                    <i class="fas fa-user-graduate"></i>
                    <strong>Aluno:</strong> <?php echo htmlspecialchars($aluno['nome']); ?>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-id-card"></i>
                    <strong>Matrícula:</strong> <?php echo $aluno['matricula']; ?>
                </div>
                <div class="col-md-3">
                    <i class="fas fa-users"></i>
                    <strong>Turma:</strong> <?php echo $aluno['turma_ano'] . 'ª - ' . ($aluno['turma_nome'] ?? 'Não atribuída'); ?>
                </div>
                <div class="col-md-2">
                    <i class="fas fa-school"></i>
                    <strong>Escola:</strong> <?php echo htmlspecialchars($aluno['escola_nome']); ?>
                </div>
            </div>
        </div>
        
        <!-- Alertas -->
        <?php if (isset($mensagem_sucesso)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($mensagem_erro)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $mensagem_erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Formulário de Solicitação -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-signature"></i> Solicitar Desconto</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="formSolicitacao">
                            <input type="hidden" name="action" value="solicitar">
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tipo de Desconto</label>
                                <div class="row">
                                    <div class="col-md-6 mb-2">
                                        <div class="tipo-desconto-card p-2 text-center rounded" data-tipo="bolsa" onclick="selectTipo('bolsa')">
                                            <i class="fas fa-graduation-cap fa-2x text-success"></i>
                                            <div class="small">Bolsa de Estudos</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <div class="tipo-desconto-card p-2 text-center rounded" data-tipo="merito" onclick="selectTipo('merito')">
                                            <i class="fas fa-star fa-2x text-warning"></i>
                                            <div class="small">Mérito Acadêmico</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <div class="tipo-desconto-card p-2 text-center rounded" data-tipo="social" onclick="selectTipo('social')">
                                            <i class="fas fa-hand-holding-heart fa-2x text-info"></i>
                                            <div class="small">Desconto Social</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <div class="tipo-desconto-card p-2 text-center rounded" data-tipo="irmao" onclick="selectTipo('irmao')">
                                            <i class="fas fa-users fa-2x text-primary"></i>
                                            <div class="small">Desconto Irmão</div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="tipo_desconto" id="tipo_desconto" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Justificativa</label>
                                <textarea name="justificativa" class="form-control" rows="4" required 
                                          placeholder="Explique o motivo da solicitação de desconto..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Documentos Comprobatórios</label>
                                <input type="file" name="documentos[]" class="form-control" multiple 
                                       accept=".pdf,.jpg,.jpeg,.png">
                                <small class="text-muted">Formatos: PDF, JPG, PNG (máximo 5MB cada)</small>
                            </div>
                            
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <strong>Documentos comuns:</strong>
                                <ul class="mb-0 mt-1">
                                    <li>Declaração de renda</li>
                                    <li>Comprovante de matrícula de irmãos</li>
                                    <li>Histórico escolar</li>
                                    <li>Laudos médicos (se aplicável)</li>
                                </ul>
                            </div>
                            
                            <button type="submit" class="btn btn-solicitar w-100 btn-success">
                                <i class="fas fa-paper-plane"></i> Solicitar Desconto
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Descontos Ativos e Histórico -->
            <div class="col-lg-7">
                <!-- Descontos Ativos -->
                <?php if (!empty($descontos_ativos)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-check-circle"></i> Descontos Ativos</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($descontos_ativos as $desconto): ?>
                        <div class="desconto-card border rounded p-3 mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0"><?php echo getTipoDescontoLabel($desconto['tipo_desconto']); ?></h6>
                                    <small>Concedido em: <?php echo formatarData($desconto['data_concessao']); ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-success"><?php echo $desconto['percentual']; ?>% de desconto</span>
                                </div>
                            </div>
                            <div class="mt-2">
                                <small><?php echo htmlspecialchars($desconto['observacoes']); ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Histórico de Solicitações -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Minhas Solicitações</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($solicitacoes)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-2"></i>
                                <p>Nenhuma solicitação encontrada.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Código</th>
                                            <th>Tipo</th>
                                            <th>Data</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($solicitacoes as $solic): ?>
                                        <tr class="solicitacao-item">
                                            <td><small><?php echo $solic['codigo']; ?></small></td>
                                            <td><?php echo getTipoDescontoLabel($solic['tipo_desconto']); ?>Ne
                                            <td><?php echo formatarData($solic['data_solicitacao']); ?>Ne
                                            <td><?php echo getStatusBadge($solic['status']); ?>Ne
                                            <td>
                                                <?php if ($solic['status'] == 'pendente'): ?>
                                                <a href="?cancelar=<?php echo $solic['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                                   onclick="return confirm('Cancelar esta solicitação?')">
                                                    <i class="fas fa-times"></i>
                                                </a>
                                                <?php endif; ?>
                                                <?php if ($solic['resposta']): ?>
                                                <button class="btn btn-sm btn-info" onclick="verResposta('<?php echo addslashes($solic['resposta']); ?>')">
                                                    <i class="fas fa-comment-dots"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Informações -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Critérios para Descontos</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Bolsa de Estudos</h6>
                                <p class="small">Para alunos com bom desempenho acadêmico (média ≥ 8,5)</p>
                                
                                <h6 class="mt-2">Desconto Social</h6>
                                <p class="small">Para famílias de baixa renda (comprovante de renda)</p>
                            </div>
                            <div class="col-md-6">
                                <h6>Desconto Irmão</h6>
                                <p class="small">Para famílias com 2 ou mais alunos na escola</p>
                                
                                <h6 class="mt-2">Mérito Acadêmico</h6>
                                <p class="small">Para alunos com destaque em olimpíadas e competições</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Resposta -->
    <div class="modal fade" id="respostaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-comment-dots"></i> Resposta da Solicitação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="respostaConteudo">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Selecionar tipo de desconto
        function selectTipo(tipo) {
            document.querySelectorAll('.tipo-desconto-card').forEach(card => {
                card.classList.remove('selected');
            });
            document.querySelector(`.tipo-desconto-card[data-tipo="${tipo}"]`).classList.add('selected');
            document.getElementById('tipo_desconto').value = tipo;
        }
        
        // Ver resposta
        function verResposta(resposta) {
            document.getElementById('respostaConteudo').innerHTML = `
                <div class="alert alert-light">
                    <p>${resposta.replace(/\n/g, '<br>')}</p>
                </div>
            `;
            new bootstrap.Modal(document.getElementById('respostaModal')).show();
        }
    </script>
</body>
</html>