<?php
// escola/professor/atividades.php - Gerenciar Atividades do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// Processar formulário de cadastro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $turma_id = $_POST['turma_id'] ?? 0;
    $disciplina_id = $_POST['disciplina_id'] ?? 0;
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $data_entrega = $_POST['data_entrega'] ?? null;
    $tipo = $_POST['tipo'] ?? 'dever';
    
    if ($turma_id && $disciplina_id && $titulo) {
        if ($_POST['acao'] === 'adicionar') {
            $sql = "INSERT INTO atividades (professor_id, turma_id, disciplina_id, titulo, descricao, data_entrega, tipo) 
                    VALUES (:professor_id, :turma_id, :disciplina_id, :titulo, :descricao, :data_entrega, :tipo)";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':professor_id' => $professor_id,
                ':turma_id' => $turma_id,
                ':disciplina_id' => $disciplina_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':data_entrega' => $data_entrega,
                ':tipo' => $tipo
            ]);
        } elseif ($_POST['acao'] === 'editar' && isset($_POST['atividade_id'])) {
            $sql = "UPDATE atividades SET turma_id = :turma_id, disciplina_id = :disciplina_id, titulo = :titulo, 
                    descricao = :descricao, data_entrega = :data_entrega, tipo = :tipo WHERE id = :id AND professor_id = :professor_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $_POST['atividade_id'],
                ':professor_id' => $professor_id,
                ':turma_id' => $turma_id,
                ':disciplina_id' => $disciplina_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':data_entrega' => $data_entrega,
                ':tipo' => $tipo
            ]);
        }
    }
    header("Location: atividades.php");
    exit;
}

// Excluir atividade
if (isset($_GET['excluir'])) {
    $id = (int)$_GET['excluir'];
    $sql = "DELETE FROM atividades WHERE id = :id AND professor_id = :professor_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
    header("Location: atividades.php");
    exit;
}

// Buscar turmas do professor
$sql_turmas = "SELECT DISTINCT t.id, t.nome, t.ano FROM professor_disciplina_turma pdt 
               JOIN turmas t ON t.id = pdt.turma_id WHERE pdt.professor_id = :professor_id ORDER BY t.ano, t.nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll();

// Buscar disciplinas do professor
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome FROM professor_disciplina_turma pdt 
                    JOIN disciplinas d ON d.id = pdt.disciplina_id WHERE pdt.professor_id = :professor_id ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll();

// Buscar atividades com filtros
$sql_atividades = "SELECT a.*, t.nome as turma_nome, t.ano as turma_ano, d.nome as disciplina_nome 
                   FROM atividades a
                   JOIN turmas t ON t.id = a.turma_id
                   JOIN disciplinas d ON d.id = a.disciplina_id
                   WHERE a.professor_id = :professor_id";
$params = [':professor_id' => $professor_id];

if (!empty($_GET['turma_id'])) {
    $sql_atividades .= " AND a.turma_id = :turma_id";
    $params[':turma_id'] = $_GET['turma_id'];
}
if (!empty($_GET['disciplina_id'])) {
    $sql_atividades .= " AND a.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $_GET['disciplina_id'];
}
if (!empty($_GET['tipo'])) {
    $sql_atividades .= " AND a.tipo = :tipo";
    $params[':tipo'] = $_GET['tipo'];
}
if (!empty($_GET['status'])) {
    if ($_GET['status'] === 'pendente') {
        $sql_atividades .= " AND (a.data_entrega >= CURDATE() OR a.data_entrega IS NULL)";
    } elseif ($_GET['status'] === 'vencido') {
        $sql_atividades .= " AND a.data_entrega < CURDATE()";
    }
}

$sql_atividades .= " ORDER BY a.data_entrega ASC";
$stmt_atividades = $conn->prepare($sql_atividades);
$stmt_atividades->execute($params);
$atividades = $stmt_atividades->fetchAll();

