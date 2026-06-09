<?php
// aluno/comunicacao/contato.php - Central de Contato do Aluno

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

// Buscar ano letivo ativo
$sql_ano_letivo = "SELECT id FROM anos_letivos 
                   WHERE escola_id = :escola_id AND status = 'ativo' 
                   LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : null;

// Buscar dados do aluno
$sql_aluno = "SELECT e.id, e.nome, e.matricula, e.email, e.telefone,
                     tur.nome as turma_nome, tur.ano as turma_ano,
                     u.email as usuario_email
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN turmas tur ON tur.id = m.turma_id
              LEFT JOIN usuarios u ON u.id = e.usuario_id
              WHERE e.id = :id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// Buscar dados da escola (com os novos campos)
$sql_escola = "SELECT nome, telefone, whatsapp, email, site, facebook, instagram, youtube, linkedin, endereco, logo
               FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Buscar professores do aluno usando a tabela professor_disciplina_turma
$sql_professores = "SELECT DISTINCT 
                           p.id, 
                           p.nome, 
                           p.email, 
                           p.telefone, 
                           p.foto, 
                           d.id as disciplina_id, 
                           d.nome as disciplina_nome, 
                           pdt.dia_semana,
                           pdt.horario_inicio,
                           pdt.horario_fim
                    FROM funcionarios p
                    JOIN professor_disciplina_turma pdt ON pdt.professor_id = p.id
                    JOIN disciplinas d ON d.id = pdt.disciplina_id
                    JOIN turmas tur ON tur.id = pdt.turma_id
                    JOIN matriculas m ON m.turma_id = tur.id
                    WHERE m.estudante_id = :aluno_id 
                    AND m.status = 'ativa'
                    AND m.escola_id = :escola_id
                    AND p.escola_id = :escola_id1
                    AND p.status = 'ativo'
                    AND pdt.status = 'ativo'
                    AND d.status = 'ativa'
                    " . ($ano_letivo_id ? "AND pdt.ano_letivo_id = :ano_letivo_id" : "") . "
                    ORDER BY d.nome, p.nome";

$stmt_professores = $conn->prepare($sql_professores);
$params = [
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':escola_id1' => $escola_id
];
if ($ano_letivo_id) {
    $params[':ano_letivo_id'] = $ano_letivo_id;
}
$stmt_professores->execute($params);
$professores = $stmt_professores->fetchAll(PDO::FETCH_ASSOC);

// Buscar contatos da secretaria
$sql_secretaria = "SELECT id, nome, email, telefone, cargo, horario_atendimento
                   FROM contatos_escola 
                   WHERE escola_id = :escola_id 
                   AND tipo IN ('secretaria', 'direcao', 'financeiro')
                   AND status = 'ativo'
                   ORDER BY ordem, nome";
$stmt_secretaria = $conn->prepare($sql_secretaria);
$stmt_secretaria->execute([':escola_id' => $escola_id]);
$secretaria = $stmt_secretaria->fetchAll(PDO::FETCH_ASSOC);

// Se não houver contatos específicos, usar dados padrão da escola
if (empty($secretaria)) {
    $secretaria = [
        [
            'id' => 1,
            'nome' => 'Secretaria Acadêmica', 
            'email' => $escola['email'] ?? 'secretaria@escola.ao', 
            'telefone' => $escola['telefone'] ?? '923456789', 
            'cargo' => 'Secretaria Geral', 
            'horario_atendimento' => '08:00 - 17:00'
        ]
    ];
}

