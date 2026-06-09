<?php
// aluno/tarefas/minhas_tarefas.php - Gerenciamento de Tarefas do Aluno

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
$sql_aluno = "SELECT e.nome, e.matricula
              FROM estudantes e 
              WHERE e.id = :id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano, t.sala 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR ENVIO DE RESPOSTA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'enviar_resposta') {
        $tarefa_id = (int)$_POST['tarefa_id'];
        $resposta_texto = trim($_POST['resposta_texto'] ?? '');
        $anexo_path = null;
        
        // Processar anexo se existir
        if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../../../uploads/tarefas/respostas/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $extensao = pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION);
            $nome_arquivo = 'resposta_' . $aluno_id . '_' . $tarefa_id . '_' . time() . '.' . $extensao;
            $caminho_arquivo = $upload_dir . $nome_arquivo;
            
            if (move_uploaded_file($_FILES['anexo']['tmp_name'], $caminho_arquivo)) {
                $anexo_path = 'uploads/tarefas/respostas/' . $nome_arquivo;
            }
        }
        
        // Verificar se já existe resposta
        $sql_check = "SELECT id FROM tarefas_respostas 
                      WHERE tarefa_id = :tarefa_id AND aluno_id = :aluno_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':tarefa_id' => $tarefa_id,
            ':aluno_id' => $aluno_id
        ]);
        
        if ($stmt_check->fetch()) {
            // Atualizar resposta existente
            $sql = "UPDATE tarefas_respostas 
                    SET resposta_texto = :resposta_texto, 
                        anexo_path = COALESCE(:anexo_path, anexo_path),
                        data_atualizacao = NOW(),
                        status = 'entregue'
                    WHERE tarefa_id = :tarefa_id AND aluno_id = :aluno_id";
        } else {
            // Inserir nova resposta
            $sql = "INSERT INTO tarefas_respostas (tarefa_id, aluno_id, resposta_texto, anexo_path, status) 
                    VALUES (:tarefa_id, :aluno_id, :resposta_texto, :anexo_path, 'entregue')";
        }
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([
            ':tarefa_id' => $tarefa_id,
            ':aluno_id' => $aluno_id,
            ':resposta_texto' => $resposta_texto,
            ':anexo_path' => $anexo_path
        ]);
        
        if ($result) {
            $_SESSION['success_message'] = "Resposta enviada com sucesso!";
        } else {
            $_SESSION['error_message'] = "Erro ao enviar resposta. Tente novamente.";
        }
        
        header('Location: minhas_tarefas.php');
        exit;
    }
}

// ============================================
// FILTROS
// ============================================
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todas';
$disciplina_filtro = isset($_GET['disciplina']) ? (int)$_GET['disciplina'] : 0;
$busca_filtro = isset($_GET['busca']) ? trim($_GET['busca']) : '';

// Buscar disciplinas disponíveis
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome
                    FROM disciplinas d
                    JOIN tarefas t ON t.disciplina_id = d.id
                    JOIN turmas tur ON t.turma_id = tur.id
                    JOIN matriculas m ON m.turma_id = tur.id
                    WHERE m.estudante_id = :aluno_id 
                    AND t.status = 'publicada'
                    AND t.data_entrega >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':aluno_id' => $aluno_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar tarefas do aluno
$sql_tarefas = "SELECT t.*, 
                       d.nome as disciplina_nome,
                       p.nome as professor_nome,
                       r.id as resposta_id,
                       r.resposta_texto,
                       r.anexo_path,
                       r.status as resposta_status,
                       r.nota,
                       r.comentario_professor,
                       r.data_entrega as data_resposta,
                       CASE 
                           WHEN r.id IS NULL THEN 'pendente'
                           WHEN r.status = 'entregue' AND t.data_entrega < NOW() THEN 'atrasado'
                           ELSE r.status
                       END as status_aluno,
                       CASE 
                           WHEN t.data_entrega < NOW() AND r.id IS NULL THEN 'atrasado'
                           WHEN t.data_entrega < NOW() AND r.status != 'corrigido' THEN 'entregue_atrasado'
                           ELSE 'normal'
                       END as situacao
                FROM tarefas t
                JOIN disciplinas d ON d.id = t.disciplina_id
                JOIN funcionarios p ON p.id = t.professor_id
                JOIN turmas tur ON tur.id = t.turma_id
                JOIN matriculas m ON m.turma_id = tur.id
                LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
                WHERE m.estudante_id = :aluno_id 
                AND t.status = 'publicada'";

if ($status_filtro != 'todas') {
    if ($status_filtro == 'pendente') {
        $sql_tarefas .= " AND r.id IS NULL";
    } elseif ($status_filtro == 'entregue') {
        $sql_tarefas .= " AND r.id IS NOT NULL AND r.status = 'entregue'";
    } elseif ($status_filtro == 'corrigido') {
        $sql_tarefas .= " AND r.status = 'corrigido'";
    } elseif ($status_filtro == 'atrasado') {
        $sql_tarefas .= " AND r.id IS NULL AND t.data_entrega < NOW()";
    }
}

