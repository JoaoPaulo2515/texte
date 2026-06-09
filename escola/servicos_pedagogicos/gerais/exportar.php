<?php
// escola/servicos_pedagogicos/gerais/exportar.php - Exportação de Dados
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar qual tipo de exportação
$tipo = $_GET['tipo'] ?? '';
$formato = $_GET['formato'] ?? 'excel';

// Buscar dados da escola
$stmt = $conn->prepare("SELECT * FROM escolas WHERE id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$escola = $stmt->fetch(PDO::FETCH_ASSOC);

// Função para exportar Excel
function exportarExcel($data, $filename, $headers, $escola) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.xls"');
    
    echo '<html><head><meta charset="UTF-8"><title>' . $filename . '</title></head><body>';
    echo '<h2>' . htmlspecialchars($escola['nome']) . '</h2>';
    echo '<h3>' . $filename . '</h3>';
    echo '<p>Data de emissão: ' . date('d/m/Y H:i:s') . '</p>';
    echo '<table border="1" cellpadding="5">';
    
    // Cabeçalhos
    echo '<tr>';
    foreach ($headers as $header) {
        echo '<th>' . htmlspecialchars($header) . '</th>';
    }
    echo '</tr>';
    
    // Dados
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($headers as $header) {
            $value = $row[strtolower(str_replace(' ', '_', $header))] ?? '';
            echo '<td>' . htmlspecialchars($value) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</table>';
    echo '<p><small>Documento gerado por SIGE Angola - Sistema Integrado de Gestão Escolar</small></p>';
    echo '</body></html>';
    exit;
}

// Função para exportar CSV
function exportarCSV($data, $filename, $headers, $escola) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Documento gerado por: ' . $escola['nome']]);
    fputcsv($output, ['Arquivo: ' . $filename]);
    fputcsv($output, ['Data: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    fputcsv($output, $headers);
    
    foreach ($data as $row) {
        $line = [];
        foreach ($headers as $header) {
            $line[] = $row[strtolower(str_replace(' ', '_', $header))] ?? '';
        }
        fputcsv($output, $line);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Documento gerado por SIGE Angola']);
    fclose($output);
    exit;
}

// Buscar dados conforme o tipo
if ($tipo == 'classes') {
    $stmt = $conn->prepare("SELECT id, nome, descricao, ordem, status, created_at FROM classes WHERE escola_id = :escola_id ORDER BY ordem");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Nome', 'Descrição', 'Ordem', 'Status', 'Data Criação'];
    
} elseif ($tipo == 'periodos') {
    $stmt = $conn->prepare("SELECT id, nome, descricao, data_inicio, data_fim, ano_letivo, status FROM periodos WHERE escola_id = :escola_id ORDER BY data_inicio DESC");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Nome', 'Descrição', 'Data Início', 'Data Fim', 'Ano Letivo', 'Status'];
    
} elseif ($tipo == 'salas') {
    $stmt = $conn->prepare("SELECT id, nome, capacidade, tipo, localizacao, recursos, status FROM salas WHERE escola_id = :escola_id ORDER BY nome");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Nome', 'Capacidade', 'Tipo', 'Localização', 'Recursos', 'Status'];
    
} elseif ($tipo == 'turmas') {
    $stmt = $conn->prepare("
        SELECT t.id, t.nome, t.ano, t.turno, t.sala, t.capacidade, t.ano_letivo, t.status,
               (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
        FROM turmas t WHERE t.escola_id = :escola_id ORDER BY t.ano, t.nome
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Nome', 'Classe/Ano', 'Turno', 'Sala', 'Capacidade', 'Ano Letivo', 'Total Alunos', 'Status'];
    
} elseif ($tipo == 'cursos') {
    $stmt = $conn->prepare("SELECT id, nome, codigo, descricao, duracao, carga_horaria, status FROM cursos WHERE escola_id = :escola_id ORDER BY nome");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Nome', 'Código', 'Descrição', 'Duração', 'Carga Horária', 'Status'];
    
} elseif ($tipo == 'disciplinas') {
    $stmt = $conn->prepare("SELECT id, nome, codigo, descricao, carga_horaria, creditos, status FROM disciplinas WHERE escola_id = :escola_id ORDER BY nome");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Nome', 'Código', 'Descrição', 'Carga Horária', 'Créditos', 'Status'];
    
} elseif ($tipo == 'associacoes') {
    $stmt = $conn->prepare("
        SELECT ac.id, c.nome as classe, cs.nome as curso, cs.codigo, ac.status 
        FROM classe_curso ac
        JOIN classes c ON c.id = ac.classe_id
        JOIN cursos cs ON cs.id = ac.curso_id
        WHERE ac.escola_id = :escola_id ORDER BY c.nome, cs.nome
    ");
    $stmt->execute([':escola_id' => $escola_id]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $headers = ['ID', 'Classe', 'Curso', 'Código', 'Status'];
    
} elseif ($tipo == 'tudo') {
    // Exportar todos os dados em arquivos separados (ZIP)
    $zip = new ZipArchive();
    $filename = tempnam(sys_get_temp_dir(), 'export_');
    if ($zip->open($filename, ZipArchive::CREATE) !== true) {
        exit("Não foi possível criar o arquivo ZIP");
    }
    
    $tipos = ['classes', 'periodos', 'salas', 'turmas', 'cursos', 'disciplinas', 'associacoes'];
    foreach ($tipos as $t) {
        ob_start();
        if ($t == 'classes') {
            $stmt = $conn->prepare("SELECT id, nome, descricao, ordem, status FROM classes WHERE escola_id = :escola_id");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['ID', 'Nome', 'Descrição', 'Ordem', 'Status'];
        } elseif ($t == 'periodos') {
            $stmt = $conn->prepare("SELECT id, nome, descricao, data_inicio, data_fim, ano_letivo, status FROM periodos WHERE escola_id = :escola_id");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['ID', 'Nome', 'Descrição', 'Data Início', 'Data Fim', 'Ano Letivo', 'Status'];
        } elseif ($t == 'salas') {
            $stmt = $conn->prepare("SELECT id, nome, capacidade, tipo, localizacao, status FROM salas WHERE escola_id = :escola_id");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['ID', 'Nome', 'Capacidade', 'Tipo', 'Localização', 'Status'];
        } elseif ($t == 'turmas') {
            $stmt = $conn->prepare("SELECT id, nome, ano, turno, sala, capacidade, ano_letivo, status FROM turmas WHERE escola_id = :escola_id");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['ID', 'Nome', 'Classe/Ano', 'Turno', 'Sala', 'Capacidade', 'Ano Letivo', 'Status'];
        } elseif ($t == 'cursos') {
            $stmt = $conn->prepare("SELECT id, nome, codigo, descricao, duracao, carga_horaria, status FROM cursos WHERE escola_id = :escola_id");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['ID', 'Nome', 'Código', 'Descrição', 'Duração', 'Carga Horária', 'Status'];
        } elseif ($t == 'disciplinas') {
            $stmt = $conn->prepare("SELECT id, nome, codigo, descricao, carga_horaria, creditos, status FROM disciplinas WHERE escola_id = :escola_id");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['ID', 'Nome', 'Código', 'Descrição', 'Carga Horária', 'Créditos', 'Status'];
        } else {
            $stmt = $conn->prepare("
                SELECT c.nome as classe, cs.nome as curso, cs.codigo 
                FROM classe_curso ac
                JOIN classes c ON c.id = ac.classe_id
                JOIN cursos cs ON cs.id = ac.curso_id
                WHERE ac.escola_id = :escola_id
            ");
            $stmt->execute([':escola_id' => $escola_id]);
            $d = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $h = ['Classe', 'Curso', 'Código'];
        }
        
        // Criar CSV
        $output = fopen('php://temp', 'w');
        fputcsv($output, ['Documento gerado por: ' . $escola['nome']]);
        fputcsv($output, ['Arquivo: ' . ucfirst($t)]);
        fputcsv($output, ['Data: ' . date('d/m/Y H:i:s')]);
        fputcsv($output, []);
        fputcsv($output, $h);
        foreach ($d as $row) {
            $line = [];
            foreach ($h as $header) {
                $key = strtolower(str_replace(' ', '_', $header));
                $line[] = $row[$key] ?? '';
            }
            fputcsv($output, $line);
        }
        rewind($output);
        $csv_content = stream_get_contents($output);
        fclose($output);
        
        $zip->addFromString($t . '.csv', $csv_content);
    }
    
    $zip->close();
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="exportacao_completa_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($filename));
    readfile($filename);
    unlink($filename);
    exit;
}

// Exportar conforme o formato
if ($formato == 'excel') {
    exportarExcel($data, ucfirst($tipo), $headers, $escola);
} else {
    exportarCSV($data, ucfirst($tipo), $headers, $escola);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Dados | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto; }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .btn-export { min-width: 120px; margin: 5px; }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar"><h2><i class="fas fa-download"></i> Exportar Dados</h2></div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-file-excel"></i> Exportar por Módulo</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-layer-group fa-2x text-primary"></i><h5>Classes</h5><a href="?tipo=classes&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=classes&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-calendar-alt fa-2x text-success"></i><h5>Períodos</h5><a href="?tipo=periodos&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=periodos&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-door-open fa-2x text-warning"></i><h5>Salas</h5><a href="?tipo=salas&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=salas&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-users-group fa-2x text-info"></i><h5>Turmas</h5><a href="?tipo=turmas&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=turmas&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-graduation-cap fa-2x text-danger"></i><h5>Cursos</h5><a href="?tipo=cursos&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=cursos&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-book fa-2x text-secondary"></i><h5>Disciplinas</h5><a href="?tipo=disciplinas&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=disciplinas&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                    <div class="col-md-4"><div class="card mb-2"><div class="card-body text-center"><i class="fas fa-link fa-2x text-primary"></i><h5>Associações</h5><a href="?tipo=associacoes&formato=excel" class="btn btn-sm btn-success btn-export"><i class="fas fa-file-excel"></i> Excel</a><a href="?tipo=associacoes&formato=csv" class="btn btn-sm btn-info btn-export"><i class="fas fa-file-csv"></i> CSV</a></div></div></div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header bg-success text-white"><i class="fas fa-file-archive"></i> Exportação Completa</div>
            <div class="card-body text-center">
                <p>Exporta todos os dados (Classes, Períodos, Salas, Turmas, Cursos, Disciplinas e Associações) em um único arquivo ZIP.</p>
                <a href="?tipo=tudo" class="btn btn-success btn-lg"><i class="fas fa-download"></i> Exportar Tudo (ZIP)</a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(event) { event.preventDefault(); const parentLi = $(event.currentTarget).closest('.has-submenu'); const submenu = parentLi.find('.nav-submenu'); $('.has-submenu').not(parentLi).removeClass('open'); $('.nav-submenu').not(submenu).removeClass('show'); parentLi.toggleClass('open'); submenu.toggleClass('show'); }
    </script>
</body>
</html>