// ============================================
// PROCESSAR ENVIO DE MENSAGEM
// ============================================
$mensagem_sucesso = '';
$mensagem_erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $destinatario_tipo = $_POST['destinatario_tipo'] ?? '';
    $destinatario_id = (int)($_POST['destinatario_id'] ?? 0);
    $assunto = trim($_POST['assunto'] ?? '');
    $mensagem = trim($_POST['mensagem'] ?? '');
    $prioridade = $_POST['prioridade'] ?? 'normal';
    
    // Validações
    if (empty($destinatario_tipo)) {
        $mensagem_erro = "Selecione o destinatário.";
    } elseif (empty($assunto)) {
        $mensagem_erro = "Informe o assunto da mensagem.";
    } elseif (empty($mensagem)) {
        $mensagem_erro = "Digite sua mensagem.";
    } elseif (strlen($mensagem) < 10) {
        $mensagem_erro = "A mensagem deve ter pelo menos 10 caracteres.";
    } else {
        // Inserir mensagem
        $sql_insert = "INSERT INTO mensagens_contato 
                       (escola_id, aluno_id, destinatario_tipo, destinatario_id, 
                        assunto, mensagem, prioridade, status, data_envio)
                       VALUES 
                       (:escola_id, :aluno_id, :destinatario_tipo, :destinatario_id,
                        :assunto, :mensagem, :prioridade, 'enviada', NOW())";
        
        $stmt_insert = $conn->prepare($sql_insert);
        $result = $stmt_insert->execute([
            ':escola_id' => $escola_id,
            ':aluno_id' => $aluno_id,
            ':destinatario_tipo' => $destinatario_tipo,
            ':destinatario_id' => $destinatario_id,
            ':assunto' => $assunto,
            ':mensagem' => $mensagem,
            ':prioridade' => $prioridade
        ]);
        
        if ($result) {
            $mensagem_sucesso = "Mensagem enviada com sucesso! Você receberá uma resposta em breve.";
            
            // Opcional: Enviar notificação para o destinatário
            if ($destinatario_tipo == 'professor') {
                // Buscar email do professor
                $sql_prof = "SELECT email FROM professores WHERE id = :id";
                $stmt_prof = $conn->prepare($sql_prof);
                $stmt_prof->execute([':id' => $destinatario_id]);
                $prof = $stmt_prof->fetch(PDO::FETCH_ASSOC);
                
                if ($prof && $prof['email']) {
                    // Aqui poderia ser implementado envio de email
                    // mail($prof['email'], $assunto, $mensagem, "From: {$aluno['email']}");
                }
            }
            
            // Limpar formulário
            $_POST = [];
        } else {
            $mensagem_erro = "Erro ao enviar mensagem. Tente novamente.";
        }
    }
}

// Buscar histórico de mensagens do aluno
$sql_historico = "SELECT m.*,
                         CASE 
                             WHEN m.destinatario_tipo = 'professor' THEN p.nome
                             WHEN m.destinatario_tipo IN ('secretaria', 'direcao', 'financeiro') THEN c.nome
                             ELSE 'Escola'
                         END as destinatario_nome,
                         CASE 
                             WHEN m.destinatario_tipo = 'professor' THEN 'Professor'
                             WHEN m.destinatario_tipo = 'secretaria' THEN 'Secretaria'
                             WHEN m.destinatario_tipo = 'direcao' THEN 'Direção'
                             WHEN m.destinatario_tipo = 'financeiro' THEN 'Financeiro'
                             ELSE 'Escola'
                         END as destinatario_tipo_label
                  FROM mensagens_contato m
                  LEFT JOIN funcionarios p ON p.id = m.destinatario_id AND m.destinatario_tipo = 'professor'
                  LEFT JOIN contatos_escola c ON c.id = m.destinatario_id AND m.destinatario_tipo IN ('secretaria', 'direcao', 'financeiro')
                  WHERE m.aluno_id = :aluno_id AND m.escola_id = :escola_id
                  ORDER BY m.data_envio DESC
                  LIMIT 20";
