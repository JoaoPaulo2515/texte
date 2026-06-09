<?php
// escola/professor/proposta_prova.php - Submeter Proposta de Prova

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// INICIALIZAR VARIÁVEIS
// ============================================
$success = '';
$error = '';

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "
    SELECT p.id, u.nome as professor_nome, u.email
    FROM funcionarios p
    INNER JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :professor_id
";
$stmt_prof = $conn->prepare($sql_professor);
$stmt_prof->execute([':professor_id' => $professor_id]);
$professor_dados = $stmt_prof->fetch(PDO::FETCH_ASSOC);
$professor_nome = $professor_dados['professor_nome'] ?? '';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->query($sql_ano);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_atual = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR (via professor_disciplina_turma)
// ============================================
$sql_turmas = "
    SELECT DISTINCT t.id, t.nome, t.ano, t.turno
    FROM turmas t
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :professor_id AND t.status = 'ativa'
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR (via professor_disciplina_turma)
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT d.id, d.nome, d.codigo
    FROM disciplinas d
    INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TURMAS E DISCIPLINAS COMBINADAS (para validação)
// ============================================
$sql_combinacoes = "
    SELECT DISTINCT 
        pdt.turma_id, 
        t.nome as turma_nome,
        pdt.disciplina_id,
        d.nome as disciplina_nome,
        pdt.ano_letivo_id
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome, d.nome
";
$stmt_combinacoes = $conn->prepare($sql_combinacoes);
$stmt_combinacoes->execute([':professor_id' => $professor_id]);
$combinacoes = $stmt_combinacoes->fetchAll(PDO::FETCH_ASSOC);

// Criar arrays para validação JavaScript
$combinacoes_json = [];
foreach ($combinacoes as $c) {
    $combinacoes_json[$c['turma_id']][] = $c['disciplina_id'];
}

// ============================================
// TIPOS DE PROVA
// ============================================
$tipos_prova = [
    'normal' => 'Prova Normal',
    'recuperacao' => 'Prova de Recuperação',
    'exame' => 'Exame Final',
    'recurso' => 'Prova de Recurso',
    'especial' => 'Prova Especial'
];

// ============================================
// PROCESSAR SUBMISSÃO DA PROPOSTA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submeter_proposta'])) {
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $tipo_prova = $_POST['tipo_prova'] ?? 'normal';
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao']);
    $conteudo = $_POST['conteudo'] ?? '';
    $data_prevista = $_POST['data_prevista'] ?? '';
    $duracao = (int)$_POST['duracao'] ?? 60;
    $peso = (float)$_POST['peso'] ?? 10;
    
    // Validações
    if ($turma_id <= 0) {
        $error = "⚠️ Selecione uma turma.";
    } elseif ($disciplina_id <= 0) {
        $error = "⚠️ Selecione uma disciplina.";
    } elseif ($bimestre < 1 || $bimestre > 4) {
        $error = "⚠️ Selecione um bimestre válido.";
    } elseif (empty($titulo)) {
        $error = "⚠️ Informe o título da prova.";
    } elseif (empty($conteudo) || $conteudo == '<p><br></p>') {
        $error = "⚠️ Descreva o conteúdo da prova.";
    } elseif (empty($data_prevista)) {
        $error = "⚠️ Informe a data prevista para a prova.";
    } else {
        // Validar se o professor realmente leciona essa disciplina nessa turma
        $sql_check = "
            SELECT id FROM professor_disciplina_turma 
            WHERE professor_id = :professor_id 
            AND turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            LIMIT 1
        ";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':professor_id' => $professor_id,
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id
        ]);
        
        if ($stmt_check->rowCount() == 0) {
            $error = "⚠️ Você não está associado a esta combinação de Turma e Disciplina.";
        } else {
            try {
                $conn->beginTransaction();
                
                // Processar upload de arquivo (se houver)
                $arquivo_path = null;
                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../../uploads/propostas_prova/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $extensao = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
                    $extensoes_permitidas = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    
                    if (in_array($extensao, $extensoes_permitidas)) {
                        $nome_arquivo = 'proposta_' . time() . '_' . uniqid() . '.' . $extensao;
                        $arquivo_path = 'uploads/propostas_prova/' . $nome_arquivo;
                        $caminho_completo = $upload_dir . $nome_arquivo;
                        
                        if (!move_uploaded_file($_FILES['anexo']['tmp_name'], $caminho_completo)) {
                            throw new Exception("Erro ao fazer upload do arquivo.");
                        }
                    } else {
                        throw new Exception("Tipo de arquivo não permitido.");
                    }
                }
                
                // Inserir proposta
                $sql = "INSERT INTO propostas_prova (
                            professor_id, escola_id, ano_letivo_id,
                            turma_id, disciplina_id, bimestre, tipo_prova,
                            titulo, descricao, conteudo, data_prevista,
                            duracao, peso, anexo, status
                        ) VALUES (
                            :professor_id, :escola_id, :ano_letivo_id,
                            :turma_id, :disciplina_id, :bimestre, :tipo_prova,
                            :titulo, :descricao, :conteudo, :data_prevista,
                            :duracao, :peso, :anexo, 'pendente'
                        )";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':professor_id' => $professor_id,
                    ':escola_id' => $escola_id,
                    ':ano_letivo_id' => $ano_letivo_id,
                    ':turma_id' => $turma_id,
                    ':disciplina_id' => $disciplina_id,
                    ':bimestre' => $bimestre,
                    ':tipo_prova' => $tipo_prova,
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':conteudo' => $conteudo,
                    ':data_prevista' => $data_prevista,
                    ':duracao' => $duracao,
                    ':peso' => $peso,
                    ':anexo' => $arquivo_path
                ]);
                
                $proposta_id = $conn->lastInsertId();
                
                $conn->commit();
                $success = "✅ Proposta de prova enviada com sucesso! Aguarde a aprovação da coordenação.";
                
            } catch (PDOException $e) {
                $conn->rollBack();
                $error = "Erro ao enviar proposta: " . $e->getMessage();
            } catch (Exception $e) {
                $conn->rollBack();
                $error = $e->getMessage();
            }
        }
    }
}

