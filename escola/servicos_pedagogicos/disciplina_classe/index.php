<?php
// escola/servicos_pedagogicos/disciplina_classe/index.php - Relacionamento Disciplina-Classe
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
// VERIFICAR E CRIAR TABELA
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'disciplina_classe'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE disciplina_classe (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            classe_id INT NOT NULL,
            carga_horaria INT DEFAULT 0,
            ano_letivo VARCHAR(9),
            periodo VARCHAR(20),
            status ENUM('ativo', 'inativo') DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id) ON DELETE CASCADE,
            FOREIGN KEY (classe_id) REFERENCES classes(id) ON DELETE CASCADE,
            UNIQUE KEY unique_relacao (disciplina_id, classe_id, ano_letivo)
        )
    ");
}

// ============================================
// PROCESSAR AÇÕES
// ============================================

// Relacionar individual
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'add_relacao') {
    $disciplina_id = $_POST['disciplina_id'];
    $classe_id = $_POST['classe_id'];
    $carga_horaria = $_POST['carga_horaria'];
    $ano_letivo = $_POST['ano_letivo'];
    $periodo = $_POST['periodo'];
    
    $stmt = $conn->prepare("
        INSERT INTO disciplina_classe (escola_id, disciplina_id, classe_id, carga_horaria, ano_letivo, periodo, status)
        VALUES (:escola_id, :disciplina_id, :classe_id, :carga_horaria, :ano_letivo, :periodo, 'ativo')
    ");
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':disciplina_id' => $disciplina_id,
        ':classe_id' => $classe_id,
        ':carga_horaria' => $carga_horaria,
        ':ano_letivo' => $ano_letivo,
        ':periodo' => $periodo
    ]);
    
    $_SESSION['mensagem'] = "Relação criada com sucesso!";
    header("Location: index.php");
    exit;
}

