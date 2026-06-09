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
// BUSCAR DADOS DO ALUNO
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

$classe_ano = $aluno['turma_ano'] ?? 0;
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;

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
        d.id as disciplina_id,
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
            return '<span class="badge-status badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'recuperacao':
            return '<span class="badge-status badge-recuperacao"><i class="fas fa-clock"></i> Recuperação</span>';
        case 'reprovado':
            return '<span class="badge-status badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>';
        default:
            return '<span class="badge-status badge-pendente"><i class="fas fa-hourglass-half"></i> Pendente</span>';
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'presente':
            return '<span class="badge-status badge-presente"><i class="fas fa-check-circle"></i> Presente</span>';
        case 'falta':
            return '<span class="badge-status badge-falta"><i class="fas fa-times-circle"></i> Falta</span>';
        case 'atraso':
            return '<span class="badge-status badge-atraso"><i class="fas fa-clock"></i> Atraso</span>';
        case 'justificado':
            return '<span class="badge-status badge-justificado"><i class="fas fa-check-double"></i> Justificado</span>';
        default:
            return '<span class="badge-status badge-pendente">-</span>';
    }
}

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA DO 3º BIMESTRE
// ============================================
function calcularMediaFinal3Bimestre($mac, $npt, $exame_normal, $exame_recurso, $exame_oral, $exame_escrito, $is_classe_exame, $is_disciplina_lingua) {
    if ($is_classe_exame) {
        // Classes de Exame (6ª, 9ª, 12ª) - NPT NÃO é considerado
        $media_parcial = $mac;
        
        if ($exame_recurso > 0) {
            return round($exame_recurso, 1);
        } else {
            if ($is_disciplina_lingua) {
                if ($exame_oral > 0 && $exame_escrito > 0) {
                    $media_exame = ($exame_oral + $exame_escrito) / 2;
                    return round(($media_parcial * 0.4) + ($media_exame * 0.6), 1);
                } elseif ($exame_oral > 0) {
                    return round(($media_parcial * 0.4) + ($exame_oral * 0.6), 1);
                } elseif ($exame_escrito > 0) {
                    return round(($media_parcial * 0.4) + ($exame_escrito * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            } else {
                if ($exame_normal > 0) {
                    return round(($media_parcial * 0.4) + ($exame_normal * 0.6), 1);
                } else {
                    return round($media_parcial, 1);
                }
            }
        }
    } else {
        // Classes normais
        $media_parcial = ($mac + $npt) / 2;
        
        if ($exame_recurso > 0) {
            return round(($media_parcial + $exame_recurso) / 2, 1);
        } elseif ($exame_normal > 0) {
            return round(($media_parcial + $exame_normal) / 2, 1);
        } else {
            return round($media_parcial, 1);
        }
    }
}

// ============================================
// FUNÇÃO PARA VERIFICAR SE É DISCIPLINA DE LÍNGUA
// ============================================
function isDisciplinaLingua($disciplina_nome) {
    $nomes_linguas = ['português', 'portugues', 'inglês', 'ingles', 'língua portuguesa', 'lingua portuguesa', 'english', 'portuguese'];
    foreach ($nomes_linguas as $lingua) {
        if (stripos($disciplina_nome, $lingua) !== false) {
            return true;
        }
    }
    return false;
}

// ============================================
// FUNÇÃO PARA COR DA NOTA
// ============================================
function getNotaClass($nota, $is_ensino_fundamental) {
    if ($is_ensino_fundamental) {
        if ($nota >= 7) return 'nota-excelente';
        if ($nota >= 5) return 'nota-bom';
        if ($nota > 0) return 'nota-ruim';
    } else {
        if ($nota >= 14) return 'nota-excelente';
        if ($nota >= 10) return 'nota-bom';
        if ($nota > 0) return 'nota-ruim';
    }
    return '';
}

// ============================================
// PROCESSAR NOTAS COM A LÓGICA DO 3º BIMESTRE
// ============================================
$notas_processadas = [];
foreach ($notas as $nota) {
    $disciplina_nome = $nota['disciplina_nome'];
    $is_lingua = isDisciplinaLingua($disciplina_nome);
    $bimestre = $nota['bimestre'];
    
    $media_final = $nota['media_final'];
    
    // Se for 3º bimestre e a média não estiver calculada, recalcular
    if ($bimestre == 3 && ($media_final == 0 || $media_final == null)) {
        $media_final = calcularMediaFinal3Bimestre(
            $nota['mac'] ?? 0,
            $nota['npt'] ?? 0,
            $nota['exame_normal'] ?? 0,
            $nota['exame_recurso'] ?? 0,
            $nota['exame_oral'] ?? 0,
            $nota['exame_escrito'] ?? 0,
            $is_classe_exame,
            $is_lingua
        );
    }
    
    $notas_processadas[] = [
        'disciplina_id' => $nota['disciplina_id'],
        'disciplina_nome' => $disciplina_nome,
        'bimestre' => $bimestre,
        'mac' => $nota['mac'],
        'npt' => $nota['npt'],
        'exame_normal' => $nota['exame_normal'],
        'exame_recurso' => $nota['exame_recurso'],
        'exame_oral' => $nota['exame_oral'],
        'exame_escrito' => $nota['exame_escrito'],
        'media_final' => $media_final,
        'situacao' => $nota['situacao'],
        'is_lingua' => $is_lingua
    ];
}

// Agrupar notas por disciplina e bimestre
$notas_agrupadas = [];
foreach ($notas_processadas as $nota) {
    $key = $nota['disciplina_nome'] . '_' . $nota['bimestre'];
    $notas_agrupadas[$key] = $nota;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title><?php echo htmlspecialchars($aluno['nome']); ?> | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E BASE
        ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            padding: 30px;
            min-height: calc(100vh - 60px);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                margin-top: 70px;
                padding: 20px;
            }
        }

        /* ============================================
           BOTÃO VOLTAR
        ============================================ */
        .btn-voltar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            margin-bottom: 20px;
        }

        .btn-voltar:hover {
            background: #5a6268;
            transform: translateY(-2px);
            color: white;
        }

        /* ============================================
           PROFILE HEADER
        ============================================ */
        .profile-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 24px;
            padding: 25px 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            position: relative;
            overflow: hidden;
        }

        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
        }

        .profile-photo {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid white;
            background: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .profile-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        /* ============================================
           INFO CARDS
        ============================================ */
        .info-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .card-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 12px 20px;
            font-weight: 600;
            border: none;
        }

        .card-header-custom i {
            margin-right: 10px;
        }

        .card-body-custom {
            padding: 20px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .info-item:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .info-item i {
            width: 25px;
            color: #006B3E;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
        }

        .info-value {
            color: #6c757d;
        }

        /* ============================================
           STATS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-box {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-3px);
            background: #e9ecef;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* ============================================
           TABELAS
        ============================================ */
        .table-custom {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.75rem;
        }

        .table-custom thead th {
            background: #f8f9fa;
            padding: 10px 8px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
        }

        .table-custom tbody td {
            padding: 8px;
            font-size: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            text-align: center;
        }

        .table-custom tbody tr:hover {
            background: #f8f9fa;
        }

        .table-custom tbody tr:last-child td {
            border-bottom: none;
        }

        /* ============================================
           BADGES
        ============================================ */
        .badge-status {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .badge-aprovado {
            background: #28a745;
            color: white;
        }

        .badge-recuperacao {
            background: #ffc107;
            color: #333;
        }

        .badge-reprovado {
            background: #dc3545;
            color: white;
        }

        .badge-pendente {
            background: #6c757d;
            color: white;
        }

        .badge-presente {
            background: #28a745;
            color: white;
        }

        .badge-falta {
            background: #dc3545;
            color: white;
        }

        .badge-atraso {
            background: #ffc107;
            color: #333;
        }

        .badge-justificado {
            background: #17a2b8;
            color: white;
        }

        /* ============================================
           CORES DAS NOTAS
        ============================================ */
        .nota-excelente {
            color: #28a745;
            font-weight: bold;
        }

        .nota-bom {
            color: #17a2b8;
            font-weight: bold;
        }

        .nota-ruim {
            color: #dc3545;
            font-weight: bold;
        }

        /* ============================================
           ANIMAÇÕES
        ============================================ */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            animation: fadeInUp 0.5s ease-out;
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .profile-header {
                padding: 20px;
            }
            
            .profile-header h1 {
                font-size: 1.2rem;
            }
            
            .table-custom thead th,
            .table-custom tbody td {
                padding: 6px 4px;
                font-size: 0.65rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Botão Voltar -->
        <div class="no-print">
            <a href="javascript:history.back()" class="btn-voltar">
                <i class="fas fa-arrow-left"></i> Voltar
            </a>
        </div>
        
        <!-- Cabeçalho do Perfil -->
        <div class="profile-header fade-in">
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
                    <p class="mb-1">
                        <i class="fas fa-id-card me-1"></i> Matrícula: <?php echo htmlspecialchars($aluno['matricula']); ?>
                    </p>
                    <p class="mb-1">
                        <i class="fas fa-calendar-alt me-1"></i> Nascimento: <?php echo formatarData($aluno['data_nascimento']); ?>
                        <span class="mx-2">|</span>
                        <i class="fas fa-venus-mars me-1"></i> <?php echo $aluno['genero'] == 'M' ? 'Masculino' : 'Feminino'; ?>
                    </p>
                    <?php if ($aluno['turma_nome']): ?>
                    <p class="mb-0 mt-2">
                        <i class="fas fa-chalkboard-user me-1"></i> Turma Atual: 
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
                <!-- Contato -->
                <div class="info-card fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-address-card"></i> Contato
                    </div>
                    <div class="card-body-custom">
                        <div class="info-item">
                            <i class="fas fa-envelope"></i>
                            <div>
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo htmlspecialchars($aluno['email']) ?: 'Não informado'; ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-phone"></i>
                            <div>
                                <div class="info-label">Telefone</div>
                                <div class="info-value"><?php echo htmlspecialchars($aluno['telefone']) ?: 'Não informado'; ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-map-marker-alt"></i>
                            <div>
                                <div class="info-label">Endereço</div>
                                <div class="info-value"><?php echo htmlspecialchars($aluno['endereco']) ?: 'Não informado'; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Documentos -->
                <div class="info-card fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-id-card"></i> Documentos
                    </div>
                    <div class="card-body-custom">
                        <div class="info-item">
                            <i class="fas fa-id-card"></i>
                            <div>
                                <div class="info-label">BI / CPF</div>
                                <div class="info-value"><?php echo htmlspecialchars($aluno['bi'] ?? $aluno['cpf'] ?? 'Não informado'); ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-calendar"></i>
                            <div>
                                <div class="info-label">Data de Nascimento</div>
                                <div class="info-value"><?php echo formatarData($aluno['data_nascimento']); ?></div>
                            </div>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-map-marker"></i>
                            <div>
                                <div class="info-label">Naturalidade</div>
                                <div class="info-value"><?php echo htmlspecialchars($aluno['naturalidade'] ?? 'Não informado'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Estatísticas de Presença -->
                <div class="info-card fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-chart-line"></i> Estatísticas de Presença
                    </div>
                    <div class="card-body-custom">
                        <div class="stats-grid">
                            <div class="stat-box">
                                <div class="stat-number text-primary"><?php echo $total_aulas; ?></div>
                                <div class="stat-label">Total de Aulas</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number text-success"><?php echo $percentual_presenca; ?>%</div>
                                <div class="stat-label">Frequência</div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 justify-content-center">
                            <span class="badge-status badge-presente">Presente: <?php echo $total_presentes; ?></span>
                            <span class="badge-status badge-falta">Falta: <?php echo $total_faltas; ?></span>
                            <span class="badge-status badge-atraso">Atraso: <?php echo $total_atrasos; ?></span>
                            <span class="badge-status badge-justificado">Justificado: <?php echo $total_justificados; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Coluna Direita - Notas e Presenças -->
            <div class="col-md-8">
                <!-- Notas do Aluno -->
                <div class="info-card fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-graduation-cap"></i> Boletim de Notas
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($notas)): ?>
                            <p class="text-muted text-center py-4">Nenhuma nota registrada</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-custom">
                                    <thead>
                                        <tr>
                                            <th>Disciplina</th>
                                            <th>Bim</th>
                                            <th>MAC</th>
                                            <th>NPT</th>
                                            <?php if ($is_classe_exame): ?>
                                                <?php if ($is_disciplina_lingua): ?>
                                                <th>Exame Oral</th>
                                                <th>Exame Escrito</th>
                                                <?php else: ?>
                                                <th>Exame Normal</th>
                                                <?php endif; ?>
                                                <th>Exame Recurso</th>
                                            <?php else: ?>
                                                <th>Exame</th>
                                            <?php endif; ?>
                                            <th>Média</th>
                                            <th>Situação</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $bimestres_por_disciplina = [];
                                        foreach ($notas_processadas as $nota) {
                                            $key = $nota['disciplina_nome'] . '_' . $nota['bimestre'];
                                            $bimestres_por_disciplina[$key] = $nota;
                                        }
                                        
                                        // Ordenar por disciplina e depois por bimestre
                                        uasort($bimestres_por_disciplina, function($a, $b) {
                                            if ($a['disciplina_nome'] != $b['disciplina_nome']) {
                                                return strcmp($a['disciplina_nome'], $b['disciplina_nome']);
                                            }
                                            return $a['bimestre'] - $b['bimestre'];
                                        });
                                        
                                        foreach ($bimestres_por_disciplina as $nota): 
                                            $media = $nota['media_final'];
                                            $nota_class = getNotaClass($media, $is_ensino_fundamental);
                                            $is_lingua = $nota['is_lingua'];
                                        ?>
                                        <tr>
                                            <td class="text-start"><?php echo htmlspecialchars($nota['disciplina_nome']); ?>
                                                <?php if ($is_lingua): ?>
                                                    <span class="badge bg-info ms-1" style="font-size: 8px;">Língua</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center"><?php echo $nota['bimestre']; ?>º</td>
                                            <td class="text-center"><?php echo number_format($nota['mac'] ?? 0, 1); ?></td>
                                            <td class="text-center"><?php echo number_format($nota['npt'] ?? 0, 1); ?></td>
                                            
                                            <?php if ($is_classe_exame): ?>
                                                <?php if ($is_lingua): ?>
                                                <td class="text-center"><?php echo number_format($nota['exame_oral'] ?? 0, 1); ?></td>
                                                <td class="text-center"><?php echo number_format($nota['exame_escrito'] ?? 0, 1); ?></td>
                                                <?php else: ?>
                                                <td class="text-center"><?php echo number_format($nota['exame_normal'] ?? 0, 1); ?></td>
                                                <?php endif; ?>
                                                <td class="text-center"><?php echo number_format($nota['exame_recurso'] ?? 0, 1); ?></td>
                                            <?php else: ?>
                                                <td class="text-center"><?php echo number_format($nota['exame_normal'] ?? 0, 1); ?></td>
                                            <?php endif; ?>
                                            
                                            <td class="text-center"><span class="<?php echo $nota_class; ?>"><?php echo number_format($media, 1); ?></span></td>
                                            <td class="text-center"><?php echo getSituacaoBadge($nota['situacao']); ?></td>
                                      
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Legenda de Notas -->
                            <div class="mt-3 p-2 bg-light rounded" style="font-size: 0.7rem;">
                                <strong>Legenda de Notas:</strong>
                                <span class="nota-excelente ms-2">● Excelente (≥ <?php echo $is_ensino_fundamental ? '7' : '14'; ?>)</span>
                                <span class="nota-bom ms-2">● Bom (≥ <?php echo $is_ensino_fundamental ? '5' : '10'; ?>)</span>
                                <span class="nota-ruim ms-2">● Baixo (< <?php echo $is_ensino_fundamental ? '5' : '10'; ?>)</span>
                                <?php if ($is_classe_exame): ?>
                                <span class="ms-3"><i class="fas fa-info-circle"></i> Classe de Exame (<?php echo $classe_ano; ?>ª) - Média = 40% MAC + 60% Exame</span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Últimas Presenças -->
                <div class="info-card fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-clipboard-list"></i> Últimas Chamadas
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($presencas)): ?>
                            <p class="text-muted text-center py-4">Nenhum registro de chamada</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-custom">
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
                                            <td class="text-center"><?php echo formatarData($p['data_aula']); ?></td>
                                            <td class="text-start"><?php echo htmlspecialchars($p['disciplina_nome']); ?></td>
                                            <td class="text-start"><?php echo htmlspecialchars($p['turma_nome']); ?></td>
                                            <td class="text-center"><?php echo getStatusBadge($p['status']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Histórico de Turmas -->
                <div class="info-card fade-in">
                    <div class="card-header-custom">
                        <i class="fas fa-history"></i> Histórico de Turmas
                    </div>
                    <div class="card-body-custom">
                        <?php if (empty($historico_turmas)): ?>
                            <p class="text-muted text-center py-4">Nenhum histórico de turmas</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table-custom">
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
                                            <td class="text-start"><?php echo $hist['turma_ano'] . 'ª ' . $hist['turma_nome']; ?></td>
                                            <td class="text-center"><?php echo ucfirst($hist['turma_turno']); ?></td>
                                            <td class="text-center"><?php echo formatarData($hist['created_at']); ?></td>
                                            <td class="text-center">
                                                <?php if ($hist['status'] == 'ativa'): ?>
                                                    <span class="badge-status badge-aprovado">Ativa</span>
                                                <?php else: ?>
                                                    <span class="badge-status badge-pendente">Concluída</span>
                                                <?php endif; ?>
                                            </td>
                                        </table>
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
    <script>
        // Animações ao scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };
        
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.info-card, .profile-header').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>