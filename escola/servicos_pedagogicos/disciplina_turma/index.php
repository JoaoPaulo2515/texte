<?php
// escola/servicos_pedagogicos/disciplina_turma/index.php - Relacionamento Disciplina-Turma
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo_atual = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_valor = $ano_letivo_atual['ano'] ?? date('Y') . '/' . (date('Y') + 1);
$ano_letivo_id = $ano_letivo_atual['id'] ?? 1;

// ============================================
// INCLUIR MENU
// ============================================
include __DIR__ . '/../../menu_escola.php';

// ============================================
// PROCESSAR AÇÕES (usando professor_disciplina_turma)
// ============================================

// Relacionar individual
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_relacao') {
    $disciplina_id = (int)$_POST['disciplina_id'];
    $turma_id = (int)$_POST['turma_id'];
    $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
    $carga_horaria = (int)$_POST['carga_horaria'];
    $periodo = $_POST['periodo'];
    
    // Verificar se já existe relação
    $check = $conn->prepare("
        SELECT id FROM professor_disciplina_turma 
        WHERE disciplina_id = :disciplina_id 
        AND turma_id = :turma_id 
        AND ano_letivo_id = :ano_letivo_id
    ");
    $check->execute([
        ':disciplina_id' => $disciplina_id,
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    
    if ($check->rowCount() > 0) {
        $_SESSION['mensagem'] = "Esta relação já existe!";
        $_SESSION['mensagem_tipo'] = "danger";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO professor_disciplina_turma (professor_id, disciplina_id, turma_id, ano_letivo_id, carga_horaria, created_at)
            VALUES (:professor_id, :disciplina_id, :turma_id, :ano_letivo_id, :carga_horaria, NOW())
        ");
        $stmt->execute([
            ':professor_id' => $professor_id,
            ':disciplina_id' => $disciplina_id,
            ':turma_id' => $turma_id,
            ':ano_letivo_id' => $ano_letivo_id,
            ':carga_horaria' => $carga_horaria
        ]);
        $_SESSION['mensagem'] = "Relação criada com sucesso!";
        $_SESSION['mensagem_tipo'] = "success";
    }
    
    header("Location: index.php");
    exit;
}

// Editar relação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_relacao') {
    $id = (int)$_POST['id'];
    $professor_id = !empty($_POST['professor_id']) ? (int)$_POST['professor_id'] : null;
    $carga_horaria = (int)$_POST['carga_horaria'];
    
    $stmt = $conn->prepare("
        UPDATE professor_disciplina_turma 
        SET professor_id = :professor_id, carga_horaria = :carga_horaria
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id,
        ':professor_id' => $professor_id,
        ':carga_horaria' => $carga_horaria
    ]);
    
    $_SESSION['mensagem'] = "Relação atualizada com sucesso!";
    $_SESSION['mensagem_tipo'] = "success";
    header("Location: index.php");
    exit;
}