$stmt_historico = $conn->prepare($sql_historico);
$stmt_historico->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$mensagens = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central de Contato | Área do Aluno</title>
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
        
        .contato-card {
            transition: all 0.3s;
            cursor: pointer;
        }
        .contato-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .professor-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 500;
        }
        .status-enviada { background: #17a2b8; color: white; }
        .status-lida { background: #28a745; color: white; }
        .status-respondida { background: #006B3E; color: white; }
        
        .mensagem-item {
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }
        .mensagem-item:hover {
            background: #f8f9fa;
        }
        .mensagem-nao-lida {
            background: #f0fdf4;
            border-left-color: #006B3E;
        }
        
        .whatsapp-btn {
            background: #25D366;
            color: white;
            transition: all 0.3s;
        }
        .whatsapp-btn:hover {
            background: #128C7E;
            color: white;
            transform: scale(1.05);
        }
        
        .btn-enviar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border: none;
            transition: all 0.3s;
        }
        .btn-enviar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,107,62,0.3);
        }
        
        .info-escola {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-radius: 10px;
            padding: 15px;
        }
        
        .btn-social {
            transition: all 0.3s;
        }
        .btn-social:hover {
            transform: translateY(-2px);
        }
        
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
   <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
            <div>
                <h2><i class="fas fa-phone-alt"></i> Central de Contato</h2>
                <p class="text-muted">Entre em contato com professores, secretaria ou envie suas dúvidas</p>
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
            <!-- Formulário de Contato -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-paper-plane"></i> Nova Mensagem</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formContato">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Destinatário</label>
                                <div class="row g-2">
                                    <div class="col-6">
                                        <input type="radio" name="destinatario_tipo" value="professor" id="radioProfessor" class="btn-check" onchange="toggleDestinatario()">
                                        <label class="btn btn-outline-primary w-100" for="radioProfessor">
                                            <i class="fas fa-chalkboard-teacher"></i> Professor
                                        </label>
                                    </div>
                                    <div class="col-6">
                                        <input type="radio" name="destinatario_tipo" value="secretaria" id="radioSecretaria" class="btn-check" onchange="toggleDestinatario()">
                                        <label class="btn btn-outline-success w-100" for="radioSecretaria">
                                            <i class="fas fa-building"></i> Secretaria
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3" id="divProfessor" style="display: none;">
                                <label class="form-label fw-bold">Selecione o Professor</label>
                                <select class="form-select" name="destinatario_id" id="professorSelect">
                                    <option value="">Selecione um professor</option>
                                    <?php foreach ($professores as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>" 
                                            data-nome="<?php echo htmlspecialchars($prof['nome']); ?>"
                                            data-email="<?php echo htmlspecialchars($prof['email']); ?>">
                                        <?php echo htmlspecialchars($prof['nome']); ?> - <?php echo htmlspecialchars($prof['disciplina_nome']); ?>
                                        <?php if ($prof['dia_semana']): ?>
                                        (<?php echo $prof['dia_semana']; ?> - <?php echo substr($prof['horario_inicio'], 0, 5); ?>h)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (empty($professores)): ?>
                                <small class="text-danger">Nenhum professor encontrado para sua turma.</small>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-3" id="divSecretaria" style="display: none;">
                                <label class="form-label fw-bold">Selecione o Departamento</label>
                                <select class="form-select" name="destinatario_id" id="secretariaSelect">
                                    <option value="">Selecione um departamento</option>
                                    <?php foreach ($secretaria as $sec): ?>
                                    <option value="<?php echo $sec['id']; ?>" 
                                            data-nome="<?php echo htmlspecialchars($sec['nome']); ?>"
                                            data-email="<?php echo htmlspecialchars($sec['email']); ?>">
                                        <?php echo htmlspecialchars($sec['nome']); ?> - <?php echo htmlspecialchars($sec['cargo']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Assunto</label>
                                <input type="text" class="form-control" name="assunto" required 
                                       placeholder="Ex: Dúvida sobre matéria, Solicitação de documento...">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Mensagem</label>
                                <textarea class="form-control" name="mensagem" rows="6" required
                                          placeholder="Descreva sua dúvida ou solicitação de forma clara e detalhada..."></textarea>
                                <small class="text-muted">Mínimo de 10 caracteres</small>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label fw-bold">Prioridade</label>
                                <select class="form-select" name="prioridade">
                                    <option value="baixa">📌 Baixa - Dúvida simples</option>
                                    <option value="normal" selected>📋 Normal - Informação geral</option>
                                    <option value="alta">⚠️ Alta - Urgente</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="btn btn-enviar w-100">
                                <i class="fas fa-paper-plane"></i> Enviar Mensagem
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Informações de Contato Rápido da Escola -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-phone"></i> Contato da Escola</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-escola">
                            <h6><i class="fas fa-school"></i> <?php echo htmlspecialchars($escola['nome']); ?></h6>
                            <p class="mb-1"><i class="fas fa-phone"></i> Telefone: <?php echo $escola['telefone'] ?? 'Não informado'; ?></p>
                            <?php if ($escola['whatsapp']): ?>
                            <p class="mb-1"><i class="fab fa-whatsapp"></i> WhatsApp: <?php echo $escola['whatsapp']; ?></p>
                            <?php endif; ?>
                            <p class="mb-1"><i class="fas fa-envelope"></i> Email: <?php echo $escola['email'] ?? 'Não informado'; ?></p>
                            <?php if ($escola['site']): ?>
                            <p class="mb-1"><i class="fas fa-globe"></i> Site: <a href="<?php echo $escola['site']; ?>" target="_blank"><?php echo $escola['site']; ?></a></p>
                            <?php endif; ?>
                            <p class="mb-0"><i class="fas fa-map-marker-alt"></i> Endereço: <?php echo $escola['endereco'] ?? 'Não informado'; ?></p>
                        </div>
                        
                        <!-- Redes Sociais -->
                        <?php if ($escola['facebook'] || $escola['instagram'] || $escola['youtube'] || $escola['linkedin']): ?>
                        <div class="mt-3">
                            <label class="fw-bold">Redes Sociais:</label>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <?php if ($escola['facebook']): ?>
                                <a href="<?php echo $escola['facebook']; ?>" target="_blank" class="btn btn-sm btn-primary btn-social">
                                    <i class="fab fa-facebook-f"></i> Facebook
                                </a>
                                <?php endif; ?>
                                <?php if ($escola['instagram']): ?>
                                <a href="<?php echo $escola['instagram']; ?>" target="_blank" class="btn btn-sm btn-danger btn-social">
                                    <i class="fab fa-instagram"></i> Instagram
                                </a>
                                <?php endif; ?>
                                <?php if ($escola['youtube']): ?>
                                <a href="<?php echo $escola['youtube']; ?>" target="_blank" class="btn btn-sm btn-danger btn-social">
                                    <i class="fab fa-youtube"></i> YouTube
                                </a>
                                <?php endif; ?>
                                <?php if ($escola['linkedin']): ?>
                                <a href="<?php echo $escola['linkedin']; ?>" target="_blank" class="btn btn-sm btn-info btn-social">
                                    <i class="fab fa-linkedin-in"></i> LinkedIn
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($escola['whatsapp']): ?>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $escola['whatsapp']); ?>" target="_blank" class="btn whatsapp-btn w-100 mt-3">
                            <i class="fab fa-whatsapp"></i> Falar no WhatsApp
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Lista de Professores e Contatos -->
            <div class="col-lg-7">
                <!-- Professores -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chalkboard-teacher"></i> Seus Professores</h5>
                        <small>Professores que lecionam na sua turma</small>
                    </div>
                    <div class="card-body">
                        <?php if (empty($professores)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-user-graduate fa-3x text-muted mb-2"></i>
                                <p class="text-muted">Nenhum professor encontrado para sua turma.</p>
                                <small>Entre em contato com a secretaria para mais informações.</small>
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($professores as $prof): ?>
                                <div class="col-md-6 mb-3">
                                    <div class="contato-card p-3 border rounded" onclick="selecionarProfessor(<?php echo $prof['id']; ?>, '<?php echo addslashes($prof['nome']); ?>')">
                                        <div class="d-flex align-items-center">
                                            <div class="professor-avatar me-3">
                                                <?php if (!empty($prof['foto'])): ?>
                                                <img src="<?php echo $prof['foto']; ?>" class="w-100 h-100 rounded-circle" style="object-fit: cover;">
                                                <?php else: ?>
                                                <i class="fas fa-user-graduate fa-2x text-secondary"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0"><?php echo htmlspecialchars($prof['nome']); ?></h6>
                                                <small class="text-muted">
                                                    <i class="fas fa-book" style="color: <?php echo $prof['disciplina_cor'] ?? '#006B3E'; ?>"></i>
                                                    <?php echo htmlspecialchars($prof['disciplina_nome']); ?>
                                                </small>
                                                <?php if ($prof['dia_semana']): ?>
                                                <div class="mt-1">
                                                    <small class="text-muted">
                                                        <i class="fas fa-calendar-alt"></i> 
                                                        <?php echo $prof['dia_semana']; ?> - <?php echo substr($prof['horario_inicio'], 0, 5); ?>h
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                                <div class="mt-1">
                                                    <small><i class="fas fa-envelope"></i> <?php echo $prof['email'] ?? 'Email não disponível'; ?></small>
                                                </div>
                                            </div>
                                            <div>
                                                <i class="fas fa-chevron-right text-muted"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Secretaria / Administração -->
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-building"></i> Secretaria / Administração</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($secretaria as $sec): ?>
                            <div class="col-md-6 mb-3">
                                <div class="contato-card p-3 border rounded" onclick="selecionarSecretaria(<?php echo $sec['id']; ?>, '<?php echo addslashes($sec['nome']); ?>')">
                                    <div class="d-flex align-items-center">
                                        <div class="professor-avatar me-3 bg-success bg-opacity-10">
                                            <i class="fas fa-user-tie fa-2x text-success"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-0"><?php echo htmlspecialchars($sec['nome']); ?></h6>
                                            <small class="text-muted"><?php echo htmlspecialchars($sec['cargo']); ?></small>
                                            <div class="mt-1">
                                                <small><i class="fas fa-envelope"></i> <?php echo $sec['email']; ?></small>
                                            </div>
                                            <?php if ($sec['horario_atendimento']): ?>
                                            <div class="mt-1">
                                                <small class="text-muted"><i class="fas fa-clock"></i> Atendimento: <?php echo $sec['horario_atendimento']; ?></small>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <i class="fas fa-chevron-right text-muted"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Histórico de Mensagens -->
                <?php if (!empty($mensagens)): ?>
                <div class="card mt-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Histórico de Mensagens</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="list-group list-group-flush">
                            <?php foreach ($mensagens as $msg): ?>
                            <div class="list-group-item mensagem-item <?php echo $msg['lida'] == 0 ? 'mensagem-nao-lida' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <div class="d-flex align-items-center mb-1 flex-wrap">
                                            <strong><?php echo htmlspecialchars($msg['assunto']); ?></strong>
                                            <span class="status-badge status-<?php echo $msg['status']; ?> ms-2">
                                                <?php 
                                                $status_labels = [
                                                    'enviada' => 'Enviada',
                                                    'lida' => 'Lida',
                                                    'respondida' => 'Respondida'
                                                ];
                                                echo $status_labels[$msg['status']] ?? ucfirst($msg['status']);
                                                ?>
                                            </span>
                                            <?php if ($msg['prioridade'] == 'alta'): ?>
                                            <span class="badge bg-danger ms-2">Urgente</span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted">
                                            Para: <?php echo htmlspecialchars($msg['destinatario_nome']); ?> (<?php echo $msg['destinatario_tipo_label']; ?>) | 
                                            <?php echo date('d/m/Y H:i', strtotime($msg['data_envio'])); ?>
                                        </small>
                                        <p class="mb-0 mt-2 small"><?php echo htmlspecialchars(substr($msg['mensagem'], 0, 100)) . (strlen($msg['mensagem']) > 100 ? '...' : ''); ?></p>
                                        <?php if ($msg['resposta']): ?>
                                        <div class="mt-2 p-2 bg-light rounded">
                                            <small><i class="fas fa-reply text-success"></i> <strong>Resposta:</strong> <?php echo htmlspecialchars(substr($msg['resposta'], 0, 100)); ?></small>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <button class="btn btn-sm btn-outline-info" onclick="verMensagem(<?php echo $msg['id']; ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Dicas -->
        <div class="card mt-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-lightbulb"></i> Dicas para um bom contato</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <i class="fas fa-info-circle fa-2x text-info mb-2"></i>
                        <p><small>Seja claro e específico na sua mensagem</small></p>
                    </div>
                    <div class="col-md-3 text-center">
                        <i class="fas fa-clock fa-2x text-warning mb-2"></i>
                        <p><small>Respeite o horário de atendimento</small></p>
                    </div>
                    <div class="col-md-3 text-center">
                        <i class="fas fa-smile fa-2x text-success mb-2"></i>
                        <p><small>Mantenha a educação e o respeito</small></p>
                    </div>
                    <div class="col-md-3 text-center">
                        <i class="fas fa-reply-all fa-2x text-primary mb-2"></i>
                        <p><small>Aguardar até 48h pela resposta</small></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Visualizar Mensagem -->
    <div class="modal fade" id="mensagemModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-envelope"></i> Detalhes da Mensagem</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="mensagemConteudo">
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
        
        // Alternar destinatário
        function toggleDestinatario() {
            let radioProfessor = document.getElementById('radioProfessor').checked;
            let radioSecretaria = document.getElementById('radioSecretaria').checked;
            
            document.getElementById('divProfessor').style.display = radioProfessor ? 'block' : 'none';
            document.getElementById('divSecretaria').style.display = radioSecretaria ? 'block' : 'none';
            
            // Limpar selects
            if (!radioProfessor) {
                document.getElementById('professorSelect').value = '';
            }
            if (!radioSecretaria) {
                document.getElementById('secretariaSelect').value = '';
            }
        }
        
        // Selecionar professor do card
        function selecionarProfessor(id, nome) {
            document.getElementById('radioProfessor').checked = true;
            toggleDestinatario();
            document.getElementById('professorSelect').value = id;
            
            // Adicionar assunto sugerido
            let assunto = document.querySelector('input[name="assunto"]');
            if (!assunto.value) {
                assunto.value = `Dúvida - ${nome}`;
            }
            
            // Scroll para o formulário
            document.getElementById('formContato').scrollIntoView({ behavior: 'smooth' });
            
            // Destacar o campo selecionado
            document.getElementById('professorSelect').style.border = '2px solid #006B3E';
            setTimeout(() => {
                document.getElementById('professorSelect').style.border = '';
            }, 2000);
        }
        
        // Selecionar secretaria do card
        function selecionarSecretaria(id, nome) {
            document.getElementById('radioSecretaria').checked = true;
            toggleDestinatario();
            document.getElementById('secretariaSelect').value = id;
            
            // Adicionar assunto sugerido
            let assunto = document.querySelector('input[name="assunto"]');
            if (!assunto.value) {
                assunto.value = `Solicitação - ${nome}`;
            }
            
            // Scroll para o formulário
            document.getElementById('formContato').scrollIntoView({ behavior: 'smooth' });
            
            // Destacar o campo selecionado
            document.getElementById('secretariaSelect').style.border = '2px solid #28a745';
            setTimeout(() => {
                document.getElementById('secretariaSelect').style.border = '';
            }, 2000);
        }
        
        // Ver mensagem completa via AJAX
        function verMensagem(id) {
            // Buscar dados via AJAX
            $.ajax({
                url: 'ajax_mensagem.php',
                method: 'GET',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        let html = `
                            <div class="mb-3">
                                <small class="text-muted">Enviado em: ${new Date(response.data_envio).toLocaleString('pt-BR')}</small>
                            </div>
                            <div class="alert alert-info">
                                <strong>Assunto:</strong> ${response.assunto}
                            </div>
                            <div class="alert alert-light">
                                <strong>Mensagem:</strong>
                                <p class="mt-2">${response.mensagem.replace(/\n/g, '<br>')}</p>
                            </div>
                            ${response.resposta ? `
                            <div class="alert alert-success">
                                <strong><i class="fas fa-reply"></i> Resposta:</strong>
                                <p class="mt-2">${response.resposta.replace(/\n/g, '<br>')}</p>
                            </div>
                            ` : '<div class="alert alert-secondary">Aguardando resposta...</div>'}
                        `;
                        $('#mensagemConteudo').html(html);
                        new bootstrap.Modal(document.getElementById('mensagemModal')).show();
                        
                        // Marcar como lida
                        if (!response.lida) {
                            $.ajax({
                                url: 'ajax_marcar_lida.php',
                                method: 'POST',
                                data: { id: id }
                            });
                        }
                    } else {
                        alert('Erro ao carregar mensagem');
                    }
                },
                error: function() {
                    alert('Erro ao carregar mensagem. Tente novamente.');
                }
            });
        }
        
        // Validar formulário antes de enviar
        document.getElementById('formContato')?.addEventListener('submit', function(e) {
            let destinatarioTipo = document.querySelector('input[name="destinatario_tipo"]:checked');
            let destinatarioId = document.querySelector('select[name="destinatario_id"]').value;
            
            if (!destinatarioTipo) {
                e.preventDefault();
                alert('Selecione o destinatário da mensagem.');
                return false;
            }
            
            if (!destinatarioId || destinatarioId === '') {
                e.preventDefault();
                let tipo = destinatarioTipo.value;
                if (tipo === 'professor') {
                    alert('Selecione um professor.');
                } else {
                    alert('Selecione um departamento.');
                }
                return false;
            }
            
            let mensagem = document.querySelector('textarea[name="mensagem"]').value;
            if (mensagem.length < 10) {
                e.preventDefault();
                alert('A mensagem deve ter pelo menos 10 caracteres.');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>