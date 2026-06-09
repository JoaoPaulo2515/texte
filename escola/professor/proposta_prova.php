<?php
// escola/professor/proposta_prova.php - Submeter Proposta de Prova

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ID DO FUNCIONARIO (professor)
// ============================================
$funcionario_id = $professor_id;
$funcionario_nome = '';

$sql_func = "SELECT id, nome, cargo FROM funcionarios WHERE id = :funcionario_id AND escola_id = :escola_id LIMIT 1";
$stmt_func = $conn->prepare($sql_func);
$stmt_func->execute([
    ':funcionario_id' => $funcionario_id,
    ':escola_id' => $escola_id
]);
$funcionario = $stmt_func->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    $sql_func2 = "SELECT id, nome, cargo FROM funcionarios WHERE escola_id = :escola_id LIMIT 1";
    $stmt_func2 = $conn->prepare($sql_func2);
    $stmt_func2->execute([':escola_id' => $escola_id]);
    $funcionario = $stmt_func2->fetch(PDO::FETCH_ASSOC);
    $funcionario_id = $funcionario ? $funcionario['id'] : $professor_id;
}

$funcionario_nome = $funcionario ? $funcionario['nome'] : '';

// ============================================
// INICIALIZAR VARIÁVEIS
// ============================================
$success = '';
$error = '';

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->query($sql_ano);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_atual = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT t.id, t.nome, t.ano, t.turno
    FROM turmas t
    INNER JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
    WHERE pdt.professor_id = :funcionario_id AND t.status = 'ativa'
    ORDER BY t.ano, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':funcionario_id' => $funcionario_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// TIPOS DE PROVA
// ============================================
$tipos_prova = [
    'normal' => 'Prova Normal',
    'recuperacao' => 'Prova de Recuperação',
    'exame' => 'Exame Final',
    'recurso' => 'Prova de Recurso',
    'especial' => 'Prova Especial'
];

// ============================================
// VERIFICAR SE A TABELA EXISTE E CRIAR SE NECESSÁRIO
// ============================================
try {
    $check_table = $conn->query("SHOW TABLES LIKE 'propostas_prova'");
    if ($check_table->rowCount() == 0) {
        $conn->exec("
            CREATE TABLE propostas_prova (
                id INT PRIMARY KEY AUTO_INCREMENT,
                funcionario_id INT NOT NULL,
                escola_id INT NOT NULL,
                ano_letivo_id INT NOT NULL,
                turma_id INT NOT NULL,
                disciplina_id INT NOT NULL,
                bimestre INT NOT NULL,
                tipo_prova VARCHAR(30) DEFAULT 'normal',
                titulo VARCHAR(200) NOT NULL,
                descricao TEXT,
                conteudo TEXT NOT NULL,
                data_prevista DATE NOT NULL,
                duracao INT DEFAULT 60,
                peso DECIMAL(5,2) DEFAULT 10,
                anexo VARCHAR(255),
                status VARCHAR(20) DEFAULT 'pendente',
                parecer TEXT,
                aprovado_por INT,
                data_aprovacao DATE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");
    }
} catch (PDOException $e) {
    error_log("Erro ao criar tabela: " . $e->getMessage());
}

// ============================================
// PROCESSAR SUBMISSÃO DA PROPOSTA
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submeter_proposta'])) {
    
    $turma_id = (int)$_POST['turma_id'];
    $disciplina_id = (int)$_POST['disciplina_id'];
    $bimestre = (int)$_POST['bimestre'];
    $tipo_prova = $_POST['tipo_prova'] ?? 'normal';
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao']);
    $conteudo = $_POST['conteudo'] ?? '';
    $data_prevista = $_POST['data_prevista'] ?? '';
    $duracao = (int)$_POST['duracao'] ?? 60;
    $peso = (float)$_POST['peso'] ?? 10;
    
    $errors = [];
    if ($turma_id <= 0) $errors[] = "Turma não selecionada";
    if ($disciplina_id <= 0) $errors[] = "Disciplina não selecionada";
    if ($bimestre < 1 || $bimestre > 4) $errors[] = "Bimestre inválido";
    if (empty($titulo)) $errors[] = "Título vazio";
    if (empty($conteudo) || $conteudo == '<p><br></p>') $errors[] = "Conteúdo vazio";
    if (empty($data_prevista)) $errors[] = "Data prevista vazia";
    if ($funcionario_id <= 0) $errors[] = "Funcionário não encontrado";
    
    if (!empty($errors)) {
        $error = "⚠️ " . implode(", ", $errors);
    } else {
        $sql_check = "
            SELECT id FROM professor_disciplina_turma 
            WHERE funcionario_id = :funcionario_id 
            AND turma_id = :turma_id 
            AND disciplina_id = :disciplina_id
            LIMIT 1
        ";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->execute([
            ':funcionario_id' => $funcionario_id,
            ':turma_id' => $turma_id,
            ':disciplina_id' => $disciplina_id
        ]);
        
        if ($stmt_check->rowCount() == 0) {
            $error = "⚠️ Você não está associado a esta combinação de Turma e Disciplina.";
        } else {
            try {
                $conn->beginTransaction();
                
                $arquivo_path = null;
                if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = __DIR__ . '/../../uploads/propostas_prova/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $extensao = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
                    $extensoes_permitidas = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
                    
                    if (in_array($extensao, $extensoes_permitidas)) {
                        $nome_arquivo = 'proposta_' . time() . '_' . uniqid() . '.' . $extensao;
                        $arquivo_path = 'uploads/propostas_prova/' . $nome_arquivo;
                        $caminho_completo = $upload_dir . $nome_arquivo;
                        move_uploaded_file($_FILES['anexo']['tmp_name'], $caminho_completo);
                    }
                }
                
                $sql = "INSERT INTO propostas_prova (
                            funcionario_id, escola_id, ano_letivo_id,
                            turma_id, disciplina_id, bimestre, tipo_prova,
                            titulo, descricao, conteudo, data_prevista,
                            duracao, peso, anexo, status
                        ) VALUES (
                            :funcionario_id, :escola_id, :ano_letivo_id,
                            :turma_id, :disciplina_id, :bimestre, :tipo_prova,
                            :titulo, :descricao, :conteudo, :data_prevista,
                            :duracao, :peso, :anexo, 'pendente'
                        )";
                
                $stmt = $conn->prepare($sql);
                $result = $stmt->execute([
                    ':funcionario_id' => $funcionario_id,
                    ':escola_id' => $escola_id,
                    ':ano_letivo_id' => $ano_letivo_id,
                    ':turma_id' => $turma_id,
                    ':disciplina_id' => $disciplina_id,
                    ':bimestre' => $bimestre,
                    ':tipo_prova' => $tipo_prova,
                    ':titulo' => $titulo,
                    ':descricao' => $descricao,
                    ':conteudo' => $conteudo,
                    ':data_prevista' => $data_prevista,
                    ':duracao' => $duracao,
                    ':peso' => $peso,
                    ':anexo' => $arquivo_path
                ]);
                
                if ($result) {
                    $conn->commit();
                    $success = "✅ Proposta de prova enviada com sucesso! Aguarde a aprovação da coordenação.";
                } else {
                    throw new Exception("Falha ao inserir na base de dados");
                }
                
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Erro ao enviar proposta: " . $e->getMessage();
            }
        }
    }
}

