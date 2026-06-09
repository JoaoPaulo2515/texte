<?php
// escola/professor/relatorios/provas/criar_prova.php - Criar Prova Online

require_once __DIR__ . '/../../../config/database.php';
session_start();



$db = Database::getInstance();
$conn = $db->getConnection();
$usuario_id = $_SESSION['usuario_id'];
$escola_id = $_SESSION['escola_id'];
$professor_nome = $_SESSION['usuario_nome'] ?? 'Professor';

// Buscar dados do professor/funcionário
$sql_professor = "SELECT f.id as funcionario_id
                  FROM funcionarios f 
                  WHERE f.usuario_id = :usuario_id AND f.escola_id = :escola_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':usuario_id' => $usuario_id, ':escola_id' => $escola_id]);
$professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);
$funcionario_id = $professor['funcionario_id'] ?? 0;

// Buscar turmas do professor
$sql_turmas = "SELECT DISTINCT t.id, t.nome, t.ano, t.turno 
               FROM turmas t
               JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
               WHERE pdt.professor_id = :funcionario_id 
               AND t.status = 'ativa'
               ORDER BY t.ano, t.nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':funcionario_id' => $funcionario_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas do professor
$sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo 
                    FROM disciplinas d
                    JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                    WHERE pdt.professor_id = :funcionario_id 
                    ORDER BY d.nome";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':funcionario_id' => $funcionario_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Processar formulário de criação de prova
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $instrucoes = $_POST['instrucoes'] ?? '';
    $disciplina_id = (int)$_POST['disciplina_id'] ?? 0;
    $turma_id = (int)$_POST['turma_id'] ?? 0;
    $tipo = $_POST['tipo'] ?? 'prova';
    $duracao_minutos = (int)$_POST['duracao_minutos'] ?? 60;
    $data_inicio = $_POST['data_inicio'] ?? '';
    $data_fim = $_POST['data_fim'] ?? '';
    $tentativas_permitidas = (int)$_POST['tentativas_permitidas'] ?? 1;
    $nota_maxima = (float)$_POST['nota_maxima'] ?? 20.00;
    $nota_minima_aprovacao = (float)$_POST['nota_minima_aprovacao'] ?? 9.50;
    $embaralhar_questoes = isset($_POST['embaralhar_questoes']) ? 1 : 0;
    $embaralhar_alternativas = isset($_POST['embaralhar_alternativas']) ? 1 : 0;
    $mostrar_gabarito = isset($_POST['mostrar_gabarito']) ? 1 : 0;
    
    if (empty($titulo) || empty($disciplina_id) || empty($turma_id) || empty($data_inicio) || empty($data_fim)) {
        $erro = 'Preencha todos os campos obrigatórios.';
    } elseif (strtotime($data_inicio) > strtotime($data_fim)) {
        $erro = 'A data de início deve ser anterior à data de fim.';
    } else {
        try {
            // Inserir prova
            $sql = "INSERT INTO online_provas 
                    (escola_id, disciplina_id, turma_id, professor_id, titulo, descricao, instrucoes, tipo, 
                     duracao_minutos, data_inicio, data_fim, tentativas_permitidas, nota_maxima, 
                     nota_minima_aprovacao, embaralhar_questoes, embaralhar_alternativas, mostrar_gabarito, status) 
                    VALUES 
                    (:escola_id, :disciplina_id, :turma_id, :professor_id, :titulo, :descricao, :instrucoes, :tipo,
                     :duracao_minutos, :data_inicio, :data_fim, :tentativas_permitidas, :nota_maxima,
                     :nota_minima_aprovacao, :embaralhar_questoes, :embaralhar_alternativas, :mostrar_gabarito, 'agendada')";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':disciplina_id' => $disciplina_id,
                ':turma_id' => $turma_id,
                ':professor_id' => $funcionario_id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':instrucoes' => $instrucoes,
                ':tipo' => $tipo,
                ':duracao_minutos' => $duracao_minutos,
                ':data_inicio' => $data_inicio,
                ':data_fim' => $data_fim,
                ':tentativas_permitidas' => $tentativas_permitidas,
                ':nota_maxima' => $nota_maxima,
                ':nota_minima_aprovacao' => $nota_minima_aprovacao,
                ':embaralhar_questoes' => $embaralhar_questoes,
                ':embaralhar_alternativas' => $embaralhar_alternativas,
                ':mostrar_gabarito' => $mostrar_gabarito
            ]);
            
            $prova_id = $conn->lastInsertId();
            
            $sucesso = "Prova criada com sucesso! <a href='adicionar_questoes.php?id={$prova_id}'>Clique aqui para adicionar questões</a>";
            
        } catch (PDOException $e) {
            $erro = 'Erro ao criar prova: ' . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Prova Online | Professor</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        .card-header {
            background: white;
            border-bottom: 1px solid #e0e0e0;
            border-radius: 15px 15px 0 0 !important;
            padding: 15px 20px;
            font-weight: 600;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 5px;
        }
        .form-control, .form-select {
            border-radius: 10px;
            padding: 10px 15px;
            border: 1px solid #ddd;
        }
        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
        .btn-criar {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
        }
        .btn-criar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,107,62,0.3);
        }
        .alert {
            border-radius: 10px;
        }
        .info-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 5px;
        }
        .required::after {
            content: '*';
            color: #dc3545;
            margin-left: 4px;
        }
    </style>
