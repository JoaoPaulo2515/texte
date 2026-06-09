<?php
// escola/rh/funcionarios/visualizar.php - Visualização detalhada do funcionário
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$id = $_GET['id'] ?? 0;

// Buscar dados do funcionário
$stmt = $conn->prepare("
    SELECT f.*, u.email as user_email, u.status as user_status
    FROM funcionarios f 
    LEFT JOIN usuarios u ON f.usuario_id = u.id 
    WHERE f.id = ? AND f.escola_id = ?
");
$stmt->execute([$id, $escola_id]);
$funcionario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    header('Location: listar.php');
    exit;
}

// Buscar documentos
$stmt = $conn->prepare("SELECT * FROM funcionarios_documentos WHERE funcionario_id = ? ORDER BY data_upload DESC");
$stmt->execute([$id]);
$documentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar avaliações
$stmt = $conn->prepare("
    SELECT a.*, ap.nome as periodo_nome, ap.data_inicio, ap.data_fim,
           (SELECT COUNT(*) FROM avaliacao_notas WHERE avaliacao_id = a.id) as total_criterios
    FROM avaliacoes a
    JOIN avaliacao_periodos ap ON a.periodo_id = ap.id
    WHERE a.funcionario_id = ?
    ORDER BY ap.data_inicio DESC
");
$stmt->execute([$id]);
$avaliacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar férias
$stmt = $conn->prepare("
    SELECT * FROM solicitacoes_ferias 
    WHERE funcionario_id = ? 
    ORDER BY data_solicitacao DESC LIMIT 5
");
$stmt->execute([$id]);
$ferias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar formações
$stmt = $conn->prepare("
    SELECT pf.*, pfi.status as inscricao_status, pfi.data_inscricao, pfi.nota_final
    FROM plano_formacao_inscricoes pfi
    JOIN planos_formacao pf ON pfi.plano_id = pf.id
    WHERE pfi.funcionario_id = ?
    ORDER BY pfi.data_inscricao DESC
");
$stmt->execute([$id]);
$formacoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Visualizar Funcionário | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
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
        .nav-submenu { list-style: none; padding-left: 40px; margin: 0; display: none; }
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
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .profile-photo { width: 150px; height: 150px; border-radius: 50%; object-fit: cover; border: 3px solid #006B3E; }
        .info-label { font-weight: bold; color: #006B3E; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .badge-status { padding: 5px 10px; border-radius: 20px; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
    </style>
</head>
<body>
 
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-tie"></i> Visualizar Funcionário</h2>
            <div>
                <a href="listar.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
                <a href="editar.php?id=<?php echo $id; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="gerar_declaracao.php?id=<?php echo $id; ?>" class="btn btn-info btn-sm"><i class="fas fa-file-pdf"></i> Declaração</a>
            </div>
        </div>
        
        <!-- Informações do Funcionário -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-id-card"></i> Dados Pessoais
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 text-center">
                        <img src="../../../uploads/funcionarios/fotos/<?php echo $funcionario['foto']; ?>" 
                             class="profile-photo mb-3" 
                             onerror="this.src='../../../assets/images/avatar-padrao.png'">
                        <h4><?php echo htmlspecialchars($funcionario['nome']); ?></h4>
                        <span class="badge bg-primary"><?php echo htmlspecialchars($funcionario['numero_processo']); ?></span>
                        <p class="mt-2">
                            <span class="badge badge-status bg-<?php echo $funcionario['status'] == 'ativo' ? 'success' : 'danger'; ?>">
                                <?php echo ucfirst($funcionario['status']); ?>
                            </span>
                        </p>
                    </div>
                    <div class="col-md-9">
                        <div class="row">
                            <div class="col-md-6">
                                <p><span class="info-label"><i class="fas fa-id-card"></i> BI:</span> <?php echo htmlspecialchars($funcionario['bi']); ?></p>
                                <p><span class="info-label"><i class="fas fa-calendar-alt"></i> Data Nascimento:</span> <?php echo date('d/m/Y', strtotime($funcionario['data_nascimento'])); ?></p>
                                <p><span class="info-label"><i class="fas fa-venus-mars"></i> Género:</span> <?php echo $funcionario['genero'] == 'M' ? 'Masculino' : 'Feminino'; ?></p>
                                <p><span class="info-label"><i class="fas fa-heart"></i> Estado Civil:</span> <?php echo htmlspecialchars($funcionario['estado_civil']); ?></p>
                                <p><span class="info-label"><i class="fas fa-map-marker-alt"></i> Naturalidade:</span> <?php echo htmlspecialchars($funcionario['naturalidade']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><span class="info-label"><i class="fas fa-phone"></i> Telefone:</span> <?php echo htmlspecialchars($funcionario['telefone']); ?></p>
                                <p><span class="info-label"><i class="fas fa-envelope"></i> E-mail:</span> <?php echo htmlspecialchars($funcionario['email']); ?></p>
                                <p><span class="info-label"><i class="fas fa-map-pin"></i> Província:</span> <?php echo htmlspecialchars($funcionario['provincia']); ?></p>
                                <p><span class="info-label"><i class="fas fa-city"></i> Município:</span> <?php echo htmlspecialchars($funcionario['municipio']); ?></p>
                                <p><span class="info-label"><i class="fas fa-location-dot"></i> Comuna:</span> <?php echo htmlspecialchars($funcionario['comuna']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Dados Profissionais -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-briefcase"></i> Dados Profissionais
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <p><span class="info-label"><i class="fas fa-tag"></i> Tipo:</span> <?php echo ucfirst($funcionario['tipo_funcionario']); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p><span class="info-label"><i class="fas fa-user-graduate"></i> Cargo:</span> <?php echo htmlspecialchars($funcionario['cargo']); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p><span class="info-label"><i class="fas fa-calendar-check"></i> Admissão:</span> <?php echo date('d/m/Y', strtotime($funcionario['data_admissao'])); ?></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4">
                        <p><span class="info-label"><i class="fas fa-file-contract"></i> Tipo Contrato:</span> <?php echo htmlspecialchars($funcionario['tipo_contrato']); ?></p>
                    </div>
                    <div class="col-md-4">
                        <p><span class="info-label"><i class="fas fa-calendar-times"></i> Fim Contrato:</span> <?php echo $funcionario['data_fim_contrato'] ? date('d/m/Y', strtotime($funcionario['data_fim_contrato'])) : 'N/A'; ?></p>
                    </div>
                    <div class="col-md-4">
                        <p><span class="info-label"><i class="fas fa-graduation-cap"></i> Habilitação:</span> <?php echo htmlspecialchars($funcionario['habilitacao']); ?></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-12">
                        <p><span class="info-label"><i class="fas fa-university"></i> Formação:</span> <?php echo nl2br(htmlspecialchars($funcionario['formacao'])); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Documentos -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-folder-open"></i> Documentos
            </div>
            <div class="card-body">
                <?php if (count($documentos) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Tipo</th>
                                    <th>Arquivo</th>
                                    <th>Formato</th>
                                    <th>Data Upload</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documentos as $doc): ?>
                                <tr>
                                    <td><?php echo ucfirst($doc['tipo_documento']); ?></td>
                                    <td><?php echo $doc['nome_arquivo']; ?></td>
                                    <td><span class="badge bg-info"><?php echo $doc['formato_papel']; ?></span></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($doc['data_upload'])); ?></td>
                                    <td>
                                        <a href="../../../<?php echo $doc['caminho_arquivo']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Nenhum documento anexado.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Avaliações -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-star"></i> Avaliações de Desempenho
            </div>
            <div class="card-body">
                <?php if (count($avaliacoes) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Período</th>
                                    <th>Data</th>
                                    <th>Pontuação</th>
                                    <th>Classificação</th>
                                    <th>Critérios</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($avaliacoes as $ava): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ava['periodo_nome']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($ava['data_avaliacao'])); ?></td>
                                    <td><?php echo $ava['pontuacao_total']; ?> pts</td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $ava['classificacao'] == 'Excelente' ? 'success' : 
                                                ($ava['classificacao'] == 'Bom' ? 'primary' : 
                                                ($ava['classificacao'] == 'Regular' ? 'warning' : 'danger')); 
                                        ?>">
                                            <?php echo $ava['classificacao']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $ava['total_criterios']; ?> critérios</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Nenhuma avaliação registada.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Formações -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-graduation-cap"></i> Formações Participadas
            </div>
            <div class="card-body">
                <?php if (count($formacoes) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Formação</th>
                                    <th>Período</th>
                                    <th>Carga Horária</th>
                                    <th>Status</th>
                                    <th>Nota</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($formacoes as $form): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($form['titulo']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($form['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($form['data_fim'])); ?></td>
                                    <td><?php echo $form['carga_horaria']; ?>h</td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $form['inscricao_status'] == 'concluido' ? 'success' : 
                                                ($form['inscricao_status'] == 'confirmado' ? 'primary' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($form['inscricao_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $form['nota_final'] ?: 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Nenhuma formação registada.</p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Férias -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-umbrella-beach"></i> Histórico de Férias
            </div>
            <div class="card-body">
                <?php if (count($ferias) > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Período</th>
                                    <th>Dias</th>
                                    <th>Status</th>
                                    <th>Data Solicitação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ferias as $f): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y', strtotime($f['data_inicio'])); ?> - <?php echo date('d/m/Y', strtotime($f['data_fim'])); ?></td>
                                    <td><?php echo $f['dias_solicitados']; ?> dias</td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $f['status'] == 'aprovado' ? 'success' : 
                                                ($f['status'] == 'pendente' ? 'warning' : 'danger'); 
                                        ?>">
                                            <?php echo ucfirst($f['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($f['data_solicitacao'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">Nenhum registo de férias.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        
        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('open');
        });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
    </script>
</body>
</html>