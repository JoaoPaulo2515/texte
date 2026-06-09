<?php
// escola/pedagogico/lancar_notas.php - Lançar Notas dos Alunos
// VERSÃO CORRIGIDA - A TABELA USA O MESMO CÁLCULO DA FICHA DO ALUNO

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
$professor_id = $funcionario['id'] ?? 0;

// Buscar turmas da escola
if ($funcionario['usuario_tipo'] == 'professor') {
    $sql_turmas = "
        SELECT DISTINCT
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        LEFT JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
        WHERE t.escola_id = :escola_id 
        AND t.status = 'ativa'
        AND pdt.professor_id = :professor_id
        GROUP BY t.id
        ORDER BY t.ano ASC, t.nome ASC
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([
        ':escola_id' => $escola_id,
        ':professor_id' => $professor_id
    ]);
} else {
    $sql_turmas = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.escola_id = :escola_id AND t.status = 'ativa'
        ORDER BY t.ano ASC, t.nome ASC
    ";
    $stmt_turmas = $conn->prepare($sql_turmas);
    $stmt_turmas->execute([':escola_id' => $escola_id]);
}
$turmas_lista = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Obter parâmetros de filtro
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;

// Se não tem turma selecionada e tem turmas disponíveis, pegar a primeira
if ($turma_id == 0 && !empty($turmas_lista)) {
    $turma_id = $turmas_lista[0]['id'];
    $ano_letivo_id = $turmas_lista[0]['ano_letivo_id'];
}

// Buscar dados da turma
$turma = null;
if ($turma_id > 0) {
    $sql_turma = "
        SELECT 
            t.id,
            t.nome,
            t.ano,
            tr.nome as turno_nome,
            t.ano_letivo_id,
            al.ano as ano_letivo_ano
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        LEFT JOIN ano_letivo al ON al.id = t.ano_letivo_id
        WHERE t.id = :turma_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':turma_id' => $turma_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if ($turma && $ano_letivo_id == 0) {
        $ano_letivo_id = $turma['ano_letivo_id'];
    }
}

// Buscar disciplinas da turma
$disciplinas = [];
if ($turma_id > 0) {
    $sql_disciplinas = "
        SELECT 
            d.id,
            d.nome,
            d.codigo,
            d.carga_horaria
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    if ($disciplina_id == 0 && !empty($disciplinas)) {
        $disciplina_id = $disciplinas[0]['id'];
    }
}