// ============================================
// AJAX: BUSCAR DISCIPLINAS POR TURMA
// ============================================
if (isset($_GET['ajax_disciplinas']) && isset($_GET['turma_id'])) {
    $turma_id = (int)$_GET['turma_id'];
    
    $sql = "
        SELECT DISTINCT d.id, d.nome
        FROM disciplinas d
        INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
        WHERE pdt.professor_id = :funcionario_id AND pdt.turma_id = :turma_id
        ORDER BY d.nome
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':funcionario_id' => $funcionario_id,
        ':turma_id' => $turma_id
    ]);
    $disciplinas_ajax = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($disciplinas_ajax);
    exit;
}

// ============================================
// FUNÇÃO PARA PREVIEW DA PROPOSTA
// ============================================
if (isset($_GET['preview']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    $sql = "
        SELECT p.*, 
               t.nome as turma_nome, t.ano,
               d.nome as disciplina_nome,
               f.nome as professor_nome
        FROM propostas_prova p
        INNER JOIN turmas t ON t.id = p.turma_id
        INNER JOIN disciplinas d ON d.id = p.disciplina_id
        INNER JOIN funcionarios f ON f.id = p.funcionario_id
        WHERE p.id = :id
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id]);
    $proposta = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($proposta) {
        echo '<!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Preview - ' . htmlspecialchars($proposta['titulo']) . '</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
            <style>
                body { padding: 40px; font-family: "Segoe UI", Arial, sans-serif; }
                .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #006B3E; }
                .info { background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); padding: 15px; border-radius: 8px; margin: 20px 0; }
                .conteudo { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 8px; background: white; }
                .footer { margin-top: 30px; text-align: center; font-size: 12px; color: #666; }
                @media print { body { padding: 20px; } .no-print { display: none; } }
            </style>
        </head>
        <body>
            <div class="no-print text-end mb-3">
                <button class="btn btn-primary" onclick="window.print()">Imprimir</button>
                <button class="btn btn-secondary" onclick="window.close()">Fechar</button>
            </div>
            <div class="header">
                <h2>Proposta de Prova</h2>
                <p>Protocolo: PROV-' . str_pad($proposta['id'], 6, '0', STR_PAD_LEFT) . '</p>
            </div>
            <div class="info">
                <table class="table table-bordered">
                    <tr><td width="30%"><strong>Professor:</strong></td><td>' . htmlspecialchars($proposta['professor_nome']) . '</td></tr>
                    <tr><td><strong>Disciplina:</strong></td><td>' . htmlspecialchars($proposta['disciplina_nome']) . '</td></tr>
                    <tr><td><strong>Turma:</strong></td><td>' . htmlspecialchars($proposta['turma_nome']) . '</td></tr>
                    <tr><td><strong>Data Prevista:</strong></td><td>' . date('d/m/Y', strtotime($proposta['data_prevista'])) . '</td></tr>
                    <tr><td><strong>Status:</strong></td><td>' . ucfirst($proposta['status']) . '</td></tr>
                </table>
            </div>
            <div class="conteudo">
                <h4>' . htmlspecialchars($proposta['titulo']) . '</h4>
                <hr>
                ' . $proposta['conteudo'] . '
            </div>
            <div class="footer">
                <p>Documento gerado pelo SIGE Angola em ' . date('d/m/Y H:i:s') . '</p>
            </div>
        </body>
        </html>';
        exit;
    }
}

