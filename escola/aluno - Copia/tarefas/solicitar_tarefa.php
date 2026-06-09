<?php
// aluno/tarefas/solicitar_tarefa.php - Solicitação de Tarefas Especiais/Recuperação

require_once __DIR__ . '/../../../config/database.php';
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
$sql_aluno = "SELECT e.*, u.email, t.nome as turma_nome, t.ano as turma_ano
              FROM estudantes e
              LEFT JOIN usuarios u ON e.usuario_id = u.id
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas t ON t.id = m.turma_id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar disciplinas do aluno
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                    FROM disciplinas d
                    JOIN tarefas t ON t.disciplina_id = d.id
                    JOIN turmas tur ON tur.id = t.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    WHERE m.estudante_id = :aluno_id 
                    AND d.escola_id = :escola_id
                    AND d.status = 'ativa'
                    ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores por disciplina
$sql_professores = "SELECT DISTINCT p.id, p.nome, d.id as disciplina_id
                    FROM funcionarios p
                    JOIN professor_disciplina_turma pd ON pd.professor_id = p.id
                    JOIN disciplinas d ON d.id = pd.disciplina_id
                    JOIN tarefas t ON t.disciplina_id = d.id
                    JOIN turmas tur ON tur.id = t.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    WHERE m.estudante_id = :aluno_id 
                    AND p.escola_id = :escola_id
                    AND p.status = 'ativo'";
$stmt_professores = $conn->prepare($sql_professores);
$stmt_professores->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$professores_temp = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// Organizar professores por disciplina
$professores_por_disciplina = [];
foreach ($professores_temp as $prof) {
    $professores_por_disciplina[$prof['disciplina_id']][] = $prof;
}

// ============================================
// PROCESSAR SOLICITAÇÃO
// ============================================
$mensagem_sucesso = '';
$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_solicitacao = $_POST['tipo_solicitacao'] ?? '';
    $disciplina_id = (int)($_POST['disciplina_id'] ?? 0);
    $professor_id = (int)($_POST['professor_id'] ?? 0);
    $titulo = trim($_POST['titulo'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $data_desejada = $_POST['data_desejada'] ?? null;
    $tarefa_referencia = (int)($_POST['tarefa_referencia'] ?? 0);
    
    // Validações
    if (empty($tipo_solicitacao)) {
        $mensagem_erro = "Selecione o tipo de solicitação.";
    } elseif ($disciplina_id <= 0) {
        $mensagem_erro = "Selecione uma disciplina.";
    } elseif ($professor_id <= 0) {
        $mensagem_erro = "Selecione um professor.";
    } elseif (empty($titulo)) {
        $mensagem_erro = "Informe o título da solicitação.";
    } elseif (empty($descricao)) {
        $mensagem_erro = "Descreva detalhadamente sua solicitação.";
    } else {
        // Inserir solicitação
        $sql_insert = "INSERT INTO solicitacoes_tarefas 
                       (escola_id, aluno_id, disciplina_id, professor_id, tipo_solicitacao, 
                        titulo, descricao, data_solicitacao, data_desejada, tarefa_referencia, status)
                       VALUES 
                       (:escola_id, :aluno_id, :disciplina_id, :professor_id, :tipo_solicitacao,
                        :titulo, :descricao, NOW(), :data_desejada, :tarefa_referencia, 'pendente')";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $result = $stmt_insert->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':disciplina_id' => $disciplina_id,
            ':professor_id' => $professor_id,
            ':tipo_solicitacao' => $tipo_solicitacao,
            ':titulo' => $titulo,
            ':descricao' => $descricao,
            ':data_desejada' => $data_desejada,
            ':tarefa_referencia' => $tarefa_referencia ?: null
        ]);
        
        if ($result) {
            $mensagem_sucesso = "Solicitação enviada com sucesso! O professor será notificado.";
            
            // Limpar formulário
            $_POST = [];
        } else {
            $mensagem_erro = "Erro ao enviar solicitação. Tente novamente.";
        }
    }
}