// Buscar alunos da turma
$alunos = [];
if ($turma_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.foto,
            m.id as matricula_id
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// Determinar se é classe de exame
$classe_ano = $turma['ano'] ?? 0;
$is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
$is_ensino_fundamental = ($classe_ano <= 6);
$limite_aprovacao = $is_ensino_fundamental ? 5 : 10;
$escala_max = $is_ensino_fundamental ? 10 : 20;

// Verificar se a disciplina é de língua
$disciplina_nome = '';
foreach ($disciplinas as $d) {
    if ($d['id'] == $disciplina_id) {
        $disciplina_nome = $d['nome'];
        break;
    }
}
$is_disciplina_lingua = (stripos($disciplina_nome, 'português') !== false || 
                          stripos($disciplina_nome, 'inglês') !== false ||
                          stripos($disciplina_nome, 'portugues') !== false ||
                          stripos($disciplina_nome, 'ingles') !== false);

// Buscar notas existentes para a disciplina atual
$notas_existentes = [];
if ($turma_id > 0 && $disciplina_id > 0 && $ano_letivo_id > 0) {
    $sql_notas = "
        SELECT 
            id,
            estudante_id,
            mac,
            npt,
            exame_normal,
            exame_recurso,
            exame_especial,
            exame_oral,
            exame_escrito,
            media_parcial,
            media_final,
            status,
            observacao_academica
        FROM notas
        WHERE turma_id = :turma_id 
        AND disciplina_id = :disciplina_id 
        AND bimestre = :bimestre
        AND ano_letivo_id = :ano_letivo_id
    ";
    $stmt_notas = $conn->prepare($sql_notas);
    $stmt_notas->execute([
        ':turma_id' => $turma_id,
        ':disciplina_id' => $disciplina_id,
        ':bimestre' => $bimestre,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $notas_existentes = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
    
    $notas_por_aluno = [];
    foreach ($notas_existentes as $nota) {
        $notas_por_aluno[$nota['estudante_id']] = $nota;
    }
}

// Buscar anos letivos
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES PARA CALCULAR MÉDIA (MESMA LÓGICA DO MODAL)
// ============================================

// Função para calcular a média final de uma disciplina (igual ao modal)
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

// Função para obter o status da disciplina
function getStatusDisciplina($media, $limite_aprovacao) {
    if ($media == 0) return 'pendente';
    if ($media >= $limite_aprovacao) return 'aprovado';
    if ($media >= $limite_aprovacao * 0.7) return 'recuperacao';
    return 'reprovado';
}

// Processar lançamento de notas via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar_ajax') {
    header('Content-Type: application/json');
    
    $estudante_id = (int)$_POST['estudante_id'];
    $bimestre_post = (int)$_POST['bimestre'];
    $is_classe_exame_post = isset($_POST['is_classe_exame']) ? (bool)$_POST['is_classe_exame'] : $is_classe_exame;
    $is_disciplina_lingua_post = isset($_POST['is_disciplina_lingua']) ? (bool)$_POST['is_disciplina_lingua'] : $is_disciplina_lingua;
    
    $mac = isset($_POST['mac']) ? (float)str_replace(',', '.', $_POST['mac']) : 0;
    $npt = isset($_POST['npt']) ? (float)str_replace(',', '.', $_POST['npt']) : 0;
    $exame_normal = isset($_POST['exame_normal']) ? (float)str_replace(',', '.', $_POST['exame_normal']) : 0;
    $exame_recurso = isset($_POST['exame_recurso']) ? (float)str_replace(',', '.', $_POST['exame_recurso']) : 0;
    $exame_especial = isset($_POST['exame_especial']) ? (float)str_replace(',', '.', $_POST['exame_especial']) : 0;
    $exame_oral = isset($_POST['exame_oral']) ? (float)str_replace(',', '.', $_POST['exame_oral']) : 0;
    $exame_escrito = isset($_POST['exame_escrito']) ? (float)str_replace(',', '.', $_POST['exame_escrito']) : 0;
    $observacao = trim($_POST['observacao'] ?? '');
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $ano_letivo_id_post = (int)$_POST['ano_letivo_id'];
    
    try {
        // Calcular média final usando a mesma função
        $media_final = calcularMediaFinalDisciplina(
            $mac, $npt, $exame_normal, $exame_recurso, $exame_especial,
            $exame_oral, $exame_escrito, $bimestre_post, $is_classe_exame_post, $is_disciplina_lingua_post
        );
        
        $status = getStatusDisciplina($media_final, $limite_aprovacao);
        
        // Verificar se já existe nota
        $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND turma_id = :turma_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':estudante_id' => $estudante_id,
            ':disciplina_id' => $disciplina_id_post,
            ':turma_id' => $turma_id_post,
            ':bimestre' => $bimestre_post,
            ':ano_letivo_id' => $ano_letivo_id_post
        ]);
        $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        $media_parcial = round(($mac + $npt) / 2, 1);
        
        if ($existe) {
            $sql = "
                UPDATE notas SET
                    mac = :mac, npt = :npt, exame_normal = :exame_normal,
                    exame_recurso = :exame_recurso, exame_especial = :exame_especial,
                    exame_oral = :exame_oral, exame_escrito = :exame_escrito,
                    media_parcial = :media_parcial, media_final = :media_final,
                    status = :status, observacao_academica = :observacao,
                    data_lancamento = NOW(), updated_at = NOW()
                WHERE id = :id
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':mac' => $mac, ':npt' => $npt, ':exame_normal' => $exame_normal,
                ':exame_recurso' => $exame_recurso, ':exame_especial' => $exame_especial,
                ':exame_oral' => $exame_oral, ':exame_escrito' => $exame_escrito,
                ':media_parcial' => $media_parcial, ':media_final' => $media_final,
                ':status' => $status, ':observacao' => $observacao,
                ':id' => $existe['id']
            ]);
        } else {
            $sql = "
                INSERT INTO notas (
                    estudante_id, disciplina_id, turma_id, escola_id,
                    bimestre, ano_letivo_id, professor_id,
                    mac, npt, exame_normal, exame_recurso, exame_especial,
                    exame_oral, exame_escrito,
                    media_parcial, media_final, status,
                    observacao_academica, data_lancamento, created_at
                ) VALUES (
                    :estudante_id, :disciplina_id, :turma_id, :escola_id,
                    :bimestre, :ano_letivo_id, :professor_id,
                    :mac, :npt, :exame_normal, :exame_recurso, :exame_especial,
                    :exame_oral, :exame_escrito,
                    :media_parcial, :media_final, :status,
                    :observacao, NOW(), NOW()
                )
            ";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':estudante_id' => $estudante_id, ':disciplina_id' => $disciplina_id_post,
                ':turma_id' => $turma_id_post, ':escola_id' => $escola_id,
                ':bimestre' => $bimestre_post, ':ano_letivo_id' => $ano_letivo_id_post,
                ':professor_id' => $professor_id ?: null,
                ':mac' => $mac, ':npt' => $npt, ':exame_normal' => $exame_normal,
                ':exame_recurso' => $exame_recurso, ':exame_especial' => $exame_especial,
                ':exame_oral' => $exame_oral, ':exame_escrito' => $exame_escrito,
                ':media_parcial' => $media_parcial, ':media_final' => $media_final,
                ':status' => $status, ':observacao' => $observacao
            ]);
        }
        
        echo json_encode(['success' => true, 'media_final' => $media_final, 'status' => $status]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
    }
    exit;
}

// Processar busca de notas para o modal via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'buscar_notas_aluno') {
    header('Content-Type: application/json');
    
    $aluno_id = (int)$_POST['aluno_id'];
    
    if (!$aluno_id) {
        echo json_encode(['success' => false, 'message' => 'Parâmetros inválidos']);
        exit;
    }
    
    try {
        // Buscar todas as notas do aluno
        $sql = "
            SELECT 
                n.*,
                d.nome as disciplina_nome,
                t.nome as turma_nome,
                t.ano as turma_ano,
                al.ano as ano_letivo
            FROM notas n
            INNER JOIN disciplinas d ON d.id = n.disciplina_id
            INNER JOIN turmas t ON t.id = n.turma_id
            INNER JOIN ano_letivo al ON al.id = n.ano_letivo_id
            WHERE n.estudante_id = :aluno_id AND n.escola_id = :escola_id
            ORDER BY al.ano DESC, n.bimestre ASC
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
        $notas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'notas' => $notas
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar notas: ' . $e->getMessage()]);
    }
    exit;
}

