<?php
// escola/pedagogico/ajax_get_ranking.php
require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

header('Content-Type: application/json');

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    echo json_encode(['success' => false, 'message' => 'Acesso negado']);
    exit;
}

$escola_id = $funcionario['escola_id'];

// Função para calcular a média final de uma disciplina (igual ao lancar_notas.php)
function calcularMediaFinalDisciplina($mac, $npt, $exame_normal, $exame_recurso, $exame_especial, $exame_oral, $exame_escrito, $bimestre, $is_classe_exame, $is_disciplina_lingua) {
    $mac = floatval($mac);
    $npt = floatval($npt);
    $exame_normal = floatval($exame_normal);
    $exame_recurso = floatval($exame_recurso);
    $exame_especial = floatval($exame_especial);
    $exame_oral = floatval($exame_oral);
    $exame_escrito = floatval($exame_escrito);
    
    $media_parcial = ($mac + $npt) / 2;
    
    if ($bimestre == 3 && $is_classe_exame) {
        if ($exame_recurso > 0) {
            return round($exame_recurso, 1);
        }
        if ($is_disciplina_lingua) {
            $media_exame = 0;
            if ($exame_oral > 0 && $exame_escrito > 0) {
                $media_exame = ($exame_oral + $exame_escrito) / 2;
            } elseif ($exame_oral > 0) {
                $media_exame = $exame_oral;
            } elseif ($exame_escrito > 0) {
                $media_exame = $exame_escrito;
            }
            return round(($mac * 0.4) + ($media_exame * 0.6), 1);
        } else {
            if ($exame_normal > 0) {
                return round(($mac * 0.4) + ($exame_normal * 0.6), 1);
            }
            return round($mac, 1);
        }
    }
    
    if ($exame_recurso > 0) {
        return round(($media_parcial + $exame_recurso) / 2, 1);
    } elseif ($exame_normal > 0) {
        return round(($media_parcial + $exame_normal) / 2, 1);
    } elseif ($exame_especial > 0) {
        return round($exame_especial, 1);
    }
    return round($media_parcial, 1);
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// ============================================
// BUSCAR RANKING DOS ALUNOS
// ============================================
if ($action == 'get_ranking') {
    $ano_letivo_id = isset($_POST['ano_letivo_id']) ? (int)$_POST['ano_letivo_id'] : 0;
    $bimestre = isset($_POST['bimestre']) ? (int)$_POST['bimestre'] : 3;
    $classe_filtro = isset($_POST['classe']) ? (int)$_POST['classe'] : 0;
    
    if (!$ano_letivo_id) {
        echo json_encode(['success' => false, 'message' => 'Ano letivo não informado']);
        exit;
    }
    
    // Buscar todos os alunos matriculados com suas turmas
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            t.id as turma_id,
            t.nome as turma_nome,
            t.ano as turma_ano
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        INNER JOIN turmas t ON t.id = m.turma_id
        WHERE m.status = 'ativa' 
        AND m.ano_letivo = :ano_letivo_id
        AND t.escola_id = :escola_id
        ORDER BY e.nome ASC
    ";
    
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':ano_letivo_id' => $ano_letivo_id,
        ':escola_id' => $escola_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    $ranking = [];
    
    foreach ($alunos as $aluno) {
        $turma_id = $aluno['turma_id'];
        $classe_ano = $aluno['turma_ano'];
        $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
        
        // Buscar todas as disciplinas da turma
        $sql_disciplinas = "
            SELECT 
                d.id,
                d.nome,
                CASE 
                    WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' 
                      OR d.nome LIKE '%portugues%' OR d.nome LIKE '%ingles%' 
                    THEN 1 ELSE 0 
                END as is_lingua
            FROM disciplina_turma dt
            INNER JOIN disciplinas d ON d.id = dt.disciplina_id
            WHERE dt.turma_id = :turma_id
        ";
        $stmt_disc = $conn->prepare($sql_disciplinas);
        $stmt_disc->execute([':turma_id' => $turma_id]);
        $disciplinas = $stmt_disc->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar notas do aluno no bimestre
        $sql_notas = "
            SELECT 
                disciplina_id,
                mac, npt, exame_normal, exame_recurso, exame_especial,
                exame_oral, exame_escrito, media_final
            FROM notas
            WHERE estudante_id = :aluno_id 
            AND bimestre = :bimestre 
            AND ano_letivo_id = :ano_letivo_id
        ";
        $stmt_notas = $conn->prepare($sql_notas);
        $stmt_notas->execute([
            ':aluno_id' => $aluno['id'],
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        
        $notas_por_disciplina = [];
        foreach ($stmt_notas->fetchAll(PDO::FETCH_ASSOC) as $nota) {
            $notas_por_disciplina[$nota['disciplina_id']] = $nota;
        }
        
        // Calcular média do aluno (soma das médias das disciplinas / total disciplinas)
        $soma_notas = 0;
        $total_disciplinas = count($disciplinas);
        
        foreach ($disciplinas as $disc) {
            if (isset($notas_por_disciplina[$disc['id']])) {
                $nota = $notas_por_disciplina[$disc['id']];
                
                // Se já tem media_final calculada, usa ela
                if (isset($nota['media_final']) && $nota['media_final'] > 0) {
                    $media_disciplina = floatval($nota['media_final']);
                } else {
                    // Calcular a média usando a função
                    $media_disciplina = calcularMediaFinalDisciplina(
                        $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                        $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                        $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                        $nota['exame_escrito'] ?? 0, $bimestre, $is_classe_exame, $disc['is_lingua']
                    );
                }
                $soma_notas += $media_disciplina;
            }
            // Se não tem nota, a disciplina contribui com 0
        }
        
        $media_geral = $total_disciplinas > 0 ? round($soma_notas / $total_disciplinas, 1) : 0;
        
        $ranking[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'turma_nome' => $aluno['turma_nome'],
            'classe' => $aluno['turma_ano'],
            'media' => $media_geral
        ];
    }
    
    // Ordenar por média (decrescente)
    usort($ranking, function($a, $b) {
        if ($b['media'] == $a['media']) return 0;
        return ($b['media'] > $a['media']) ? 1 : -1;
    });
    
    // Filtrar por classe se necessário
    if ($classe_filtro > 0) {
        $ranking = array_filter($ranking, function($aluno) use ($classe_filtro) {
            return $aluno['classe'] == $classe_filtro;
        });
        $ranking = array_values($ranking);
    }
    
    echo json_encode(['success' => true, 'data' => $ranking]);
    exit;
}

// ============================================
// BUSCAR DETALHES DO ALUNO (FICHA)
// ============================================
if ($action == 'get_aluno_detalhes') {
    $aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
    $bimestre = isset($_POST['bimestre']) ? (int)$_POST['bimestre'] : 3;
    $ano_letivo_id = isset($_POST['ano_letivo_id']) ? (int)$_POST['ano_letivo_id'] : 0;
    
    if (!$aluno_id || !$ano_letivo_id) {
        echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
        exit;
    }
    
    // Buscar dados do aluno e sua turma
    $sql_aluno = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.data_nascimento,
            e.genero,
            e.foto,
            t.id as turma_id,
            t.nome as turma_nome,
            t.ano as turma_ano
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        INNER JOIN turmas t ON t.id = m.turma_id
        WHERE e.id = :aluno_id 
        AND m.status = 'ativa'
        AND m.ano_letivo = :ano_letivo_id
        LIMIT 1
    ";
    
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([
        ':aluno_id' => $aluno_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if (!$aluno) {
        echo json_encode(['success' => false, 'message' => 'Aluno não encontrado']);
        exit;
    }
    
    $classe_ano = $aluno['turma_ano'];
    $escala = ($classe_ano <= 6) ? '0-10' : '0-20';
    $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    
    // Buscar todas as disciplinas da turma com as notas do aluno
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            CASE 
                WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' 
                  OR d.nome LIKE '%portugues%' OR d.nome LIKE '%ingles%' 
                THEN 1 ELSE 0 
            END as is_lingua,
            COALESCE(n.mac, 0) as mac,
            COALESCE(n.npt, 0) as npt,
            COALESCE(n.exame_normal, 0) as exame_normal,
            COALESCE(n.exame_recurso, 0) as exame_recurso,
            COALESCE(n.exame_especial, 0) as exame_especial,
            COALESCE(n.exame_oral, 0) as exame_oral,
            COALESCE(n.exame_escrito, 0) as exame_escrito,
            COALESCE(n.media_final, 0) as media_final,
            n.status
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        LEFT JOIN notas n ON n.disciplina_id = d.id 
            AND n.estudante_id = :aluno_id 
            AND n.bimestre = :bimestre 
            AND n.ano_letivo_id = :ano_letivo_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([
        ':aluno_id' => $aluno_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id,
        ':turma_id' => $aluno['turma_id']
    ]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular média geral do aluno
    $soma_notas = 0;
    $total_disciplinas = count($disciplinas);
    $disciplinas_com_nota = 0;
    
    foreach ($disciplinas as &$disc) {
        // Calcular a média da disciplina
        $media_disciplina = calcularMediaFinalDisciplina(
            $disc['mac'], $disc['npt'], $disc['exame_normal'], $disc['exame_recurso'],
            $disc['exame_especial'], $disc['exame_oral'], $disc['exame_escrito'],
            $bimestre, $is_classe_exame, $disc['is_lingua']
        );
        
        if ($media_disciplina > 0) {
            $disciplinas_com_nota++;
        }
        
        $soma_notas += $media_disciplina;
        $disc['media_final'] = $media_disciplina > 0 ? number_format($media_disciplina, 1) : '-';
        
        // Definir classe CSS para a nota
        if ($media_disciplina >= $limite_aprovacao) {
            $disc['nota_class'] = 'nota-alta';
        } elseif ($media_disciplina > 0) {
            $disc['nota_class'] = 'nota-baixa';
        } else {
            $disc['nota_class'] = '';
        }
        
        // Definir status
        if ($disc['status']) {
            $status_map = ['aprovado' => 'Aprovado', 'recuperacao' => 'Recuperação', 'reprovado' => 'Reprovado', 'pendente' => 'Pendente'];
            $disc['status'] = $status_map[$disc['status']] ?? 'Pendente';
        } else {
            if ($media_disciplina == 0) {
                $disc['status'] = 'Sem nota';
            } elseif ($media_disciplina >= $limite_aprovacao) {
                $disc['status'] = 'Aprovado';
            } elseif ($media_disciplina >= $limite_aprovacao * 0.7) {
                $disc['status'] = 'Recuperação';
            } else {
                $disc['status'] = 'Reprovado';
            }
        }
    }
    
    $media_geral = $total_disciplinas > 0 ? round($soma_notas / $total_disciplinas, 1) : 0;
    
    // Definir status geral
    if ($media_geral == 0) {
        $status_geral = 'Sem nota';
    } elseif ($media_geral >= $limite_aprovacao) {
        $status_geral = 'Aprovado';
    } elseif ($media_geral >= $limite_aprovacao * 0.7) {
        $status_geral = 'Recuperação';
    } else {
        $status_geral = 'Reprovado';
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'bi' => $aluno['bi'] ?? 'N/A',
            'data_nascimento' => $aluno['data_nascimento'] ?? '',
            'genero' => $aluno['genero'] ?? '',
            'turma_nome' => $aluno['turma_nome'],
            'turma_ano' => $aluno['turma_ano'],
            'escala' => $escala,
            'limite_aprovacao' => $limite_aprovacao,
            'media_geral' => $media_geral > 0 ? number_format($media_geral, 1) : '-',
            'status_geral' => $status_geral,
            'total_disciplinas' => $total_disciplinas,
            'disciplinas_com_nota' => $disciplinas_com_nota,
            'disciplinas' => $disciplinas
        ]
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação não reconhecida']);
?>