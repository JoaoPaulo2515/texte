<?php
// escola/pedagogico/cadastrar_disciplina.php - Cadastrar Nova Disciplina

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// Buscar cursos para o select
$sql_cursos = "SELECT id, nome FROM cursos WHERE status = 1 ORDER BY nome";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute();
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Função para gerar código automaticamente
function gerarCodigoDisciplina($nome, $curso_id = null, $conn) {
    // Remover acentos e caracteres especiais
    $nome_limpo = preg_replace('/[^A-Za-z0-9]/', ' ', $nome);
    $palavras = explode(' ', trim($nome_limpo));
    
    // Pegar primeiras letras (máximo 3 caracteres)
    $sigla = '';
    foreach ($palavras as $palavra) {
        if (!empty($palavra)) {
            $sigla .= strtoupper(substr($palavra, 0, 1));
            if (strlen($sigla) >= 3) break;
        }
    }
    
    // Se a sigla tiver menos de 2 caracteres, usar as primeiras letras do nome
    if (strlen($sigla) < 2) {
        $sigla = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $nome), 0, 3));
    }
    
    // Buscar último código para esta sigla
    $sql = "SELECT codigo FROM disciplinas WHERE codigo LIKE :prefixo AND escola_id = :escola_id ORDER BY codigo DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':prefixo' => $sigla.'%',
        ':escola_id' => $escola_id
    ]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo) {
        // Extrair o número do último código
        $numero = (int)substr($ultimo['codigo'], strlen($sigla));
        $numero++;
        $codigo = $sigla . str_pad($numero, 3, '0', STR_PAD_LEFT);
    } else {
        $codigo = $sigla . '001';
    }
    
    return $codigo;
}

// Processar cadastro
$mensagem = '';
$erro = '';
$dados = [];
$codigo_gerado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $codigo = trim($_POST['codigo'] ?? '');
    $curso_id = !empty($_POST['curso_id']) ? (int)$_POST['curso_id'] : null;
    $carga_horaria = !empty($_POST['carga_horaria']) ? (int)$_POST['carga_horaria'] : null;
    $descricao = trim($_POST['descricao'] ?? '');
    $cor = trim($_POST['cor'] ?? '#1e5799');
    $status = $_POST['status'] ?? '1';
    $gerar_auto = isset($_POST['gerar_auto']) && $_POST['gerar_auto'] == '1';
    
    $erros = [];
    
    if (empty($nome)) $erros[] = "Informe o nome da disciplina.";
    
    // Se for gerar automático, criar o código
    if ($gerar_auto && empty($codigo)) {
        $codigo = gerarCodigoDisciplina($nome, $curso_id, $conn);
    }
    
    if (empty($codigo)) $erros[] = "Informe o código da disciplina.";
    
    if (empty($erros)) {
        try {
            // Verificar se já existe disciplina com mesmo código
            $sql_check = "SELECT id FROM disciplinas WHERE codigo = :codigo AND escola_id = :escola_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':codigo' => $codigo,
                ':escola_id' => $escola_id
            ]);
            
            if ($stmt_check->fetch()) {
                $erros[] = "Já existe uma disciplina com este código. Tente gerar novamente.";
            } else {
                $sql_insert = "
                    INSERT INTO disciplinas (
                        nome, codigo, curso_id, carga_horaria, 
                        descricao, cor, status, escola_id, created_at
                    ) VALUES (
                        :nome, :codigo, :curso_id, :carga_horaria,
                        :descricao, :cor, :status, :escola_id, NOW()
                    )
                ";
                
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->execute([
                    ':nome' => $nome,
                    ':codigo' => $codigo,
                    ':curso_id' => $curso_id,
                    ':carga_horaria' => $carga_horaria,
                    ':descricao' => $descricao,
                    ':cor' => $cor,
                    ':status' => $status,
                    ':escola_id' => $escola_id
                ]);
                
                $disciplina_id = $conn->lastInsertId();
                $mensagem = "Disciplina cadastrada com sucesso! Código: " . $codigo;
                
                // Limpar dados do formulário
                $dados = [];
                $codigo_gerado = '';
                
                // Redirecionar após 2 segundos
                header("refresh:2;url=listar_disciplinas.php");
            }
        } catch (PDOException $e) {
            $erros[] = "Erro ao cadastrar disciplina: " . $e->getMessage();
        }
    }
    
    if (!empty($erros)) {
        $erro = implode("<br>", $erros);
        // Manter dados preenchidos
        $dados = $_POST;
        $codigo_gerado = $codigo;
    }
}

