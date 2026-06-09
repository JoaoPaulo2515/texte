<?php
// escola/pedagogico/gerar_boletim.php - Gerar Boletim de Notas

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR TURMAS
// ============================================
$sql_turmas = "
    SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PARÂMETROS DE FILTRO
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$imprimir = isset($_GET['imprimir']) ? (int)$_GET['imprimir'] : 0;

// Se não tem ano letivo selecionado, pegar o ano ativo
if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$alunos = [];
if ($turma_id > 0 && $ano_letivo_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.data_nascimento,
            e.foto,
            t.nome as turma_nome,
            t.ano as turma_ano,
            tr.nome as turno_nome
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        INNER JOIN turmas t ON t.id = m.turma_id
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE m.turma_id = :turma_id 
        AND m.status = 'ativa'
        AND m.ano_letivo = :ano_letivo_id
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function getLimiteAprovacao($classe_ano) {
    return ($classe_ano <= 6) ? 5 : 10;
}

function getEscalaMax($classe_ano) {
    return ($classe_ano <= 6) ? 10 : 20;
}

function getStatusText($status) {
    switch($status) {
        case 'aprovado': return 'Aprovado';
        case 'recuperacao': return 'Recuperação';
        case 'reprovado': return 'Reprovado';
        default: return 'Pendente';
    }
}

function getStatusClass($status) {
    switch($status) {
        case 'aprovado': return 'status-aprovado';
        case 'recuperacao': return 'status-recuperacao';
        case 'reprovado': return 'status-reprovado';
        default: return 'status-pendente';
    }
}

// ============================================
// GERAR BOLETIM EM HTML PARA IMPRESSÃO
// ============================================
if ($imprimir == 1 && $aluno_id > 0 && $turma_id > 0 && $ano_letivo_id > 0) {
    // Buscar dados do aluno
    $sql_aluno = "
        SELECT e.*, t.nome as turma_nome, t.ano as turma_ano, tr.nome as turno_nome
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        INNER JOIN turmas t ON t.id = m.turma_id
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE e.id = :aluno_id AND m.turma_id = :turma_id AND m.ano_letivo = :ano_letivo_id
        LIMIT 1
    ";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([
        ':aluno_id' => $aluno_id,
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $aluno_boletim = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if (!$aluno_boletim) {
        die('Aluno não encontrado');
    }
    
    $classe_ano = $aluno_boletim['turma_ano'];
    $limite_aprovacao = getLimiteAprovacao($classe_ano);
    $escala_max = getEscalaMax($classe_ano);
    
    // Buscar disciplinas da turma e notas do aluno
    $sql_notas = "
        SELECT 
            d.id,
            d.nome as disciplina_nome,
            d.codigo,
            COALESCE(n1.media_final, 0) as nota_1,
            COALESCE(n2.media_final, 0) as nota_2,
            COALESCE(n3.media_final, 0) as nota_3,
            COALESCE(n4.media_final, 0) as nota_4,
            ROUND((COALESCE(n1.media_final, 0) + COALESCE(n2.media_final, 0) + COALESCE(n3.media_final, 0) + COALESCE(n4.media_final, 0)) / 4, 1) as media_final
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        LEFT JOIN notas n1 ON n1.disciplina_id = d.id AND n1.estudante_id = :aluno_id AND n1.bimestre = 1 AND n1.ano_letivo_id = :ano_letivo_id
        LEFT JOIN notas n2 ON n2.disciplina_id = d.id AND n2.estudante_id = :aluno_id1 AND n2.bimestre = 2 AND n2.ano_letivo_id = :ano_letivo_id1
        LEFT JOIN notas n3 ON n3.disciplina_id = d.id AND n3.estudante_id = :aluno_id2 AND n3.bimestre = 3 AND n3.ano_letivo_id = :ano_letivo_id2
        LEFT JOIN notas n4 ON n4.disciplina_id = d.id AND n4.estudante_id = :aluno_id3 AND n4.bimestre = 4 AND n4.ano_letivo_id = :ano_letivo_id3
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':aluno_id' => $aluno_id,
        ':aluno_id1' => $aluno_id,
        ':aluno_id2' => $aluno_id,
        ':aluno_id3' => $aluno_id,
        ':ano_letivo_id' => $ano_letivo_id,
        ':ano_letivo_id1' => $ano_letivo_id,
        ':ano_letivo_id2' => $ano_letivo_id,
        ':ano_letivo_id3' => $ano_letivo_id,
        ':turma_id' => $turma_id
    ]);
    $disciplinas_boletim = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular média geral
    $soma_medias = 0;
    $total_disciplinas = 0;
    foreach ($disciplinas_boletim as $disc) {
        if ($disc['media_final'] > 0) {
            $soma_medias += $disc['media_final'];
            $total_disciplinas++;
        }
    }
    $media_geral = $total_disciplinas > 0 ? round($soma_medias / $total_disciplinas, 1) : 0;
    
    $status_geral = 'pendente';
    if ($media_geral >= $limite_aprovacao) {
        $status_geral = 'aprovado';
    } elseif ($media_geral >= $limite_aprovacao * 0.7) {
        $status_geral = 'recuperacao';
    } elseif ($media_geral > 0) {
        $status_geral = 'reprovado';
    }
    
    // Buscar ano letivo
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
    
    // Gerar HTML para impressão
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Boletim - <?php echo htmlspecialchars($aluno_boletim['nome']); ?></title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                font-size: 12px;
                padding: 20px;
            }
            .boletim-container {
                max-width: 1000px;
                margin: 0 auto;
                background: white;
            }
            .header {
                text-align: center;
                margin-bottom: 25px;
                border-bottom: 2px solid #1e5799;
                padding-bottom: 15px;
            }
            .header h1 {
                color: #1e5799;
                font-size: 24px;
                margin-bottom: 5px;
            }
            .header p {
                margin: 3px 0;
                color: #555;
            }
            .info-aluno {
                background: #f8f9fa;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
                border: 1px solid #ddd;
            }
            .info-aluno table {
                width: 100%;
            }
            .info-aluno td {
                padding: 5px;
            }
            .table-notas {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            .table-notas th {
                background: #1e5799;
                color: white;
                padding: 8px;
                text-align: center;
                font-size: 11px;
            }
            .table-notas td {
                border: 1px solid #ddd;
                padding: 6px;
                text-align: center;
            }
            .table-notas td.text-start {
                text-align: left;
            }
            .footer {
                margin-top: 25px;
                text-align: center;
                font-size: 10px;
                color: #666;
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }
            .media-geral {
                text-align: center;
                padding: 12px;
                background: #e8f4fd;
                border-radius: 8px;
                margin-top: 15px;
            }
            .status-aprovado { color: #27ae60; font-weight: bold; }
            .status-recuperacao { color: #f39c12; font-weight: bold; }
            .status-reprovado { color: #e74c3c; font-weight: bold; }
            .status-pendente { color: #7f8c8d; font-weight: bold; }
            .nota-alta { color: #27ae60; font-weight: bold; }
            .nota-baixa { color: #e74c3c; font-weight: bold; }
            
            @media print {
                body {
                    padding: 0;
                    margin: 0;
                }
                .no-print {
                    display: none;
                }
                .boletim-container {
                    margin: 0;
                    padding: 10px;
                }
            }
        </style>
    </head>
    <body>
        <div class="boletim-container">
            <div class="header">
                <h1><?php echo htmlspecialchars($escola['nome']); ?></h1>
                <p><?php echo htmlspecialchars($escola['endereco'] ?? ''); ?></p>
                <p>Tel: <?php echo htmlspecialchars($escola['telefone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($escola['email'] ?? ''); ?></p>
                <h3>BOLETIM DE NOTAS</h3>
                <p>Ano Letivo: <?php echo $ano_letivo_ano; ?></p>
            </div>
            
            <div class="info-aluno">
                <table>
                    <tr>
                        <td width="50%"><strong>Nome:</strong> <?php echo htmlspecialchars($aluno_boletim['nome']); ?></td>
                        <td width="50%"><strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno_boletim['matricula']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Turma:</strong> <?php echo $aluno_boletim['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno_boletim['turma_nome']); ?></td>
                        <td><strong>Turno:</strong> <?php echo ucfirst($aluno_boletim['turno_nome'] ?? ''); ?></td>
                    </tr>
                    <tr>
                        <td><strong>BI:</strong> <?php echo htmlspecialchars($aluno_boletim['bi'] ?? 'N/A'); ?></td>
                        <td><strong>Data Nascimento:</strong> <?php echo date('d/m/Y', strtotime($aluno_boletim['data_nascimento'] ?? '')); ?></td>
                    </tr>
                </table>
            </div>
            
            <table class="table-notas">
                <thead>
                    <tr>
                        <th>Disciplina</th>
                        <th>1º Bim</th>
                        <th>2º Bim</th>
                        <th>3º Bim</th>
                        <th>4º Bim</th>
                        <th>Média Final</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($disciplinas_boletim as $disc): 
                        $nota_1 = $disc['nota_1'] > 0 ? number_format($disc['nota_1'], 1) : '-';
                        $nota_2 = $disc['nota_2'] > 0 ? number_format($disc['nota_2'], 1) : '-';
                        $nota_3 = $disc['nota_3'] > 0 ? number_format($disc['nota_3'], 1) : '-';
                        $nota_4 = $disc['nota_4'] > 0 ? number_format($disc['nota_4'], 1) : '-';
                        $media_final = $disc['media_final'] > 0 ? number_format($disc['media_final'], 1) : '-';
                        
                        $status_disc = 'pendente';
                        if ($disc['media_final'] >= $limite_aprovacao) {
                            $status_disc = 'aprovado';
                        } elseif ($disc['media_final'] >= $limite_aprovacao * 0.7) {
                            $status_disc = 'recuperacao';
                        } elseif ($disc['media_final'] > 0) {
                            $status_disc = 'reprovado';
                        }
                        
                        $nota_1_class = ($disc['nota_1'] >= $limite_aprovacao) ? 'nota-alta' : (($disc['nota_1'] > 0) ? 'nota-baixa' : '');
                        $nota_2_class = ($disc['nota_2'] >= $limite_aprovacao) ? 'nota-alta' : (($disc['nota_2'] > 0) ? 'nota-baixa' : '');
                        $nota_3_class = ($disc['nota_3'] >= $limite_aprovacao) ? 'nota-alta' : (($disc['nota_3'] > 0) ? 'nota-baixa' : '');
                        $nota_4_class = ($disc['nota_4'] >= $limite_aprovacao) ? 'nota-alta' : (($disc['nota_4'] > 0) ? 'nota-baixa' : '');
                        $media_class = ($disc['media_final'] >= $limite_aprovacao) ? 'nota-alta' : (($disc['media_final'] > 0) ? 'nota-baixa' : '');
                    ?>
                        <tr>
                            <td class="text-start"><strong><?php echo htmlspecialchars($disc['disciplina_nome']); ?></strong></td>
                            <td class="<?php echo $nota_1_class; ?>"><?php echo $nota_1; ?></td>
                            <td class="<?php echo $nota_2_class; ?>"><?php echo $nota_2; ?></td>
                            <td class="<?php echo $nota_3_class; ?>"><?php echo $nota_3; ?></td>
                            <td class="<?php echo $nota_4_class; ?>"><?php echo $nota_4; ?></td>
                            <td class="<?php echo $media_class; ?>"><strong><?php echo $media_final; ?></strong></td>
                            <td><span class="status-<?php echo $status_disc; ?>"><?php echo getStatusText($status_disc); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="media-geral">
                <strong>MÉDIA GERAL:</strong> <?php echo number_format($media_geral, 1); ?> pontos &nbsp;&nbsp;&nbsp;
                <strong>STATUS:</strong> <span class="status-<?php echo $status_geral; ?>"><?php echo getStatusText($status_geral); ?></span>
                <br>
                <small>Escala de Avaliação: 0 a <?php echo $escala_max; ?> pontos | Mínimo para aprovação: <?php echo $limite_aprovacao; ?> pontos</small>
            </div>
            
            <div class="footer">
                <p>Documento gerado eletronicamente pelo Sistema de Gestão Escolar (SIGE)</p>
                <p>Data de emissão: <?php echo date('d/m/Y H:i:s'); ?></p>
            </div>
            
            <div class="text-center mt-3 no-print">
                <button onclick="window.print()" style="background: #1e5799; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer;">
                    <i class="fas fa-print"></i> Imprimir / Salvar como PDF
                </button>
                <a href="gerar_boletim.php?turma_id=<?php echo $turma_id; ?>&ano_letivo_id=<?php echo $ano_letivo_id; ?>" style="background: #6c757d; color: white; text-decoration: none; padding: 10px 20px; border-radius: 5px; margin-left: 10px;">
                    Voltar
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$caminho_base = '/sige_Plataforma/uploads/alunos/';
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Boletim - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 20px;
            border-radius: 12px 12px 0 0;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header-title h1 { font-size: 24px; margin-bottom: 5px; }
        .header-title p { opacity: 0.9; font-size: 14px; }
        
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .btn-voltar:hover {
            background: rgba(255,255,255,0.3);
            color: white;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body { padding: 20px; }
        
        .filtros-row {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filtro-group {
            flex: 1;
            min-width: 180px;
        }
        
        .filtro-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #2c3e50;
            font-size: 13px;
        }
        
        .filtro-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            cursor: pointer;
            background: white;
        }
        
        .btn-filtrar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 10px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .table-alunos {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-alunos th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
        }
        
        .table-alunos td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-alunos tr:hover {
            background: #f8f9fa;
        }
        
        .aluno-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .aluno-foto {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #1e5799;
            background: #f0f2f5;
        }
        
        .aluno-foto-placeholder {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        
        .btn-boletim {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 12px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-boletim:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0,0,0,0.2);
            color: white;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-info { background: #d4e6f1; color: #1e5799; border-left: 4px solid #1e5799; }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-alunos { font-size: 11px; }
            .table-alunos th, .table-alunos td { padding: 6px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1><i class="fas fa-file-pdf me-2"></i> Gerar Boletim</h1>
            <p>Visualize e imprima boletins de notas dos alunos</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">
                ← Voltar ao Dashboard
            </a>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card">
        <div class="card-header">
            <i class="fas fa-filter me-2"></i> Filtros
        </div>
        <div class="card-body">
            <form method="GET" action="" id="formFiltros">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Ano Letivo</label>
                        <select name="ano_letivo_id" class="filtro-select" required>
                            <option value="">Selecione</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select" required>
                            <option value="">Selecione</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno_nome']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>&nbsp;</label>
                        <button type="submit" class="btn-filtrar w-100">
                            <i class="fas fa-search me-1"></i> Buscar Alunos
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma_id > 0 && $ano_letivo_id > 0 && !empty($alunos)): ?>
        <!-- Lista de Alunos -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-users me-2"></i> Alunos da Turma
                <span class="badge bg-light text-dark ms-2"><?php echo count($alunos); ?> alunos</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-responsive">
                    <table class="table-alunos">
                        <thead>
                            <tr>
                                <th>Aluno</th>
                                <th>Matrícula</th>
                                <th>BI</th>
                                <th>Turma</th>
                                <th>Boletim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $aluno): 
                                $inicial = strtoupper(substr($aluno['nome'], 0, 1));
                                $foto_path = !empty($aluno['foto']) ? $caminho_base . $aluno['foto'] : '';
                                $tem_foto = !empty($aluno['foto']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $foto_path);
                            ?>
                                <tr>
                                    <td class="text-start">
                                        <div class="aluno-info">
                                            <?php if ($tem_foto && $foto_path): ?>
                                                <img src="<?php echo $foto_path; ?>" class="aluno-foto" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                <div class="aluno-foto-placeholder" style="display: none;"><?php echo $inicial; ?></div>
                                            <?php else: ?>
                                                <div class="aluno-foto-placeholder"><?php echo $inicial; ?></div>
                                            <?php endif; ?>
                                            <div>
                                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                    <td><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                                    <td><?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?> (<?php echo ucfirst($aluno['turno_nome'] ?? ''); ?>)</td>
                                    <td>
                                        <a href="gerar_boletim.php?turma_id=<?php echo $turma_id; ?>&ano_letivo_id=<?php echo $ano_letivo_id; ?>&aluno_id=<?php echo $aluno['id']; ?>&imprimir=1" class="btn-boletim" target="_blank">
                                            <i class="fas fa-print"></i> Gerar Boletim
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif ($turma_id > 0 && empty($alunos)): ?>
        <div class="alert alert-info">Nenhum aluno encontrado para esta turma.</div>
    <?php endif; ?>
</div>

<script>
    // Auto-submit quando selecionar turma
    document.querySelector('select[name="ano_letivo_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
    document.querySelector('select[name="turma_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>