<?php
// escola/pedagogico/listar_turmas.php - Listar Turmas

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];
$ano_letivo_atual = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : date('Y');

// Buscar anos letivos para filtro
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas com informações completas
$sql_turmas = "
    SELECT 
        t.id,
        t.nome,
        t.ano,
        t.turno,
        t.sala,
        t.capacidade,
        t.ativo,
        COUNT(DISTINCT m.id) as total_alunos,
        COUNT(DISTINCT CASE WHEN m.status = 'ativa' THEN m.id END) as alunos_ativos,
        GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas
    FROM turmas t
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.ano_letivo = :ano_letivo
    LEFT JOIN turmas_disciplinas td ON td.turma_id = t.id
    LEFT JOIN disciplinas d ON d.id = td.disciplina_id
    WHERE t.escola_id = :escola_id
    GROUP BY t.id
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([
    ':escola_id' => $escola_id,
    ':ano_letivo' => $ano_letivo_atual
]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_turmas = count($turmas);
$total_alunos = array_sum(array_column($turmas, 'alunos_ativos'));
$turmas_ativas = count(array_filter($turmas, function($t) { return $t['ativo'] == 1; }));
$media_alunos_por_turma = $total_turmas > 0 ? round($total_alunos / $total_turmas, 1) : 0;
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Turmas - SIGE Angola</title>
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
        
        /* Header */
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
        
        /* Filtros */
        .filtros {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .filtro-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .filtro-group label {
            font-weight: 600;
            color: #2c3e50;
        }
        
        .filtro-select {
            padding: 8px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .btn-novo {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-novo:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        /* Cards de Estatísticas */
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
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
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
        
        /* Tabela de Turmas */
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
        
        /* Badges */
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
        
        .badge-manha {
            background: #d4e6f1;
            color: #1e5799;
        }
        
        .badge-tarde {
            background: #fef9e7;
            color: #f39c12;
        }
        
        .badge-noite {
            background: #e8daef;
            color: #8e44ad;
        }
        
        /* Ações */
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
        
        /* Modal */
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
            max-width: 800px;
            border-radius: 12px;
            box-shadow: 0 5px 30px rgba(0,0,0,0.3);
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
            transition: all 0.3s ease;
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
        
        /* Responsivo */
        @media (max-width: 768px) {
            body {
                padding: 10px;
            }
            
            .header {
                flex-direction: column;
                text-align: center;
            }
            
            .filtros {
                flex-direction: column;
            }
            
            .filtro-group {
                width: 100%;
                justify-content: space-between;
            }
            
            .filtro-select {
                flex: 1;
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
        
        @media print {
            .btn-voltar, .filtros, .btn-acoes, .btn-novo {
                display: none;
            }
            
            body {
                background: white;
                padding: 0;
            }
            
            .card {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Header -->
    <div class="header">
        <div class="header-title">
            <h1>🏫 Lista de Turmas</h1>
            <p>Gerencie todas as turmas da sua escola</p>
        </div>
        <div>
            <a href="../dashboard.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filtros">
        <div class="filtro-group">
            <label>📅 Ano Letivo:</label>
            <select id="anoLetivo" class="filtro-select" onchange="filtrarAno()">
                <?php foreach ($anos_letivos as $ano): ?>
                    <option value="<?php echo $ano['ano']; ?>" <?php echo $ano_letivo_atual == $ano['ano'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($ano['ano']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <a href="cadastrar_turma.php" class="btn-novo">
            ➕ Nova Turma
        </a>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-number"><?php echo $total_turmas; ?></div>
            <div class="stat-label">Total de Turmas</div>
        </div>
        <div class="stat-card green">
            <div class="stat-number"><?php echo $turmas_ativas; ?></div>
            <div class="stat-label">Turmas Ativas</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-number"><?php echo $total_alunos; ?></div>
            <div class="stat-label">Alunos Matriculados</div>
        </div>
        <div class="stat-card purple">
            <div class="stat-number"><?php echo $media_alunos_por_turma; ?></div>
            <div class="stat-label">Média Alunos/Turma</div>
        </div>
    </div>
    
    <!-- Lista de Turmas -->
    <div class="card">
        <div class="card-header">
            <span>📋 Turmas Cadastradas</span>
            <span style="font-size: 12px;">Total: <?php echo $total_turmas; ?> turmas | Ano Letivo: <?php echo $ano_letivo_atual; ?></span>
        </div>
        <div class="card-body">
            <?php if (empty($turmas)): ?>
                <div style="text-align: center; padding: 50px;">
                    <p style="color: #7f8c8d;">Nenhuma turma cadastrada ainda.</p>
                    <a href="cadastrar_turma.php" class="btn-novo" style="margin-top: 15px; display: inline-block;">➕ Cadastrar Primeira Turma</a>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Turma</th>
                            <th>Ano</th>
                            <th>Turno</th>
                            <th>Sala</th>
                            <th>Capacidade</th>
                            <th>Alunos</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($turmas as $turma): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($turma['nome']); ?></strong>
                                    <?php if ($turma['disciplinas']): ?>
                                        <br><small style="color: #7f8c8d;">📚 <?php echo htmlspecialchars(substr($turma['disciplinas'], 0, 50)) . (strlen($turma['disciplinas']) > 50 ? '...' : ''); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $turma['ano'] . 'ª'; ?> Ano</td>
                                <td>
                                    <span class="badge badge-<?php echo $turma['turno']; ?>">
                                        <?php echo ucfirst($turma['turno']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($turma['sala'] ?? 'Não definida'); ?></td>
                                <td><?php echo $turma['capacidade'] ?? '-'; ?></td>
                                <td>
                                    <?php echo $turma['alunos_ativos']; ?> / <?php echo $turma['capacidade'] ?? '∞'; ?>
                                    <br>
                                    <small style="color: #7f8c8d;">Total: <?php echo $turma['total_alunos']; ?></small>
                                </td>
                                <td>
                                    <span class="badge <?php echo $turma['ativo'] == 1 ? 'badge-ativo' : 'badge-inativo'; ?>">
                                        <?php echo $turma['ativo'] == 1 ? 'ATIVO' : 'INATIVO'; ?>
                                    </span>
                                </td>
                                <td class="btn-acoes">
                                    <button class="btn-icon btn-view" onclick="verTurma(<?php echo $turma['id']; ?>)" title="Ver Detalhes">
                                        👁️
                                    </button>
                                    <button class="btn-icon btn-edit" onclick="editarTurma(<?php echo $turma['id']; ?>)" title="Editar">
                                        ✏️
                                    </button>
                                    <button class="btn-icon btn-delete" onclick="excluirTurma(<?php echo $turma['id']; ?>, '<?php echo addslashes($turma['nome']); ?>')" title="Excluir">
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

<!-- Modal de Detalhes da Turma -->
<div id="modalTurma" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>📋 Detalhes da Turma</h3>
            <span class="close" onclick="fecharModal()">&times;</span>
        </div>
        <div class="modal-body" id="modalBody">
            <p>Carregando...</p>
        </div>
        <div class="modal-footer">
            <button class="btn-icon btn-edit" onclick="editarTurmaFromModal()">✏️ Editar</button>
            <button class="btn-icon btn-delete" onclick="excluirTurmaFromModal()">🗑️ Excluir</button>
            <button class="btn-voltar" onclick="fecharModal()" style="background: #95a5a6;">Fechar</button>
        </div>
    </div>
</div>

<script>
    let turmaAtualId = null;
    
    function filtrarAno() {
        const ano = document.getElementById('anoLetivo').value;
        window.location.href = `listar_turmas.php?ano_letivo=${ano}`;
    }
    
    function verTurma(id) {
        turmaAtualId = id;
        const modal = document.getElementById('modalTurma');
        const modalBody = document.getElementById('modalBody');
        
        modal.style.display = 'block';
        modalBody.innerHTML = '<p>🔍 Carregando dados da turma...</p>';
        
        fetch(`buscar_turma.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    modalBody.innerHTML = `<p style="color: red;">❌ ${data.error}</p>`;
                    return;
                }
                
                let html = `
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: #1e5799;">${data.nome}</h4>
                        <hr>
                    </div>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Ano:</strong></td><td style="padding: 8px;">${data.ano}ª Ano</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Turno:</strong></td><td style="padding: 8px;">${data.turno}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Sala:</strong></td><td style="padding: 8px;">${data.sala || 'Não definida'}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Capacidade:</strong></td><td style="padding: 8px;">${data.capacidade || 'Não definida'}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Status:</strong></td><td style="padding: 8px;"><span class="badge ${data.ativo == 1 ? 'badge-ativo' : 'badge-inativo'}">${data.ativo == 1 ? 'ATIVO' : 'INATIVO'}</span></td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Total Alunos:</strong></td><td style="padding: 8px;">${data.total_alunos || 0}</td></tr>
                        <tr><td style="padding: 8px; background: #f8f9fa;"><strong>Alunos Ativos:</strong></td><td style="padding: 8px;">${data.alunos_ativos || 0}</td></tr>
                    </table>
                    
                    <h4 style="margin: 20px 0 10px 0; color: #1e5799;">📚 Disciplinas</h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                        ${data.disciplinas && data.disciplinas.length > 0 ? data.disciplinas.map(d => `<span class="badge" style="background: #ecf0f1; color: #2c3e50;">📖 ${d.nome}</span>`).join('') : '<p>Nenhuma disciplina associada.</p>'}
                    </div>
                    
                    <h4 style="margin: 20px 0 10px 0; color: #1e5799;">👨‍🎓 Alunos Matriculados</h4>
                    <div style="max-height: 200px; overflow-y: auto;">
                        ${data.alunos && data.alunos.length > 0 ? `
                            <table style="width: 100%; border-collapse: collapse;">
                                <thead>
                                    <tr style="background: #f8f9fa;">
                                        <th style="padding: 8px; text-align: left;">Nome</th>
                                        <th style="padding: 8px; text-align: left;">Matrícula</th>
                                        <th style="padding: 8px; text-align: left;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${data.alunos.map(aluno => `
                                        <tr>
                                            <td style="padding: 8px;">${aluno.nome}</td>
                                            <td style="padding: 8px;">${aluno.matricula}</td>
                                            <td style="padding: 8px;"><span class="badge badge-ativo">ATIVO</span></td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        ` : '<p>Nenhum aluno matriculado nesta turma.</p>'}
                    </div>
                `;
                
                modalBody.innerHTML = html;
            })
            .catch(error => {
                modalBody.innerHTML = `<p style="color: red;">❌ Erro ao carregar dados: ${error.message}</p>`;
            });
    }
    
    function editarTurma(id) {
        window.location.href = `editar_turma.php?id=${id}`;
    }
    
    function editarTurmaFromModal() {
        if (turmaAtualId) {
            window.location.href = `editar_turma.php?id=${turmaAtualId}`;
        }
    }
    
    function excluirTurma(id, nome) {
        if (confirm(`Tem certeza que deseja excluir a turma "${nome}"?\n\nEsta ação não poderá ser desfeita!`)) {
            window.location.href = `excluir_turma.php?id=${id}`;
        }
    }
    
    function excluirTurmaFromModal() {
        if (turmaAtualId) {
            if (confirm(`Tem certeza que deseja excluir esta turma?\n\nEsta ação não poderá ser desfeita!`)) {
                window.location.href = `excluir_turma.php?id=${turmaAtualId}`;
            }
        }
    }
    
    function fecharModal() {
        const modal = document.getElementById('modalTurma');
        modal.style.display = 'none';
        turmaAtualId = null;
    }
    
    // Fechar modal clicando fora
    window.onclick = function(event) {
        const modal = document.getElementById('modalTurma');
        if (event.target == modal) {
            fecharModal();
        }
    }
</script>
</body>
</html>