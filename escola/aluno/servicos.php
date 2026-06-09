<?php
// aluno/financeiro/servicos.php - Contratação de Serviços Escolares

require_once __DIR__ . '/../../config/database.php';
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
// PROCESSAR AÇÕES
// ============================================

// Solicitar serviço
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'solicitar') {
    $servico_id = (int)($_POST['servico_id'] ?? 0);
    $observacoes = trim($_POST['observacoes'] ?? '');
    
    // Buscar dados do serviço
    $sql_servico = "SELECT * FROM servicos_escolares WHERE id = :id AND escola_id = :escola_id AND status = 'ativo'";
    $stmt_servico = $conn->prepare($sql_servico);
    $stmt_servico->execute([
        ':id' => $servico_id,
        ':escola_id' => $escola_id
    ]);
    $servico = $stmt_servico->fetch(PDO::FETCH_ASSOC);
    
    if (!$servico) {
        $mensagem_erro = "Serviço não encontrado ou indisponível.";
    } else {
        // Verificar se já possui solicitação ativa
        $sql_check = "SELECT id FROM servicos_solicitacoes 
                      WHERE aluno_id = :aluno_id AND servico_id = :servico_id 
                      AND status IN ('pendente', 'aprovado', 'ativo')
                      AND escola_id = :escola_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':aluno_id' => $aluno_id,
            ':servico_id' => $servico_id,
            ':escola_id' => $escola_id
        ]);
        
        if ($stmt_check->fetch()) {
            $mensagem_erro = "Você já possui uma solicitação ativa para este serviço.";
        } else {
            // Gerar código da solicitação
            $codigo = 'SOL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            
            // Inserir solicitação
            $sql_insert = "INSERT INTO servicos_solicitacoes 
                           (escola_id, aluno_id, servico_id, codigo, valor, observacoes, status, data_solicitacao)
                           VALUES 
                           (:escola_id, :aluno_id, :servico_id, :codigo, :valor, :observacoes, 'pendente', NOW())";
            
            $stmt_insert = $conn->prepare($sql_insert);
            $result = $stmt_insert->execute([
                ':escola_id' => $escola_id,
                ':aluno_id' => $aluno_id,
                ':servico_id' => $servico_id,
                ':codigo' => $codigo,
                ':valor' => $servico['valor'],
                ':observacoes' => $observacoes
            ]);
            
            if ($result) {
                $mensagem_sucesso = "Solicitação enviada com sucesso! Código: $codigo";
            } else {
                $mensagem_erro = "Erro ao enviar solicitação. Tente novamente.";
            }
        }
    }
}

// Cancelar solicitação
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $solicitacao_id = (int)$_GET['cancelar'];
    
    $sql_cancelar = "UPDATE servicos_solicitacoes 
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
// BUSCAR SERVIÇOS DISPONÍVEIS
// ============================================

$sql_servicos = "SELECT * FROM servicos_escolares 
                 WHERE escola_id = :escola_id AND status = 'ativo' 
                 ORDER BY categoria, nome";
$stmt_servicos = $conn->prepare($sql_servicos);
$stmt_servicos->execute([':escola_id' => $escola_id]);
$servicos = $stmt_servicos->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por categoria
$categorias = [];
foreach ($servicos as $servico) {
    $cat = $servico['categoria'];
    if (!isset($categorias[$cat])) {
        $categorias[$cat] = [];
    }
    $categorias[$cat][] = $servico;
}

// ============================================
// BUSCAR SOLICITAÇÕES DO ALUNO
// ============================================

$sql_solicitacoes = "SELECT s.*, 
                            serv.nome as servico_nome, 
                            serv.categoria as servico_categoria,
                            serv.icone as servico_icone,
                            CASE 
                                WHEN s.status = 'pendente' THEN 'Pendente'
                                WHEN s.status = 'aprovado' THEN 'Aprovado'
                                WHEN s.status = 'rejeitado' THEN 'Rejeitado'
                                WHEN s.status = 'ativo' THEN 'Ativo'
                                WHEN s.status = 'concluido' THEN 'Concluído'
                                WHEN s.status = 'cancelado' THEN 'Cancelado'
                            END as status_label
                     FROM servicos_solicitacoes s
                     JOIN servicos_escolares serv ON serv.id = s.servico_id
                     WHERE s.aluno_id = :aluno_id AND s.escola_id = :escola_id
                     ORDER BY s.data_solicitacao DESC";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// SERVIÇOS MAIS SOLICITADOS (estatísticas)
