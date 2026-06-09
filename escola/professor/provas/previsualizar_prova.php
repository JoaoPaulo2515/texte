<?php
// escola/professor/provas/previsualizar_prova.php - Pré-visualizar Prova Online

require_once __DIR__ . '/../../../config/database.php';
session_start();


$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['usuario_nome'] ?? 'Professor';

// Buscar dados do professor
$sql_professor = "SELECT f.id as funcionario_id FROM funcionarios f WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $professor['funcionario_id'] ?? 0;

$prova_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Buscar dados da prova
$sql_prova = "SELECT p.*, d.nome as disciplina_nome, t.nome as turma_nome, t.ano as turma_ano
              FROM online_provas p
              JOIN disciplinas d ON d.id = p.disciplina_id
              JOIN turmas t ON t.id = p.turma_id
              WHERE p.id = :prova_id AND p.professor_id = :funcionario_id AND p.escola_id = :escola_id";
$stmt_prova = $conn->prepare($sql_prova);
$stmt_prova->execute([':prova_id' => $prova_id, ':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id]);
$prova = $stmt_prova->fetch(PDO::FETCH_ASSOC);

if (!$prova) {
    die('Prova não encontrada ou você não tem permissão para visualizá-la.');
}

// Buscar questões da prova
$sql_questoes = "SELECT q.* 
                 FROM online_provas_questoes q
                 WHERE q.prova_id = :prova_id
                 ORDER BY q.ordem ASC";
$stmt_questoes = $conn->prepare($sql_questoes);
$stmt_questoes->execute([':prova_id' => $prova_id]);
$questoes = $stmt_questoes->fetchAll(PDO::FETCH_ASSOC);

// Buscar alternativas para cada questão
foreach ($questoes as &$questao) {
    $sql_alt = "SELECT * FROM online_provas_alternativas WHERE questao_id = :questao_id ORDER BY ordem ASC";
    $stmt_alt = $conn->prepare($sql_alt);
    $stmt_alt->execute([':questao_id' => $questao['id']]);
    $questao['alternativas'] = $stmt_alt->fetchAll(PDO::FETCH_ASSOC);
}

$total_pontos = array_sum(array_column($questoes, 'pontuacao'));

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pré-visualizar Prova - <?php echo htmlspecialchars($prova['titulo']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f0f2f5;
        }
        
        .preview-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .prova-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .info-prova {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .questao-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        
        .questao-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .questao-body {
            padding: 20px;
        }
        
        .alternativa {
            margin: 10px 0;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alternativa-correta {
            background: #d4edda;
            border-color: #28a745;
        }
        
        .badge-correta {
            background: #28a745;
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.7rem;
        }
        
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        .btn-editar {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 20px;
        }
        
        @media print {
            .btn-voltar, .btn-editar, .menu-toggle, .sidebar, .top-header, .footer-professor {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
            }
            .preview-container {
                padding: 0 !important;
            }
        }
    </style>
</head>
<body>
    
     <?php include '../includes/menu_professor.php'; ?>
<div class="main-content">
    <div class="preview-container">
        <!-- Cabeçalho da Prova -->
        <div class="prova-header">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <h2 class="mb-2"><?php echo htmlspecialchars($prova['titulo']); ?></h2>
                    <p class="mb-1"><i class="fas fa-book"></i> <?php echo htmlspecialchars($prova['disciplina_nome']); ?></p>
                    <p class="mb-1"><i class="fas fa-users"></i> <?php echo $prova['turma_ano'] . 'ª - ' . htmlspecialchars($prova['turma_nome']); ?></p>
                </div>
                <div class="text-end">
                    <span class="badge bg-light text-dark mb-2 d-block"><?php echo ucfirst($prova['tipo']); ?></span>
                    <small><i class="fas fa-clock"></i> Duração: <?php echo $prova['duracao_minutos']; ?> min</small>
                </div>
            </div>
        </div>
        
        <!-- Informações da Prova -->
        <div class="info-prova">
            <div class="row">
                <div class="col-md-4">
                    <div class="text-center">
                        <h3><?php echo count($questoes); ?></h3>
                        <p class="text-muted mb-0">Questões</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <h3><?php echo number_format($total_pontos, 1); ?></h3>
                        <p class="text-muted mb-0">Pontos Totais</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-center">
                        <h3><?php echo $prova['nota_maxima']; ?></h3>
                        <p class="text-muted mb-0">Nota Máxima</p>
                    </div>
                </div>
            </div>
            
            <?php if ($prova['descricao']): ?>
            <div class="mt-3 p-3 bg-light rounded">
                <strong><i class="fas fa-info-circle"></i> Descrição:</strong>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($prova['descricao'])); ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($prova['instrucoes']): ?>
            <div class="mt-3 p-3 bg-warning bg-opacity-10 rounded">
                <strong><i class="fas fa-exclamation-triangle"></i> Instruções:</strong>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($prova['instrucoes'])); ?></p>
            </div>
            <?php endif; ?>
            
            <div class="mt-3 d-flex gap-3 flex-wrap">
                <div><i class="fas fa-calendar-alt text-primary"></i> Disponível de: <?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></div>
                <div><i class="fas fa-calendar-times text-danger"></i> Até: <?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></div>
                <div><i class="fas fa-repeat text-info"></i> Tentativas: <?php echo $prova['tentativas_permitidas']; ?></div>
                <div><i class="fas fa-star text-warning"></i> Aprovação: <?php echo $prova['nota_minima_aprovacao']; ?> pontos</div>
            </div>
            
            <div class="mt-3">
                <div class="form-check form-switch d-inline-block me-3">
                    <input class="form-check-input" type="checkbox" id="embaralhar_questoes" <?php echo $prova['embaralhar_questoes'] ? 'checked' : ''; ?> disabled>
                    <label class="form-check-label">Embaralhar questões</label>
                </div>
                <div class="form-check form-switch d-inline-block me-3">
                    <input class="form-check-input" type="checkbox" id="embaralhar_alternativas" <?php echo $prova['embaralhar_alternativas'] ? 'checked' : ''; ?> disabled>
                    <label class="form-check-label">Embaralhar alternativas</label>
                </div>
                <div class="form-check form-switch d-inline-block">
                    <input class="form-check-input" type="checkbox" id="mostrar_gabarito" <?php echo $prova['mostrar_gabarito'] ? 'checked' : ''; ?> disabled>
                    <label class="form-check-label">Mostrar gabarito após correção</label>
                </div>
            </div>
        </div>
        
        <!-- Questões -->
        <h4 class="mb-3"><i class="fas fa-question-circle"></i> Questões da Prova</h4>
        
        <?php if (empty($questoes)): ?>
            <div class="alert alert-warning text-center">
                <i class="fas fa-exclamation-triangle"></i> Nenhuma questão adicionada ainda.
                <br>
                <a href="adicionar_questoes.php?id=<?php echo $prova_id; ?>" class="btn btn-primary mt-2">
                    <i class="fas fa-plus"></i> Adicionar Questões
                </a>
            </div>
        <?php else: ?>
            <?php foreach ($questoes as $index => $questao): ?>
            <div class="questao-card">
                <div class="questao-header">
                    <div>
                        <span class="badge bg-secondary me-2">Questão <?php echo $index + 1; ?></span>
                        <span class="badge bg-info"><?php echo ucfirst(str_replace('_', ' ', $questao['tipo'])); ?></span>
                        <span class="badge bg-success ms-2"><?php echo $questao['pontuacao']; ?> pontos</span>
                    </div>
                </div>
                <div class="questao-body">
                    <p class="mb-3"><strong><?php echo nl2br(htmlspecialchars($questao['enunciado'])); ?></strong></p>
                    
                    <?php if ($questao['tipo'] == 'multipla_escolha' && !empty($questao['alternativas'])): ?>
                        <?php foreach ($questao['alternativas'] as $alt): ?>
                        <div class="alternativa <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                            <span class="badge <?php echo $alt['correta'] ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $alt['correta'] ? 'Correta' : 'Alternativa'; ?>
                            </span>
                            <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                        </div>
                        <?php endforeach; ?>
                        
                    <?php elseif ($questao['tipo'] == 'verdadeiro_falso'): ?>
                        <?php foreach ($questao['alternativas'] as $alt): ?>
                        <div class="alternativa <?php echo $alt['correta'] ? 'alternativa-correta' : ''; ?>">
                            <span class="badge <?php echo $alt['correta'] ? 'bg-success' : 'bg-secondary'; ?>">
                                <?php echo $alt['correta'] ? 'Resposta Correta' : 'Alternativa'; ?>
                            </span>
                            <span><?php echo htmlspecialchars($alt['texto']); ?></span>
                        </div>
                        <?php endforeach; ?>
                        
                    <?php elseif ($questao['tipo'] == 'dissertativa'): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-pen"></i> Questão dissertativa - O aluno deverá escrever uma resposta textual.
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Resposta esperada (gabarito):</label>
                            <textarea class="form-control" rows="3" placeholder="Resposta do aluno..." disabled></textarea>
                            <small class="text-muted">A resposta será corrigida pelo professor manualmente.</small>
                        </div>
                        
                    <?php elseif ($questao['tipo'] == 'completar'): ?>
                        <div class="alert alert-info">
                            <i class="fas fa-ellipsis-h"></i> Questão de completar - O aluno deverá preencher a lacuna.
                        </div>
                        <div class="mt-2">
                            <label class="form-label">Resposta esperada:</label>
                            <input type="text" class="form-control" placeholder="Resposta do aluno..." disabled>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <!-- Botões de Ação -->
        <div class="text-center mt-4">
            <button class="btn-voltar" onclick="window.location.href='adicionar_questoes.php?id=<?php echo $prova_id; ?>'">
                <i class="fas fa-arrow-left"></i> Voltar para Edição
            </button>
            <button class="btn-editar ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
            <?php if (!empty($questoes)): ?>
            <a href="publicar_prova.php?id=<?php echo $prova_id; ?>" class="btn btn-success ms-2" onclick="return confirm('Tem certeza que deseja publicar esta prova? Após publicada, os alunos poderão visualizá-la.')">
                <i class="fas fa-check-circle"></i> Publicar Prova
            </a>
            <?php endif; ?>
        </div>
        
        <!-- Resumo para o Professor -->
        <div class="card mt-4">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-chart-line"></i> Resumo da Prova
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><td width="180"><strong>Total de Questões:</strong></td><td><?php echo count($questoes); ?></td></tr>
                            <tr><td><strong>Pontuação Total:</strong></td><td><?php echo number_format($total_pontos, 1); ?> pontos</td></tr>
                            <tr><td><strong>Nota Máxima:</strong></td><td><?php echo $prova['nota_maxima']; ?></td></tr>
                            <tr><td><strong>Nota Mínima para Aprovação:</strong></td><td><?php echo $prova['nota_minima_aprovacao']; ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm">
                            <tr><td width="180"><strong>Duração:</strong></td><td><?php echo $prova['duracao_minutos']; ?> minutos</td></tr>
                            <tr><td><strong>Tentativas Permitidas:</strong></td><td><?php echo $prova['tentativas_permitidas']; ?></td></tr>
                            <tr><td><strong>Data de Início:</strong></td><td><?php echo date('d/m/Y H:i', strtotime($prova['data_inicio'])); ?></td></tr>
                            <tr><td><strong>Data de Término:</strong></td><td><?php echo date('d/m/Y H:i', strtotime($prova['data_fim'])); ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>