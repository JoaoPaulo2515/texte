<?php
// escola/professor/meus_horarios.php - Gerenciar Meus Horários

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

// ============================================
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';

// Adicionar/Editar horário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $dia_semana = $_POST['dia_semana'] ?? '';
    $horario_inicio = $_POST['horario_inicio'] ?? '';
    $horario_fim = $_POST['horario_fim'] ?? '';
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    
    if ($dia_semana && $horario_inicio && $horario_fim && $turma_id && $disciplina_id) {
        try {
            if ($_POST['acao'] == 'adicionar') {
                $sql = "INSERT INTO horarios (professor_id, turma_id, disciplina_id, dia_semana, horario_inicio, horario_fim, escola_id, ano_letivo_id, created_at) 
                        VALUES (:professor_id, :turma_id, :disciplina_id, :dia_semana, :horario_inicio, :horario_fim, :escola_id, :ano_letivo_id, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':professor_id' => $professor_id,
                    ':turma_id' => $turma_id,
                    ':disciplina_id' => $disciplina_id,
                    ':dia_semana' => $dia_semana,
                    ':horario_inicio' => $horario_inicio,
                    ':horario_fim' => $horario_fim,
                    ':escola_id' => $escola_id,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                $success = "Horário adicionado com sucesso!";
            } elseif ($_POST['acao'] == 'editar' && $id > 0) {
                $sql = "UPDATE horarios SET 
                            turma_id = :turma_id, 
                            disciplina_id = :disciplina_id, 
                            dia_semana = :dia_semana, 
                            horario_inicio = :horario_inicio, 
                            horario_fim = :horario_fim,
                            updated_at = NOW() 
                        WHERE id = :id AND professor_id = :professor_id";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':id' => $id,
                    ':professor_id' => $professor_id,
                    ':turma_id' => $turma_id,
                    ':disciplina_id' => $disciplina_id,
                    ':dia_semana' => $dia_semana,
                    ':horario_inicio' => $horario_inicio,
                    ':horario_fim' => $horario_fim
                ]);
                $success = "Horário atualizado com sucesso!";
            }
        } catch (PDOException $e) {
            $error = "Erro ao salvar horário: " . $e->getMessage();
        }
    } else {
        $error = "Preencha todos os campos obrigatórios.";
    }
}

