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
$stats = [
    'total_aprovados' => 0,
    'total_recuperacao' => 0,
    'total_reprovados' => 0,
    'media_geral' => 0,
    'maior_nota' => 0,
    'percentual_aprovacao' => 0
];

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
    
    foreach ($alunos as $aluno) {
        $nota = $notas_existentes[$aluno['id']] ?? null;
        $media = $nota['media_final'] ?? 0;
        
        if ($media > 0) {
            if ($media > $limite_aprovacao) $total_aprovados++;
            elseif ($media == $limite_aprovacao) $total_recuperacao++;
            elseif ($media < $limite_aprovacao) $total_reprovados++;
            
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

if (!empty($alunos)) {
    $stats = calcularEstatisticas($alunos, $notas_existentes, $is_ensino_fundamental);
}

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
    $max_nota_parcial = $is_ensino_fundamental ? 10 : 20;
    $max_nota_exame = 20;
    
    try {
        $conn->beginTransaction();
        
        if (!empty($notas_data)) {
            foreach ($notas_data as $estudante_id => $dados) {
                $mac = isset($dados['mac']) && $dados['mac'] !== '' ? min(max(floatval($dados['mac']), 0), $max_nota_parcial) : null;
                $npt = isset($dados['npt']) && $dados['npt'] !== '' ? min(max(floatval($dados['npt']), 0), $max_nota_parcial) : null;
                $exame_normal = isset($dados['exame_normal']) && $dados['exame_normal'] !== '' ? min(max(floatval($dados['exame_normal']), 0), $max_nota_exame) : null;
                $exame_recurso = isset($dados['exame_recurso']) && $dados['exame_recurso'] !== '' ? min(max(floatval($dados['exame_recurso']), 0), $max_nota_exame) : null;
                $exame_especial = isset($dados['exame_especial']) && $dados['exame_especial'] !== '' ? min(max(floatval($dados['exame_especial']), 0), $max_nota_exame) : null;
                $exame_oral = isset($dados['exame_oral']) && $dados['exame_oral'] !== '' ? min(max(floatval($dados['exame_oral']), 0), $max_nota_exame) : null;
                $exame_escrito = isset($dados['exame_escrito']) && $dados['exame_escrito'] !== '' ? min(max(floatval($dados['exame_escrito']), 0), $max_nota_exame) : null;
                
                // Cálculo da média parcial
                $media_parcial = $is_classe_exame ? $mac : ($mac + $npt) / 2;
                $media_final = $media_parcial;
                
                // Regras de cálculo para 3º bimestre
                if ($bimestre_post == 3) {
                    if ($is_classe_exame) {
                        // Classes de Exame (6ª, 9ª, 12ª)
                        if ($exame_recurso !== null && $exame_recurso > 0) {
                            $media_final = $exame_recurso;
                        } else {
                            if ($is_disciplina_lingua) {
                                if ($exame_oral !== null && $exame_escrito !== null && $exame_oral > 0 && $exame_escrito > 0) {
                                    $media_exame = ($exame_oral + $exame_escrito) / 2;
                                    $media_final = ($media_parcial * 0.4) + ($media_exame * 0.6);
                                } elseif ($exame_oral !== null && $exame_oral > 0) {
                                    $media_final = ($media_parcial * 0.4) + ($exame_oral * 0.6);
                                } elseif ($exame_escrito !== null && $exame_escrito > 0) {
                                    $media_final = ($media_parcial * 0.4) + ($exame_escrito * 0.6);
                                } else {
                                    $media_final = $media_parcial;
                                }
                            } else {
                                if ($exame_normal !== null && $exame_normal > 0) {
                                    $media_final = ($media_parcial * 0.4) + ($exame_normal * 0.6);
                                } else {
                                    $media_final = $media_parcial;
                                }
                            }
                        }
                    } else {
                        // Classes normais (1ª,2ª,3ª,4ª,5ª,7ª,8ª,10ª,11ª)
                        if ($exame_recurso !== null && $exame_recurso > 0) {
                            $media_final = ($media_parcial + $exame_recurso) / 2;
                        } elseif ($exame_normal !== null && $exame_normal > 0) {
                            $media_final = ($media_parcial + $exame_normal) / 2;
                        } else {
                            $media_final = $media_parcial;
                        }
                    }
                }
                
                // Definir status
                if ($media_final > $limite_aprovacao) {
                    $status = 'aprovado';
                } elseif ($media_final == $limite_aprovacao && $media_final > 0) {
                    $status = 'recuperacao';
                } elseif ($media_final > 0 && $media_final < $limite_aprovacao) {
                    $status = 'reprovado';
                } else {
                    $status = 'pendente';
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            margin-top: 60px;
            margin-bottom: 45px;
            padding: 20px;
            min-height: calc(100vh - 105px);
        }
        
        @media (max-width: 768px) { .main-content { margin-left: 0; margin-top: 70px; padding: 15px; } }
        
        .page-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border-radius: 20px;
            padding: 25px 30px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .page-header h2 { margin: 0; font-size: 1.6rem; font-weight: 700; }
        .page-header p { margin: 8px 0 0; opacity: 0.85; font-size: 0.9rem; }
        
        .filter-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 2px 15px rgba(0,0,0,0.05);
            border: 1px solid rgba(0,0,0,0.03);
        }
        .filter-label {
            font-weight: 700;
            font-size: 0.7rem;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 5px;
            letter-spacing: 0.5px;
        }
        .btn-action {
            border-radius: 30px;
            padding: 6px 18px;
            font-size: 0.75rem;
            margin: 2px;
            transition: all 0.3s;
        }
        .btn-action:hover { transform: translateY(-2px); }
        .btn-group-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        
        .info-turma {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 25px;
            border-left: 5px solid #006B3E;
        }
        
        .regras-info {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 5px solid #006B3E;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 13px;
        }
        
        .notas-table {
            width: 100%;
            border-collapse: collapse;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .notas-table th {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            text-align: center;
            padding: 14px 8px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .notas-table td {
            vertical-align: middle;
            text-align: center;
            padding: 12px 8px;
            font-size: 0.85rem;
            border-bottom: 1px solid #e9ecef;
            background: white;
        }
        .notas-table tbody tr:hover { background: #f8f9fa; }
        .notas-table tbody tr:hover td { background: #f8f9fa; }
        
        .media-input {
            width: 80px;
            text-align: center;
            font-size: 0.85rem;
            padding: 8px;
            border-radius: 12px;
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
            font-weight: 600;
        }
        .media-input:focus {
            outline: none;
            border-color: #006B3E;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
        
        /* Cores para inputs baseadas no desempenho */
        .media-input.nota-excelente { background: #d4edda; border-color: #28a745; color: #155724; }
        .media-input.nota-bom { background: #d1ecf1; border-color: #17a2b8; color: #0c5460; }
        .media-input.nota-regular { background: #fff3cd; border-color: #ffc107; color: #856404; }
        .media-input.nota-ruim { background: #f8d7da; border-color: #dc3545; color: #721c24; }
        
        /* Badges de Status com cores padrão */
        .badge-aprovado { background: #28a745; color: white; padding: 5px 12px; border-radius: 30px; font-size: 11px; font-weight: 600; }
        .badge-reprovado { background: #dc3545; color: white; padding: 5px 12px; border-radius: 30px; font-size: 11px; font-weight: 600; }
        .badge-recuperacao { background: #ffc107; color: #333; padding: 5px 12px; border-radius: 30px; font-size: 11px; font-weight: 600; }
        .badge-pendente { background: #6c757d; color: white; padding: 5px 12px; border-radius: 30px; font-size: 11px; font-weight: 600; }
        
        /* Cores para as estatísticas */
        .stats-card .text-success { color: #28a745 !important; }
        .stats-card .text-warning { color: #ffc107 !important; }
        .stats-card .text-danger { color: #dc3545 !important; }
        
        .stats-card {
            background: white;
            border-radius: 20px;
            padding: 20px 15px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: all 0.3s;
            cursor: pointer;
            border: 1px solid rgba(0,0,0,0.05);
            height: 100%;
        }
        .stats-card:hover { transform: translateY(-5px); box-shadow: 0 15px 30px rgba(0,0,0,0.12); }
        .stats-number { font-size: 32px; font-weight: 800; margin-bottom: 5px; }
        .stats-label { font-size: 11px; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .stats-icon { font-size: 35px; margin-bottom: 12px; }
        
        .foto-aluno {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid #006B3E;
        }
        .foto-aluno:hover { transform: scale(1.05); opacity: 0.9; }
        
        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        
        .modal-foto {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .modal-foto.show { display: flex; }
        .modal-foto img { max-width: 90%; max-height: 90%; border-radius: 12px; box-shadow: 0 5px 30px rgba(0,0,0,0.5); }
        
        .auto-save-indicator {
            position: fixed;
            bottom: 80px;
            right: 30px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 13px;
            z-index: 999;
            display: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            font-weight: 600;
            gap: 8px;
            align-items: center;
        }
        
        .btn-salvar {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            padding: 12px 35px;
            border-radius: 40px;
            font-weight: 700;
            transition: all 0.3s;
        }
        .btn-salvar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); }
        
        /* Cores para células da média no histórico */
        .media-aprovado { color: #28a745; font-weight: bold; }
        .media-recuperacao { color: #ffc107; font-weight: bold; }
        .media-reprovado { color: #dc3545; font-weight: bold; }
        
        @media (max-width: 768px) {
            .media-input { width: 60px; font-size: 0.7rem; }
            .notas-table th, .notas-table td { padding: 8px 4px; font-size: 0.7rem; }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2><i class="fas fa-pen-alt me-2"></i> Lançamento de Notas</h2>
            <p>Gerencie as notas dos alunos de forma rápida e intuitiva. Selecione a turma e disciplina para visualizar os alunos.</p>
        </div>
        
        <div class="filter-card">
            <div class="row">
                <div class="col-md-12">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <span class="filter-label">ANO LETIVO</span>
                            <div class="filter-value"><?php echo $ano_letivo_atual; ?></div>
                        </div>
                        <div class="btn-group-actions">
                            <button type="button" class="btn btn-sm btn-outline-secondary btn-action" onclick="aplicarFiltros()">
                                <i class="fas fa-search"></i> Buscar Alunos
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-action" onclick="calcularTodas()">
                                <i class="fas fa-calculator"></i> Calcular Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-success btn-action" onclick="salvarNotas()">
                                <i class="fas fa-save"></i> Salvar Todas
                            </button>
                            <button type="button" class="btn btn-sm btn-danger btn-action" onclick="window.open('minipauta_pdf.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&bimestre=<?php echo $bimestre; ?>', '_blank')">
    <i class="fas fa-print"></i> Minipauta PDF
</button>
                            <button type="button" class="btn btn-sm btn-info btn-action" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                                <i class="fas fa-question-circle"></i> Ajuda
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
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
                </div>
                <div class="col-md-4 mb-3">
                    <label class="filter-label">BIMESTRE</label>
                    <select name="bimestre" id="bimestre" class="form-select form-select-sm" onchange="aplicarFiltros()">
                        <option value="1" <?php echo $bimestre == 1 ? 'selected' : ''; ?>>1º Bimestre</option>
                        <option value="2" <?php echo $bimestre == 2 ? 'selected' : ''; ?>>2º Bimestre</option>
                        <option value="3" <?php echo $bimestre == 3 ? 'selected' : ''; ?>>3º Bimestre</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="regras-info" id="regrasInfo">
            <i class="fas fa-info-circle me-2"></i>
            <strong>Regras de Avaliação:</strong>
            <?php if ($is_ensino_fundamental): ?>
                Escala <strong>0-10</strong> | 
                <span style="color: #28a745;">Aprovado: > 5</span> | 
                <span style="color: #ffc107;">Recuperação: = 5</span> | 
                <span style="color: #dc3545;">Reprovado: < 5</span>
            <?php else: ?>
                Escala <strong>0-20</strong> | 
                <span style="color: #28a745;">Aprovado: > 10</span> | 
                <span style="color: #ffc107;">Recuperação: = 10</span> | 
                <span style="color: #dc3545;">Reprovado: < 10</span>
            <?php endif; ?>
            <?php if ($is_classe_exame && $bimestre == 3): ?>
                | <strong>Classe de Exame (<?php echo $classe_ano; ?>ª):</strong> Média = 40% (MAC) + 60% (Exame)
            <?php endif; ?>
            <?php if ($is_disciplina_lingua && $is_classe_exame && $bimestre == 3): ?>
                | <strong>Disciplina de Língua:</strong> Exame Oral + Escrito
            <?php endif; ?>
        </div>
        
        <div class="row mb-4" id="statsContainer">
            <div class="col-md-2 col-6 mb-2"><div class="stats-card"><div class="stats-icon"><i class="fas fa-users text-primary"></i></div><div class="stats-number text-primary" id="statTotalAlunos"><?php echo $total_alunos; ?></div><div class="stats-label">Total Alunos</div></div></div>
            <div class="col-md-2 col-6 mb-2"><div class="stats-card"><div class="stats-icon"><i class="fas fa-check-circle"></i></div><div class="stats-number" id="statAprovados" style="color: #28a745;"><?php echo $stats['total_aprovados']; ?></div><div class="stats-label">Aprovados</div><small id="statPercentual" style="color: #28a745;"><?php echo $stats['percentual_aprovacao']; ?>%</small></div></div>
            <div class="col-md-2 col-6 mb-2"><div class="stats-card"><div class="stats-icon"><i class="fas fa-clock"></i></div><div class="stats-number" id="statRecuperacao" style="color: #ffc107;"><?php echo $stats['total_recuperacao']; ?></div><div class="stats-label">Recuperação</div></div></div>
            <div class="col-md-2 col-6 mb-2"><div class="stats-card"><div class="stats-icon"><i class="fas fa-times-circle"></i></div><div class="stats-number" id="statReprovados" style="color: #dc3545;"><?php echo $stats['total_reprovados']; ?></div><div class="stats-label">Reprovados</div></div></div>
            <div class="col-md-2 col-6 mb-2"><div class="stats-card"><div class="stats-icon"><i class="fas fa-chart-line text-info"></i></div><div class="stats-number text-info" id="statMediaGeral"><?php echo $stats['media_geral']; ?></div><div class="stats-label">Média Geral</div></div></div>
            <div class="col-md-2 col-6 mb-2"><div class="stats-card"><div class="stats-icon"><i class="fas fa-trophy text-warning"></i></div><div class="stats-number text-warning" id="statMaiorNota"><?php echo $stats['maior_nota'] > 0 ? $stats['maior_nota'] : '-'; ?></div><div class="stats-label">Melhor Nota</div></div></div>
        </div>
        
        <div class="info-turma">
            <div class="row">
                <div class="col-md-3"><small class="text-muted text-uppercase">Turma</small><div class="fw-bold"><?php if ($turma_id > 0 && !empty($turmas)) { foreach ($turmas as $t) { if ($t['id'] == $turma_id) { echo $t['ano'] . 'ª Classe - ' . $t['nome']; break; } } } else { echo 'Selecione uma turma'; } ?></div></div>
                <div class="col-md-2"><small class="text-muted text-uppercase">Turno</small><div class="fw-bold"><?php if ($turma_id > 0 && !empty($turmas)) { foreach ($turmas as $t) { if ($t['id'] == $turma_id) { echo ucfirst($t['turno']); break; } } } else { echo '-'; } ?></div></div>
                <div class="col-md-2"><small class="text-muted text-uppercase">Ano Letivo</small><div class="fw-bold"><?php echo $ano_letivo_atual; ?></div></div>
                <div class="col-md-2"><small class="text-muted text-uppercase">Sala</small><div class="fw-bold"><?php if ($turma_id > 0 && !empty($turmas)) { foreach ($turmas as $t) { if ($t['id'] == $turma_id) { echo $t['sala'] ?: 'Não definida'; break; } } } else { echo '-'; } ?></div></div>
                <div class="col-md-3"><small class="text-muted text-uppercase">Professor(a)</small><div class="fw-bold"><?php echo htmlspecialchars($professor['professor_nome']); ?></div></div>
            </div>
        </div>
        
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($alunos)): ?>
        <form method="POST" id="formNotas">
            <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
            <input type="hidden" name="disciplina_id" value="<?php echo $disciplina_id; ?>">
            <input type="hidden" name="bimestre" value="<?php echo $bimestre; ?>">
            
            <div class="filter-card">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="fas fa-table me-2"></i> Notas dos Alunos</h5>
                    <span class="badge bg-primary"><?php echo $total_alunos; ?> alunos</span>
                </div>
                
                <div class="table-responsive">
                    <table class="notas-table">
                        <thead>
                            <tr>
                                <th width="3%">#</th>
                                <th width="8%">Foto</th>
                                <th width="22%">Aluno</th>
                                <th width="10%">Matrícula</th>
                                <?php if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                                <th width="8%">MAC<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <th width="8%" style="display: none;">NPT<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <th width="8%">Exame Normal<br><small>(0-20)</small></th>
                                <th width="8%">Exame Recurso<br><small>(0-20)</small></th>
                                <?php elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                                <th width="8%">MAC<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <th width="8%" style="display: none;">NPT<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <th width="8%">Exame Oral<br><small>(0-20)</small></th>
                                <th width="8%">Exame Escrito<br><small>(0-20)</small></th>
                                <th width="8%">Exame Recurso<br><small>(0-20)</small></th>
                                <?php elseif ($bimestre == 3): ?>
                                <th width="8%">MAC<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <th width="8%">NPT<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <?php else: ?>
                                <th width="8%">MAC<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <th width="8%">NPT<br><small>(0-<?php echo $is_ensino_fundamental ? '10' : '20'; ?>)</small></th>
                                <?php endif; ?>
                                <th width="8%">Média</th>
                                <th width="12%">Situação</th>
                                <th width="8%">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos as $index => $aluno):
                                $nota = $notas_existentes[$aluno['id']] ?? null;
                                $media_final = $nota['media_final'] ?? 0;
                                $classe_nota_input = '';
                                if ($is_ensino_fundamental) {
                                    if ($media_final > 5) $classe_nota_input = 'nota-excelente';
                                    elseif ($media_final == 5) $classe_nota_input = 'nota-regular';
                                    elseif ($media_final > 0 && $media_final < 5) $classe_nota_input = 'nota-ruim';
                                } else {
                                    if ($media_final > 10) $classe_nota_input = 'nota-excelente';
                                    elseif ($media_final == 10) $classe_nota_input = 'nota-regular';
                                    elseif ($media_final > 0 && $media_final < 10) $classe_nota_input = 'nota-ruim';
                                }
                            ?>
                            <tr data-aluno-id="<?php echo $aluno['id']; ?>">
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td class="text-center">
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="foto-aluno" onclick="ampliarFoto(this.src, '<?php echo addslashes($aluno['nome']); ?>')">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="foto-aluno" onclick="ampliarFoto(this.src, '<?php echo addslashes($aluno['nome']); ?>')">
                                    <?php endif; ?>
                                </td>
                                <td class="text-start"><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong><br><small class="text-muted"><?php echo $aluno['matricula']; ?></small></td>
                                <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                
                                <?php if ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?php echo $is_ensino_fundamental ? '10' : '20'; ?>" 
                                           name="mac[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input <?php echo $classe_nota_input; ?>" 
                                           value="<?php echo $nota['mac'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, <?php echo $is_ensino_fundamental ? 10 : 20; ?>); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center" style="display: none;">
                                    <input type="hidden" name="npt[<?php echo $aluno['id']; ?>]" value="0">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_normal[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input" 
                                           value="<?php echo $nota['exame_normal'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, 20); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_recurso[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input" 
                                           value="<?php echo $nota['exame_recurso'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, 20); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                
                                <?php elseif ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?php echo $is_ensino_fundamental ? '10' : '20'; ?>" 
                                           name="mac[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input <?php echo $classe_nota_input; ?>" 
                                           value="<?php echo $nota['mac'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, <?php echo $is_ensino_fundamental ? 10 : 20; ?>); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center" style="display: none;">
                                    <input type="hidden" name="npt[<?php echo $aluno['id']; ?>]" value="0">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_oral[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input" 
                                           value="<?php echo $nota['exame_oral'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, 20); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_escrito[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input" 
                                           value="<?php echo $nota['exame_escrito'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, 20); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="20" 
                                           name="exame_recurso[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input" 
                                           value="<?php echo $nota['exame_recurso'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, 20); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                
                                <?php elseif ($bimestre == 3): ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?php echo $is_ensino_fundamental ? '10' : '20'; ?>" 
                                           name="mac[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input <?php echo $classe_nota_input; ?>" 
                                           value="<?php echo $nota['mac'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, <?php echo $is_ensino_fundamental ? 10 : 20; ?>); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?php echo $is_ensino_fundamental ? '10' : '20'; ?>" 
                                           name="npt[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input <?php echo $classe_nota_input; ?>" 
                                           value="<?php echo $nota['npt'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, <?php echo $is_ensino_fundamental ? 10 : 20; ?>); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                
                                <?php else: ?>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?php echo $is_ensino_fundamental ? '10' : '20'; ?>" 
                                           name="mac[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input <?php echo $classe_nota_input; ?>" 
                                           value="<?php echo $nota['mac'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, <?php echo $is_ensino_fundamental ? 10 : 20; ?>); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <td class="text-center">
                                    <input type="number" step="0.5" min="0" max="<?php echo $is_ensino_fundamental ? '10' : '20'; ?>" 
                                           name="npt[<?php echo $aluno['id']; ?>]" 
                                           class="form-control form-control-sm media-input <?php echo $classe_nota_input; ?>" 
                                           value="<?php echo $nota['npt'] ?? ''; ?>"
                                           data-aluno="<?php echo $aluno['id']; ?>"
                                           oninput="validarNota(this, <?php echo $is_ensino_fundamental ? 10 : 20; ?>); atualizarMediaAluno(<?php echo $aluno['id']; ?>); autoSalvar()">
                                </td>
                                <?php endif; ?>
                                
                                <td class="text-center">
                                    <span id="media_<?php echo $aluno['id']; ?>" class="fw-bold fs-5" style="<?php 
                                        if ($media_final > 0) {
                                            if ($is_ensino_fundamental) {
                                                if ($media_final > 5) echo 'color: #28a745;';
                                                elseif ($media_final == 5) echo 'color: #ffc107;';
                                                elseif ($media_final < 5) echo 'color: #dc3545;';
                                            } else {
                                                if ($media_final > 10) echo 'color: #28a745;';
                                                elseif ($media_final == 10) echo 'color: #ffc107;';
                                                elseif ($media_final < 10) echo 'color: #dc3545;';
                                            }
                                        }
                                    ?>">
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
                                    <div class="btn-group">
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="atualizarMediaAluno(<?php echo $aluno['id']; ?>)" title="Calcular média"><i class="fas fa-calculator"></i></button>
                                        <button type="button" class="btn btn-sm btn-outline-info" onclick="verHistorico(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>')" title="Ver histórico"><i class="fas fa-history"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="text-end mt-4">
                    <button type="button" class="btn-salvar" onclick="salvarNotas()"><i class="fas fa-save me-2"></i> Salvar Todas as Notas</button>
                </div>
            </div>
        </form>
        <?php elseif ($turma_id > 0 && $disciplina_id > 0): ?>
            <div class="alert alert-info text-center"><i class="fas fa-info-circle"></i> Nenhum aluno encontrado nesta turma.</div>
        <?php elseif ($turma_id > 0): ?>
            <div class="alert alert-warning text-center"><i class="fas fa-exclamation-triangle"></i> Selecione uma disciplina para continuar.</div>
        <?php else: ?>
            <div class="alert alert-secondary text-center"><i class="fas fa-filter"></i> Carregue os filtros para ver as notas.</div>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Histórico -->
    <div class="modal fade" id="modalHistorico" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-history"></i> Histórico do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="historicoModalBody"><div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Carregando dados...</p></div></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Regras de Avaliação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <strong>Regras Gerais de Avaliação</strong>
                    </div>
                    
                    <h6 class="mt-3"><i class="fas fa-chart-line text-primary"></i> Escalas de Avaliação:</h6>
                    <ul>
                        <li><strong>Ensino Fundamental (1ª à 6ª Classe):</strong> Escala <strong>0-10</strong></li>
                        <li><strong>Ensino Secundário (7ª à 12ª Classe):</strong> Escala <strong>0-20</strong></li>
                    </ul>
                    
                  <h6 class="mt-3"><i class="fas fa-graduation-cap"></i> Critérios de Aprovação com Cores:</h6>
<ul>
    <li><strong>Ensino Fundamental (1ª à 6ª Classe) - Escala 0-10:</strong></li>
    <ul>
        <li><strong style="color: #28a745;">Aprovado (Verde):</strong> Nota > 5</li>
        <li><strong style="color: #ffc107;">Recuperação (Amarelo):</strong> Nota = 5</li>
        <li><strong style="color: #dc3545;">Reprovado (Vermelho):</strong> Nota < 5</li>
    </ul>
    <li class="mt-2"><strong>Ensino Secundário (7ª à 12ª Classe) - Escala 0-20:</strong></li>
    <ul>
        <li><strong style="color: #28a745;">Aprovado (Verde):</strong> Nota > 10</li>
        <li><strong style="color: #ffc107;">Recuperação (Amarelo):</strong> Nota = 10</li>
        <li><strong style="color: #dc3545;">Reprovado (Vermelho):</strong> Nota < 10</li>
    </ul>
</ul>
                    
                    
                    <h6 class="mt-3"><i class="fas fa-calculator text-warning"></i> Cálculo das Médias:</h6>
                    <ul>
                        <li><strong>1º e 2º Bimestre:</strong> Média = (MAC + NPT) / 2</li>
                        <li><strong>3º Bimestre (Classes Normais - 1ª,2ª,3ª,4ª,5ª,7ª,8ª,10ª,11ª):</strong>
                            <ul>
                                <li>Média Parcial = (MAC + NPT) / 2</li>
                                <li>Com Exame Normal: Média Final = (Média Parcial + Exame) / 2</li>
                                <li>Com Exame Recurso: Média Final = (Média Parcial + Recurso) / 2</li>
                            </ul>
                        </li>
                        <li><strong>3º Bimestre (Classes de Exame - 6ª, 9ª, 12ª):</strong>
                            <ul>
                                <li>Média Parcial = MAC (apenas, NPT não é considerado)</li>
                                <li>Com Exame Normal: Média Final = (MAC × 40%) + (Exame Normal × 60%)</li>
                                <li>Com Exame Recurso: Média Final = Nota do Exame Recurso</li>
                            </ul>
                        </li>
                        <li><strong>Disciplinas de Língua (Português/Inglês) - Classes de Exame:</strong>
                            <ul>
                                <li>Média Parcial = MAC (apenas)</li>
                                <li>Com Exame Oral e Escrito: Média Exame = (Oral + Escrito) / 2</li>
                                <li>Média Final = (MAC × 40%) + (Média Exame × 60%)</li>
                                <li>Com Exame Recurso: Média Final = Nota do Exame Recurso</li>
                            </ul>
                        </li>
                    </ul>
                    
                    <h6 class="mt-3"><i class="fas fa-palette"></i> Significado das Cores:</h6>
                    <ul>
                        <li><span style="background: #d4edda; color: #155724; padding: 2px 8px; border-radius: 4px;">Verde</span> - Nota Excelente (Acima da média de aprovação)</li>
                        <li><span style="background: #d1ecf1; color: #0c5460; padding: 2px 8px; border-radius: 4px;">Azul</span> - Nota Boa (Na média de aprovação)</li>
                        <li><span style="background: #fff3cd; color: #856404; padding: 2px 8px; border-radius: 4px;">Amarelo</span> - Nota de Recuperação (Igual ao limite)</li>
                        <li><span style="background: #f8d7da; color: #721c24; padding: 2px 8px; border-radius: 4px;">Vermelho</span> - Nota Ruim (Abaixo da média)</li>
                    </ul>
                    
                    <div class="alert alert-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Observação Importante:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Para Classes de Exame (6ª, 9ª, 12ª), o campo NPT fica oculto no 3º bimestre</li>
                            <li>O Exame Recurso tem prioridade sobre os outros exames</li>
                            <li>As cores dos campos indicam o desempenho conforme os critérios acima</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>
    
    <div id="modalFoto" class="modal-foto" onclick="fecharFoto()"><img id="fotoAmpliada" src=""></div>
    <div id="autoSaveIndicator" class="auto-save-indicator"><i class="fas fa-save"></i> Notas salvas automaticamente!</div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let autoSaveTimer = null;
        let isSaving = false;
        let isEnsinoFundamental = <?php echo $is_ensino_fundamental ? 'true' : 'false'; ?>;
        let isClasseExame = <?php echo ($is_classe_exame && $bimestre == 3) ? 'true' : 'false'; ?>;
        let isDisciplinaLingua = <?php echo $is_disciplina_lingua ? 'true' : 'false'; ?>;
        let bimestreAtual = <?php echo $bimestre; ?>;
        let limiteAprovacao = isEnsinoFundamental ? 5 : 10;
        
        function validarNota(input, max) {
            let valor = parseFloat(input.value);
            if (isNaN(valor)) valor = 0;
            if (valor < 0) input.value = 0;
            if (valor > max) input.value = max;
        }
        
        function aplicarFiltros() {
            let turmaId = $('#turma_id').val();
            let disciplinaId = $('#disciplina_id').val();
            let bimestre = $('#bimestre').val();
            if (turmaId && disciplinaId) window.location.href = `lancar_notas.php?turma_id=${turmaId}&disciplina_id=${disciplinaId}&bimestre=${bimestre}`;
            else if (turmaId) window.location.href = `lancar_notas.php?turma_id=${turmaId}&bimestre=${bimestre}`;
            else window.location.href = `lancar_notas.php?bimestre=${bimestre}`;
        }
        
        function atualizarMediaAluno(alunoId) {
            let mac = parseFloat(document.querySelector(`input[name="mac[${alunoId}]"]`)?.value) || 0;
            let npt = parseFloat(document.querySelector(`input[name="npt[${alunoId}]"]`)?.value) || 0;
            let exameNormal = parseFloat(document.querySelector(`input[name="exame_normal[${alunoId}]"]`)?.value) || 0;
            let exameRecurso = parseFloat(document.querySelector(`input[name="exame_recurso[${alunoId}]"]`)?.value) || 0;
            let exameOral = parseFloat(document.querySelector(`input[name="exame_oral[${alunoId}]"]`)?.value) || 0;
            let exameEscrito = parseFloat(document.querySelector(`input[name="exame_escrito[${alunoId}]"]`)?.value) || 0;
            
            let media = 0;
            
            if (bimestreAtual == 3) {
                if (isClasseExame) {
                    let mediaParcial = mac;
                    
                    if (exameRecurso > 0) {
                        media = exameRecurso;
                    } else {
                        if (isDisciplinaLingua) {
                            if (exameOral > 0 && exameEscrito > 0) {
                                let mediaExame = (exameOral + exameEscrito) / 2;
                                media = (mediaParcial * 0.4) + (mediaExame * 0.6);
                            } else if (exameOral > 0) {
                                media = (mediaParcial * 0.4) + (exameOral * 0.6);
                            } else if (exameEscrito > 0) {
                                media = (mediaParcial * 0.4) + (exameEscrito * 0.6);
                            } else {
                                media = mediaParcial;
                            }
                        } else {
                            if (exameNormal > 0) {
                                media = (mediaParcial * 0.4) + (exameNormal * 0.6);
                            } else {
                                media = mediaParcial;
                            }
                        }
                    }
                } else {
                    let mediaParcial = (mac + npt) / 2;
                    
                    if (exameRecurso > 0) {
                        media = (mediaParcial + exameRecurso) / 2;
                    } else if (exameNormal > 0) {
                        media = (mediaParcial + exameNormal) / 2;
                    } else {
                        media = mediaParcial;
                    }
                }
            } else {
                media = (mac + npt) / 2;
            }
            
            media = Math.round(media * 10) / 10;
            
            let situacao = '';
            let badgeClass = '';
            let inputClass = '';
            let mediaColor = '';
            
            if (media > limiteAprovacao) {
                situacao = 'Aprovado';
                badgeClass = 'badge-aprovado';
                mediaColor = '#28a745';
                if (isEnsinoFundamental) {
                    if (media >= 7) inputClass = 'nota-excelente';
                    else inputClass = 'nota-bom';
                } else {
                    if (media >= 14) inputClass = 'nota-excelente';
                    else inputClass = 'nota-bom';
                }
            } else if (media == limiteAprovacao && media > 0) {
                situacao = 'Recuperação';
                badgeClass = 'badge-recuperacao';
                mediaColor = '#ffc107';
                inputClass = 'nota-regular';
            } else if (media > 0 && media < limiteAprovacao) {
                situacao = 'Reprovado';
                badgeClass = 'badge-reprovado';
                mediaColor = '#dc3545';
                inputClass = 'nota-ruim';
            } else {
                situacao = 'Pendente';
                badgeClass = 'badge-pendente';
                mediaColor = '#6c757d';
                inputClass = '';
            }
            
            let mediaSpan = document.getElementById(`media_${alunoId}`);
            if (mediaSpan) {
                mediaSpan.innerHTML = media.toFixed(1);
                mediaSpan.style.color = mediaColor;
            }
            
            let statusSpan = document.getElementById(`status_${alunoId}`);
            if (statusSpan) {
                statusSpan.innerHTML = situacao;
                statusSpan.className = `badge ${badgeClass}`;
            }
            
            let todosInputs = document.querySelectorAll(`input[name*="[${alunoId}]"]`);
            todosInputs.forEach(input => {
                if (input.type !== 'hidden') {
                    input.classList.remove('nota-excelente', 'nota-bom', 'nota-regular', 'nota-ruim');
                    if (inputClass) input.classList.add(inputClass);
                }
            });
            
            return media;
        }
        
        function calcularTodas() {
            document.querySelectorAll('tr[data-aluno-id]').forEach(row => {
                let alunoId = row.getAttribute('data-aluno-id');
                if (alunoId) atualizarMediaAluno(alunoId);
            });
        }
        
        function autoSalvar() {
            if (autoSaveTimer) clearTimeout(autoSaveTimer);
            let indicator = document.getElementById('autoSaveIndicator');
            if (indicator) indicator.style.display = 'flex';
            autoSaveTimer = setTimeout(() => { salvarNotasSilencioso(); }, 1500);
            setTimeout(() => { if (indicator) indicator.style.display = 'none'; }, 2500);
        }
        
        function salvarNotasSilencioso() {
            if (isSaving) return;
            isSaving = true;
            
            let turmaId = <?php echo $turma_id; ?>;
            let disciplinaId = <?php echo $disciplina_id; ?>;
            let bimestre = <?php echo $bimestre; ?>;
            let notasData = {};
            
            document.querySelectorAll('tr[data-aluno-id]').forEach(row => {
                let alunoId = row.getAttribute('data-aluno-id');
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
            .then(data => { if (data.success && data.stats) atualizarEstatisticas(data); })
            .catch(error => console.error('Erro:', error))
            .finally(() => { isSaving = false; });
        }
        
        function salvarNotas() {
            let btn = document.querySelector('.btn-salvar');
            let textoOriginal = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            btn.disabled = true;
            
            let turmaId = <?php echo $turma_id; ?>;
            let disciplinaId = <?php echo $disciplina_id; ?>;
            let bimestre = <?php echo $bimestre; ?>;
            let notasData = {};
            
            document.querySelectorAll('tr[data-aluno-id]').forEach(row => {
                let alunoId = row.getAttribute('data-aluno-id');
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
                    let indicator = document.getElementById('autoSaveIndicator');
                    if (indicator) {
                        indicator.innerHTML = '<i class="fas fa-check-circle"></i> Notas salvas com sucesso!';
                        indicator.style.display = 'flex';
                        setTimeout(() => { indicator.style.display = 'none'; indicator.innerHTML = '<i class="fas fa-save"></i> Notas salvas automaticamente!'; }, 2000);
                    }
                    if (data.stats) atualizarEstatisticas(data);
                    alert('Notas salvas com sucesso!');
                } else { alert('Erro ao salvar: ' + (data.message || 'Erro desconhecido')); }
            })
            .catch(error => { alert('Erro ao salvar notas: ' + error); })
            .finally(() => { btn.innerHTML = textoOriginal; btn.disabled = false; });
        }
        
        function atualizarEstatisticas(data) {
            if (data && data.stats) {
                if (document.getElementById('statAprovados')) {
                    document.getElementById('statAprovados').innerHTML = data.stats.total_aprovados;
                    document.getElementById('statAprovados').style.color = '#28a745';
                }
                if (document.getElementById('statRecuperacao')) {
                    document.getElementById('statRecuperacao').innerHTML = data.stats.total_recuperacao;
                    document.getElementById('statRecuperacao').style.color = '#ffc107';
                }
                if (document.getElementById('statReprovados')) {
                    document.getElementById('statReprovados').innerHTML = data.stats.total_reprovados;
                    document.getElementById('statReprovados').style.color = '#dc3545';
                }
                if (document.getElementById('statMediaGeral')) document.getElementById('statMediaGeral').innerHTML = data.stats.media_geral;
                if (document.getElementById('statMaiorNota')) document.getElementById('statMaiorNota').innerHTML = data.stats.maior_nota > 0 ? data.stats.maior_nota : '-';
                if (document.getElementById('statPercentual')) {
                    document.getElementById('statPercentual').innerHTML = data.stats.percentual_aprovacao + '%';
                    document.getElementById('statPercentual').style.color = '#28a745';
                }
            }
        }
        
        function ampliarFoto(src, nome) { document.getElementById('fotoAmpliada').src = src; document.getElementById('modalFoto').classList.add('show'); }
        function fecharFoto() { document.getElementById('modalFoto').classList.remove('show'); }
        
        function verHistorico(alunoId, alunoNome) {
            document.getElementById('historicoModalBody').innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p class="mt-2">Carregando dados...</p></div>';
            new bootstrap.Modal(document.getElementById('modalHistorico')).show();
            
            fetch(`ajax/get_historico_aluno.php?estudante_id=${alunoId}&disciplina_id=<?php echo $disciplina_id; ?>&ano_letivo_id=<?php echo $ano_letivo_id; ?>&turma_id=<?php echo $turma_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let somaMedias = 0;
                        let qtdMedias = 0;
                        let html = `<div class="row"><div class="col-md-3 text-center"><div class="mb-3">${data.aluno.foto ? `<img src="../../uploads/alunos/fotos/${data.aluno.foto}" class="rounded-circle" style="width: 100px; height: 100px; object-fit: cover; border: 3px solid #006B3E; cursor: pointer;" onclick="ampliarFoto('../../uploads/alunos/fotos/${data.aluno.foto}', '${data.aluno.nome}')">` : '<i class="fas fa-user-graduate fa-5x text-secondary"></i>'}</div><h5>${data.aluno.nome}</h5><p class="text-muted">Matrícula: ${data.aluno.matricula}</p><hr><div class="text-start"><p><strong><i class="fas fa-id-card"></i> BI:</strong> ${data.aluno.bi || 'Não informado'}</p><p><strong><i class="fas fa-calendar-alt"></i> Nascimento:</strong> ${data.aluno.data_nascimento || 'Não informado'}</p><p><strong><i class="fas fa-venus-mars"></i> Género:</strong> ${data.aluno.genero == 'M' ? 'Masculino' : (data.aluno.genero == 'F' ? 'Feminino' : 'Não informado')}</p><p><strong><i class="fas fa-phone"></i> Contacto:</strong> ${data.aluno.telefone || 'Não informado'}</p><p><strong><i class="fas fa-envelope"></i> Email:</strong> ${data.aluno.email || 'Não informado'}</p></div></div><div class="col-md-9"><h6><i class="fas fa-chart-line text-primary"></i> Histórico de Notas - ${data.disciplina_nome || 'Disciplina'}</h6><div class="table-responsive"><table class="table table-bordered table-sm"><thead class="table-light"><tr><th>Bimestre</th><th>MAC</th><th>NPT</th><th>Exame Normal</th><th>Exame Recurso</th><th>Exame Especial</th><th>Exame Oral</th><th>Exame Escrito</th><th>Média Final</th><th>Situação</th></tr></thead><tbody>`;
                        for (let i = 1; i <= 3; i++) {
                            let nota = data.notas[i] || {};
                            let situacao = nota.situacao || 'Pendente';
                            let badgeClass = situacao == 'Aprovado' ? 'bg-success' : (situacao == 'Recuperação' ? 'bg-warning' : (situacao == 'Reprovado' ? 'bg-danger' : 'bg-secondary'));
                            let media = nota.media_final ? parseFloat(nota.media_final) : 0;
                            let mediaColor = '';
                            if (media > 0) {
                                if (isEnsinoFundamental) {
                                    if (media > 5) mediaColor = 'color: #28a745;';
                                    else if (media == 5) mediaColor = 'color: #ffc107;';
                                    else if (media < 5) mediaColor = 'color: #dc3545;';
                                } else {
                                    if (media > 10) mediaColor = 'color: #28a745;';
                                    else if (media == 10) mediaColor = 'color: #ffc107;';
                                    else if (media < 10) mediaColor = 'color: #dc3545;';
                                }
                                somaMedias += media;
                                qtdMedias++;
                            }
                            html += `<tr>
                                <td class="text-center"><strong>${i}º Bimestre</strong></td>
                                <td class="text-center">${nota.mac || '-'}</td>
                                <td class="text-center">${nota.npt || '-'}</td>
                                <td class="text-center">${nota.exame_normal || '-'}</td>
                                <td class="text-center">${nota.exame_recurso || '-'}</td>
                                <td class="text-center">${nota.exame_especial || '-'}</td>
                                <td class="text-center">${nota.exame_oral || '-'}</td>
                                <td class="text-center">${nota.exame_escrito || '-'}</td>
                                <td class="text-center"><strong style="${mediaColor}">${nota.media_final || '-'}</strong></td>
                                <td class="text-center"><span class="badge ${badgeClass}">${situacao}</span></td>
                             </tr>`;
                        }
                        let mediaAnual = qtdMedias > 0 ? (somaMedias / qtdMedias).toFixed(1) : 0;
                        let situacaoAnual = '';
                        let mediaAnualColor = '';
                        if (isEnsinoFundamental) {
                            if (mediaAnual > 5) {
                                situacaoAnual = 'Aprovado';
                                mediaAnualColor = '#28a745';
                            } else if (mediaAnual == 5 && mediaAnual > 0) {
                                situacaoAnual = 'Recuperação';
                                mediaAnualColor = '#ffc107';
                            } else if (mediaAnual > 0 && mediaAnual < 5) {
                                situacaoAnual = 'Reprovado';
                                mediaAnualColor = '#dc3545';
                            } else {
                                situacaoAnual = 'Pendente';
                                mediaAnualColor = '#6c757d';
                            }
                        } else {
                            if (mediaAnual > 10) {
                                situacaoAnual = 'Aprovado';
                                mediaAnualColor = '#28a745';
                            } else if (mediaAnual == 10 && mediaAnual > 0) {
                                situacaoAnual = 'Recuperação';
                                mediaAnualColor = '#ffc107';
                            } else if (mediaAnual > 0 && mediaAnual < 10) {
                                situacaoAnual = 'Reprovado';
                                mediaAnualColor = '#dc3545';
                            } else {
                                situacaoAnual = 'Pendente';
                                mediaAnualColor = '#6c757d';
                            }
                        }
                        let badgeAnualClass = situacaoAnual == 'Aprovado' ? 'bg-success' : (situacaoAnual == 'Recuperação' ? 'bg-warning' : (situacaoAnual == 'Reprovado' ? 'bg-danger' : 'bg-secondary'));
                        html += `</tbody><tfoot class="table-light"><tr><td colspan="8" class="text-end"><strong>Média Anual:</strong></td><td class="text-center"><strong style="color: ${mediaAnualColor};">${mediaAnual}</strong></td><td class="text-center"><span class="badge ${badgeAnualClass}">${situacaoAnual}</span></td></tr></tfoot></table></div><div class="alert alert-info mt-3"><i class="fas fa-info-circle"></i> <strong>Legenda de Cores:</strong><br><span style="color: #28a745;">● Aprovado</span> - Aluno aprovado (Verde)<br><span style="color: #ffc107;">● Recuperação</span> - Aluno em recuperação (Amarelo)<br><span style="color: #dc3545;">● Reprovado</span> - Aluno reprovado (Vermelho)<br><span style="color: #6c757d;">● Pendente</span> - Nota não lançada (Cinza)</div></div></div>`;
                        document.getElementById('historicoModalBody').innerHTML = html;
                    } else {
                        document.getElementById('historicoModalBody').innerHTML = `<div class="alert alert-danger">${data.message || 'Erro ao carregar histórico'}</div>`;
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    document.getElementById('historicoModalBody').innerHTML = '<div class="alert alert-danger">Erro ao carregar histórico do aluno</div>';
                });
        }
        
        $(document).ready(function() {
            $('#turma_id').val('<?php echo $turma_id; ?>');
            $('#disciplina_id').val('<?php echo $disciplina_id; ?>');
            $('#bimestre').val('<?php echo $bimestre; ?>');
        });
    </script>
</body>
</html>