<?php
// escola/secretaria/ver_aluno.php - Visualizar Detalhes do Aluno

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar se o ID do aluno foi passado
$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aluno_id <= 0) {
    header('Location: lista_alunos.php');
    exit;
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.bi_data_emissao,
        e.bi_local_emissao,
        e.data_nascimento,
        e.genero,
        e.email,
        e.telefone,
        e.endereco,
        e.foto,
        e.status as aluno_status,
        e.created_at,
        e.pais_id,
        e.pais_nome,
        e.cidade_id,
        e.cidade_nome,
        e.provincia_id,
        e.provincia_nome,
        e.municipio_id,
        e.municipio_nome,
        e.comuna_id,
        e.comuna_nome,
        e.pai_nome,
        e.pai_bi,
        e.pai_telefone,
        e.pai_profissao,
        e.mae_nome,
        e.mae_bi,
        e.mae_telefone,
        e.mae_profissao,
        e.encarregado_nome,
        e.encarregado_parentesco,
        e.encarregado_bi,
        e.encarregado_telefone,
        e.encarregado_email,
        e.encarregado_endereco,
        e.ano_letivo,
        e.ano_escolar,
        e.curso,
        e.nivel,
        e.classe,
        e.bi_documento,
        e.certificado_documento,
        e.atestado_documento,
        e.declaracao_documento,
        e.outros_documentos
    FROM estudantes e
    WHERE e.id = :aluno_id AND e.escola_id = :escola_id
";

$stmt = $conn->prepare($sql);
$stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: lista_alunos.php?erro=Aluno não encontrado');
    exit;
}

// ============================================
// BUSCAR MATRÍCULAS DO ALUNO
// ============================================
$sql_matriculas = "
    SELECT 
        m.id,
        m.turma_id,
        m.turno,
        m.sala,
        m.classe,
        m.curso,
        m.nivel,
        m.ano_letivo,
        m.numero_processo,
        m.status as matricula_status,
        m.data_matricula,
        m.created_at,
        t.nome as turma_nome,
        t.ano as turma_ano
    FROM matriculas m
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :aluno_id
    ORDER BY m.ano_letivo DESC, m.created_at DESC
";

$stmt_mat = $conn->prepare($sql_matriculas);
$stmt_mat->execute([':aluno_id' => $aluno_id]);
$matriculas = $stmt_mat->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusBadge($status) {
    switch ($status) {
        case 'ativo':
            return '<span class="badge bg-success">Ativo</span>';
        case 'inativo':
            return '<span class="badge bg-danger">Inativo</span>';
        case 'transferido':
            return '<span class="badge bg-warning">Transferido</span>';
        case 'concluido':
            return '<span class="badge bg-info">Concluído</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status ?? '') . '</span>';
    }
}

