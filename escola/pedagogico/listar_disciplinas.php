<?php
// escola/pedagogico/listar_disciplinas.php - Listar Disciplinas

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

// Parâmetros de filtro
$curso_filtro = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$busca = isset($_GET['busca']) ? trim($_GET['busca']) : '';

// Buscar cursos para o filtro
$sql_cursos = "SELECT id, nome FROM cursos WHERE status = 1 ORDER BY nome";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute();
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Montar query base
$sql_disciplinas = "
    SELECT 
        d.id,
        d.nome,
        d.codigo,
        d.carga_horaria,
        d.descricao,
        d.cor,
        d.status,
        d.created_at,
        d.updated_at,
        d.curso_id,
        c.nome as curso_nome,
        COUNT(DISTINCT dt.turma_id) as total_turmas,
        COUNT(DISTINCT pdt.professor_id) as total_professores
    FROM disciplinas d
    LEFT JOIN cursos c ON c.id = d.curso_id
    LEFT JOIN disciplina_turma dt ON dt.disciplina_id = d.id
    LEFT JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
    WHERE d.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

// Aplicar filtros
if ($curso_filtro > 0) {
    $sql_disciplinas .= " AND d.curso_id = :curso_id";
    $params[':curso_id'] = $curso_filtro;
}

if ($status_filtro != '') {
    $sql_disciplinas .= " AND d.status = :status";
    $params[':status'] = $status_filtro;
}

if ($busca != '') {
    $sql_disciplinas .= " AND (d.nome LIKE :busca OR d.codigo LIKE :busca OR d.descricao LIKE :busca)";
    $params[':busca'] = "%$busca%";
}

$sql_disciplinas .= " GROUP BY d.id ORDER BY d.nome ASC";