// Excluir horário
if (isset($_GET['excluir'])) {
    $id = (int)$_GET['excluir'];
    try {
        $sql = "DELETE FROM horarios WHERE id = :id AND professor_id = :professor_id";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
        $success = "Horário excluído com sucesso!";
    } catch (PDOException $e) {
        $error = "Erro ao excluir horário: " . $e->getMessage();
    }
    header("Location: meus_horarios.php");
    exit;
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar turmas do professor
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas do professor
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar horários existentes
$sql_horarios = "
    SELECT h.*, 
           t.nome as turma_nome, t.ano as turma_ano, t.turno, t.sala,
           d.nome as disciplina_nome, d.codigo as disciplina_codigo
    FROM horarios h
    INNER JOIN turmas t ON t.id = h.turma_id
    INNER JOIN disciplinas d ON d.id = h.disciplina_id
    WHERE h.professor_id = :professor_id
    ORDER BY FIELD(h.dia_semana, 'SEGUNDA', 'TERCA', 'QUARTA', 'QUINTA', 'SEXTA', 'SABADO'), h.horario_inicio
";
$stmt_horarios = $conn->prepare($sql_horarios);
$stmt_horarios->execute([':professor_id' => $professor_id]);
$horarios = $stmt_horarios->fetchAll(PDO::FETCH_ASSOC);

// Buscar horário para edição
$horario_editar = null;
if (isset($_GET['editar'])) {
    $id = (int)$_GET['editar'];
    $sql = "SELECT * FROM horarios WHERE id = :id AND professor_id = :professor_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
    $horario_editar = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getDiaSemanaExtenso($dia) {
    switch ($dia) {
        case 'SEGUNDA': return 'Segunda-feira';
        case 'TERCA': return 'Terça-feira';
        case 'QUARTA': return 'Quarta-feira';
        case 'QUINTA': return 'Quinta-feira';
        case 'SEXTA': return 'Sexta-feira';
        case 'SABADO': return 'Sábado';
        default: return $dia;
    }
}

function formatarHorario($horario) {
    if (empty($horario)) return '-';
    return date('H:i', strtotime($horario));
}

// Dias da semana
$dias_semana = [
    'SEGUNDA' => 'Segunda-feira',
    'TERCA' => 'Terça-feira',
    'QUARTA' => 'Quarta-feira',
    'QUINTA' => 'Quinta-feira',
    'SEXTA' => 'Sexta-feira',
    'SABADO' => 'Sábado'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meus Horários | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .filter-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .horario-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: transform 0.2s;
        }
        .horario-card:hover {
            transform: translateY(-3px);
        }
        .dia-titulo {
            font-size: 1.1em;
            font-weight: bold;
            color: #006B3E;
            padding-bottom: 10px;
            margin-bottom: 15px;
            border-bottom: 2px solid #006B3E;
        }
        .horario-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .horario-item:last-child {
            border-bottom: none;
        }
        .horario-horario {
            font-weight: bold;
            color: #006B3E;
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
        .badge-sala {
            background: #17a2b8;
            color: white;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 10px;
        }
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
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 0;
            }
        }
        .table-horarios th {
            background: #006B3E;
            color: white;
            text-align: center;
        }
        .table-horarios td {
            vertical-align: middle;
        }
        
        /* Modal Preview */
        .modal-preview .modal-dialog {
            max-width: 90%;
            margin: 30px auto;
        }
        .modal-preview .modal-body {
            padding: 0;
            height: 80vh;
        }
        .preview-iframe {
            width: 100%;
            height: 100%;
            border: none;
        }
        .carregando-preview {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-calendar-alt"></i> Meus Horários</h2>
                    <p>Gerencie seu horário de aulas</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                    <button onclick="abrirPreviewPDF()" class="btn-voltar btn me-2" style="background: #17a2b8;">
                        <i class="fas fa-eye"></i> Visualizar PDF
                    </button>
                    <button onclick="window.print()" class="btn-voltar btn" style="background: #28a745;">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Formulário de Adicionar/Editar Horário -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                        <h5 class="mb-0">
                            <i class="fas fa-plus-circle"></i> 
                            <?php echo $horario_editar ? 'Editar Horário' : 'Adicionar Horário'; ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="acao" value="<?php echo $horario_editar ? 'editar' : 'adicionar'; ?>">
                            <?php if ($horario_editar): ?>
                                <input type="hidden" name="id" value="<?php echo $horario_editar['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label">Dia da Semana</label>
                                <select name="dia_semana" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($dias_semana as $key => $nome): ?>
                                    <option value="<?php echo $key; ?>" <?php echo ($horario_editar && $horario_editar['dia_semana'] == $key) ? 'selected' : ''; ?>>
                                        <?php echo $nome; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Horário Início</label>
                                    <input type="time" name="horario_inicio" class="form-control" 
                                           value="<?php echo $horario_editar ? $horario_editar['horario_inicio'] : ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Horário Fim</label>
                                    <input type="time" name="horario_fim" class="form-control" 
                                           value="<?php echo $horario_editar ? $horario_editar['horario_fim'] : ''; ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Turma</label>
                                <select name="turma_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>" <?php echo ($horario_editar && $horario_editar['turma_id'] == $turma['id']) ? 'selected' : ''; ?>>
                                        <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Disciplina</label>
                                <select name="disciplina_id" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $disciplina): ?>
                                    <option value="<?php echo $disciplina['id']; ?>" <?php echo ($horario_editar && $horario_editar['disciplina_id'] == $disciplina['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($disciplina['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> <?php echo $horario_editar ? 'Atualizar' : 'Salvar'; ?>
                                </button>
                                <?php if ($horario_editar): ?>
                                    <a href="meus_horarios.php" class="btn btn-secondary">
                                        <i class="fas fa-times"></i> Cancelar
                                    </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Informações -->
                <div class="card mt-3">
                    <div class="card-header bg-white">
                        <h6 class="mb-0"><i class="fas fa-info-circle"></i> Informações</h6>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted mb-2">
                            <i class="fas fa-clock"></i> <strong>Horário de início e fim:</strong> Defina o horário da aula.
                        </p>
                        <p class="small text-muted mb-2">
                            <i class="fas fa-chalkboard"></i> <strong>Turma:</strong> Selecione a turma que você leciona.
                        </p>
                        <p class="small text-muted mb-0">
                            <i class="fas fa-book"></i> <strong>Disciplina:</strong> Selecione a disciplina que você ministra.
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Lista de Horários -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Meus Horários de Aula</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($horarios)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle"></i> Nenhum horário cadastrado.
                                <br>Clique em "Adicionar Horário" para começar.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-horarios">
                                    <thead>
                                        <tr>
                                            <th>Dia</th>
                                            <th>Horário</th>
                                            <th>Disciplina</th>
                                            <th>Turma</th>
                                            <th>Sala</th>
                                            <th width="10%">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($horarios as $horario): ?>
                                        <tr>
                                            <td><?php echo getDiaSemanaExtenso($horario['dia_semana']); ?></td>
                                            <td class="text-center">
                                                <span class="horario-horario">
                                                    <?php echo formatarHorario($horario['horario_inicio']); ?> - <?php echo formatarHorario($horario['horario_fim']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($horario['disciplina_nome']); ?></strong>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($horario['disciplina_codigo'] ?? ''); ?></small>
                                            </td>
                                            <td>
                                                <?php echo $horario['turma_ano'] . 'ª ' . htmlspecialchars($horario['turma_nome']); ?>
                                                <br><small class="text-muted"><?php echo ucfirst($horario['turno']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge-sala">Sala <?php echo $horario['sala'] ?: 'N/D'; ?></span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <a href="?editar=<?php echo $horario['id']; ?>" class="btn btn-sm btn-warning" title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?excluir=<?php echo $horario['id']; ?>" class="btn btn-sm btn-danger" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir este horário?')">
                                                        <i class="fas fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Visualização por Dia da Semana (BUSCA DA BASE DE DADOS) -->
                <div class="card mt-4">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-calendar-week"></i> Visualização por Dia</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php 
                            // Buscar horários da base de dados para visualização por dia
                            $horarios_por_dia = [];
                            foreach ($horarios as $horario) {
                                $dia = $horario['dia_semana'];
                                if (!isset($horarios_por_dia[$dia])) {
                                    $horarios_por_dia[$dia] = [];
                                }
                                $horarios_por_dia[$dia][] = $horario;
                            }
                            ?>
                            <?php foreach ($dias_semana as $key => $nome): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="horario-card">
                                    <div class="dia-titulo"><?php echo $nome; ?></div>
                                    <?php if (empty($horarios_por_dia[$key])): ?>
                                        <div class="text-center text-muted py-2">
                                            <i class="fas fa-clock"></i> Nenhuma aula agendada
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($horarios_por_dia[$key] as $h): ?>
                                        <div class="horario-item">
                                            <div class="horario-horario">
                                                <?php echo formatarHorario($h['horario_inicio']); ?> - <?php echo formatarHorario($h['horario_fim']); ?>
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($h['disciplina_nome']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-chalkboard"></i> <?php echo $h['turma_ano'] . 'ª ' . htmlspecialchars($h['turma_nome']); ?>
                                                </small>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Preview PDF -->
    <div class="modal fade modal-preview" id="modalPreviewPDF" tabindex="-1" aria-labelledby="modalPreviewPDFLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title" id="modalPreviewPDFLabel">
                        <i class="fas fa-eye"></i> Visualizar PDF - Meus Horários
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="previewBody">
                    <div class="carregando-preview">
                        <div class="text-center">
                            <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                            <p>Gerando PDF, aguarde...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> Fechar
                    </button>
                    <button type="button" class="btn btn-primary" onclick="imprimirPDF()">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                    <button type="button" class="btn btn-success" onclick="baixarPDF()">
                        <i class="fas fa-download"></i> Baixar PDF
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let pdfUrl = '';
        
        // Mostrar instruções SQL
        function mostrarInstrucoes() {
            document.getElementById('instrucoesSQL').style.display = 'block';
        }
        
        function fecharInstrucoes() {
            document.getElementById('instrucoesSQL').style.display = 'none';
        }
        
        // Abrir preview do PDF
        function abrirPreviewPDF() {
            const modal = new bootstrap.Modal(document.getElementById('modalPreviewPDF'));
            const previewBody = document.getElementById('previewBody');
            
            previewBody.innerHTML = `
                <div class="carregando-preview">
                    <div class="text-center">
                        <i class="fas fa-spinner fa-spin fa-3x text-primary mb-3"></i>
                        <p>Gerando PDF, aguarde...</p>
                    </div>
                </div>
            `;
            
            modal.show();
            
            // Gerar PDF
            const dados = new FormData();
            dados.append('gerar_pdf', '1');
            
            fetch('gerar_pdf_horarios.php', {
                method: 'POST',
                body: dados
            })
            .then(response => response.blob())
            .then(blob => {
                pdfUrl = URL.createObjectURL(blob);
                const iframe = `<iframe src="${pdfUrl}" class="preview-iframe" frameborder="0"></iframe>`;
                previewBody.innerHTML = iframe;
            })
            .catch(error => {
                console.error('Erro:', error);
                previewBody.innerHTML = `
                    <div class="alert alert-danger text-center m-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2"></i>
                        <p>Erro ao gerar o PDF. Tente novamente.</p>
                        <button class="btn btn-danger mt-2" onclick="abrirPreviewPDF()">
                            <i class="fas fa-redo"></i> Tentar novamente
                        </button>
                    </div>
                `;
            });
        }
        
        // Imprimir PDF
        function imprimirPDF() {
            if (pdfUrl) {
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = pdfUrl;
                document.body.appendChild(iframe);
                iframe.contentWindow.print();
            } else {
                alert('PDF ainda não carregado. Aguarde um momento.');
            }
        }
        
        // Baixar PDF
        function baixarPDF() {
            if (pdfUrl) {
                const link = document.createElement('a');
                link.href = pdfUrl;
                link.download = 'meus_horarios.pdf';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            } else {
                alert('PDF ainda não carregado. Aguarde um momento.');
            }
        }
    </script>
</body>
</html>