if ($disciplina_filtro > 0) {
    $sql_tarefas .= " AND t.disciplina_id = :disciplina_id";
}

if (!empty($busca_filtro)) {
    $sql_tarefas .= " AND (t.titulo LIKE :busca OR t.descricao LIKE :busca)";
}

$sql_tarefas .= " ORDER BY t.data_entrega ASC, t.data_publicacao DESC";

$stmt_tarefas = $conn->prepare($sql_tarefas);
$params = [':aluno_id' => $aluno_id];
if ($disciplina_filtro > 0) {
    $params[':disciplina_id'] = $disciplina_filtro;
}
if (!empty($busca_filtro)) {
    $params[':busca'] = "%$busca_filtro%";
}
$stmt_tarefas->execute($params);
$tarefas = $stmt_tarefas->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$total_tarefas = count($tarefas);
$total_pendentes = count(array_filter($tarefas, function($t) { return $t['status_aluno'] == 'pendente'; }));
$total_entregues = count(array_filter($tarefas, function($t) { return $t['status_aluno'] == 'entregue'; }));
$total_corrigidas = count(array_filter($tarefas, function($t) { return $t['status_aluno'] == 'corrigido'; }));
$total_atrasadas = count(array_filter($tarefas, function($t) { return $t['situacao'] == 'atrasado'; }));

// Calcular média de notas
$notas = array_filter(array_column($tarefas, 'nota'), function($n) { return $n !== null; });
$media_notas = !empty($notas) ? array_sum($notas) / count($notas) : 0;

// Funções auxiliares
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y H:i', strtotime($data));
}

function getStatusBadge($status, $situacao) {
    if ($status == 'corrigido') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Corrigida</span>';
    } elseif ($status == 'entregue') {
        if ($situacao == 'entregue_atrasado') {
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Entregue com Atraso</span>';
        }
        return '<span class="badge bg-info"><i class="fas fa-paper-plane"></i> Entregue</span>';
    } elseif ($situacao == 'atrasado') {
        return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasada</span>';
    } else {
        return '<span class="badge bg-secondary"><i class="fas fa-hourglass-half"></i> Pendente</span>';
    }
}