</head>
<body>
     <?php include '../includes/menu_professor.php'; ?>
  
<div class="main-content">
    <div class="container-fluid">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-plus-circle"></i> Criar Prova Online</h4>
                <p class="text-muted mb-0">Crie uma nova prova para seus alunos</p>
            </div>
            <div>
                <a href="listar_provas.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <?php if ($erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle"></i> <?php echo $erro; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $sucesso; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Formulário -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-info-circle"></i> Dados da Prova
            </div>
            <div class="card-body">
                <form method="POST" id="formProva">
                    <div class="row">
                        <!-- Coluna Esquerda -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label required">Título da Prova</label>
                                <input type="text" name="titulo" class="form-control" 
                                       placeholder="Ex: Prova de Matemática - 1º Bimestre" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label required">Disciplina</label>
                                <select name="disciplina_id" class="form-select" required>
                                    <option value="">Selecione a disciplina</option>
                                    <?php foreach ($disciplinas as $disc): ?>
                                    <option value="<?php echo $disc['id']; ?>"><?php echo htmlspecialchars($disc['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label required">Turma</label>
                                <select name="turma_id" class="form-select" required>
                                    <option value="">Selecione a turma</option>
                                    <?php foreach ($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>"><?php echo $turma['ano'] . 'ª - ' . htmlspecialchars($turma['nome']) . ' (' . ucfirst($turma['turno']) . ')'; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Tipo de Prova</label>
                                <select name="tipo" class="form-select">
                                    <option value="prova">Prova</option>
                                    <option value="teste">Teste</option>
                                    <option value="quiz">Quiz</option>
                                    <option value="simulado">Simulado</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Duração (minutos)</label>
                                <input type="number" name="duracao_minutos" class="form-control" value="60" min="1" max="300">
                                <div class="info-text">Tempo máximo para realização da prova</div>
                            </div>
                        </div>
                        
                        <!-- Coluna Direita -->
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label required">Data de Início</label>
                                <input type="datetime-local" name="data_inicio" class="form-control" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label required">Data de Término</label>
                                <input type="datetime-local" name="data_fim" class="form-control" required>
                                <div class="info-text">Após esta data, a prova não estará mais disponível</div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Tentativas Permitidas</label>
                                <select name="tentativas_permitidas" class="form-select">
                                    <option value="1">1 tentativa</option>
                                    <option value="2">2 tentativas</option>
                                    <option value="3">3 tentativas</option>
                                    <option value="5">5 tentativas</option>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Nota Máxima</label>
                                        <input type="number" name="nota_maxima" class="form-control" value="20.00" step="0.5">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">Nota Mínima</label>
                                        <input type="number" name="nota_minima_aprovacao" class="form-control" value="9.50" step="0.5">
                                        <div class="info-text">Nota para aprovação</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Configurações (Full Width) -->
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label">Descrição da Prova</label>
                                <textarea name="descricao" class="form-control" rows="3" 
                                          placeholder="Descreva o conteúdo da prova..."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Instruções para os Alunos</label>
                                <textarea name="instrucoes" class="form-control" rows="3" 
                                          placeholder="Instruções importantes para a realização da prova..."></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-check mb-2">
                                        <input type="checkbox" name="embaralhar_questoes" class="form-check-input" id="embaralhar_questoes">
                                        <label class="form-check-label" for="embaralhar_questoes">
                                            Embaralhar questões
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check mb-2">
                                        <input type="checkbox" name="embaralhar_alternativas" class="form-check-input" id="embaralhar_alternativas">
                                        <label class="form-check-label" for="embaralhar_alternativas">
                                            Embaralhar alternativas
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check mb-2">
                                        <input type="checkbox" name="mostrar_gabarito" class="form-check-input" id="mostrar_gabarito" checked>
                                        <label class="form-check-label" for="mostrar_gabarito">
                                            Mostrar gabarito após correção
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn-criar">
                            <i class="fas fa-save"></i> Criar Prova
                        </button>
                        <a href="listar_provas.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Dicas -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-lightbulb"></i> Dicas para Criar uma Boa Prova
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="text-success"><i class="fas fa-tasks fa-2x"></i></div>
                            <div>
                                <strong>Defina objetivos claros</strong>
                                <p class="text-muted small mb-0">Saiba o que você quer avaliar</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="text-success"><i class="fas fa-clock fa-2x"></i></div>
                            <div>
                                <strong>Respeite o tempo</strong>
                                <p class="text-muted small mb-0">Calcule o tempo necessário por questão</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="text-success"><i class="fas fa-chart-line fa-2x"></i></div>
                            <div>
                                <strong>Diversifique os tipos</strong>
                                <p class="text-muted small mb-0">Use múltipla escolha, V/F, dissertativas</p>
                            </div>
                        </div>
                    </div>
                </div>
                <hr>
                <p class="text-muted small mb-0">
                    <i class="fas fa-info-circle"></i> Após criar a prova, você será redirecionado para adicionar as questões.
                    Uma prova bem elaborada deve ter entre 10 e 20 questões, dependendo da complexidade.
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Definir data mínima para início (hoje)
    const today = new Date();
    const yyyy = today.getFullYear();
    const mm = String(today.getMonth() + 1).padStart(2, '0');
    const dd = String(today.getDate()).padStart(2, '0');
    const hours = String(today.getHours()).padStart(2, '0');
    const minutes = String(today.getMinutes()).padStart(2, '0');
    
    const minDateTime = `${yyyy}-${mm}-${dd}T${hours}:${minutes}`;
    document.querySelector('input[name="data_inicio"]').min = minDateTime;
    
    // Quando data_inicio mudar, ajustar data_fim
    document.querySelector('input[name="data_inicio"]').addEventListener('change', function() {
        const dataInicio = new Date(this.value);
        if (!isNaN(dataInicio)) {
            const dataFim = new Date(dataInicio);
            dataFim.setDate(dataFim.getDate() + 7);
            const yyyyFim = dataFim.getFullYear();
            const mmFim = String(dataFim.getMonth() + 1).padStart(2, '0');
            const ddFim = String(dataFim.getDate()).padStart(2, '0');
            document.querySelector('input[name="data_fim"]').min = `${yyyyFim}-${mmFim}-${ddFim}T${hours}:${minutes}`;
        }
    });
    
    // Validação do formulário
    document.getElementById('formProva').addEventListener('submit', function(e) {
        const dataInicio = new Date(document.querySelector('input[name="data_inicio"]').value);
        const dataFim = new Date(document.querySelector('input[name="data_fim"]').value);
        
        if (dataInicio >= dataFim) {
            e.preventDefault();
            alert('A data de início deve ser anterior à data de término.');
        }
    });
</script>
</body>
</html>