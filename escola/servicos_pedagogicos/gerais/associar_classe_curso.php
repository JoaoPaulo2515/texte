<?php
// escola/servicos_pedagogicos/gerais/associar_classe_curso.php - Associação Classe-Curso
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
// INCLUIR MENU
// ============================================
//include __DIR__ . '/../../menu_escola.php';

// ============================================
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Verificar se a tabela classes existe
$check = $conn->query("SHOW TABLES LIKE 'classes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE classes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            codigo VARCHAR(50),
            ordem INT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'ativa',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Verificar se a tabela cursos existe
$check = $conn->query("SHOW TABLES LIKE 'cursos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE cursos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            nome VARCHAR(100) NOT NULL,
            codigo VARCHAR(50),
            duracao_anos INT DEFAULT 3,
            status VARCHAR(20) DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// Verificar e criar tabela classe_curso
$check = $conn->query("SHOW TABLES LIKE 'classe_curso'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE classe_curso (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            classe_id INT NOT NULL,
            curso_id INT NOT NULL,
            status VARCHAR(20) DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_associacao (classe_id, curso_id),
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (classe_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao == 'add_associacao') {
        $classe_id = (int)$_POST['classe_id'];
        $curso_id = (int)$_POST['curso_id'];
        
        if ($classe_id > 0 && $curso_id > 0) {
            $stmt = $conn->prepare("INSERT IGNORE INTO classe_curso (escola_id, classe_id, curso_id, status) VALUES (:escola_id, :classe_id, :curso_id, 'ativo')");
            $stmt->execute([':escola_id' => $escola_id, ':classe_id' => $classe_id, ':curso_id' => $curso_id]);
            $_SESSION['mensagem'] = "Associação criada com sucesso!";
            $_SESSION['mensagem_tipo'] = "success";
        }
        header("Location: associar_classe_curso.php");
        exit;
    }
    
    if ($acao == 'associar_massa') {
        $classe_id = (int)$_POST['classe_id'];
        $cursos = $_POST['cursos'] ?? [];
        
        if ($classe_id > 0 && !empty($cursos)) {
            $inseridos = 0;
            foreach ($cursos as $curso_id) {
                $stmt = $conn->prepare("INSERT IGNORE INTO classe_curso (escola_id, classe_id, curso_id, status) VALUES (:escola_id, :classe_id, :curso_id, 'ativo')");
                $stmt->execute([':escola_id' => $escola_id, ':classe_id' => $classe_id, ':curso_id' => (int)$curso_id]);
                if ($stmt->rowCount() > 0) $inseridos++;
            }
            $_SESSION['mensagem'] = "$inseridos curso(s) associado(s) com sucesso!";
            $_SESSION['mensagem_tipo'] = "success";
        } else {
            $_SESSION['mensagem'] = "Selecione uma classe e pelo menos um curso.";
            $_SESSION['mensagem_tipo'] = "danger";
        }
        header("Location: associar_classe_curso.php");
        exit;
    }
}

// ============================================
// PROCESSAR GET (REMOVER/TOGGLE)
// ============================================

// Remover associação
if (isset($_GET['remove']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $conn->prepare("DELETE FROM classe_curso WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Associação removida!";
    $_SESSION['mensagem_tipo'] = "success";
    header("Location: associar_classe_curso.php");
    exit;
}

// Ativar/Desativar
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $status = $_GET['status'] ?? 'ativo';
    $novo_status = ($status == 'ativo') ? 'inativo' : 'ativo';
    $stmt = $conn->prepare("UPDATE classe_curso SET status = :status WHERE id = :id AND escola_id = :escola_id");
    $stmt->execute([':status' => $novo_status, ':id' => $id, ':escola_id' => $escola_id]);
    $_SESSION['mensagem'] = "Status alterado para " . ($novo_status == 'ativo' ? 'Ativo' : 'Inativo') . "!";
    $_SESSION['mensagem_tipo'] = "success";
    header("Location: associar_classe_curso.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar classes
$sql_classes = "SELECT id, nome, ordem FROM classes WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ordem, nome";
$stmt = $conn->prepare($sql_classes);
$stmt->execute([':escola_id' => $escola_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar cursos - CORRIGIDO
$sql_cursos = "SELECT id, nome, codigo, duracao_anos FROM cursos WHERE escola_id = :escola_id AND status = 1 ORDER BY nome";
$stmt = $conn->prepare($sql_cursos);
$stmt->execute([':escola_id' => $escola_id]);
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar associações existentes
$sql_associacoes = "
    SELECT ac.*, 
           c.nome as classe_nome, 
           c.ordem as classe_codigo,
           cs.nome as curso_nome, 
           cs.codigo as curso_codigo
    FROM classe_curso ac
    JOIN classes c ON c.id = ac.classe_id
    JOIN cursos cs ON cs.id = ac.curso_id
    WHERE ac.escola_id = :escola_id
    ORDER BY c.ordem, c.nome, cs.nome
";
$stmt = $conn->prepare($sql_associacoes);
$stmt->execute([':escola_id' => $escola_id]);
$associacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Associar Classe-Curso | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Estilos que complementam o menu */
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
        
        .checkbox-group {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
        }
        
        .checkbox-item {
            margin-bottom: 8px;
            padding: 5px;
            border-radius: 5px;
            transition: background 0.2s;
        }
        
        .checkbox-item:hover {
            background: #f8f9fa;
        }
        
        .checkbox-item input {
            margin-right: 10px;
        }
        
        .btn-acao {
            margin: 2px;
            padding: 4px 10px;
        }
    </style>
</head>
<body>
    <!-- O menu_escola.php já inclui o sidebar e o botão toggle -->
     <?php include __DIR__ . '/../../menu_escola.php'; ?>
   
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-link"></i> Associar Classe - Curso</h2>
                <small class="text-muted">Relacione as classes com os cursos oferecidos</small>
            </div>
            <div>
                <button class="btn btn-primary btn-sm me-2" data-bs-toggle="modal" data-bs-target="#modalAssociar">
                    <i class="fas fa-plus"></i> Nova Associação
                </button>
                <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalAssociarMassa">
                    <i class="fas fa-layer-group"></i> Associar em Massa
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
        
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i> Associações Classe - Curso
                <span class="badge bg-secondary float-end">Total: <?php echo count($associacoes); ?></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="tabelaAssociacoes">
                        <thead class="table-light">
                            <tr>
                                <th width="5%">ID</th>
                                <th width="25%">Classe</th>
                                <th width="30%">Curso</th>
                                <th width="15%">Código</th>
                                <th width="10%">Status</th>
                                <th width="15%">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($associacoes)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">Nenhuma associação encontrada. Clique em "Nova Associação" para começar.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($associacoes as $assoc): ?>
                                    <tr>
                                        <td><?php echo $assoc['id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($assoc['classe_nome']); ?></strong> <?php echo $assoc['classe_codigo'] ? '(' . htmlspecialchars($assoc['classe_codigo']) . ')' : ''; ?></td>
                                        <td><?php echo htmlspecialchars($assoc['curso_nome']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($assoc['curso_codigo']); ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $assoc['status'] == 'ativo' ? 'badge-ativo' : 'badge-inativo'; ?>">
                                                <?php echo $assoc['status'] == 'ativo' ? 'Ativo' : 'Inativo'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="?toggle=1&id=<?php echo $assoc['id']; ?>&status=<?php echo $assoc['status']; ?>" class="btn btn-sm btn-warning btn-acao" title="<?php echo $assoc['status'] == 'ativo' ? 'Desativar' : 'Ativar'; ?>">
                                                <i class="fas fa-toggle-<?php echo $assoc['status'] == 'ativo' ? 'off' : 'on'; ?>"></i>
                                            </a>
                                            <a href="?remove=1&id=<?php echo $assoc['id']; ?>" class="btn btn-sm btn-danger btn-acao" onclick="return confirm('Tem certeza que deseja remover esta associação?')" title="Remover">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Associar (Individual) -->
    <div class="modal fade" id="modalAssociar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> Associar Classe a Curso</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="add_associacao">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Classe *</label>
                            <select name="classe_id" class="form-select" required>
                                <option value="">Selecione uma classe...</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?> <?php echo $c['ordem'] ? '(' . htmlspecialchars($c['ordem']) . ')' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Curso *</label>
                            <select name="curso_id" class="form-select" required>
                                <option value="">Selecione um curso...</option>
                                <?php foreach ($cursos as $cs): ?>
                                    <option value="<?php echo $cs['id']; ?>"><?php echo htmlspecialchars($cs['nome']); ?> (<?php echo htmlspecialchars($cs['codigo']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Associar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Associar em Massa -->
    <div class="modal fade" id="modalAssociarMassa" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-layer-group"></i> Associar em Massa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="associar_massa">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Selecione a Classe *</label>
                            <select name="classe_id" id="classe_massa" class="form-select" required>
                                <option value="">Selecione uma classe...</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?> <?php echo $c['ordem'] ? '(' . htmlspecialchars($c['ordem']) . ')' : ''; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Selecione os Cursos (pode selecionar vários) *</label>
                            <div class="checkbox-group">
                                <div class="mb-2">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selecionarTodos()">Selecionar Todos</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="deselecionarTodos()">Desmarcar Todos</button>
                                </div>
                                <?php foreach ($cursos as $cs): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="cursos[]" value="<?php echo $cs['id']; ?>" id="curso_<?php echo $cs['id']; ?>">
                                        <label for="curso_<?php echo $cs['id']; ?>">
                                            <strong><?php echo htmlspecialchars($cs['codigo']); ?></strong> - <?php echo htmlspecialchars($cs['nome']); ?>
                                            <small class="text-muted">(Duração: <?php echo $cs['duracao_anos']; ?> anos)</small>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <small class="text-muted mt-2 d-block">Selecione um ou vários cursos para associar à classe selecionada.</small>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Informação:</strong> Serão criadas associações para cada curso selecionado com a classe escolhida.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Associar em Massa</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Funções para o menu (já estão no menu_escola.php)
        // Funções adicionais para a página
        $(document).ready(function() {
            // Inicializar DataTable
            if ($('#tabelaAssociacoes tbody tr').length > 1 || ($('#tabelaAssociacoes tbody tr').length == 1 && $('#tabelaAssociacoes tbody tr td').attr('colspan') != 6)) {
                $('#tabelaAssociacoes').DataTable({
                    language: {
                        url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
                    },
                    pageLength: 25,
                    order: [[0, 'desc']]
                });
            }
        });
        
        function selecionarTodos() {
            $('.checkbox-item input[type="checkbox"]').prop('checked', true);
        }
        
        function deselecionarTodos() {
            $('.checkbox-item input[type="checkbox"]').prop('checked', false);
        }
        
        // Garantir que o menu toggle funcione
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
    </script>
</body>
</html>