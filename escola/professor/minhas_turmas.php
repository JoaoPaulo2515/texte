<?php

// escola/professor/minhas_turmas.php - Minhas Turmas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];


// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim, ativo FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute();
$ano_letivo_data = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);

if (!$ano_letivo_data) {
    // Se não houver ano letivo ativo, buscar o mais recente
    $sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim, ativo FROM ano_letivo ORDER BY ano DESC LIMIT 1";
    $stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
    $stmt_ano_letivo->execute();
    $ano_letivo_data = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
}

$ano_letivo_id = $ano_letivo_data ? $ano_letivo_data['id'] : 1;
$ano_letivo_ano = $ano_letivo_data ? $ano_letivo_data['ano'] : date('Y');

// ============================================
// PROCESSAR EXPORTAÇÃO CSV
// ============================================
if (isset($_GET['exportar']) && $_GET['exportar'] == 'lista_nominal' && isset($_GET['turma_id'])) {
    $turma_id_export = (int)$_GET['turma_id'];
    
    // Buscar dados da escola
    $sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :escola_id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':escola_id' => $escola_id]);
    $escola_export = $stmt_escola->fetch(PDO::FETCH_ASSOC);
    $nome_escola = $escola_export ? $escola_export['nome'] : $professor['escola_nome'];
    
    // Buscar dados da turma
    $sql_turma = "SELECT nome, ano, turno, sala, capacidade FROM turmas WHERE id = :turma_id AND escola_id = :escola_id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':turma_id' => $turma_id_export, ':escola_id' => $escola_id]);
    $turma_export = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if (!$turma_export) {
        die('Turma não encontrada!');
    }
    
    // Buscar alunos da turma
    $sql_alunos_export = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.data_nascimento,
            e.email,
            e.telefone,
            e.bi,
            e.genero,
            e.endereco,
            e.pai_nome,
            e.mae_nome,
            e.encarregado_nome,
            m.data_matricula
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos_export = $conn->prepare($sql_alunos_export);
    $stmt_alunos_export->execute([':turma_id' => $turma_id_export]);
    $alunos_export = $stmt_alunos_export->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas
    $total_alunos = count($alunos_export);
    $total_masculino = 0;
    $total_feminino = 0;
    $soma_idades = 0;
    
    foreach ($alunos_export as $aluno) {
        if ($aluno['genero'] == 'M') $total_masculino++;
        if ($aluno['genero'] == 'F') $total_feminino++;
        
        if (!empty($aluno['data_nascimento'])) {
            $data_nasc = new DateTime($aluno['data_nascimento']);
            $hoje = new DateTime();
            $soma_idades += $data_nasc->diff($hoje)->y;
        }
    }
    $media_idades = $total_alunos > 0 ? round($soma_idades / $total_alunos, 1) : 0;
    $percentual_ocupacao = ($turma_export['capacidade'] > 0) ? round(($total_alunos / $turma_export['capacidade']) * 100, 1) : 0;
    
    // Definir cabeçalhos para download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lista_nominal_' . $turma_export['ano'] . 'ano_' . $turma_export['nome'] . '_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Adicionar BOM para UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Cabeçalho do relatório
    fputcsv($output, [str_repeat('=', 75)]);
    fputcsv($output, [strtoupper($nome_escola)]);
    if (!empty($escola_export['endereco'])) {
        fputcsv($output, [$escola_export['endereco']]);
    }
    $contato = '';
    if (!empty($escola_export['telefone'])) $contato .= 'Tel: ' . $escola_export['telefone'];
    if (!empty($escola_export['email'])) $contato .= ($contato ? ' | ' : '') . 'Email: ' . $escola_export['email'];
    if (!empty($contato)) {
        fputcsv($output, [$contato]);
    }
    if (!empty($escola_export['nif'])) {
        fputcsv($output, ['NIF: ' . $escola_export['nif']]);
    }
    fputcsv($output, [str_repeat('=', 75)]);
    fputcsv($output, []);
    fputcsv($output, ['LISTA NOMINAL DE ALUNOS']);
    fputcsv($output, []);
    
    // Dados da turma
    fputcsv($output, ['DADOS DA TURMA']);
    fputcsv($output, ['Turma:', $turma_export['ano'] . 'ª ' . $turma_export['nome']]);
    fputcsv($output, ['Turno:', ucfirst($turma_export['turno'])]);
    fputcsv($output, ['Sala:', $turma_export['sala'] ?: 'Não definida']);
    if ($turma_export['capacidade']) {
        fputcsv($output, ['Capacidade:', $turma_export['capacidade'] . ' alunos']);
    }
    fputcsv($output, ['Professor:', $professor['nome']]);
    fputcsv($output, ['Data de Emissão:', date('d/m/Y H:i:s')]);
    fputcsv($output, []);
    
    // Estatísticas
    fputcsv($output, ['ESTATÍSTICAS']);
    fputcsv($output, ['Total de Alunos:', $total_alunos]);
    fputcsv($output, ['Alunos Masculino:', $total_masculino]);
    fputcsv($output, ['Alunos Feminino:', $total_feminino]);
    fputcsv($output, ['Média de Idade:', $media_idades . ' anos']);
    fputcsv($output, ['Ocupação da Turma:', $percentual_ocupacao . '%']);
    fputcsv($output, []);
    fputcsv($output, [str_repeat('=', 75)]);
    fputcsv($output, []);
    
    // Tabela principal
    fputcsv($output, ['LISTA DETALHADA DE ALUNOS']);
    fputcsv($output, []);
    fputcsv($output, ['Nº', 'NOME COMPLETO', 'MATRÍCULA', 'GÉNERO', 'IDADE', 'DATA NASC.', 'BI', 'TELEFONE', 'CONTATO EMERG.']);
    fputcsv($output, ['---', str_repeat('-', 30), '--------', '------', '-----', '----------', '--------', '---------', '-------------']);
    
    foreach ($alunos_export as $index => $aluno) {
        $idade = '';
        if (!empty($aluno['data_nascimento'])) {
            $data_nasc = new DateTime($aluno['data_nascimento']);
            $hoje = new DateTime();
            $idade = $data_nasc->diff($hoje)->y;
        }
        
        $genero = '';
        if ($aluno['genero'] == 'M') {
            $genero = 'MASCULINO';
        } elseif ($aluno['genero'] == 'F') {
            $genero = 'FEMININO';
        }
        
        fputcsv($output, [
            $index + 1,
            strtoupper($aluno['nome']),
            $aluno['matricula'],
            $genero,
            $idade,
            !empty($aluno['data_nascimento']) ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '',
            $aluno['bi'] ?? '---',
            $aluno['telefone'] ?? '---',
            $aluno['encarregado_nome'] ?? $aluno['telefone'] ?? '---'
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['Total de Alunos:', $total_alunos]);
    fputcsv($output, []);
    fputcsv($output, [str_repeat('=', 75)]);
    fputcsv($output, []);
    
    // Informações complementares
    fputcsv($output, ['INFORMAÇÕES COMPLEMENTARES DOS ALUNOS']);
    fputcsv($output, []);
    fputcsv($output, ['Nº', 'NOME COMPLETO', 'ENDEREÇO', 'NOME DO PAI', 'NOME DA MÃE', 'ENCARREGADO', 'E-MAIL']);
    fputcsv($output, ['---', str_repeat('-', 30), str_repeat('-', 30), str_repeat('-', 25), str_repeat('-', 25), str_repeat('-', 25), str_repeat('-', 30)]);
    
    foreach ($alunos_export as $index => $aluno) {
        fputcsv($output, [
            $index + 1,
            strtoupper($aluno['nome']),
            $aluno['endereco'] ?? '---',
            $aluno['pai_nome'] ?? '---',
            $aluno['mae_nome'] ?? '---',
            $aluno['encarregado_nome'] ?? '---',
            $aluno['email'] ?? '---'
        ]);
    }
    
    fputcsv($output, []);
    fputcsv($output, [str_repeat('=', 75)]);
    fputcsv($output, []);
    
    // Assinaturas
    fputcsv($output, ['ASSINATURAS']);
    fputcsv($output, []);
    fputcsv($output, ['_________________________________________']);
    fputcsv($output, [strtoupper($professor['nome'])]);
    fputcsv($output, ['Professor(a) Responsável']);
    fputcsv($output, []);
    fputcsv($output, ['_________________________________________']);
    fputcsv($output, [strtoupper($nome_escola)]);
    fputcsv($output, ['Direção / Carimbo da Escola']);
    fputcsv($output, []);
    fputcsv($output, [str_repeat('=', 75)]);
    fputcsv($output, []);
    
    // Rodapé
    fputcsv($output, ['Sistema Integrado de Gestão Escolar (SIGE) - Angola']);
    fputcsv($output, ['Documento emitido em: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, ['Gerado por: ' . strtoupper($professor['nome'])]);
    fputcsv($output, [str_repeat('=', 75)]);
    
    fclose($output);
    exit;
}


// ============================================
// PROCESSAR SALVAR OBSERVAÇÃO
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['salvar_observacao'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
        $turma_id = isset($_POST['turma_id']) ? (int)$_POST['turma_id'] : 0;
        $bimestre = isset($_POST['bimestre']) ? (int)$_POST['bimestre'] : 1;
        $observacao = isset($_POST['observacao']) ? trim($_POST['observacao']) : '';
        
        // Buscar o professor_id e escola_id da sessão
        $professor_id = $professor['professor_id'];
        $escola_id = $professor['escola_id'];
        
        // Buscar disciplina_id (se não veio, buscar uma do professor para esta turma)
        $disciplina_id = isset($_POST['disciplina_id']) ? (int)$_POST['disciplina_id'] : 0;
        
        if ($disciplina_id == 0) {
            $sql_disciplina = "
                SELECT disciplina_id 
                FROM professor_disciplina_turma 
                WHERE professor_id = :professor_id AND turma_id = :turma_id 
                LIMIT 1
            ";
            $stmt_disciplina = $conn->prepare($sql_disciplina);
            $stmt_disciplina->execute([
                ':professor_id' => $professor_id,
                ':turma_id' => $turma_id
            ]);
            $disciplina = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);
            $disciplina_id = $disciplina ? $disciplina['disciplina_id'] : 0;
        }
        
        // Buscar ano letivo ativo
        $sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
        $stmt_ano = $conn->prepare($sql_ano);
        $stmt_ano->execute();
        $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
        
        if (!$ano_letivo) {
            $sql_ano = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC LIMIT 1";
            $stmt_ano = $conn->prepare($sql_ano);
            $stmt_ano->execute();
            $ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
        }
        
        $ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
        
        // Validar dados obrigatórios
        if ($aluno_id <= 0 || $turma_id <= 0 || $professor_id <= 0 || $escola_id <= 0 || $disciplina_id <= 0) {
            echo json_encode([ 
                'success' => false, 
                'message' => 'Dados inválidos: aluno_id=' . $aluno_id . ', turma_id=' . $turma_id . ', professor_id=' . $professor_id . ', escola_id=' . $escola_id . ', disciplina_id=' . $disciplina_id 
            ]);
            exit;
        }
        
        // Verificar se a tabela notas tem o campo observacao_academica
        $sql_check_column = "SHOW COLUMNS FROM notas LIKE 'observacao_academica'";
        $stmt_check = $conn->prepare($sql_check_column);
        $stmt_check->execute();
        if ($stmt_check->rowCount() == 0) {
            $sql_add_column = "ALTER TABLE notas ADD COLUMN observacao_academica TEXT NULL";
            $conn->exec($sql_add_column);
        }
        
        // Verificar se a tabela notas tem o campo escola_id
        $sql_check_column_escola = "SHOW COLUMNS FROM notas LIKE 'escola_id'";
        $stmt_check_escola = $conn->prepare($sql_check_column_escola);
        $stmt_check_escola->execute();
        if ($stmt_check_escola->rowCount() == 0) {
            $sql_add_column_escola = "ALTER TABLE notas ADD COLUMN escola_id INT NULL";
            $conn->exec($sql_add_column_escola);
            
            // Adicionar a chave estrangeira se necessário
            $sql_add_foreign = "ALTER TABLE notas ADD CONSTRAINT fk_notas_escola FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE ON UPDATE CASCADE";
            try {
                $conn->exec($sql_add_foreign);
            } catch (Exception $e) {
                // Chave já existe ou erro ignorado
                error_log("Erro ao adicionar foreign key: " . $e->getMessage());
            }
        }
        
        // Verificar se já existe registro
        $sql_check = "
            SELECT id FROM notas 
            WHERE estudante_id = :aluno_id 
            AND turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            AND professor_id = :professor_id
            AND escola_id = :escola_id
            AND bimestre = :bimestre 
            AND ano_letivo_id = :ano_letivo_id
        ";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':aluno_id' => $aluno_id,
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id,
            ':professor_id' => $professor_id,
            ':escola_id' => $escola_id,
            ':bimestre' => $bimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        
        if ($stmt_check->fetch()) {
            // Atualizar observação existente
            $sql_update = "
                UPDATE notas 
                SET observacao_academica = :observacao,
                    updated_at = NOW()
                WHERE estudante_id = :aluno_id 
                AND turma_id = :turma_id 
                AND disciplina_id = :disciplina_id
                AND professor_id = :professor_id
                AND escola_id = :escola_id
                AND bimestre = :bimestre 
                AND ano_letivo_id = :ano_letivo_id
            ";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':observacao' => $observacao,
                ':aluno_id' => $aluno_id,
                ':turma_id' => $turma_id,
                ':disciplina_id' => $disciplina_id,
                ':professor_id' => $professor_id,
                ':escola_id' => $escola_id,
                ':bimestre' => $bimestre,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            $mensagem = "Observação atualizada com sucesso!";
        } else {
            // Inserir nova observação
            $sql_insert = "
                INSERT INTO notas (
                    estudante_id, turma_id, disciplina_id, professor_id, escola_id, bimestre, ano_letivo_id, 
                    observacao_academica, data_lancamento, status
                ) VALUES (
                    :aluno_id, :turma_id, :disciplina_id, :professor_id, :escola_id, :bimestre, :ano_letivo_id,
                    :observacao, NOW(), 'rascunho'
                )
            ";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->execute([
                ':aluno_id' => $aluno_id,
                ':turma_id' => $turma_id,
                ':disciplina_id' => $disciplina_id,
                ':professor_id' => $professor_id,
                ':escola_id' => $escola_id,
                ':bimestre' => $bimestre,
                ':ano_letivo_id' => $ano_letivo_id,
                ':observacao' => $observacao
            ]);
            $mensagem = "Observação salva com sucesso!";
        }
        
        echo json_encode(['success' => true, 'message' => $mensagem]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar: ' . $e->getMessage()]);
        exit;
    }
}
// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id,
        t.nome,
        t.ano,
        t.turno,
        t.sala,
        t.capacidade,
        (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano DESC, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id,
        d.nome,
        d.codigo,
        d.carga_horaria
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DETALHES DA TURMA SELECIONADA
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$turma_detalhes = null;
$alunos_turma = [];

if ($turma_id > 0) {
    $sql_turma_detalhes = "
        SELECT 
            t.*,
            COUNT(DISTINCT m.estudante_id) as total_alunos_matriculados
        FROM turmas t
        LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
        WHERE t.id = :turma_id AND t.escola_id = :escola_id
        GROUP BY t.id
    ";
    $stmt_detalhes = $conn->prepare($sql_turma_detalhes);
    $stmt_detalhes->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
    $turma_detalhes = $stmt_detalhes->fetch(PDO::FETCH_ASSOC);
    
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.email,
            e.telefone,
            e.foto,
            e.data_nascimento,
            e.bi,
            e.genero,
            e.endereco,
            e.pai_nome,
            e.mae_nome,
            m.data_matricula
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getProgressBar($percentual) {
    $cor = $percentual >= 75 ? 'success' : ($percentual >= 50 ? 'warning' : 'danger');
    return '<div class="progress-custom">
                <div class="progress-fill bg-' . $cor . '" style="width: ' . $percentual . '%;"></div>
            </div>';
}

function calcularIdade($data_nascimento) {
    if (empty($data_nascimento)) return '-';
    $data_nasc = new DateTime($data_nascimento);
    $hoje = new DateTime();
    $idade = $data_nasc->diff($hoje)->y;
    return $idade . ' anos';
}

// Buscar observações do aluno
function getObservacaoAluno($conn, $aluno_id, $turma_id, $bimestre) {
    $ano_letivo = date('Y');
    $sql = "SELECT observacao_academica FROM notas 
            WHERE estudante_id = :aluno_id 
            AND turma_id = :turma_id 
            AND bimestre = :bimestre 
            AND ano_letivo = :ano_letivo
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':aluno_id' => $aluno_id,
        ':turma_id' => $turma_id,
        ':bimestre' => $bimestre,
        ':ano_letivo' => $ano_letivo
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['observacao_academica'] : '';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Minhas Turmas | Professor | SIGE Angola</title>
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
           CABEÇALHO DA PÁGINA
        ============================================ */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1A2A6C;
        }

        .btn-voltar {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-voltar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(108, 117, 125, 0.3);
            color: white;
        }

        /* ============================================
           CARDS DE TURMAS
        ============================================ */
        .turma-card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            transition: all 0.3s ease;
            cursor: pointer;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(0, 0, 0, 0.05);
        }

        .turma-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12);
        }

        .turma-card.selected {
            border: 2px solid #006B3E;
            box-shadow: 0 10px 30px rgba(0, 107, 62, 0.2);
        }

        .turma-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 18px 20px;
        }

        .turma-header h5 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .turma-header small {
            opacity: 0.85;
        }

        .badge-turno {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .turma-body {
            padding: 20px;
        }

        .turma-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
            color: #6c757d;
            font-size: 0.85rem;
        }

        .turma-info i {
            width: 20px;
            color: #006B3E;
        }

        .progress-custom {
            height: 6px;
            background: #e9ecef;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.3s ease;
        }

        /* ============================================
           BOTÕES DE AÇÃO NAS TURMAS
        ============================================ */
        .btn-chamada {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-chamada:hover {
            background: #138496;
            transform: translateY(-2px);
        }

        .btn-notas {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-notas:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }

        /* ============================================
           BOTÃO EXPORTAR
        ============================================ */
        .btn-exportar {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-exportar:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
            color: white;
        }

        .btn-exportar-pdf {
            background: #dc3545;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: 10px;
        }

        .btn-exportar-pdf:hover {
            background: #c82333;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
            color: white;
        }

        /* ============================================
           CARD DE DETALHES DA TURMA
        ============================================ */
        .detalhes-card {
            background: white;
            border-radius: 24px;
            margin-top: 30px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .detalhes-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .detalhes-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 18px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .detalhes-header h5 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        /* ============================================
           TABELA DE ALUNOS
        ============================================ */
        .table-container {
            padding: 0;
            overflow-x: auto;
        }

        .alunos-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .alunos-table thead th {
            background: #f8f9fa;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #e9ecef;
            text-align: center;
        }

        .alunos-table tbody td {
            padding: 12px 15px;
            font-size: 0.85rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
            cursor: pointer;
        }

        .alunos-table tbody tr:hover {
            background: #f8f9fa;
            cursor: pointer;
        }

        .alunos-table tbody tr:last-child td {
            border-bottom: none;
        }

        .aluno-foto {
            width: 45px;
            height: 45px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #006B3E;
            cursor: pointer;
            transition: transform 0.3s ease;
        }

        .aluno-foto:hover {
            transform: scale(1.1);
        }

        /* ============================================
           BOTÕES DE AÇÃO NA TABELA
        ============================================ */
        .btn-ver-aluno {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .btn-ver-aluno:hover {
            background: #138496;
            transform: translateY(-1px);
            color: white;
        }

        .btn-notas-aluno {
            background: #ffc107;
            color: #333;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            cursor: pointer;
        }

        .btn-notas-aluno:hover {
            background: #e0a800;
            transform: translateY(-1px);
        }

        /* ============================================
           MODAL ESTILOS
        ============================================ */
        .modal-img {
            max-width: 100%;
            max-height: 400px;
            object-fit: contain;
            border-radius: 10px;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
        }

        .modal-header-custom .btn-close {
            filter: brightness(0) invert(1);
        }

        .ficha-aluno-field {
            margin-bottom: 15px;
        }

        .ficha-aluno-label {
            font-weight: 600;
            color: #006B3E;
            font-size: 0.8rem;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .ficha-aluno-value {
            background: #f8f9fa;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            border-left: 3px solid #006B3E;
        }

        .observacao-textarea {
            resize: vertical;
            min-height: 100px;
        }

        .bimestre-tab {
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .bimestre-tab.active {
            background: #006B3E;
            color: white;
            border-color: #006B3E;
        }

        .bimestre-tab:hover:not(.active) {
            background: #e8f5e9;
            border-color: #006B3E;
        }

        /* ============================================
           CARD DE DISCIPLINAS
        ============================================ */
        .disciplinas-card {
            background: white;
            border-radius: 24px;
            margin-top: 30px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.08);
            transition: all 0.3s ease;
        }

        .disciplinas-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.12);
        }

        .disciplinas-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 18px 25px;
        }

        .disciplinas-header h5 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .disciplinas-body {
            padding: 25px;
        }

        .disciplina-badge {
            background: #e8f5e9;
            color: #006B3E;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 5px;
            transition: all 0.3s ease;
        }

        .disciplina-badge:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(0, 107, 62, 0.2);
        }

        /* ============================================
           ALERTAS
        ============================================ */
        .alert-custom {
            border-radius: 20px;
            padding: 30px;
            text-align: center;
            border: none;
        }

        .alert-info-custom {
            background: linear-gradient(135deg, #cfe2ff 0%, #b8d4ff 100%);
            color: #084298;
        }

        .alert-warning-custom {
            background: linear-gradient(135deg, #fff3cd 0%, #ffe69b 100%);
            color: #856404;
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
           TOAST MENSAGENS
        ============================================ */
        .toast-custom {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            animation: slideInRight 0.3s ease;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                gap: 15px;
            }
            
            .turma-card {
                margin-bottom: 20px;
            }
            
            .alunos-table thead th,
            .alunos-table tbody td {
                padding: 8px 10px;
                font-size: 0.7rem;
            }
            
            .btn-ver-aluno,
            .btn-notas-aluno {
                padding: 4px 8px;
                font-size: 0.6rem;
            }
            
            .aluno-foto {
                width: 35px;
                height: 35px;
            }
            
            .detalhes-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .btn-exportar, .btn-exportar-pdf {
                width: 100%;
                justify-content: center;
                margin-left: 0;
                margin-top: 5px;
            }
        }

        @media (max-width: 576px) {
            .turma-card {
                margin-bottom: 15px;
            }
            
            .detalhes-header h5 {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-3 fade-in">
            <div>
                <h2><i class="fas fa-chalkboard-user me-2"></i> Minhas Turmas</h2>
                <p class="text-muted">Visualize e gerencie todas as suas turmas</p>
            </div>
            <div>
                <a href="dashboard.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
                </a>
            </div>
        </div>
        
        <?php if (empty($turmas)): ?>
            <div class="alert-custom alert-info-custom fade-in">
                <i class="fas fa-info-circle fa-3x mb-3"></i>
                <h5>Nenhuma turma encontrada</h5>
                <p class="mb-0">Você não está vinculado a nenhuma turma ainda. Entre em contato com a coordenação da escola.</p>
            </div>
        <?php else: ?>
        
        <!-- Lista de Turmas -->
        <div class="row fade-in">
            <?php foreach ($turmas as $turma): 
                $percentual = $turma['capacidade'] > 0 ? round(($turma['total_alunos'] / $turma['capacidade']) * 100, 1) : 0;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="turma-card <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>" onclick="window.location.href='?turma_id=<?php echo $turma['id']; ?>'">
                    <div class="turma-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?></h5>
                                <small><i class="fas fa-clock me-1"></i> <?php echo ucfirst($turma['turno']); ?></small>
                            </div>
                            <div>
                                <span class="badge-turno">
                                    <i class="fas fa-users me-1"></i> <?php echo $turma['total_alunos']; ?>/<?php echo $turma['capacidade'] ?? '∞'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="turma-body">
                        <div class="turma-info">
                            <i class="fas fa-door-open"></i>
                            <span>Sala: <?php echo $turma['sala'] ?: 'Não definida'; ?></span>
                        </div>
                        <?php if ($turma['capacidade'] > 0): ?>
                            <div>
                                <div class="d-flex justify-content-between mb-1">
                                    <small>Ocupação</small>
                                    <small><?php echo $percentual; ?>%</small>
                                </div>
                                <?php echo getProgressBar($percentual); ?>
                            </div>
                        <?php endif; ?>
                        <div class="text-center mt-3 d-flex gap-2 justify-content-center">
                            <button class="btn-chamada" onclick="event.stopPropagation(); window.location.href='registrar_chamada.php?turma_id=<?php echo $turma['id']; ?>'">
                                <i class="fas fa-clipboard-list"></i> Chamada
                            </button>
                            <button class="btn-notas" onclick="event.stopPropagation(); window.location.href='lancar_notas.php?turma_id=<?php echo $turma['id']; ?>'">
                                <i class="fas fa-graduation-cap"></i> Notas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Detalhes da Turma Selecionada -->
        <?php if ($turma_detalhes && !empty($alunos_turma)): ?>
        <div class="detalhes-card fade-in">
            <div class="detalhes-header">
                <h5><i class="fas fa-users me-2"></i> Alunos da Turma - <?php echo $turma_detalhes['ano'] . 'ª ' . htmlspecialchars($turma_detalhes['nome']); ?></h5>
                <div class="d-flex gap-2">
                    <button onclick="window.location.href='?exportar=lista_nominal&turma_id=<?php echo $turma_id; ?>'" class="btn-exportar">
                        <i class="fas fa-download me-1"></i> Exportar Lista (CSV)
                    </button>
                    <button onclick="window.open('exportar_lista_pdf.php?turma_id=<?php echo $turma_id; ?>', '_blank')" class="btn-exportar-pdf">
                        <i class="fas fa-file-pdf me-1"></i> Exportar PDF
                    </button>
                </div>
            </div>
            <div class="table-container">
                <table class="alunos-table">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th width="8%">Foto</th>
                            <th width="20%">Nome do Aluno</th>
                            <th width="10%">Matrícula</th>
                            <th width="8%">Idade</th>
                            <th width="15%">Contato</th>
                            <th width="18%">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos_turma as $index => $aluno): ?>
                        <tr onclick="abrirFichaAluno(<?php echo $aluno['id']; ?>, <?php echo $turma_id; ?>)">
                            <td class="text-center"><?php echo $index + 1; ?></td>
                            <td class="text-center">
                                <img 
                                    src="<?php echo !empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto']) ? '../../uploads/alunos/fotos/' . $aluno['foto'] : '../../assets/images/avatar-padrao.png'; ?>" 
                                    class="aluno-foto"
                                    onclick="event.stopPropagation(); abrirModalImagem(this.src, '<?php echo htmlspecialchars($aluno['nome']); ?>')"
                                >
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                <?php if ($aluno['genero']): ?>
                                    <br><small class="text-muted"><?php echo $aluno['genero'] == 'M' ? 'Masculino' : 'Feminino'; ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                            <td class="text-center"><?php echo calcularIdade($aluno['data_nascimento']); ?></td>
                            <td>
                                <?php if ($aluno['email']): ?>
                                    <div><small><i class="fas fa-envelope me-1"></i> <?php echo htmlspecialchars(substr($aluno['email'], 0, 20)); ?></small></div>
                                <?php endif; ?>
                                <?php if ($aluno['telefone']): ?>
                                    <div><small><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($aluno['telefone']); ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <div class="d-flex gap-2 justify-content-center flex-wrap" onclick="event.stopPropagation()">
                                    <button class="btn-ver-aluno" onclick="abrirFichaAluno(<?php echo $aluno['id']; ?>, <?php echo $turma_id; ?>)">
                                        <i class="fas fa-eye"></i> Ver Ficha
                                    </button>
                                    <button class="btn-notas-aluno" onclick="window.location.href='lancar_notas.php?turma_id=<?php echo $turma_id; ?>&aluno_id=<?php echo $aluno['id']; ?>'">
                                        <i class="fas fa-graduation-cap"></i> Notas
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="detalhes-footer p-3 bg-light">
                <div class="row">
                    <div class="col-md-6">
                        <small class="text-muted">
                            <i class="fas fa-users me-1"></i> Total de alunos: <strong><?php echo count($alunos_turma); ?></strong>
                        </small>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <small class="text-muted">
                            <i class="fas fa-calendar-alt me-1"></i> Última atualização: <?php echo date('d/m/Y H:i:s'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php elseif ($turma_detalhes): ?>
        <div class="alert-custom alert-warning-custom fade-in">
            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
            <h5>Nenhum aluno encontrado</h5>
            <p class="mb-0">Não há alunos matriculados nesta turma.</p>
        </div>
        <?php endif; ?>
        
        <!-- Disciplinas do Professor -->
        <div class="disciplinas-card fade-in">
            <div class="disciplinas-header">
                <h5><i class="fas fa-book me-2"></i> Minhas Disciplinas</h5>
            </div>
            <div class="disciplinas-body">
                <?php if (empty($disciplinas)): ?>
                    <p class="text-muted text-center mb-0">Nenhuma disciplina atribuída.</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap">
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <span class="disciplina-badge">
                            <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($disciplina['nome']); ?>
                            <?php if ($disciplina['carga_horaria']): ?>
                            <small class="text-muted">(<?php echo $disciplina['carga_horaria']; ?>h)</small>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </div>

    <!-- Modal para Ampliar Imagem -->
    <div class="modal fade" id="modalImagem" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-image me-2"></i> Foto do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="modalImagemSrc" src="" alt="Foto do Aluno" class="modal-img">
                    <p id="modalImagemNome" class="mt-3 fw-bold"></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal da Ficha do Aluno -->
    <div class="modal fade" id="modalFichaAluno" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-user-graduate me-2"></i> Ficha do Aluno</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="conteudoFichaAluno">
                        <div class="text-center py-5">
                            <div class="spinner-border text-success" role="status">
                                <span class="visually-hidden">Carregando...</span>
                            </div>
                            <p class="mt-2">Carregando dados do aluno...</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast para Mensagens -->
    <div class="toast-custom" id="toastMensagem" style="display: none;">
        <div class="toast show" role="alert">
            <div class="toast-header" id="toastHeader">
                <i class="fas fa-check-circle me-2" style="color: #28a745;"></i>
                <strong class="me-auto">Sucesso</strong>
                <button type="button" class="btn-close" onclick="fecharToast()"></button>
            </div>
            <div class="toast-body" id="toastBody">
                Operação realizada com sucesso!
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
        
        document.querySelectorAll('.turma-card, .detalhes-card, .disciplinas-card, .page-header').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
        
        // Função para abrir modal de imagem
        function abrirModalImagem(src, nome) {
            document.getElementById('modalImagemSrc').src = src;
            document.getElementById('modalImagemNome').innerHTML = '<i class="fas fa-user me-2"></i>' + nome;
            new bootstrap.Modal(document.getElementById('modalImagem')).show();
        }
        
        // Função para abrir ficha do aluno
        async function abrirFichaAluno(alunoId, turmaId) {
            const modalElement = document.getElementById('modalFichaAluno');
            const modal = new bootstrap.Modal(modalElement);
            const conteudoDiv = document.getElementById('conteudoFichaAluno');
            
            // Mostrar loading
            conteudoDiv.innerHTML = `
                <div class="text-center py-5">
                    <div class="spinner-border text-success" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2">Carregando dados do aluno...</p>
                </div>
            `;
            
            modal.show();
            
            try {
                // Buscar dados do aluno via AJAX
                const response = await fetch(`buscar_dados_aluno.php?id=${alunoId}&turma_id=${turmaId}`);
                const dados = await response.json();
                
                if (dados.success) {
                    conteudoDiv.innerHTML = criarFichaAlunoHTML(dados.aluno, turmaId);
                    
                    // Adicionar evento de salvar observação
                    document.getElementById('btnSalvarObservacao').addEventListener('click', () => salvarObservacao(alunoId, turmaId));
                } else {
                    conteudoDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            Erro ao carregar dados do aluno. Tente novamente.
                        </div>
                    `;
                }
            } catch (error) {
                console.error('Erro:', error);
                conteudoDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Erro de conexão. Tente novamente.
                    </div>
                `;
            }
        }
        
        // Função para criar HTML da ficha do aluno
        function criarFichaAlunoHTML(aluno, turmaId) {
            return `
                <div class="row">
                    <div class="col-md-3 text-center">
                        <img src="${aluno.foto_url || '../../assets/images/avatar-padrao.png'}" 
                             class="img-fluid rounded-circle mb-3" 
                             style="width: 150px; height: 150px; object-fit: cover; border: 3px solid #006B3E;">
                        <h5 class="mt-2">${aluno.nome}</h5>
                        <p class="text-muted small">Matrícula: ${aluno.matricula}</p>
                    </div>
                    <div class="col-md-9">
                        <ul class="nav nav-tabs mb-3" id="fichaTabs" role="tablist" style="border-bottom: 2px solid #006B3E;">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="dados-tab" data-bs-toggle="tab" data-bs-target="#dados" type="button" role="tab" style="color: #006B3E; font-weight: 600; background-color: #e8f5e9; border: none; margin-right: 5px; border-radius: 8px 8px 0 0;">
            <i class="fas fa-info-circle me-1"></i> Dados Pessoais
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="familia-tab" data-bs-toggle="tab" data-bs-target="#familia" type="button" role="tab" style="color: #1A2A6C; font-weight: 600; background-color: #f0f2f5; border: none; margin-right: 5px; border-radius: 8px 8px 0 0;">
            <i class="fas fa-users me-1"></i> Informações Familiares
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="observacoes-tab" data-bs-toggle="tab" data-bs-target="#observacoes" type="button" role="tab" style="color: #1A2A6C; font-weight: 600; background-color: #f0f2f5; border: none; margin-right: 5px; border-radius: 8px 8px 0 0;">
            <i class="fas fa-comment me-1"></i> Observações Acadêmicas
        </button>
    </li>
</ul>

<style>
    /* Estilos adicionais para as tabs */
    .nav-tabs .nav-link {
        transition: all 0.3s ease;
        padding: 10px 20px;
    }
    
    .nav-tabs .nav-link:hover {
        background-color: #d4edda !important;
        color: #006B3E !important;
        transform: translateY(-2px);
    }
    
    .nav-tabs .nav-link.active {
        background-color: #006B3E !important;
        color: white !important;
        border-bottom: 2px solid #006B3E !important;
    }
    
    .nav-tabs .nav-link.active i {
        color: white !important;
    }
    
    .nav-tabs .nav-link i {
        margin-right: 5px;
    }
    
    /* Para garantir legibilidade em todos os estados */
    .tab-pane {
        background: white;
        padding: 20px;
        border-radius: 0 8px 8px 8px;
        border: 1px solid #dee2e6;
        border-top: none;
    }
</style>
                        
                        <div class="tab-content">
                            <!-- Dados Pessoais -->
                            <div class="tab-pane fade show active" id="dados" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Data de Nascimento</div>
                                            <div class="ficha-aluno-value">${aluno.data_nascimento || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Idade</div>
                                            <div class="ficha-aluno-value">${aluno.idade || '-'} anos</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Genero</div>
                                            <div class="ficha-aluno-value">${aluno.sexo === 'M' ? 'Masculino' : (aluno.sexo === 'F' ? 'Feminino' : '-')}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">BI / Identificação</div>
                                            <div class="ficha-aluno-value">${aluno.bi || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Endereço</div>
                                            <div class="ficha-aluno-value">${aluno.endereco || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">E-mail</div>
                                            <div class="ficha-aluno-value">${aluno.email || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Telefone</div>
                                            <div class="ficha-aluno-value">${aluno.telefone || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Necessidades Especiais</div>
                                            <div class="ficha-aluno-value">${aluno.encarregado_nome || 'Nenhuma'}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Informações Familiares -->
                            <div class="tab-pane fade" id="familia" role="tabpanel">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Nome do Pai</div>
                                            <div class="ficha-aluno-value">${aluno.nome_pai || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Nome da Mãe</div>
                                            <div class="ficha-aluno-value">${aluno.nome_mae || '-'}</div>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Contato de Emergência</div>
                                            <div class="ficha-aluno-value">${aluno.telefone || '-'}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Observações Acadêmicas -->
                            <div class="tab-pane fade" id="observacoes" role="tabpanel">
                                <div class="row mb-3">
                                    <div class="col-12">
                                        <div class="btn-group w-100" role="group">
                                            <button type="button" class="btn btn-outline-success bimestre-tab active" data-bimestre="1">
                                                1º Bimestre
                                            </button>
                                            <button type="button" class="btn btn-outline-success bimestre-tab" data-bimestre="2">
                                                2º Bimestre
                                            </button>
                                            <button type="button" class="btn btn-outline-success bimestre-tab" data-bimestre="3">
                                                3º Bimestre
                                            </button>
                                            <button type="button" class="btn btn-outline-success bimestre-tab" data-bimestre="4">
                                                4º Bimestre
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12">
                                        <div class="ficha-aluno-field">
                                            <div class="ficha-aluno-label">Observações sobre o desempenho acadêmico</div>
                                            <textarea id="observacaoAluno" class="form-control observacao-textarea" 
                                                      placeholder="Digite aqui as observações sobre o desempenho, comportamento, participação e outras informações relevantes sobre o aluno neste bimestre..."></textarea>
                                            <small class="text-muted mt-2 d-block">
                                                <i class="fas fa-info-circle me-1"></i>
                                                Estas observações aparecerão no boletim do aluno e servem para o acompanhamento acadêmico.
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <button id="btnSalvarObservacao" class="btn btn-success w-100">
                                            <i class="fas fa-save me-2"></i> Salvar Observação
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }
        
        // Função para salvar observação
// Função para salvar observação
async function salvarObservacao(alunoId, turmaId) {
    const bimestreAtivo = document.querySelector('.bimestre-tab.active');
    if (!bimestreAtivo) {
        mostrarToast('Erro', 'Selecione um bimestre primeiro!', 'danger');
        return;
    }
    
    const bimestre = bimestreAtivo.getAttribute('data-bimestre');
    const observacao = document.getElementById('observacaoAluno').value;
    
    const btn = document.getElementById('btnSalvarObservacao');
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Salvando...';
    btn.disabled = true;
    
    try {
        const formData = new FormData();
        formData.append('salvar_observacao', '1');
        formData.append('aluno_id', alunoId);
        formData.append('turma_id', turmaId);
        formData.append('bimestre', bimestre);
        formData.append('observacao', observacao);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            mostrarToast('Sucesso', result.message, 'success');
        } else {
            mostrarToast('Erro', result.message || 'Erro ao salvar observação', 'danger');
        }
    } catch (error) {
        console.error('Erro:', error);
        mostrarToast('Erro', 'Erro de conexão. Tente novamente.', 'danger');
    } finally {
        btn.innerHTML = originalText;
        btn.disabled = false;
    }
}
        
        // Função para mostrar toast
        function mostrarToast(titulo, mensagem, tipo) {
            const toast = document.getElementById('toastMensagem');
            const header = document.getElementById('toastHeader');
            const body = document.getElementById('toastBody');
            
            const icon = tipo === 'success' ? 'fas fa-check-circle' : (tipo === 'warning' ? 'fas fa-exclamation-triangle' : 'fas fa-times-circle');
            const cor = tipo === 'success' ? '#28a745' : (tipo === 'warning' ? '#ffc107' : '#dc3545');
            
            header.innerHTML = `
                <i class="${icon} me-2" style="color: ${cor};"></i>
                <strong class="me-auto">${titulo}</strong>
                <button type="button" class="btn-close" onclick="fecharToast()"></button>
            `;
            body.innerHTML = mensagem;
            
            toast.style.display = 'block';
            setTimeout(() => {
                fecharToast();
            }, 3000);
        }
        
        function fecharToast() {
            document.getElementById('toastMensagem').style.display = 'none';
        }
        
        // Delegar eventos para as tabs de bimestre
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('bimestre-tab')) {
                document.querySelectorAll('.bimestre-tab').forEach(tab => {
                    tab.classList.remove('active');
                });
                e.target.classList.add('active');
                
                // Aqui você pode carregar a observação salva para este bimestre via AJAX
                const bimestre = e.target.getAttribute('data-bimestre');
                const alunoId = document.querySelector('#modalFichaAluno').getAttribute('data-aluno-id');
                if (alunoId) {
                    carregarObservacao(alunoId, bimestre);
                }
            }
        });
        
        // Função para carregar observação salva
        async function carregarObservacao(alunoId, bimestre) {
            try {
                const response = await fetch(`buscar_observacao.php?aluno_id=${alunoId}&bimestre=${bimestre}&turma_id=${turmaId}`);
                const data = await response.json();
                if (data.observacao) {
                    document.getElementById('observacaoAluno').value = data.observacao;
                } else {
                    document.getElementById('observacaoAluno').value = '';
                }
            } catch (error) {
                console.error('Erro ao carregar observação:', error);
            }
        }
    </script>
</body>
</html>