// ============================================
// BUSCAR PROPOSTAS ANTERIORES
// ============================================
$sql_propostas = "
    SELECT p.*,
           t.nome as turma_nome, t.ano,
           d.nome as disciplina_nome
    FROM propostas_prova p
    INNER JOIN turmas t ON t.id = p.turma_id
    INNER JOIN disciplinas d ON d.id = p.disciplina_id
    WHERE p.funcionario_id = :funcionario_id
    ORDER BY p.created_at DESC
    LIMIT 20
";
$stmt_propostas = $conn->prepare($sql_propostas);
$stmt_propostas->execute([':funcionario_id' => $funcionario_id]);
$propostas = $stmt_propostas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// ESTATÍSTICAS
// ============================================
$total_propostas = count($propostas);
$total_pendente = 0;
$total_aprovado = 0;
$total_reprovado = 0;

foreach ($propostas as $prop) {
    if ($prop['status'] == 'pendente') $total_pendente++;
    if ($prop['status'] == 'aprovado') $total_aprovado++;
    if ($prop['status'] == 'reprovado') $total_reprovado++;
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getStatusBadgeProposta($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge badge-pendente"><i class="fas fa-clock"></i> Pendente</span>';
        case 'aprovado':
            return '<span class="badge badge-aprovado"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'reprovado':
            return '<span class="badge badge-reprovado"><i class="fas fa-times-circle"></i> Reprovado</span>';
        case 'revisao':
            return '<span class="badge badge-revisao"><i class="fas fa-edit"></i> Revisão</span>';
        default:
            return '<span class="badge badge-secondary">' . $status . '</span>';
    }
}

