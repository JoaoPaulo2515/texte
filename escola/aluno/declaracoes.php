<?php
// aluno/documentos/declaracoes.php - Solicitação de Declarações e Certificados

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$sql_aluno = "SELECT e.*, 
                     u.email, 
                     tur.nome as turma_nome, 
                     tur.ano as turma_ano,
                     es.nome as escola_nome,
                     es.telefone as escola_telefone
              FROM estudantes e
              LEFT JOIN usuarios u ON e.usuario_id = u.id
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
// PROCESSAR SOLICITAÇÃO
// ============================================
$mensagem_sucesso = '';
$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_documento = $_POST['tipo_documento'] ?? '';
    $finalidade = trim($_POST['finalidade'] ?? '');
    $observacoes = trim($_POST['observacoes'] ?? '');
    $ano_letivo = (int)($_POST['ano_letivo'] ?? date('Y'));
    $quantidade = (int)($_POST['quantidade'] ?? 1);
    $para_quem = trim($_POST['para_quem'] ?? '');
    
    // Validações
    if (empty($tipo_documento)) {
        $mensagem_erro = "Selecione o tipo de documento.";
    } elseif (empty($finalidade)) {
        $mensagem_erro = "Informe a finalidade do documento.";
    } else {
        // Gerar código da solicitação
        $codigo = 'SOL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        
        // Inserir solicitação
        $sql_insert = "INSERT INTO solicitacoes_certificados 
                       (escola_id, aluno_id, tipo, titulo, descricao, ano_letivo, 
                        codigo_solicitacao, status, data_solicitacao, observacoes)
                       VALUES 
                       (:escola_id, :aluno_id, :tipo, :titulo, :descricao, :ano_letivo,
                        :codigo, 'pendente', NOW(), :observacoes)";
        
        $titulo = getTituloDocumento($tipo_documento);
        $descricao = "Finalidade: " . $finalidade . "\n";
        $descricao .= "Quantidade: " . $quantidade . "\n";
        if (!empty($para_quem)) {
            $descricao .= "Para: " . $para_quem;
        }
        
        $stmt_insert = $conn->prepare($sql_insert);
        $result = $stmt_insert->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':tipo' => $tipo_documento,
            ':titulo' => $titulo,
            ':descricao' => $descricao,
            ':ano_letivo' => $ano_letivo,
            ':codigo' => $codigo,
            ':observacoes' => $observacoes
        ]);
        
        if ($result) {
            $mensagem_sucesso = "Solicitação enviada com sucesso! Seu código é: <strong>$codigo</strong>";
        } else {
            $mensagem_erro = "Erro ao enviar solicitação. Tente novamente.";
        }
    }
}

// ============================================
// BUSCAR SOLICITAÇÕES DO ALUNO
// ============================================
$sql_solicitacoes = "SELECT s.*,
                            CASE 
                                WHEN s.tipo = 'declaracao' THEN 'Declaração'
                                WHEN s.tipo = 'historico' THEN 'Histórico Escolar'
                                WHEN s.tipo = 'certificado' THEN 'Certificado'
                                WHEN s.tipo = 'atestado' THEN 'Atestado'
                                WHEN s.tipo = 'transferencia' THEN 'Transferência'
                                ELSE 'Outro'
                            END as tipo_label,
                            CASE 
                                WHEN s.status = 'pendente' THEN 'Pendente'
                                WHEN s.status = 'em_processamento' THEN 'Em Processamento'
                                WHEN s.status = 'aprovado' THEN 'Aprovado'
                                WHEN s.status = 'rejeitado' THEN 'Rejeitado'
                            END as status_label
                     FROM solicitacoes_certificados s
                     WHERE s.aluno_id = :aluno_id AND s.escola_id = :escola_id
                     ORDER BY s.data_solicitacao DESC";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar certificados já emitidos