// Buscar tarefas anteriores do aluno (para referência)
$sql_tarefas_anteriores = "SELECT t.id, t.titulo, d.nome as disciplina_nome, 
                                   r.status, r.nota, r.comentario_professor
                            FROM tarefas t
                            JOIN disciplinas d ON d.id = t.disciplina_id
                            LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = :aluno_id
                            WHERE t.turma_id IN (SELECT turma_id FROM matriculas WHERE estudante_id = :alunos_id AND status = 'ativa')
                            AND t.status = 'publicada'
                            AND (r.status = 'corrigido' OR r.status IS NULL)
                            ORDER BY t.data_entrega DESC
                            LIMIT 20";
$stmt_tarefas_anteriores = $conn->prepare($sql_tarefas_anteriores);
$stmt_tarefas_anteriores->execute([
    ':aluno_id' => $aluno_id,
    ':alunos_id' => $aluno_id
]);
$tarefas_anteriores = $stmt_tarefas_anteriores->fetchAll(PDO::FETCH_ASSOC);

// Buscar solicitações anteriores do aluno
$sql_solicitacoes = "SELECT s.*, d.nome as disciplina_nome, p.nome as professor_nome
                     FROM solicitacoes_tarefas s
                     LEFT JOIN disciplinas d ON d.id = s.disciplina_id
                     LEFT JOIN funcionarios p ON p.id = s.professor_id
                     WHERE s.aluno_id = :aluno_id AND s.escola_id = :escola_id
                     ORDER BY s.data_solicitacao DESC
                     LIMIT 10";
