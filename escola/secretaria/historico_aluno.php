<?php
// escola/secretaria/historico_aluno.php - Histórico Completo do Aluno

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
$estudante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($estudante_id <= 0) {
    header('Location: lista_alunos.php?erro=ID do aluno inválido');
    exit;
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.data_nascimento,
        e.genero,
        e.email,
        e.telefone,
        e.endereco,
        e.foto,
        e.status as aluno_status,
        e.pais_nome,
        e.cidade_nome,
        e.provincia_nome,
        e.municipio_nome,
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
        e.created_at as data_cadastro,
        es.nome as escola_nome,
        es.logo as escola_logo
    FROM estudantes e
    LEFT JOIN escolas es ON es.id = e.escola_id
    WHERE e.id = :estudante_id AND e.escola_id = :escola_id
";

$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':estudante_id' => $estudante_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: lista_alunos.php?erro=Aluno não encontrado');
    exit;
}

// ============================================
// BUSCAR TODAS AS MATRÍCULAS DO ALUNO
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
    WHERE m.estudante_id = :estudante_id
    ORDER BY m.ano_letivo DESC, m.created_at DESC
";

$stmt_matriculas = $conn->prepare($sql_matriculas);
$stmt_matriculas->execute([':estudante_id' => $estudante_id]);
$matriculas = $stmt_matriculas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TODAS AS NOTAS DO ALUNO POR ANO LETIVO
// ============================================
$sql_notas = "
    SELECT 
        n.id,
        n.disciplina_id,
        n.bimestre,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.media_final,
        n.status as nota_status,
        n.created_at as data_lancamento,
        d.nome as disciplina_nome,
        al.ano as ano_letivo,
        p.nome as professor_nome
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
    LEFT JOIN funcionarios p ON p.id = n.professor_id
    WHERE n.estudante_id = :estudante_id
    ORDER BY al.ano DESC, n.bimestre, d.nome
";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':estudante_id' => $estudante_id]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR FREQUÊNCIA / CHAMADA
// ============================================
$sql_frequencia = "
    SELECT 
        c.id,
        c.disciplina_id,
        c.data_lancamento,
        c.status,
        c.observacao,
        d.nome as disciplina_nome,
        al.ano as ano_letivo
    FROM chamada c
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN ano_letivo al ON al.id = c.ano_letivo_id
    WHERE c.estudante_id = :estudante_id
    ORDER BY al.ano DESC, c.data_lancamento DESC
    LIMIT 50
";

$stmt_frequencia = $conn->prepare($sql_frequencia);
$stmt_frequencia->execute([':estudante_id' => $estudante_id]);
$frequencias = $stmt_frequencia->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataHora($data) {
    if (empty($data)) return '-';
    return date('d/m/Y H:i', strtotime($data));
}

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

function getNotaStatusBadge($status) {
    switch ($status) {
        case 'aprovado':
            return '<span class="badge bg-success">Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning">Recuperação</span>';
        case 'reprovado':
            return '<span class="badge bg-danger">Reprovado</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status ?? 'Pendente') . '</span>';
    }
}

function getFrequenciaBadge($status) {
    switch ($status) {
        case 'presente':
            return '<span class="badge bg-success">Presente</span>';
        case 'falta':
            return '<span class="badge bg-danger">Falta</span>';
        case 'justificado':
            return '<span class="badge bg-info">Justificado</span>';
        default:
            return '<span class="badge bg-secondary">' . htmlspecialchars($status ?? '') . '</span>';
    }
}

function getMediaGeralPorAno($notas, $ano) {
    $notas_ano = array_filter($notas, function($nota) use ($ano) {
        return $nota['ano_letivo'] == $ano;
    });
    if (empty($notas_ano)) return 0;
    $soma = 0;
    foreach ($notas_ano as $nota) {
        $soma += $nota['media_final'] ?? 0;
    }
    return round($soma / count($notas_ano), 1);
}

function getSituacaoPorMedia($media) {
    if ($media >= 10) {
        return ['texto' => 'Aprovado', 'classe' => 'success'];
    } elseif ($media >= 7) {
        return ['texto' => 'Recuperação', 'classe' => 'warning'];
    } else {
        return ['texto' => 'Reprovado', 'classe' => 'danger'];
    }
}

// Agrupar notas por ano letivo
$notas_por_ano = [];
foreach ($notas as $nota) {
    $ano = $nota['ano_letivo'];
    if (!isset($notas_por_ano[$ano])) {
        $notas_por_ano[$ano] = [];
    }
    $notas_por_ano[$ano][] = $nota;
}

