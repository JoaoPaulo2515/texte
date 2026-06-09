<?php
// coordenador/configurar_conselho.php - Configurar Conselho de Nota

require_once '../includes/auth.php';
checkCoordenadorAuth();

$conn = getConnection();
$coordenador_id = $_SESSION['professor_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar ano letivo ativo
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$ano_letivo = $conn->query($sql_ano)->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'];

// Processar adicionar permissão
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['adicionar_permissao'])) {
    $professor_id = (int)$_POST['professor_id'];
    
    $sql = "INSERT INTO conselho_nota_permissoes (coordenador_id, professor_id, escola_id, ano_letivo_id) 
            VALUES (:coordenador_id, :professor_id, :escola_id, :ano_letivo_id)
            ON DUPLICATE KEY UPDATE ativo = 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':coordenador_id' => $coordenador_id,
        ':professor_id' => $professor_id,
        ':escola_id' => $escola_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $success = "Professor adicionado ao conselho com sucesso!";
}

// Processar criar sessão
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_sessao'])) {
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $data_sessao = $_POST['data_sessao'];
    $hora_inicio = $_POST['hora_inicio'];
    $hora_fim = $_POST['hora_fim'];
    $titulo = $_POST['titulo'];
    $descricao = $_POST['descricao'];
    $participantes = $_POST['participantes'] ?? [];
    
    try {
        $conn->beginTransaction();
        
        $sql = "INSERT INTO conselho_nota_sessoes (coordenador_id, escola_id, ano_letivo_id, turma_id, disciplina_id, bimestre, titulo, descricao, data_sessao, hora_inicio, hora_fim, status) 
                VALUES (:coordenador_id, :escola_id, :ano_letivo_id, :turma_id, :disciplina_id, :bimestre, :titulo, :descricao, :data_sessao, :hora_inicio, :hora_fim, 'agendado')";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':coordenador_id' => $coordenador_id,
            ':escola_id' => $escola_id,
            ':ano_letivo_id' => $ano_letivo_id,
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre,
            ':titulo' => $titulo,
            ':descricao' => $descricao,
            ':data_sessao' => $data_sessao,
            ':hora_inicio' => $hora_inicio,
            ':hora_fim' => $hora_fim
        ]);
        
        $sessao_id = $conn->lastInsertId();
        
        // Adicionar participantes
        foreach ($participantes as $prof_id) {
            $sql_part = "INSERT INTO conselho_nota_participantes (sessao_id, professor_id) VALUES (:sessao_id, :professor_id)";
            $stmt_part = $conn->prepare($sql_part);
            $stmt_part->execute([':sessao_id' => $sessao_id, ':professor_id' => $prof_id]);
        }
        
        $conn->commit();
        $success_sessao = "Sessão do conselho criada com sucesso!";
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_sessao = $e->getMessage();
    }
}

// Buscar professores da escola
$sql_professores = "
    SELECT p.id, u.nome, u.email
    FROM professores p
    INNER JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.escola_id = :escola_id
    ORDER BY u.nome
";
$professores = $conn->prepare($sql_professores);
$professores->execute([':escola_id' => $escola_id]);
$professores = $professores->fetchAll();

// Buscar professores com permissão
$sql_permissoes = "
    SELECT cnp.*, u.nome as professor_nome
    FROM conselho_nota_permissoes cnp
    INNER JOIN professores p ON p.id = cnp.professor_id
    INNER JOIN usuarios u ON u.id = p.usuario_id
    WHERE cnp.escola_id = :escola_id AND cnp.ano_letivo_id = :ano_letivo_id AND cnp.ativo = 1
";
$stmt_perm = $conn->prepare($sql_permissoes);
$stmt_perm->execute([':escola_id' => $escola_id, ':ano_letivo_id' => $ano_letivo_id]);
$permissoes = $stmt_perm->fetchAll();

// Buscar turmas
$sql_turmas = "SELECT id, nome, serie FROM turmas WHERE escola_id = :escola_id ORDER BY serie, nome";
$turmas = $conn->prepare($sql_turmas);
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll();

// Buscar disciplinas
$sql_disciplinas = "SELECT id, nome FROM disciplinas ORDER BY nome";
$disciplinas = $conn->query($sql_disciplinas)->fetchAll();

// Buscar sessões criadas
$sql_sessoes = "
    SELECT cns.*, t.nome as turma_nome, d.nome as disciplina_nome,
           (SELECT COUNT(*) FROM conselho_nota_participantes WHERE sessao_id = cns.id) as total_participantes
    FROM conselho_nota_sessoes cns
    INNER JOIN turmas t ON t.id = cns.turma_id
    INNER JOIN disciplinas d ON d.id = cns.disciplina_id
    WHERE cns.escola_id = :escola_id AND cns.ano_letivo_id = :ano_letivo_id
    ORDER BY cns.data_sessao DESC
