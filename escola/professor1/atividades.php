<?php
// escola/professor/atividades.php - Gestão de Atividades e Trabalhos

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
$status_filtro = isset($_GET['status']) ? $_GET['status'] : '';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 10;
$offset = ($pagina - 1) * $por_pagina;

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

// ============================================
// FUNÇÃO PARA DETECTAR BIMESTRE ATUAL
// ============================================
function detectarBimestreAtual($data_atual, $ano_letivo) {
    if ($ano_letivo && $ano_letivo['data_inicio'] && $ano_letivo['data_fim']) {
        $inicio = strtotime($ano_letivo['data_inicio']);
        $fim = strtotime($ano_letivo['data_fim']);
        $hoje = strtotime($data_atual);
        $total_dias = ($fim - $inicio) / (60 * 60 * 24);
        $dias_passados = ($hoje - $inicio) / (60 * 60 * 24);
        if ($dias_passados <= $total_dias / 3) return 1;
        if ($dias_passados <= ($total_dias / 3) * 2) return 2;
        return 3;
    }
    $mes = (int)date('m', strtotime($data_atual));
    if ($mes <= 4) return 1;
    if ($mes <= 8) return 2;
    return 3;
}

// ============================================
// BUSCAR HORÁRIOS DO DIA PARA GERAÇÃO AUTOMÁTICA
// ============================================
$dias_semana = [
    'Monday' => 'segunda', 'Tuesday' => 'terca', 'Wednesday' => 'quarta',
    'Thursday' => 'quinta', 'Friday' => 'sexta', 'Saturday' => 'sabado', 'Sunday' => 'domingo'
];
$dia_atual = $dias_semana[date('l')];
$hora_atual = date('H:i:s');

$sql_aulas_hoje = "
    SELECT h.turma_id, h.disciplina_id, t.nome as turma_nome, t.ano as turma_ano,
           d.nome as disciplina_nome, d.id as disciplina_id
    FROM horarios h
    INNER JOIN turmas t ON t.id = h.turma_id
    INNER JOIN disciplinas d ON d.id = h.disciplina_id
    WHERE h.professor_id = :professor_id 
    AND h.dia_semana = :dia_semana
    AND h.horario_inicio <= :hora_atual
    AND h.horario_fim >= :hora_atual
    LIMIT 1
";

$stmt_aulas = $conn->prepare($sql_aulas_hoje);
$stmt_aulas->execute([
    ':professor_id' => $professor_id,
    ':dia_semana' => $dia_atual,
    ':hora_atual' => $hora_atual
]);
$aula_atual = $stmt_aulas->fetch(PDO::FETCH_ASSOC);

// ============================================
// GERAR ATIVIDADE AUTOMATICAMENTE
// ============================================
if (isset($_GET['gerar_automatica']) && $aula_atual) {
    $bimestre_atual = detectarBimestreAtual(date('Y-m-d'), $ano_letivo);
    
    $stmt_check = $conn->prepare("
        SELECT id FROM atividades 
        WHERE turma_id = :turma_id 
        AND disciplina_id = :disciplina_id 
        AND DATE(created_at) = CURDATE()
        AND professor_id = :professor_id
    ");
    $stmt_check->execute([
        ':turma_id' => $aula_atual['turma_id'],
        ':disciplina_id' => $aula_atual['disciplina_id'],
        ':professor_id' => $professor_id
    ]);
    
    if (!$stmt_check->fetch()) {
        $stmt_insert = $conn->prepare("
            INSERT INTO atividades (
                titulo, descricao, turma_id, disciplina_id, professor_id,
                tipo, valor_maximo, data_entrega, bimestre, status, escola_id, created_at
            ) VALUES (
                :titulo, :descricao, :turma_id, :disciplina_id, :professor_id,
                'outro', :valor_maximo, DATE_ADD(NOW(), INTERVAL 2 DAY), :bimestre, 'ativo', :escola_id, NOW()
            )
        ");
        $stmt_insert->execute([
            ':titulo' => 'Avaliação Diária',
            ':descricao' => 'Avaliação contínua sobre a aula de hoje',
            ':turma_id' => $aula_atual['turma_id'],
            ':disciplina_id' => $aula_atual['disciplina_id'],
            ':professor_id' => $professor_id,
            ':valor_maximo' => 10,
            ':bimestre' => $bimestre_atual,
            ':escola_id' => $escola_id
        ]);
        $success = "Atividade automática gerada com sucesso para a turma " . $aula_atual['turma_ano'] . 'ª ' . $aula_atual['turma_nome'];
    } else {
        $error = "Já existe uma atividade gerada automaticamente para esta turma hoje.";
    }
    header("Location: atividades.php");
    exit;
}

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
// PROCESSAR FORMULÁRIOS
// ============================================
$success = '';
$error = '';

// Adicionar atividade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'adicionar') {
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $turma_id_post = (int)$_POST['turma_id'];
    $disciplina_id_post = (int)$_POST['disciplina_id'];
    $tipo = $_POST['tipo'] ?? 'trabalho';
    $valor_maximo = (float)$_POST['valor_maximo'] ?? 10;
    $data_entrega = $_POST['data_entrega'] ?? '';
    $bimestre = (int)$_POST['bimestre'] ?? 1;
    $status = isset($_POST['status']) ? 'ativo' : 'inativo';
    
    if ($titulo && $turma_id_post && $disciplina_id_post) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO atividades (
                    titulo, descricao, turma_id, disciplina_id, professor_id,
                    tipo, valor_maximo, data_entrega, bimestre, status, escola_id, created_at
                ) VALUES (
                    :titulo, :descricao, :turma_id, :disciplina_id, :professor_id,
                    :tipo, :valor_maximo, :data_entrega, :bimestre, :status, :escola_id, NOW()
                )
            ");
            $stmt->execute([
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':turma_id' => $turma_id_post,
                ':disciplina_id' => $disciplina_id_post,
                ':professor_id' => $professor_id,
                ':tipo' => $tipo,
                ':valor_maximo' => $valor_maximo,
                ':data_entrega' => $data_entrega,
                ':bimestre' => $bimestre,
                ':status' => $status,
                ':escola_id' => $escola_id
            ]);
            $success = "Atividade adicionada com sucesso!";
        } catch (PDOException $e) {
            $error = "Erro ao adicionar atividade: " . $e->getMessage();
        }
    } else {
        $error = "Preencha os campos obrigatórios.";
    }
}