// Agrupar frequências por ano letivo
$frequencias_por_ano = [];
foreach ($frequencias as $freq) {
    $ano = $freq['ano_letivo'];
    if (!isset($frequencias_por_ano[$ano])) {
        $frequencias_por_ano[$ano] = [];
    }
    $frequencias_por_ano[$ano][] = $freq;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico do Aluno | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .foto-aluno { width: 120px; height: 120px; object-fit: cover; border-radius: 50%; border: 3px solid #006B3E; }
        .info-label { font-weight: 600; color: #555; width: 180px; display: inline-block; }
        .info-row { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #eee; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .section-title { font-size: 1.2rem; font-weight: 600; color: #006B3E; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #006B3E; }
        .table-historico th { background: #f8f9fa; }
        .media-geral { font-size: 1.5rem; font-weight: bold; }
        .ano-card { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 20px; border-left: 4px solid #006B3E; }
        .ano-titulo { font-size: 1.1rem; font-weight: bold; color: #006B3E; margin-bottom: 15px; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-history"></i> Histórico do Aluno</h2>
            <div>
                <a href="ver_aluno.php?id=<?php echo $estudante_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye"></i> Ver Perfil
                </a>
                <a href="emitir_certificado.php?id=<?php echo $estudante_id; ?>" class="btn btn-success">
                    <i class="fas fa-certificate"></i> Certificado
                </a>
                <a href="gerar_pdf_historico.php?id=<?php echo $estudante_id; ?>" class="btn btn-danger" target="_blank">
                    <i class="fas fa-file-pdf"></i> PDF
                </a>
                <a href="lista_alunos.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="row">
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
                    </div>
                </div>
            </div>
            
            <div class="col-md-9">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user"></i> Informações Pessoais</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row"><span class="info-label"><i class="fas fa-id-card"></i> BI/Nº:</span> <?php echo htmlspecialchars($aluno['bi'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label"><i class="fas fa-calendar"></i> Data Nascimento:</span> <?php echo formatarData($aluno['data_nascimento'] ?? ''); ?></div>
                                <div class="info-row"><span class="info-label"><i class="fas fa-venus-mars"></i> Género:</span> <?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label"><i class="fas fa-map-marker-alt"></i> Naturalidade:</span> <?php echo htmlspecialchars($aluno['cidade_nome'] ?? ''); ?> / <?php echo htmlspecialchars($aluno['provincia_nome'] ?? ''); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row"><span class="info-label"><i class="fas fa-envelope"></i> E-mail:</span> <?php echo htmlspecialchars($aluno['email'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label"><i class="fas fa-phone"></i> Telefone:</span> <?php echo htmlspecialchars($aluno['telefone'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label"><i class="fas fa-home"></i> Endereço:</span> <?php echo htmlspecialchars($aluno['endereco'] ?? 'Não informado'); ?></div>
                                <div class="info-row"><span class="info-label"><i class="fas fa-calendar-plus"></i> Data Cadastro:</span> <?php echo formatarData($aluno['data_cadastro'] ?? ''); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Histórico de Matrículas -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-list-alt"></i> Histórico de Matrículas</h5>
            </div>
            <div class="card-body">
                <?php if (empty($matriculas)): ?>
                    <div class="alert alert-info">Nenhuma matrícula registrada.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Ano Letivo</th>
                                    <th>Nº Matrícula</th>
                                    <th>Turma</th>
                                    <th>Classe</th>
                                    <th>Curso</th>
                                    <th>Nível</th>
                                    <th>Turno</th>
                                    <th>Sala</th>
                                    <th>Status</th>
                                    <th>Data</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($matriculas as $mat): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($mat['ano_letivo'] ?? ''); ?></td>
                                    <td><strong><?php echo htmlspecialchars($mat['numero_processo'] ?? ''); ?></strong></td>
                                    <td><?php echo htmlspecialchars($mat['turma_nome'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($mat['classe'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($mat['curso'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($mat['nivel'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($mat['turno'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($mat['sala'] ?? '-'); ?></td>
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
        
        <!-- Histórico de Notas por Ano Letivo -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Histórico de Notas</h5>
            </div>
            <div class="card-body">
                <?php if (empty($notas_por_ano)): ?>
                    <div class="alert alert-info">Nenhuma nota registrada.</div>
                <?php else: ?>
                    <?php foreach ($notas_por_ano as $ano => $notas_ano): ?>
                        <?php $media_geral = getMediaGeralPorAno($notas, $ano); ?>
                        <?php $situacao = getSituacaoPorMedia($media_geral); ?>
                        <div class="ano-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div class="ano-titulo">
                                    <i class="fas fa-calendar-alt"></i> Ano Letivo: <?php echo $ano; ?>
                                </div>
                                <div>
                                    <span class="badge bg-secondary"><?php echo count($notas_ano); ?> disciplinas</span>
                                    <span class="badge bg-<?php echo $situacao['classe']; ?> ms-2">Média: <?php echo $media_geral; ?> - <?php echo $situacao['texto']; ?></span>
                                </div>
                            </div>
                            <div class="table-responsive mt-3">
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Disciplina</th>
                                            <th>Professor</th>
                                            <th>MAC</th>
                                            <th>NPT</th>
                                            <th>Exame Normal</th>
                                            <th>Exame Recurso</th>
                                            <th>Média Final</th>
                                            <th>Status</th>
                                            <th>Data</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($notas_ano as $nota): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($nota['disciplina_nome'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($nota['professor_nome'] ?? '-'); ?></td>
                                            <td><?php echo number_format($nota['mac'] ?? 0, 1); ?></td>
                                            <td><?php echo number_format($nota['npt'] ?? 0, 1); ?></td>
                                            <td><?php echo number_format($nota['exame_normal'] ?? 0, 1); ?></td>
                                            <td><?php echo number_format($nota['exame_recurso'] ?? 0, 1); ?></td>
                                            <td><strong><?php echo number_format($nota['media_final'] ?? 0, 1); ?></strong></td>
                                            <td><?php echo getNotaStatusBadge($nota['nota_status'] ?? ''); ?></td>
                                            <td><?php echo formatarData($nota['data_lancamento'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot class="table-light">
                                        <tr>
                                            <th colspan="6">Média Geral do Ano</th>
                                            <th colspan="3"><strong><?php echo $media_geral; ?> valores - <?php echo $situacao['texto']; ?></strong></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Histórico de Frequência -->
        <div class="card mt-3">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-calendar-check"></i> Histórico de Frequência</h5>
            </div>
            <div class="card-body">
                <?php if (empty($frequencias_por_ano)): ?>
                    <div class="alert alert-info">Nenhum registro de frequência.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Data</th>
                                    <th>Disciplina</th>
                                    <th>Ano Letivo</th>
                                    <th>Status</th>
                                    <th>Observação</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($frequencias as $freq): ?>
                                <tr>
                                    <td><?php echo formatarData($freq['data'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($freq['disciplina_nome'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($freq['ano_letivo'] ?? ''); ?></td>
                                    <td><?php echo getFrequenciaBadge($freq['status'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($freq['observacao'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Dados dos Pais e Encarregado -->
        <div class="row mt-3">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-friends"></i> Filiação</h5>
                    </div>
                    <div class="card-body">
                        <h6 class="text-primary">Pai</h6>
                        <div class="info-row"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($aluno['pai_nome'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">BI:</span> <?php echo htmlspecialchars($aluno['pai_bi'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Telefone:</span> <?php echo htmlspecialchars($aluno['pai_telefone'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Profissão:</span> <?php echo htmlspecialchars($aluno['pai_profissao'] ?? 'Não informado'); ?></div>
                        
                        <h6 class="text-primary mt-3">Mãe</h6>
                        <div class="info-row"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($aluno['mae_nome'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">BI:</span> <?php echo htmlspecialchars($aluno['mae_bi'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Telefone:</span> <?php echo htmlspecialchars($aluno['mae_telefone'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Profissão:</span> <?php echo htmlspecialchars($aluno['mae_profissao'] ?? 'Não informado'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-user-tie"></i> Encarregado de Educação</h5>
                    </div>
                    <div class="card-body">
                        <div class="info-row"><span class="info-label">Nome:</span> <?php echo htmlspecialchars($aluno['encarregado_nome'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Parentesco:</span> <?php echo htmlspecialchars($aluno['encarregado_parentesco'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">BI:</span> <?php echo htmlspecialchars($aluno['encarregado_bi'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Telefone:</span> <?php echo htmlspecialchars($aluno['encarregado_telefone'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">E-mail:</span> <?php echo htmlspecialchars($aluno['encarregado_email'] ?? 'Não informado'); ?></div>
                        <div class="info-row"><span class="info-label">Endereço:</span> <?php echo htmlspecialchars($aluno['encarregado_endereco'] ?? 'Não informado'); ?></div>
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