// Buscar atividade para edição
$atividade_editar = null;
if (isset($_GET['editar'])) {
    $id = (int)$_GET['editar'];
    $sql = "SELECT * FROM atividades WHERE id = :id AND professor_id = :professor_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
    $atividade_editar = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atividades | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .card-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px 20px;
        }
        .btn-primary-custom {
            background: #006B3E;
            border: none;
        }
        .btn-primary-custom:hover {
            background: #004d2d;
        }
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-pendente {
            background: #ffeaa7;
            color: #d63031;
        }
        .status-vencido {
            background: #ff7675;
            color: #fff;
        }
        .status-concluido {
            background: #55efc4;
            color: #006266;
        }
        .tipo-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .tipo-dever {
            background: #0984e3;
            color: white;
        }
        .tipo-trabalho {
            background: #6c5ce7;
            color: white;
        }
        .tipo-prova {
            background: #e17055;
            color: white;
        }
        .tipo-outro {
            background: #00b894;
            color: white;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        
        /* Ajuste para o main-content com sidebar */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Botão Voltar -->
        <div class="mb-3">
            <a href="dashboard.php" class="btn-voltar btn">
                <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
            </a>
        </div>
        
        <div class="row">
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header-custom">
                        <i class="fas fa-plus-circle"></i> <?php echo $atividade_editar ? 'Editar Atividade' : 'Nova Atividade'; ?>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="acao" value="<?php echo $atividade_editar ? 'editar' : 'adicionar'; ?>">
                            <?php if ($atividade_editar): ?>
                                <input type="hidden" name="atividade_id" value="<?php echo $atividade_editar['id']; ?>">
                            <?php endif; ?>
                            <div class="mb-3">
                                <label class="form-label">Turma</label>
                                <select name="turma_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turmas as $turma): ?>
                                        <option value="<?php echo $turma['id']; ?>" <?php echo ($atividade_editar && $atividade_editar['turma_id'] == $turma['id']) ? 'selected' : ''; ?>>
                                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Disciplina</label>
                                <select name="disciplina_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $disciplina): ?>
                                        <option value="<?php echo $disciplina['id']; ?>" <?php echo ($atividade_editar && $atividade_editar['disciplina_id'] == $disciplina['id']) ? 'selected' : ''; ?>>
                                            <?php echo $disciplina['nome']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Título</label>
                                <input type="text" name="titulo" class="form-control" required value="<?php echo $atividade_editar ? htmlspecialchars($atividade_editar['titulo']) : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Descrição</label>
                                <textarea name="descricao" class="form-control" rows="3"><?php echo $atividade_editar ? htmlspecialchars($atividade_editar['descricao']) : ''; ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Data de Entrega</label>
                                <input type="date" name="data_entrega" class="form-control" value="<?php echo $atividade_editar ? $atividade_editar['data_entrega'] : ''; ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" class="form-select">
                                    <option value="dever" <?php echo ($atividade_editar && $atividade_editar['tipo'] == 'dever') ? 'selected' : ''; ?>>Dever de Casa</option>
                                    <option value="trabalho" <?php echo ($atividade_editar && $atividade_editar['tipo'] == 'trabalho') ? 'selected' : ''; ?>>Trabalho</option>
                                    <option value="prova" <?php echo ($atividade_editar && $atividade_editar['tipo'] == 'prova') ? 'selected' : ''; ?>>Prova</option>
                                    <option value="outro" <?php echo ($atividade_editar && $atividade_editar['tipo'] == 'outro') ? 'selected' : ''; ?>>Outro</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary-custom w-100">
                                <i class="fas fa-save"></i> <?php echo $atividade_editar ? 'Atualizar' : 'Cadastrar'; ?>
                            </button>
                            <?php if ($atividade_editar): ?>
                                <a href="atividades.php" class="btn btn-secondary w-100 mt-2">
                                    <i class="fas fa-times"></i> Cancelar
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
            
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header-custom">
                        <i class="fas fa-filter"></i> Filtros
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-2">
                            <div class="col-md-4">
                                <select name="turma_id" class="form-select">
                                    <option value="">Todas as turmas</option>
                                    <?php foreach ($turmas as $turma): ?>
                                        <option value="<?php echo $turma['id']; ?>" <?php echo (!empty($_GET['turma_id']) && $_GET['turma_id'] == $turma['id']) ? 'selected' : ''; ?>>
                                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select name="disciplina_id" class="form-select">
                                    <option value="">Todas as disciplinas</option>
                                    <?php foreach ($disciplinas as $disciplina): ?>
                                        <option value="<?php echo $disciplina['id']; ?>" <?php echo (!empty($_GET['disciplina_id']) && $_GET['disciplina_id'] == $disciplina['id']) ? 'selected' : ''; ?>>
                                            <?php echo $disciplina['nome']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="tipo" class="form-select">
                                    <option value="">Todos os tipos</option>
                                    <option value="dever" <?php echo (!empty($_GET['tipo']) && $_GET['tipo'] == 'dever') ? 'selected' : ''; ?>>Dever de Casa</option>
                                    <option value="trabalho" <?php echo (!empty($_GET['tipo']) && $_GET['tipo'] == 'trabalho') ? 'selected' : ''; ?>>Trabalho</option>
                                    <option value="prova" <?php echo (!empty($_GET['tipo']) && $_GET['tipo'] == 'prova') ? 'selected' : ''; ?>>Prova</option>
                                    <option value="outro" <?php echo (!empty($_GET['tipo']) && $_GET['tipo'] == 'outro') ? 'selected' : ''; ?>>Outro</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select name="status" class="form-select">
                                    <option value="">Todos os status</option>
                                    <option value="pendente" <?php echo (!empty($_GET['status']) && $_GET['status'] == 'pendente') ? 'selected' : ''; ?>>Pendentes</option>
                                    <option value="vencido" <?php echo (!empty($_GET['status']) && $_GET['status'] == 'vencido') ? 'selected' : ''; ?>>Vencidos</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary-custom w-100">Filtrar</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header-custom">
                        <i class="fas fa-list"></i> Lista de Atividades
                    </div>
                    <div class="card-body">
                        <?php if (empty($atividades)): ?>
                            <div class="alert alert-info text-center">Nenhuma atividade encontrada.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Título</th>
                                            <th>Turma/Disciplina</th>
                                            <th>Tipo</th>
                                            <th>Data Entrega</th>
                                            <th>Status</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($atividades as $atividade): 
                                            $vencido = $atividade['data_entrega'] && $atividade['data_entrega'] < date('Y-m-d');
                                            $status_texto = $vencido ? 'Vencido' : 'Pendente';
                                            $status_class = $vencido ? 'status-vencido' : 'status-pendente';
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($atividade['titulo']); ?><br><small><?php echo htmlspecialchars($atividade['descricao']); ?></small></td>
                                                <td><?php echo $atividade['turma_ano'] . 'ª - ' . $atividade['turma_nome']; ?><br><small><?php echo $atividade['disciplina_nome']; ?></small></td>
                                                <td><span class="tipo-badge tipo-<?php echo $atividade['tipo']; ?>"><?php echo ucfirst($atividade['tipo']); ?></span></td>
                                                <td><?php echo $atividade['data_entrega'] ? date('d/m/Y', strtotime($atividade['data_entrega'])) : 'Sem data'; ?></td>
                                                <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_texto; ?></span></td>
                                                <td class="text-center">
                                                    <a href="?editar=<?php echo $atividade['id']; ?>" class="btn btn-warning btn-sm">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?excluir=<?php echo $atividade['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir esta atividade?');">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>