<?php
// escola/pedagogico/lista_chamada.php - Listar Chamadas Registradas

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
$professor_id = $funcionario['id'] ?? 0;

// Buscar turmas para filtro
$sql_turmas = "
    SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas para filtro
$sql_disciplinas = "
    SELECT d.id, d.nome, d.codigo
    FROM disciplinas d
    WHERE d.escola_id = :escola_id AND d.status = 'ativa'
    ORDER BY d.nome ASC
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Parâmetros de filtro
$turma_filtro = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_filtro = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_filtro = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : date('Y-m-d', strtotime('-30 days'));
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : date('Y-m-d');
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

// Se não tem ano letivo selecionado, pegar o mais recente
if ($ano_letivo_filtro == 0 && !empty($anos_letivos)) {
    $ano_letivo_filtro = $anos_letivos[0]['id'];
}

// Buscar chamadas
$sql_chamadas = "
    SELECT 
        c.*,
        e.nome as estudante_nome,
        e.matricula,
        d.nome as disciplina_nome,
        d.codigo as disciplina_codigo,
        t.nome as turma_nome,
        t.ano as turma_ano,
        tr.nome as turno_nome,
        al.ano as ano_letivo,
        fu.nome as professor_nome
    FROM chamada c
    INNER JOIN estudantes e ON e.id = c.estudante_id
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN turmas t ON t.id = c.turma_id
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    LEFT JOIN ano_letivo al ON al.id = c.ano_letivo_id
    LEFT JOIN funcionarios fu ON fu.id = c.lancado_por
    WHERE c.escola_id = :escola_id
";

$params = [':escola_id' => $escola_id];

if ($turma_filtro > 0) {
    $sql_chamadas .= " AND c.turma_id = :turma_id";
    $params[':turma_id'] = $turma_filtro;
}
if ($disciplina_filtro > 0) {
    $sql_chamadas .= " AND c.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_filtro;
}
if ($ano_letivo_filtro > 0) {
    $sql_chamadas .= " AND c.ano_letivo_id = :ano_letivo_id";
    $params[':ano_letivo_id'] = $ano_letivo_filtro;
}
if ($data_inicio) {
    $sql_chamadas .= " AND c.data_aula >= :data_inicio";
    $params[':data_inicio'] = $data_inicio;
}
if ($data_fim) {
    $sql_chamadas .= " AND c.data_aula <= :data_fim";
    $params[':data_fim'] = $data_fim;
}
if ($status_filtro != '') {
    $sql_chamadas .= " AND c.status = :status";
    $params[':status'] = $status_filtro;
}
if ($bimestre_filtro > 0) {
    $sql_chamadas .= " AND c.bimestre = :bimestre";
    $params[':bimestre'] = $bimestre_filtro;
}

$sql_chamadas .= " ORDER BY c.data_aula DESC, c.created_at DESC";

$stmt_chamadas = $conn->prepare($sql_chamadas);
$stmt_chamadas->execute($params);
$chamadas = $stmt_chamadas->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas
$total_registros = count($chamadas);
$total_presentes = count(array_filter($chamadas, function($c) { return $c['status'] == 'presente'; }));
$total_faltas = count(array_filter($chamadas, function($c) { return $c['status'] == 'falta'; }));
$total_atrasos = count(array_filter($chamadas, function($c) { return $c['status'] == 'atrasado'; }));

// Status para badge
$status_badge = [
    'presente' => 'badge-presente',
    'falta' => 'badge-falta',
    'atrasado' => 'badge-atrasado'
];

$status_texto = [
    'presente' => '✅ Presente',
    'falta' => '❌ Falta',
    'atrasado' => '⏰ Atrasado'
];

