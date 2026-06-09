<?php
// escola/relatorios/historico_notas.php - Histórico de Notas do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS DA TURMA SELECIONADA
// ============================================
$alunos_turma = [];
if ($turma_id > 0) {
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id AND m.status = 'ativa'
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function isClasseExame($ano_turma) {
    $classes_exame = [6, 9, 12];
    return in_array($ano_turma, $classes_exame);
}

function isLinguagem($disciplina_nome) {
    $linguagens = ['Português', 'Inglês', 'Língua Portuguesa', 'Língua Inglesa', 'Portuguese', 'English'];
    $disciplina_lower = strtolower($disciplina_nome);
    foreach ($linguagens as $ling) {
        if (strpos($disciplina_lower, strtolower($ling)) !== false) {
            return true;
        }
    }
    return false;
}

function getStatusNota($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'text-secondary', 'icone' => 'fa-minus-circle'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'text-success', 'icone' => 'fa-check-circle'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'text-warning', 'icone' => 'fa-exclamation-triangle'];
    return ['texto' => 'Reprovado', 'classe' => 'text-danger', 'icone' => 'fa-times-circle'];
}

function getSituacaoFinal($medias_trimestres) {
    foreach ($medias_trimestres as $media) {
        if ($media !== null && $media > 0 && $media < 10) {
            return ['texto' => 'Reprovado', 'classe' => 'text-danger', 'icone' => 'fa-times-circle'];
        }
    }
    
    $tem_exame = false;
    foreach ($medias_trimestres as $media) {
        if ($media !== null && $media > 0 && $media >= 10 && $media < 14) {
            $tem_exame = true;
        }
    }
    
    if ($tem_exame) {
        return ['texto' => 'Exame Final', 'classe' => 'text-warning', 'icone' => 'fa-exclamation-triangle'];
    }
    
    return ['texto' => 'Aprovado', 'classe' => 'text-success', 'icone' => 'fa-check-circle'];
}

// Função para calcular média com regras de exame
function calcularMediaComRegras($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe, $is_linguagem, $trimestre) {
    // Calcular média do bimestre (MAC + NPT)
    $media_bimestre = null;
    $valores = [];
    if ($mac !== null) $valores[] = $mac;
    if ($npt !== null) $valores[] = $npt;
    $media_bimestre = !empty($valores) ? array_sum($valores) / count($valores) : null;
    
    // Se não for classe de exame, retorna apenas a média do bimestre
    if (!$is_exame_classe) {
        return $media_bimestre;
    }
    
    // Se for 3º trimestre e classe de exame, aplica regra de exame
    if ($trimestre == 3) {
        if ($is_linguagem) {
            // Média do exame para línguas (Oral + Escrito)
            $media_exame = null;
            $valores_exame = [];
            if ($exame_oral !== null) $valores_exame[] = $exame_oral;
            if ($exame_escrita !== null) $valores_exame[] = $exame_escrita;
            $media_exame = !empty($valores_exame) ? array_sum($valores_exame) / count($valores_exame) : null;
            
            // Fórmula: 40% média bimestre + 60% média exame
            if ($media_bimestre !== null && $media_exame !== null) {
                return ($media_bimestre * 0.4) + ($media_exame * 0.6);
            }
            return $media_bimestre !== null ? $media_bimestre : $media_exame;
        } else {
            // Disciplina normal com exame normal
            if ($media_bimestre !== null && $exame_normal !== null) {
                // Fórmula: 40% média bimestre + 60% exame normal
                return ($media_bimestre * 0.4) + ($exame_normal * 0.6);
            }
            return $media_bimestre !== null ? $media_bimestre : $exame_normal;
        }
    }
    
    // Para 1º e 2º trimestre em classe de exame, sem exame
    return $media_bimestre;
}

// ============================================
// BUSCAR DADOS DO ALUNO SE SELECIONADO
// ============================================
$aluno_info = null;
$historico_por_ano = [];
$resumo_geral = [
    'total_anos' => 0,
    'total_disciplinas_cursadas' => 0,
    'total_aprovadas' => 0,
    'total_exame' => 0,
    'total_reprovadas' => 0,
    'media_geral_geral' => 0,
    'aprovado_geral' => true
];