function getTipoProvaBadge($tipo) {
    switch ($tipo) {
        case 'normal': return '<span class="badge badge-normal">Normal</span>';
        case 'recuperacao': return '<span class="badge badge-recuperacao">Recuperação</span>';
        case 'exame': return '<span class="badge badge-exame">Exame Final</span>';
        case 'recurso': return '<span class="badge badge-recurso">Recurso</span>';
        case 'especial': return '<span class="badge badge-especial">Especial</span>';
        default: return '<span class="badge badge-secondary">' . $tipo . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Proposta de Prova | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css" rel="stylesheet">
    <style>
        /* ============================================
           RESET E VARIÁVEIS
        ============================================ */
        :root {
            --primary-green: #006B3E;
            --primary-dark: #1A2A6C;
            --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --purple: #6f42c1;
            --orange: #fd7e14;
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            --card-shadow-hover: 0 15px 50px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
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
           PAGE HEADER
        ============================================ */
        .page-header {
            background: var(--primary-gradient);
            color: white;
            border-radius: 24px;
            padding: 30px;
            margin-bottom: 35px;
            box-shadow: var(--card-shadow);
            position: relative;
            overflow: hidden;
        }

        .page-header::before {
            content: '📝';
            position: absolute;
            bottom: -30px;
            right: -30px;
            font-size: 120px;
            opacity: 0.1;
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-20px); }
        }

        .page-header h2 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
            position: relative;
            z-index: 1;
        }

        /* ============================================
           BOTÕES
        ============================================ */
        .btn-voltar, .btn-ajuda {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
        }

        .btn-voltar {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        .btn-ajuda {
            background: linear-gradient(135deg, var(--orange) 0%, #e66a00 100%);
            color: white;
        }

        .btn-ajuda:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(253, 126, 20, 0.3);
            color: white;
        }

        .btn-enviar {
            background: linear-gradient(135deg, var(--success) 0%, #1e7e34 100%);
            color: white;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 700;
            transition: var(--transition);
            border: none;
        }

        .btn-enviar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(40, 167, 69, 0.4);
            color: white;
        }

        .btn-preview {
            background: linear-gradient(135deg, var(--info) 0%, #138496 100%);
            color: white;
            border-radius: 30px;
            padding: 5px 15px;
            font-size: 0.75rem;
            transition: var(--transition);
            border: none;
        }

        .btn-preview:hover {
            transform: translateY(-2px);
            box-shadow: 0 3px 10px rgba(23, 162, 184, 0.3);
            color: white;
        }

        /* ============================================
           CARDS
        ============================================ */
        .info-card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            margin-bottom: 25px;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .info-title {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            padding: 18px 24px;
            font-weight: 700;
            color: var(--primary-green);
            border-bottom: 2px solid var(--primary-green);
            font-size: 1.1rem;
        }

        .info-title i {
            margin-right: 10px;
            color: var(--primary-green);
        }

        .info-body {
            padding: 24px;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-gradient);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--card-shadow-hover);
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
        }

        .stat-card.warning .stat-number { color: var(--warning); }
        .stat-card.success .stat-number { color: var(--success); }
        .stat-card.danger .stat-number { color: var(--danger); }

        /* ============================================
           FORMULÁRIO
        ============================================ */
        .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .form-control, .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: var(--transition);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
            outline: none;
        }

        /* ============================================
           CUSTOM TOOLBAR
        ============================================ */
        .custom-toolbar-buttons {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 12px;
            margin-bottom: 10px;
            border: 1px solid #e9ecef;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .custom-toolbar-buttons .btn {
            border-radius: 30px;
            font-size: 0.7rem;
            padding: 6px 14px;
            transition: var(--transition);
        }

        .custom-toolbar-buttons .btn i {
            margin-right: 5px;
        }

        .custom-toolbar-buttons .btn:hover {
            transform: translateY(-2px);
        }

        /* ============================================
           UPLOAD AREA
        ============================================ */
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 16px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            background: #f8f9fa;
        }

        .upload-area:hover {
            border-color: var(--primary-green);
            background: linear-gradient(135deg, #e8f5e9 0%, #f8f9fa 100%);
            transform: translateY(-2px);
        }

        .upload-area.dragover {
            border-color: var(--success);
            background: #d4edda;
        }

        /* ============================================
           PROPOSTA CARD
        ============================================ */
        .proposta-card {
            background: white;
            border-radius: 16px;
            margin-bottom: 15px;
            padding: 18px;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .proposta-card:hover {
            transform: translateX(8px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .proposta-card.aprovado { border-left-color: var(--success); }
        .proposta-card.reprovado { border-left-color: var(--danger); }
        .proposta-card.revisao { border-left-color: var(--info); }

        /* ============================================
           BADGES
        ============================================ */
        .badge {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .badge-pendente { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-aprovado { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-reprovado { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-revisao { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        
        .badge-normal { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .badge-recuperacao { background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: #212529; }
        .badge-exame { background: linear-gradient(135deg, #dc3545 0%, #c82333 100%); color: white; }
        .badge-recurso { background: linear-gradient(135deg, #17a2b8 0%, #0dcaf0 100%); color: white; }
        .badge-especial { background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%); color: white; }
        .badge-secondary { background: #6c757d; color: white; }

        /* ============================================
           ALERTA INFO
        ============================================ */
        .alerta-info {
            background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
            border-left: 4px solid var(--success);
            border-radius: 16px;
            padding: 15px 20px;
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .alerta-info::before {
            content: '📌';
            position: absolute;
            right: 10px;
            bottom: 10px;
            font-size: 40px;
            opacity: 0.2;
        }

        /* ============================================
           TOAST NOTIFICATION
        ============================================ */
        .toast-notification {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 9999;
            min-width: 320px;
            animation: slideInRight 0.3s ease-out;
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        /* ============================================
           LOADING OVERLAY
        ============================================ */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 9998;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .loading-content {
            background: white;
            padding: 30px;
            border-radius: 24px;
            text-align: center;
            animation: fadeInUp 0.3s ease-out;
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

        @keyframes slideInLeft {
            from {
                opacity: 0;
                transform: translateX(-30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(30px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .fade-in-up {
            animation: fadeInUp 0.6s ease-out;
        }

        .slide-in-left {
            animation: slideInLeft 0.6s ease-out;
        }

        .slide-in-right {
            animation: slideInRight 0.6s ease-out;
        }

        .delay-1 { animation-delay: 0.1s; }
        .delay-2 { animation-delay: 0.2s; }
        .delay-3 { animation-delay: 0.3s; }

        /* ============================================
           HELP SECTION
        ============================================ */
        .help-step {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 16px;
            transition: var(--transition);
        }

        .help-step:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }

        .help-number {
            width: 40px;
            height: 40px;
            background: var(--primary-gradient);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .help-content h6 {
            color: var(--primary-green);
            margin-bottom: 5px;
            font-weight: 700;
        }

        /* ============================================
           SCROLLBAR
        ============================================ */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: var(--primary-green);
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }

        /* ============================================
           RESPONSIVIDADE
        ============================================ */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .custom-toolbar-buttons {
                justify-content: center;
            }
            
            .toast-notification {
                left: 20px;
                right: 20px;
                min-width: auto;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .btn-ajuda, .btn-enviar, .btn-preview,
            .custom-toolbar-buttons, .upload-area, .alerta-info {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
            }
            
            .page-header {
                background: #006B3E !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .info-card, .stat-card, .proposta-card {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header fade-in-up">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h2><i class="fas fa-file-alt me-2"></i> Proposta de Prova</h2>
                    <p>Submeta propostas de prova para aprovação da coordenação pedagógica</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                    <button type="button" class="btn-ajuda" data-bs-toggle="modal" data-bs-target="#modalAjuda">
                        <i class="fas fa-question-circle"></i> Como Funciona
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Alerta Info -->
        <div class="alerta-info fade-in-up">
            <i class="fas fa-info-circle text-success me-2"></i>
            <strong>📌 Informação Importante:</strong><br>
            As propostas de prova devem ser submetidas com pelo menos 5 dias de antecedência da data prevista.
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show fade-in-up" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Coluna Esquerda - Formulário -->
            <div class="col-md-6">
                <div class="info-card slide-in-left">
                    <div class="info-title"><i class="fas fa-edit"></i> Nova Proposta de Prova</div>
                    <div class="info-body">
                        <form method="POST" id="formProposta" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-users"></i> Turma *</label>
                                <select name="turma_id" id="turma_id" class="form-select" required>
                                    <option value="">Selecione a turma...</option>
                                    <?php foreach ($turmas as $turma): ?>
                                    <option value="<?php echo $turma['id']; ?>"><?php echo htmlspecialchars($turma['nome']); ?> - <?php echo $turma['ano']; ?>ª (<?php echo ucfirst($turma['turno']); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-book"></i> Disciplina *</label>
                                <select name="disciplina_id" id="disciplina_id" class="form-select" required>
                                    <option value="">Primeiro selecione a turma...</option>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><i class="fas fa-layer-group"></i> Bimestre *</label>
                                    <select name="bimestre" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <option value="1">1º Bimestre</option>
                                        <option value="2">2º Bimestre</option>
                                        <option value="3">3º Bimestre</option>
                                        <option value="4">4º Bimestre</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><i class="fas fa-tag"></i> Tipo de Prova *</label>
                                    <select name="tipo_prova" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($tipos_prova as $key => $nome): ?>
                                        <option value="<?php echo $key; ?>"><?php echo $nome; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-heading"></i> Título da Proposta *</label>
                                <input type="text" name="titulo" class="form-control" placeholder="Ex: Prova de Matemática - 1º Bimestre" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-align-left"></i> Descrição / Justificativa</label>
                                <textarea name="descricao" class="form-control" rows="3" placeholder="Descreva os objetivos da prova, conteúdos abordados, etc."></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-pencil-ruler"></i> Conteúdo da Prova *</label>
                                <div class="custom-toolbar-buttons">
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="inserirFormula()">
                                        <i class="fas fa-square-root-alt"></i> Fórmula
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-success" onclick="inserirFigura()">
                                        <i class="fas fa-shapes"></i> Figura
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-info" onclick="inserirTabela()">
                                        <i class="fas fa-table"></i> Tabela
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="inserirMatriz()">
                                        <i class="fas fa-border-all"></i> Matriz
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" onclick="inserirExercicio()">
                                        <i class="fas fa-pencil-alt"></i> Exercício
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="inserirAlternativas()">
                                        <i class="fas fa-check-circle"></i> Alternativas
                                    </button>
                                </div>
                                <textarea name="conteudo" id="conteudo" class="form-control summernote" rows="10"></textarea>
                                <small class="text-muted">Utilize os botões acima para inserir fórmulas matemáticas, figuras, tabelas, matrizes e exercícios.</small>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label"><i class="fas fa-calendar"></i> Data Prevista *</label>
                                    <input type="date" name="data_prevista" id="data_prevista" class="form-control" required>
                                    <small class="text-muted">Mínimo 5 dias de antecedência</small>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><i class="fas fa-hourglass-half"></i> Duração (min)</label>
                                    <input type="number" name="duracao" class="form-control" value="60" min="30" max="180">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label"><i class="fas fa-weight-hanging"></i> Peso na Média</label>
                                    <input type="number" step="0.5" name="peso" class="form-control" value="10" min="0" max="100">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label"><i class="fas fa-paperclip"></i> Anexar Documento (Opcional)</label>
                                <div class="upload-area" id="uploadArea" onclick="document.getElementById('anexo').click()">
                                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-2"></i>
                                    <p class="mb-0">Clique para fazer upload ou arraste arquivos aqui</p>
                                    <small class="text-muted">Formatos: PDF, DOC, DOCX, JPG, PNG (Max: 5MB)</small>
                                    <input type="file" name="anexo" id="anexo" style="display: none;" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                                </div>
                                <div id="fileInfo" class="mt-2" style="display: none;">
                                    <div class="alert alert-info">
                                        <i class="fas fa-file me-2"></i> <span id="fileName"></span>
                                        <button type="button" class="btn-close float-end" onclick="removerArquivo()"></button>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="alert alert-warning small">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Atenção:</strong> Após enviar, a proposta será analisada pela coordenação.
                            </div>
                            
                            <button type="button" class="btn-enviar w-100" onclick="confirmarEnvio()">
                                <i class="fas fa-paper-plane me-2"></i> Submeter Proposta
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Coluna Direita - Estatísticas e Histórico -->
            <div class="col-md-6">
                <div class="stats-grid">
                    <div class="stat-card slide-in-right delay-1">
                        <div class="stat-number"><?php echo $total_propostas; ?></div>
                        <div class="stat-label">Total de Propostas</div>
                    </div>
                    <div class="stat-card warning slide-in-right delay-2">
                        <div class="stat-number"><?php echo $total_pendente; ?></div>
                        <div class="stat-label">Pendentes</div>
                    </div>
                    <div class="stat-card success slide-in-right delay-3">
                        <div class="stat-number"><?php echo $total_aprovado; ?></div>
                        <div class="stat-label">Aprovadas</div>
                    </div>
                </div>
                
                <div class="info-card fade-in-up">
                    <div class="info-title">
                        <i class="fas fa-history"></i> Histórico de Propostas
                        <button class="btn btn-sm btn-outline-secondary float-end" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Atualizar
                        </button>
                    </div>
                    <div class="info-body">
                        <?php if (empty($propostas)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p>Nenhuma proposta encontrada.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($propostas as $prop): ?>
                            <div class="proposta-card <?php echo $prop['status']; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($prop['titulo']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($prop['disciplina_nome']); ?> - 
                                            <i class="fas fa-users"></i> <?php echo htmlspecialchars($prop['turma_nome']); ?>
                                        </small>
                                    </div>
                                    <div class="d-flex gap-1">
                                        <?php echo getTipoProvaBadge($prop['tipo_prova']); ?>
                                        <?php echo getStatusBadgeProposta($prop['status']); ?>
                                    </div>
                                </div>
                                <div class="mt-2">
                                    <small><i class="fas fa-calendar"></i> Data: <?php echo formatarData($prop['data_prevista']); ?></small><br>
                                    <small><i class="fas fa-clock"></i> Duração: <?php echo $prop['duracao']; ?> min | Peso: <?php echo $prop['peso']; ?></small>
                                </div>
                                <div class="mt-2">
                                    <button class="btn-preview" onclick="visualizarPreview(<?php echo $prop['id']; ?>)">
                                        <i class="fas fa-eye"></i> Preview
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Ajuda -->
    <div class="modal fade" id="modalAjuda" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-custom" style="background: var(--primary-gradient); color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Como Funciona?</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="help-step">
                        <div class="help-number">1</div>
                        <div class="help-content">
                            <h6>Preencher Formulário</h6>
                            <p>Informe a turma, disciplina, bimestre e conteúdo da prova.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">2</div>
                        <div class="help-content">
                            <h6>Formatar Conteúdo</h6>
                            <p>Use os botões coloridos acima do editor para inserir fórmulas, figuras, tabelas, matrizes e exercícios.</p>
                        </div>
                    </div>
                    <div class="help-step">
                        <div class="help-number">3</div>
                        <div class="help-content">
                            <h6>Submeter</h6>
                            <p>Envie para análise da coordenação pedagógica.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal de Confirmação -->
    <div class="modal fade" id="modalConfirmacao" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-question-circle me-2"></i> Confirmar Submissão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja submeter esta proposta?</p>
                    <div class="alert alert-info">
                        <span id="confirm_resumo"></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-success" id="btnConfirmarEnvio">Sim, Submeter</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
    
    <script>
        $(document).ready(function() {
            $('#conteudo').summernote({
                height: 300,
                toolbar: [
                    ['style', ['style', 'p', 'h1', 'h2', 'h3']],
                    ['font', ['bold', 'italic', 'underline', 'clear']],
                    ['fontname', ['fontname']],
                    ['color', ['color']],
                    ['para', ['ul', 'ol', 'paragraph']],
                    ['height', ['height']],
                    ['table', ['table']],
                    ['insert', ['link', 'picture', 'video']],
                    ['view', ['fullscreen', 'codeview', 'undo', 'redo']]
                ]
            });
            
            let dataMinima = new Date();
            dataMinima.setDate(dataMinima.getDate() + 5);
            $('#data_prevista').attr('min', dataMinima.toISOString().split('T')[0]);
            
            $('#turma_id').change(function() {
                let turmaId = $(this).val();
                let disciplinaSelect = $('#disciplina_id');
                
                if (!turmaId) {
                    disciplinaSelect.html('<option value="">Primeiro selecione a turma...</option>');
                    return;
                }
                
                disciplinaSelect.html('<option value="">Carregando...</option>');
                
                $.ajax({
                    url: window.location.href,
                    method: 'GET',
                    data: { ajax_disciplinas: 1, turma_id: turmaId },
                    dataType: 'json',
                    success: function(data) {
                        disciplinaSelect.html('<option value="">Selecione a disciplina...</option>');
                        if (data.length > 0) {
                            $.each(data, function(i, disc) {
                                disciplinaSelect.append('<option value="' + disc.id + '">' + disc.nome + '</option>');
                            });
                        } else {
                            disciplinaSelect.append('<option value="">Nenhuma disciplina encontrada</option>');
                        }
                    },
                    error: function() {
                        disciplinaSelect.html('<option value="">Erro ao carregar disciplinas</option>');
                    }
                });
            });
        });
        
        function inserirFormula() {
            var formula = prompt('Digite a fórmula matemática em LaTeX:', '\\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}');
            if (formula) {
                var html = '<div class="formula" style="text-align:center; padding:10px; background:#f0f0f0; margin:10px 0; border-radius:5px;">';
                html += '\\[' + formula + '\\]';
                html += '</div>';
                $('#conteudo').summernote('pasteHTML', html);
            }
        }
        
        function inserirFigura() {
            var html = '<div class="figura" style="text-align:center; margin:10px 0; padding:10px; background:#f8f9fa; border-radius:5px;">';
            html += '<p><strong>Figura Geométrica</strong></p>';
            html += '<p>Base: ____ cm | Altura: ____ cm | Área: ____ cm²</p>';
            html += '</div>';
            $('#conteudo').summernote('pasteHTML', html);
        }
        
        function inserirTabela() {
            var linhas = prompt('Número de linhas:', '3');
            var colunas = prompt('Número de colunas:', '4');
            if (linhas && colunas) {
                var html = '<table class="table table-bordered" style="width:100%; border-collapse:collapse;">';
                html += '<thead class="table-dark"><tr>';
                for (var c = 0; c < colunas; c++) html += '<th>Coluna ' + (c+1) + '</th>';
                html += '</tr></thead><tbody>';
                for (var l = 1; l < linhas; l++) {
                    html += '<tr>';
                    for (var c = 0; c < colunas; c++) html += '<td>____</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                $('#conteudo').summernote('pasteHTML', html);
            }
        }
        
        function inserirMatriz() {
            var tipo = prompt('Matriz:\n1-2x2\n2-3x3\n3-Determinante', '1');
            var html = '<div class="matriz" style="text-align:center; padding:10px; background:#f0f0f0; margin:10px 0;">';
            if (tipo == '1') html += '\\[ \\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix} \\]';
            else if (tipo == '2') html += '\\[ \\begin{pmatrix} a & b & c \\\\ d & e & f \\\\ g & h & i \\end{pmatrix} \\]';
            else html += '\\[ \\det \\begin{pmatrix} a & b \\\\ c & d \\end{pmatrix} = ad - bc \\]';
            html += '</div>';
            $('#conteudo').summernote('pasteHTML', html);
        }
        
        function inserirExercicio() {
            var html = '<div class="exercicio" style="margin:15px 0; padding:10px; border-left:4px solid #006B3E; background:#f8f9fa;">';
            html += '<strong>Exercício:</strong><br><br>_________________________________<br><br>';
            html += '<strong>Resolução:</strong><br>_________________________________</div>';
            $('#conteudo').summernote('pasteHTML', html);
        }
        
        function inserirAlternativas() {
            var enunciado = prompt('Enunciado da questão:', 'Questão:');
            if (enunciado) {
                var html = '<div class="questao" style="margin:20px 0; padding:15px; border:1px solid #ddd; border-radius:10px; background:#fef9e6;">';
                html += '<p><strong>' + enunciado + '</strong></p>';
                html += '<div class="alternativas">';
                html += '<div><strong>A)</strong> _________________________________</div>';
                html += '<div><strong>B)</strong> _________________________________</div>';
                html += '<div><strong>C)</strong> _________________________________</div>';
                html += '<div><strong>D)</strong> _________________________________</div>';
                html += '<div><strong>E)</strong> _________________________________</div>';
                html += '</div><div class="mt-2"><strong>Resposta:</strong> ______</div>';
                html += '</div>';
                $('#conteudo').summernote('pasteHTML', html);
            }
        }
        
        function visualizarPreview(id) {
            window.open('proposta_prova.php?preview=1&id=' + id, '_blank', 'width=900,height=700');
        }
        
        $('#anexo').change(function() {
            if (this.files && this.files[0]) {
                $('#fileName').text(this.files[0].name);
                $('#fileInfo').show();
            }
        });
        
        $('.upload-area').on('dragover', function(e) { e.preventDefault(); $(this).addClass('dragover'); });
        $('.upload-area').on('dragleave', function(e) { $(this).removeClass('dragover'); });
        $('.upload-area').on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
            var files = e.originalEvent.dataTransfer.files;
            if (files.length) {
                $('#anexo')[0].files = files;
                $('#anexo').trigger('change');
            }
        });
        
        function removerArquivo() {
            $('#anexo').val('');
            $('#fileInfo').hide();
        }
        
        function confirmarEnvio() {
            if (!$('#turma_id').val()) { 
                showNotification('warning', 'Selecione uma turma.');
                return; 
            }
            if (!$('#disciplina_id').val()) { 
                showNotification('warning', 'Selecione uma disciplina.');
                return; 
            }
            if ($('select[name="bimestre"]').val() == '') { 
                showNotification('warning', 'Selecione o bimestre.');
                return; 
            }
            if ($('input[name="titulo"]').val() == '') { 
                showNotification('warning', 'Informe o título da prova.');
                return; 
            }
            if ($('#conteudo').summernote('isEmpty')) { 
                showNotification('warning', 'Descreva o conteúdo da prova.');
                return; 
            }
            if ($('#data_prevista').val() == '') { 
                showNotification('warning', 'Informe a data prevista.');
                return; 
            }
            
            $('#confirm_resumo').html(
                '📚 Turma: ' + $('#turma_id option:selected').text() + '<br>' +
                '📖 Disciplina: ' + $('#disciplina_id option:selected').text() + '<br>' +
                '📅 Data: ' + $('#data_prevista').val() + '<br>' +
                '⏱️ Duração: ' + $('input[name="duracao"]').val() + ' minutos'
            );
            
            new bootstrap.Modal(document.getElementById('modalConfirmacao')).show();
        }
        
        $('#btnConfirmarEnvio').click(function() {
            $('#modalConfirmacao').modal('hide');
            showLoading();
            $('#formProposta').submit();
        });
        
        function showNotification(type, message) {
            var toastHTML = `
                <div class="toast-notification">
                    <div class="alert alert-${type === 'warning' ? 'warning' : type === 'success' ? 'success' : 'danger'} alert-dismissible fade show shadow-lg" style="border-radius: 12px;">
                        <div class="d-flex align-items-center">
                            <div class="me-3">
                                ${type === 'success' ? '<i class="fas fa-check-circle fa-2x text-success"></i>' : '<i class="fas fa-exclamation-triangle fa-2x text-warning"></i>'}
                            </div>
                            <div class="flex-grow-1">
                                <strong>${type === 'success' ? 'Sucesso!' : 'Atenção!'}</strong><br>
                                <small>${message}</small>
                            </div>
                            <button type="button" class="btn-close" onclick="$(this).closest('.toast-notification').fadeOut(300, function(){ $(this).remove(); })"></button>
                        </div>
                    </div>
                </div>
            `;
            $('.toast-notification').remove();
            $('body').append(toastHTML);
            setTimeout(function() {
                $('.toast-notification').fadeOut(300, function() { $(this).remove(); });
            }, 5000);
        }
        
        function showLoading() {
            var loadingHTML = `
                <div class="loading-overlay">
                    <div class="loading-content">
                        <div class="spinner-border text-success mb-2" role="status" style="width: 40px; height: 40px;">
                            <span class="visually-hidden">Carregando...</span>
                        </div>
                        <p class="mb-0">Enviando proposta...</p>
                    </div>
                </div>
            `;
            $('.loading-overlay').remove();
            $('body').append(loadingHTML);
        }
        
        function hideLoading() {
            $('.loading-overlay').fadeOut(300, function() { $(this).remove(); });
        }
    </script>
</body>
</html>