$status_cor = [
    'presente' => 'green',
    'falta' => 'red',
    'atrasado' => 'orange'
];
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Chamadas - SIGE Angola</title>
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
            min-width: 150px;
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
            text-decoration: none;
        }
        
        .btn-limpar:hover {
            background: #7f8c8d;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
        
        .stat-card.green { border-bottom: 4px solid #27ae60; }
        .stat-card.red { border-bottom: 4px solid #e74c3c; }
        .stat-card.orange { border-bottom: 4px solid #f39c12; }
        .stat-card.blue { border-bottom: 4px solid #1e5799; }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 12px;
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
        }
        
        .card-body {
            padding: 0;
            overflow-x: auto;
        }
        
        .table-chamada {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-chamada th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 13px;
        }
        
        .table-chamada td {
            padding: 12px;
            border-bottom: 1px solid #ecf0f1;
            vertical-align: middle;
            font-size: 13px;
        }
        
        .table-chamada tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .badge-presente {
            background: #d5f4e6;
            color: #27ae60;
        }
        
        .badge-falta {
            background: #fadbd8;
            color: #c0392b;
        }
        
        .badge-atrasado {
            background: #fef9e7;
            color: #f39c12;
        }
        
        .btn-edit {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            padding: 5px;
            border-radius: 5px;
            transition: all 0.3s ease;
            color: #f39c12;
        }
        
        .btn-edit:hover {
            background: #fef9e7;
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
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
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
        }
        
        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #ecf0f1;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1e5799;
            box-shadow: 0 0 0 3px rgba(30, 87, 153, 0.1);
        }
        
        .btn-salvar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-salvar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .btn-cancelar {
            background: #95a5a6;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-cancelar:hover {
            background: #7f8c8d;
        }
        
        .radio-group {
            display: flex;
            gap: 15px;
            margin-top: 5px;
        }
        
        .radio-option {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }
        
        .radio-option input {
            margin: 0;
        }
        
        .alert-info {
            background: #d4e6f1;
            color: #1e5799;
            border-left: 4px solid #1e5799;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
            
            .table-chamada {
                font-size: 11px;
            }
            
            .table-chamada th, .table-chamada td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📋 Lista de Chamadas</h1>
            <p>Visualize todas as chamadas registradas</p>
        </div>
        <div>
            <a href="marcar_presenca.php" class="btn-voltar">
                ← Marcar Presença
            </a>
            <a href="index.php" class="btn-voltar" style="margin-left: 10px;">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">
            🔍 Filtrar Chamadas
        </div>
        <div class="filtros-body">
            <form method="GET" action="" class="filtros-row">
                <div class="filtro-group">
                    <label>Turma</label>
                    <select name="turma_id" class="filtro-select">
                        <option value="0">Todas as turmas</option>
                        <?php foreach ($turmas as $t): ?>
                            <option value="<?php echo $t['id']; ?>" <?php echo ($turma_filtro == $t['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($t['nome']); ?> - <?php echo $t['ano']; ?>ª - <?php echo ucfirst($t['turno_nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Disciplina</label>
                    <select name="disciplina_id" class="filtro-select">
                        <option value="0">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $d): ?>
                            <option value="<?php echo $d['id']; ?>" <?php echo ($disciplina_filtro == $d['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($d['nome']); ?> (<?php echo htmlspecialchars($d['codigo']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Ano Letivo</label>
                    <select name="ano_letivo_id" class="filtro-select">
                        <option value="0">Todos os anos</option>
                        <?php foreach ($anos_letivos as $ano): ?>
                            <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_filtro == $ano['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ano['ano']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio" class="filtro-input" value="<?php echo $data_inicio; ?>">
                </div>
                <div class="filtro-group">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim" class="filtro-input" value="<?php echo $data_fim; ?>">
                </div>
                <div class="filtro-group">
                    <label>Status</label>
                    <select name="status" class="filtro-select">
                        <option value="">Todos</option>
                        <option value="presente" <?php echo ($status_filtro == 'presente') ? 'selected' : ''; ?>>Presente</option>
                        <option value="falta" <?php echo ($status_filtro == 'falta') ? 'selected' : ''; ?>>Falta</option>
                        <option value="atrasado" <?php echo ($status_filtro == 'atrasado') ? 'selected' : ''; ?>>Atrasado</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <label>Bimestre</label>
                    <select name="bimestre" class="filtro-select">
                        <option value="0">Todos</option>
                        <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                        <option value="4" <?php echo ($bimestre_filtro == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                    </select>
                </div>
                <div class="filtro-group">
                    <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                    <a href="lista_chamada.php" class="btn-limpar">🗑️ Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Estatísticas -->
    <div class="stats-grid">
        <div class="stat-card blue">
            <div class="stat-number"><?php echo $total_registros; ?></div>
            <div class="stat-label">Total de Registros</div>
        </div>
        <div class="stat-card green">
            <div class="stat-number"><?php echo $total_presentes; ?></div>
            <div class="stat-label">✅ Presentes</div>
        </div>
        <div class="stat-card red">
            <div class="stat-number"><?php echo $total_faltas; ?></div>
            <div class="stat-label">❌ Faltas</div>
        </div>
        <div class="stat-card orange">
            <div class="stat-number"><?php echo $total_atrasos; ?></div>
            <div class="stat-label">⏰ Atrasos</div>
        </div>
    </div>
    
    <!-- Lista de Chamadas -->
    <div class="card">
        <div class="card-header">
            <span>📋 Chamadas Registradas</span>
        </div>
        <div class="card-body">
            <?php if (empty($chamadas)): ?>
                <div style="text-align: center; padding: 50px;">
                    <p style="color: #7f8c8d;">Nenhuma chamada registrada.</p>
                </div>
            <?php else: ?>
                <table class="table-chamada">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Aluno</th>
                            <th>Matrícula</th>
                            <th>Disciplina</th>
                            <th>Turma</th>
                            <th>Status</th>
                            <th>Atraso</th>
                            <th>Justificativa</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chamadas as $chamada): ?>
                            <tr>
                                <td><?php echo date('d/m/Y', strtotime($chamada['data_aula'])); ?></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($chamada['estudante_nome']); ?></strong>
                                 </div>
                                <td><?php echo htmlspecialchars($chamada['matricula']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($chamada['disciplina_nome']); ?><br>
                                    <small><?php echo htmlspecialchars($chamada['disciplina_codigo']); ?></small>
                                 </div>
                                <td>
                                    <?php echo htmlspecialchars($chamada['turma_nome']); ?> - <?php echo $chamada['turma_ano']; ?>ª
                                 </div>
                                <td>
                                    <span class="badge badge-<?php echo $chamada['status']; ?>">
                                        <?php echo $status_texto[$chamada['status']]; ?>
                                    </span>
                                 </div>
                                <td>
                                    <?php echo $chamada['minutos_atraso'] ? $chamada['minutos_atraso'] . ' min' : '-'; ?>
                                 </div>
                                <td>
                                    <?php echo htmlspecialchars(substr($chamada['justificativa'] ?? '', 0, 50)) . (strlen($chamada['justificativa'] ?? '') > 50 ? '...' : ''); ?>
                                 </div>
                                <td>
                                    <button class="btn-edit" onclick="editarChamada(<?php echo $chamada['id']; ?>, '<?php echo $chamada['status']; ?>', <?php echo $chamada['minutos_atraso']; ?>, '<?php echo addslashes($chamada['justificativa']); ?>', '<?php echo addslashes($chamada['observacao']); ?>')" title="Editar">
                                        ✏️
                                    </button>
                                 </div>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal de Edição -->
<div id="modalEditar" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>✏️ Editar Chamada</h3>
            <span class="close" onclick="fecharModal()">&times;</span>
        </div>
        <form method="POST" action="editar_chamada.php" id="formEditar">
            <input type="hidden" name="chamada_id" id="edit_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>Status</label>
                    <div class="radio-group" id="edit_status_group">
                        <label class="radio-option">
                            <input type="radio" name="status" value="presente"> ✅ Presente
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="status" value="falta"> ❌ Falta
                        </label>
                        <label class="radio-option">
                            <input type="radio" name="status" value="atrasado"> ⏰ Atrasado
                        </label>
                    </div>
                </div>
                <div class="form-group">
                    <label>Minutos de Atraso</label>
                    <input type="number" name="minutos_atraso" id="edit_minutos_atraso" class="form-control" min="0" max="180">
                </div>
                <div class="form-group">
                    <label>Justificativa</label>
                    <textarea name="justificativa" id="edit_justificativa" class="form-control" rows="3" placeholder="Justificativa da falta/atraso..."></textarea>
                </div>
                <div class="form-group">
                    <label>Observação</label>
                    <textarea name="observacao" id="edit_observacao" class="form-control" rows="2" placeholder="Observações adicionais..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="fecharModal()" class="btn-cancelar">Cancelar</button>
                <button type="submit" class="btn-salvar">💾 Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<script>
    function editarChamada(id, status, minutos_atraso, justificativa, observacao) {
        const modal = document.getElementById('modalEditar');
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_minutos_atraso').value = minutos_atraso;
        document.getElementById('edit_justificativa').value = justificativa;
        document.getElementById('edit_observacao').value = observacao;
        
        // Selecionar o radio button correto
        const radios = document.querySelectorAll('input[name="status"]');
        radios.forEach(radio => {
            if (radio.value === status) {
                radio.checked = true;
            }
        });
        
        modal.style.display = 'block';
    }
    
    function fecharModal() {
        const modal = document.getElementById('modalEditar');
        modal.style.display = 'none';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalEditar');
        if (event.target == modal) {
            fecharModal();
        }
    }
</script>
</body>
</html>