if ($aluno_id > 0) {
    // Buscar informações básicas do aluno
    $sql_aluno = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento, e.bi, e.pai_nome, e.mae_nome
                  FROM estudantes e
                  WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([
        ':aluno_id' => $aluno_id,
        ':escola_id' => $escola_id
    ]);
    $aluno_info = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno_info) {
        // Buscar todas as matrículas do aluno (histórico por ano)
        $sql_matriculas = "SELECT m.id, m.ano_letivo, m.data_matricula, m.status as matricula_status,
                                  a.ano as ano_letivo, a.data_inicio, a.data_fim,
                                  t.id as turma_id, t.nome as turma_nome, t.ano as turma_ano, t.turno
                           FROM matriculas m
                           INNER JOIN ano_letivo a ON a.id = m.ano_letivo
                           INNER JOIN turmas t ON t.id = m.turma_id
                           WHERE m.estudante_id = :aluno_id
                           ORDER BY a.ano ASC";
        $stmt_matriculas = $conn->prepare($sql_matriculas);
        $stmt_matriculas->execute([':aluno_id' => $aluno_id]);
        $matriculas = $stmt_matriculas->fetchAll(PDO::FETCH_ASSOC);
        
        $resumo_geral['total_anos'] = count($matriculas);
        $soma_medias_gerais = 0;
        $total_medias = 0;
        $total_aprovadas_geral = 0;
        $total_exame_geral = 0;
        $total_reprovadas_geral = 0;
        
        foreach ($matriculas as $matricula) {
            $ano_letivo = $matricula['ano_letivo'];
            $turma_id_ano = $matricula['turma_id'];
            $ano_letivo_id = $matricula['ano_letivo'];
            $is_exame_classe = isClasseExame($matricula['turma_ano']);
            
            // Buscar disciplinas cursadas neste ano
            $sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                               FROM disciplinas d
                               INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                               WHERE pdt.turma_id = :turma_id
                               ORDER BY d.nome";
            $stmt_disciplinas = $conn->prepare($sql_disciplinas);
            $stmt_disciplinas->execute([':turma_id' => $turma_id_ano]);
            $disciplinas_ano = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
            
            $disciplinas_notas = [];
            $soma_notas_ano = 0;
            $total_notas_ano = 0;
            $aprovadas = 0;
            $exame = 0;
            $reprovadas = 0;
            
            foreach ($disciplinas_ano as $disciplina) {
                $is_linguagem = isLinguagem($disciplina['nome']);
                $medias_trimestres = [];
                $detalhes_trimestres = [];
                
                for ($trimestre = 1; $trimestre <= 3; $trimestre++) {
                    $sql_notas = "SELECT mac, npt, exame_normal, exame_oral, exame_escrito, media_final
                                 FROM notas 
                                 WHERE estudante_id = :aluno_id 
                                 AND disciplina_id = :disciplina_id 
                                 AND bimestre = :trimestre
                                 AND ano_letivo_id = :ano_letivo_id";
                    $stmt_notas = $conn->prepare($sql_notas);
                    $stmt_notas->execute([
                        ':aluno_id' => $aluno_id,
                        ':disciplina_id' => $disciplina['id'],
                        ':trimestre' => $trimestre,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                    $nota_data = $stmt_notas->fetch(PDO::FETCH_ASSOC);
                    
                    $mac = $nota_data ? (float)$nota_data['mac'] : null;
                    $npt = $nota_data ? (float)$nota_data['npt'] : null;
                    $exame_normal = $nota_data ? (float)$nota_data['exame_normal'] : null;
                    $exame_oral = $nota_data ? (float)$nota_data['exame_oral'] : null;
                    $exame_escrita = $nota_data ? (float)$nota_data['exame_escrito'] : null;
                    
                    // Aplicar regras de cálculo
                    $media_final = calcularMediaComRegras($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe, $is_linguagem, $trimestre);
                    
                    $medias_trimestres[$trimestre] = $media_final;
                    $status = getStatusNota($media_final);
                    
                    $detalhes_trimestres[$trimestre] = [
                        'mac' => $mac,
                        'npt' => $npt,
                        'exame_normal' => $exame_normal,
                        'exame_oral' => $exame_oral,
                        'exame_escrita' => $exame_escrita,
                        'media' => $media_final,
                        'status' => $status
                    ];
                }
                
                // Calcular média anual (média dos 3 trimestres)
                $medias_validas = array_filter($medias_trimestres, function($m) { return $m !== null && $m > 0; });
                $media_anual = !empty($medias_validas) ? round(array_sum($medias_validas) / count($medias_validas), 2) : null;
                
                if ($media_anual !== null && $media_anual > 0) {
                    $soma_notas_ano += $media_anual;
                    $total_notas_ano++;
                    
                    if ($media_anual >= 14) {
                        $aprovadas++;
                        $total_aprovadas_geral++;
                    } elseif ($media_anual >= 10) {
                        $exame++;
                        $total_exame_geral++;
                    } else {
                        $reprovadas++;
                        $total_reprovadas_geral++;
                    }
                }
                
                $status_anual = getStatusNota($media_anual);
                $situacao_final = getSituacaoFinal($medias_trimestres);
                
                $disciplinas_notas[] = [
                    'nome' => $disciplina['nome'],
                    'codigo' => $disciplina['codigo'],
                    'trimestres' => $detalhes_trimestres,
                    'media_anual' => $media_anual,
                    'status_anual' => $status_anual,
                    'situacao_final' => $situacao_final,
                    'is_exame_classe' => $is_exame_classe,
                    'is_linguagem' => $is_linguagem
                ];
            }
            
            $media_geral_ano = $total_notas_ano > 0 ? round($soma_notas_ano / $total_notas_ano, 2) : 0;
            $soma_medias_gerais += $media_geral_ano;
            $total_medias++;
            
            $situacao_ano = 'Aprovado';
            $situacao_classe = 'success';
            if ($reprovadas > 0) {
                $situacao_ano = 'Reprovado';
                $situacao_classe = 'danger';
                $resumo_geral['aprovado_geral'] = false;
            } elseif ($exame > 0) {
                $situacao_ano = 'Exame';
                $situacao_classe = 'warning';
            }
            
            $resumo_geral['total_disciplinas_cursadas'] += count($disciplinas_notas);
            
            $historico_por_ano[] = [
                'ano_letivo' => $ano_letivo,
                'ano_letivo_id' => $ano_letivo_id,
                'turma' => $matricula['turma_ano'] . 'ª ' . $matricula['turma_nome'],
                'turno' => ucfirst($matricula['turno']),
                'data_matricula' => $matricula['data_matricula'],
                'disciplinas' => $disciplinas_notas,
                'media_geral' => $media_geral_ano,
                'aprovadas' => $aprovadas,
                'exame' => $exame,
                'reprovadas' => $reprovadas,
                'situacao' => $situacao_ano,
                'situacao_classe' => $situacao_classe,
                'is_exame_classe' => $is_exame_classe
            ];
        }
        
        $resumo_geral['total_aprovadas'] = $total_aprovadas_geral;
        $resumo_geral['total_exame'] = $total_exame_geral;
        $resumo_geral['total_reprovadas'] = $total_reprovadas_geral;
        $resumo_geral['media_geral_geral'] = $total_medias > 0 ? round($soma_medias_gerais / $total_medias, 2) : 0;
    }
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Notas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .historico-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .info-aluno {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .ano-card {
            background: white;
            border-radius: 10px;
            margin-bottom: 25px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .ano-header {
            background: #006B3E;
            color: white;
            padding: 12px 20px;
        }
        
        .ano-header h4 {
            margin: 0;
            display: inline-block;
        }
        
        .ano-badge {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .table-historico th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-historico td {
            text-align: center;
            vertical-align: middle;
        }
        
        .nota-cell {
            font-weight: bold;
            font-size: 14px;
        }
        
        .media-final {
            font-size: 16px;
            font-weight: bold;
        }
        
        .resumo-card {
            background: white;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .btn-print {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
        }
        
        .btn-print:hover {
            background: #138496;
        }
        
        .info-regras {
            background: #e8f5e9;
            border-left: 4px solid #006B3E;
            padding: 10px 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 12px;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            .historico-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .ano-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .table-historico th {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-history"></i> Histórico de Notas</h2>
            <div class="no-print">
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Imprimir Histórico
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                        <option value="0">Selecione uma turma...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-bold">Aluno</label>
                    <select name="aluno_id" class="form-select" id="aluno_id" onchange="this.form.submit()">
                        <option value="0">Selecione um aluno...</option>
                        <?php foreach ($alunos_turma as $aluno): ?>
                        <option value="<?php echo $aluno['id']; ?>" <?php echo $aluno_id == $aluno['id'] ? 'selected' : ''; ?>>
                            <?php echo $aluno['matricula'] . ' - ' . $aluno['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Histórico do Aluno -->
        <?php if ($aluno_info && !empty($historico_por_ano)): ?>
        
        <!-- Cabeçalho -->
        <div class="historico-header">
            <div class="text-center">
                <h3><?php echo htmlspecialchars($escola_info['nome']); ?></h3>
                <h4>HISTÓRICO ESCOLAR</h4>
                <p>Registro de Notas por Ano Letivo</p>
            </div>
        </div>
        
        <!-- Informações do Aluno -->
        <div class="info-aluno">
            <div class="row">
                <div class="col-md-4">
                    <strong><i class="fas fa-user"></i> Nome:</strong><br>
                    <?php echo htmlspecialchars($aluno_info['nome']); ?>
                </div>
                <div class="col-md-3">
                    <strong><i class="fas fa-id-card"></i> Matrícula:</strong><br>
                    <?php echo htmlspecialchars($aluno_info['matricula']); ?>
                </div>
                <div class="col-md-3">
                    <strong><i class="fas fa-calendar-alt"></i> Data Nascimento:</strong><br>
                    <?php echo $aluno_info['data_nascimento'] ? date('d/m/Y', strtotime($aluno_info['data_nascimento'])) : '---'; ?>
                </div>
                <div class="col-md-2">
                    <strong><i class="fas fa-venus-mars"></i> Gênero:</strong><br>
                    <?php echo $aluno_info['genero'] == 'masculino' ? 'Masculino' : 'Feminino'; ?>
                </div>
                <div class="col-md-6 mt-2">
                    <strong><i class="fas fa-user-tie"></i> Nome do Pai:</strong><br>
                    <?php echo htmlspecialchars($aluno_info['pai_nome'] ?: '---'); ?>
                </div>
                <div class="col-md-6 mt-2">
                    <strong><i class="fas fa-user-tie"></i> Nome da Mãe:</strong><br>
                    <?php echo htmlspecialchars($aluno_info['mae_nome'] ?: '---'); ?>
                </div>
            </div>
        </div>
        
        <!-- Regras de Avaliação (apenas para classes de exame) -->
        <?php foreach ($historico_por_ano as $ano): ?>
            <?php if ($ano['is_exame_classe']): ?>
                <div class="info-regras no-print">
                    <i class="fas fa-info-circle text-success"></i> 
                    <strong>Regras de Avaliação - Classe de Exame (<?php echo $ano['ano_letivo']; ?>):</strong>
                    A nota final do 3º Trimestre é composta por 40% da média (MAC+NPT) e 60% do exame.
                    <?php if (!empty(array_filter($ano['disciplinas'], function($d) { return $d['is_linguagem']; }))): ?>
                        Para disciplinas de Língua (Português/Inglês), o exame é composto por Prova Oral + Prova Escrita.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
        
        <!-- Histórico por Ano -->
        <?php foreach ($historico_por_ano as $ano): ?>
        <div class="ano-card">
            <div class="ano-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h4><i class="fas fa-calendar-alt"></i> Ano Letivo: <?php echo $ano['ano_letivo']; ?></h4>
                        <small>Turma: <?php echo $ano['turma']; ?> - <?php echo $ano['turno']; ?></small>
                        <?php if ($ano['is_exame_classe']): ?>
                            <span class="ms-2 badge bg-warning text-dark">Classe de Exame</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <span class="ano-badge">
                            <i class="fas fa-chart-line"></i> Média Geral: <?php echo number_format($ano['media_geral'], 1, ',', '.'); ?>
                        </span>
                        <span class="ano-badge ms-2">
                            <i class="fas fa-flag-checkered"></i> Situação: <?php echo $ano['situacao']; ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="p-3">
                <div class="table-responsive">
                    <table class="table table-bordered table-historico">
                        <thead>
                            <tr>
                                <th rowspan="2" width="22%">Disciplina</th>
                                <th colspan="4">1º Trimestre</th>
                                <th colspan="4">2º Trimestre</th>
                                <th colspan="<?php echo $ano['is_exame_classe'] ? '5' : '4'; ?>">3º Trimestre</th>
                                <th rowspan="2" width="10%">Média Anual</th>
                                <th rowspan="2" width="12%">Situação</th>
                            </tr>
                            <tr>
                                <th>MAC</th>
                                <th>NPT</th>
                                <th>Média</th>
                                <th>Status</th>
                                <th>MAC</th>
                                <th>NPT</th>
                                <th>Média</th>
                                <th>Status</th>
                                <th>MAC</th>
                                <th>NPT</th>
                                <?php if ($ano['is_exame_classe']): ?>
                                    <th>Exame</th>
                                <?php endif; ?>
                                <th>Média</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ano['disciplinas'] as $disciplina): ?>
                            <tr>
                                <td class="text-start">
                                    <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong>
                                    <?php if ($disciplina['codigo']): ?>
                                        <br><small class="text-muted"><?php echo $disciplina['codigo']; ?></small>
                                    <?php endif; ?>
                                    <?php if ($disciplina['is_linguagem'] && $ano['is_exame_classe']): ?>
                                        <br><span class="badge bg-info">Língua</span>
                                    <?php endif; ?>
                                </td>
                                
                                <!-- 1º Trimestre -->
                                <td class="nota-cell"><?php echo $disciplina['trimestres'][1]['mac'] !== null ? number_format($disciplina['trimestres'][1]['mac'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><?php echo $disciplina['trimestres'][1]['npt'] !== null ? number_format($disciplina['trimestres'][1]['npt'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><strong><?php echo $disciplina['trimestres'][1]['media'] !== null ? number_format($disciplina['trimestres'][1]['media'], 1, ',', '.') : '---'; ?></strong></td>
                                <td class="<?php echo $disciplina['trimestres'][1]['status']['classe']; ?>">
                                    <i class="fas <?php echo $disciplina['trimestres'][1]['status']['icone']; ?>"></i>
                                </td>
                                
                                <!-- 2º Trimestre -->
                                <td class="nota-cell"><?php echo $disciplina['trimestres'][2]['mac'] !== null ? number_format($disciplina['trimestres'][2]['mac'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><?php echo $disciplina['trimestres'][2]['npt'] !== null ? number_format($disciplina['trimestres'][2]['npt'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><strong><?php echo $disciplina['trimestres'][2]['media'] !== null ? number_format($disciplina['trimestres'][2]['media'], 1, ',', '.') : '---'; ?></strong></td>
                                <td class="<?php echo $disciplina['trimestres'][2]['status']['classe']; ?>">
                                    <i class="fas <?php echo $disciplina['trimestres'][2]['status']['icone']; ?>"></i>
                                </td>
                                
                                <!-- 3º Trimestre -->
                                <td class="nota-cell"><?php echo $disciplina['trimestres'][3]['mac'] !== null ? number_format($disciplina['trimestres'][3]['mac'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><?php echo $disciplina['trimestres'][3]['npt'] !== null ? number_format($disciplina['trimestres'][3]['npt'], 1, ',', '.') : '---'; ?></td>
                                <?php if ($ano['is_exame_classe']): ?>
                                    <td class="nota-cell">
                                        <?php
                                        if ($disciplina['is_linguagem']) {
                                            $oral = $disciplina['trimestres'][3]['exame_oral'];
                                            $escrita = $disciplina['trimestres'][3]['exame_escrita'];
                                            if ($oral !== null && $escrita !== null) {
                                                echo number_format($oral, 1, ',', '.') . ' / ' . number_format($escrita, 1, ',', '.');
                                            } elseif ($oral !== null) {
                                                echo number_format($oral, 1, ',', '.') . ' / ---';
                                            } elseif ($escrita !== null) {
                                                echo '--- / ' . number_format($escrita, 1, ',', '.');
                                            } else {
                                                echo '---';
                                            }
                                        } else {
                                            echo $disciplina['trimestres'][3]['exame_normal'] !== null ? number_format($disciplina['trimestres'][3]['exame_normal'], 1, ',', '.') : '---';
                                        }
                                        ?>
                                    </td>
                                <?php endif; ?>
                                <td class="nota-cell"><strong><?php echo $disciplina['trimestres'][3]['media'] !== null ? number_format($disciplina['trimestres'][3]['media'], 1, ',', '.') : '---'; ?></strong></td>
                                <td class="<?php echo $disciplina['trimestres'][3]['status']['classe']; ?>">
                                    <i class="fas <?php echo $disciplina['trimestres'][3]['status']['icone']; ?>"></i>
                                </td>
                                
                                <!-- Média Anual e Situação -->
                                <td class="media-final <?php echo $disciplina['situacao_final']['classe']; ?>">
                                    <?php echo $disciplina['media_anual'] !== null ? number_format($disciplina['media_anual'], 1, ',', '.') : '---'; ?>
                                </td>
                                <td class="<?php echo $disciplina['situacao_final']['classe']; ?>">
                                    <i class="fas <?php echo $disciplina['situacao_final']['icone']; ?>"></i> <?php echo $disciplina['situacao_final']['texto']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td class="text-end fw-bold">Resumo do Ano:</td>
                                <td colspan="4">✅ Aprovadas: <?php echo $ano['aprovadas']; ?></td>
                                <td colspan="4">⚠️ Exame: <?php echo $ano['exame']; ?></td>
                                <td colspan="<?php echo $ano['is_exame_classe'] ? '5' : '4'; ?>">❌ Reprovadas: <?php echo $ano['reprovadas']; ?></td>
                                <td colspan="2">📊 Média Geral: <?php echo number_format($ano['media_geral'], 1, ',', '.'); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Resumo Geral do Aluno -->
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-calendar-alt fa-2x text-primary mb-2"></i>
                    <h3><?php echo $resumo_geral['total_anos']; ?></h3>
                    <small>Anos Letivos</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-book fa-2x text-info mb-2"></i>
                    <h3><?php echo $resumo_geral['total_disciplinas_cursadas']; ?></h3>
                    <small>Disciplinas Cursadas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-chart-line fa-2x text-success mb-2"></i>
                    <h3><?php echo number_format($resumo_geral['media_geral_geral'], 1, ',', '.'); ?></h3>
                    <small>Média Geral</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-trophy fa-2x text-warning mb-2"></i>
                    <h3 class="text-<?php echo $resumo_geral['aprovado_geral'] ? 'success' : 'danger'; ?>">
                        <?php echo $resumo_geral['aprovado_geral'] ? 'APROVADO' : 'REPROVADO'; ?>
                    </h3>
                    <small>Situação Final</small>
                </div>
            </div>
        </div>
        
        <!-- Detalhes Finais -->
        <div class="row mt-3">
            <div class="col-md-4">
                <div class="resumo-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <h3 class="text-success"><?php echo $resumo_geral['total_aprovadas']; ?></h3>
                    <small>Disciplinas Aprovadas</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="resumo-card">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <h3 class="text-warning"><?php echo $resumo_geral['total_exame']; ?></h3>
                    <small>Disciplinas em Exame</small>
                </div>
            </div>
            <div class="col-md-4">
                <div class="resumo-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <h3 class="text-danger"><?php echo $resumo_geral['total_reprovadas']; ?></h3>
                    <small>Disciplinas Reprovadas</small>
                </div>
            </div>
        </div>
        
        <!-- Situação Final do Aluno -->
        <div class="alert alert-<?php echo $resumo_geral['aprovado_geral'] ? 'success' : 'warning'; ?> text-center mt-4">
            <h4>
                <i class="fas <?php echo $resumo_geral['aprovado_geral'] ? 'fa-trophy' : 'fa-exclamation-circle'; ?>"></i>
                Situação Final do Aluno: 
                <?php echo $resumo_geral['aprovado_geral'] ? 'APROVADO' : 'EM EXAME / REPROVADO'; ?>
            </h4>
            <?php if (!$resumo_geral['aprovado_geral'] && $resumo_geral['total_exame'] > 0): ?>
                <p class="mb-0">O aluno terá que realizar exame nas disciplinas pendentes.</p>
            <?php elseif (!$resumo_geral['aprovado_geral'] && $resumo_geral['total_reprovadas'] > 0): ?>
                <p class="mb-0">O aluno foi reprovado nas disciplinas indicadas.</p>
            <?php endif; ?>
        </div>
        
        <!-- Assinaturas -->
        <div class="row mt-5 no-print">
            <div class="col-md-4 text-center">
                <div class="border-top pt-2" style="width: 80%; margin: 0 auto;">
                    <small>Coordenador Pedagógico</small>
                </div>
            </div>
            <div class="col-md-4 text-center">
                <div class="border-top pt-2" style="width: 80%; margin: 0 auto;">
                    <small>Secretário Escolar</small>
                </div>
            </div>
            <div class="col-md-4 text-center">
                <div class="border-top pt-2" style="width: 80%; margin: 0 auto;">
                    <small>Diretor Pedagógico</small>
                </div>
            </div>
        </div>
        
        <div class="text-center text-muted mt-4 small no-print">
            <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
            <p>
                <?php echo htmlspecialchars($escola_info['endereco'] ?? ''); ?> | 
                Tel: <?php echo htmlspecialchars($escola_info['telefone'] ?? ''); ?> | 
                Email: <?php echo htmlspecialchars($escola_info['email'] ?? ''); ?>
            </p>
        </div>
        
        <?php elseif ($aluno_id > 0 && empty($historico_por_ano)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum histórico encontrado para este aluno.
            </div>
        <?php elseif ($turma_id > 0 && $aluno_id == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione um aluno para visualizar o histórico.
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma e um aluno para visualizar o histórico.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>