$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute($params);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_disciplinas = count($disciplinas);
$total_ativas = count(array_filter($disciplinas, function($d) { return $d['status'] == 'ativa'; }));
$total_inativas = $total_disciplinas - $total_ativas;
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Disciplinas - SIGE Angola</title>
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
            max-width: 1400px;
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
        
        .filtros-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filtros-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .filtros-body {
            padding: 20px;
        }
        
        .filtros-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 180px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filtro-select, .filtro-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: white;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-limpar {
            background: #95a5a6;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .stat-card.blue { border-bottom: 4px solid #1e5799; }
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        .stat-card.purple { border-bottom: 4px solid #9b59b6; }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .btn-novo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .card-body {
            padding: 0;
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
        }
        
        .table td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
        }
        
        .table tr:hover {
            background: #f8f9fa;
        }
        
        .cor-disciplina {
            width: 30px;
            height: 30px;
            border-radius: 6px;
            display: inline-block;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-ativo {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .badge-inativo {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .badge-info {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .btn-acoes {
            display: inline-flex;
            gap: 8px;
        }
        
        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }
        
        .btn-view {
            color: #1e5799;
        }
        
        .btn-view:hover {
            background: #d4e6f1;
        }
        
        .btn-edit {
            color: #f39c12;
        }
        
        .btn-edit:hover {
            background: #fef9e7;
        }
        
        .btn-delete {
            color: #e74c3c;
        }
        
        .btn-delete:hover {
            background: #fadbd8;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            width: 90%;
            max-width: 600px;
            border-radius: 12px;
            animation: modalSlideIn 0.3s ease;
        }
        
        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h3 {
            font-size: 18px;
        }
        
        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        
        .close:hover {
            opacity: 0.8;
        }
        
        .modal-body {
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .filtros-row {
                flex-direction: column;
            }
            
            .filtro-group {
                width: 100%;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .table {
                font-size: 12px;
            }
            
            .table th, .table td {
                padding: 8px;
            }
            
            .btn-acoes {
                flex-direction: column;
                gap: 5px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📚 Lista de Disciplinas</h1>
            <p>Gerencie todas as disciplinas da sua escola</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar Disciplinas
        </div>
        <div class="filtros-body">
            <form method="GET" action="">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Curso</label>
                        <select name="curso_id" class="filtro-select">
                            <option value="0">Todos os cursos</option>
                            <?php foreach ($cursos as $curso): ?>
                                <option value="<?php echo $curso['id']; ?>" <?php echo ($curso_filtro == $curso['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Status</label>
                        <select name="status" class="filtro-select">
                            <option value="">Todos</option>
                            <option value="ativo" <?php echo ($status_filtro == 'ativa') ? 'selected' : ''; ?>>Ativo</option>
                            <option value="inativo" <?php echo ($status_filtro == 'inativa') ? 'selected' : ''; ?>>Inativo</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Buscar</label>
                        <input type="text" name="busca" class="filtro-input" placeholder="Nome, código ou descrição..." value="<?php echo htmlspecialchars($busca); ?>">
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                        <a href="listar_disciplinas.php" class="btn-limpar">🗑️ Limpar</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-number"><?php echo $total_disciplinas; ?></div>
            <div class="stat-label">Total de Disciplinas</div>
        </div>
        <div class="stat-card green">
            <div class="stat-number"><?php echo $total_ativas; ?></div>
            <div class="stat-label">Disciplinas Ativas</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-number"><?php echo $total_inativas; ?></div>
            <div class="stat-label">Disciplinas Inativas</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-number"><?php echo count($cursos); ?></div>
            <div class="stat-label">Cursos</div>
        </div>
    </div>
    
    <!-- Lista de Disciplinas -->
    <div class="card">
        <div class="card-header">
            <span>📋 Disciplinas Cadastradas</span>
            <a href="cadastrar_disciplina.php" class="btn-novo">
                ➕ Nova Disciplina
            </a>
        </div>
        <div class="card-body">
            <?php if (empty($disciplinas)): ?>
                <div style="text-align: center; padding: 50px;">
                    <p style="color: #7f8c8d;">Nenhuma disciplina cadastrada.</p>
                    <a href="cadastrar_disciplina.php" class="btn-novo" style="margin-top: 15px; display: inline-block;">
                        ➕ Cadastrar Primeira Disciplina
                    </a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Disciplina</th>
                            <th>Código</th>
                            <th>Curso</th>
                            <th>Carga Horária</th>
                            <th>Turmas</th>
                            <th>Professores</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disciplinas as $disciplina): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php if ($disciplina['cor']): ?>
                                            <div class="cor-disciplina" style="background-color: <?php echo htmlspecialchars($disciplina['cor']); ?>;"></div>
                                        <?php else: ?>
                                            <div class="cor-disciplina" style="background-color: #1e5799;"></div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong>
                                            <?php if ($disciplina['descricao']): ?>
                                                <br><small style="color: #7f8c8d;"><?php echo htmlspecialchars(substr($disciplina['descricao'], 0, 50)) . (strlen($disciplina['descricao']) > 50 ? '...' : ''); ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($disciplina['codigo']); ?></td>
                                <td>
                                    <?php if ($disciplina['curso_nome']): ?>
                                        <span class="badge badge-info"><?php echo htmlspecialchars($disciplina['curso_nome']); ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-info">Geral</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $disciplina['carga_horaria'] ? $disciplina['carga_horaria'] . 'h' : '---'; ?></td>
                                <td>
                                    <span class="badge badge-info"><?php echo $disciplina['total_turmas']; ?> turma(s)</span>
                                </td>
                                <td>
                                    <span class="badge badge-info"><?php echo $disciplina['total_professores']; ?> professor(es)</span>
                                </td>
                                <td>
                                    <span class="badge <?php echo $disciplina['status'] == 'ativa' ? 'badge-ativo' : 'badge-inativo'; ?>">
                                        <?php echo $disciplina['status'] == 'ativa' ? 'ATIVA' : 'INATIVA'; ?>
                                    </span>
                                </td>
                                <td class="btn-acoes">
                                    <button class="btn-icon btn-view" onclick="verDisciplina(<?php echo $disciplina['id']; ?>)" title="Ver Detalhes">
                                        👁️
                                    </button>
                                    <button class="btn-icon btn-edit" onclick="editarDisciplina(<?php echo $disciplina['id']; ?>)" title="Editar">
                                        ✏️
                                    </button>
                                    <button class="btn-icon btn-delete" onclick="excluirDisciplina(<?php echo $disciplina['id']; ?>, '<?php echo addslashes($disciplina['nome']); ?>')" title="Excluir">
                                        🗑️
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal de Detalhes -->
<div id="modalDisciplina" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 Detalhes da Disciplina</h3>
            <span class="close" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <p>Carregando...</p>
        </div>
        <div class="modal-footer">
            <button class="btn-icon btn-edit" onclick="editarFromModal()">✏️ Editar</button>
            <button onclick="fecharModal()" style="background: #95a5a6; padding: 8px 15px; border-radius: 8px; border: none; color: white; cursor: pointer;">Fechar</button>
        </div>
    </div>
</div>

<script>
    let disciplinaAtualId = null;
    
   
    function verDisciplina(id) {
    disciplinaAtualId = id;
    const modal = document.getElementById('modalDisciplina');
    const modalBody = document.getElementById('modalBody');
    
    modal.style.display = 'block';
    modalBody.innerHTML = '<p>🔍 Carregando dados da disciplina...</p>';
    
    fetch(`buscar_disciplina.php?id=${id}`)
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.error) {
                modalBody.innerHTML = `<div style="color: red; padding: 20px; text-align: center;">
                    ❌ ${data.error}<br>
                    <button onclick="fecharModal()" style="margin-top: 10px; padding: 5px 15px;">Fechar</button>
                </div>`;
                return;
            }
            
            let html = `
                <div style="margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <div style="background-color: ${data.cor || '#1e5799'}; width: 50px; height: 50px; border-radius: 10px;"></div>
                        <div>
                            <h4 style="color: #1e5799;">${escapeHtml(data.nome)}</h4>
                            <p><strong>Código:</strong> ${escapeHtml(data.codigo)}</p>
                        </div>
                    </div>
                    <hr>
                </div>
                <table style="width: 100%; border-collapse: collapse;">
                    <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Curso:</strong></td><td style="padding: 8px;">${escapeHtml(data.curso_nome)}</td></tr>
                    <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Carga Horária:</strong></td><td style="padding: 8px;">${data.carga_horaria ? data.carga_horaria + ' horas' : 'Não definida'}</td></tr>
                    <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Status:</strong></td><td style="padding: 8px;"><span class="badge ${data.status == 'ativo' ? 'badge-ativo' : 'badge-inativo'}">${data.status == 'ativo' ? 'ATIVO' : 'INATIVO'}</span></td></tr>
                    <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Turmas:</strong></td><td style="padding: 8px;">${data.total_turmas || 0} turma(s)</td></tr>
                    <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Professores:</strong></td><td style="padding: 8px;">${data.total_professores || 0} professor(es)</td></tr>
                </table>
                
                <h4 style="margin: 20px 0 10px 0; color: #1e5799;">📝 Descrição</h4>
                <div style="background: #f8f9fa; padding: 10px; border-radius: 8px;">
                    ${data.descricao ? escapeHtml(data.descricao) : 'Nenhuma descrição cadastrada.'}
                </div>
            `;
            
            // Adicionar lista de turmas se houver
            if (data.turmas && data.turmas.length > 0) {
                html += `
                    <h4 style="margin: 20px 0 10px 0; color: #1e5799;">🏫 Turmas</h4>
                    <div style="max-height: 150px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 8px; text-align: left;">Turma</th>
                                    <th style="padding: 8px; text-align: left;">Ano</th>
                                    <th style="padding: 8px; text-align: left;">Turno</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.turmas.map(t => `
                                    <tr>
                                        <td style="padding: 8px;">${escapeHtml(t.nome)}</td>
                                        <td style="padding: 8px;">${t.ano}ª</td>
                                        <td style="padding: 8px;">${escapeHtml(t.turno_nome || '-')}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            // Adicionar lista de professores se houver
            if (data.professores && data.professores.length > 0) {
                html += `
                    <h4 style="margin: 20px 0 10px 0; color: #1e5799;">👨‍🏫 Professores</h4>
                    <div style="max-height: 150px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: #f8f9fa;">
                                    <th style="padding: 8px; text-align: left;">Nome</th>
                                    <th style="padding: 8px; text-align: left;">Turma</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.professores.map(p => `
                                    <tr>
                                        <td style="padding: 8px;">${escapeHtml(p.nome)}</td>
                                        <td style="padding: 8px;">${escapeHtml(p.turma_nome)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `;
            }
            
            modalBody.innerHTML = html;
        })
        .catch(error => {
            console.error('Erro detalhado:', error);
            modalBody.innerHTML = `<div style="color: red; padding: 20px; text-align: center;">
                ❌ Erro ao carregar dados: ${error.message}<br>
                <button onclick="fecharModal()" style="margin-top: 10px; padding: 5px 15px;">Fechar</button>
            </div>`;
        });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
    
    function editarDisciplina(id) {
        window.location.href = `editar_disciplina.php?id=${id}`;
    }
    
    function editarFromModal() {
        if (disciplinaAtualId) {
            window.location.href = `editar_disciplina.php?id=${disciplinaAtualId}`;
        }
    }
    
    function excluirDisciplina(id, nome) {
        if (confirm(`Tem certeza que deseja excluir a disciplina "${nome}"?\n\nEsta ação não poderá ser desfeita!`)) {
            window.location.href = `excluir_disciplina.php?id=${id}`;
        }
    }
    
    function formatarData(data) {
        if (!data) return '---';
        const d = new Date(data);
        return d.toLocaleDateString('pt-BR') + ' ' + d.toLocaleTimeString('pt-BR');
    }
    
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function fecharModal() {
        const modal = document.getElementById('modalDisciplina');
        modal.style.display = 'none';
        disciplinaAtualId = null;
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalDisciplina');
        if (event.target == modal) {
            fecharModal();
        }
    }
</script>
</body>
</html>