// Processar lançamento de notas (submit normal)
$mensagem = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'salvar') {
    $salvos = 0;
    $erros = 0;
    
    foreach ($_POST['notas'] as $estudante_id => $nota_data) {
        $mac = isset($nota_data['mac']) ? (float)str_replace(',', '.', $nota_data['mac']) : 0;
        $npt = isset($nota_data['npt']) ? (float)str_replace(',', '.', $nota_data['npt']) : 0;
        $exame_normal = isset($nota_data['exame_normal']) ? (float)str_replace(',', '.', $nota_data['exame_normal']) : 0;
        $exame_recurso = isset($nota_data['exame_recurso']) ? (float)str_replace(',', '.', $nota_data['exame_recurso']) : 0;
        $exame_especial = isset($nota_data['exame_especial']) ? (float)str_replace(',', '.', $nota_data['exame_especial']) : 0;
        $exame_oral = isset($nota_data['exame_oral']) ? (float)str_replace(',', '.', $nota_data['exame_oral']) : 0;
        $exame_escrito = isset($nota_data['exame_escrito']) ? (float)str_replace(',', '.', $nota_data['exame_escrito']) : 0;
        $observacao = trim($nota_data['observacao'] ?? '');
        
        try {
            $media_final = calcularMediaFinalDisciplina(
                $mac, $npt, $exame_normal, $exame_recurso, $exame_especial,
                $exame_oral, $exame_escrito, $bimestre, $is_classe_exame, $is_disciplina_lingua
            );
            
            $status = getStatusDisciplina($media_final, $limite_aprovacao);
            $media_parcial = round(($mac + $npt) / 2, 1);
            
            // Verificar se já existe
            $sql_check = "SELECT id FROM notas WHERE estudante_id = :estudante_id AND disciplina_id = :disciplina_id AND turma_id = :turma_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo_id";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->execute([
                ':estudante_id' => $estudante_id,
                ':disciplina_id' => $disciplina_id,
                ':turma_id' => $turma_id,
                ':bimestre' => $bimestre,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            $existe = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($existe) {
                $sql = "
                    UPDATE notas SET
                        mac = :mac, npt = :npt, exame_normal = :exame_normal,
                        exame_recurso = :exame_recurso, exame_especial = :exame_especial,
                        exame_oral = :exame_oral, exame_escrito = :exame_escrito,
                        media_parcial = :media_parcial, media_final = :media_final,
                        status = :status, observacao_academica = :observacao,
                        data_lancamento = NOW(), updated_at = NOW()
                    WHERE id = :id
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':mac' => $mac, ':npt' => $npt, ':exame_normal' => $exame_normal,
                    ':exame_recurso' => $exame_recurso, ':exame_especial' => $exame_especial,
                    ':exame_oral' => $exame_oral, ':exame_escrito' => $exame_escrito,
                    ':media_parcial' => $media_parcial, ':media_final' => $media_final,
                    ':status' => $status, ':observacao' => $observacao,
                    ':id' => $existe['id']
                ]);
            } else {
                $sql = "
                    INSERT INTO notas (
                        estudante_id, disciplina_id, turma_id, escola_id,
                        bimestre, ano_letivo_id, professor_id,
                        mac, npt, exame_normal, exame_recurso, exame_especial,
                        exame_oral, exame_escrito,
                        media_parcial, media_final, status,
                        observacao_academica, data_lancamento, created_at
                    ) VALUES (
                        :estudante_id, :disciplina_id, :turma_id, :escola_id,
                        :bimestre, :ano_letivo_id, :professor_id,
                        :mac, :npt, :exame_normal, :exame_recurso, :exame_especial,
                        :exame_oral, :exame_escrito,
                        :media_parcial, :media_final, :status,
                        :observacao, NOW(), NOW()
                    )
                ";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':estudante_id' => $estudante_id, ':disciplina_id' => $disciplina_id,
                    ':turma_id' => $turma_id, ':escola_id' => $escola_id,
                    ':bimestre' => $bimestre, ':ano_letivo_id' => $ano_letivo_id,
                    ':professor_id' => $professor_id ?: null,
                    ':mac' => $mac, ':npt' => $npt, ':exame_normal' => $exame_normal,
                    ':exame_recurso' => $exame_recurso, ':exame_especial' => $exame_especial,
                    ':exame_oral' => $exame_oral, ':exame_escrito' => $exame_escrito,
                    ':media_parcial' => $media_parcial, ':media_final' => $media_final,
                    ':status' => $status, ':observacao' => $observacao
                ]);
            }
            $salvos++;
        } catch (PDOException $e) {
            $erros++;
        }
    }
    
    if ($erros == 0) {
        $mensagem = "Notas salvas com sucesso! ($salvos registros)";
        // Recarregar notas
        $stmt_notas->execute([
            ':turma_id' => $turma_id, ':disciplina_id' => $disciplina_id,
            ':bimestre' => $bimestre, ':ano_letivo_id' => $ano_letivo_id
        ]);
        $notas_existentes = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);
        $notas_por_aluno = [];
        foreach ($notas_existentes as $nota) {
            $notas_por_aluno[$nota['estudante_id']] = $nota;
        }
    } else {
        $erro = "Erro ao salvar notas. $salvos salvos, $erros erros.";
    }
}

// Preparar dados para exibição na tabela
foreach ($alunos as &$aluno) {
    $nota = isset($notas_por_aluno[$aluno['id']]) ? $notas_por_aluno[$aluno['id']] : null;
    
    if ($nota) {
        $aluno['mac_exibir'] = number_format($nota['mac'], 1);
        $aluno['npt_exibir'] = number_format($nota['npt'], 1);
        $aluno['exame_normal_exibir'] = number_format($nota['exame_normal'], 1);
        $aluno['exame_recurso_exibir'] = number_format($nota['exame_recurso'], 1);
        $aluno['exame_especial_exibir'] = number_format($nota['exame_especial'], 1);
        $aluno['exame_oral_exibir'] = number_format($nota['exame_oral'], 1);
        $aluno['exame_escrito_exibir'] = number_format($nota['exame_escrito'], 1);
        $aluno['media_final'] = number_format($nota['media_final'], 1);
        $aluno['status'] = $nota['status'];
    } else {
        $aluno['mac_exibir'] = '';
        $aluno['npt_exibir'] = '';
        $aluno['exame_normal_exibir'] = '';
        $aluno['exame_recurso_exibir'] = '';
        $aluno['exame_especial_exibir'] = '';
        $aluno['exame_oral_exibir'] = '';
        $aluno['exame_escrito_exibir'] = '';
        $aluno['media_final'] = '';
        $aluno['status'] = '';
    }
}

function getNotaStyle($nota, $is_ensino_fundamental) {
    if ($nota === '' || $nota === null) return '';
    $nota_val = floatval($nota);
    if ($is_ensino_fundamental) {
        if ($nota_val >= 4.5) return 'color: #1e5799; font-weight: bold; background-color: #e8f4fd;';
        if ($nota_val > 0) return 'color: #c0392b; font-weight: bold; background-color: #fdeaea;';
    } else {
        if ($nota_val >= 9.5) return 'color: #1e5799; font-weight: bold; background-color: #e8f4fd;';
        if ($nota_val > 0) return 'color: #c0392b; font-weight: bold; background-color: #fdeaea;';
    }
    return '';
}

