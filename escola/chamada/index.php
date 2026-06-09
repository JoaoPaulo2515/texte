<?php
// escola/chamada/index.php - Registro de Chamada (Professor e Escola)
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_tipo = $_SESSION['usuario_tipo'];

// ============================================
// DETECTAR TIPO DE USUÁRIO
// ============================================
$is_professor = ($usuario_tipo == 'professor');
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor');

// ============================================
// BUSCAR TURMAS DO PROFESSOR (se for professor)
// ============================================
if ($is_professor) {
    $stmt = $conn->prepare("
        SELECT DISTINCT t.id, t.nome, t.ano, t.turno
        FROM turmas t
        JOIN alocacoes a ON a.turma_id = t.id
        JOIN professores p ON p.id = a.professor_id
        WHERE p.usuario_id = :usuario_id AND t.status = 'ativa' AND t.ano_letivo = YEAR(CURDATE())
        ORDER BY t.nome
    ");
    $stmt->execute([':usuario_id' => $usuario_id]);
    $turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $turmas = $conn->prepare("
        SELECT id, nome, ano, turno 
        FROM turmas 
        WHERE escola_id = :escola_id AND status = 'ativa'
        ORDER BY nome
    ");
    $turmas->execute([':escola_id' => $escola_id]);
    $turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$turma_id = $_GET['turma_id'] ?? 0;
$data = $_GET['data'] ?? date('Y-m-d');
$tipo_chamada = $_GET['tipo'] ?? 'aula'; // aula ou entrada_saida

$message = '';
$error = '';

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$alunos = [];
$presencas = [];
$entradas_saidas = [];

if ($turma_id) {
    if ($tipo_chamada == 'aula') {
        // Buscar alunos da turma para chamada de aula
        $stmt = $conn->prepare("
            SELECT e.id, u.nome, e.matricula, m.id as matricula_id, 
                   u.telefone as aluno_telefone, e.encarregado_telefone,
                   e.encarregado_nome, e.encarregado_email
            FROM estudantes e
            JOIN usuarios u ON u.id = e.usuario_id
            JOIN matriculas m ON m.estudante_id = e.id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa'
            ORDER BY u.nome ASC
        ");
        $stmt->execute([':turma_id' => $turma_id]);
        $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar presenças já registradas
        $stmt = $conn->prepare("
            SELECT p.*, m.estudante_id
            FROM presencas p
            JOIN matriculas m ON m.id = p.matricula_id
            WHERE m.turma_id = :turma_id AND p.data = :data
        ");
        $stmt->execute([':turma_id' => $turma_id, ':data' => $data]);
        $presencas_existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($presencas_existentes as $pe) {
            $presencas[$pe['estudante_id']] = $pe;
        }
    } else {
        // Buscar registros de entrada/saída do dia
        $stmt = $conn->prepare("
            SELECT es.*, e.nome as estudante_nome, e.matricula
            FROM entrada_saida es
            JOIN estudantes e ON e.id = es.estudante_id
            WHERE e.escola_id = :escola_id AND es.data = :data
            ORDER BY es.hora_entrada ASC
        ");
        $stmt->execute([':escola_id' => $escola_id, ':data' => $data]);
        $entradas_saidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// ============================================
// FUNÇÃO PARA ENVIAR NOTIFICAÇÃO
// ============================================
function enviarNotificacao($conn, $estudante_id, $tipo, $mensagem) {
    // Buscar dados do aluno e encarregado
    $stmt = $conn->prepare("
        SELECT e.encarregado_telefone, e.encarregado_email, e.encarregado_nome,
               u.nome as aluno_nome
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.id = :id
    ");
    $stmt->execute([':id' => $estudante_id]);
    $aluno = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$aluno) return;
    
    // 1. Notificação na plataforma (para o encarregado)
    $stmt = $conn->prepare("
        INSERT INTO notificacoes (escola_id, usuario_id, titulo, mensagem, tipo, prioridade, created_at)
        VALUES (NULL, NULL, :titulo, :mensagem, 'info', 'normal', NOW())
    ");
    
    // Buscar usuário encarregado (se existir)
    if (!empty($aluno['encarregado_email'])) {
        $stmt = $conn->prepare("
            INSERT INTO notificacoes (escola_id, usuario_id, titulo, mensagem, tipo, prioridade, created_at)
            SELECT :escola_id, u.id, :titulo, :mensagem, 'info', 'normal', NOW()
            FROM usuarios u
            WHERE u.email = :email AND u.tipo = 'pai'
        ");
        $stmt->execute([
            ':escola_id' => $_SESSION['escola_id'],
            ':email' => $aluno['encarregado_email'],
            ':titulo' => $tipo,
            ':mensagem' => $mensagem
        ]);
    }
    
    // 2. SMS (integração com API de SMS - exemplo)
    if (!empty($aluno['encarregado_telefone'])) {
        // Integrar com serviço de SMS (ex: Twilio, Clickatell, etc.)
        // enviarSMS($aluno['encarregado_telefone'], $mensagem);
    }
    
    // 3. WhatsApp (integração com API do WhatsApp Business)
    if (!empty($aluno['encarregado_telefone'])) {
        // enviarWhatsApp($aluno['encarregado_telefone'], $mensagem);
    }
    
    return true;
}

// ============================================
// SALVAR CHAMADA DE AULA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_chamada']) && $tipo_chamada == 'aula') {
    $turma_id = $_POST['turma_id'];
    $data = $_POST['data'];
    $enviar_notificacao = isset($_POST['enviar_notificacao']);
    
    try {
        $conn->beginTransaction();
        
        foreach ($_POST['presenca'] as $matricula_id => $presente) {
            $justificativa = $_POST['justificativa'][$matricula_id] ?? null;
            $tipo_falta = $justificativa ? 'justificada' : 'injustificada';
            
            // Verificar se já existe registro
            $stmt = $conn->prepare("
                SELECT id FROM presencas 
                WHERE matricula_id = :matricula_id AND data = :data
            ");
            $stmt->execute([
                ':matricula_id' => $matricula_id,
                ':data' => $data
            ]);
            
            if ($stmt->fetch()) {
                // Atualizar
                $stmt = $conn->prepare("
                    UPDATE presencas SET 
                        presente = :presente, 
                        justificativa = :justificativa,
                        tipo_falta = :tipo_falta,
                        updated_at = NOW()
                    WHERE matricula_id = :matricula_id AND data = :data
                ");
            } else {
                // Inserir
                $stmt = $conn->prepare("
                    INSERT INTO presencas (matricula_id, data, presente, justificativa, tipo_falta, created_at)
                    VALUES (:matricula_id, :data, :presente, :justificativa, :tipo_falta, NOW())
                ");
            }
            
            $stmt->execute([
                ':matricula_id' => $matricula_id,
                ':data' => $data,
                ':presente' => $presente,
                ':justificativa' => $justificativa,
                ':tipo_falta' => $tipo_falta
            ]);
            
            // Enviar notificação se for falta injustificada e opção ativada
            if ($enviar_notificacao && $presente == 0 && !$justificativa) {
                // Buscar estudante_id
                $stmt_est = $conn->prepare("
                    SELECT estudante_id FROM matriculas WHERE id = :matricula_id
                ");
                $stmt_est->execute([':matricula_id' => $matricula_id]);
                $estudante = $stmt_est->fetch(PDO::FETCH_ASSOC);
                
                if ($estudante) {
                    $mensagem = "Seu filho(a) faltou à aula no dia " . date('d/m/Y', strtotime($data)) . " sem justificativa.";
                    enviarNotificacao($conn, $estudante['estudante_id'], 'Falta não justificada', $mensagem);
                }
            }
        }
        
        $conn->commit();
        $message = "Chamada salva com sucesso!";
        
        // Recarregar presenças
        $stmt = $conn->prepare("
            SELECT p.*, m.estudante_id
            FROM presencas p
            JOIN matriculas m ON m.id = p.matricula_id
            WHERE m.turma_id = :turma_id AND p.data = :data
        ");
        $stmt->execute([':turma_id' => $turma_id, ':data' => $data]);
        $presencas_existentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $presencas = [];
        foreach ($presencas_existentes as $pe) {
            $presencas[$pe['estudante_id']] = $pe;
        }
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// ============================================
// REGISTRAR ENTRADA/SAÍDA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_entrada_saida']) && $tipo_chamada == 'entrada_saida') {
    $estudante_id = $_POST['estudante_id'] ?? 0;
    $tipo_registro = $_POST['tipo_registro'] ?? 'entrada'; // entrada ou saida
    $observacao = $_POST['observacao'] ?? '';
    $enviar_notificacao = isset($_POST['enviar_notificacao']);
    
    try {
        $conn->beginTransaction();
        
        $data_atual = date('Y-m-d');
        $hora_atual = date('H:i:s');
        
        // Verificar se já existe registro de entrada hoje
        $stmt = $conn->prepare("
            SELECT id, hora_entrada, hora_saida FROM entrada_saida 
            WHERE estudante_id = :estudante_id AND data = :data
        ");
        $stmt->execute([':estudante_id' => $estudante_id, ':data' => $data_atual]);
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tipo_registro == 'entrada') {
            if ($registro) {
                $error = "Este aluno já registrou entrada hoje.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO entrada_saida (estudante_id, data, hora_entrada, observacao_entrada, created_at)
                    VALUES (:estudante_id, :data, :hora_entrada, :observacao, NOW())
                ");
                $stmt->execute([
                    ':estudante_id' => $estudante_id,
                    ':data' => $data_atual,
                    ':hora_entrada' => $hora_atual,
                    ':observacao' => $observacao
                ]);
                
                if ($enviar_notificacao) {
                    $mensagem = "Seu filho(a) entrou na escola às " . date('H:i', strtotime($hora_atual));
                    enviarNotificacao($conn, $estudante_id, 'Entrada registrada', $mensagem);
                }
                $message = "Entrada registrada com sucesso!";
            }
        } else {
            if (!$registro) {
                $error = "Este aluno não registrou entrada hoje.";
            } elseif ($registro['hora_saida']) {
                $error = "Este aluno já registrou saída hoje.";
            } else {
                $stmt = $conn->prepare("
                    UPDATE entrada_saida SET 
                        hora_saida = :hora_saida,
                        observacao_saida = :observacao,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':hora_saida' => $hora_atual,
                    ':observacao' => $observacao,
                    ':id' => $registro['id']
                ]);
                
                if ($enviar_notificacao) {
                    $mensagem = "Seu filho(a) saiu da escola às " . date('H:i', strtotime($hora_atual));
                    enviarNotificacao($conn, $estudante_id, 'Saída registrada', $mensagem);
                }
                $message = "Saída registrada com sucesso!";
            }
        }
        
        $conn->commit();
        
        // Recarregar registros
        $stmt = $conn->prepare("
            SELECT es.*, e.nome as estudante_nome, e.matricula
            FROM entrada_saida es
            JOIN estudantes e ON e.id = es.estudante_id
            WHERE e.escola_id = :escola_id AND es.data = :data
            ORDER BY es.hora_entrada ASC
        ");
        $stmt->execute([':escola_id' => $escola_id, ':data' => $data_atual]);
        $entradas_saidas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

// Estatísticas de presença do mês
$estatisticas = [];
if ($turma_id) {
    $stmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN p.presente = 1 THEN 1 END) as presentes,
            COUNT(CASE WHEN p.presente = 0 AND p.tipo_falta = 'injustificada' THEN 1 END) as faltas,
            COUNT(CASE WHEN p.presente = 0 AND p.tipo_falta = 'justificada' THEN 1 END) as justificadas,
            COUNT(*) as total_dias
        FROM presencas p
        JOIN matriculas m ON m.id = p.matricula_id
        WHERE m.turma_id = :turma_id AND MONTH(p.data) = MONTH(CURDATE()) AND YEAR(p.data) = YEAR(CURDATE())
    ");
    $stmt->execute([':turma_id' => $turma_id]);
    $estatisticas = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Buscar alunos para entrada/saída
$alunos_escola = [];
if ($is_admin && $tipo_chamada == 'entrada_saida') {
    $stmt = $conn->prepare("
        SELECT e.id, u.nome, e.matricula
        FROM estudantes e
        JOIN usuarios u ON u.id = e.usuario_id
        WHERE e.escola_id = :escola_id
        ORDER BY u.nome
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $alunos_escola = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chamada | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .presenca-btn { width: 40px; height: 40px; border-radius: 50%; margin: 0 5px; cursor: pointer; transition: all 0.3s; }
        .presenca-presente { background-color: #28a745; color: white; }
        .presenca-falta { background-color: #dc3545; color: white; }
        .presenca-justificada { background-color: #ffc107; color: #333; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .stats-card { text-align: center; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        .stats-number { font-size: 2em; font-weight: bold; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .tipo-usuario { font-size: 12px; padding: 2px 8px; border-radius: 20px; }
        .tipo-professor { background: #17a2b8; color: white; }
        .tipo-admin { background: #28a745; color: white; }
        .registro-card { transition: transform 0.3s; }
        .registro-card:hover { transform: translateY(-5px); }
        .presente { border-left: 4px solid #28a745; }
        .ausente { border-left: 4px solid #dc3545; }
    </style>
</head>
<body>
  <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <div>
                <h2><i class="fas fa-calendar-check"></i> Chamada</h2>
                <?php if ($is_professor): ?>
                    <span class="tipo-usuario tipo-professor"><i class="fas fa-chalkboard-user"></i> Modo Professor</span>
                <?php else: ?>
                    <span class="tipo-usuario tipo-admin"><i class="fas fa-user-shield"></i> Modo Administrador</span>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Abas para Professor e Admin -->
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="chamadaTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tipo_chamada == 'aula' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#chamadaAula" type="button" role="tab">
                            <i class="fas fa-chalkboard-user"></i> Chamada de Aula
                        </button>
                    </li>
                    <?php if ($is_admin): ?>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $tipo_chamada == 'entrada_saida' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#entradaSaida" type="button" role="tab">
                            <i class="fas fa-door-open"></i> Entrada/Saída
                        </button>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    <!-- Aba Chamada de Aula -->
                    <div class="tab-pane fade <?php echo $tipo_chamada == 'aula' ? 'show active' : ''; ?>" id="chamadaAula" role="tabpanel">
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <label>Turma</label>
                                <select name="turma_id_aula" id="turma_id_aula" class="form-control" onchange="location.href='?turma_id='+this.value+'&data=<?php echo $data; ?>&tipo=aula'">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>" <?php echo $turma_id == $t['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($t['nome']); ?> (<?php echo $t['ano']; ?> - <?php echo ucfirst($t['turno']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Data</label>
                                <input type="date" class="form-control" id="data_aula" value="<?php echo $data; ?>" onchange="location.href='?turma_id=<?php echo $turma_id; ?>&data='+this.value+'&tipo=aula'">
                            </div>
                        </div>
                        
                        <?php if ($turma_id && !empty($alunos)): ?>
                        <!-- Estatísticas do Mês -->
                        <div class="row mb-4">
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number text-success"><?php echo $estatisticas['presentes'] ?? 0; ?></div>
                                    <div>Presentes</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number text-danger"><?php echo $estatisticas['faltas'] ?? 0; ?></div>
                                    <div>Faltas</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number text-warning"><?php echo $estatisticas['justificadas'] ?? 0; ?></div>
                                    <div>Justificadas</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stats-card">
                                    <div class="stats-number"><?php echo $estatisticas['total_dias'] ?? 0; ?></div>
                                    <div>Dias Letivos</div>
                                </div>
                            </div>
                        </div>
                        
                        <form method="POST">
                            <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                            <input type="hidden" name="data" value="<?php echo $data; ?>">
                            <input type="hidden" name="tipo" value="aula">
                            
                            <div class="table-responsive">
                                <table class="table table-bordered">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>Matrícula</th>
                                            <th>Aluno</th>
                                            <th width="250">Presença</th>
                                            <th>Justificativa (para faltas)</th>
                                            <th>Notificar Encarregado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($alunos as $i => $aluno): 
                                            $presenca = $presencas[$aluno['id']] ?? null;
                                            $presente = $presenca ? $presenca['presente'] : 1;
                                            $justificativa = $presenca ? $presenca['justificativa'] : '';
                                        ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td><?php echo $aluno['matricula']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                            <td>
                                                <div class="btn-group">
                                                    <button type="button" class="presenca-btn presenca-presente <?php echo $presente == 1 ? 'active' : ''; ?>" 
                                                            onclick="setPresenca(this, <?php echo $aluno['matricula_id']; ?>, 1)">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                    <button type="button" class="presenca-btn presenca-falta <?php echo $presente == 0 && !$justificativa ? 'active' : ''; ?>" 
                                                            onclick="setPresenca(this, <?php echo $aluno['matricula_id']; ?>, 0)">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                    <button type="button" class="presenca-btn presenca-justificada <?php echo $presente == 0 && $justificativa ? 'active' : ''; ?>" 
                                                            onclick="setPresencaJustificada(this, <?php echo $aluno['matricula_id']; ?>)">
                                                        <i class="fas fa-notes-medical"></i>
                                                    </button>
                                                </div>
                                                <input type="hidden" name="presenca[<?php echo $aluno['matricula_id']; ?>]" id="presenca_<?php echo $aluno['matricula_id']; ?>" value="<?php echo $presente; ?>">
                                             </div>
                                            </td>
                                            <td>
                                                <input type="text" name="justificativa[<?php echo $aluno['matricula_id']; ?>]" 
                                                       class="form-control" placeholder="Motivo da falta" 
                                                       value="<?php echo htmlspecialchars($justificativa); ?>"
                                                       onchange="atualizarJustificativa(this, <?php echo $aluno['matricula_id']; ?>)">
                                             </div>
                                            </td>
                                            <td>
                                                <div class="form-check text-center">
                                                    <input type="checkbox" name="notificar[<?php echo $aluno['matricula_id']; ?>]" class="form-check-input" value="1">
                                                    <label class="form-check-label">
                                                        <i class="fas fa-bell"></i>
                                                    </label>
                                                </div>
                                             </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <div class="row mt-3">
                                <div class="col-md-12">
                                    <div class="form-check">
                                        <input type="checkbox" name="enviar_notificacao" class="form-check-input" id="notificar_todos" value="1">
                                        <label class="form-check-label">
                                            <i class="fas fa-envelope"></i> Enviar notificações para os encarregados dos alunos faltosos
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" name="salvar_chamada" class="btn btn-primary btn-lg px-5">
                                    <i class="fas fa-save"></i> Salvar Chamada
                                </button>
                            </div>
                        </form>
                        <?php elseif ($turma_id && empty($alunos)): ?>
                        <div class="alert alert-warning">Nenhum aluno encontrado nesta turma.</div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Aba Entrada/Saída (Apenas Admin) -->
                    <?php if ($is_admin): ?>
                    <div class="tab-pane fade <?php echo $tipo_chamada == 'entrada_saida' ? 'show active' : ''; ?>" id="entradaSaida" role="tabpanel">
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <label>Data</label>
                                <input type="date" class="form-control" id="data_es" value="<?php echo $data; ?>" onchange="location.href='?tipo=entrada_saida&data='+this.value">
                            </div>
                            <div class="col-md-8">
                                <label>&nbsp;</label>
                                <div>
                                    <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalRegistrarEntrada">
                                        <i class="fas fa-sign-in-alt"></i> Registrar Entrada
                                    </button>
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalRegistrarSaida">
                                        <i class="fas fa-sign-out-alt"></i> Registrar Saída
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Hora Entrada</th>
                                        <th>Hora Saída</th>
                                        <th>Matrícula</th>
                                        <th>Aluno</th>
                                        <th>Observações</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($entradas_saidas as $reg): 
                                        $status = $reg['hora_saida'] ? 'Completo' : 'Na escola';
                                        $status_class = $reg['hora_saida'] ? 'success' : 'warning';
                                    ?>
                                    <tr>
                                        <td><?php echo date('H:i', strtotime($reg['hora_entrada'])); ?></td>
                                        <td><?php echo $reg['hora_saida'] ? date('H:i', strtotime($reg['hora_saida'])) : '-'; ?></td>
                                        <td><?php echo $reg['matricula']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($reg['estudante_nome']); ?></strong></td>
                                        <td>
                                            <?php if ($reg['observacao_entrada']): ?>
                                            <small class="text-muted">Entrada: <?php echo htmlspecialchars($reg['observacao_entrada']); ?></small><br>
                                            <?php endif; ?>
                                            <?php if ($reg['observacao_saida']): ?>
                                            <small class="text-muted">Saída: <?php echo htmlspecialchars($reg['observacao_saida']); ?></small>
                                            <?php endif; ?>
                                         </div>
                                        </td>
                                        <td><span class="badge bg-<?php echo $status_class; ?>"><?php echo $status; ?></span></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($entradas_saidas)): ?>
                                    <tr><td colspan="6" class="text-center">Nenhum registro para esta data</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Registrar Entrada -->
    <div class="modal fade" id="modalRegistrarEntrada" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sign-in-alt"></i> Registrar Entrada</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tipo_registro" value="entrada">
                    <input type="hidden" name="tipo" value="entrada_saida">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Aluno</label>
                            <select name="estudante_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($alunos_escola as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Observação</label>
                            <textarea name="observacao" class="form-control" rows="2" placeholder="Opcional"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="enviar_notificacao" class="form-check-input" value="1">
                            <label class="form-check-label">Notificar encarregado</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_entrada_saida" class="btn btn-success">Registrar Entrada</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Registrar Saída -->
    <div class="modal fade" id="modalRegistrarSaida" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-sign-out-alt"></i> Registrar Saída</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="tipo_registro" value="saida">
                    <input type="hidden" name="tipo" value="entrada_saida">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label>Aluno</label>
                            <select name="estudante_id" class="form-control" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($alunos_escola as $a): ?>
                                <option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['nome']); ?> (<?php echo $a['matricula']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label>Observação</label>
                            <textarea name="observacao" class="form-control" rows="2" placeholder="Opcional"></textarea>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="enviar_notificacao" class="form-check-input" value="1">
                            <label class="form-check-label">Notificar encarregado</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_entrada_saida" class="btn btn-warning">Registrar Saída</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function setPresenca(btn, matriculaId, valor) {
            $(btn).closest('.btn-group').find('.presenca-btn').removeClass('active');
            $(btn).addClass('active');
            $('#presenca_' + matriculaId).val(valor);
            
            if (valor == 1) {
                $(btn).closest('tr').find('input[name*="justificativa"]').val('');
            }
        }
        
        function setPresencaJustificada(btn, matriculaId) {
            $(btn).closest('.btn-group').find('.presenca-btn').removeClass('active');
            $(btn).addClass('active');
            $('#presenca_' + matriculaId).val(0);
        }
        
        function atualizarJustificativa(input, matriculaId) {
            if ($(input).val()) {
                $('#presenca_' + matriculaId).val(0);
                $(input).closest('tr').find('.presenca-btn').removeClass('active');
                $(input).closest('tr').find('.presenca-justificada').addClass('active');
            } else {
                if ($('#presenca_' + matriculaId).val() == 0) {
                    $(input).closest('tr').find('.presenca-falta').addClass('active');
                }
            }
        }
        
        // Ativar a tab correta
        var tipoChamada = '<?php echo $tipo_chamada; ?>';
        if (tipoChamada == 'entrada_saida') {
            $('#chamadaTabs button[data-bs-target="#entradaSaida"]').tab('show');
        }
    </script>
</body>
</html>