<?php
// escola/professor/relatorios/provas/criar_prova.php - Criar Prova Online

require_once __DIR__ . '/../../config/database.php';
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
    <title>Criar Prova Online | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
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
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 20px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 15px;
            }
        }

        /* ============================================
           CARDS
        ============================================ */
        .card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .card:hover {
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 15px 25px;
            font-weight: 600;
            font-size: 1.1rem;
        }

        .card-header i {
            margin-right: 10px;
        }

        .card-body {
            padding: 25px;
            background: white;
        }

        /* ============================================
           FORMULÁRIO
        ============================================ */
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #333;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .required::after {
            content: '*';
            color: #dc3545;
            margin-left: 4px;
            font-weight: bold;
        }

        .form-control,
        .form-select {
            border-radius: 12px;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            transition: all 0.3s ease;
            font-size: 0.9rem;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }

        .info-text {
            font-size: 0.75rem;
            color: #6c757d;
            margin-top: 5px;
        }

        /* ============================================
           CHECKBOXES
        ============================================ */
        .form-check {
            padding-left: 1.8rem;
        }

        .form-check-input {
            width: 1.2rem;
            height: 1.2rem;
            margin-left: -1.8rem;
            border-radius: 5px;
            border: 2px solid #ddd;
            cursor: pointer;
        }

        .form-check-input:checked {
            background-color: #006B3E;
            border-color: #006B3E;
        }

        .form-check-label {
            font-weight: 500;
            margin-left: 5px;
            cursor: pointer;
            color: #333;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-criar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 12px 35px;
            border-radius: 40px;
            font-weight: 700;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .btn-criar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 107, 62, 0.3);
        }

        .btn-criar i {
            margin-right: 8px;
        }

        .btn-outline-secondary {
            border-radius: 40px;
            padding: 12px 35px;
            font-weight: 600;
            border: 2px solid #6c757d;
            transition: all 0.3s ease;
        }

        .btn-outline-secondary:hover {
            transform: translateY(-2px);
        }

        /* ============================================
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 20px 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }

        .page-header h4 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 8px 0 0;
            opacity: 0.85;
            font-size: 0.9rem;
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .alert i {
            margin-right: 10px;
        }

        /* ============================================
           DICAS / INFO CARDS
        ============================================ */
        .dicas-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 20px;
        }

        .dica-item {
            flex: 1;
            min-width: 200px;
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .dica-item:hover {
            background: #e9ecef;
            transform: translateY(-2px);
        }

        .dica-icon {
            width: 50px;
            height: 50px;
            background: rgba(0, 107, 62, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .dica-icon i {
            font-size: 24px;
            color: #006B3E;
        }

        .dica-content h6 {
            margin: 0 0 5px 0;
            font-weight: 700;
            color: #333;
        }

        .dica-content p {
            margin: 0;
            font-size: 0.75rem;
            color: #6c757d;
        }

        hr {
            margin: 20px 0;
            border-color: #e9ecef;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .card-body {
                padding: 20px;
            }
            
            .btn-criar,
            .btn-outline-secondary {
                padding: 10px 25px;
                font-size: 0.85rem;
            }
            
            .dicas-container {
                flex-direction: column;
            }
            
            .dica-item {
                min-width: auto;
            }
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert {
            animation: fadeIn 0.3s ease;
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
  
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4><i class="fas fa-plus-circle me-2"></i> Criar Prova Online</h4>
                        <p>Crie uma nova prova para seus alunos de forma rápida e intuitiva</p>
                    </div>
                    <div>
                        <a href="listar_provas.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>

            <!-- Alertas -->
            <?php if ($erro): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $erro; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($sucesso): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle"></i> <?php echo $sucesso; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Formulário Principal -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Dados da Prova
                </div>
                <div class="card-body">
                    <form method="POST" id="formProva">
                        <div class="row">
                            <!-- Coluna Esquerda -->
                            <div class="col-lg-6">
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
                                        <option value="prova">📝 Prova</option>
                                        <option value="teste">📋 Teste</option>
                                        <option value="quiz">🎯 Quiz</option>
                                        <option value="simulado">📚 Simulado</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Duração (minutos)</label>
                                    <input type="number" name="duracao_minutos" class="form-control" value="60" min="1" max="300">
                                    <div class="info-text">⏱️ Tempo máximo para realização da prova</div>
                                </div>
                            </div>
                            
                            <!-- Coluna Direita -->
                            <div class="col-lg-6">
                                <div class="mb-3">
                                    <label class="form-label required">Data de Início</label>
                                    <input type="datetime-local" name="data_inicio" class="form-control" required>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label required">Data de Término</label>
                                    <input type="datetime-local" name="data_fim" class="form-control" required>
                                    <div class="info-text">⏰ Após esta data, a prova não estará mais disponível</div>
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
                                            <div class="info-text">⭐ Nota para aprovação</div>
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
                                                🔀 Embaralhar questões
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check mb-2">
                                            <input type="checkbox" name="embaralhar_alternativas" class="form-check-input" id="embaralhar_alternativas">
                                            <label class="form-check-label" for="embaralhar_alternativas">
                                                🔄 Embaralhar alternativas
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="form-check mb-2">
                                            <input type="checkbox" name="mostrar_gabarito" class="form-check-input" id="mostrar_gabarito" checked>
                                            <label class="form-check-label" for="mostrar_gabarito">
                                                📖 Mostrar gabarito após correção
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
                    <div class="dicas-container">
                        <div class="dica-item">
                            <div class="dica-icon">
                                <i class="fas fa-tasks"></i>
                            </div>
                            <div class="dica-content">
                                <h6>Defina objetivos claros</h6>
                                <p>Saiba exatamente o que você quer avaliar em cada questão</p>
                            </div>
                        </div>
                        <div class="dica-item">
                            <div class="dica-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div class="dica-content">
                                <h6>Respeite o tempo</h6>
                                <p>Calcule aproximadamente 2-3 minutos por questão</p>
                            </div>
                        </div>
                        <div class="dica-item">
                            <div class="dica-icon">
                                <i class="fas fa-chart-line"></i>
                            </div>
                            <div class="dica-content">
                                <h6>Diversifique os tipos</h6>
                                <p>Use múltipla escolha, V/F e questões dissertativas</p>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-info-circle"></i> Após criar a prova, você será redirecionado para adicionar as questões.
                        Uma prova bem elaborada deve ter entre 10 e 20 questões, dependendo da complexidade e do tempo disponível.
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
        const dataInicioInput = document.querySelector('input[name="data_inicio"]');
        const dataFimInput = document.querySelector('input[name="data_fim"]');
        
        if (dataInicioInput) {
            dataInicioInput.min = minDateTime;
        }
        
        // Quando data_inicio mudar, ajustar data_fim
        if (dataInicioInput) {
            dataInicioInput.addEventListener('change', function() {
                const dataInicio = new Date(this.value);
                if (!isNaN(dataInicio)) {
                    const dataFim = new Date(dataInicio);
                    dataFim.setDate(dataFim.getDate() + 7);
                    const yyyyFim = dataFim.getFullYear();
                    const mmFim = String(dataFim.getMonth() + 1).padStart(2, '0');
                    const ddFim = String(dataFim.getDate()).padStart(2, '0');
                    if (dataFimInput) {
                        dataFimInput.min = `${yyyyFim}-${mmFim}-${ddFim}T${hours}:${minutes}`;
                    }
                }
            });
        }
        
        // Validação do formulário
        const formProva = document.getElementById('formProva');
        if (formProva) {
            formProva.addEventListener('submit', function(e) {
                const dataInicio = new Date(document.querySelector('input[name="data_inicio"]').value);
                const dataFim = new Date(document.querySelector('input[name="data_fim"]').value);
                
                if (dataInicio >= dataFim) {
                    e.preventDefault();
                    alert('❌ A data de início deve ser anterior à data de término.');
                }
            });
        }
    </script>
</body>
</html>