// Editar relação
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'edit_relacao') {
    $id = $_POST['id'];
    $carga_horaria = $_POST['carga_horaria'];
    $periodo = $_POST['periodo'];
    
    $stmt = $conn->prepare("
        UPDATE disciplina_classe 
        SET carga_horaria = :carga_horaria, periodo = :periodo
        WHERE id = :id AND escola_id = :escola_id
    ");
    $stmt->execute([
        ':id' => $id,
        ':escola_id' => $escola_id,
        ':carga_horaria' => $carga_horaria,
        ':periodo' => $periodo
    ]);
    
    $_SESSION['mensagem'] = "Relação atualizada com sucesso!";
    header("Location: index.php");
    exit;
}

// Ativar/Desativar relação
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    
    $stmt = $conn->prepare("UPDATE disciplina_classe SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Status alterado!";
    header("Location: index.php");
    exit;
}

// Remover relação
if (isset($_GET['remove']) && isset($_GET['id'])) {
    $id = $_GET['id'];
    
    $stmt = $conn->prepare("DELETE FROM disciplina_classe WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    
    $_SESSION['mensagem'] = "Relação removida com sucesso!";
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

// Buscar classes
$classes = $conn->prepare("
    SELECT id, nome, ordem 
    FROM classes 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY ordem, nome
");
$classes->execute([':escola_id' => $escola_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);

// Buscar relações existentes
$relacoes = $conn->prepare("
    SELECT dc.*, 
           d.nome as disciplina_nome, d.codigo as disciplina_codigo,
           c.nome as classe_nome, c.ordem as classe_ordem
    FROM disciplina_classe dc
    JOIN disciplinas d ON d.id = dc.disciplina_id
    JOIN classes c ON c.id = dc.classe_id
    WHERE dc.escola_id = :escola_id
    ORDER BY c.ordem, c.nome, d.nome
");
$relacoes->execute([':escola_id' => $escola_id]);
$relacoes = $relacoes->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_relacoes = count($relacoes);
$total_disciplinas_associadas = count(array_unique(array_column($relacoes, 'disciplina_id')));
$total_classes_associadas = count(array_unique(array_column($relacoes, 'classe_id')));

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disciplina e Classe | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .badge-ativo { background: #d4edda; color: #155724; }
        .badge-inativo { background: #f8d7da; color: #721c24; }
        .table-responsive { overflow-x: auto; }
        
        .info-box {
            background: #e8f5e9;
            border-left: 4px solid #006B3E;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-layer-group"></i> Relacionamento Disciplina - Classe</h2>
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
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Informação -->
        <div class="info-box">
            <i class="fas fa-info-circle"></i> 
            <strong>O que é este módulo?</strong> Aqui você define quais disciplinas são ministradas em cada classe/ano escolar.
            Por exemplo: A disciplina de Matemática é ministrada na 1ª Classe, 2ª Classe, etc.
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $total_relacoes; ?></div><div>Total de Relações</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $total_disciplinas_associadas; ?></div><div>Disciplinas Associadas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $total_classes_associadas; ?></div><div>Classes Associadas</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo count($disciplinas); ?></div><div>Disciplinas Ativas</div></div>
        </div>
        
        <!-- Lista de Relações -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> Relações Disciplina - Classe</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaRelacoes">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Código</th>
                                <th>Disciplina</th>
                                <th>Classe</th>
                                <th>Ordem</th>
                                <th>Carga Horária</th>
                                <th>Período</th>
                                <th>Ano Letivo</th>
                                <th>Status</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($relacoes)): ?>
                                <?php foreach ($relacoes as $rel): ?>
                                <tr>
                                    <td><?php echo $rel['id']; ?></td>
                                    <td><?php echo htmlspecialchars($rel['disciplina_codigo']); ?></td>
                                    <td><strong><?php echo htmlspecialchars($rel['disciplina_nome']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($rel['classe_nome']); ?></td>
                                    <td><?php echo $rel['classe_ordem']; ?></td>
                                    <td><?php echo $rel['carga_horaria']; ?>h</div>
                                    <td><?php echo htmlspecialchars($rel['periodo']); ?></td>
                                    <td><?php echo htmlspecialchars($rel['ano_letivo']); ?></td>
                                    <td><span class="badge <?php echo $rel['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>"><?php echo $rel['status']; ?></span></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button class="btn btn-info" onclick="editarRelacao(<?php echo $rel['id']; ?>, <?php echo $rel['carga_horaria']; ?>, '<?php echo addslashes($rel['periodo']); ?>')">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <a href="?toggle=1&id=<?php echo $rel['id']; ?>&status=<?php echo $rel['status']; ?>" class="btn btn-success">
                                                <i class="fas fa-toggle-<?php echo $rel['status'] == 'ativo' ? 'off' : 'on'; ?>"></i>
                                            </a>
                                            <a href="?remove=1&id=<?php echo $rel['id']; ?>" class="btn btn-danger" onclick="return confirm('Remover esta relação?')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </div>
                                     </div>
                                 </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-4">
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
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Nova Relação Disciplina - Classe</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_relacao">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Disciplina</label>
                                <select name="disciplina_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>">
                                        <?php echo htmlspecialchars($d['codigo']); ?> - <?php echo htmlspecialchars($d['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Classe</label>
                                <select name="classe_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Carga Horária (horas/semana)</label>
                                <input type="number" name="carga_horaria" class="form-control" value="4" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Período</label>
                                <select name="periodo" class="form-control" required>
                                    <option value="1º Bimestre">1º Bimestre</option>
                                    <option value="2º Bimestre">2º Bimestre</option>
                                    <option value="3º Bimestre">3º Bimestre</option>
                                    <option value="4º Bimestre">4º Bimestre</option>
                                    <option value="Anual">Anual</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label>Ano Letivo</label>
                            <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Esta relação define quais disciplinas são lecionadas em cada classe/ano escolar.
                            Todas as turmas pertencentes a esta classe herdarão estas disciplinas.
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
                            <label>Carga Horária (horas/semana)</label>
                            <input type="number" name="carga_horaria" id="edit_carga_horaria" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label>Período</label>
                            <select name="periodo" id="edit_periodo" class="form-control" required>
                                <option value="1º Bimestre">1º Bimestre</option>
                                <option value="2º Bimestre">2º Bimestre</option>
                                <option value="3º Bimestre">3º Bimestre</option>
                                <option value="4º Bimestre">4º Bimestre</option>
                                <option value="Anual">Anual</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        function editarRelacao(id, cargaHoraria, periodo) {
            $('#edit_id').val(id);
            $('#edit_carga_horaria').val(cargaHoraria);
            $('#edit_periodo').val(periodo);
            $('#modalEditarRelacao').modal('show');
        }
        
        // Inicialização do DataTables condicional e segura
        $(document).ready(function() {
            var $table = $('#tabelaRelacoes');
            // Verifica se existem linhas de dados reais (linhas sem colspan)
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
                // Aplica apenas o estilo básico quando não há dados
                $table.addClass('table-bordered');
            }
        });
    </script>
</body>
</html>