$caminho_base = '/sige_Plataforma/uploads/alunos/';
$mensagem = $mensagem ?? '';
$erro = $erro ?? '';
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançar Notas - SIGE Angola</title>
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
        
        .filtros-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .filtros-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .filtros-body { padding: 20px; }
        
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-bar {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        
        .info-grid {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            justify-content: space-around;
        }
        
        .info-item { text-align: center; }
        .info-label { font-size: 12px; color: #7f8c8d; margin-bottom: 5px; }
        .info-value { font-size: 18px; font-weight: bold; color: #1e5799; }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 12px 20px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .card-body { padding: 0; overflow-x: auto; }
        
        .table-notas {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-notas th {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-weight: 600;
            color: #2c3e50;
            border-bottom: 2px solid #1e5799;
            font-size: 12px;
        }
        
        .table-notas td {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-notas tr:hover { background: #f8f9fa; }
        
        .aluno-info {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
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
        
        .nota-input {
            width: 70px;
            padding: 6px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .nota-input:focus {
            outline: none;
            border-color: #1e5799;
            box-shadow: 0 0 0 2px rgba(30, 87, 153, 0.1);
        }
        
        .nota-input.saving { background-color: #fff3cd; border-color: #ffc107; }
        .nota-input.saved { background-color: #d5f4e6; border-color: #27ae60; }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        
        .status-aprovado { background: #d5f4e6; color: #27ae60; }
        .status-recuperacao { background: #fef9e7; color: #f39c12; }
        .status-reprovado { background: #fadbd8; color: #c0392b; }
        .status-pendente { background: #ecf0f1; color: #7f8c8d; }
        
        .btn-salvar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin: 20px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .alert-success { background: #d5f4e6; color: #27ae60; border-left: 4px solid #27ae60; }
        .alert-danger { background: #fadbd8; color: #c0392b; border-left: 4px solid #c0392b; }
        .alert-info { background: #d4e6f1; color: #1e5799; border-left: 4px solid #1e5799; }
        
        .media-cell { font-weight: bold; background: #ecf0f1; }
        .form-actions { text-align: right; }
        
        .bimestre-warning {
            background: #fef9e7;
            border-left: 4px solid #f39c12;
            padding: 10px 15px;
            margin-bottom: 15px;
            border-radius: 8px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #27ae60;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 9999;
            display: none;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        .modal-historico {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-historico-content {
            background: white;
            margin: 2% auto;
            width: 95%;
            max-width: 1300px;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-historico-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .modal-historico-header h2 { font-size: 20px; }
        
        .close-historico {
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .close-historico:hover { color: #ddd; }
        
        .modal-historico-body {
            display: flex;
            padding: 20px;
            gap: 25px;
            flex-wrap: wrap;
        }
        
        .aluno-sidebar {
            flex: 0 0 280px;
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }
        
        .aluno-foto-grande {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #1e5799;
            margin-bottom: 15px;
        }
        
        .aluno-foto-placeholder-grande {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            margin: 0 auto 15px auto;
        }
        
        .aluno-info-sidebar p {
            margin: 8px 0;
            font-size: 14px;
        }
        
        .aluno-info-sidebar strong { color: #2c3e50; }
        
        .notas-container {
            flex: 1;
            overflow-x: auto;
        }
        
        .bimestre-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 2px solid #ecf0f1;
            padding-bottom: 10px;
        }
        
        .tab-btn {
            padding: 8px 20px;
            background: #ecf0f1;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.3s;
        }
        
        .tab-btn.active {
            background: #1e5799;
            color: white;
        }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .table-notas-historico {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        .table-notas-historico th {
            background: #1e5799;
            color: white;
            padding: 10px;
            text-align: center;
            font-size: 12px;
        }
        
        .table-notas-historico td {
            padding: 8px;
            border-bottom: 1px solid #ecf0f1;
            text-align: center;
        }
        
        .table-notas-historico tr:hover { background: #f8f9fa; }
        
        .disciplina-group {
            margin-bottom: 25px;
            border: 1px solid #ecf0f1;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .disciplina-header {
            background: #34495e;
            color: white;
            padding: 10px 15px;
            font-weight: bold;
        }
        
        .status-badge-small {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-notas { font-size: 11px; }
            .table-notas th, .table-notas td { padding: 6px; }
            .nota-input { width: 55px; font-size: 11px; }
            .aluno-foto, .aluno-foto-placeholder { width: 35px; height: 35px; font-size: 14px; }
            .modal-historico-body { flex-direction: column; }
            .aluno-sidebar { flex: auto; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div class="header-title">
            <h1>📝 Lançar Notas</h1>
            <p>Registre as notas dos alunos por disciplina e bimestre</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">← Voltar ao Dashboard</a>
        </div>
    </div>
    
    <?php if ($mensagem): ?>
        <div class="alert alert-success">✅ <?php echo htmlspecialchars($mensagem); ?></div>
    <?php endif; ?>
    
    <?php if ($erro): ?>
        <div class="alert alert-danger">❌ <?php echo htmlspecialchars($erro); ?></div>
    <?php endif; ?>
    
    <div id="toastMessage" class="toast-notification"></div>
    
    <!-- Filtros -->
    <div class="filtros-card">
        <div class="filtros-header">🔍 Filtrar</div>
        <div class="filtros-body">
            <form method="GET" action="" id="formFiltros" onchange="this.submit()">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select" required>
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas_lista as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['nome']); ?> - <?php echo $t['ano']; ?>ª - <?php echo ucfirst($t['turno_nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="filtro-select" required>
                            <option value="">Selecione a disciplina</option>
                            <?php foreach ($disciplinas as $disc): ?>
                                <option value="<?php echo $disc['id']; ?>" <?php echo ($disciplina_id == $disc['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($disc['nome']); ?> (<?php echo htmlspecialchars($disc['codigo']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="1" <?php echo ($bimestre == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                            <option value="4" <?php echo ($bimestre == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Ano Letivo</label>
                        <select name="ano_letivo_id" class="filtro-select">
                            <option value="">Todos os anos</option>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">🔍 Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma && $disciplina_id > 0): ?>
        <div class="info-bar">
            <div class="info-grid">
                <div class="info-item"><div class="info-label">📚 Turma</div><div class="info-value"><?php echo htmlspecialchars($turma['nome']); ?></div></div>
                <div class="info-item"><div class="info-label">📖 Disciplina</div><div class="info-value"><?php foreach ($disciplinas as $d) { if ($d['id'] == $disciplina_id) { echo htmlspecialchars($d['nome']); break; } } ?></div></div>
                <div class="info-item"><div class="info-label">📅 Bimestre</div><div class="info-value"><?php echo $bimestre; ?>º Bimestre</div></div>
                <div class="info-item"><div class="info-label">👨‍🎓 Alunos</div><div class="info-value"><?php echo count($alunos); ?></div></div>
                <div class="info-item"><div class="info-label">📊 Escala</div><div class="info-value">0-<?php echo $escala_max; ?></div></div>
                <div class="info-item"><div class="info-label">📊 Limite Aprovação</div><div class="info-value"><?php echo $limite_aprovacao; ?> pontos</div></div>
            </div>
        </div>
        
        <?php if ($bimestre == 3): ?>
            <div class="bimestre-warning">
                ⚠️ <strong>3º Bimestre:</strong> 
                <?php if ($is_classe_exame): ?>
                    Para classes de exame (<?php echo $classe_ano; ?>ª Classe), a nota final é calculada como: 
                    <?php if ($is_disciplina_lingua): ?>
                        40% MAC + 60% (Média do Exame Oral e Escrito). Exame de Recurso substitui a nota final.
                    <?php else: ?>
                        40% MAC + 60% Exame Normal. Exame de Recurso substitui a nota final.
                    <?php endif; ?>
                <?php else: ?>
                    A média é calculada como (MAC + NPT)/2. Exames complementares substituem a média.
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <!-- Tabela de Notas -->
        <div class="card">
            <div class="card-header">📝 Lançamento de Notas - <?php echo $bimestre; ?>º Bimestre</div>
            <div class="card-body">
                <form method="POST" action="" id="formNotas">
                    <input type="hidden" name="action" value="salvar">
                    <input type="hidden" name="bimestre" value="<?php echo $bimestre; ?>">
                    
                    <div class="table-responsive">
                        <table class="table-notas">
                            <thead>
                                <tr>
                                    <th style="width: 20%; text-align: left;">Aluno</th>
                                    <th style="width: 10%;">Matrícula</th>
                                    <?php if ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                                        <th style="width: 10%;">MAC<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Exame Oral<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Exame Escrito<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Exame Recurso<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Média</th>
                                        <th style="width: 12%;">Status</th>
                                    <?php elseif ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                                        <th style="width: 14%;">MAC<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 14%;">Exame Normal<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 14%;">Exame Recurso<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Média</th>
                                        <th style="width: 12%;">Status</th>
                                    <?php elseif ($bimestre == 1 || $bimestre == 2): ?>
                                        <th style="width: 18%;">MAC<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 18%;">NPT<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Média</th>
                                        <th style="width: 12%;">Status</th>
                                    <?php else: ?>
                                        <th style="width: 10%;">MAC<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">NPT<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Exame Normal<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Exame Especial<br><small>(0-<?php echo $escala_max; ?>)</small></th>
                                        <th style="width: 10%;">Média</th>
                                        <th style="width: 12%;">Status</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos as $aluno): 
                                    $inicial = strtoupper(substr($aluno['nome'], 0, 1));
                                    $foto_path = !empty($aluno['foto']) ? $caminho_base . $aluno['foto'] : '';
                                    $tem_foto = !empty($aluno['foto']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $foto_path);
                                    
                                    $status_class = '';
                                    $status_text = '';
                                    if ($aluno['status'] == 'aprovado') { $status_class = 'status-aprovado'; $status_text = 'Aprovado';
                                    } elseif ($aluno['status'] == 'recuperacao') { $status_class = 'status-recuperacao'; $status_text = 'Recuperação';
                                    } elseif ($aluno['status'] == 'reprovado') { $status_class = 'status-reprovado'; $status_text = 'Reprovado';
                                    } else { $status_class = 'status-pendente'; $status_text = 'Pendente'; }
                                    
                                    $mac_style = getNotaStyle($aluno['mac_exibir'], $is_ensino_fundamental);
                                    $npt_style = getNotaStyle($aluno['npt_exibir'], $is_ensino_fundamental);
                                    $exame_normal_style = getNotaStyle($aluno['exame_normal_exibir'], $is_ensino_fundamental);
                                    $exame_recurso_style = getNotaStyle($aluno['exame_recurso_exibir'], $is_ensino_fundamental);
                                    $exame_oral_style = getNotaStyle($aluno['exame_oral_exibir'], $is_ensino_fundamental);
                                    $exame_escrito_style = getNotaStyle($aluno['exame_escrito_exibir'], $is_ensino_fundamental);
                                    $media_style = getNotaStyle($aluno['media_final'], $is_ensino_fundamental);
                                ?>
                                    <tr>
                                        <td style="text-align: left;">
                                            <div class="aluno-info" onclick="abrirModalHistorico(<?php echo $aluno['id']; ?>, '<?php echo htmlspecialchars($aluno['nome']); ?>', '<?php echo htmlspecialchars($aluno['matricula']); ?>', '<?php echo $aluno['bi'] ?? ''; ?>', '<?php echo $foto_path; ?>', '<?php echo $inicial; ?>')">
                                                <?php if ($tem_foto && $foto_path): ?>
                                                    <img src="<?php echo $foto_path; ?>" class="aluno-foto" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                                    <div class="aluno-foto-placeholder" style="display: none;"><?php echo $inicial; ?></div>
                                                <?php else: ?>
                                                    <div class="aluno-foto-placeholder"><?php echo $inicial; ?></div>
                                                <?php endif; ?>
                                                <div><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></div>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                        
                                        <?php if ($bimestre == 3 && $is_classe_exame && $is_disciplina_lingua): ?>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][mac]" class="nota-input" value="<?php echo $aluno['mac_exibir']; ?>" style="<?php echo $mac_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_oral]" class="nota-input" value="<?php echo $aluno['exame_oral_exibir']; ?>" style="<?php echo $exame_oral_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_escrito]" class="nota-input" value="<?php echo $aluno['exame_escrito_exibir']; ?>" style="<?php echo $exame_escrito_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_recurso]" class="nota-input" value="<?php echo $aluno['exame_recurso_exibir']; ?>" style="<?php echo $exame_recurso_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                        <?php elseif ($bimestre == 3 && $is_classe_exame && !$is_disciplina_lingua): ?>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][mac]" class="nota-input" value="<?php echo $aluno['mac_exibir']; ?>" style="<?php echo $mac_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_normal]" class="nota-input" value="<?php echo $aluno['exame_normal_exibir']; ?>" style="<?php echo $exame_normal_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_recurso]" class="nota-input" value="<?php echo $aluno['exame_recurso_exibir']; ?>" style="<?php echo $exame_recurso_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                        <?php elseif ($bimestre == 1 || $bimestre == 2): ?>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][mac]" class="nota-input" value="<?php echo $aluno['mac_exibir']; ?>" style="<?php echo $mac_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][npt]" class="nota-input" value="<?php echo $aluno['npt_exibir']; ?>" style="<?php echo $npt_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                        <?php else: ?>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][mac]" class="nota-input" value="<?php echo $aluno['mac_exibir']; ?>" style="<?php echo $mac_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][npt]" class="nota-input" value="<?php echo $aluno['npt_exibir']; ?>" style="<?php echo $npt_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_normal]" class="nota-input" value="<?php echo $aluno['exame_normal_exibir']; ?>" style="<?php echo $exame_normal_style; ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                            <td><input type="number" step="0.1" min="0" max="<?php echo $escala_max; ?>" name="notas[<?php echo $aluno['id']; ?>][exame_especial]" class="nota-input" value="<?php echo $aluno['exame_especial_exibir']; ?>" style="<?php echo getNotaStyle($aluno['exame_especial_exibir'], $is_ensino_fundamental); ?>" data-aluno="<?php echo $aluno['id']; ?>" onchange="salvarNotaAjax(this, <?php echo $aluno['id']; ?>)"></td>
                                        <?php endif; ?>
                                        
                                        <td class="media-cell" id="media_<?php echo $aluno['id']; ?>" style="<?php echo $media_style; ?>"><?php echo $aluno['media_final']; ?></td>
                                        <td><span class="status-badge <?php echo $status_class; ?>" id="status_<?php echo $aluno['id']; ?>"><?php echo $status_text; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-salvar">💾 Salvar Todas as Notas</button>
                    </div>
                </form>
            </div>
        </div>
        
    <?php elseif ($turma_id > 0 && !$disciplina_id): ?>
        <div class="alert alert-info">ℹ️ Selecione uma disciplina para lançar as notas.</div>
    <?php elseif ($turma_id > 0 && empty($alunos)): ?>
        <div class="alert alert-info">ℹ️ Nenhum aluno matriculado nesta turma.</div>
    <?php elseif (empty($turmas_lista)): ?>
        <div class="alert alert-info">ℹ️ Nenhuma turma disponível.</div>
    <?php endif; ?>
</div>

<!-- Modal de Histórico do Aluno -->
<div id="modalHistorico" class="modal-historico">
    <div class="modal-historico-content">
        <div class="modal-historico-header">
            <h2>📊 Histórico Acadêmico</h2>
            <span class="close-historico" onclick="fecharModalHistorico()">&times;</span>
        </div>
        <div class="modal-historico-body" id="modalHistoricoBody">
            <div class="aluno-sidebar" id="alunoSidebar">
                <div id="alunoFotoContainer"></div>
                <div class="aluno-info-sidebar" id="alunoInfoSidebar"></div>
            </div>
            <div class="notas-container" id="notasContainer">
                <div class="bimestre-tabs" id="bimestreTabs"></div>
                <div id="bimestreContent"></div>
            </div>
        </div>
    </div>
</div>

<script>
    function abrirModalHistorico(alunoId, nome, matricula, bi, fotoPath, inicial) {
        const modal = document.getElementById('modalHistorico');
        
        const fotoContainer = document.getElementById('alunoFotoContainer');
        const infoSidebar = document.getElementById('alunoInfoSidebar');
        const notasContainer = document.getElementById('notasContainer');
        
        if (!fotoContainer || !infoSidebar || !notasContainer) {
            console.error('Elementos do modal não encontrados');
            return;
        }
        
        if (fotoPath && fotoPath !== '') {
            fotoContainer.innerHTML = `<img src="${fotoPath}" class="aluno-foto-grande" alt="Foto" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">`;
        } else {
            fotoContainer.innerHTML = `<div class="aluno-foto-placeholder-grande">${inicial}</div>`;
        }
        
        infoSidebar.innerHTML = `
            <p><strong>👨‍🎓 Nome:</strong> ${nome}</p>
            <p><strong>🔢 Matrícula:</strong> ${matricula}</p>
            <p><strong>🆔 BI:</strong> ${bi || 'N/A'}</p>
        `;
        
        notasContainer.innerHTML = '<p style="padding: 20px; text-align: center;">⏳ Carregando notas...</p>';
        
        const formData = new FormData();
        formData.append('action', 'buscar_notas_aluno');
        formData.append('aluno_id', alunoId);
        
        fetch('lancar_notas.php', { 
            method: 'POST', 
            body: formData 
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.notas) {
                renderizarNotas(data.notas);
            } else {
                notasContainer.innerHTML = '<p style="padding: 20px; text-align: center;">📚 Nenhuma nota encontrada para este aluno.</p>';
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            notasContainer.innerHTML = '<p style="padding: 20px; text-align: center;">❌ Erro ao carregar notas.</p>';
        });
        
        modal.style.display = 'block';
    }
    
    function renderizarNotas(notas) {
        const bimestreTabs = document.getElementById('bimestreTabs');
        const bimestreContent = document.getElementById('bimestreContent');
        
        if (!bimestreTabs || !bimestreContent) return;
        
        if (!notas || notas.length === 0) {
            bimestreContent.innerHTML = '<p style="padding: 20px; text-align: center;">Nenhuma nota registrada.</p>';
            return;
        }
        
        const notasPorDisciplina = {};
        notas.forEach(nota => {
            if (!notasPorDisciplina[nota.disciplina_id]) {
                notasPorDisciplina[nota.disciplina_id] = {
                    nome: nota.disciplina_nome,
                    bimestres: {1: null, 2: null, 3: null, 4: null}
                };
            }
            if (nota.bimestre >= 1 && nota.bimestre <= 4) {
                notasPorDisciplina[nota.disciplina_id].bimestres[nota.bimestre] = nota;
            }
        });
        
        const bims = [1, 2, 3, 4];
        bimestreTabs.innerHTML = '';
        bims.forEach(b => {
            const btn = document.createElement('button');
            btn.className = 'tab-btn' + (b === 1 ? ' active' : '');
            btn.textContent = `${b}º Bimestre`;
            btn.onclick = () => {
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                btn.classList.add('active');
                document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
                const targetTab = document.getElementById(`bimestre_${b}`);
                if (targetTab) targetTab.classList.add('active');
            };
            bimestreTabs.appendChild(btn);
        });
        
        bimestreContent.innerHTML = '';
        bims.forEach(b => {
            const tabDiv = document.createElement('div');
            tabDiv.id = `bimestre_${b}`;
            tabDiv.className = 'tab-content' + (b === 1 ? ' active' : '');
            
            let html = '';
            for (const [discId, discData] of Object.entries(notasPorDisciplina)) {
                const nota = discData.bimestres[b];
                html += `
                    <div class="disciplina-group">
                        <div class="disciplina-header">📖 ${discData.nome}</div>
                        <table class="table-notas-historico">
                            <thead>
                                <tr>
                                    <th>MAC</th>
                                    <th>NPT</th>
                                    <th>Exame Normal</th>
                                    <th>Exame Recurso</th>
                                    <th>Exame Especial</th>
                                    <th>Média Final</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>${nota && nota.mac ? parseFloat(nota.mac).toFixed(1) : '-'}</td>
                                    <td>${nota && nota.npt ? parseFloat(nota.npt).toFixed(1) : '-'}</td>
                                    <td>${nota && nota.exame_normal ? parseFloat(nota.exame_normal).toFixed(1) : '-'}</td>
                                    <td>${nota && nota.exame_recurso ? parseFloat(nota.exame_recurso).toFixed(1) : '-'}</td>
                                    <td>${nota && nota.exame_especial ? parseFloat(nota.exame_especial).toFixed(1) : '-'}</td>
                                    <td><strong>${nota && nota.media_final ? parseFloat(nota.media_final).toFixed(1) : '-'}</strong></td>
                                    <td>${nota ? `<span class="status-badge-small ${nota.status === 'aprovado' ? 'status-aprovado' : (nota.status === 'recuperacao' ? 'status-recuperacao' : (nota.status === 'reprovado' ? 'status-reprovado' : 'status-pendente'))}">${nota.status === 'aprovado' ? 'Aprovado' : (nota.status === 'recuperacao' ? 'Recuperação' : (nota.status === 'reprovado' ? 'Reprovado' : 'Pendente'))}</span>` : '-'}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                `;
            }
            if (html === '') {
                html = '<p style="padding: 20px; text-align: center;">Nenhuma nota registrada para este bimestre.</p>';
            }
            tabDiv.innerHTML = html;
            bimestreContent.appendChild(tabDiv);
        });
    }
    
    function fecharModalHistorico() {
        document.getElementById('modalHistorico').style.display = 'none';
    }
    
    function showToast(message, isError = false) {
        const toast = document.getElementById('toastMessage');
        if (toast) {
            toast.textContent = message;
            toast.style.backgroundColor = isError ? '#e74c3c' : '#27ae60';
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 2000);
        }
    }
    
    function atualizarMediaEStatus(alunoId, mediaFinal, status, isEnsinoFundamental) {
        const mediaCell = document.getElementById(`media_${alunoId}`);
        const statusCell = document.getElementById(`status_${alunoId}`);
        if (mediaCell) {
            mediaCell.textContent = mediaFinal > 0 ? mediaFinal.toFixed(1) : '';
            if (isEnsinoFundamental) {
                if (mediaFinal >= 4.5) mediaCell.style.color = '#1e5799';
                else if (mediaFinal > 0) mediaCell.style.color = '#c0392b';
            } else {
                if (mediaFinal >= 9.5) mediaCell.style.color = '#1e5799';
                else if (mediaFinal > 0) mediaCell.style.color = '#c0392b';
            }
            mediaCell.style.fontWeight = 'bold';
        }
        if (statusCell) {
            let statusText = '', statusClass = '';
            if (status === 'aprovado') { statusText = 'Aprovado'; statusClass = 'status-aprovado'; }
            else if (status === 'recuperacao') { statusText = 'Recuperação'; statusClass = 'status-recuperacao'; }
            else if (status === 'reprovado') { statusText = 'Reprovado'; statusClass = 'status-reprovado'; }
            else { statusText = 'Pendente'; statusClass = 'status-pendente'; }
            statusCell.textContent = statusText;
            statusCell.className = `status-badge ${statusClass}`;
        }
    }
    
    let saveTimeouts = {};
    let currentBimestre = <?php echo $bimestre; ?>;
    let isClasseExame = <?php echo $is_classe_exame ? 'true' : 'false'; ?>;
    let isDisciplinaLingua = <?php echo $is_disciplina_lingua ? 'true' : 'false'; ?>;
    let isEnsinoFundamental = <?php echo $is_ensino_fundamental ? 'true' : 'false'; ?>;
    let escalaMax = <?php echo $escala_max; ?>;
    let limiteAprovacao = <?php echo $limite_aprovacao; ?>;
    
    function salvarNotaAjax(input, alunoId) {
        if (saveTimeouts[alunoId]) clearTimeout(saveTimeouts[alunoId]);
        saveTimeouts[alunoId] = setTimeout(() => {
            const row = input.closest('tr');
            let mac = 0, npt = 0, exameNormal = 0, exameRecurso = 0, exameEspecial = 0, exameOral = 0, exameEscrito = 0;
            
            if (currentBimestre == 3 && isClasseExame && isDisciplinaLingua) {
                mac = parseFloat(row.querySelector('input[name*="[mac]"]')?.value) || 0;
                exameOral = parseFloat(row.querySelector('input[name*="[exame_oral]"]')?.value) || 0;
                exameEscrito = parseFloat(row.querySelector('input[name*="[exame_escrito]"]')?.value) || 0;
                exameRecurso = parseFloat(row.querySelector('input[name*="[exame_recurso]"]')?.value) || 0;
            } else if (currentBimestre == 3 && isClasseExame && !isDisciplinaLingua) {
                mac = parseFloat(row.querySelector('input[name*="[mac]"]')?.value) || 0;
                exameNormal = parseFloat(row.querySelector('input[name*="[exame_normal]"]')?.value) || 0;
                exameRecurso = parseFloat(row.querySelector('input[name*="[exame_recurso]"]')?.value) || 0;
            } else if (currentBimestre == 1 || currentBimestre == 2) {
                mac = parseFloat(row.querySelector('input[name*="[mac]"]')?.value) || 0;
                npt = parseFloat(row.querySelector('input[name*="[npt]"]')?.value) || 0;
            } else {
                mac = parseFloat(row.querySelector('input[name*="[mac]"]')?.value) || 0;
                npt = parseFloat(row.querySelector('input[name*="[npt]"]')?.value) || 0;
                exameNormal = parseFloat(row.querySelector('input[name*="[exame_normal]"]')?.value) || 0;
                exameEspecial = parseFloat(row.querySelector('input[name*="[exame_especial]"]')?.value) || 0;
            }
            
            let mediaParcial = (mac + npt) / 2;
            let mediaFinal = 0;
            
            if (currentBimestre == 3 && isClasseExame) {
                if (exameRecurso > 0) {
                    mediaFinal = exameRecurso;
                } else if (isDisciplinaLingua) {
                    let mediaExame = 0;
                    if (exameOral > 0 && exameEscrito > 0) mediaExame = (exameOral + exameEscrito) / 2;
                    else if (exameOral > 0) mediaExame = exameOral;
                    else if (exameEscrito > 0) mediaExame = exameEscrito;
                    mediaFinal = (mac * 0.4) + (mediaExame * 0.6);
                } else if (exameNormal > 0) {
                    mediaFinal = (mac * 0.4) + (exameNormal * 0.6);
                } else {
                    mediaFinal = mac;
                }
            } else {
                if (exameRecurso > 0) mediaFinal = (mediaParcial + exameRecurso) / 2;
                else if (exameNormal > 0) mediaFinal = (mediaParcial + exameNormal) / 2;
                else if (exameEspecial > 0) mediaFinal = exameEspecial;
                else mediaFinal = mediaParcial;
            }
            
            mediaFinal = Math.round(mediaFinal * 10) / 10;
            let status = mediaFinal >= limiteAprovacao ? 'aprovado' : (mediaFinal >= limiteAprovacao * 0.7 ? 'recuperacao' : (mediaFinal > 0 ? 'reprovado' : 'pendente'));
            
            input.classList.add('saving');
            const formData = new FormData();
            formData.append('action', 'salvar_ajax');
            formData.append('estudante_id', alunoId);
            formData.append('bimestre', currentBimestre);
            formData.append('is_classe_exame', isClasseExame);
            formData.append('is_disciplina_lingua', isDisciplinaLingua);
            formData.append('mac', mac);
            formData.append('npt', npt);
            formData.append('exame_normal', exameNormal);
            formData.append('exame_recurso', exameRecurso);
            formData.append('exame_especial', exameEspecial);
            formData.append('exame_oral', exameOral);
            formData.append('exame_escrito', exameEscrito);
            formData.append('turma_id', <?php echo $turma_id; ?>);
            formData.append('disciplina_id', <?php echo $disciplina_id; ?>);
            formData.append('ano_letivo_id', <?php echo $ano_letivo_id; ?>);
            
            fetch('lancar_notas.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    input.classList.remove('saving');
                    if (data.success) {
                        input.classList.add('saved');
                        setTimeout(() => input.classList.remove('saved'), 1000);
                        atualizarMediaEStatus(alunoId, data.media_final || mediaFinal, data.status || status, isEnsinoFundamental);
                        showToast('✅ Nota salva com sucesso!');
                        const notaVal = parseFloat(input.value) || 0;
                        if (isEnsinoFundamental) {
                            if (notaVal >= 4.5) input.style.cssText = 'color: #1e5799; font-weight: bold; background-color: #e8f4fd;';
                            else if (notaVal > 0) input.style.cssText = 'color: #c0392b; font-weight: bold; background-color: #fdeaea;';
                        } else {
                            if (notaVal >= 9.5) input.style.cssText = 'color: #1e5799; font-weight: bold; background-color: #e8f4fd;';
                            else if (notaVal > 0) input.style.cssText = 'color: #c0392b; font-weight: bold; background-color: #fdeaea;';
                        }
                    } else {
                        showToast('❌ ' + data.message, true);
                    }
                })
                .catch(error => { input.classList.remove('saving'); showToast('❌ Erro de conexão', true); });
        }, 500);
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('modalHistorico');
        if (event.target == modal) {
            fecharModalHistorico();
        }
    }
</script>
</body>
</html>