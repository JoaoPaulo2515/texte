<?php
// escola/professor/ver_aluno.php - Visualizar Detalhes do Aluno

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// PEGAR ID DO ALUNO
// ============================================
$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aluno_id <= 0) {
    header('Location: dashboard.php');
    exit;
}

// ============================================
// BUSCAR DADOS DO ALUNO (incluindo responsável)
// ============================================
$sql_aluno = "
    SELECT 
        e.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno as turma_turno,
        t.sala as turma_sala
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id AND t.escola_id = :escola_id
    WHERE e.id = :aluno_id AND e.escola_id = :escola_id
";

$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: dashboard.php?erro=Aluno não encontrado');
    exit;
}

// ============================================
// BUSCAR HISTÓRICO DE TURMAS DO ALUNO
// ============================================
$sql_historico_turmas = "
    SELECT 
        m.*,
        t.nome as turma_nome,
        t.ano as turma_ano,
        t.turno as turma_turno
    FROM matriculas m
    INNER JOIN turmas t ON t.id = m.turma_id
    WHERE m.estudante_id = :aluno_id
    ORDER BY m.created_at DESC
";

$stmt_historico = $conn->prepare($sql_historico_turmas);
$stmt_historico->execute([':aluno_id' => $aluno_id]);
$historico_turmas = $stmt_historico->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR NOTAS DO ALUNO
// ============================================
$sql_notas = "
    SELECT 
        d.nome as disciplina_nome,
        n.bimestre,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.exame_oral,
        n.exame_escrito,
        n.media_final,
        n.status as situacao
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    WHERE n.estudante_id = :aluno_id
    ORDER BY n.bimestre, d.nome
";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':aluno_id' => $aluno_id]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR PRESENÇAS DO ALUNO
// ============================================
$sql_presencas = "
    SELECT 
        c.*,
        d.nome as disciplina_nome,
        t.nome as turma_nome
    FROM chamada c
    INNER JOIN disciplinas d ON d.id = c.disciplina_id
    INNER JOIN turmas t ON t.id = c.turma_id
    WHERE c.estudante_id = :aluno_id
    ORDER BY c.data_aula DESC
    LIMIT 50
";

$stmt_presencas = $conn->prepare($sql_presencas);
$stmt_presencas->execute([':aluno_id' => $aluno_id]);
$presencas = $stmt_presencas->fetchAll(PDO::FETCH_ASSOC);

// Calcular estatísticas de presença
$total_aulas = count($presencas);
$total_presentes = 0;
$total_faltas = 0;
$total_atrasos = 0;
$total_justificados = 0;