$sql_certificados = "SELECT c.*,
                            CASE 
                                WHEN c.tipo = 'conclusao' THEN 'Certificado de Conclusão'
                                WHEN c.tipo = 'historico' THEN 'Histórico Escolar'
                                WHEN c.tipo = 'transferencia' THEN 'Transferência'
                                WHEN c.tipo = 'declaracao' THEN 'Declaração'
                                WHEN c.tipo = 'atestado' THEN 'Atestado'
                                ELSE 'Outro'
                            END as tipo_label
                     FROM certificados c
                     WHERE c.aluno_id = :aluno_id AND c.escola_id = :escola_id
                     AND c.status = 'ativo'
                     ORDER BY c.data_emissao DESC";
$stmt_certificados = $conn->prepare($sql_certificados);
$stmt_certificados->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$certificados = $stmt_certificados->fetchAll(PDO::FETCH_ASSOC);

// Função para obter título do documento
function getTituloDocumento($tipo) {
    $titulos = [
        'declaracao' => 'Solicitação de Declaração de Matrícula',
        'historico' => 'Solicitação de Histórico Escolar',
        'certificado' => 'Solicitação de Certificado de Conclusão',
        'atestado' => 'Solicitação de Atestado de Frequência',
        'transferencia' => 'Solicitação de Transferência Escolar'
    ];
    return $titulos[$tipo] ?? 'Solicitação de Documento';
}