function getPrazoClass($data_entrega, $status) {
    $hoje = new DateTime();
    $entrega = new DateTime($data_entrega);
    
    if ($status != 'pendente') return '';
    
    if ($hoje > $entrega) {
        return 'text-danger fw-bold';
    } elseif ($hoje->diff($entrega)->days <= 2) {
        return 'text-warning fw-bold';
    }
    return '';
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Tarefas | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        
        .stat-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,0.05); height: 100%; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 1.8em; font-weight: bold; }
        .stat-label { color: #6c757d; font-size: 0.85rem; margin-top: 5px; }
        
        .tarefa-card { transition: all 0.3s; cursor: pointer; }
        .tarefa-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .disciplina-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .resposta-box { background: #f8f9fa; border-left: 4px solid #006B3E; padding: 15px; margin-top: 15px; border-radius: 8px; }
        
        .modal-xl { max-width: 90%; }
        
        @media print {
            .no-print { display: none; }
            .tarefa-card { break-inside: avoid; }
        }
    </style>
</head>
<body>
  <?php include 'includes/menu_aluno.php'; ?> 
</br></br></br>
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-tasks"></i> Minhas Tarefas</h2>
                <p class="text-muted">Acompanhe e entregue suas atividades escolares</p>
            </div>
            <div class="no-print">
                <button class="btn btn-outline-primary" onclick="window.print()">
                    <i class="fas fa-print"></i> Imprimir
                </button>
            </div>
        </div>
        
        <!-- Alertas -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Cards de Estatísticas -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-primary"><?php echo $total_tarefas; ?></div>
                    <div class="stat-label"><i class="fas fa-list"></i> Total de Tarefas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-warning"><?php echo $total_pendentes; ?></div>
                    <div class="stat-label"><i class="fas fa-hourglass-half"></i> Pendentes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-info"><?php echo $total_entregues; ?></div>
                    <div class="stat-label"><i class="fas fa-paper-plane"></i> Entregues</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-value text-success"><?php echo number_format($media_notas, 1); ?></div>
                    <div class="stat-label"><i class="fas fa-star"></i> Média de Notas</div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="card mb-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-filter"></i> Filtros</h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Status</label>
                        <select name="status" class="form-select">
                            <option value="todas" <?php echo $status_filtro == 'todas' ? 'selected' : ''; ?>>Todas</option>
                            <option value="pendente" <?php echo $status_filtro == 'pendente' ? 'selected' : ''; ?>>Pendentes</option>
                            <option value="entregue" <?php echo $status_filtro == 'entregue' ? 'selected' : ''; ?>>Entregues</option>
                            <option value="corrigido" <?php echo $status_filtro == 'corrigido' ? 'selected' : ''; ?>>Corrigidas</option>
                            <option value="atrasado" <?php echo $status_filtro == 'atrasado' ? 'selected' : ''; ?>>Atrasadas</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Disciplina</label>
                        <select name="disciplina" class="form-select">
                            <option value="0">Todas</option>
                            <?php foreach ($disciplinas as $disc): ?>
                            <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($disc['nome']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Buscar</label>
                        <input type="text" name="busca" class="form-control" placeholder="Título ou descrição..." value="<?php echo htmlspecialchars($busca_filtro); ?>">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <?php if ($status_filtro != 'todas' || $disciplina_filtro > 0 || !empty($busca_filtro)): ?>
                        <a href="minhas_tarefas.php" class="btn btn-secondary ms-2">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Tarefas -->
        <?php if (empty($tarefas)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox fa-4x text-muted mb-3"></i>
                    <h5>Nenhuma tarefa encontrada</h5>
                    <p class="text-muted">Não há tarefas disponíveis com os filtros selecionados.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($tarefas as $tarefa): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card tarefa-card h-100" onclick="verTarefa(<?php echo $tarefa['id']; ?>)">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="disciplina-badge" style="background: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>20; color: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>">
                                    <i class="fas fa-book"></i> <?php echo htmlspecialchars($tarefa['disciplina_nome']); ?>
                                </span>
                                <?php echo getStatusBadge($tarefa['status_aluno'], $tarefa['situacao']); ?>
                            </div>
                            
                            <h5 class="card-title mt-2"><?php echo htmlspecialchars($tarefa['titulo']); ?></h5>
                            <p class="card-text small text-muted">
                                <?php echo htmlspecialchars(substr($tarefa['descricao'], 0, 100)) . (strlen($tarefa['descricao']) > 100 ? '...' : ''); ?>
                            </p>
                            
                            <hr>
                            
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="fas fa-calendar-alt text-muted"></i>
                                    <small class="<?php echo getPrazoClass($tarefa['data_entrega'], $tarefa['status_aluno']); ?>">
                                        Entrega: <?php echo formatarData($tarefa['data_entrega']); ?>
                                    </small>
                                </div>
                                <?php if ($tarefa['nota'] !== null): ?>
                                <div class="text-success">
                                    <i class="fas fa-star"></i> Nota: <?php echo number_format($tarefa['nota'], 1); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($tarefa['resposta_status'] == 'entregue' && $tarefa['nota'] === null): ?>
                            <div class="mt-2 text-info">
                                <i class="fas fa-clock"></i> <small>Aguardando correção</small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Visualizar/Responder Tarefa -->
    <div class="modal fade" id="tarefaModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-tasks"></i> Detalhes da Tarefa</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="tarefaModalBody">
                    <!-- Conteúdo carregado via AJAX -->
                    <div class="text-center py-5">
                        <div class="spinner-border text-success" role="status">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mt-2">Carregando tarefa...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.min.js"></script>
    
    <script>
        let quillEditor = null;
        
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Função para ver tarefa
        function verTarefa(tarefaId) {
            $('#tarefaModalBody').html(`
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando tarefa...</p>
                </div>
            `);
            
            $.ajax({
                url: 'ajax_carregar_tarefa.php',
                method: 'GET',
                data: { id: tarefaId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#tarefaModalBody').html(response.html);
                        // Inicializar Quill editor se necessário
                        if (document.getElementById('resposta-editor')) {
                            if (quillEditor) {
                                quillEditor = null;
                            }
                            quillEditor = new Quill('#resposta-editor', {
                                theme: 'snow',
                                placeholder: 'Digite sua resposta aqui...',
                                modules: {
                                    toolbar: [
                                        ['bold', 'italic', 'underline', 'strike'],
                                        ['blockquote', 'code-block'],
                                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                                        ['link', 'clean']
                                    ]
                                }
                            });
                            
                            // Carregar resposta existente
                            if (response.resposta_texto) {
                                quillEditor.root.innerHTML = response.resposta_texto;
                            }
                        }
                        
                        // Configurar formulário
                        $('#formResposta').on('submit', function(e) {
                            e.preventDefault();
                            
                            // Pegar conteúdo do editor
                            let respostaTexto = quillEditor ? quillEditor.root.innerHTML : $('#resposta_texto').val();
                            
                            // Criar FormData para upload de arquivo
                            let formData = new FormData();
                            formData.append('action', 'enviar_resposta');
                            formData.append('tarefa_id', tarefaId);
                            formData.append('resposta_texto', respostaTexto);
                            
                            let anexo = $('#anexo_file')[0].files[0];
                            if (anexo) {
                                formData.append('anexo', anexo);
                            }
                            
                            $.ajax({
                                url: 'minhas_tarefas.php',
                                method: 'POST',
                                data: formData,
                                processData: false,
                                contentType: false,
                                success: function() {
                                    location.reload();
                                },
                                error: function() {
                                    alert('Erro ao enviar resposta. Tente novamente.');
                                }
                            });
                        });
                    } else {
                        $('#tarefaModalBody').html('<div class="alert alert-danger">' + response.message + '</div>');
                    }
                },
                error: function() {
                    $('#tarefaModalBody').html('<div class="alert alert-danger">Erro ao carregar tarefa. Tente novamente.</div>');
                }
            });
            
            new bootstrap.Modal(document.getElementById('tarefaModal')).show();
        }
    </script>
</body>
</html>