foreach ($presencas as $p) {
    switch ($p['status']) {
        case 'presente': $total_presentes++; break;
        case 'falta': $total_faltas++; break;
        case 'atraso': $total_atrasos++; break;
        case 'justificado': $total_justificados++; break;
    }
}
$percentual_presenca = $total_aulas > 0 ? round(($total_presentes / $total_aulas) * 100, 1) : 0;

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getSituacaoBadge($situacao) {
    switch ($situacao) {
        case 'aprovado':
            return '<span class="badge bg-success">Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge bg-warning text-dark">Recuperação</span>';
        case 'reprovado':
            return '<span class="badge bg-danger">Reprovado</span>';
        default:
            return '<span class="badge bg-secondary">Pendente</span>';
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'presente':
            return '<span class="badge bg-success">✅ Presente</span>';
        case 'falta':
            return '<span class="badge bg-danger">❌ Falta</span>';
        case 'atraso':
            return '<span class="badge bg-warning text-dark">⏰ Atraso</span>';
        case 'justificado':
            return '<span class="badge bg-info">📋 Justificado</span>';
        default:
            return '<span class="badge bg-secondary">-</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarTelefone($telefone) {
    if (empty($telefone)) return 'Não informado';
    // Formatar telefone (ex: 923456789 -> 923 456 789)
    return preg_replace('/(\d{3})(\d{3})(\d{3})/', '$1 $2 $3', $telefone);
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($aluno['nome']); ?> | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <?php include 'includes/sidebar.php'; ?>
    <style>
        .profile-header {
            background: linear-gradient(135deg, #006B3E 0%, #008B4E 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .profile-photo {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid white;
            background: white;
        }
        .info-card {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            transition: transform 0.2s;
        }
        .info-card:hover {
            transform: translateY(-2px);
        }
        .info-card .card-header {
            background: #f8f9fa;
            border-bottom: 2px solid #006B3E;
            font-weight: bold;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #006B3E;
        }
        .table-notas th {
            background: #006B3E;
            color: white;
            text-align: center;
            font-size: 12px;
        }
        .table-notas td {
            text-align: center;
            vertical-align: middle;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
        .info-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            font-weight: normal;
        }
        .info-value {
            font-size: 14px;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="main-content">
        <!-- Botão Voltar -->
        <div class="mb-3">
            <a href="javascript:history.back()" class="btn-voltar btn">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
        
        <!-- Cabeçalho do Perfil -->
        <div class="profile-header">
            <div class="row align-items-center">
                <div class="col-auto">
                    <?php 
                    $foto_path = '../../uploads/alunos/fotos/' . $aluno['foto'];
                    if (!empty($aluno['foto']) && file_exists($foto_path)): ?>
                        <img src="<?php echo $foto_path; ?>" class="profile-photo">
                    <?php else: ?>
                        <img src="../../assets/images/avatar-padrao.png" class="profile-photo">
                    <?php endif; ?>
                </div>
                <div class="col">
                    <h1 class="mb-1"><?php echo htmlspecialchars($aluno['nome']); ?></h1>
                    <p class="mb-0">
                        <i class="fas fa-id-card"></i> Matrícula: <?php echo htmlspecialchars($aluno['matricula']); ?> |
                        <i class="fas fa-calendar-alt"></i> Nascimento: <?php echo formatarData($aluno['data_nascimento']); ?> |
                        <i class="fas fa-venus-mars"></i> <?php echo $aluno['sexo'] == 'M' ? 'Masculino' : 'Feminino'; ?>
                    </p>
                    <?php if ($aluno['turma_nome']): ?>
                    <p class="mb-0 mt-2">
                        <i class="fas fa-chalkboard-user"></i> Turma Atual: 
                        <strong><?php echo $aluno['turma_ano'] . 'ª ' . $aluno['turma_nome']; ?></strong> 
                        (<?php echo ucfirst($aluno['turma_turno']); ?> - Sala: <?php echo $aluno['turma_sala'] ?: 'Não definida'; ?>)
                    </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Coluna Esquerda - Informações Pessoais -->
            <div class="col-md-4">
                <!-- Contato do Aluno -->
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-address-card"></i> Contato do Aluno
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <i class="fas fa-envelope"></i> 
                            <span class="info-label">Email</span><br>
                            <span class="info-value"><?php echo htmlspecialchars($aluno['email']) ?: 'Não informado'; ?></span>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-phone"></i> 
                            <span class="info-label">Telefone</span><br>
                            <span class="info-value"><?php echo !empty($aluno['telefone']) ? formatarTelefone($aluno['telefone']) : 'Não informado'; ?></span>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-map-marker-alt"></i> 
                            <span class="info-label">Endereço</span><br>
                            <span class="info-value"><?php echo htmlspecialchars($aluno['endereco']) ?: 'Não informado'; ?></span>
                        </p>
                    </div>
                </div>
                
                <!-- Encarregado/Responsável (da tabela estudantes) -->
                <?php if (!empty($aluno['encarregado_nome']) || !empty($aluno['encarregado_telefone'])): ?>
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-user-tie"></i> Encarregado de Educação
                    </div>
                    <div class="card-body">
                        <?php if (!empty($aluno['encarregado_nome'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-user"></i> 
                            <span class="info-label">Nome</span><br>
                            <span class="info-value"><strong><?php echo htmlspecialchars($aluno['encarregado_nome']); ?></strong></span>
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($aluno['encarregado_parentesco'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-heart"></i> 
                            <span class="info-label">Parentesco</span><br>
                            <span class="info-value"><?php echo htmlspecialchars($aluno['encarregado_parentesco']); ?></span>
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($aluno['encarregado_telefone'])): ?>
                        <p class="mb-2">
                            <i class="fas fa-phone-alt"></i> 
                            <span class="info-label">Telefone</span><br>
                            <span class="info-value"><?php echo formatarTelefone($aluno['encarregado_telefone']); ?></span>
                        </p>
                        <?php endif; ?>
                        
                        <?php if (!empty($aluno['encarregado_email'])): ?>
                        <p class="mb-0">
                            <i class="fas fa-envelope"></i> 
                            <span class="info-label">Email</span><br>
                            <span class="info-value"><?php echo htmlspecialchars($aluno['encarregado_email']); ?></span>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Documentos -->
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-id-card"></i> Documentos
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <i class="fas fa-id-card"></i> 
                            <span class="info-label">BI / CPF</span><br>
                            <span class="info-value"><?php echo htmlspecialchars($aluno['cpf'] ?? $aluno['bi'] ?? $aluno['documento'] ?? 'Não informado'); ?></span>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-calendar"></i> 
                            <span class="info-label">Data de Nascimento</span><br>
                            <span class="info-value"><?php echo formatarData($aluno['data_nascimento']); ?></span>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-map-marker"></i> 
                            <span class="info-label">Naturalidade</span><br>
                            <span class="info-value"><?php echo htmlspecialchars($aluno['naturalidade'] ?? 'Não informado'); ?></span>
                        </p>
                    </div>
                </div>
                
                <!-- Estatísticas de Presença -->
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-chart-line"></i> Estatísticas de Presença
                    </div>
                    <div class="card-body text-center">
                        <div class="row">
                            <div class="col-6">
                                <div class="stat-number"><?php echo $total_aulas; ?></div>
                                <small>Total de Aulas</small>
                            </div>
                            <div class="col-6">
                                <div class="stat-number text-success"><?php echo $percentual_presenca; ?>%</div>
                                <small>Frequência</small>
                            </div>
                        </div>
                        <hr>
                        <div class="row mt-2">
                            <div class="col-4">
                                <span class="badge bg-success">Presente: <?php echo $total_presentes; ?></span>
                            </div>
                            <div class="col-4">
                                <span class="badge bg-danger">Falta: <?php echo $total_faltas; ?></span>
                            </div>
                            <div class="col-4">
                                <span class="badge bg-warning text-dark">Atraso: <?php echo $total_atrasos; ?></span>
                            </div>
                            <div class="col-12 mt-2">
                                <span class="badge bg-info">Justificado: <?php echo $total_justificados; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna Direita - Notas e Presenças -->
            <div class="col-md-8">
                <!-- Notas do Aluno -->
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-graduation-cap"></i> Boletim de Notas
                    </div>
                    <div class="card-body">
                        <?php if (empty($notas)): ?>
                            <p class="text-muted text-center">Nenhuma nota registrada</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-notas">
                                    <thead>
                                        <tr>
                                            <th>Disciplina</th>
                                            <th>Bim</th>
                                            <th>MAC</th>
                                            <th>NPT</th>
                                            <th>Exame Normal</th>
                                            <th>Média</th>
                                            <th>Situação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($notas as $nota): ?>
                                        <tr>
                                            <td class="text-start"><?php echo htmlspecialchars($nota['disciplina_nome']); ?></td>
                                            <td><?php echo $nota['bimestre']; ?>º</td>
                                            <td><?php echo number_format($nota['mac'] ?? 0, 1); ?></td>
                                            <td><?php echo number_format($nota['npt'] ?? 0, 1); ?></td>
                                            <td><?php echo number_format($nota['exame_normal'] ?? 0, 1); ?></td>
                                            <td><strong><?php echo number_format($nota['media_final'] ?? 0, 1); ?></strong></td>
                                            <td><?php echo getSituacaoBadge($nota['situacao']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Últimas Presenças -->
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-clipboard-list"></i> Últimas Chamadas
                    </div>
                    <div class="card-body">
                        <?php if (empty($presencas)): ?>
                            <p class="text-muted text-center">Nenhum registro de chamada</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Data</th>
                                            <th>Disciplina</th>
                                            <th>Turma</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($presencas as $p): ?>
                                        <tr>
                                            <td><?php echo formatarData($p['data_aula']); ?></td>
                                            <td><?php echo htmlspecialchars($p['disciplina_nome']); ?></td>
                                            <td><?php echo htmlspecialchars($p['turma_nome']); ?></td>
                                            <td><?php echo getStatusBadge($p['status']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Histórico de Turmas -->
                <div class="card info-card">
                    <div class="card-header">
                        <i class="fas fa-history"></i> Histórico de Turmas
                    </div>
                    <div class="card-body">
                        <?php if (empty($historico_turmas)): ?>
                            <p class="text-muted text-center">Nenhum histórico de turmas</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Turma</th>
                                            <th>Turno</th>
                                            <th>Data Matrícula</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historico_turmas as $hist): ?>
                                        <tr>
                                            <td><?php echo $hist['turma_ano'] . 'ª ' . $hist['turma_nome']; ?></td>
                                            <td><?php echo ucfirst($hist['turma_turno']); ?></td>
                                            <td><?php echo formatarData($hist['created_at']); ?></td>
                                            <td>
                                                <?php if ($hist['status'] == 'ativa'): ?>
                                                    <span class="badge bg-success">Ativa</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Concluída</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>