function getStatusBadge($status) {
    $badges = [
        'pendente' => '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>',
        'em_processamento' => '<span class="badge bg-info"><i class="fas fa-spinner fa-spin"></i> Em Processamento</span>',
        'aprovado' => '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>',
        'rejeitado' => '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejeitado</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Declarações e Certificados | Área do Aluno</title>
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
        
        .documento-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .documento-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .solicitacao-item {
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        .solicitacao-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        .btn-solicitar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            transition: all 0.3s;
        }
        .btn-solicitar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,107,62,0.3);
        }
        
        .codigo-solicitacao {
            font-family: monospace;
            font-size: 12px;
            background: #f0f0f0;
            padding: 3px 8px;
            border-radius: 5px;
        }
        
        .info-aluno {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 10px;
            padding: 15px;
        }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
  <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-file-alt"></i> Declarações e Certificados</h2>
                <p class="text-muted">Solicite declarações, certificados e outros documentos escolares</p>
            </div>
            <div class="no-print mt-2 mt-sm-0">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Alertas -->
        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $mensagem_sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($mensagem_erro): ?>
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
                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Nova Solicitação</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-aluno mb-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <small class="text-muted">Aluno</small>
                                    <p class="mb-0"><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></p>
                                </div>
                                <div class="col-md-6">
                                    <small class="text-muted">Matrícula</small>
                                    <p class="mb-0"><strong><?php echo $aluno['matricula']; ?></strong></p>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <small class="text-muted">Turma</small>
                                    <p class="mb-0"><strong><?php echo $aluno['turma_ano'] . 'ª ' . ($aluno['turma_nome'] ?? ''); ?></strong></p>
                                </div>
                                <div class="col-md-6 mt-2">
                                    <small class="text-muted">Ano Letivo</small>
                                    <p class="mb-0"><strong><?php echo date('Y'); ?></strong></p>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Tipo de Documento</label>
                                <select class="form-select" name="tipo_documento" required>
                                    <option value="">Selecione...</option>
                                    <option value="declaracao">📄 Declaração de Matrícula</option>
                                    <option value="historico">📊 Histórico Escolar</option>
                                    <option value="certificado">🎓 Certificado de Conclusão</option>
                                    <option value="atestado">📋 Atestado de Frequência</option>
                                    <option value="transferencia">🔄 Transferência Escolar</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Finalidade</label>
                                <select class="form-select" name="finalidade" required>
                                    <option value="">Selecione...</option>
                                    <option value="Matrícula em outra escola">Matrícula em outra escola</option>
                                    <option value="Emprego">Emprego</option>
                                    <option value="Concurso">Concurso público</option>
                                    <option value="Visto/Imigração">Visto/Imigração</option>
                                    <option value="Benefício social">Benefício social</option>
                                    <option value="Estágio">Estágio</option>
                                    <option value="Outros">Outros</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Ano Letivo</label>
                                <select class="form-select" name="ano_letivo">
                                    <option value="2024">2024</option>
                                    <option value="2023">2023</option>
                                    <option value="2022">2022</option>
                                    <option value="2021">2021</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Quantidade</label>
                                <input type="number" class="form-control" name="quantidade" value="1" min="1" max="5">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Para quem será o documento?</label>
                                <input type="text" class="form-control" name="para_quem" placeholder="Ex: Instituto Superior Politécnico, Empresa XYZ, etc">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Observações (opcional)</label>
                                <textarea class="form-control" name="observacoes" rows="3" 
                                          placeholder="Informações adicionais relevantes..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-solicitar w-100">
                                <i class="fas fa-paper-plane"></i> Solicitar Documento
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Informações -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Informações</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="mb-2"><i class="fas fa-clock text-warning"></i> Prazo de emissão: 3 a 5 dias úteis</li>
                            <li class="mb-2"><i class="fas fa-money-bill text-success"></i> Taxa de emissão: Consulte a secretaria</li>
                            <li class="mb-2"><i class="fas fa-file-pdf text-danger"></i> Documentos são emitidos em PDF</li>
                            <li class="mb-2"><i class="fas fa-qrcode text-info"></i> Todos os documentos possuem código de verificação</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Lista de Solicitações -->
            <div class="col-lg-7">
                <!-- Certificados já emitidos -->
                <?php if (!empty($certificados)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-check-circle text-success"></i> Documentos Emitidos</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Documento</th>
                                        <th>Data Emissão</th>
                                        <th>Código</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($certificados as $cert): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($cert['titulo']); ?></strong>
                                            <br><small class="text-muted"><?php echo $cert['tipo_label']; ?></small>
                                        </td>
                                        <td><?php echo date('d/m/Y', strtotime($cert['data_emissao'])); ?>Ne
                                        <td>
                                            <span class="codigo-solicitacao"><?php echo $cert['codigo_verificacao']; ?></span>
                                        </td>
                                        <td>
                                            <?php if ($cert['arquivo_path']): ?>
                                            <a href="<?php echo $cert['arquivo_path']; ?>" target="_blank" class="btn btn-sm btn-danger">
                                                <i class="fas fa-file-pdf"></i> Baixar
                                            </a>
                                            <?php endif; ?>
                                            <button class="btn btn-sm btn-info" onclick="verificarAutenticidade('<?php echo $cert['codigo_verificacao']; ?>')">
                                                <i class="fas fa-qrcode"></i> Verificar
                                            </button>
                                         </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Solicitações Pendentes -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock"></i> Minhas Solicitações</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($solicitacoes)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-2"></i>
                                <p class="text-muted">Nenhuma solicitação encontrada.</p>
                                <small>Utilize o formulário ao lado para solicitar documentos.</small>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($solicitacoes as $solic): ?>
                                <div class="list-group-item solicitacao-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center mb-2 flex-wrap">
                                                <strong><?php echo htmlspecialchars($solic['titulo']); ?></strong>
                                                <span class="ms-2"><?php echo getStatusBadge($solic['status']); ?></span>
                                            </div>
                                            <div class="row small">
                                                <div class="col-md-6">
                                                    <i class="fas fa-calendar-alt"></i> 
                                                    Solicitação: <?php echo date('d/m/Y H:i', strtotime($solic['data_solicitacao'])); ?>
                                                </div>
                                                <div class="col-md-6">
                                                    <span class="codigo-solicitacao">
                                                        <i class="fas fa-code"></i> <?php echo $solic['codigo_solicitacao']; ?>
                                                    </span>
                                                </div>
                                                <?php if ($solic['ano_letivo']): ?>
                                                <div class="col-md-6 mt-1">
                                                    <i class="fas fa-calendar"></i> Ano Letivo: <?php echo $solic['ano_letivo']; ?>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($solic['data_resposta']): ?>
                                                <div class="col-md-6 mt-1">
                                                    <i class="fas fa-reply-all"></i> 
                                                    Resposta: <?php echo date('d/m/Y H:i', strtotime($solic['data_resposta'])); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="mt-2">
                                                <small class="text-muted"><?php echo nl2br(htmlspecialchars(substr($solic['descricao'], 0, 150))); ?></small>
                                            </div>
                                            <?php if ($solic['resposta_motivo'] && $solic['status'] == 'rejeitado'): ?>
                                            <div class="alert alert-danger mt-2 mb-0 py-1">
                                                <i class="fas fa-info-circle"></i> 
                                                <strong>Motivo:</strong> <?php echo htmlspecialchars($solic['resposta_motivo']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if ($solic['arquivo_gerado'] && $solic['status'] == 'aprovado'): ?>
                                            <div class="mt-2">
                                                <a href="<?php echo $solic['arquivo_gerado']; ?>" target="_blank" class="btn btn-sm btn-danger">
                                                    <i class="fas fa-file-pdf"></i> Baixar Documento
                                                </a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Preços -->
        <div class="card mt-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-table"></i> Tabela de Emissão de Documentos</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Tipo de Documento</th>
                                <th>Prazo Normal</th>
                                <th>Prazo Urgente*</th>
                                <th>Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Declaração de Matrícula</td><td>3 dias</td><td>1 dia</td><td>1.500 Kz</td></tr>
                            <tr><td>Histórico Escolar</td><td>5 dias</td><td>2 dias</td><td>3.000 Kz</td></tr>
                            <tr><td>Certificado de Conclusão</td><td>7 dias</td><td>3 dias</td><td>5.000 Kz</td></tr>
                            <tr><td>Atestado de Frequência</td><td>3 dias</td><td>1 dia</td><td>1.500 Kz</td></tr>
                            <tr><td>Transferência Escolar</td><td>5 dias</td><td>2 dias</td><td>3.000 Kz</td></tr>
                        </tbody>
                    </table>
                </div>
                <small class="text-muted">* Taxa adicional de 50% para emissão urgente. Consulte a secretaria.</small>
            </div>
        </div>
    </div>
    
    <!-- Modal Verificar Autenticidade -->
    <div class="modal fade" id="verificarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-qrcode"></i> Verificar Autenticidade</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center" id="verificarConteudo">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Verificando código...</p>
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
        
        // Verificar autenticidade do documento
        function verificarAutenticidade(codigo) {
            $('#verificarConteudo').html(`
                <div class="spinner-border text-info" role="status">
                    <span class="visually-hidden">Carregando...</span>
                </div>
                <p class="mt-2">Verificando código: <strong>${codigo}</strong></p>
            `);
            
            $.ajax({
                url: 'ajax_verificar_documento.php',
                method: 'POST',
                data: { codigo: codigo },
                dataType: 'json',
                success: function(response) {
                    if (response.valido) {
                        $('#verificarConteudo').html(`
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h5>Documento Válido!</h5>
                            <p>Este documento é autêntico e foi emitido pela escola.</p>
                            <div class="alert alert-success">
                                <strong>Código:</strong> ${response.codigo}<br>
                                <strong>Emitido para:</strong> ${response.aluno}<br>
                                <strong>Data de Emissão:</strong> ${response.data_emissao}
                            </div>
                        `);
                    } else {
                        $('#verificarConteudo').html(`
                            <i class="fas fa-times-circle fa-4x text-danger mb-3"></i>
                            <h5>Documento Inválido!</h5>
                            <p>Não foi possível verificar a autenticidade deste documento.</p>
                            <div class="alert alert-danger">
                                <strong>Código:</strong> ${codigo}<br>
                                <strong>Status:</strong> Documento não encontrado ou cancelado
                            </div>
                        `);
                    }
                },
                error: function() {
                    $('#verificarConteudo').html(`
                        <i class="fas fa-exclamation-triangle fa-4x text-warning mb-3"></i>
                        <h5>Erro na Verificação</h5>
                        <p>Não foi possível verificar o código. Tente novamente mais tarde.</p>
                    `);
                }
            });
            
            new bootstrap.Modal(document.getElementById('verificarModal')).show();
        }
    </script>
</body>
</html>