function getMatriculaStatusBadge($status) {
    switch ($status) {
        case 'ativa':
            return '<span class="badge bg-success">Ativa</span>';
        case 'cancelada':
            return '<span class="badge bg-danger">Cancelada</span>';
        case 'trancada':
            return '<span class="badge bg-warning">Trancada</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status ?? '') . '</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function exibirDocumento($arquivo, $titulo) {
    if (empty($arquivo)) return '<span class="text-muted">Não anexado</span>';
    
    $ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
    $caminho = '../../uploads/alunos/documentos/' . $arquivo;
    
    if ($ext == 'pdf') {
        return '<a href="' . $caminho . '" target="_blank" class="btn btn-sm btn-danger"><i class="fas fa-file-pdf"></i> Ver PDF</a>';
    } elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
        return '<a href="' . $caminho . '" target="_blank" class="btn btn-sm btn-primary"><i class="fas fa-image"></i> Ver Imagem</a>';
    } else {
        return '<a href="' . $caminho . '" download class="btn btn-sm btn-secondary"><i class="fas fa-download"></i> Baixar</a>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($aluno['nome'] ?? 'Aluno'); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
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
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .foto-aluno { width: 150px; height: 150px; object-fit: cover; border-radius: 50%; border: 3px solid #006B3E; }
        .info-label { font-weight: 600; color: #555; width: 180px; display: inline-block; }
        .info-row { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .section-title { font-size: 1.2rem; font-weight: 600; color: #006B3E; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #006B3E; }
        .table-documentos td { padding: 8px; vertical-align: middle; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-graduate"></i> Perfil do Aluno</h2>
            <div>
                <a href="editar_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Editar
                </a>
                <a href="emitir_certificado.php?id=<?php echo $aluno['id']; ?>" class="btn btn-success">
                    <i class="fas fa-certificate"></i> Certificado
                </a>
                <a href="lista_alunos.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <div class="row">
            <!-- Coluna da Foto -->
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                            <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-aluno mb-3">
                        <?php else: ?>
                            <img src="../../assets/images/avatar-padrao.png" class="foto-aluno mb-3">
                        <?php endif; ?>
                        <h4><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></h4>
                        <p class="text-muted">Matrícula: <?php echo htmlspecialchars($aluno['matricula'] ?? ''); ?></p>
                        <?php echo getStatusBadge($aluno['aluno_status'] ?? 'ativo'); ?>
                        <hr>
                        <div class="text-start">
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-calendar"></i> Data Cadastro:</span>
                                <span><?php echo formatarData($aluno['created_at'] ?? ''); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label"><i class="fas fa-id-card"></i> BI/Nº:</span>
                                <span><?php echo htmlspecialchars($aluno['bi'] ?? 'Não informado'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna dos Dados -->
            <div class="col-md-9">
                <!-- Dados Pessoais -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user"></i> Dados Pessoais</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-user"></i> Nome Completo:</span>
                                    <span><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-calendar-alt"></i> Data Nascimento:</span>
                                    <span><?php echo formatarData($aluno['data_nascimento'] ?? ''); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-venus-mars"></i> Género:</span>
                                    <span><?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-id-card"></i> BI/Nº:</span>
                                    <span><?php echo htmlspecialchars($aluno['bi'] ?? 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-calendar"></i> Data Emissão BI:</span>
                                    <span><?php echo formatarData($aluno['bi_data_emissao'] ?? ''); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-map-marker-alt"></i> Local Emissão:</span>
                                    <span><?php echo htmlspecialchars($aluno['bi_local_emissao'] ?? 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-globe"></i> País:</span>
                                    <span><?php echo htmlspecialchars($aluno['pais_nome'] ?? 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-city"></i> Cidade:</span>
                                    <span><?php echo htmlspecialchars($aluno['cidade_nome'] ?? 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-map-pin"></i> Província/Município:</span>
                                    <span><?php echo htmlspecialchars($aluno['provincia_nome'] ?? ''); ?> / <?php echo htmlspecialchars($aluno['municipio_nome'] ?? ''); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-home"></i> Endereço:</span>
                                    <span><?php echo htmlspecialchars($aluno['endereco'] ?? 'Não informado'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Contactos -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-address-card"></i> Contactos</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-envelope"></i> E-mail:</span>
                                    <span><?php echo htmlspecialchars($aluno['email'] ?? 'Não informado'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-phone"></i> Telefone:</span>
                                    <span><?php echo htmlspecialchars($aluno['telefone'] ?? 'Não informado'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Dados Académicos -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Dados Académicos</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-calendar"></i> Ano Letivo:</span>
                                    <span><?php echo htmlspecialchars($aluno['ano_letivo'] ?? 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-book"></i> Curso:</span>
                                    <span><?php echo htmlspecialchars($aluno['curso'] ?? 'Não informado'); ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-layer-group"></i> Nível:</span>
                                    <span><?php echo htmlspecialchars($aluno['nivel'] ?? 'Não informado'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label"><i class="fas fa-chalkboard"></i> Classe:</span>
                                    <span><?php echo htmlspecialchars($aluno['classe'] ?? $aluno['ano_escolar'] ?? 'Não informado'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Dados dos Pais / Encarregado -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-family"></i> Filiação e Encarregado</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="text-primary">Dados do Pai</h6>
                                <div class="info-row"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($aluno['pai_nome'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">BI:</span> <?php echo htmlspecialchars($aluno['pai_bi'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Telefone:</span> <?php echo htmlspecialchars($aluno['pai_telefone'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Profissão:</span> <?php echo htmlspecialchars($aluno['pai_profissao'] ?? 'Não informado'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary">Dados da Mãe</h6>
                                <div class="info-row"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($aluno['mae_nome'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">BI:</span> <?php echo htmlspecialchars($aluno['mae_bi'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Telefone:</span> <?php echo htmlspecialchars($aluno['mae_telefone'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Profissão:</span> <?php echo htmlspecialchars($aluno['mae_profissao'] ?? 'Não informado'); ?></div>
                            </div>
                        </div>
                        <hr>
                        <h6 class="text-primary">Dados do Encarregado</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($aluno['encarregado_nome'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Parentesco:</span> <?php echo htmlspecialchars($aluno['encarregado_parentesco'] ?? 'Não informado'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row"><span class="info-label">BI:</span> <?php echo htmlspecialchars($aluno['encarregado_bi'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Telefone:</span> <?php echo htmlspecialchars($aluno['encarregado_telefone'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">E-mail:</span> <?php echo htmlspecialchars($aluno['encarregado_email'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label">Endereço:</span> <?php echo htmlspecialchars($aluno['encarregado_endereco'] ?? 'Não informado'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Histórico de Matrículas -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Histórico de Matrículas</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($matriculas)): ?>
                            <div class="alert alert-info">Nenhuma matrícula registrada.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Ano Letivo</th>
                                            <th>Nº Matrícula</th>
                                            <th>Turma</th>
                                            <th>Turno</th>
                                            <th>Sala</th>
                                            <th>Classe</th>
                                            <th>Status</th>
                                            <th>Data</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($matriculas as $mat): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($mat['ano_letivo'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($mat['numero_processo'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($mat['turma_nome'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($mat['turno'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($mat['sala'] ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars($mat['classe'] ?? '-'); ?></td>
                                            <td><?php echo getMatriculaStatusBadge($mat['matricula_status'] ?? ''); ?></td>
                                            <td><?php echo formatarData($mat['data_matricula'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Documentos -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-alt"></i> Documentos</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-documentos">
                                <tbody>
                                    <tr>
                                        <td style="width: 200px;"><i class="fas fa-id-card text-primary"></i> BI / Documento:</td>
                                        <td><?php echo exibirDocumento($aluno['bi_documento'] ?? '', 'BI'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-certificate text-success"></i> Certificado:</td>
                                        <td><?php echo exibirDocumento($aluno['certificado_documento'] ?? '', 'Certificado'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-stethoscope text-info"></i> Atestado Médico:</td>
                                        <td><?php echo exibirDocumento($aluno['atestado_documento'] ?? '', 'Atestado'); ?></td>
                                    </tr>
                                    <tr>
                                        <td><i class="fas fa-file-signature text-warning"></i> Declaração:</td>
                                        <td><?php echo exibirDocumento($aluno['declaracao_documento'] ?? '', 'Declaração'); ?></td>
                                    </tr>
                                    <?php 
                                    $outros = json_decode($aluno['outros_documentos'] ?? '', true);
                                    if (!empty($outros)):
                                    ?>
                                    <tr>
                                        <td><i class="fas fa-folder-open text-secondary"></i> Outros Documentos:</td>
                                        <td>
                                            <?php foreach ($outros as $doc): ?>
                                                <a href="../../uploads/alunos/documentos/<?php echo $doc; ?>" download class="btn btn-sm btn-secondary me-1 mb-1">
                                                    <i class="fas fa-download"></i> <?php echo $doc; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('secretaria')) {
            $('#menuSecretaria').addClass('open');
            $('#submenuSecretaria').addClass('show');
        }
    </script>
</body>
</html>