// ============================================
// BUSCAR PROPOSTAS ANTERIORES
// ============================================
$sql_propostas = "
    SELECT p.*,
           t.nome as turma_nome, t.ano,
           d.nome as disciplina_nome,
           CASE 
               WHEN p.status = 'pendente' THEN 'Pendente'
               WHEN p.status = 'aprovado' THEN 'Aprovado'
               WHEN p.status = 'reprovado' THEN 'Reprovado'
               WHEN p.status = 'revisao' THEN 'Revisão'
               ELSE p.status
           END as status_texto
    FROM propostas_prova p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    WHERE p.professor_id = :professor_id
    ORDER BY p.created_at DESC
    LIMIT 20
";
$stmt_propostas = $conn->prepare($sql_propostas);
$stmt_propostas->execute([':professor_id' => $professor_id]);
$propostas = $stmt_propostas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_propostas = count($propostas);
$total_pendente = 0;
$total_aprovado = 0;
$total_reprovado = 0;

foreach ($propostas as $prop) {
    if ($prop['status'] == 'pendente') $total_pendente++;
    if ($prop['status'] == 'aprovado') $total_aprovado++;
    if ($prop['status'] == 'reprovado') $total_reprovado++;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadgeProposta($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'aprovado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'revisao':
            return '<span class="badge bg-info"><i class="fas fa-edit"></i> Revisão</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}

function getTipoProvaBadge($tipo) {
    switch ($tipo) {
        case 'normal':
            return '<span class="badge bg-primary">Normal</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning text-dark">Recuperação</span>';
        case 'exame':
            return '<span class="badge bg-danger">Exame Final</span>';
        case 'recurso':
            return '<span class="badge bg-info">Recurso</span>';
        case 'especial':
            return '<span class="badge bg-secondary">Especial</span>';
        default:
            return '<span class="badge bg-secondary">' . $tipo . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proposta de Prova | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    <style>
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px; padding: 20px; margin-bottom: 20px; }
        .info-card { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .info-title { font-size: 1.1em; font-weight: bold; color: #006B3E; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #006B3E; }
        .stat-card { background: white; border-radius: 12px; padding: 15px; text-align: center; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .stat-number { font-size: 28px; font-weight: bold; color: #006B3E; }
        .stat-label { font-size: 12px; color: #666; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; border: none; text-decoration: none; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        .btn-enviar { background: #28a745; color: white; border-radius: 25px; padding: 10px 30px; border: none; font-weight: bold; }
        .btn-enviar:hover { background: #1e7e34; color: white; }
        .btn-ajuda { background: #fd7e14; color: white; border-radius: 25px; padding: 8px 20px; border: none; }
        .btn-ver { background: #17a2b8; color: white; border-radius: 20px; padding: 5px 15px; font-size: 12px; border: none; }
        .main-content { margin-left: 280px; padding: 20px; background: #f5f7fb; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .proposta-card { background: white; border-radius: 12px; margin-bottom: 15px; padding: 15px; border-left: 4px solid #ffc107; transition: transform 0.2s; }
        .proposta-card:hover { transform: translateX(5px); }
        .proposta-card.aprovado { border-left-color: #28a745; }
        .proposta-card.reprovado { border-left-color: #dc3545; }
        .proposta-card.revisao { border-left-color: #17a2b8; }
        .upload-area { border: 2px dashed #ddd; border-radius: 10px; padding: 20px; text-align: center; cursor: pointer; transition: all 0.3s; }
        .upload-area:hover { border-color: #006B3E; background: #f5f5f5; }
        .upload-area.dragover { border-color: #28a745; background: #e8f5e9; }
        .help-step { display: flex; align-items: center; margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 10px; }
        .help-number { width: 40px; height: 40px; background: #006B3E; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 18px; margin-right: 15px; }
        .alerta-info { background: #e8f5e9; border-left: 4px solid #28a745; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animated { animation: fadeInUp 0.5s ease-out; }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content" id="mainContent">
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h2><i class="fas fa-file-alt"></i> Proposta de Prova</h2>
                    <p>Submeta propostas de prova para aprovação da coordenação pedagógica</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar btn me-2"><i class="fas fa-arrow-left"></i> Voltar</a>
                    <button type="button" class="btn-ajuda btn" data-bs-toggle="modal" data-bs-target="#modalAjuda"><i class="fas fa-question-circle"></i> Como Funciona</button>
                </div>
            </div>
        </div>
        
        <div class="alerta-info">
            <i class="fas fa-info-circle text-success"></i> 
            <strong>📌 Informação Importante:</strong><br>
            As propostas de prova devem ser submetidas com pelo menos 5 dias de antecedência da data prevista.
            A coordenação irá analisar e aprovar ou solicitar revisão.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Formulário -->
            <div class="col-md-6">
                <div class="info-card">
                    <div class="info-title"><i class="fas fa-edit"></i> Nova Proposta de Prova</div>
                    <form method="POST" id="formProposta" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Turma *</label>
                            <select name="turma_id" id="turma_id" class="form-select" required>
                                <option value="">Selecione a turma...</option>
                                <?php foreach ($turmas as $turma): ?>
                                <option value="<?php echo $turma['id']; ?>">
                                    <?php echo htmlspecialchars($turma['nome']); ?> - <?php echo $turma['ano']; ?> (<?php echo $turma['turno']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Disciplina *</label>
                            <select name="disciplina_id" id="disciplina_id" class="form-select" required>
                                <option value="">Primeiro selecione a turma...</option>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Bimestre *</label>
                                <select name="bimestre" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <option value="1">1º Bimestre</option>
                                    <option value="2">2º Bimestre</option>
                                    <option value="3">3º Bimestre</option>
                                    <option value="4">4º Bimestre</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo de Prova *</label>
                                <select name="tipo_prova" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($tipos_prova as $key => $nome): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $nome; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Título da Proposta *</label>
                            <input type="text" name="titulo" class="form-control" placeholder="Ex: Prova de Matemática - 1º Bimestre" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Descrição / Justificativa</label>
                            <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva os objetivos da prova, conteúdos abordados, etc."></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Conteúdo da Prova *</label>
                            <textarea name="conteudo" id="conteudo" class="form-control summernote" rows="8" placeholder="Descreva detalhadamente o conteúdo da prova..."></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data Prevista *</label>
                                <input type="date" name="data_prevista" class="form-control" min="<?php echo date('Y-m-d', strtotime('+5 days')); ?>" required>
                                <small class="text-muted">Mínimo 5 dias de antecedência</small>
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Duração (min)</label>
                                <input type="number" name="duracao" class="form-control" value="60" min="30" max="180">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Peso na Média</label>
                                <input type="number" step="0.5" name="peso" class="form-control" value="10" min="0" max="100">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Anexar Documento (Opcional)</label>
                            <div class="upload-area" id="uploadArea" onclick="document.getElementById('anexo').click()">
                                <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                <p class="mb-0">Clique para fazer upload ou arraste arquivos aqui</p>
                                <small class="text-muted">Formatos: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</small>
                                <input type="file" name="anexo" id="anexo" style="display: none;" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                            </div>
                            <div id="fileInfo" class="mt-2" style="display: none;">
                                <div class="alert alert-info">
                                    <i class="fas fa-file"></i> <span id="fileName"></span>
                                    <button type="button" class="btn-close float-end" onclick="removerArquivo()"></button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-warning small">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Atenção:</strong> Após enviar, a proposta será analisada pela coordenação.
                        </div>
                        
                        <button type="button" class="btn btn-enviar w-100" onclick="confirmarEnvio()">
                            <i class="fas fa-paper-plane"></i> Submeter Proposta
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Estatísticas e Histórico -->
            <div class="col-md-6">
                <div class="row">
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number"><?php echo $total_propostas; ?></div><div class="stat-label">Total Propostas</div></div></div>
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number text-warning"><?php echo $total_pendente; ?></div><div class="stat-label">Pendentes</div></div></div>
                    <div class="col-md-4"><div class="stat-card"><div class="stat-number text-success"><?php echo $total_aprovado; ?></div><div class="stat-label">Aprovadas</div></div></div>
                </div>
                
                <div class="info-card">
                    <div class="info-title">
                        <i class="fas fa-history"></i> Histórico de Propostas
                        <button class="btn btn-sm btn-outline-secondary float-end" onclick="location.reload()"><i class="fas fa-sync-alt"></i> Atualizar</button>
                    </div>
                    <div id="historicoContainer">
                        <?php if (empty($propostas)): ?>
                            <p class="text-muted text-center">Nenhuma proposta encontrada.</p>
                        <?php else: ?>
                            <?php foreach ($propostas as $prop): ?>
                            <div class="proposta-card <?php echo $prop['status']; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($prop['titulo']); ?></strong>
                                        <br>
                                        <small><?php echo htmlspecialchars($prop['disciplina_nome']); ?> - <?php echo htmlspecialchars($prop['turma_nome']); ?></small>
                                    </div>
                                    <div>
                                        <?php echo getStatusBadgeProposta($prop['status']); ?>
                                        <?php echo getTipoProvaBadge($prop['tipo_prova']); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small><i class="fas fa-calendar"></i> Data: <?php echo formatarData($prop['data_prevista']); ?></small><br>
                                    <small><i class="fas fa-clock"></i> Duração: <?php echo $prop['duracao']; ?> min | Peso: <?php echo $prop['peso']; ?></small>
                                    <?php if ($prop['anexo']): ?>
                                    <br><small><i class="fas fa-paperclip"></i> <a href="<?php echo $prop['anexo']; ?>" target="_blank">Ver anexo</a></small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: #006B3E; color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Como Submeter Proposta?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="help-step"><div class="help-number">1</div><div class="help-content"><h6>Preencher Formulário</h6><p>Informe a turma, disciplina, bimestre e conteúdo da prova.</p></div></div>
                    <div class="help-step"><div class="help-number">2</div><div class="help-content"><h6>Definir Data</h6><p>Escolha a data prevista (mínimo 5 dias de antecedência).</p></div></div>
                    <div class="help-step"><div class="help-number">3</div><div class="help-content"><h6>Submeter para Análise</h6><p>A proposta vai para a coordenação pedagógica.</p></div></div>
                    <div class="help-step"><div class="help-number">4</div><div class="help-content"><h6>Aguardar Resultado</h6><p>Você será notificado sobre a aprovação ou revisão.</p></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: #28a745; color: white;">
                    <h5 class="modal-title">Confirmar Submissão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja submeter esta proposta?</p>
                    <div class="alert alert-info">
                        <span id="confirm_resumo"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarEnvio">Sim, Submeter</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
    <script>
        // Combinacoes de Turma x Disciplina
        const combinacoes = <?php echo json_encode($combinacoes_json); ?>;
        
        // Inicializar Summernote
        $('.summernote').summernote({
            height: 200,
            toolbar: [['style', ['style']], ['font', ['bold', 'underline']], ['para', ['ul', 'ol']], ['view', ['codeview']]]
        });
        
        // Data mínima
        let dataMinima = new Date();
        dataMinima.setDate(dataMinima.getDate() + 5);
        $('input[name="data_prevista"]').attr('min', dataMinima.toISOString().split('T')[0]);
        
        // Carregar disciplinas ao selecionar turma
        $('#turma_id').change(function() {
            let turmaId = $(this).val();
            let disciplinaSelect = $('#disciplina_id');
            disciplinaSelect.empty().append('<option value="">Selecione a disciplina...</option>');
            
            if (turmaId && combinacoes[turmaId]) {
                // Buscar nomes das disciplinas via AJAX
                $.ajax({
                    url: 'proposta_prova.php',
                    method: 'GET',
                    data: { ajax_disciplinas: 1, turma_id: turmaId },
                    dataType: 'json',
                    success: function(disciplinas) {
                        disciplinas.forEach(function(disc) {
                            disciplinaSelect.append('<option value="' + disc.id + '">' + disc.nome + '</option>');
                        });
                        disciplinaSelect.prop('disabled', false);
                    },
                    error: function() {
                        disciplinaSelect.append('<option value="">Erro ao carregar disciplinas</option>');
                    }
                });
            } else {
                disciplinaSelect.prop('disabled', true);
            }
        });
        
        // Upload
        $('#anexo').change(function(e) {
            if (this.files && this.files[0]) {
                let file = this.files[0];
                if (file.size > 5 * 1024 * 1024) {
                    alert('Arquivo muito grande! Máximo 5MB.');
                    this.value = '';
                    return;
                }
                $('#fileName').text(file.name);
                $('#fileInfo').show();
            }
        });
        
        const uploadArea = document.getElementById('uploadArea');
        uploadArea.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('dragover'); });
        uploadArea.addEventListener('dragleave', function(e) { e.preventDefault(); this.classList.remove('dragover'); });
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                $('#anexo')[0].files = files;
                $('#anexo').trigger('change');
            }
        });
        
        function removerArquivo() {
            $('#anexo').val('');
            $('#fileInfo').hide();
        }
        
        function confirmarEnvio() {
            let turma = $('#turma_id option:selected').text();
            let disciplina = $('#disciplina_id option:selected').text();
            let titulo = $('input[name="titulo"]').val();
            let dataPrevista = $('input[name="data_prevista"]').val();
            let conteudo = $('.summernote').summernote('code');
            
            if (!$('#turma_id').val()) { alert('Selecione a turma.'); return; }
            if (!$('#disciplina_id').val()) { alert('Selecione a disciplina.'); return; }
            if (!$('select[name="bimestre"]').val()) { alert('Selecione o bimestre.'); return; }
            if (!$('select[name="tipo_prova"]').val()) { alert('Selecione o tipo de prova.'); return; }
            if (!titulo) { alert('Informe o título.'); return; }
            if (!conteudo || conteudo == '<p><br></p>') { alert('Descreva o conteúdo da prova.'); return; }
            if (!dataPrevista) { alert('Informe a data prevista.'); return; }
            
            $('#confirm_resumo').html(`📚 Turma: ${turma}<br>📖 Disciplina: ${disciplina}<br>📝 Título: ${titulo}<br>📅 Data: ${new Date(dataPrevista).toLocaleDateString('pt-BR')}`);
            new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
        }
        
        $('#btnConfirmarEnvio').click(function() { $('#formProposta').submit(); });
        
        // AJAX para buscar disciplinas
        <?php if (isset($_GET['ajax_disciplinas']) && isset($_GET['turma_id'])): ?>
        <?php
            $turma_id = (int)$_GET['turma_id'];
            $sql_disc = "
                SELECT DISTINCT d.id, d.nome
                FROM disciplinas d
                INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                WHERE pdt.professor_id = :professor_id AND pdt.turma_id = :turma_id
                ORDER BY d.nome
            ";
            $stmt_disc = $conn->prepare($sql_disc);
            $stmt_disc->execute([':professor_id' => $professor_id, ':turma_id' => $turma_id]);
            $disciplinas_ajax = $stmt_disc->fetchAll(PDO::FETCH_ASSOC);
            header('Content-Type: application/json');
            echo json_encode($disciplinas_ajax);
            exit;
        ?>
        <?php endif; ?>
    </script>
</body>
</html>