// Gerar código automaticamente via AJAX
if (isset($_GET['ajax']) && $_GET['ajax'] == 'gerar_codigo') {
    $nome = $_GET['nome'] ?? '';
    $curso_id = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : null;
    
    if (!empty($nome)) {
        $codigo = gerarCodigoDisciplina($nome, $curso_id, $conn);
        header('Content-Type: application/json');
        echo json_encode(['codigo' => $codigo]);
        exit;
    }
    echo json_encode(['codigo' => '']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Disciplina - SIGE Angola</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-title h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .header-title p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-voltar:hover {
            background: rgba(255,255,255,0.3);
            transform: translateX(-3px);
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body {
            padding: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .form-group label .required {
            color: #e74c3c;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1e5799;
            box-shadow: 0 0 0 3px rgba(30, 87, 153, 0.1);
        }
        
        select.form-control {
            cursor: pointer;
            background: white;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .btn-group {
            display: flex;
            gap: 15px;
            justify-content: flex-end;
            margin-top: 30px;
        }
        
        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background: #95a5a6;
            color: white;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .alert-danger {
            background: #fadbd8;
            color: #c0392b;
            border-left: 4px solid #c0392b;
        }
        
        .alert-info {
            background: #d4e6f1;
            color: #1e5799;
            border-left: 4px solid #1e5799;
        }
        
        .cor-preview {
            width: 50px;
            height: 36px;
            border-radius: 6px;
            border: 1px solid #ddd;
            margin-top: 5px;
        }
        
        .info-text {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .codigo-gerado {
            background: #ecf0f1;
            padding: 10px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            margin-top: 5px;
        }
        
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }
        
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: 0.4s;
            border-radius: 24px;
        }
        
        .slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: 0.4s;
            border-radius: 50%;
        }
        
        input:checked + .slider {
            background-color: #27ae60;
        }
        
        input:checked + .slider:before {
            transform: translateX(26px);
        }
        
        .auto-codigo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }
        
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #ecf0f1;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .btn-group {
                flex-direction: column;
            }
            
            .btn-group .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>➕ Cadastrar Nova Disciplina</h1>
            <p>Preencha os dados abaixo para criar uma nova disciplina</p>
        </div>
        <div>
            <a href="listar_disciplinas.php" class="btn-voltar">
                ← Voltar para Lista
            </a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">
            ✅ <?php echo htmlspecialchars($mensagem); ?> Redirecionando...
        </div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">
            ❌ <?php echo htmlspecialchars($erro); ?>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            📝 Formulário de Cadastro
        </div>
        <div class="card-body">
            <form method="POST" action="" id="formDisciplina">
                <input type="hidden" name="gerar_auto" id="gerar_auto" value="1">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Nome da Disciplina <span class="required">*</span></label>
                        <input type="text" name="nome" id="nome_disciplina" class="form-control" 
                               placeholder="Ex: Matemática, Português, Física..."
                               value="<?php echo htmlspecialchars($dados['nome'] ?? ''); ?>" required>
                        <div class="info-text">Nome completo da disciplina</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Curso</label>
                        <select name="curso_id" id="curso_id" class="form-control">
                            <option value="">Selecione o curso (opcional)</option>
                            <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo $curso['id']; ?>" 
                                    <?php echo (($dados['curso_id'] ?? '') == $curso['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="info-text">Curso ao qual esta disciplina pertence</div>
                    </div>
                </div>
                
                <div class="form-group">
                    <div class="auto-codigo">
                        <label>Código <span class="required">*</span></label>
                        <label class="switch">
                            <input type="checkbox" id="auto_codigo" checked>
                            <span class="slider"></span>
                        </label>
                        <span style="font-size: 12px; color: #7f8c8d;">Gerar automaticamente</span>
                    </div>
                    <input type="text" name="codigo" id="codigo" class="form-control" 
                           placeholder="Código será gerado automaticamente"
                           value="<?php echo htmlspecialchars($dados['codigo'] ?? $codigo_gerado); ?>">
                    <div id="codigo_preview" class="codigo-gerado" style="display: none;"></div>
                    <div class="info-text">Código único da disciplina. Pode ser editado manualmente.</div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Carga Horária (horas)</label>
                        <input type="number" name="carga_horaria" class="form-control" 
                               placeholder="Ex: 96"
                               value="<?php echo htmlspecialchars($dados['carga_horaria'] ?? ''); ?>">
                        <div class="info-text">Carga horária total da disciplina no ano letivo</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Cor da Disciplina</label>
                        <input type="color" name="cor" class="form-control" 
                               value="<?php echo htmlspecialchars($dados['cor'] ?? '#1e5799'); ?>">
                        <div class="info-text">Cor utilizada para identificação visual</div>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" class="form-control">
                            <option value="ativo" <?php echo (($dados['status'] ?? '1') == '1') ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo (($dados['status'] ?? '') == '2') ? 'selected' : ''; ?>>Inativo</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Descrição</label>
                    <textarea name="descricao" class="form-control" 
                              placeholder="Descreva a disciplina, seus objetivos e conteúdos programáticos..."><?php echo htmlspecialchars($dados['descricao'] ?? ''); ?></textarea>
                    <div class="info-text">Descrição detalhada da disciplina (opcional)</div>
                </div>
                
                <hr>
                
                <div class="alert alert-info">
                    ℹ️ <strong>Informações importantes:</strong>
                    <ul style="margin-left: 20px; margin-top: 10px;">
                        <li>Os campos com <span class="required">*</span> são obrigatórios</li>
                        <li>O código é gerado automaticamente baseado no nome da disciplina</li>
                        <li>O código segue o padrão: SIGLA + NÚMERO (ex: MAT001)</li>
                        <li>Você pode editar o código manualmente se desejar</li>
                        <li>Disciplinas inativas não podem ser atribuídas a novas turmas</li>
                    </ul>
                </div>
                
                <div class="btn-group">
                    <button type="button" onclick="window.location.href='listar_disciplinas.php'" class="btn btn-secondary">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-primary">
                        ✅ Cadastrar Disciplina
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    const nomeInput = document.getElementById('nome_disciplina');
    const cursoSelect = document.getElementById('curso_id');
    const codigoInput = document.getElementById('codigo');
    const autoCodigoCheck = document.getElementById('auto_codigo');
    const codigoPreview = document.getElementById('codigo_preview');
    
    // Função para gerar código via AJAX
    async function gerarCodigo() {
        const nome = nomeInput.value.trim();
        const cursoId = cursoSelect.value;
        
        if (nome.length === 0) {
            codigoInput.placeholder = "Digite o nome primeiro";
            return;
        }
        
        try {
            const response = await fetch(`cadastrar_disciplina.php?ajax=gerar_codigo&nome=${encodeURIComponent(nome)}&curso_id=${cursoId}`);
            const data = await response.json();
            
            if (data.codigo) {
                if (autoCodigoCheck.checked) {
                    codigoInput.value = data.codigo;
                    codigoPreview.textContent = data.codigo;
                    codigoPreview.style.display = 'block';
                    setTimeout(() => {
                        codigoPreview.style.display = 'none';
                    }, 3000);
                } else {
                    codigoPreview.textContent = `Sugestão: ${data.codigo}`;
                    codigoPreview.style.display = 'block';
                    setTimeout(() => {
                        codigoPreview.style.display = 'none';
                    }, 3000);
                }
            }
        } catch (error) {
            console.error('Erro ao gerar código:', error);
        }
    }
    
    // Eventos para gerar código
    nomeInput.addEventListener('blur', gerarCodigo);
    nomeInput.addEventListener('keyup', function() {
        if (this.value.length > 3) {
            gerarCodigo();
        }
    });
    cursoSelect.addEventListener('change', gerarCodigo);
    
    // Habilitar/desabilitar edição manual do código
    autoCodigoCheck.addEventListener('change', function() {
        if (this.checked) {
            codigoInput.readOnly = false;
            codigoInput.placeholder = "Código será gerado automaticamente";
            codigoInput.style.backgroundColor = '#f8f9fa';
            gerarCodigo();
        } else {
            codigoInput.readOnly = false;
            codigoInput.placeholder = "Digite o código manualmente";
            codigoInput.style.backgroundColor = 'white';
        }
    });
    
    // Se já tem nome, gerar código ao carregar
    if (nomeInput.value.trim().length > 0) {
        gerarCodigo();
    }
    
    // Visualizar cor selecionada
    const corInput = document.querySelector('input[name="cor"]');
    if (corInput) {
        const preview = document.createElement('div');
        preview.className = 'cor-preview';
        preview.style.backgroundColor = corInput.value;
        corInput.parentNode.appendChild(preview);
        
        corInput.addEventListener('input', function() {
            preview.style.backgroundColor = this.value;
        });
    }
</script>
</body>
</html>