";
$stmt_sessoes = $conn->prepare($sql_sessoes);
$stmt_sessoes->execute([':escola_id' => $escola_id, ':ano_letivo_id' => $ano_letivo_id]);
$sessoes = $stmt_sessoes->fetchAll();
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Configurar Conselho de Nota - Coordenador</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
</head>
<body>
    <div class="container mt-4">
        <h2><i class="fas fa-chalkboard-teacher"></i> Configurar Conselho de Nota</h2>
        
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#permissoes">Permissões</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sessoes">Sessões</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#listasessoes">Sessões Criadas</a></li>
        </ul>
        
        <div class="tab-content">
            <!-- Aba de Permissões -->
            <div class="tab-pane fade show active" id="permissoes">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5><i class="fas fa-user-plus"></i> Adicionar Professor ao Conselho</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success)): ?>
                            <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST" class="row g-3">
                            <div class="col-md-8">
                                <label>Professor</label>
                                <select name="professor_id" class="form-select select2" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($professores as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>"><?php echo htmlspecialchars($prof['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label>&nbsp;</label>
                                <button type="submit" name="adicionar_permissao" class="btn btn-primary w-100">Adicionar</button>
                            </div>
                        </form>
                        
                        <hr>
                        <h5>Professores com Permissão</h5>
                        <table class="table table-bordered">
                            <thead>
                                <tr><th>Nome</th><th>Adicionado por</th><th>Data</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($permissoes as $p): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($p['professor_nome']); ?></td>
                                    <td>Coordenador</td>
                                    <td><?php echo date('d/m/Y', strtotime($p['created_at'])); ?></td>
                                    <td><button class="btn btn-sm btn-danger" onclick="removerPermissao(<?php echo $p['id']; ?>)">Remover</button></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Aba de Criar Sessão -->
            <div class="tab-pane fade" id="sessoes">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5><i class="fas fa-calendar-plus"></i> Criar Sessão do Conselho</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($success_sessao)): ?>
                            <div class="alert alert-success"><?php echo $success_sessao; ?></div>
                        <?php endif; ?>
                        <?php if (isset($error_sessao)): ?>
                            <div class="alert alert-danger"><?php echo $error_sessao; ?></div>
                        <?php endif; ?>
                        
                        <form method="POST">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label>Turma *</label>
                                    <select name="turma_id" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($turmas as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nome']); ?> - <?php echo $t['serie']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Disciplina *</label>
                                    <select name="disciplina_id" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($disciplinas as $d): ?>
                                        <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Bimestre *</label>
                                    <select name="bimestre" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <option value="1">1º Bimestre</option>
                                        <option value="2">2º Bimestre</option>
                                        <option value="3">3º Bimestre</option>
                                        <option value="4">4º Bimestre</option>
                                    </select>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Data da Sessão *</label>
                                    <input type="date" name="data_sessao" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Hora Início *</label>
                                    <input type="time" name="hora_inicio" class="form-control" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label>Hora Fim *</label>
                                    <input type="time" name="hora_fim" class="form-control" required>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label>Título</label>
                                    <input type="text" name="titulo" class="form-control" placeholder="Ex: Conselho de Nota - Matemática - 1º Bimestre">
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label>Descrição</label>
                                    <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva os objetivos da sessão..."></textarea>
                                </div>
                                <div class="col-md-12 mb-3">
                                    <label>Participantes (Professores com permissão)</label>
                                    <select name="participantes[]" class="form-select select2" multiple required>
                                        <?php foreach ($permissoes as $p): ?>
                                        <option value="<?php echo $p['professor_id']; ?>"><?php echo htmlspecialchars($p['professor_nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <button type="submit" name="criar_sessao" class="btn btn-success">Criar Sessão</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Aba de Lista de Sessões -->
            <div class="tab-pane fade" id="listasessoes">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5><i class="fas fa-list"></i> Sessões Criadas</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-bordered">
                            <thead>
                                <tr><th>Título</th><th>Turma</th><th>Disciplina</th><th>Data</th><th>Participantes</th><th>Status</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessoes as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['titulo'] ?: $s['disciplina_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($s['turma_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($s['disciplina_nome']); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($s['data_sessao'] . ' ' . $s['hora_inicio'])); ?></td>
                                    <td><?php echo $s['total_participantes']; ?> professores</td>
                                    <td><span class="badge bg-<?php echo $s['status'] == 'agendado' ? 'warning' : ($s['status'] == 'em_andamento' ? 'info' : 'secondary'); ?>">
                                        <?php echo $s['status']; ?>
                                    </span></td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" onclick="iniciarSessao(<?php echo $s['id']; ?>)">Iniciar</button>
                                        <button class="btn btn-sm btn-danger" onclick="cancelarSessao(<?php echo $s['id']; ?>)">Cancelar</button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $('.select2').select2({ theme: 'bootstrap-5' });
        
        function removerPermissao(id) {
            if (confirm('Tem certeza que deseja remover este professor do conselho?')) {
                window.location = 'configurar_conselho.php?remover=' + id;
            }
        }
        
        function iniciarSessao(id) {
            if (confirm('Iniciar esta sessão do conselho?')) {
                window.location = 'configurar_conselho.php?iniciar=' + id;
            }
        }
        
        function cancelarSessao(id) {
            if (confirm('Cancelar esta sessão do conselho?')) {
                window.location = 'configurar_conselho.php?cancelar=' + id;
            }
        }
    </script>
</body>
</html>