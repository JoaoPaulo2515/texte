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
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_letivos)) {
    $anos_letivos = [['id' => 1, 'ano' => date('Y')]];
}
if ($ano_letivo_id == 0) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

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
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
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

// ============================================
// FUNÇÃO PARA CALCULAR MÉDIA COM AS REGRAS DE EXAME
// ============================================
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
$disciplinas_notas = [];
$resumo_geral = [
    'total_disciplinas' => 0,
    'aprovadas' => 0,
    'exame' => 0,
    'reprovadas' => 0,
    'aprovado_geral' => false
];

if ($aluno_id > 0) {
    // Buscar informações do aluno
    $sql_aluno = "SELECT e.id, e.nome, e.matricula, e.genero, e.data_nascimento, e.bi,
                         t.id as turma_id, t.nome as turma_nome, t.ano as turma_ano, t.turno
                  FROM estudantes e
                  INNER JOIN matriculas m ON m.estudante_id = e.id
                  INNER JOIN turmas t ON t.id = m.turma_id
                  WHERE e.id = :aluno_id AND m.ano_letivo = :ano_letivo_id";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([
        ':aluno_id' => $aluno_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $aluno_info = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if ($aluno_info) {
        $is_exame_classe = isClasseExame($aluno_info['turma_ano']);
        
        // Buscar disciplinas do aluno na turma
        $sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                           FROM disciplinas d
                           INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                           WHERE pdt.turma_id = :turma_id
                           ORDER BY d.nome";
        $stmt_disciplinas = $conn->prepare($sql_disciplinas);
        $stmt_disciplinas->execute([':turma_id' => $aluno_info['turma_id']]);
        $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar notas do aluno por disciplina e trimestre
        foreach ($disciplinas as $disciplina) {
            $notas_trimestres = [];
            $medias = [];
            $is_linguagem = isLinguagem($disciplina['nome']);
            
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
                
                // APLICA AS REGRAS DE CÁLCULO
                $media_final = calcularMediaComRegras($mac, $npt, $exame_normal, $exame_oral, $exame_escrita, $is_exame_classe, $is_linguagem, $trimestre);
                
                $medias[] = $media_final;
                $status = getStatusNota($media_final);
                
                $notas_trimestres[$trimestre] = [
                    'mac' => $mac,
                    'npt' => $npt,
                    'exame_normal' => $exame_normal,
                    'exame_oral' => $exame_oral,
                    'exame_escrita' => $exame_escrita,
                    'media' => $media_final,
                    'status' => $status,
                    'is_exame_classe' => $is_exame_classe,
                    'is_linguagem' => $is_linguagem
                ];
            }
            
            // Calcular média final (média dos 3 trimestres)
            $medias_validas = array_filter($medias, function($m) { return $m !== null && $m > 0; });
            $media_final_anual = !empty($medias_validas) ? round(array_sum($medias_validas) / count($medias_validas), 2) : null;
            $situacao_final = getSituacaoFinal($medias);
            
            if ($situacao_final['texto'] == 'Aprovado') {
                $resumo_geral['aprovadas']++;
            } elseif ($situacao_final['texto'] == 'Exame Final') {
                $resumo_geral['exame']++;
            } else {
                $resumo_geral['reprovadas']++;
            }
            
            $disciplinas_notas[] = [
                'id' => $disciplina['id'],
                'nome' => $disciplina['nome'],
                'codigo' => $disciplina['codigo'],
                'notas' => $notas_trimestres,
                'media_final_anual' => $media_final_anual,
                'situacao_final' => $situacao_final,
                'is_exame_classe' => $is_exame_classe,
                'is_linguagem' => $is_linguagem
            ];
        }
        
        $resumo_geral['total_disciplinas'] = count($disciplinas_notas);
        $resumo_geral['aprovado_geral'] = ($resumo_geral['reprovadas'] == 0);
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
        
        .boletim-header {
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
        
        .table-notas th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-notas td {
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
            .boletim-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .table-notas th {
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
            <h2><i class="fas fa-graduation-cap"></i> Histórico de Notas</h2>
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
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_id == $ano['id'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
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
                <div class="col-md-4">
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
                <div class="col-md-2">
                    <label class="form-label fw-bold">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Histórico -->
        <?php if ($aluno_info && !empty($disciplinas_notas)): ?>
        
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
                <div class="col-md-4 mt-2">
                    <strong><i class="fas fa-school"></i> Turma:</strong><br>
                    <?php echo $aluno_info['turma_ano'] . 'ª ' . htmlspecialchars($aluno_info['turma_nome']) . ' (' . ucfirst($aluno_info['turno']) . ')'; ?>
                </div>
                <div class="col-md-4 mt-2">
                    <strong><i class="fas fa-id-card"></i> BI:</strong><br>
                    <?php echo htmlspecialchars($aluno_info['bi'] ?: '---'); ?>
                </div>
                <div class="col-md-4 mt-2">
                    <strong><i class="fas fa-chalkboard-user"></i> Ano Letivo:</strong><br>
                    <?php echo $anos_letivos[array_search($ano_letivo_id, array_column($anos_letivos, 'id'))]['ano']; ?>
                </div>
            </div>
        </div>
        
        <!-- Tabela de Notas por Disciplina -->
        <div class="table-responsive">
            <table class="table table-bordered table-notas">
                <thead>
                    <tr>
                        <th rowspan="2" width="20%">Disciplina</th>
                        <th colspan="5">1º Trimestre</th>
                        <th colspan="5">2º Trimestre</th>
                        <th colspan="5">3º Trimestre</th>
                        <th rowspan="2" width="10%">Média Final</th>
                        <th rowspan="2" width="12%">Situação</th>
                    </tr>
                    <tr>
                        <!-- 1º Trimestre -->
                        <th width="8%">MAC</th>
                        <th width="8%">NPT</th>
                        <th width="8%">Exame</th>
                        <th width="8%">Média</th>
                        <th width="8%">Status</th>
                        <!-- 2º Trimestre -->
                        <th width="8%">MAC</th>
                        <th width="8%">NPT</th>
                        <th width="8%">Exame</th>
                        <th width="8%">Média</th>
                        <th width="8%">Status</th>
                        <!-- 3º Trimestre -->
                        <th width="8%">MAC</th>
                        <th width="8%">NPT</th>
                        <th width="8%">Exame</th>
                        <th width="8%">Média</th>
                        <th width="8%">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disciplinas_notas as $disciplina): ?>
                    <tr>
                        <td class="text-start">
                            <strong><?php echo htmlspecialchars($disciplina['nome']); ?></strong>
                            <?php if ($disciplina['codigo']): ?>
                                <br><small class="text-muted"><?php echo $disciplina['codigo']; ?></small>
                            <?php endif; ?>
                            <?php if ($disciplina['is_exame_classe']): ?>
                                <br><span class="badge bg-warning text-dark">Classe de Exame</span>
                            <?php endif; ?>
                            <?php if ($disciplina['is_linguagem'] && $disciplina['is_exame_classe']): ?>
                                <br><span class="badge bg-info">Língua</span>
                            <?php endif; ?>
                        </td>
                        
                        <!-- 1º Trimestre -->
                        <?php $nota1 = $disciplina['notas'][1]; ?>
                        <td class="nota-cell"><?php echo $nota1['mac'] !== null ? number_format($nota1['mac'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell"><?php echo $nota1['npt'] !== null ? number_format($nota1['npt'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell">
                            <?php
                            if ($disciplina['is_exame_classe'] && $disciplina['is_linguagem']) {
                                echo $nota1['exame_oral'] !== null ? number_format($nota1['exame_oral'], 1, ',', '.') : '---';
                            } elseif ($disciplina['is_exame_classe']) {
                                echo $nota1['exame_normal'] !== null ? number_format($nota1['exame_normal'], 1, ',', '.') : '---';
                            } else {
                                echo '---';
                            }
                            ?>
                        </td>
                        <td class="nota-cell"><strong><?php echo $nota1['media'] !== null ? number_format($nota1['media'], 1, ',', '.') : '---'; ?></strong></td>
                        <td class="<?php echo $nota1['status']['classe']; ?>">
                            <i class="fas <?php echo $nota1['status']['icone']; ?>"></i> <?php echo $nota1['status']['texto']; ?>
                        </td>
                        
                        <!-- 2º Trimestre -->
                        <?php $nota2 = $disciplina['notas'][2]; ?>
                        <td class="nota-cell"><?php echo $nota2['mac'] !== null ? number_format($nota2['mac'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell"><?php echo $nota2['npt'] !== null ? number_format($nota2['npt'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell">
                            <?php
                            if ($disciplina['is_exame_classe'] && $disciplina['is_linguagem']) {
                                echo $nota2['exame_oral'] !== null ? number_format($nota2['exame_oral'], 1, ',', '.') : '---';
                            } elseif ($disciplina['is_exame_classe']) {
                                echo $nota2['exame_normal'] !== null ? number_format($nota2['exame_normal'], 1, ',', '.') : '---';
                            } else {
                                echo '---';
                            }
                            ?>
                        </td>
                        <td class="nota-cell"><strong><?php echo $nota2['media'] !== null ? number_format($nota2['media'], 1, ',', '.') : '---'; ?></strong></td>
                        <td class="<?php echo $nota2['status']['classe']; ?>">
                            <i class="fas <?php echo $nota2['status']['icone']; ?>"></i> <?php echo $nota2['status']['texto']; ?>
                        </td>
                        
                        <!-- 3º Trimestre -->
                        <?php $nota3 = $disciplina['notas'][3]; ?>
                        <td class="nota-cell"><?php echo $nota3['mac'] !== null ? number_format($nota3['mac'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell"><?php echo $nota3['npt'] !== null ? number_format($nota3['npt'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell">
                            <?php
                            if ($disciplina['is_exame_classe'] && $disciplina['is_linguagem']) {
                                $oral = $nota3['exame_oral'];
                                $escrita = $nota3['exame_escrita'];
                                if ($oral !== null && $escrita !== null) {
                                    echo number_format($oral, 1, ',', '.') . ' / ' . number_format($escrita, 1, ',', '.');
                                } elseif ($oral !== null) {
                                    echo number_format($oral, 1, ',', '.') . ' / ---';
                                } elseif ($escrita !== null) {
                                    echo '--- / ' . number_format($escrita, 1, ',', '.');
                                } else {
                                    echo '---';
                                }
                            } elseif ($disciplina['is_exame_classe']) {
                                echo $nota3['exame_normal'] !== null ? number_format($nota3['exame_normal'], 1, ',', '.') : '---';
                            } else {
                                echo '---';
                            }
                            ?>
                        </td>
                        <td class="nota-cell"><strong><?php echo $nota3['media'] !== null ? number_format($nota3['media'], 1, ',', '.') : '---'; ?></strong></td>
                        <td class="<?php echo $nota3['status']['classe']; ?>">
                            <i class="fas <?php echo $nota3['status']['icone']; ?>"></i> <?php echo $nota3['status']['texto']; ?>
                        </td>
                        
                        <!-- Média Final e Situação -->
                        <td class="media-final <?php echo $disciplina['situacao_final']['classe']; ?>">
                            <?php echo $disciplina['media_final_anual'] !== null ? number_format($disciplina['media_final_anual'], 1, ',', '.') : '---'; ?>
                        </td>
                        <td class="<?php echo $disciplina['situacao_final']['classe']; ?>">
                            <i class="fas <?php echo $disciplina['situacao_final']['icone']; ?>"></i> <?php echo $disciplina['situacao_final']['texto']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Resumo do Aluno -->
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-book fa-2x text-primary mb-2"></i>
                    <h3><?php echo $resumo_geral['total_disciplinas']; ?></h3>
                    <small>Total de Disciplinas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                    <h3 class="text-success"><?php echo $resumo_geral['aprovadas']; ?></h3>
                    <small>Disciplinas Aprovadas</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                    <h3 class="text-warning"><?php echo $resumo_geral['exame']; ?></h3>
                    <small>Disciplinas em Exame</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="resumo-card">
                    <i class="fas fa-times-circle fa-2x text-danger mb-2"></i>
                    <h3 class="text-danger"><?php echo $resumo_geral['reprovadas']; ?></h3>
                    <small>Disciplinas Reprovadas</small>
                </div>
            </div>
        </div>
        
        <!-- Situação Final do Aluno -->
        <div class="alert alert-<?php echo $resumo_geral['aprovado_geral'] ? 'success' : 'warning'; ?> text-center mt-4">
            <h4>
                <i class="fas <?php echo $resumo_geral['aprovado_geral'] ? 'fa-trophy' : 'fa-exclamation-circle'; ?>"></i>
                Situação Final: 
                <?php echo $resumo_geral['aprovado_geral'] ? 'APROVADO' : 'EM EXAME / REPROVADO'; ?>
            </h4>
            <?php if (!$resumo_geral['aprovado_geral'] && $resumo_geral['exame'] > 0): ?>
                <p class="mb-0">O aluno terá que realizar exame nas disciplinas pendentes.</p>
            <?php elseif (!$resumo_geral['aprovado_geral'] && $resumo_geral['reprovadas'] > 0): ?>
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
                    <small>Diretor Pedagógico</small>
                </div>
            </div>
            <div class="col-md-4 text-center">
                <div class="border-top pt-2" style="width: 80%; margin: 0 auto;">
                    <small>Encarregado de Educação</small>
                </div>
            </div>
        </div>
        
        <div class="text-center text-muted mt-4 small no-print">
            <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        </div>
        
        <?php elseif ($aluno_id > 0 && empty($disciplinas_notas)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhuma nota encontrada para este aluno no ano letivo selecionado.
            </div>
        <?php elseif ($turma_id > 0 && $aluno_id == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione um aluno para visualizar o Histórico.
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma e um aluno para visualizar o Histórico.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>