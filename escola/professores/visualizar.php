<?php
// escola/professores/visualizar.php - Visualizar detalhes do professor
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar dados do professor
$stmt = $conn->prepare("
    SELECT p.*, u.nome, u.email as usuario_email, u.telefone as usuario_telefone, u.status as usuario_status
    FROM professores p
    JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :id AND p.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$professor = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$professor) {
    header('Location: index.php?error=Professor não encontrado');
    exit;
}

// Buscar disciplinas do professor
$stmt = $conn->prepare("
    SELECT d.id, d.nome, d.carga_horaria,
           COUNT(DISTINCT a.turma_id) as total_turmas
    FROM disciplinas d
    JOIN alocacoes a ON a.disciplina_id = d.id
    WHERE a.professor_id = :professor_id
    GROUP BY d.id
");
$stmt->execute([':professor_id' => $id]);
$disciplinas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas do professor
$stmt = $conn->prepare("
    SELECT DISTINCT t.id, t.nome, t.turno, t.ano,
           COUNT(DISTINCT m.id) as total_alunos
    FROM turmas t
    JOIN alocacoes a ON a.turma_id = t.id
    LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
    WHERE a.professor_id = :professor_id
    GROUP BY t.id
");
$stmt->execute([':professor_id' => $id]);
$turmas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar documentos
$outros_docs = json_decode($professor['outros_documentos'], true) ?: [];

// Províncias para exibição
$provincias_lista = [
    'Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 
    'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 
    'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 
    'Namibe', 'Uíge', 'Zaire'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($professor['nome']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .profile-img { width: 150px; height: 150px; object-fit: cover; border-radius: 50%; border: 3px solid #006B3E; }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 180px; font-weight: 600; color: #555; }
        .info-value { flex: 1; color: #333; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .document-link { margin: 5px; display: inline-block; }
        .turma-card { text-align: center; padding: 10px; margin: 5px; border-radius: 8px; background: #f8f9fa; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-chalkboard-user"></i> Perfil do Professor</h2>
            <div>
                <a href="editar.php?id=<?php echo $professor['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="excluir.php?id=<?php echo $professor['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir este professor?')"><i class="fas fa-trash"></i> Excluir</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center p-4">
                    <?php 
                    $foto_path = '../../uploads/professores/fotos/' . $professor['foto'];
                    if ($professor['foto'] && file_exists($foto_path)) {
                        echo '<img src="' . $foto_path . '" class="profile-img mx-auto mb-3">';
                    } else {
                        echo '<img src="../../assets/images/avatar-prof-padrao.png" class="profile-img mx-auto mb-3">';
                    }
                    ?>
                    <h4><?php echo htmlspecialchars($professor['nome']); ?></h4>
                    <p class="text-muted"><?php echo htmlspecialchars($professor['especialidade'] ?? 'Professor'); ?></p>
                    <p><span class="badge bg-<?php echo $professor['usuario_status'] == 'ativo' ? 'success' : 'danger'; ?>"><?php echo ucfirst($professor['usuario_status']); ?></span></p>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-simple"></i> Resumo Profissional</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Data Admissão:</div><div class="info-value"><?php echo date('d/m/Y', strtotime($professor['data_admissao'])); ?></div></div>
                        <div class="info-row"><div class="info-label">Carga Horária:</div><div class="info-value"><?php echo $professor['carga_horaria'] ?? 0; ?> h/semana</div></div>
                        <div class="info-row"><div class="info-label">Disciplinas:</div><div class="info-value"><?php echo count($disciplinas); ?></div></div>
                        <div class="info-row"><div class="info-label">Turmas:</div><div class="info-value"><?php echo count($turmas); ?></div></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-user"></i> Dados Pessoais</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome Completo:</div><div class="info-value"><?php echo htmlspecialchars($professor['nome']); ?></div></div>
                        <div class="info-row"><div class="info-label">Data de Nascimento:</div><div class="info-value"><?php echo $professor['data_nascimento'] ? date('d/m/Y', strtotime($professor['data_nascimento'])) : '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Género:</div><div class="info-value"><?php echo $professor['genero'] == 'M' ? 'Masculino' : ($professor['genero'] == 'F' ? 'Feminino' : '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">BI/Nº Identificação:</div><div class="info-value"><?php echo $professor['bi'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Data Emissão BI:</div><div class="info-value"><?php echo $professor['bi_data_emissao'] ? date('d/m/Y', strtotime($professor['bi_data_emissao'])) : '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Local Emissão BI:</div><div class="info-value"><?php echo $professor['bi_local_emissao'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">NUIT:</div><div class="info-value"><?php echo $professor['nuit'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Nacionalidade:</div><div class="info-value"><?php echo $professor['nacionalidade'] ?? 'Angolana'; ?></div></div>
                        <div class="info-row"><div class="info-label">Naturalidade:</div><div class="info-value"><?php echo $professor['naturalidade'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Província:</div><div class="info-value"><?php echo $professor['provincia'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Município:</div><div class="info-value"><?php echo $professor['municipio'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Comuna:</div><div class="info-value"><?php echo $professor['comuna'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo nl2br(htmlspecialchars($professor['endereco'] ?? '-')); ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo $professor['usuario_telefone'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo $professor['usuario_email'] ?? '-'; ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-graduation-cap"></i> Formação Académica</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Especialidade:</div><div class="info-value"><?php echo htmlspecialchars($professor['especialidade'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Formação:</div><div class="info-value"><?php echo nl2br(htmlspecialchars($professor['formacao'] ?? '-')); ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-book"></i> Disciplinas Ministradas</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr><th>Disciplina</th><th>Carga Horária</th><th>Turmas</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($disciplinas as $disc): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($disc['nome']); ?></strong></td>
                                        <td><?php echo $disc['carga_horaria'] ?? '-'; ?> h/semana</small></td>
                                        <td><?php echo $disc['total_turmas']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($disciplinas)): ?>
                                    <tr><td colspan="3" class="text-center">Nenhuma disciplina atribuída</td></td>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-users-group"></i> Turmas</div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($turmas as $turma): ?>
                            <div class="col-md-4 mb-2">
                                <div class="turma-card">
                                    <i class="fas fa-users-group fa-2x text-primary mb-2"></i>
                                    <h6><?php echo htmlspecialchars($turma['nome']); ?></h6>
                                    <small><?php echo ucfirst($turma['turno']); ?> | <?php echo $turma['ano']; ?></small><br>
                                    <small><?php echo $turma['total_alunos']; ?> alunos</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php if (empty($turmas)): ?>
                            <div class="col-12 text-center">Nenhuma turma atribuída</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-file-alt"></i> Documentos</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>BI:</strong><br>
                                <?php if ($professor['bi_documento']): ?>
                                    <a href="../../uploads/professores/documentos/<?php echo $professor['bi_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Diploma:</strong><br>
                                <?php if ($professor['diploma_documento']): ?>
                                    <a href="../../uploads/professores/documentos/<?php echo $professor['diploma_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mt-2">
                                <strong>Certificações:</strong><br>
                                <?php if ($professor['certificacoes_documento']): ?>
                                    <a href="../../uploads/professores/documentos/<?php echo $professor['certificacoes_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mt-2">
                                <strong>Declaração:</strong><br>
                                <?php if ($professor['declaracao_documento']): ?>
                                    <a href="../../uploads/professores/documentos/<?php echo $professor['declaracao_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>$('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });</script>
</body>
</html>