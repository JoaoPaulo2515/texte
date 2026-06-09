<?php
// escola/servicos_pedagogicos/coordenacao/relatorios.php - Relatórios Pedagógicos
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// PROCESSAR EXPORTAÇÕES
// ============================================

// Exportar para Excel/CSV
if (isset($_GET['exportar']) && isset($_GET['tipo_relatorio'])) {
    $tipo_relatorio = $_GET['tipo_relatorio'];
    $formato = $_GET['formato'] ?? 'excel';
    
    // Buscar dados conforme o tipo de relatório
    $dados = [];
    $titulo = '';
    
    if ($tipo_relatorio == 'aprovacao') {
        $ano_letivo = $_GET['ano_letivo'] ?? date('Y') . '/' . (date('Y')+1);
        $stmt = $conn->prepare("
            SELECT 
                t.nome as turma,
                COUNT(DISTINCT e.id) as total_alunos,
                COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) as aprovados,
                COUNT(CASE WHEN n.status = 'reprovado' THEN 1 END) as reprovados,
                COUNT(CASE WHEN n.status = 'recuperacao' THEN 1 END) as recuperacao,
                ROUND(COUNT(CASE WHEN n.status = 'aprovado' THEN 1 END) * 100.0 / COUNT(DISTINCT e.id), 1) as taxa_aprovacao
            FROM turmas t
            JOIN matriculas m ON m.turma_id = t.id
            JOIN estudantes e ON e.id = m.estudante_id
            LEFT JOIN notas n ON n.matricula_id = m.id AND n.status IS NOT NULL
            WHERE t.escola_id = :escola_id AND m.ano_letivo = :ano_letivo
            GROUP BY t.id
            ORDER BY t.ano, t.nome
        ");
        $stmt->execute([':escola_id' => $escola_id, ':ano_letivo' => $ano_letivo]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $titulo = "Relatório de Aprovação - $ano_letivo";
        
    } elseif ($tipo_relatorio == 'frequencia') {
        $periodo = $_GET['periodo'] ?? 'Anual';
        $stmt = $conn->prepare("
            SELECT 
                u.nome as aluno,
                t.nome as turma,
                COUNT(c.id) as total_aulas,
                SUM(CASE WHEN c.presenca = 'presente' THEN 1 ELSE 0 END) as presencas,
                SUM(CASE WHEN c.presenca = 'falta' THEN 1 ELSE 0 END) as faltas,
                SUM(CASE WHEN c.presenca = 'justificado' THEN 1 ELSE 0 END) as justificadas,
                ROUND(SUM(CASE WHEN c.presenca = 'presente' THEN 1 ELSE 0 END) * 100.0 / COUNT(c.id), 1) as percentual
            FROM chamadas c
            JOIN matriculas m ON m.id = c.matricula_id
            JOIN estudantes e ON e.id = m.estudante_id
            JOIN usuarios u ON u.id = e.usuario_id
            JOIN turmas t ON t.id = m.turma_id
            WHERE e.escola_id = :escola_id AND c.periodo = :periodo
            GROUP BY e.id
            ORDER BY percentual ASC
        ");
        $stmt->execute([':escola_id' => $escola_id, ':periodo' => $periodo]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $titulo = "Relatório de Frequência - $periodo";
        
    } elseif ($tipo_relatorio == 'desempenho') {
        $disciplina_id = $_GET['disciplina_id'] ?? 0;
        $stmt = $conn->prepare("
            SELECT 
                u.nome as aluno,
                t.nome as turma,
                d.nome as disciplina,
                n.nota1, n.nota2, n.nota3, n.nota4,
                n.media_parcial, n.exame, n.media_final,
                n.status
            FROM notas n
            JOIN matriculas m ON m.id = n.matricula_id
            JOIN estudantes e ON e.id = m.estudante_id
            JOIN usuarios u ON u.id = e.usuario_id
            JOIN turmas t ON t.id = m.turma_id
            JOIN disciplinas d ON d.id = n.disciplina_id
            WHERE e.escola_id = :escola_id AND n.disciplina_id = :disciplina_id
            ORDER BY u.nome
        ");
        $stmt->execute([':escola_id' => $escola_id, ':disciplina_id' => $disciplina_id]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt_disc = $conn->prepare("SELECT nome FROM disciplinas WHERE id = :id");
        $stmt_disc->execute([':id' => $disciplina_id]);
        $disciplina_nome = $stmt_disc->fetch(PDO::FETCH_ASSOC)['nome'] ?? 'Desconhecida';
        $titulo = "Relatório de Desempenho - $disciplina_nome";
        
    } elseif ($tipo_relatorio == 'turmas') {
        $stmt = $conn->prepare("
            SELECT 
                t.id, t.nome, t.ano, t.turno, t.sala, t.capacidade,
                COUNT(DISTINCT m.estudante_id) as total_alunos,
                COUNT(DISTINCT dt.disciplina_id) as total_disciplinas
            FROM turmas t
            LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
            LEFT JOIN disciplina_turma dt ON dt.turma_id = t.id AND dt.status = 'ativo'
            WHERE t.escola_id = :escola_id
            GROUP BY t.id
            ORDER BY t.ano, t.nome
        ");
        $stmt->execute([':escola_id' => $escola_id]);
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $titulo = "Relatório de Turmas";
        
    } elseif ($tipo_relatorio == 'alunos') {
        $turma_id = $_GET['turma_id'] ?? 0;
        $sql = "
            SELECT 
                u.nome, e.matricula, e.bi, e.data_nascimento, e.genero,
                t.nome as turma, m.data_matricula, m.numero_matricula
            FROM estudantes e
            JOIN usuarios u ON u.id = e.usuario_id
            JOIN matriculas m ON m.estudante_id = e.id
            JOIN turmas t ON t.id = m.turma_id
            WHERE e.escola_id = :escola_id AND m.status = 'ativa'
        ";
        if ($turma_id) {
            $sql .= " AND m.turma_id = :turma_id";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':escola_id' => $escola_id, ':turma_id' => $turma_id]);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->execute([':escola_id' => $escola_id]);
        }
        $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $titulo = "Relatório de Alunos" . ($turma_id ? " por Turma" : "");
    }
    
    // Gerar arquivo
    if ($formato == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $titulo . '_' . date('Y-m-d') . '.xls"');
        
        echo '<html><head><meta charset="UTF-8"><title>' . $titulo . '</title></head><body>';
        echo '<h2>' . htmlspecialchars($titulo) . '</h2>';
        echo '<p>Data de emissão: ' . date('d/m/Y H:i:s') . '</p>';
        echo '<table border="1" cellpadding="5">';
        
        if (!empty($dados)) {
            // Cabeçalhos
            echo '<tr>';
            foreach (array_keys($dados[0]) as $coluna) {
                echo '<th>' . ucfirst(str_replace('_', ' ', $coluna)) . '</th>';
            }
            echo '</tr>';
            
            // Dados
            foreach ($dados as $row) {
                echo '<tr>';
                foreach ($row as $valor) {
                    echo '<td>' . htmlspecialchars($valor ?? '-') . '</td>';
                }
                echo '</tr>';
            }
        } else {
            echo '<tr><td>Nenhum dado encontrado</td></tr>';
        }
        
        echo '</table>';
        echo '<p><small>Documento gerado por SIGE Angola</small></p>';
        echo '</body></html>';
        exit;
    } elseif ($formato == 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $titulo . '_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Documento gerado por: SIGE Angola']);
        fputcsv($output, ['Relatório: ' . $titulo]);
        fputcsv($output, ['Data: ' . date('d/m/Y H:i:s')]);
        fputcsv($output, []);
        
        if (!empty($dados)) {
            fputcsv($output, array_keys($dados[0]));
            foreach ($dados as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
        exit;
    }
}

// ============================================
// BUSCAR DADOS PARA FILTROS
// ============================================

// Anos letivos disponíveis
$anos_letivos = $conn->prepare("SELECT DISTINCT ano_letivo FROM turmas WHERE escola_id = :escola_id UNION SELECT DISTINCT ano_letivo FROM matriculas WHERE escola_id = :escola_id ORDER BY ano_letivo DESC");
$anos_letivos->execute([':escola_id' => $escola_id]);
$anos_letivos = $anos_letivos->fetchAll(PDO::FETCH_COLUMN);

// Períodos disponíveis
$periodos = ['1º Bimestre', '2º Bimestre', '3º Bimestre', '4º Bimestre', 'Anual'];

// Disciplinas
$disciplinas = $conn->prepare("SELECT id, nome FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Turmas
$turmas = $conn->prepare("SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas gerais para dashboard
$stats = [];

// Total de alunos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_alunos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM professores WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$stats['total_professores'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Taxa de aprovação geral
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN status = 'aprovado' THEN 1 END) as aprovados,
        COUNT(*) as total
    FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$aprov = $stmt->fetch(PDO::FETCH_ASSOC);
$stats['taxa_aprovacao'] = $aprov['total'] > 0 ? round(($aprov['aprovados'] / $aprov['total']) * 100, 1) : 0;

// Média geral
$stmt = $conn->prepare("
    SELECT AVG(media_final) as media FROM notas n
    JOIN matriculas m ON m.id = n.matricula_id
    JOIN estudantes e ON e.id = m.estudante_id
    WHERE e.escola_id = :escola_id AND n.media_final IS NOT NULL
");
$stmt->execute([':escola_id' => $escola_id]);
$stats['media_geral'] = round($stmt->fetch(PDO::FETCH_ASSOC)['media'] ?? 0, 1);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Pedagógicos | Coordenação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.85em; }
        
        .relatorio-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            display: block;
            height: 100%;
            border: 1px solid #eee;
        }
        .relatorio-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); color: #006B3E; }
        .relatorio-icon { font-size: 3em; margin-bottom: 15px; }
        .relatorio-title { font-size: 1.1em; font-weight: bold; margin-bottom: 10px; }
        .relatorio-desc { font-size: 0.8em; color: #666; }
        
        .preview-container {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .btn-export-group { display: flex; gap: 10px; justify-content: center; margin-top: 15px; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chart-line"></i> Relatórios Pedagógicos</h2>
        </div>
        
        <!-- Estatísticas Rápidas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total_alunos']; ?></div><div class="stat-label">Total de Alunos</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['total_professores']; ?></div><div class="stat-label">Total de Professores</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['taxa_aprovacao']; ?>%</div><div class="stat-label">Taxa de Aprovação</div></div>
            <div class="stat-card"><div class="stat-value"><?php echo $stats['media_geral']; ?></div><div class="stat-label">Média Geral</div></div>
        </div>
        
        <!-- Tipos de Relatórios -->
        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="relatorio-card" onclick="mostrarFormulario('aprovacao')">
                    <div class="relatorio-icon"><i class="fas fa-chart-line text-success"></i></div>
                    <div class="relatorio-title">Relatório de Aprovação</div>
                    <div class="relatorio-desc">Taxas de aprovação, reprovação e recuperação por turma</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="relatorio-card" onclick="mostrarFormulario('frequencia')">
                    <div class="relatorio-icon"><i class="fas fa-calendar-check text-primary"></i></div>
                    <div class="relatorio-title">Relatório de Frequência</div>
                    <div class="relatorio-desc">Presenças e faltas por aluno e turma</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="relatorio-card" onclick="mostrarFormulario('desempenho')">
                    <div class="relatorio-icon"><i class="fas fa-graduation-cap text-info"></i></div>
                    <div class="relatorio-title">Relatório de Desempenho</div>
                    <div class="relatorio-desc">Notas e desempenho por disciplina</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="relatorio-card" onclick="mostrarFormulario('turmas')">
                    <div class="relatorio-icon"><i class="fas fa-users-group text-warning"></i></div>
                    <div class="relatorio-title">Relatório de Turmas</div>
                    <div class="relatorio-desc">Informações gerais das turmas</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="relatorio-card" onclick="mostrarFormulario('alunos')">
                    <div class="relatorio-icon"><i class="fas fa-users text-danger"></i></div>
                    <div class="relatorio-title">Relatório de Alunos</div>
                    <div class="relatorio-desc">Dados cadastrais dos alunos</div>
                </div>
            </div>
            <div class="col-md-4 mb-4">
                <div class="relatorio-card" onclick="mostrarFormulario('personalizado')">
                    <div class="relatorio-icon"><i class="fas fa-sliders-h text-secondary"></i></div>
                    <div class="relatorio-title">Relatório Personalizado</div>
                    <div class="relatorio-desc">Combine múltiplos filtros</div>
                </div>
            </div>
        </div>
        
        <!-- Formulário Dinâmico -->
        <div class="card" id="formularioContainer" style="display: none;">
            <div class="card-header" id="formularioTitulo"></div>
            <div class="card-body" id="formularioConteudo"></div>
        </div>
        
        <!-- Preview do Relatório -->
        <div class="card" id="previewContainer" style="display: none;">
            <div class="card-header"><i class="fas fa-eye"></i> Visualização do Relatório</div>
            <div class="card-body" id="previewConteudo">
                <div class="text-center text-muted p-3" id="previewLoading">
                    <i class="fas fa-spinner fa-spin"></i> Carregando...
                </div>
                <div id="previewDados" style="display: none;"></div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        function mostrarFormulario(tipo) {
            $('#formularioContainer').show();
            $('#previewContainer').hide();
            
            let html = '';
            let titulo = '';
            
            if (tipo == 'aprovacao') {
                titulo = '<i class="fas fa-chart-line"></i> Relatório de Aprovação';
                html = `
                    <form id="formRelatorio" onsubmit="gerarPreview(event, 'aprovacao')">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Ano Letivo</label>
                                <select name="ano_letivo" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($anos_letivos as $ano): ?>
                                    <option value="<?php echo $ano; ?>"><?php echo $ano; ?></option>
                                    <?php endforeach; ?>
                                    <option value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" selected>Ano Atual</option>
                                </select>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Visualizar</button>
                            <button type="button" class="btn btn-success" onclick="exportarRelatorio('aprovacao', 'excel')"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                            <button type="button" class="btn btn-info" onclick="exportarRelatorio('aprovacao', 'csv')"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                        </div>
                    </form>
                `;
            } else if (tipo == 'frequencia') {
                titulo = '<i class="fas fa-calendar-check"></i> Relatório de Frequência';
                html = `
                    <form id="formRelatorio" onsubmit="gerarPreview(event, 'frequencia')">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Período</label>
                                <select name="periodo" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($periodos as $p): ?>
                                    <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Visualizar</button>
                            <button type="button" class="btn btn-success" onclick="exportarRelatorio('frequencia', 'excel')"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                            <button type="button" class="btn btn-info" onclick="exportarRelatorio('frequencia', 'csv')"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                        </div>
                    </form>
                `;
            } else if (tipo == 'desempenho') {
                titulo = '<i class="fas fa-graduation-cap"></i> Relatório de Desempenho';
                html = `
                    <form id="formRelatorio" onsubmit="gerarPreview(event, 'desempenho')">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Disciplina</label>
                                <select name="disciplina_id" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Visualizar</button>
                            <button type="button" class="btn btn-success" onclick="exportarRelatorio('desempenho', 'excel')"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                            <button type="button" class="btn btn-info" onclick="exportarRelatorio('desempenho', 'csv')"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                        </div>
                    </form>
                `;
            } else if (tipo == 'turmas') {
                titulo = '<i class="fas fa-users-group"></i> Relatório de Turmas';
                html = `
                    <form id="formRelatorio" onsubmit="gerarPreview(event, 'turmas')">
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Visualizar</button>
                            <button type="button" class="btn btn-success" onclick="exportarRelatorio('turmas', 'excel')"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                            <button type="button" class="btn btn-info" onclick="exportarRelatorio('turmas', 'csv')"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                        </div>
                    </form>
                `;
            } else if (tipo == 'alunos') {
                titulo = '<i class="fas fa-users"></i> Relatório de Alunos';
                html = `
                    <form id="formRelatorio" onsubmit="gerarPreview(event, 'alunos')">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Turma (opcional)</label>
                                <select name="turma_id" class="form-control">
                                    <option value="">Todas as turmas</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Visualizar</button>
                            <button type="button" class="btn btn-success" onclick="exportarRelatorio('alunos', 'excel')"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                            <button type="button" class="btn btn-info" onclick="exportarRelatorio('alunos', 'csv')"><i class="fas fa-file-csv"></i> Exportar CSV</button>
                        </div>
                    </form>
                `;
            } else if (tipo == 'personalizado') {
                titulo = '<i class="fas fa-sliders-h"></i> Relatório Personalizado';
                html = `
                    <form id="formRelatorio" onsubmit="gerarPreview(event, 'personalizado')">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Turma</label>
                                <select name="turma_id" class="form-control">
                                    <option value="">Todas</option>
                                    <?php foreach ($turmas as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['ano']; ?> - <?php echo htmlspecialchars($t['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Disciplina</label>
                                <select name="disciplina_id" class="form-control">
                                    <option value="">Todas</option>
                                    <?php foreach ($disciplinas as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label>Período</label>
                                <select name="periodo" class="form-control">
                                    <option value="">Todos</option>
                                    <?php foreach ($periodos as $p): ?>
                                    <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label>Ano Letivo</label>
                                <select name="ano_letivo" class="form-control">
                                    <option value="">Todos</option>
                                    <?php foreach ($anos_letivos as $ano): ?>
                                    <option value="<?php echo $ano; ?>"><?php echo $ano; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="text-center">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-eye"></i> Visualizar</button>
                        </div>
                    </form>
                `;
            }
            
            $('#formularioTitulo').html(titulo);
            $('#formularioConteudo').html(html);
            $('html, body').animate({ scrollTop: $('#formularioContainer').offset().top - 100 }, 500);
        }
        
        function gerarPreview(event, tipo) {
            event.preventDefault();
            
            $('#previewContainer').show();
            $('#previewDados').hide();
            $('#previewLoading').show();
            
            let dados = $('#formRelatorio').serialize();
            dados += '&preview=1&tipo_relatorio=' + tipo;
            
            $.ajax({
                url: 'relatorios_preview.php',
                method: 'GET',
                data: dados,
                success: function(response) {
                    $('#previewLoading').hide();
                    $('#previewDados').html(response).show();
                },
                error: function() {
                    $('#previewLoading').html('<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> Erro ao carregar visualização</div>');
                }
            });
        }
        
        function exportarRelatorio(tipo, formato) {
            let dados = $('#formRelatorio').serialize();
            window.location.href = 'relatorios.php?exportar=1&tipo_relatorio=' + tipo + '&formato=' + formato + '&' + dados;
        }
    </script>
</body>
</html>