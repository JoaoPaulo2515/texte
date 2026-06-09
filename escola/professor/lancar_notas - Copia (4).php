<?php
// escola/professor/lancar_notas.php - Lançar Notas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// FILTROS
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;

// ============================================
// DECLARAR VARIÁVEIS PARA EVITAR ERROS
// ============================================
$success = '';
$error = '';
$is_ensino_fundamental = false;
$is_classe_exame = false;
$is_disciplina_lingua = false;
$classe_ano = 0;
$disciplina_nome = '';
$turma_info = null;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_letivo_atual = $ano_letivo['ano'] ?? date('Y') . '/' . (date('Y') + 1);

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id, t.nome, t.ano, t.turno, t.sala
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id, d.nome, d.codigo
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR ALUNOS DA TURMA
// ============================================
$alunos = [];
$total_alunos = 0;
$notas_existentes = [];

if ($turma_id > 0 && $disciplina_id > 0) {
    // Buscar dados da turma
    $sql_turma_info = "SELECT id, nome, ano FROM turmas WHERE id = :turma_id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':turma_id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    $classe_ano = $turma_info['ano'] ?? 0;
    
    // Buscar disciplina
    $sql_disc_info = "SELECT nome FROM disciplinas WHERE id = :disciplina_id";
    $stmt_disc_info = $conn->prepare($sql_disc_info);
    $stmt_disc_info->execute([':disciplina_id' => $disciplina_id]);
    $disc_info = $stmt_disc_info->fetch(PDO::FETCH_ASSOC);
    $disciplina_nome = $disc_info['nome'] ?? '';
    
    // Determinar regras
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    $is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || stripos($disciplina_nome, 'inglês') !== false);
    $is_ensino_fundamental = ($classe_ano <= 6);
    
    // Buscar alunos
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.foto,
            e.status as aluno_status
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    $total_alunos = count($alunos);
    
    // Buscar notas existentes
    $sql_notas_existentes = "
        SELECT 
            estudante_id,
            mac,
            npt,
            exame_normal,
            exame_recurso,
            exame_especial,
            exame_oral,
            exame_escrito,
            media_final,
            status
        FROM notas
        WHERE disciplina_id = :disciplina_id 
        AND bimestre = :bimestre 
        AND ano_letivo_id = :ano_letivo_id
    ";
    $stmt_notas = $conn->prepare($sql_notas_existentes);
    $stmt_notas->execute([
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
        $notas_existentes[$row['estudante_id']] = $row;
    }
}

// ============================================
// CALCULAR ESTATÍSTICAS DINÂMICAS
// ============================================
function calcularEstatisticas($alunos, $notas_existentes, $is_ensino_fundamental) {
    $total_aprovados = 0;
    $total_recuperacao = 0;
    $total_reprovados = 0;
    $soma_medias = 0;
    $count_com_nota = 0;
    $maior_nota = 0;
    
    $limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
    $limite_recuperacao = $is_ensino_fundamental ? 5 : 10;
    
    foreach ($alunos as $aluno) {
        $nota = $notas_existentes[$aluno['id']] ?? null;
        $media = $nota['media_final'] ?? 0;
        
        if ($media > 0) {
            if ($media > $limite_aprovacao) $total_aprovados++;
            elseif ($media == $limite_recuperacao) $total_recuperacao++;
            elseif ($media < $limite_recuperacao) $total_reprovados++;
            
            $soma_medias += $media;
            $count_com_nota++;
            if ($media > $maior_nota) $maior_nota = $media;
        }
    }
    
    $media_geral = $count_com_nota > 0 ? round($soma_medias / $count_com_nota, 1) : 0;
    $percentual_aprovacao = count($alunos) > 0 ? round(($total_aprovados / count($alunos)) * 100, 1) : 0;
    
    return [
        'total_aprovados' => $total_aprovados,
        'total_recuperacao' => $total_recuperacao,
        'total_reprovados' => $total_reprovados,
        'media_geral' => $media_geral,
        'maior_nota' => $maior_nota,
        'percentual_aprovacao' => $percentual_aprovacao
    ];
}

$stats = calcularEstatisticas($alunos, $notas_existentes, $is_ensino_fundamental ?? false);

