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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Meus Horários | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 24px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .page-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.85;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-preview {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-preview:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-print {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-print:hover {
            background: #1e7e34;
            transform: translateY(-2px);
        }

        /* ============================================
           CARDS
        ============================================ */
        .card-custom {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .card-custom:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .card-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 25px;
            font-weight: 600;
            border: none;
        }

        .card-header-custom i {
            margin-right: 10px;
        }

        .card-body-custom {
            padding: 25px;
        }

        /* ============================================
           FORMULÁRIO
        ============================================ */
        .form-label {
            font-weight: 600;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        .btn-primary {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        .btn-secondary {
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
        }

        /* ============================================
           INFO CARD
        ============================================ */
        .info-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
        }

        .info-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 12px 20px;
            font-weight: 600;
        }

        .info-body {
            padding: 20px;
        }

        .info-text {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 8px;
            background: #f8f9fa;
            border-radius: 12px;
        }

        .info-text i {
            width: 25px;
            color: #006B3E;
        }

        /* ============================================
           TABELA DE HORÁRIOS
        ============================================ */
        .table-container {
            overflow-x: auto;
        }

        .horarios-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .horarios-table thead th {
            background: #f8f9fa;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
        }

        .horarios-table tbody td {
            padding: 12px 15px;
            font-size: 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }

        .horarios-table tbody tr:hover {
            background: #f8f9fa;
        }

        .horario-horario {
            font-weight: 700;
            color: #006B3E;
        }

        .badge-sala {
            background: #17a2b8;
            color: white;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .btn-warning-custom {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
        }

        .btn-warning-custom:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        .btn-danger-custom {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
        }

        .btn-danger-custom:hover {
            background: #c82333;
            transform: translateY(-1px);
        }

        /* ============================================
           VISUALIZAÇÃO POR DIA
        ============================================ */
        .dia-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            height: 100%;
        }

        .dia-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .dia-titulo {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 12px 15px;
            font-weight: 700;
            font-size: 0.9rem;
        }

        .dia-body {
            padding: 15px;
        }

        .horario-item {
            padding: 10px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .horario-item:hover {
            background: #e9ecef;
            transform: translateX(3px);
        }

        .horario-time {
            font-weight: 700;
            color: #006B3E;
            font-size: 0.8rem;
            margin-bottom: 5px;
        }

        .horario-disciplina {
            font-weight: 600;
            font-size: 0.85rem;
        }

        .horario-turma {
            font-size: 0.7rem;
            color: #6c757d;
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 16px;
            padding: 15px 20px;
            border: none;
        }

        .alert-success-custom {
            background: linear-gradient(135deg, #d4edda 0%, #c8e6c9 100%);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger-custom {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .modal-body-preview {
            height: 70vh;
            padding: 0;
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

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .horarios-table thead th,
            .horarios-table tbody td {
                padding: 8px 10px;
                font-size: 0.7rem;
            }
            
            .btn-warning-custom,
            .btn-danger-custom {
                padding: 4px 8px;
                font-size: 0.6rem;
            }
            
            .badge-sala {
                font-size: 0.6rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header fade-in">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-calendar-alt me-2"></i> Meus Horários</h2>
                    <p>Gerencie seu horário de aulas</p>
                </div>
                <div class="no-print d-flex flex-wrap gap-2">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                    </a>
                    <button onclick="abrirPreviewPDF()" class="btn-preview">
                        <i class="fas fa-eye"></i> Visualizar PDF
                    </button>
                    <button onclick="window.print()" class="btn-print">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Mensagens -->
        <?php if ($success): ?>
            <div class="alert-custom alert-success-custom fade-in mb-4">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-custom alert-danger-custom fade-in mb-4">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Coluna Esquerda - Formulário -->
            <div class="col-md-4">
                <div class="card-custom fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-plus-circle"></i> 
                        <?php echo $horario_editar ? 'Editar Horário' : 'Adicionar Horário'; ?>
                    </div>
                    <div class="card-body-custom">
                        <form method="POST">
                            <input type="hidden" name="acao" value="<?php echo $horario_editar ? 'editar' : 'adicionar'; ?>">
                            <?php if ($horario_editar): ?>
                                <input type="hidden" name="id" value="<?php echo $horario_editar['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-calendar-day"></i> Dia da Semana</label>
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
                                    <label class="form-label"><i class="fas fa-hourglass-start"></i> Início</label>
                                    <input type="time" name="horario_inicio" class="form-control" 
                                           value="<?php echo $horario_editar ? $horario_editar['horario_inicio'] : ''; ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><i class="fas fa-hourglass-end"></i> Término</label>
                                    <input type="time" name="horario_fim" class="form-control" 
                                           value="<?php echo $horario_editar ? $horario_editar['horario_fim'] : ''; ?>" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-chalkboard"></i> Turma</label>
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
                                <label class="form-label"><i class="fas fa-book"></i> Disciplina</label>
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
                                    <i class="fas fa-save"></i> <?php echo $horario_editar ? 'Atualizar Horário' : 'Salvar Horário'; ?>
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
                <div class="card-custom fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-info-circle"></i> Informações
                    </div>
                    <div class="card-body-custom">
                        <div class="info-text">
                            <i class="fas fa-clock"></i>
                            <span>Defina o horário de início e fim da aula</span>
                        </div>
                        <div class="info-text">
                            <i class="fas fa-chalkboard"></i>
                            <span>Selecione a turma que você leciona</span>
                        </div>
                        <div class="info-text">
                            <i class="fas fa-book"></i>
                            <span>Selecione a disciplina que você ministra</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna Direita - Lista de Horários -->
            <div class="col-md-8">
                <div class="card-custom fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-list"></i> Meus Horários de Aula
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($horarios)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-calendar-alt fa-4x text-muted mb-3"></i>
                                <h5>Nenhum horário cadastrado</h5>
                                <p class="text-muted">Clique em "Adicionar Horário" para começar.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="horarios-table">
                                    <thead>
                                        <tr>
                                            <th><i class="fas fa-calendar-day"></i> Dia</th>
                                            <th><i class="fas fa-clock"></i> Horário</th>
                                            <th><i class="fas fa-book"></i> Disciplina</th>
                                            <th><i class="fas fa-chalkboard"></i> Turma</th>
                                            <th><i class="fas fa-door-open"></i> Sala</th>
                                            <th><i class="fas fa-cogs"></i> Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($horarios as $horario): ?>
                                        <tr>
                                            <td class="text-center"><?php echo getDiaSemanaExtenso($horario['dia_semana']); ?></td>
                                            <td class="text-center">
                                                <span class="horario-horario">
                                                    <?php echo formatarHorario($horario['horario_inicio']); ?> - <?php echo formatarHorario($horario['horario_fim']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($horario['disciplina_nome']); ?></strong>
                                                <?php if ($horario['disciplina_codigo']): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($horario['disciplina_codigo']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $horario['turma_ano'] . 'ª ' . htmlspecialchars($horario['turma_nome']); ?>
                                                <br><small class="text-muted"><?php echo ucfirst($horario['turno']); ?></small>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge-sala">
                                                    <i class="fas fa-door-open"></i> Sala <?php echo $horario['sala'] ?: 'N/D'; ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex gap-2 justify-content-center">
                                                    <a href="?editar=<?php echo $horario['id']; ?>" class="btn-warning-custom" title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </a>
                                                    <a href="?excluir=<?php echo $horario['id']; ?>" class="btn-danger-custom" title="Excluir" onclick="return confirm('Tem certeza que deseja excluir este horário?')">
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
                
                <!-- Visualização por Dia -->
                <div class="card-custom fade-in mt-4">
                    <div class="card-header-custom">
                        <i class="fas fa-calendar-week"></i> Visualização por Dia
                    </div>
                    <div class="card-body-custom">
                        <div class="row">
                            <?php 
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
                                <div class="dia-card">
                                    <div class="dia-titulo"><?php echo $nome; ?></div>
                                    <div class="dia-body">
                                        <?php if (empty($horarios_por_dia[$key])): ?>
                                            <div class="text-center text-muted py-3">
                                                <i class="fas fa-clock"></i> Nenhuma aula
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($horarios_por_dia[$key] as $h): ?>
                                            <div class="horario-item">
                                                <div class="horario-time">
                                                    <i class="fas fa-clock"></i> <?php echo formatarHorario($h['horario_inicio']); ?> - <?php echo formatarHorario($h['horario_fim']); ?>
                                                </div>
                                                <div class="horario-disciplina">
                                                    <?php echo htmlspecialchars($h['disciplina_nome']); ?>
                                                </div>
                                                <div class="horario-turma">
                                                    <i class="fas fa-chalkboard"></i> <?php echo $h['turma_ano'] . 'ª ' . htmlspecialchars($h['turma_nome']); ?>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
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
    <div class="modal fade" id="modalPreviewPDF" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-eye me-2"></i> Visualizar PDF - Meus Horários</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body-preview" id="previewBody">
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
                    <div class="carregando-preview">
                        <div class="text-center">
                            <i class="fas fa-exclamation-triangle fa-3x text-danger mb-3"></i>
                            <p>Erro ao gerar o PDF. Tente novamente.</p>
                            <button class="btn btn-danger mt-2" onclick="abrirPreviewPDF()">
                                <i class="fas fa-redo"></i> Tentar novamente
                            </button>
                        </div>
                    </div>
                `;
            });
        }
        
        function imprimirPDF() {
            if (pdfUrl) {
                const iframe = document.createElement('iframe');
                iframe.style.display = 'none';
                iframe.src = pdfUrl;
                document.body.appendChild(iframe);
                setTimeout(() => {
                    iframe.contentWindow.print();
                }, 500);
            } else {
                alert('PDF ainda não carregado. Aguarde um momento.');
            }
        }
        
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
        
        // Animações ao scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.card-custom, .page-header').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>