$stmt_solicitacoes = $conn->prepare($sql_solicitacoes);
$stmt_solicitacoes->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$solicitacoes = $stmt_solicitacoes->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Tarefa | Área do Aluno</title>
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
        
        .solicitacao-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .solicitacao-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-pendente { background: #ffc107; color: #000; }
        .status-aprovado { background: #28a745; color: #fff; }
        .status-rejeitado { background: #dc3545; color: #fff; }
        .status-concluido { background: #17a2b8; color: #fff; }
        
        .required:after {
            content: " *";
            color: red;
        }
        
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .tipo-solicitacao-box {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .tipo-solicitacao-box:hover {
            border-color: #006B3E;
            background: #f0fdf4;
        }
        .tipo-solicitacao-box.selected {
            border-color: #006B3E;
            background: #e8f5e9;
        }
        .tipo-solicitacao-box i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-envelope-open-text"></i> Solicitar Tarefa</h2>
                <p class="text-muted">Solicite tarefas de recuperação, atividades complementares ou segunda chamada</p>
            </div>
            <div>
                <button class="btn btn-outline-primary" onclick="window.location.href='minhas_tarefas.php'">
                    <i class="fas fa-arrow-left"></i> Voltar
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
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-pen-alt"></i> Nova Solicitação</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formSolicitacao">
                            <!-- Tipo de Solicitação -->
                            <div class="form-section">
                                <label class="form-label fw-bold required">Tipo de Solicitação</label>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <div class="tipo-solicitacao-box" data-tipo="recuperacao" onclick="selectTipo('recuperacao')">
                                            <i class="fas fa-sync-alt text-warning"></i>
                                            <h6>Recuperação</h6>
                                            <small class="text-muted">Nota abaixo da média</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="tipo-solicitacao-box" data-tipo="complementar" onclick="selectTipo('complementar')">
                                            <i class="fas fa-plus-circle text-info"></i>
                                            <h6>Complementar</h6>
                                            <small class="text-muted">Atividade extra</small>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="tipo-solicitacao-box" data-tipo="segunda_chamada" onclick="selectTipo('segunda_chamada')">
                                            <i class="fas fa-calendar-alt text-danger"></i>
                                            <h6>Segunda Chamada</h6>
                                            <small class="text-muted">Faltou à avaliação</small>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="tipo_solicitacao" id="tipo_solicitacao" required>
                            </div>
                            
                            <!-- Dados da Solicitação -->
                            <div class="form-section">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold required">Disciplina</label>
                                        <select class="form-select" name="disciplina_id" id="disciplina_id" required onchange="carregarProfessores()">
                                            <option value="">Selecione a disciplina</option>
                                            <?php foreach ($disciplinas as $disciplina): ?>
                                            <option value="<?php echo $disciplina['id']; ?>" data-cor="<?php echo $disciplina['cor'] ?? '#006B3E'; ?>">
                                                <?php echo htmlspecialchars($disciplina['nome']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold required">Professor</label>
                                        <select class="form-select" name="professor_id" id="professor_id" required>
                                            <option value="">Primeiro selecione a disciplina</option>
                                        </select>
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label fw-bold required">Título da Solicitação</label>
                                        <input type="text" class="form-control" name="titulo" required 
                                               placeholder="Ex: Solicitação de Recuperação - Matemática">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label class="form-label fw-bold required">Descrição Detalhada</label>
                                        <textarea class="form-control" name="descricao" rows="5" required
                                                  placeholder="Descreva detalhadamente o motivo da solicitação, a matéria específica, e qualquer informação relevante..."></textarea>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Data Desejada para Entrega</label>
                                        <input type="date" class="form-control" name="data_desejada" 
                                               min="<?php echo date('Y-m-d', strtotime('+2 days')); ?>"
                                               max="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                        <small class="text-muted">Deixe em branco para o professor definir</small>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label fw-bold">Tarefa de Referência (opcional)</label>
                                        <select class="form-select" name="tarefa_referencia" id="tarefa_referencia">
                                            <option value="">Selecione uma tarefa anterior</option>
                                            <?php foreach ($tarefas_anteriores as $tarefa): ?>
                                            <option value="<?php echo $tarefa['id']; ?>">
                                                <?php echo htmlspecialchars($tarefa['disciplina_nome'] . ' - ' . $tarefa['titulo']); ?>
                                                <?php if ($tarefa['nota'] !== null): ?>
                                                (Nota: <?php echo number_format($tarefa['nota'], 1); ?>)
                                                <?php endif; ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <small class="text-muted">Selecione a tarefa relacionada a esta solicitação</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-paper-plane"></i> Enviar Solicitação
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Informações e Histórico -->
            <div class="col-lg-5">
                <!-- Informações do Aluno -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-graduate"></i> Seus Dados</h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Nome:</strong> <?php echo htmlspecialchars($aluno['nome']); ?></p>
                        <p><strong>Matrícula:</strong> <?php echo $aluno['matricula']; ?></p>
                        <p><strong>Turma:</strong> <?php echo $aluno['turma_ano'] . 'ª ' . ($aluno['turma_nome'] ?? ''); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($aluno['email'] ?? 'Não informado'); ?></p>
                    </div>
                </div>
                
                <!-- Instruções -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle"></i> Instruções</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled">
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Seja claro e específico na descrição</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Informe o motivo da solicitação</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Aguarde o retorno do professor</li>
                            <li class="mb-2"><i class="fas fa-check-circle text-success"></i> Acompanhe o status da sua solicitação</li>
                            <li class="mb-2"><i class="fas fa-clock text-warning"></i> O prazo de resposta é de até 5 dias úteis</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Histórico de Solicitações -->
        <?php if (!empty($solicitacoes)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Minhas Solicitações</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Disciplina</th>
                                <th>Título</th>
                                <th>Status</th>
                                <th>Resposta</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($solicitacoes as $solicitacao): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($solicitacao['data_solicitacao'])); ?></td>
                                <td>
                                    <?php
                                    $tipos = [
                                        'recuperacao' => 'Recuperação',
                                        'complementar' => 'Complementar',
                                        'segunda_chamada' => '2ª Chamada'
                                    ];
                                    echo $tipos[$solicitacao['tipo_solicitacao']] ?? $solicitacao['tipo_solicitacao'];
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($solicitacao['disciplina_nome']); ?></td>
                                <td><?php echo htmlspecialchars(substr($solicitacao['titulo'], 0, 40)) . (strlen($solicitacao['titulo']) > 40 ? '...' : ''); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $solicitacao['status']; ?>">
                                        <?php echo ucfirst($solicitacao['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($solicitacao['resposta_professor']): ?>
                                    <button class="btn btn-sm btn-info" onclick="verResposta(<?php echo $solicitacao['id']; ?>, '<?php echo addslashes($solicitacao['resposta_professor']); ?>')">
                                        <i class="fas fa-comment-dots"></i> Ver
                                    </button>
                                    <?php else: ?>
                                    <span class="text-muted">Aguardando</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Visualizar Resposta -->
    <div class="modal fade" id="respostaModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-comment-dots"></i> Resposta do Professor</h5>
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
        
        // Selecionar tipo de solicitação
        function selectTipo(tipo) {
            document.querySelectorAll('.tipo-solicitacao-box').forEach(box => {
                box.classList.remove('selected');
            });
            document.querySelector(`.tipo-solicitacao-box[data-tipo="${tipo}"]`).classList.add('selected');
            document.getElementById('tipo_solicitacao').value = tipo;
            
            // Atualizar título sugerido
            let tituloSugerido = '';
            switch(tipo) {
                case 'recuperacao':
                    tituloSugerido = 'Solicitação de Recuperação';
                    break;
                case 'complementar':
                    tituloSugerido = 'Solicitação de Atividade Complementar';
                    break;
                case 'segunda_chamada':
                    tituloSugerido = 'Solicitação de Segunda Chamada';
                    break;
            }
            
            let disciplina = document.getElementById('disciplina_id').options[document.getElementById('disciplina_id').selectedIndex]?.text;
            if (disciplina && disciplina !== 'Selecione a disciplina') {
                tituloSugerido += ' - ' + disciplina;
                document.querySelector('input[name="titulo"]').value = tituloSugerido;
            }
        }
        
        // Carregar professores por disciplina
        const professoresPorDisciplina = <?php echo json_encode($professores_por_disciplina); ?>;
        
        function carregarProfessores() {
            let disciplinaId = document.getElementById('disciplina_id').value;
            let professorSelect = document.getElementById('professor_id');
            
            professorSelect.innerHTML = '<option value="">Selecione o professor</option>';
            
            if (disciplinaId && professoresPorDisciplina[disciplinaId]) {
                professoresPorDisciplina[disciplinaId].forEach(prof => {
                    let option = document.createElement('option');
                    option.value = prof.id;
                    option.textContent = prof.nome;
                    professorSelect.appendChild(option);
                });
            }
        }
        
        // Atualizar título quando disciplina mudar
        document.getElementById('disciplina_id').addEventListener('change', function() {
            let disciplina = this.options[this.selectedIndex]?.text;
            let tipo = document.getElementById('tipo_solicitacao').value;
            if (tipo && disciplina && disciplina !== 'Selecione a disciplina') {
                let tituloBase = '';
                switch(tipo) {
                    case 'recuperacao': tituloBase = 'Solicitação de Recuperação'; break;
                    case 'complementar': tituloBase = 'Solicitação de Atividade Complementar'; break;
                    case 'segunda_chamada': tituloBase = 'Solicitação de Segunda Chamada'; break;
                }
                document.querySelector('input[name="titulo"]').value = tituloBase + ' - ' + disciplina;
            }
        });
        
        // Ver resposta do professor
        function verResposta(id, resposta) {
            document.getElementById('respostaConteudo').innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Resposta do professor:
                </div>
                <p>${resposta.replace(/\n/g, '<br>')}</p>
            `;
            new bootstrap.Modal(document.getElementById('respostaModal')).show();
        }
        
        // Validar formulário
        document.getElementById('formSolicitacao').addEventListener('submit', function(e) {
            let tipo = document.getElementById('tipo_solicitacao').value;
            if (!tipo) {
                e.preventDefault();
                alert('Selecione o tipo de solicitação');
                return false;
            }
            return true;
        });
    </script>
</body>
</html>