// Remover relação
if (isset($_GET['remove']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM professor_disciplina_turma WHERE id = :id");
    $stmt->execute([':id' => $id]);
    
    $_SESSION['mensagem'] = "Relação removida com sucesso!";
    $_SESSION['mensagem_tipo'] = "success";
    header("Location: index.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar disciplinas
$disciplinas = $conn->prepare("
    SELECT id, nome, codigo, carga_horaria as ch_padrao 
    FROM disciplinas 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY nome
");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas
$turmas = $conn->prepare("
    SELECT id, nome, ano, turno 
    FROM turmas 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY ano, nome
");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar professores da tabela funcionarios
$professores = $conn->prepare("
    SELECT f.id, f.nome 
    FROM funcionarios f
    WHERE f.escola_id = :escola_id 
    AND (f.cargo = 'Professor' OR f.tipo_funcionario = 'professor')
    AND f.status = 'ativo'
    ORDER BY f.nome
");
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar relações existentes (usando professor_disciplina_turma)
$relacoes = $conn->prepare("
    SELECT pdt.*, 
           d.nome as disciplina_nome, d.codigo as disciplina_codigo,
           t.nome as turma_nome, t.ano as turma_ano,
           f.nome as professor_nome,
           al.ano as ano_letivo_nome
    FROM professor_disciplina_turma pdt
    JOIN disciplinas d ON d.id = pdt.disciplina_id
    JOIN turmas t ON t.id = pdt.turma_id
    LEFT JOIN funcionarios f ON f.id = pdt.professor_id
    LEFT JOIN ano_letivo al ON al.id = pdt.ano_letivo_id
    WHERE d.escola_id = :escola_id
    ORDER BY t.ano, t.nome, d.nome
");
$relacoes->execute([':escola_id' => $escola_id]);
$relacoes = $relacoes->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_relacoes = count($relacoes);
$total_disciplinas_associadas = count(array_unique(array_column($relacoes, 'disciplina_id')));
$total_turmas_associadas = count(array_unique(array_column($relacoes, 'turma_id')));

$mensagem = $_SESSION['mensagem'] ?? '';
$mensagem_tipo = $_SESSION['mensagem_tipo'] ?? 'success';
unset($_SESSION['mensagem']);
unset($_SESSION['mensagem_tipo']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disciplina e Turma | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .top-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .card {
            background: white;
            border-radius: 15px;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .card-header {
            background: white;
            border-bottom: 1px solid #eee;
            padding: 15px 20px;
            font-weight: bold;
            border-radius: 15px 15px 0 0;
        }
        
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        
        .btn-primary:hover {
            background: #004d2d;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-value {
            font-size: 2em;
            font-weight: bold;
            color: #006B3E;
        }
        
        .badge-ativo {
            background: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .badge-inativo {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 10px;
            border-radius: 20px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .btn-sm {
            margin: 2px;
        }
    </style>
</head>
<body>
    <!-- O menu_escola.php já inclui o sidebar -->
    
      <?php include __DIR__ . '/../../menu_escola.php'; ?>
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-link"></i> Relacionamento Disciplina - Turma</h2>
            <div>
                <a href="relacionar_massa.php" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-layer-group"></i> Relacionar em Massa
                </a>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalNovaRelacao">
                    <i class="fas fa-plus"></i> Nova Relação
                </button>
            </div>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-<?php echo $mensagem_tipo; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-<?php echo $mensagem_tipo == 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($mensagem); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_relacoes; ?></div>
                <div><i class="fas fa-link"></i> Total de Relações</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_disciplinas_associadas; ?></div>
                <div><i class="fas fa-book"></i> Disciplinas Associadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_turmas_associadas; ?></div>
                <div><i class="fas fa-users-group"></i> Turmas Associadas</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo count($professores); ?></div>
                <div><i class="fas fa-chalkboard-user"></i> Professores</div>
            </div>
        </div>
        
        <!-- Lista de Relações -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Relações Disciplina - Turma
                <span class="badge bg-secondary float-end">Total: <?php echo $total_relacoes; ?></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaRelacoes">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">ID</th>
                                <th width="10%">Código</th>
                                <th width="20%">Disciplina</th>
                                <th width="15%">Turma</th>
                                <th width="10%">Classe</th>
                                <th width="15%">Professor</th>
                                <th width="10%">Carga Horária</th>
                                <th width="10%">Ano Letivo</th>
                                <th width="10%">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($relacoes)): ?>
                                <?php foreach ($relacoes as $rel): ?>
                                <tr>
                                    <td><?php echo $rel['id']; ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($rel['disciplina_codigo']); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars($rel['disciplina_nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($rel['turma_nome']); ?></td>
                                    <td><?php echo $rel['turma_ano']; ?>ª Classe</div>
                                    <td>
                                        <?php if ($rel['professor_nome']): ?>
                                            <span class="badge bg-info"><?php echo htmlspecialchars($rel['professor_nome']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted"><i class="fas fa-user-slash"></i> Não atribuído</span>
                                        <?php endif; ?>
                                     </div>
                                    <td><span class="badge bg-primary"><?php echo $rel['carga_horaria']; ?>h/semana</span></div>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($rel['ano_letivo_nome']); ?></span></div>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info" onclick="editarRelacao(<?php echo $rel['id']; ?>, <?php echo $rel['professor_id'] ?: 'null'; ?>, <?php echo $rel['carga_horaria']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?remove=1&id=<?php echo $rel['id']; ?>" class="btn btn-danger" onclick="return confirm('Tem certeza que deseja remover esta relação?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                     </div>
                                 </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="fas fa-info-circle fa-2x text-muted mb-2 d-block"></i>
                                        Nenhuma relação encontrada. Clique em "Nova Relação" para começar.
                                     </div>
                                 </div>
                            <?php endif; ?>
                        </tbody>
                     </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Relação -->
    <div class="modal fade" id="modalNovaRelacao" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Relação Disciplina - Turma</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_relacao">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Disciplina *</label>
                                <select name="disciplina_id" class="form-select" required>
                                    <option value="">Selecione uma disciplina...</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>">
                                        <?php echo htmlspecialchars($d['codigo']); ?> - <?php echo htmlspecialchars($d['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Turma *</label>
                                <select name="turma_id" class="form-select" required>
                                    <option value="">Selecione uma turma...</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>">
                                        <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Professor</label>
                                <select name="professor_id" class="form-select">
                                    <option value="">Selecione um professor...</option>
                                    <?php foreach ($professores as $p): ?>
                                    <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Opcional - pode ser atribuído depois</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Carga Horária (horas/semana) *</label>
                                <input type="number" name="carga_horaria" class="form-control" value="4" min="1" max="20" required>
                            </div>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Informação:</strong> Esta relação define qual disciplina será ministrada em qual turma no ano letivo atual.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Relação</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Relação -->
    <div class="modal fade" id="modalEditarRelacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Relação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="edit_relacao">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Professor</label>
                            <select name="professor_id" id="edit_professor_id" class="form-select">
                                <option value="">Selecione um professor...</option>
                                <?php foreach ($professores as $p): ?>
                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Carga Horária (horas/semana)</label>
                            <input type="number" name="carga_horaria" id="edit_carga_horaria" class="form-control" min="1" max="20" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            A disciplina, turma e ano letivo não podem ser alterados. Para mudar, remova esta relação e crie uma nova.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            var $table = $('#tabelaRelacoes');
            var hasDataRows = $table.find('tbody tr:not(:has(td[colspan]))').length > 0;

            if (hasDataRows) {
                try {
                    $table.DataTable({
                        language: {
                            url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                        },
                        pageLength: 25,
                        order: [[0, 'desc']],
                        responsive: true
                    });
                } catch (e) {
                    console.error('Erro ao inicializar DataTables:', e);
                    $table.addClass('table-bordered');
                }
            } else {
                $table.addClass('table-bordered');
            }
        });
        
        function editarRelacao(id, professorId, cargaHoraria) {
            $('#edit_id').val(id);
            $('#edit_professor_id').val(professorId);
            $('#edit_carga_horaria').val(cargaHoraria);
            $('#modalEditarRelacao').modal('show');
        }
    </script>
</body>
</html>