// ============================================

$sql_populares = "SELECT serv.nome, COUNT(*) as total
                  FROM servicos_solicitacoes s
                  JOIN servicos_escolares serv ON serv.id = s.servico_id
                  WHERE s.escola_id = :escola_id
                  GROUP BY s.servico_id
                  ORDER BY total DESC
                  LIMIT 5";
$stmt_populares = $conn->prepare($sql_populares);
$stmt_populares->execute([':escola_id' => $escola_id]);
$servicos_populares = $stmt_populares->fetchAll(PDO::FETCH_ASSOC);

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
        'aprovado' => '<span class="badge bg-info"><i class="fas fa-check-circle"></i> Aprovado</span>',
        'rejeitado' => '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejeitado</span>',
        'ativo' => '<span class="badge bg-success"><i class="fas fa-play-circle"></i> Ativo</span>',
        'concluido' => '<span class="badge bg-secondary"><i class="fas fa-check-double"></i> Concluído</span>',
        'cancelado' => '<span class="badge bg-dark"><i class="fas fa-ban"></i> Cancelado</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">' . ucfirst($status) . '</span>';
}

function getCategoriaIcone($categoria) {
    $icones = [
        'biblioteca' => 'fa-book',
        'laboratorio' => 'fa-flask',
        'esporte' => 'fa-futbol',
        'cultura' => 'fa-palette',
        'idiomas' => 'fa-language',
        'tecnologia' => 'fa-laptop-code',
        'reforco' => 'fa-chalkboard-user',
        'outros' => 'fa-cogs'
    ];
    return $icones[$categoria] ?? 'fa-star';
}