// Editar atividade
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'editar') {
    $id = (int)$_POST['id'];
    $titulo = $_POST['titulo'] ?? '';
    $descricao = $_POST['descricao'] ?? '';
    $tipo = $_POST['tipo'] ?? 'trabalho';
    $valor_maximo = (float)$_POST['valor_maximo'] ?? 10;
    $data_entrega = $_POST['data_entrega'] ?? '';
    $bimestre = (int)$_POST['bimestre'] ?? 1;
    $status = isset($_POST['status']) ? 'ativo' : 'inativo';
    
    if ($id && $titulo) {
        try {
            $stmt = $conn->prepare("
                UPDATE atividades SET 
                    titulo = :titulo,
                    descricao = :descricao,
                    tipo = :tipo,
                    valor_maximo = :valor_maximo,
                    data_entrega = :data_entrega,
                    bimestre = :bimestre,
                    status = :status,
                    updated_at = NOW()
                WHERE id = :id AND professor_id = :professor_id
            ");
            $stmt->execute([
                ':id' => $id,
                ':titulo' => $titulo,
                ':descricao' => $descricao,
                ':tipo' => $tipo,
                ':valor_maximo' => $valor_maximo,
                ':data_entrega' => $data_entrega,
                ':bimestre' => $bimestre,
                ':status' => $status,
                ':professor_id' => $professor_id
            ]);
            $success = "Atividade atualizada com sucesso!";
        } catch (PDOException $e) {
            $error = "Erro ao atualizar atividade: " . $e->getMessage();
        }
    }
}

// Excluir atividade (AJAX para modal)
if (isset($_POST['acao']) && $_POST['acao'] == 'excluir' && isset($_POST['id'])) {
    $id = (int)$_POST['id'];
    try {
        $stmt = $conn->prepare("DELETE FROM atividades WHERE id = :id AND professor_id = :professor_id");
        $stmt->execute([':id' => $id, ':professor_id' => $professor_id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// BUSCAR ATIVIDADES
// ============================================
$sql_atividades = "
    SELECT a.*, 
           t.nome as turma_nome, t.ano as turma_ano,
           d.nome as disciplina_nome,
           (SELECT COUNT(*) FROM lancamentos_notas ln WHERE ln.atividade_id = a.id) as total_lancamentos,
           (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = a.turma_id AND m.status = 'ativa') as total_alunos
    FROM atividades a
    INNER JOIN turmas t ON t.id = a.turma_id
    INNER JOIN disciplinas d ON d.id = a.disciplina_id
    WHERE a.professor_id = :professor_id
";

$params = [':professor_id' => $professor_id];

if ($turma_id > 0) {
    $sql_atividades .= " AND a.turma_id = :turma_id";
    $params[':turma_id'] = $turma_id;
}
if ($disciplina_id > 0) {
    $sql_atividades .= " AND a.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_id;
}
if (!empty($status_filtro)) {
    $sql_atividades .= " AND a.status = :status";
    $params[':status'] = $status_filtro;
}
if (!empty($busca)) {
    $sql_atividades .= " AND (a.titulo LIKE :busca OR a.descricao LIKE :busca)";
    $params[':busca'] = '%' . $busca . '%';
}

$sql_atividades .= " ORDER BY a.data_entrega ASC LIMIT :offset, :por_pagina";

$stmt_atividades = $conn->prepare($sql_atividades);
foreach ($params as $key => $value) {
    if ($key == ':offset' || $key == ':por_pagina') {
        $stmt_atividades->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt_atividades->bindValue($key, $value);
    }
}
$stmt_atividades->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_atividades->bindValue(':por_pagina', $por_pagina, PDO::PARAM_INT);
$stmt_atividades->execute();
$atividades = $stmt_atividades->fetchAll(PDO::FETCH_ASSOC);

// Contar total de atividades
$sql_count = "
    SELECT COUNT(*) as total FROM atividades a
    WHERE a.professor_id = :professor_id
";
if ($turma_id > 0) $sql_count .= " AND a.turma_id = :turma_id";
if ($disciplina_id > 0) $sql_count .= " AND a.disciplina_id = :disciplina_id";
if (!empty($status_filtro)) $sql_count .= " AND a.status = :status";
if (!empty($busca)) $sql_count .= " AND (a.titulo LIKE :busca OR a.descricao LIKE :busca)";

$stmt_count = $conn->prepare($sql_count);
$stmt_count->execute($params);
$total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Buscar atividade para edição
$atividade_editar = null;
if (isset($_GET['editar']) && isset($_GET['id'])) {
    $id_editar = (int)$_GET['id'];
    $stmt_editar = $conn->prepare("
        SELECT * FROM atividades 
        WHERE id = :id AND professor_id = :professor_id
    ");
    $stmt_editar->execute([':id' => $id_editar, ':professor_id' => $professor_id]);
    $atividade_editar = $stmt_editar->fetch(PDO::FETCH_ASSOC);
}

// ============================================
// BUSCAR DADOS PARA MODAL DE LANÇAR NOTAS
// ============================================
$show_lancar_modal = false;
$atividade_lancar = null;
$alunos_lancar = [];
$notas_existentes_lancar = [];
$contador_avaliacoes_lancar = [];
$pode_lancar = true;

if (isset($_GET['lancar']) && isset($_GET['id'])) {
    $id_atividade = (int)$_GET['id'];
    
    $stmt_atv = $conn->prepare("
        SELECT a.*, d.nome as disciplina_nome 
        FROM atividades a
        INNER JOIN disciplinas d ON d.id = a.disciplina_id
        WHERE a.id = :id AND a.professor_id = :professor_id
    ");
    $stmt_atv->execute([':id' => $id_atividade, ':professor_id' => $professor_id]);
    $atividade_lancar = $stmt_atv->fetch(PDO::FETCH_ASSOC);
    
    if ($atividade_lancar) {
        if ($atividade_lancar['data_entrega']) {
            $data_limite = date('Y-m-d', strtotime($atividade_lancar['data_entrega'] . ' + 2 days'));
            $pode_lancar = strtotime(date('Y-m-d')) <= strtotime($data_limite);
        }
        
        $show_lancar_modal = true;
        
        $stmt_alunos = $conn->prepare("
            SELECT e.id, e.nome, e.matricula, e.foto
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa'
            ORDER BY e.nome
        ");
        $stmt_alunos->execute([':turma_id' => $atividade_lancar['turma_id']]);
        $alunos_lancar = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt_notas = $conn->prepare("
            SELECT estudante_id, mac, npt, exame_normal, media_final, status
            FROM notas 
            WHERE disciplina_id = :disciplina_id 
            AND bimestre = :bimestre 
            AND ano_letivo_id = :ano_letivo_id
        ");
        $stmt_notas->execute([
            ':disciplina_id' => $atividade_lancar['disciplina_id'],
            ':bimestre' => $atividade_lancar['bimestre'],
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        while ($row = $stmt_notas->fetch(PDO::FETCH_ASSOC)) {
            $notas_existentes_lancar[$row['estudante_id']] = $row;
        }
        
        $stmt_contador = $conn->prepare("
            SELECT estudante_id, COUNT(*) as total_avaliacoes
            FROM lancamentos_notas ln
            INNER JOIN atividades a ON a.id = ln.atividade_id
            WHERE a.disciplina_id = :disciplina_id 
            AND a.bimestre = :bimestre
            AND ln.estudante_id IN (SELECT id FROM estudantes WHERE id IN (SELECT estudante_id FROM matriculas WHERE turma_id = :turma_id))
            GROUP BY ln.estudante_id
        ");
        $stmt_contador->execute([
            ':disciplina_id' => $atividade_lancar['disciplina_id'],
            ':bimestre' => $atividade_lancar['bimestre'],
            ':turma_id' => $atividade_lancar['turma_id']
        ]);
        while ($row = $stmt_contador->fetch(PDO::FETCH_ASSOC)) {
            $contador_avaliacoes_lancar[$row['estudante_id']] = $row['total_avaliacoes'];
        }
    }
}

// Processar lançamento de notas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] == 'lancar_notas') {
    $atividade_id = (int)$_POST['atividade_id'];
    
    $stmt_atv = $conn->prepare("
        SELECT turma_id, disciplina_id, bimestre, valor_maximo, data_entrega 
        FROM atividades 
        WHERE id = :id AND professor_id = :professor_id
    ");
    $stmt_atv->execute([':id' => $atividade_id, ':professor_id' => $professor_id]);
    $atv = $stmt_atv->fetch(PDO::FETCH_ASSOC);
    
    if ($atv) {
        $pode_lancar_check = true;
        if ($atv['data_entrega']) {
            $data_limite = date('Y-m-d', strtotime($atv['data_entrega'] . ' + 2 days'));
            $pode_lancar_check = strtotime(date('Y-m-d')) <= strtotime($data_limite);
        }
        
        if (!$pode_lancar_check) {
            $error = "Não é possível lançar notas! O prazo de 2 dias após a data de entrega expirou.";
        } else {
            try {
                $conn->beginTransaction();
                
                foreach ($_POST['nota'] as $estudante_id => $nota) {
                    if ($nota !== '') {
                        $nota = floatval($nota);
                        $nota_convertida = ($nota / $atv['valor_maximo']) * 10;
                        $nota_convertida = min(10, max(0, $nota_convertida));
                        
                        $stmt_check = $conn->prepare("
                            SELECT id FROM lancamentos_notas 
                            WHERE atividade_id = :atividade_id AND estudante_id = :estudante_id
                        ");
                        $stmt_check->execute([
                            ':atividade_id' => $atividade_id,
                            ':estudante_id' => $estudante_id
                        ]);
                        
                        if ($stmt_check->fetch()) {
                            $stmt_update = $conn->prepare("
                                UPDATE lancamentos_notas SET 
                                    nota = :nota, 
                                    updated_at = NOW()
                                WHERE atividade_id = :atividade_id AND estudante_id = :estudante_id
                            ");
                            $stmt_update->execute([
                                ':nota' => $nota_convertida,
                                ':atividade_id' => $atividade_id,
                                ':estudante_id' => $estudante_id
                            ]);
                        } else {
                            $stmt_insert = $conn->prepare("
                                INSERT INTO lancamentos_notas (atividade_id, estudante_id, nota, lancado_por, data_lancamento)
                                VALUES (:atividade_id, :estudante_id, :nota, :lancado_por, NOW())
                            ");
                            $stmt_insert->execute([
                                ':atividade_id' => $atividade_id,
                                ':estudante_id' => $estudante_id,
                                ':nota' => $nota_convertida,
                                ':lancado_por' => $professor_id
                            ]);
                        }
                        
                        $stmt_media = $conn->prepare("
                            SELECT AVG(nota) as media_notas
                            FROM lancamentos_notas ln
                            INNER JOIN atividades a ON a.id = ln.atividade_id
                            WHERE a.disciplina_id = :disciplina_id 
                            AND a.bimestre = :bimestre
                            AND ln.estudante_id = :estudante_id
                        ");
                        $stmt_media->execute([
                            ':disciplina_id' => $atv['disciplina_id'],
                            ':bimestre' => $atv['bimestre'],
                            ':estudante_id' => $estudante_id
                        ]);
                        $media_calculada = $stmt_media->fetch(PDO::FETCH_ASSOC);
                        $nova_mac = round($media_calculada['media_notas'] ?? $nota_convertida, 2);
                        
                        $stmt_check_nota = $conn->prepare("
                            SELECT id FROM notas 
                            WHERE estudante_id = :estudante_id 
                            AND disciplina_id = :disciplina_id 
                            AND bimestre = :bimestre 
                            AND ano_letivo_id = :ano_letivo_id
                        ");
                        $stmt_check_nota->execute([
                            ':estudante_id' => $estudante_id,
                            ':disciplina_id' => $atv['disciplina_id'],
                            ':bimestre' => $atv['bimestre'],
                            ':ano_letivo_id' => $ano_letivo_id
                        ]);
                        
                        if ($stmt_check_nota->fetch()) {
                            $stmt_update_nota = $conn->prepare("
                                UPDATE notas SET 
                                    mac = :mac,
                                    media_final = (mac + COALESCE(npt, 0)) / 2,
                                    status = CASE 
                                        WHEN (mac + COALESCE(npt, 0)) / 2 >= 10 THEN 'aprovado'
                                        WHEN (mac + COALESCE(npt, 0)) / 2 >= 7 THEN 'recuperacao'
                                        ELSE 'reprovado'
                                    END,
                                    updated_at = NOW()
                                WHERE estudante_id = :estudante_id 
                                AND disciplina_id = :disciplina_id 
                                AND bimestre = :bimestre 
                                AND ano_letivo_id = :ano_letivo_id
                            ");
                            $stmt_update_nota->execute([
                                ':mac' => $nova_mac,
                                ':estudante_id' => $estudante_id,
                                ':disciplina_id' => $atv['disciplina_id'],
                                ':bimestre' => $atv['bimestre'],
                                ':ano_letivo_id' => $ano_letivo_id
                            ]);
                        } else {
                            $stmt_insert_nota = $conn->prepare("
                                INSERT INTO notas (
                                    estudante_id, disciplina_id, professor_id, bimestre, 
                                    mac, npt, media_final, status, ano_letivo_id
                                ) VALUES (
                                    :estudante_id, :disciplina_id, :professor_id, :bimestre,
                                    :mac, 0, :mac, 
                                    CASE WHEN :mac >= 10 THEN 'aprovado' WHEN :mac >= 7 THEN 'recuperacao' ELSE 'reprovado' END,
                                    :ano_letivo_id
                                )
                            ");
                            $stmt_insert_nota->execute([
                                ':estudante_id' => $estudante_id,
                                ':disciplina_id' => $atv['disciplina_id'],
                                ':professor_id' => $professor_id,
                                ':bimestre' => $atv['bimestre'],
                                ':mac' => $nova_mac,
                                ':ano_letivo_id' => $ano_letivo_id
                            ]);
                        }
                    }
                }
                
                $conn->commit();
                $success = "Notas lançadas com sucesso! A média do MAC foi atualizada automaticamente.";
                header("Location: atividades.php?success=1");
                exit;
                
            } catch (PDOException $e) {
                $conn->rollBack();
                $error = "Erro ao lançar notas: " . $e->getMessage();
            }
        }
    }
}

function getStatusBadge($status) {
    if ($status == 'ativo') {
        return '<span class="badge bg-success">Ativo</span>';
    } else {
        return '<span class="badge bg-secondary">Inativo</span>';
    }
}

function getTipoBadge($tipo) {
    $tipos = [
        'trabalho' => '<span class="badge bg-primary">Trabalho</span>',
        'prova' => '<span class="badge bg-danger">Prova</span>',
        'exercicio' => '<span class="badge bg-info">Exercício</span>',
        'outro' => '<span class="badge bg-secondary">Outro</span>'
    ];
    return $tipos[$tipo] ?? '<span class="badge bg-secondary">' . $tipo . '</span>';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atividades | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.3em;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.8;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
            font-size: 0.95em;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
            border-left: 4px solid #FFD700;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .has-submenu {
            position: relative;
        }
        
        .has-submenu > .nav-link {
            cursor: pointer;
        }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s;
        }
        
        .has-submenu.open > .nav-link::after {
            transform: rotate(180deg);
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 55px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .has-submenu.open .nav-submenu {
            max-height: 500px;
        }
        
        .nav-submenu .nav-link {
            padding: 10px 25px;
            font-size: 0.85em;
        }
        
        .nav-submenu .nav-link i {
            font-size: 0.8em;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        
        .top-bar {
            background: white;
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .welcome-text h2 {
            font-size: 1.5em;
            margin-bottom: 5px;
        }
        
        .welcome-text p {
            color: #666;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5em;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
            .top-bar {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
        
        /* Estilos originais da página */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .card-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }
        .btn-primary {
            background: #006B3E;
            border: none;
        }
        .btn-primary:hover {
            background: #004d2d;
        }
        .btn-help {
            background: #17a2b8;
            color: white;
            border: none;
        }
        .btn-help:hover {
            background: #138496;
            color: white;
        }
        .btn-auto {
            background: #fd7e14;
            color: white;
            border: none;
        }
        .btn-auto:hover {
            background: #e66a00;
            color: white;
        }
        .atividade-card {
            transition: transform 0.2s;
            border-left: 4px solid #006B3E;
        }
        .atividade-card:hover {
            transform: translateY(-3px);
        }
        .pagination .page-link {
            color: #006B3E;
        }
        .pagination .active .page-link {
            background-color: #006B3E;
            border-color: #006B3E;
            color: white;
        }
        .data-vencida {
            color: #dc3545;
            font-weight: bold;
        }
        .data-proxima {
            color: #ffc107;
        }
        .data-normal {
            color: #28a745;
        }
        .prazo-expirado {
            background-color: #f8d7da;
            border-left-color: #dc3545;
        }
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
        .help-step:hover {
            transform: translateX(5px);
            background: #e8f5e9;
        }
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
        .help-content {
            flex: 1;
        }
        .help-content h6 {
            margin-bottom: 5px;
            color: #006B3E;
        }
        .help-content p {
            margin-bottom: 0;
            font-size: 13px;
            color: #666;
        }
        .help-icon {
            font-size: 30px;
            color: #006B3E;
            margin-right: 10px;
        }
        .modal-help .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        .btn-disabled {
            opacity: 0.6;
            pointer-events: none;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
            margin-bottom: 15px;
            display: inline-block;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar com Menu Completo -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE Angola</h3>
            <p>Área do Professor</p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-graduation-cap"></i> Menu Acadêmico
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="minhas_turmas.php" class="nav-link"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                    <li class="nav-item"><a href="lancar_notas.php" class="nav-link"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                    <li class="nav-item"><a href="registrar_chamada.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                    <li class="nav-item"><a href="atividades.php" class="nav-link active"><i class="fas fa-tasks"></i> Atividades</a></li>
                    <li class="nav-item"><a href="meus_alunos.php" class="nav-link"><i class="fas fa-users"></i> Meus Alunos</a></li>
                </ul>
            </li>
            
            <li class="nav-item">
                <a href="meu_horario.php" class="nav-link">
                    <i class="fas fa-calendar-week"></i> Meu Horário
                </a>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chart-line"></i> Relatórios
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="relatorios/index.php" class="nav-link"><i class="fas fa-home"></i> Index</a></li>
                    <li class="nav-item"><a href="relatorios/mini_pautas.php" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                    <li class="nav-item"><a href="relatorios/pautas_gerais.php" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                    <li class="nav-item"><a href="relatorios/estatistica_turma.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                    <li class="nav-item"><a href="relatorios/estatistica_disciplina.php" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
                </ul>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                    <li class="nav-item"><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
                </ul>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> Financeiro
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meu_perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                    <li class="nav-item"><a href="meu_salario.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                    <li class="nav-item"><a href="dividas_pagar.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                    <li class="nav-item"><a href="dividas_receber.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                    <li class="nav-item"><a href="solicitar_vale.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                    <li class="nav-item"><a href="solicitar_ferias.php" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
                </ul>
            </li>
            
            <li class="nav-item">
                <a href="conselho_nota.php" class="nav-link">
                    <i class="fas fa-chalkboard-user"></i> Conselho de Nota
                </a>
            </li>
            
            <li class="nav-item">
                <a href="chamada.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i> Chamada
                </a>
            </li>
            
            <li class="nav-item">
                <a href="lancamento_nota.php" class="nav-link">
                    <i class="fas fa-edit"></i> Lançamento de Nota
                </a>
            </li>
            
            <li class="nav-item">
                <a href="biblioteca.php" class="nav-link">
                    <i class="fas fa-book"></i> Biblioteca
                </a>
            </li>
            
            <li class="nav-item">
                <a href="proposta_prova.php" class="nav-link">
                    <i class="fas fa-file-alt"></i> Proposta de Prova
                </a>
            </li>
            
            <li class="nav-item">
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Botão Voltar -->
        <a href="dashboard.php" class="btn-voltar btn">
            <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
        </a>
        
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-tasks"></i> Atividades e Trabalhos</h2>
            <div>
                <button type="button" class="btn btn-help me-2" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                    <i class="fas fa-question-circle"></i> Ajuda
                </button>
                <?php if ($aula_atual): ?>
                <a href="?gerar_automatica=1" class="btn btn-auto me-2" onclick="return confirm('Deseja gerar uma atividade automática para a turma <?php echo $aula_atual['turma_ano'] . 'ª ' . $aula_atual['turma_nome']; ?>?')">
                    <i class="fas fa-magic"></i> Gerar Atividade Automática
                </a>
                <?php endif; ?>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAtividade" onclick="resetForm()">
                    <i class="fas fa-plus"></i> Nova Atividade
                </button>
            </div>
        </div>
        
        <?php if (isset($_GET['success']) || $success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle"></i> <?php echo $success ?: "Operação realizada com sucesso!"; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Turma</label>
                    <select name="turma_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas as turmas</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Disciplina</label>
                    <select name="disciplina_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Todas as disciplinas</option>
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <option value="<?php echo $disciplina['id']; ?>" <?php echo $disciplina_id == $disciplina['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($disciplina['nome']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="">Todos</option>
                        <option value="ativo" <?php echo $status_filtro == 'ativo' ? 'selected' : ''; ?>>Ativo</option>
                        <option value="inativo" <?php echo $status_filtro == 'inativo' ? 'selected' : ''; ?>>Inativo</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Buscar</label>
                    <div class="input-group">
                        <input type="text" name="busca" class="form-control" placeholder="Título ou descrição" value="<?php echo htmlspecialchars($busca); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Lista de Atividades -->
        <?php if (empty($atividades)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhuma atividade encontrada.
                <button type="button" class="btn btn-sm btn-primary ms-2" data-bs-toggle="modal" data-bs-target="#modalAtividade">
                    <i class="fas fa-plus"></i> Criar primeira atividade
                </button>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($atividades as $atividade): 
                    $prazo_expirado = false;
                    if ($atividade['data_entrega']) {
                        $data_limite = date('Y-m-d', strtotime($atividade['data_entrega'] . ' + 2 days'));
                        $prazo_expirado = strtotime(date('Y-m-d')) > strtotime($data_limite);
                    }
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card atividade-card h-100 <?php echo $prazo_expirado ? 'prazo-expirado' : ''; ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h6 class="card-title mb-0"><?php echo htmlspecialchars($atividade['titulo']); ?></h6>
                                <?php echo getStatusBadge($atividade['status']); ?>
                            </div>
                            <p class="card-text small text-muted mb-2">
                                <?php echo htmlspecialchars(substr($atividade['descricao'] ?? '', 0, 100)); ?>
                                <?php if (strlen($atividade['descricao'] ?? '') > 100) echo '...'; ?>
                            </p>
                            <div class="mb-2">
                                <?php echo getTipoBadge($atividade['tipo']); ?>
                                <span class="badge bg-secondary"><?php echo $atividade['valor_maximo']; ?> pts</span>
                                <span class="badge bg-info"><?php echo $atividade['bimestre']; ?>º Bimestre</span>
                            </div>
                            <div class="small">
                                <div><i class="fas fa-users"></i> Turma: <?php echo $atividade['turma_ano'] . 'ª ' . $atividade['turma_nome']; ?></div>
                                <div><i class="fas fa-book"></i> Disciplina: <?php echo htmlspecialchars($atividade['disciplina_nome']); ?></div>
                                <div>
                                    <i class="fas fa-calendar-alt"></i> Entrega: 
                                    <?php if ($atividade['data_entrega']): ?>
                                        <span class="<?php 
                                            echo strtotime($atividade['data_entrega']) < time() ? 'data-vencida' : 
                                                (strtotime($atividade['data_entrega']) < strtotime('+7 days') ? 'data-proxima' : 'data-normal'); 
                                        ?>">
                                            <?php echo date('d/m/Y', strtotime($atividade['data_entrega'])); ?>
                                        </span>
                                        <?php if ($prazo_expirado): ?>
                                            <br><small class="text-danger">Prazo para lançamento expirado!</small>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Sem data definida
                                    <?php endif; ?>
                                </div>
                                <div><i class="fas fa-check-circle"></i> Lançamentos: <?php echo $atividade['total_lancamentos']; ?>/<?php echo $atividade['total_alunos']; ?></div>
                            </div>
                        </div>
                        <div class="card-footer bg-white">
                            <div class="btn-group w-100">
                                <a href="?lancar=1&id=<?php echo $atividade['id']; ?>" class="btn btn-sm btn-success <?php echo $prazo_expirado ? 'btn-disabled' : ''; ?>">
                                    <i class="fas fa-pen-alt"></i> Lançar Notas
                                </a>
                                <a href="?editar=1&id=<?php echo $atividade['id']; ?>" class="btn btn-sm btn-warning">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-danger" onclick="confirmarExclusao(<?php echo $atividade['id']; ?>, '<?php echo addslashes($atividade['titulo']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Paginação -->
            <?php if ($total_paginas > 1): ?>
            <nav aria-label="Paginação" class="mt-4">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $pagina <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $pagina - 1; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=<?php echo $status_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                            <i class="fas fa-chevron-left"></i> Anterior
                        </a>
                    </li>
                    <?php
                    $inicio = max(1, $pagina - 2);
                    $fim = min($total_paginas, $pagina + 2);
                    for ($i = $inicio; $i <= $fim; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $i; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=<?php echo $status_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $pagina >= $total_paginas ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?pagina=<?php echo $pagina + 1; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&status=<?php echo $status_filtro; ?>&busca=<?php echo urlencode($busca); ?>">
                            Próximo <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade modal-help" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-question-circle"></i> Como usar a Gestão de Atividades</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-tasks help-icon"></i>
                        <h4>Gestão de Atividades e Trabalhos</h4>
                        <p class="text-muted">Organize, acompanhe e avalie as atividades dos seus alunos</p>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6><i class="fas fa-plus-circle text-primary"></i> Criar Nova Atividade</h6>
                            <p>Clique no botão <strong>"Nova Atividade"</strong> no canto superior direito. Preencha o título, descrição, selecione a turma e disciplina, defina o tipo, valor máximo, bimestre e data de entrega.</p>
                        </div>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6><i class="fas fa-magic text-primary"></i> Gerar Atividade Automática</h6>
                            <p>Clique no botão <strong>"Gerar Atividade Automática"</strong> (disponível quando você está em aula). O sistema detecta automaticamente a turma e disciplina da sua aula atual e cria uma atividade chamada "Avaliação Diária" com validade de 2 dias.</p>
                        </div>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6><i class="fas fa-filter text-primary"></i> Filtrar Atividades</h6>
                            <p>Utilize os filtros no topo da página para buscar atividades por <strong>Turma</strong>, <strong>Disciplina</strong>, <strong>Status</strong> ou por <strong>palavra-chave</strong> no título/descrição.</p>
                        </div>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">4</div>
                        <div class="help-content">
                            <h6><i class="fas fa-pen-alt text-primary"></i> Lançar Notas</h6>
                            <p>Clique no botão <strong>"Lançar Notas"</strong> no card da atividade. Você tem até <strong>2 dias após a data de entrega</strong> para lançar as notas. Após esse prazo, o botão fica desabilitado.</p>
                        </div>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">5</div>
                        <div class="help-content">
                            <h6><i class="fas fa-calculator text-primary"></i> Cálculo da Média</h6>
                            <p>O sistema calcula automaticamente a média das notas de todas as atividades do mesmo bimestre e atualiza o campo <strong>MAC</strong> na tabela de notas. Cada novo lançamento recalcula a média.</p>
                        </div>
                    </div>
                    
                    <div class="help-step">
                        <div class="help-number">6</div>
                        <div class="help-content">
                            <h6><i class="fas fa-trash-alt text-primary"></i> Excluir Atividade</h6>
                            <p>Para excluir uma atividade, clique no botão <strong>lixeira (🗑️)</strong>. Uma janela de confirmação aparecerá para evitar exclusões acidentais.</p>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mt-3">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Como funciona o cálculo da média?</strong><br>
                        Cada atividade lançada contribui para a formação da nota MAC do aluno naquele bimestre. O sistema calcula a média aritmética de todas as atividades lançadas e atualiza automaticamente o campo MAC na tabela de notas.
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Atenção:</strong> 
                        <ul class="mb-0 mt-2">
                            <li>As atividades com data de entrega vencida aparecem em <span class="text-danger">vermelho</span>, as próximas do vencimento em <span class="text-warning">amarelo</span> e as com prazo normal em <span class="text-success">verde</span>.</li>
                            <li>Você tem apenas <strong>2 dias após a data de entrega</strong> para lançar as notas. Após esse período, o lançamento é bloqueado.</li>
                            <li>O botão <strong>"Gerar Atividade Automática"</strong> só aparece quando você está em horário de aula.</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">
                        <i class="fas fa-check"></i> Entendi
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação de Exclusão -->
    <div class="modal fade" id="modalExcluir" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle"></i> Confirmar Exclusão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja excluir a atividade <strong id="atividadeNome"></strong>?</p>
                    <p class="text-danger small">Esta ação não pode ser desfeita. Todas as notas associadas a esta atividade serão removidas.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btnConfirmarExcluir">Sim, excluir</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Adicionar/Editar Atividade -->
    <div class="modal fade" id="modalAtividade" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title" id="modalTitle"><i class="fas fa-tasks"></i> Adicionar Atividade</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formAtividade">
                    <input type="hidden" name="id" id="atividade_id" value="">
                    <input type="hidden" name="acao" id="acao" value="adicionar">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label required">Título</label>
                            <input type="text" name="titulo" id="titulo" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descrição</label>
                            <textarea name="descricao" id="descricao" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Turma</label>
                                <select name="turma_id" id="turma_id_select" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>">
                                        <?php echo $turma['ano'] . 'ª - ' . $turma['nome']; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label required">Disciplina</label>
                                <select name="disciplina_id" id="disciplina_id_select" class="form-select" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($disciplinas as $disciplina): ?>
                                    <option value="<?php echo $disciplina['id']; ?>">
                                        <?php echo htmlspecialchars($disciplina['nome']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tipo</label>
                                <select name="tipo" id="tipo" class="form-select">
                                    <option value="trabalho">Trabalho</option>
                                    <option value="prova">Prova</option>
                                    <option value="exercicio">Exercício</option>
                                    <option value="outro">Outro</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Valor Máximo</label>
                                <input type="number" name="valor_maximo" id="valor_maximo" class="form-control" step="0.5" value="10">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label required">Bimestre</label>
                                <select name="bimestre" id="bimestre" class="form-select" required>
                                    <option value="1">1º Bimestre</option>
                                    <option value="2">2º Bimestre</option>
                                    <option value="3">3º Bimestre</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Data de Entrega</label>
                                <input type="date" name="data_entrega" id="data_entrega" class="form-control">
                                <small class="text-muted">Prazo para lançamento de notas: 2 dias após esta data</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check mt-4">
                                    <input class="form-check-input" type="checkbox" name="status" id="status" checked>
                                    <label class="form-check-label" for="status">
                                        Ativo
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Salvar Atividade</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Lançar Notas -->
    <?php if ($show_lancar_modal && $atividade_lancar): ?>
    <div class="modal fade show" id="modalLancarNotas" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-pen-alt"></i> Lançar Notas</h5>
                    <a href="atividades.php" class="btn-close btn-close-white"></a>
                </div>
                <form method="POST">
                    <input type="hidden" name="acao" value="lancar_notas">
                    <input type="hidden" name="atividade_id" value="<?php echo $atividade_lancar['id']; ?>">
                    <div class="modal-body">
                        <?php if (!$pode_lancar): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-ban"></i>
                            <strong>Prazo expirado!</strong> O prazo de 2 dias após a data de entrega já passou. Não é mais possível lançar notas para esta atividade.
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Atividade:</strong> <?php echo htmlspecialchars($atividade_lancar['titulo']); ?> - 
                            <strong>Disciplina:</strong> <?php echo htmlspecialchars($atividade_lancar['disciplina_nome']); ?> - 
                            <strong>Bimestre:</strong> <?php echo $atividade_lancar['bimestre']; ?>º Bimestre
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-calculator"></i>
                            <strong>Como funciona?</strong> As notas lançadas aqui serão somadas à média do MAC do aluno neste bimestre. 
                            O sistema calcula automaticamente a média de todas as atividades e atualiza o campo MAC na tabela de notas.
                        </div>
                        <?php endif; ?>
                        
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Aluno</th>
                                        <th>Matrícula</th>
                                        <th>Nota (0-<?php echo $atividade_lancar['valor_maximo']; ?>)</th>
                                        <th>MAC Atual</th>
                                        <th>Avaliações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($alunos_lancar as $index => $aluno): 
                                        $mac_atual = $notas_existentes_lancar[$aluno['id']]['mac'] ?? 0;
                                        $total_avaliacoes = $contador_avaliacoes_lancar[$aluno['id']] ?? 0;
                                    ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                        <td>
                                            <input type="number" step="0.5" min="0" max="<?php echo $atividade_lancar['valor_maximo']; ?>" 
                                                   name="nota[<?php echo $aluno['id']; ?>]" class="form-control" style="width: 120px"
                                                   <?php echo !$pode_lancar ? 'disabled' : ''; ?>>
                                            <small class="text-muted">Valor da atividade</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo number_format($mac_atual, 1); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo $total_avaliacoes; ?> avaliações</span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="atividades.php" class="btn btn-secondary">Cancelar</a>
                        <?php if ($pode_lancar): ?>
                        <button type="submit" class="btn btn-primary">Salvar Notas e Atualizar MAC</button>
                        <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>Prazo Expirado</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle submenu
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
            }
        }
        
        // Toggle sidebar mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Fechar sidebar ao clicar em link (mobile)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                }
            });
        });
        
        // Manter submenus abertos baseado na URL atual
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.nav-submenu .nav-link').forEach(link => {
            if (currentUrl.includes(link.getAttribute('href'))) {
                const parent = link.closest('.has-submenu');
                if (parent) parent.classList.add('open');
            }
        });
        
        var atividadeIdParaExcluir = null;
        
        function confirmarExclusao(id, nome) {
            console.log('confirmarExclusao chamada - ID:', id, 'Nome:', nome);
            atividadeIdParaExcluir = id;
            document.getElementById('atividadeNome').innerText = nome;
            var modalExcluir = new bootstrap.Modal(document.getElementById('modalExcluir'));
            modalExcluir.show();
        }
        
        // Garantir que o evento seja registrado após o DOM carregar
        document.addEventListener('DOMContentLoaded', function() {
            var btnConfirmar = document.getElementById('btnConfirmarExcluir');
            if (btnConfirmar) {
                btnConfirmar.addEventListener('click', function() {
                    if (atividadeIdParaExcluir) {
                        fetch('atividades.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: 'acao=excluir&id=' + atividadeIdParaExcluir
                        })
                        .then(function(response) { return response.json(); })
                        .then(function(data) {
                            if (data.success) {
                                location.reload();
                            } else {
                                alert('Erro ao excluir atividade: ' + (data.error || 'Erro desconhecido'));
                                var modal = bootstrap.Modal.getInstance(document.getElementById('modalExcluir'));
                                if (modal) modal.hide();
                            }
                        })
                        .catch(function(error) {
                            alert('Erro ao processar a requisição.');
                            var modal = bootstrap.Modal.getInstance(document.getElementById('modalExcluir'));
                            if (modal) modal.hide();
                        });
                    }
                });
            }
        });
        
        function resetForm() {
            $('#formAtividade')[0].reset();
            $('#acao').val('adicionar');
            $('#modalTitle').html('<i class="fas fa-tasks"></i> Adicionar Atividade');
            $('#atividade_id').val('');
            $('#status').prop('checked', true);
            $('#bimestre').val('1');
        }
        
        <?php if ($atividade_editar): ?>
        $(document).ready(function() {
            $('#modalTitle').html('<i class="fas fa-edit"></i> Editar Atividade');
            $('#acao').val('editar');
            $('#atividade_id').val('<?php echo $atividade_editar['id']; ?>');
            $('#titulo').val('<?php echo addslashes($atividade_editar['titulo']); ?>');
            $('#descricao').val('<?php echo addslashes($atividade_editar['descricao']); ?>');
            $('#turma_id_select').val('<?php echo $atividade_editar['turma_id']; ?>');
            $('#disciplina_id_select').val('<?php echo $atividade_editar['disciplina_id']; ?>');
            $('#tipo').val('<?php echo $atividade_editar['tipo']; ?>');
            $('#valor_maximo').val('<?php echo $atividade_editar['valor_maximo']; ?>');
            $('#bimestre').val('<?php echo $atividade_editar['bimestre']; ?>');
            $('#data_entrega').val('<?php echo $atividade_editar['data_entrega']; ?>');
            $('#status').prop('checked', <?php echo $atividade_editar['status'] == 'ativo' ? 'true' : 'false'; ?>);
            
            $('#modalAtividade').modal('show');
        });
        <?php endif; ?>
        
        // Fechar modal de lançar notas ao clicar fora
        $(document).ready(function() {
            $(document).on('click', function(e) {
                if ($('#modalLancarNotas').is(':visible') && !$(e.target).closest('.modal-content').length && !$(e.target).closest('.btn-success').length) {
                    window.location.href = 'atividades.php';
                }
            });
        });
    </script>
</body>
</html>