// ============================================
// PROCESSAR FORMULÁRIO (AJAX)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_notas_ajax'])) {
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $bimestre_post = (int)$_POST['bimestre'];
    $notas_data = json_decode($_POST['notas_data'], true);
    
    // Buscar informações da turma para regras
    $sql_info = "SELECT ano FROM turmas WHERE id = :turma_id";
    $stmt_info = $conn->prepare($sql_info);
    $stmt_info->execute([':turma_id' => $turma_id_post]);
    $turma_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
    $classe_ano = $turma_info['ano'] ?? 0;
    $is_ensino_fundamental = ($classe_ano <= 6);
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    
    $sql_disc = "SELECT nome FROM disciplinas WHERE id = :disciplina_id";
    $stmt_disc = $conn->prepare($sql_disc);
    $stmt_disc->execute([':disciplina_id' => $disciplina_id_post]);
    $disc_info = $stmt_disc->fetch(PDO::FETCH_ASSOC);
    $is_disciplina_lingua = (stripos($disc_info['nome'] ?? '', 'português') !== false || stripos($disc_info['nome'] ?? '', 'inglês') !== false);
    
    $limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
    
    try {
        $conn->beginTransaction();
        
        if (!empty($notas_data)) {
            foreach ($notas_data as $estudante_id => $dados) {
                $mac = isset($dados['mac']) && $dados['mac'] !== '' ? floatval($dados['mac']) : null;
                $npt = isset($dados['npt']) && $dados['npt'] !== '' ? floatval($dados['npt']) : null;
                $exame_normal = isset($dados['exame_normal']) && $dados['exame_normal'] !== '' ? floatval($dados['exame_normal']) : null;
                $exame_recurso = isset($dados['exame_recurso']) && $dados['exame_recurso'] !== '' ? floatval($dados['exame_recurso']) : null;
                $exame_especial = isset($dados['exame_especial']) && $dados['exame_especial'] !== '' ? floatval($dados['exame_especial']) : null;
                $exame_oral = isset($dados['exame_oral']) && $dados['exame_oral'] !== '' ? floatval($dados['exame_oral']) : null;
                $exame_escrito = isset($dados['exame_escrito']) && $dados['exame_escrito'] !== '' ? floatval($dados['exame_escrito']) : null;
                
                $media_parcial = (floatval($mac) + floatval($npt)) / 2;
                $media_final = $media_parcial;
                
                // Regras de cálculo
                if ($bimestre_post == 3 && $is_classe_exame && $is_disciplina_lingua) {
                    if ($exame_oral !== null && $exame_escrito !== null && $exame_oral > 0 && $exame_escrito > 0) {
                        $media_exame = ($exame_oral + $exame_escrito) / 2;
                        $media_final = ($media_parcial * 0.4) + ($media_exame * 0.6);
                    } elseif ($exame_recurso !== null && $exame_recurso > 0) {
                        $media_final = ($media_parcial * 0.4) + ($exame_recurso * 0.6);
                    }
                } elseif ($bimestre_post == 3 && $is_classe_exame) {
                    if ($exame_normal !== null && $exame_normal > 0) {
                        $media_final = ($media_parcial * 0.4) + ($exame_normal * 0.6);
                    } elseif ($exame_recurso !== null && $exame_recurso > 0) {
                        $media_final = ($media_parcial * 0.4) + ($exame_recurso * 0.6);
                    }
                } elseif ($bimestre_post == 3 && $exame_normal !== null && $exame_normal > 0) {
                    $media_final = ($media_parcial + $exame_normal) / 2;
                } elseif ($bimestre_post == 3 && $exame_recurso !== null && $exame_recurso > 0) {
                    $media_final = ($media_parcial + $exame_recurso) / 2;
                }
                
                // Definir status
                if ($media_final > $limite_aprovacao) {
                    $status = 'aprovado';
                } elseif ($media_final == $limite_aprovacao) {
                    $status = 'recuperacao';
                } else {
                    $status = 'reprovado';
                }
                
                // Verificar se já existe registro
                $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':estudante_id' => $estudante_id,
                    ':disciplina_id' => $disciplina_id_post,
                    ':bimestre' => $bimestre_post,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                
                if ($stmt_check->fetch()) {
                    // UPDATE
                    $sql = "UPDATE notas SET 
                                mac = :mac, 
                                npt = :npt, 
                                exame_normal = :exame_normal, 
                                exame_recurso = :exame_recurso,
                                exame_especial = :exame_especial, 
                                exame_oral = :exame_oral, 
                                exame_escrito = :exame_escrito,
                                media_final = :media_final, 
                                status = :status, 
                                updated_at = NOW() 
                            WHERE estudante_id = :estudante_id 
                            AND disciplina_id = :disciplina_id 
                            AND bimestre = :bimestre 
                            AND ano_letivo_id = :ano_letivo_id";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':mac' => $mac,
                        ':npt' => $npt,
                        ':exame_normal' => $exame_normal,
                        ':exame_recurso' => $exame_recurso,
                        ':exame_especial' => $exame_especial,
                        ':exame_oral' => $exame_oral,
                        ':exame_escrito' => $exame_escrito,
                        ':media_final' => $media_final,
                        ':status' => $status,
                        ':estudante_id' => $estudante_id,
                        ':disciplina_id' => $disciplina_id_post,
                        ':bimestre' => $bimestre_post,
                        ':ano_letivo_id' => $ano_letivo_id
                    ]);
                } else {
                    // INSERT
                    $sql = "INSERT INTO notas (
                                estudante_id, disciplina_id, professor_id, bimestre, 
                                mac, npt, exame_normal, exame_recurso, exame_especial,
                                exame_oral, exame_escrito, media_final, status, ano_letivo_id, escola_id
                            ) VALUES (
                                :estudante_id, :disciplina_id, :professor_id, :bimestre,
                                :mac, :npt, :exame_normal, :exame_recurso, :exame_especial,
                                :exame_oral, :exame_escrito, :media_final, :status, :ano_letivo_id, :escola_id
                            )";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':estudante_id' => $estudante_id,
                        ':disciplina_id' => $disciplina_id_post,
                        ':professor_id' => $professor_id,
                        ':bimestre' => $bimestre_post,
                        ':mac' => $mac,
                        ':npt' => $npt,
                        ':exame_normal' => $exame_normal,
                        ':exame_recurso' => $exame_recurso,
                        ':exame_especial' => $exame_especial,
                        ':exame_oral' => $exame_oral,
                        ':exame_escrito' => $exame_escrito,
                        ':media_final' => $media_final,
                        ':status' => $status,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':escola_id' => $escola_id
                    ]);
                }
            }
        }
        
        $conn->commit();
        
        // Recalcular estatísticas
        $sql_alunos_count = "SELECT COUNT(DISTINCT m.estudante_id) as total 
                            FROM matriculas m 
                            WHERE m.turma_id = :turma_id AND m.status = 'ativa'";
        $stmt_alunos_count = $conn->prepare($sql_alunos_count);
        $stmt_alunos_count->execute([':turma_id' => $turma_id_post]);
        $total_alunos = $stmt_alunos_count->fetch(PDO::FETCH_ASSOC)['total'];
        
        $total_aprovados = 0;
        $total_recuperacao = 0;
        $total_reprovados = 0;
        $soma_medias = 0;
        $maior_nota = 0;
        
        $sql_stats = "SELECT media_final, status FROM notas 
                      WHERE disciplina_id = :disciplina_id 
                      AND bimestre = :bimestre 
                      AND ano_letivo_id = :ano_letivo_id";
        $stmt_stats = $conn->prepare($sql_stats);
        $stmt_stats->execute([
            ':disciplina_id' => $disciplina_id_post,
            ':bimestre' => $bimestre_post,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        
        while ($row = $stmt_stats->fetch(PDO::FETCH_ASSOC)) {
            if ($row['status'] == 'aprovado') $total_aprovados++;
            elseif ($row['status'] == 'recuperacao') $total_recuperacao++;
            elseif ($row['status'] == 'reprovado') $total_reprovados++;
            
            $soma_medias += $row['media_final'];
            if ($row['media_final'] > $maior_nota) $maior_nota = $row['media_final'];
        }
        
        $media_geral = $total_alunos > 0 ? round($soma_medias / $total_alunos, 1) : 0;
        $percentual_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;
        
        echo json_encode([
            'success' => true, 
            'message' => 'Notas salvas com sucesso!',
            'stats' => [
                'total_aprovados' => $total_aprovados,
                'total_recuperacao' => $total_recuperacao,
                'total_reprovados' => $total_reprovados,
                'media_geral' => $media_geral,
                'maior_nota' => $maior_nota,
                'percentual_aprovacao' => $percentual_aprovacao
            ]
        ]);
        exit;
        
    } catch (PDOException $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Lançar Notas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Estilos da página - mantidos todos os originais */
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 15px;
            padding: 20px 25px;
            margin-bottom: 25px;
        }
        .page-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }
        .page-header p {
            margin: 5px 0 0;
            opacity: 0.8;
            font-size: 0.85rem;
        }
        .filter-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .filter-label {
            font-weight: 600;
            font-size: 0.8rem;
            text-transform: uppercase;
            color: #666;
            margin-bottom: 5px;
        }
        .filter-value {
            font-weight: 700;
            font-size: 1rem;
            color: #006B3E;
        }
        .btn-action {
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 0.75rem;
            margin: 2px;
        }
        .btn-group-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }
        .notas-table th {
            background: #f8f9fa;
            text-align: center;
            font-size: 0.75rem;
            padding: 10px 5px;
        }
        .notas-table td {
            vertical-align: middle;
            text-align: center;
            font-size: 0.8rem;
        }
        .media-input {
            width: 70px;
            text-align: center;
            font-size: 0.75rem;
            padding: 4px;
        }
        .foto-mini {
            width: 35px;
            height: 35px;
            object-fit: cover;
            border-radius: 50%;
        }
        .badge-aprovado { background: #28a745; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .badge-reprovado { background: #dc3545; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .badge-recuperacao { background: #ffc107; color: #333; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .badge-pendente { background: #6c757d; color: white; padding: 4px 10px; border-radius: 20px; font-size: 11px; }
        .btn-salvar {
            background: #006B3E;
            color: white;
            border: none;
            padding: 10px 30px;
            border-radius: 30px;
            font-weight: 600;
        }
        .btn-salvar:hover {
            background: #004d2d;
            color: white;
        }
        .stats-card {
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            border-radius: 15px;
            padding: 15px 12px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
            border: 1px solid rgba(0,107,62,0.1);
        }
        .stats-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        .stats-number { font-size: 32px; font-weight: bold; }
        .stats-label { font-size: 11px; color: #666; margin-top: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
        .stats-icon { font-size: 35px; margin-bottom: 10px; }
        
        .nota-excelente { background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%) !important; }
        .nota-bom { background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%) !important; }
        .nota-regular { background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%) !important; }
        .nota-ruim { background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%) !important; }
        
        .btn-help {
            background: #17a2b8;
            color: white;
            border: none;
        }
        .btn-help:hover { background: #138496; color: white; }
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .help-step {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: transform 0.2s;
        }
        .help-step:hover { transform: translateX(5px); background: #e8f5e9; }
        .help-number {
            width: 40px;
            height: 40px;
            background: #006B3E;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            margin-right: 15px;
        }
        .help-content { flex: 1; }
        .help-content h6 { margin-bottom: 5px; color: #006B3E; }
        .help-content p { margin-bottom: 0; font-size: 13px; color: #666; }
        .auto-save-indicator {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 999;
            display: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            font-weight: 500;
        }
        .auto-save-indicator i { margin-right: 5px; }
        .regras-info {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 4px solid #006B3E;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 12px;
        }
        .regras-info i { margin-right: 8px; color: #006B3E; }

        .nota-excelente { 
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%) !important; 
        }
        .nota-bom { 
            background: linear-gradient(135deg, #d1ecf1 0%, #bee5eb 100%) !important; 
        }
        .nota-regular { 
            background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%) !important; 
        }
        .nota-ruim { 
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%) !important; 
        }

        .table tbody tr.nota-excelente td,
        .table tbody tr.nota-bom td,
        .table tbody tr.nota-regular td,
        .table tbody tr.nota-ruim td {
            background: inherit;
        }

        .table tbody tr {
            transition: background 0.3s ease;
        }

        .table tbody tr.nota-excelente td {
            background-color: #d4edda !important;
        }

        .table tbody tr.nota-bom td {
            background-color: #d1ecf1 !important;
        }

        .table tbody tr.nota-regular td {
            background-color: #fff3cd !important;
        }

        .table tbody tr.nota-ruim td {
            background-color: #f8d7da !important;
        }
    </style>
</head>
<body>
    <!-- INCLUIR O MENU CENTRALIZADO -->
    <?php include 'includes/menu_professor.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header">
            <h2><i class="fas fa-pen-alt"></i> Lançamento de Notas</h2>
            <p>Gerencie as notas dos alunos de forma rápida e intuitiva. Selecione a turma e disciplina para visualizar os alunos.</p>
        </div>
        
        <!-- Como funciona -->
        <div class="filter-card">
            <div class="row">
                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="filter-label">ANO LETIVO</span>
                            <div class="filter-value"><?php echo $ano_letivo_atual; ?></div>
                        </div>
                        <div class="btn-group-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-action" id="btnBuscarAlunos" onclick="aplicarFiltros()">
                                <i class="fas fa-search"></i> Buscar Alunos
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-action" id="btnCalcularTodas" onclick="calcularTodas()">
                                <i class="fas fa-calculator"></i> Calcular Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-success btn-action" id="btnSalvarTodas" onclick="salvarNotas()">
                                <i class="fas fa-save"></i> Salvar Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-info btn-action" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                                <i class="fas fa-question-circle"></i> Ajuda
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-card">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="filter-label">TURMA</label>
                    <select name="turma_id" id="turma_id" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="">Selecionar...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-2 btn-group-actions">
                        <button type="button" class="btn btn-sm btn-outline-info btn-action" onclick="window.open('historico_completo.php?turma_id='+$('#turma_id').val(), '_blank')">
                            <i class="fas fa-history"></i> Histórico Completo
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-danger btn-action" onclick="gerarPDF()">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-success btn-action" onclick="gerarExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary btn-action" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Recarregar
                        </button>
                        <button type="button" class="btn btn-sm btn-warning btn-action" onclick="resetarFormulario()">
                            <i class="fas fa-undo-alt"></i> Resetar
                        </button>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="filter-label">DISCIPLINA</label>
                    <select name="disciplina_id" id="disciplina_id" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="">Selecionar...</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="mt-2 btn-group-actions">
                        <button type="button" class="btn btn-sm btn-outline-success btn-action" onclick="gerarExcel()">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-action" onclick="window.print()">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                        <button type="button" class="btn btn-sm btn-secondary btn-action" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Recarregar
                        </button>
                        <button type="button" class="btn btn-sm btn-warning btn-action" onclick="resetarFormulario()">
                            <i class="fas fa-undo-alt"></i> Resetar
                        </button>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="filter-label">BIMESTRE</label>
                    <select name="bimestre" id="bimestre" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Informações das Regras -->
        <div class="regras-info">
            <i class="fas fa-info-circle"></i>
            <strong>Regras de Avaliação:</strong>
            <?php if (isset($is_ensino_fundamental) && $is_ensino_fundamental): ?>
                Escala 0-10 | Aprovado: >5 | Recuperação: =5 | Reprovado: <5
            <?php else: ?>
                Escala 0-20 | Aprovado: >10 | Recuperação: =10 | Reprovado: <10
            <?php endif; ?>
            <?php if (isset($is_classe_exame) && $is_classe_exame && $bimestre == 3): ?>
                | <strong>Classe de Exame (<?php echo $classe_ano; ?>ª):</strong> Média = 40% (Média Parcial) + 60% (Exame)
            <?php endif; ?>
            <?php if (isset($is_disciplina_lingua) && $is_disciplina_lingua && $is_classe_exame && $bimestre == 3): ?>
                | <strong>Disciplina de Língua:</strong> Exame Oral + Escrito
            <?php endif; ?>
        </div>
        
        <!-- ESTATÍSTICAS DINÂMICAS COM ÍCONES -->
        <div class="row mb-4" id="statsContainer">
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-icon"><i class="fas fa-users text-primary"></i></div>
                    <div class="stats-number text-primary" id="statTotalAlunos"><?php echo $total_alunos; ?></div>
                    <div class="stats-label">Total Alunos</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-icon"><i class="fas fa-check-circle text-success"></i></div>
                    <div class="stats-number text-success" id="statAprovados"><?php echo $stats['total_aprovados']; ?></div>
                    <div class="stats-label">Aprovados</div>
                    <small id="statPercentual"><?php echo $stats['percentual_aprovacao']; ?>%</small>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-icon"><i class="fas fa-clock text-warning"></i></div>
                    <div class="stats-number text-warning" id="statRecuperacao"><?php echo $stats['total_recuperacao']; ?></div>
                    <div class="stats-label">Recuperação</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-icon"><i class="fas fa-times-circle text-danger"></i></div>
                    <div class="stats-number text-danger" id="statReprovados"><?php echo $stats['total_reprovados']; ?></div>
                    <div class="stats-label">Reprovados</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-icon"><i class="fas fa-chart-line text-info"></i></div>
                    <div class="stats-number text-info" id="statMediaGeral"><?php echo $stats['media_geral']; ?></div>
                    <div class="stats-label">Média Geral</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stats-card">
                    <div class="stats-icon"><i class="fas fa-trophy text-warning"></i></div>
                    <div class="stats-number text-warning" id="statMaiorNota"><?php echo $stats['maior_nota'] > 0 ? $stats['maior_nota'] : '-'; ?></div>
                    <div class="stats-label">Melhor Nota</div>
                </div>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Tabela de Notas -->
        <div class="filter-card">
            <!-- Informações da Turma -->
            <div class="info-turma" style="background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 20px; border-left: 4px solid #006B3E;">
                <div class="row">
                    <div class="col-md-3">
                        <small class="text-muted text-uppercase">Turma</small>
                        <div class="fw-bold">
                            <?php 
                            if ($turma_id > 0 && !empty($turmas)) {
                                foreach ($turmas as $t) {
                                    if ($t['id'] == $turma_id) {
                                        echo $t['ano'] . 'ª Classe - ' . $t['nome'];
                                        break;
                                    }
                                }
                            } else {
                                echo 'Selecione uma turma';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted text-uppercase">Turno</small>
                        <div class="fw-bold">
                            <?php 
                            if ($turma_id > 0 && !empty($turmas)) {
                                foreach ($turmas as $t) {
                                    if ($t['id'] == $turma_id) {
                                        echo ucfirst($t['turno']);
                                        break;
                                    }
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted text-uppercase">Ano Letivo</small>
                        <div class="fw-bold"><?php echo $ano_letivo_atual; ?></div>
                    </div>
                    <div class="col-md-2">
                        <small class="text-muted text-uppercase">Sala</small>
                        <div class="fw-bold">
                            <?php 
                            if ($turma_id > 0 && !empty($turmas)) {
                                foreach ($turmas as $t) {
                                    if ($t['id'] == $turma_id) {
                                        echo $t['sala'] ?: 'Não definida';
                                        break;
                                    }
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <small class="text-muted text-uppercase">Professor(a)</small>
                        <div class="fw-bold"><?php echo htmlspecialchars($professor['professor_nome']); ?></div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12">
                        <small class="text-muted text-uppercase">Data Emissão</small>
                        <div class="fw-bold"><?php echo date('d') . ' de ' . date('F') . ' de ' . date('Y'); ?></div>
                    </div>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="fas fa-table"></i> Notas dos Alunos</h5>
                <span class="badge bg-primary"><?php echo $total_alunos; ?> alunos carregados</span>
            </div>
            
            <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
            <form method="POST" id="formNotas">
                <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
                <input type="hidden" name="bimestre" value="<?php echo $bimestre; ?>">
                
                <div class="table-responsive">
                    <table class="table table-bordered notas-table">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="8%">Foto</th>
                                <th width="20%">Aluno</th>
                                <th width="10%">Matrícula</th>
                                <th width="8%">MAC<br><small>(0-10)</small></th>
                                <th width="8%">NPT<br><small>(0-10)</small></th>
                                <?php if ($bimestre == 3 && ($is_classe_exame ?? false) && !($is_disciplina_lingua ?? false)): ?>
                                <th width="8%">Exame Normal<br><small>(0-20)</small></th>
                                <th width="8%">Exame Recurso<br><small>(0-20)</small></th>
                                <?php elseif ($bimestre == 3 && ($is_classe_exame ?? false) && ($is_disciplina_lingua ?? false)): ?>
                                <th width="8%">Exame Oral<br><small>(0-20)</small></th>
                                <th width="8%">Exame Escrito<br><small>(0-20)</small></th>
                                <th width="8%">Exame Recurso<br><small>(0-20)</small></th>
                                <?php elseif ($bimestre == 3): ?>
                                <th width="8%">Exame Normal<br><small>(0-20)</small></th>
                                <?php endif; ?>
                                <th width="8%">Média</th>
                                <th width="10%">Status</th>
                                <th width="8%">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno):
                                $nota = $notas_existentes[$aluno['id']] ?? null;
                                $media_final = $nota['media_final'] ?? 0;
                                $classe_nota = '';
                                if ($media_final >= ($is_ensino_fundamental ?? false ? 7 : 14)) $classe_nota = 'nota-excelente';
                                elseif ($media_final >= ($is_ensino_fundamental ?? false ? 5 : 10)) $classe_nota = 'nota-bom';
                                elseif ($media_final >= ($is_ensino_fundamental ?? false ? 4 : 7)) $classe_nota = 'nota-regular';
                                elseif ($media_final > 0) $classe_nota = 'nota-ruim';
                            ?>
                            <tr class="<?php echo $classe_nota; ?>" data-aluno-id="<?php echo $aluno['id']; ?>">
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td class="text-center">
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-mini">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="foto-mini">
                                    <?php endif; ?>
                                </td>
                                <td class="text-start">
                                    <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                    <br>
                                    <small class="text-muted"><?php echo $aluno['matricula']; ?></small>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="10" 
                                           name="mac[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input nota-input" 
                                           value="<?php echo $nota['mac'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="10" 
                                           name="npt[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input nota-input" 
                                           value="<?php echo $nota['npt'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <?php if ($bimestre == 3 && ($is_classe_exame ?? false) && !($is_disciplina_lingua ?? false)): ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_normal[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_normal'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_recurso[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_recurso'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <?php elseif ($bimestre == 3 && ($is_classe_exame ?? false) && ($is_disciplina_lingua ?? false)): ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_oral[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_oral'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_escrito[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_escrito'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_recurso[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_recurso'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <?php elseif ($bimestre == 3): ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_normal[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input exame-input" 
                                           value="<?php echo $nota['exame_normal'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="autoSalvar()">
                                </td>
                                <?php endif; ?>
                                <td class="text-center">
                                    <span id="media_<?php echo $aluno['id']; ?>" class="fw-bold">
                                        <?php echo number_format($nota['media_final'] ?? 0, 1); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span id="status_<?php echo $aluno['id']; ?>" class="badge <?php 
                                        echo $nota['status'] == 'aprovado' ? 'badge-aprovado' : 
                                            ($nota['status'] == 'recuperacao' ? 'badge-recuperacao' : 
                                            ($nota['status'] == 'reprovado' ? 'badge-reprovado' : 'badge-pendente')); 
                                    ?>">
                                        <?php echo ucfirst($nota['status'] ?? 'Pendente'); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="calcularMedia(<?php echo $aluno['id']; ?>); autoSalvar()" title="Calcular média">
                                            <i class="fas fa-calculator"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-info" onclick="verHistorico(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>')" title="Ver histórico">
                                            <i class="fas fa-history"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="text-end mt-4">
                    <button type="button" class="btn btn-salvar" onclick="salvarNotas()">
                        <i class="fas fa-save"></i> Salvar Todas as Notas
                    </button>
                </div>
            </form>
            <?php elseif ($turma_id > 0 && $disciplina_id > 0): ?>
                <div class="alert alert-info text-center">
                    <i class="fas fa-info-circle"></i> Nenhum aluno encontrado nesta turma.
                </div>
            <?php elseif ($turma_id > 0): ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle"></i> Selecione uma disciplina para continuar.
                </div>
            <?php else: ?>
                <div class="alert alert-secondary text-center">
                    <i class="fas fa-filter"></i> Carregue os filtros para ver as notas.
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Como funciona o Lançamento de Notas?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6>Selecione os Filtros</h6>
                            <p>Escolha a Turma, Disciplina e o Bimestre desejado. Os campos disponíveis se adaptam automaticamente conforme as regras.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6>Escala de Avaliação</h6>
                            <p><strong>Ensino Fundamental (1ª à 6ª classe):</strong> Escala 0-10<br>
                               - Aprovado: nota > 5<br>
                               - Recuperação: nota = 5<br>
                               - Reprovado: nota < 5<br><br>
                               <strong>Ensino Médio (7ª à 12ª classe):</strong> Escala 0-20<br>
                               - Aprovado: nota > 10<br>
                               - Recuperação: nota = 10<br>
                               - Reprovado: nota < 10</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6>Cores das Notas</h6>
                            <p><span class="badge bg-success">Verde escuro</span> - Nota Excelente (≥70%)<br>
                               <span class="badge bg-info">Azul</span> - Nota Boa (≥50%)<br>
                               <span class="badge bg-warning">Amarelo</span> - Nota Regular (≥35%)<br>
                               <span class="badge bg-danger">Vermelho</span> - Nota Baixa (&lt;35%)</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">4</div>
                        <div class="help-content">
                            <h6>Regras por Bimestre</h6>
                            <p><strong>1º e 2º Bimestre:</strong> Apenas MAC e NPT. Média = (MAC + NPT) / 2<br>
                               <strong>3º Bimestre - Classe de Exame (6ª, 9ª, 12ª):</strong> Média = 40% (Média Parcial) + 60% (Exame)<br>
                               <strong>3º Bimestre - Disciplina de Língua (Classes de Exame):</strong> Média = 40% (Média Parcial) + 60% (Média Oral+Escrito)</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">5</div>
                        <div class="help-content">
                            <h6>Salvamento Automático</h6>
                            <p>Ao alterar qualquer campo, as notas são salvas automaticamente após 1,5 segundos. Um indicador verde aparece no canto inferior direito.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">6</div>
                        <div class="help-content">
                            <h6>Estatísticas Dinâmicas</h6>
                            <p>Os cards de estatísticas atualizam automaticamente sempre que as notas são salvas.</p>
                        </div>
                    </div>
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Dica:</strong> Use o botão "Calcular Todas" para recalcular todas as médias ou "Salvar Todas" para salvar todas as notas manualmente.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Histórico do Aluno -->
    <div class="modal fade" id="modalHistorico" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                    <h5 class="modal-title" id="historicoModalLabel">
                        <i class="fas fa-history"></i> Histórico do Aluno
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="historicoModalBody">
                    <div class="text-center py-5">
                        <div class="spinner-border text-primary"></div>
                        <p class="mt-2">Carregando dados do aluno...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Indicador de Auto-Salvamento -->
    <div id="autoSaveIndicator" class="auto-save-indicator">
        <i class="fas fa-save"></i> Notas salvas automaticamente!
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let autoSaveTimer = null;
        let isSaving = false;
        
        // Variáveis de configuração
        let isEnsinoFundamental = <?php echo isset($is_ensino_fundamental) && $is_ensino_fundamental ? 'true' : 'false'; ?>;
        let isClasseExame = <?php echo isset($is_classe_exame) && $is_classe_exame && $bimestre == 3 ? 'true' : 'false'; ?>;
        let isDisciplinaLingua = <?php echo isset($is_disciplina_lingua) && $is_disciplina_lingua ? 'true' : 'false'; ?>;
        let bimestreAtual = <?php echo $bimestre; ?>;
        let limiteAprovacao = isEnsinoFundamental ? 5 : 10;
        
        console.log('Configurações:', {
            isEnsinoFundamental: isEnsinoFundamental,
            isClasseExame: isClasseExame,
            isDisciplinaLingua: isDisciplinaLingua,
            bimestreAtual: bimestreAtual,
            limiteAprovacao: limiteAprovacao
        });
        
        function aplicarFiltros() {
            var turmaId = $('#turma_id').val();
            var disciplinaId = $('#disciplina_id').val();
            var bimestre = $('#bimestre').val();
            
            if (turmaId && disciplinaId) {
                window.location.href = `lancar_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else if (turmaId) {
                window.location.href = `lancar_notas.php?turma_id=${turmaId}&bimestre=${bimestre}`;
            } else {
                window.location.href = `lancar_notas.php?bimestre=${bimestre}`;
            }
        }
        
        function calcularMedia(alunoId) {
            var macInput = document.querySelector(`input[name="mac[${alunoId}]"]`);
            var nptInput = document.querySelector(`input[name="npt[${alunoId}]"]`);
            var exameNormalInput = document.querySelector(`input[name="exame_normal[${alunoId}]"]`);
            var exameRecursoInput = document.querySelector(`input[name="exame_recurso[${alunoId}]"]`);
            var exameOralInput = document.querySelector(`input[name="exame_oral[${alunoId}]"]`);
            var exameEscritoInput = document.querySelector(`input[name="exame_escrito[${alunoId}]"]`);
            
            var mac = macInput ? parseFloat(macInput.value) || 0 : 0;
            var npt = nptInput ? parseFloat(nptInput.value) || 0 : 0;
            var exameNormal = exameNormalInput ? parseFloat(exameNormalInput.value) || 0 : 0;
            var exameRecurso = exameRecursoInput ? parseFloat(exameRecursoInput.value) || 0 : 0;
            var exameOral = exameOralInput ? parseFloat(exameOralInput.value) || 0 : 0;
            var exameEscrito = exameEscritoInput ? parseFloat(exameEscritoInput.value) || 0 : 0;
            
            var media = (mac + npt) / 2;
            
            if (bimestreAtual == 3) {
                if (isClasseExame && isDisciplinaLingua) {
                    if (exameOral > 0 && exameEscrito > 0) {
                        var mediaExame = (exameOral + exameEscrito) / 2;
                        media = (media * 0.4) + (mediaExame * 0.6);
                    } else if (exameRecurso > 0) {
                        media = (media * 0.4) + (exameRecurso * 0.6);
                    }
                } else if (isClasseExame) {
                    if (exameNormal > 0) {
                        media = (media * 0.4) + (exameNormal * 0.6);
                    } else if (exameRecurso > 0) {
                        media = (media * 0.4) + (exameRecurso * 0.6);
                    }
                } else {
                    if (exameNormal > 0) {
                        media = (media + exameNormal) / 2;
                    } else if (exameRecurso > 0) {
                        media = (media + exameRecurso) / 2;
                    }
                }
            }
            
            var situacao = '';
            var badgeClass = '';
            
            if (media > limiteAprovacao) {
                situacao = 'Aprovado';
                badgeClass = 'badge-aprovado';
            } else if (media == limiteAprovacao && media > 0) {
                situacao = 'Recuperação';
                badgeClass = 'badge-recuperacao';
            } else if (media > 0 && media < limiteAprovacao) {
                situacao = 'Reprovado';
                badgeClass = 'badge-reprovado';
            } else {
                situacao = 'Pendente';
                badgeClass = 'badge-pendente';
            }
            
            var mediaSpan = document.getElementById(`media_${alunoId}`);
            if (mediaSpan) {
                mediaSpan.innerHTML = media.toFixed(1);
            }
            
            var statusSpan = document.getElementById(`status_${alunoId}`);
            if (statusSpan) {
                statusSpan.innerHTML = situacao;
                statusSpan.className = `badge ${badgeClass}`;
            }
            
            var row = statusSpan ? statusSpan.closest('tr') : null;
            if (row) {
                row.classList.remove('nota-excelente', 'nota-bom', 'nota-regular', 'nota-ruim');
                
                if (media >= (isEnsinoFundamental ? 7 : 14)) {
                    row.classList.add('nota-excelente');
                    console.log(`Aluno ${alunoId}: Nota excelente (${media}) - classe nota-excelente aplicada`);
                } else if (media >= (isEnsinoFundamental ? 5 : 10)) {
                    row.classList.add('nota-bom');
                    console.log(`Aluno ${alunoId}: Nota boa (${media}) - classe nota-bom aplicada`);
                } else if (media >= (isEnsinoFundamental ? 4 : 7)) {
                    row.classList.add('nota-regular');
                    console.log(`Aluno ${alunoId}: Nota regular (${media}) - classe nota-regular aplicada`);
                } else if (media > 0) {
                    row.classList.add('nota-ruim');
                    console.log(`Aluno ${alunoId}: Nota ruim (${media}) - classe nota-ruim aplicada`);
                }
            }
            
            return media;
        }
        
        function calcularTodas() {
            console.log('Calculando todas as médias e aplicando cores...');
            document.querySelectorAll('tr[data-aluno-id]').forEach(row => {
                var alunoId = row.getAttribute('data-aluno-id');
                if (alunoId) {
                    calcularMedia(alunoId);
                }
            });
        }
        
        function resetarFormulario() {
            document.querySelectorAll('.media-input').forEach(input => {
                input.value = '';
            });
            calcularTodas();
            autoSalvar();
        }
        
        function autoSalvar() {
            if (autoSaveTimer) clearTimeout(autoSaveTimer);
            
            var indicator = document.getElementById('autoSaveIndicator');
            if (indicator) indicator.style.display = 'block';
            
            autoSaveTimer = setTimeout(function() {
                salvarNotasSilencioso();
            }, 1500);
            
            setTimeout(function() {
                if (indicator) indicator.style.display = 'none';
            }, 2500);
        }
        
        function atualizarEstatisticas(data) {
            if (data && data.stats) {
                var statAprovados = document.getElementById('statAprovados');
                var statRecuperacao = document.getElementById('statRecuperacao');
                var statReprovados = document.getElementById('statReprovados');
                var statMediaGeral = document.getElementById('statMediaGeral');
                var statMaiorNota = document.getElementById('statMaiorNota');
                var statPercentual = document.getElementById('statPercentual');
                var statTotalAlunos = document.getElementById('statTotalAlunos');
                
                if (statAprovados) statAprovados.innerHTML = data.stats.total_aprovados;
                if (statRecuperacao) statRecuperacao.innerHTML = data.stats.total_recuperacao;
                if (statReprovados) statReprovados.innerHTML = data.stats.total_reprovados;
                if (statMediaGeral) statMediaGeral.innerHTML = data.stats.media_geral;
                if (statMaiorNota) statMaiorNota.innerHTML = data.stats.maior_nota > 0 ? data.stats.maior_nota : '-';
                if (statPercentual) statPercentual.innerHTML = data.stats.percentual_aprovacao + '%';
                if (statTotalAlunos && data.stats.total_alunos) statTotalAlunos.innerHTML = data.stats.total_alunos;
            }
        }
        
        function salvarNotasSilencioso() {
            if (isSaving) return;
            isSaving = true;
            
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            
            var notasData = {};
            document.querySelectorAll('tr[data-aluno-id]').forEach(row => {
                var alunoId = row.getAttribute('data-aluno-id');
                if (alunoId) {
                    notasData[alunoId] = {
                        mac: document.querySelector(`input[name="mac[${alunoId}]"]`)?.value || '',
                        npt: document.querySelector(`input[name="npt[${alunoId}]"]`)?.value || '',
                        exame_normal: document.querySelector(`input[name="exame_normal[${alunoId}]"]`)?.value || '',
                        exame_recurso: document.querySelector(`input[name="exame_recurso[${alunoId}]"]`)?.value || '',
                        exame_especial: document.querySelector(`input[name="exame_especial[${alunoId}]"]`)?.value || '',
                        exame_oral: document.querySelector(`input[name="exame_oral[${alunoId}]"]`)?.value || '',
                        exame_escrito: document.querySelector(`input[name="exame_escrito[${alunoId}]"]`)?.value || ''
                    };
                }
            });
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `salvar_notas_ajax=1&turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}&notas_data=${encodeURIComponent(JSON.stringify(notasData))}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.stats) atualizarEstatisticas(data);
                }
            })
            .catch(error => console.error('Erro:', error))
            .finally(() => { isSaving = false; });
        }
        
        function salvarNotas() {
            var btn = document.querySelector('.btn-salvar');
            var textoOriginal = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;
            
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var bimestre = <?php echo $bimestre; ?>;
            
            var notasData = {};
            document.querySelectorAll('tr[data-aluno-id]').forEach(row => {
                var alunoId = row.getAttribute('data-aluno-id');
                if (alunoId) {
                    notasData[alunoId] = {
                        mac: document.querySelector(`input[name="mac[${alunoId}]"]`)?.value || '',
                        npt: document.querySelector(`input[name="npt[${alunoId}]"]`)?.value || '',
                        exame_normal: document.querySelector(`input[name="exame_normal[${alunoId}]"]`)?.value || '',
                        exame_recurso: document.querySelector(`input[name="exame_recurso[${alunoId}]"]`)?.value || '',
                        exame_especial: document.querySelector(`input[name="exame_especial[${alunoId}]"]`)?.value || '',
                        exame_oral: document.querySelector(`input[name="exame_oral[${alunoId}]"]`)?.value || '',
                        exame_escrito: document.querySelector(`input[name="exame_escrito[${alunoId}]"]`)?.value || ''
                    };
                }
            });
            
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `salvar_notas_ajax=1&turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}&notas_data=${encodeURIComponent(JSON.stringify(notasData))}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    var indicator = document.getElementById('autoSaveIndicator');
                    if (indicator) {
                        indicator.innerHTML = '<i class="fas fa-check-circle"></i> Notas salvas com sucesso!';
                        indicator.style.background = 'linear-gradient(135deg, #28a745 0%, #20c997 100%)';
                        indicator.style.display = 'block';
                    }
                    
                    if (data.stats) atualizarEstatisticas(data);
                    
                    setTimeout(function() {
                        if (indicator) {
                            indicator.style.display = 'none';
                            indicator.innerHTML = '<i class="fas fa-save"></i> Notas salvas automaticamente!';
                        }
                    }, 2000);
                } else {
                    alert('Erro ao salvar: ' + (data.message || 'Erro desconhecido'));
                }
            })
            .catch(error => { alert('Erro ao salvar notas: ' + error); })
            .finally(() => {
                btn.innerHTML = textoOriginal;
                btn.disabled = false;
            });
        }
        
        function gerarPDF() {
            var turmaId = $('#turma_id').val();
            var disciplinaId = $('#disciplina_id').val();
            var bimestre = $('#bimestre').val();
            if (turmaId && disciplinaId) {
                window.open(`gerar_pdf_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`, '_blank');
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        function gerarExcel() {
            var turmaId = $('#turma_id').val();
            var disciplinaId = $('#disciplina_id').val();
            var bimestre = $('#bimestre').val();
            if (turmaId && disciplinaId) {
                window.location.href = `gerar_excel_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            } else {
                alert('Selecione a turma e disciplina primeiro!');
            }
        }
        
        $(document).on('input', '.nota-input, .exame-input', function() {
            var alunoId = $(this).attr('data-aluno');
            console.log('Input detectado - alunoId:', alunoId);
            if (alunoId) {
                calcularMedia(alunoId);
                autoSalvar();
            }
        });
        
        $(document).ready(function() {
            $('#turma_id').val('<?php echo $turma_id; ?>');
            $('#disciplina_id').val('<?php echo $disciplina_id; ?>');
            $('#bimestre').val('<?php echo $bimestre; ?>');
            
            setTimeout(function() {
                console.log('Calculando médias iniciais...');
                calcularTodas();
            }, 500);
            
            document.querySelectorAll('.nota-input, .exame-input').forEach(input => {
                input.addEventListener('input', function() {
                    var alunoId = this.getAttribute('data-aluno');
                    if (alunoId) {
                        calcularMedia(alunoId);
                        autoSalvar();
                    }
                });
            });
        });

        function verHistorico(alunoId, alunoNome) {
            document.getElementById('historicoModalLabel').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Carregando histórico de ' + alunoNome + '...';
            document.getElementById('historicoModalBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><br><p class="mt-2">Carregando dados do aluno...</p></div>';
            
            var historicoModal = new bootstrap.Modal(document.getElementById('modalHistorico'));
            historicoModal.show();
            
            var turmaId = <?php echo $turma_id; ?>;
            var disciplinaId = <?php echo $disciplina_id; ?>;
            var anoLetivoId = <?php echo $ano_letivo_id; ?>;
            
            fetch(`ajax/get_historico_aluno.php?estudante_id=${alunoId}&disciplina_id=${disciplinaId}&ano_letivo_id=${anoLetivoId}&turma_id=${turmaId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        var html = `
                            <div class="row">
                                <div class="col-md-4 text-center">
                                    <div class="mb-3">
                                        ${data.aluno.foto ? `<img src="../../uploads/alunos/fotos/${data.aluno.foto}" class="rounded-circle" style="width: 100px; height: 100px; object-fit: cover; border: 3px solid #006B3E;">` : '<i class="fas fa-user-graduate fa-5x text-secondary"></i>'}
                                    </div>
                                    <h5>${data.aluno.nome}</h5>
                                    <p class="text-muted">Matrícula: ${data.aluno.matricula}</p>
                                    <hr>
                                    <div class="text-start">
                                        <p><strong><i class="fas fa-id-card"></i> BI:</strong> ${data.aluno.bi || 'Não informado'}</p>
                                        <p><strong><i class="fas fa-calendar-alt"></i> Nascimento:</strong> ${data.aluno.data_nascimento || 'Não informado'}</p>
                                        <p><strong><i class="fas fa-venus-mars"></i> Género:</strong> ${data.aluno.genero == 'M' ? 'Masculino' : (data.aluno.genero == 'F' ? 'Feminino' : 'Não informado')}</p>
                                        <p><strong><i class="fas fa-phone"></i> Contacto:</strong> ${data.aluno.telefone || 'Não informado'}</p>
                                        <p><strong><i class="fas fa-envelope"></i> Email:</strong> ${data.aluno.email || 'Não informado'}</p>
                                    </div>
                                </div>
                                <div class="col-md-8">
                                    <h6><i class="fas fa-chart-line text-primary"></i> Histórico de Notas</h6>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Bimestre</th>
                                                    <th>MAC</th>
                                                    <th>NPT</th>
                                                    <th>Exame Normal</th>
                                                    <th>Exame Recurso</th>
                                                    <th>Média Final</th>
                                                    <th>Situação</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                        `;
                        
                        for (let i = 1; i <= 3; i++) {
                            let nota = data.notas[i] || {};
                            let situacao = nota.situacao || 'Pendente';
                            let badgeClass = situacao == 'Aprovado' ? 'bg-success' : (situacao == 'Recuperação' ? 'bg-warning' : (situacao == 'Reprovado' ? 'bg-danger' : 'bg-secondary'));
                            
                            html += `
                                <tr>
                                    <td class="text-center"><strong>${i}º Bimestre</strong></td>
                                    <td class="text-center">${nota.mac || '-'}</td>
                                    <td class="text-center">${nota.npt || '-'}</td>
                                    <td class="text-center">${nota.exame_normal || '-'}</td>
                                    <td class="text-center">${nota.exame_recurso || '-'}</td>
                                    <td class="text-center"><strong>${nota.media_final || '-'}</strong></td>
                                    <td class="text-center"><span class="badge ${badgeClass}">${situacao}</span></td>
                                </tr>
                            `;
                        }
                        
                        let mediaAnual = data.media_anual || 0;
                        let situacaoAnual = data.situacao_anual || 'Pendente';
                        let badgeAnualClass = situacaoAnual == 'Aprovado' ? 'bg-success' : (situacaoAnual == 'Recuperação' ? 'bg-warning' : (situacaoAnual == 'Reprovado' ? 'bg-danger' : 'bg-secondary'));
                        
                        html += `
                                            </tbody>
                                            <tfoot class="table-light">
                                                <tr>
                                                    <td colspan="5" class="text-end"><strong>Média Anual:</strong></td>
                                                    <td class="text-center"><strong>${mediaAnual}</strong></td>
                                                    <td class="text-center"><span class="badge ${badgeAnualClass}">${situacaoAnual}</span></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                    
                                    <div class="alert alert-info mt-3">
                                        <i class="fas fa-info-circle"></i>
                                        <strong>Legenda:</strong><br>
                                        <span class="badge bg-success">Aprovado</span> - Aluno aprovado na disciplina<br>
                                        <span class="badge bg-warning">Recuperação</span> - Aluno em recuperação<br>
                                        <span class="badge bg-danger">Reprovado</span> - Aluno reprovado<br>
                                        <span class="badge bg-secondary">Pendente</span> - Nota não lançada
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        document.getElementById('historicoModalLabel').innerHTML = `<i class="fas fa-history"></i> Histórico do Aluno - ${data.aluno.nome}`;
                        document.getElementById('historicoModalBody').innerHTML = html;
                    } else {
                        document.getElementById('historicoModalLabel').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Erro';
                        document.getElementById('historicoModalBody').innerHTML = `<div class="alert alert-danger">${data.message || 'Erro ao carregar histórico'}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    document.getElementById('historicoModalLabel').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Erro';
                    document.getElementById('historicoModalBody').innerHTML = '<div class="alert alert-danger">Erro ao carregar histórico do aluno</div>';
                });
        }
    </script>
</body>
</html>