function getCategoriaLabel($categoria) {
    $labels = [
        'biblioteca' => 'Biblioteca',
        'laboratorio' => 'Laboratório',
        'esporte' => 'Esportes',
        'cultura' => 'Cultura e Artes',
        'idiomas' => 'Idiomas',
        'tecnologia' => 'Tecnologia',
        'reforco' => 'Reforço Escolar',
        'outros' => 'Outros Serviços'
    ];
    return $labels[$categoria] ?? ucfirst($categoria);
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Serviços Escolares | Área do Aluno</title>
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
        
        .servico-card {
            transition: all 0.3s;
            cursor: pointer;
            height: 100%;
        }
        .servico-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
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
        
        .categoria-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
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
  <?php include 'includes/menu_aluno.php'; ?>
  
   <div class="main-content-aluno">
         <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-concierge-bell"></i> Serviços Escolares</h2>
                <p class="text-muted">Contrate serviços adicionais e atividades extracurriculares</p>
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
            <!-- Lista de Serviços -->
            <div class="col-lg-8">
                <?php foreach ($categorias as $categoria => $servicos_cat): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas <?php echo getCategoriaIcone($categoria); ?>"></i>
                            <?php echo getCategoriaLabel($categoria); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($servicos_cat as $servico): ?>
                            <div class="col-md-6 mb-3">
                                <div class="servico-card card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0">
                                                <i class="fas <?php echo $servico['icone'] ?? getCategoriaIcone($categoria); ?> me-2 text-success"></i>
                                                <?php echo htmlspecialchars($servico['nome']); ?>
                                            </h6>
                                            <span class="categoria-badge" style="background: #006B3E20; color: #006B3E;">
                                                <?php echo formatarMoeda($servico['valor']); ?>
                                            </span>
                                        </div>
                                        <p class="small text-muted">
                                            <?php echo htmlspecialchars($servico['descricao']); ?>
                                        </p>
                                        <div class="mt-2">
                                            <small class="text-muted">
                                                <i class="fas fa-clock"></i> Duração: <?php echo $servico['duracao'] ?? 'Sob consulta'; ?>
                                            </small>
                                        </div>
                                        <div class="mt-3">
                                            <button class="btn btn-sm btn-success w-100 btn-solicitar" 
                                                    onclick="solicitarServico(<?php echo $servico['id']; ?>, '<?php echo addslashes($servico['nome']); ?>', <?php echo $servico['valor']; ?>)">
                                                <i class="fas fa-hand-holding-heart"></i> Solicitar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Sidebar - Histórico e Informações -->
            <div class="col-lg-4">
                <!-- Serviços Populares -->
                <?php if (!empty($servicos_populares)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-fire"></i> Serviços Populares</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($servicos_populares as $popular): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span><?php echo htmlspecialchars($popular['nome']); ?></span>
                            <span class="badge bg-primary"><?php echo $popular['total']; ?> solicitações</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Informações -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Como funciona</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Solicite o serviço desejado</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Aguarde aprovação da escola</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Realize o pagamento (se aplicável)</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Aproveite o serviço contratado</li>
                        </ul>
                    </div>
                </div>
                
                <!-- Contato -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-headset"></i> Dúvidas?</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">Entre em contato com a secretaria:</p>
                        <p class="mb-1"><i class="fas fa-phone"></i> Telefone: (011) 1234-5678</p>
                        <p class="mb-0"><i class="fas fa-envelope"></i> Email: servicos@escola.ao</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Minhas Solicitações -->
        <?php if (!empty($solicitacoes)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list"></i> Minhas Solicitações</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Código</th>
                                <th>Serviço</th>
                                <th>Data</th>
                                <th class="text-end">Valor</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitacoes as $solic): ?>
                            <tr class="solicitacao-item">
                                <td><small><?php echo $solic['codigo']; ?></small></td>
                                <td>
                                    <i class="fas <?php echo $solic['servico_icone'] ?? getCategoriaIcone($solic['servico_categoria']); ?> me-1"></i>
                                    <?php echo htmlspecialchars($solic['servico_nome']); ?>
                                </td>
                                <td><?php echo formatarData($solic['data_solicitacao']); ?></td>
                                <td class="text-end fw-bold"><?php echo formatarMoeda($solic['valor']); ?>Ne
                                <td><?php echo getStatusBadge($solic['status']); ?>Ne
                                <td>
                                    <?php if ($solic['status'] == 'pendente'): ?>
                                    <a href="?cancelar=<?php echo $solic['id']; ?>" class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('Cancelar esta solicitação?')">
                                        <i class="fas fa-times"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($solic['status'] == 'aprovado' && $solic['valor'] > 0): ?>
                                    <button class="btn btn-sm btn-success" onclick="pagarServico(<?php echo $solic['id']; ?>, <?php echo $solic['valor']; ?>)">
                                        <i class="fas fa-credit-card"></i> Pagar
                                    </button>
                                    <?php endif; ?>
                                 </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Solicitar Serviço -->
    <div class="modal fade" id="solicitarModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-heart"></i> Solicitar Serviço</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="solicitar">
                        <input type="hidden" name="servico_id" id="servico_id">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Serviço</label>
                            <p class="form-control-plaintext" id="servico_nome"></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Valor</label>
                            <p class="form-control-plaintext text-success fw-bold" id="servico_valor"></p>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Observações (opcional)</label>
                            <textarea name="observacoes" class="form-control" rows="3" 
                                      placeholder="Informações adicionais sobre sua solicitação..."></textarea>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i>
                            Após a solicitação, aguarde a aprovação da escola. Você será notificado.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar Solicitação</button>
                    </div>
                </form>
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
        
        // Solicitar serviço
        function solicitarServico(id, nome, valor) {
            document.getElementById('servico_id').value = id;
            document.getElementById('servico_nome').innerHTML = nome;
            document.getElementById('servico_valor').innerHTML = formatarMoeda(valor);
            
            new bootstrap.Modal(document.getElementById('solicitarModal')).show();
        }
        
        // Pagar serviço
        function pagarServico(id, valor) {
            if (confirm(`Deseja gerar boleto para pagamento de ${formatarMoeda(valor)}?`)) {
                window.location.href = `boletos.php?servico_id=${id}&valor=${valor}`;
            }
        }
        
        // Formatar moeda
        function formatarMoeda(valor) {
            return new Intl.NumberFormat('pt-AO', { style: 'currency', currency: 'AOA' }).format(valor);
        }
    </script>
</body>
</html>