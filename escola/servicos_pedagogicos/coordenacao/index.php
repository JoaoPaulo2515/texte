<?php
// escola/servicos_pedagogicos/coordenacao/index.php - Dashboard da Coordenação
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$coordenador_id = $_SESSION['usuario_id'] ?? 0;

// Buscar ID do coordenador na tabela professores
$sql_prof = "SELECT id FROM funcionarios WHERE usuario_id = :usuario_id AND escola_id = :escola_id";
$stmt_prof = $conn->prepare($sql_prof);
$stmt_prof->execute([':usuario_id' => $coordenador_id, ':escola_id' => $escola_id]);
$professor_data = $stmt_prof->fetch(PDO::FETCH_ASSOC);
$professor_id = $professor_data['id'] ?? 0;

// ============================================
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Tabela de comunicados
$check = $conn->query("SHOW TABLES LIKE 'comunicados_coordenacao'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE comunicados_coordenacao (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            conteudo TEXT NOT NULL,
            tipo VARCHAR(20) DEFAULT 'informativo',
            prioridade VARCHAR(20) DEFAULT 'media',
            destinatarios TEXT,
            data_publicacao DATE,
            data_expiracao DATE,
            status VARCHAR(20) DEFAULT 'ativo',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// Tabela de reuniões
$check = $conn->query("SHOW TABLES LIKE 'reunioes_coordenacao'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE reunioes_coordenacao (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            data_reuniao DATETIME NOT NULL,
            duracao INT DEFAULT 60,
            local VARCHAR(100),
            participantes TEXT,
            pauta TEXT,
            ata TEXT,
            status VARCHAR(20) DEFAULT 'agendada',
            usuario_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
        )
    ");
}

// Tabela de avaliações institucionais
$check = $conn->query("SHOW TABLES LIKE 'avaliacoes_institucionais'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE avaliacoes_institucionais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            tipo VARCHAR(30) DEFAULT 'pedagogica',
            data_inicio DATE,
            data_fim DATE,
            status VARCHAR(20) DEFAULT 'pendente',
            resultados TEXT,
            recomendacoes TEXT,
            responsavel VARCHAR(100),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// TABELAS DO CONSELHO DE NOTA
// ============================================

$check = $conn->query("SHOW TABLES LIKE 'conselho_nota_permissoes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE conselho_nota_permissoes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            coordenador_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            escola_id INT NOT NULL,
            ano_letivo_id INT NOT NULL,
            ativo TINYINT DEFAULT 1,
            criado_por INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_professor (funcionario_id),
            UNIQUE KEY uk_professor_ano (funcionario_id, ano_letivo_id),
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
            FOREIGN KEY (coordenador_id) REFERENCES funcionarios(id) ON DELETE CASCADE,
            FOREIGN KEY (escola_id) REFERENCES escolas(id) ON DELETE CASCADE,
            FOREIGN KEY (ano_letivo_id) REFERENCES ano_letivo(id) ON DELETE CASCADE
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'conselho_nota_sessoes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE conselho_nota_sessoes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            coordenador_id INT NOT NULL,
            escola_id INT NOT NULL,
            ano_letivo_id INT NOT NULL,
            turma_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            bimestre INT NOT NULL,
            titulo VARCHAR(200),
            descricao TEXT,
            data_sessao DATE,
            hora_inicio TIME,
            hora_fim TIME,
            status VARCHAR(20) DEFAULT 'agendado',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_turma (turma_id),
            INDEX idx_disciplina (disciplina_id),
            INDEX idx_status (status),
            FOREIGN KEY (coordenador_id) REFERENCES funcionarios(id),
            FOREIGN KEY (escola_id) REFERENCES escolas(id),
            FOREIGN KEY (ano_letivo_id) REFERENCES ano_letivo(id),
            FOREIGN KEY (turma_id) REFERENCES turmas(id),
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id)
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'conselho_nota_participantes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE conselho_nota_participantes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            sessao_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            papel VARCHAR(20) DEFAULT 'membro',
            presente TINYINT DEFAULT 0,
            confirmado TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sessao (sessao_id),
            INDEX idx_professor (funcionario_id),
            UNIQUE KEY uk_sessao_professor (sessao_id, funcionario_id),
            FOREIGN KEY (sessao_id) REFERENCES conselho_nota_sessoes(id) ON DELETE CASCADE,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'conselho_nota_solicitacoes'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE conselho_nota_solicitacoes (
            id INT PRIMARY KEY AUTO_INCREMENT,
            sessao_id INT NOT NULL,
            professor_solicitante_id INT NOT NULL,
            matricula_id INT NOT NULL,
            estudante_id INT NOT NULL,
            disciplina_id INT NOT NULL,
            bimestre INT NOT NULL,
            nota_atual DECIMAL(5,2) NOT NULL,
            nota_sugerida DECIMAL(5,2) NOT NULL,
            motivo VARCHAR(200) NOT NULL,
            justificativa TEXT NOT NULL,
            evidencias TEXT,
            documento_anexo VARCHAR(255),
            status VARCHAR(20) DEFAULT 'pendente',
            votos_favoraveis INT DEFAULT 0,
            votos_contra INT DEFAULT 0,
            resultado_final VARCHAR(20) DEFAULT NULL,
            parecer_final TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sessao (sessao_id),
            INDEX idx_matricula (matricula_id),
            INDEX idx_status (status),
            FOREIGN KEY (sessao_id) REFERENCES conselho_nota_sessoes(id) ON DELETE CASCADE,
            FOREIGN KEY (matricula_id) REFERENCES matriculas(id) ON DELETE CASCADE,
            FOREIGN KEY (estudante_id) REFERENCES estudantes(id) ON DELETE CASCADE,
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
            FOREIGN KEY (professor_solicitante_id) REFERENCES funcionarios(id)
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'conselho_nota_votos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE conselho_nota_votos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            solicitacao_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            voto VARCHAR(20) NOT NULL,
            justificativa TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_solicitacao (solicitacao_id),
            INDEX idx_professor (funcionario_id),
            UNIQUE KEY uk_solicitacao_professor (solicitacao_id, funcionario_id),
            FOREIGN KEY (solicitacao_id) REFERENCES conselho_nota_solicitacoes(id) ON DELETE CASCADE,
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id) ON DELETE CASCADE
        )
    ");
}

$check = $conn->query("SHOW TABLES LIKE 'conselho_nota_historicos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE conselho_nota_historicos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            solicitacao_id INT NOT NULL,
            acao VARCHAR(50) NOT NULL,
            observacao TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_solicitacao (solicitacao_id),
            FOREIGN KEY (solicitacao_id) REFERENCES conselho_nota_solicitacoes(id) ON DELETE CASCADE
        )
    ");
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$ano_letivo = $conn->query($sql_ano)->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_atual = $ano_letivo['ano'] ?? date('Y');

// ============================================
// BUSCAR ESTATÍSTICAS
// ============================================

// Total de comunicados ativos
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM comunicados_coordenacao WHERE escola_id = :escola_id AND status = 'ativo'");
$stmt->execute([':escola_id' => $escola_id]);
$total_comunicados = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de reuniões agendadas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM reunioes_coordenacao WHERE escola_id = :escola_id AND status = 'agendada' AND data_reuniao >= NOW()");
$stmt->execute([':escola_id' => $escola_id]);
$total_reunioes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de avaliações em andamento
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM avaliacoes_institucionais WHERE escola_id = :escola_id AND status IN ('pendente', 'em_andamento')");
$stmt->execute([':escola_id' => $escola_id]);
$total_avaliacoes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de professores
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = :escola_id");
$stmt->execute([':escola_id' => $escola_id]);
$total_professores = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de turmas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'");
$stmt->execute([':escola_id' => $escola_id]);
$total_turmas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Total de sessões do conselho ativas
$stmt = $conn->prepare("SELECT COUNT(*) as total FROM conselho_nota_sessoes WHERE escola_id = :escola_id AND status IN ('agendado', 'em_andamento')");
$stmt->execute([':escola_id' => $escola_id]);
$total_sessoes_conselho = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Buscar últimos comunicados
$ultimos_comunicados = $conn->prepare("
    SELECT * FROM comunicados_coordenacao 
    WHERE escola_id = :escola_id AND status = 'ativo' 
    ORDER BY created_at DESC 
    LIMIT 5
");
$ultimos_comunicados->execute([':escola_id' => $escola_id]);
$ultimos_comunicados = $ultimos_comunicados->fetchAll(PDO::FETCH_ASSOC);

// Buscar próximas reuniões
$proximas_reunioes = $conn->prepare("
    SELECT * FROM reunioes_coordenacao 
    WHERE escola_id = :escola_id AND status = 'agendada' AND data_reuniao >= NOW()
    ORDER BY data_reuniao ASC 
    LIMIT 5
");
$proximas_reunioes->execute([':escola_id' => $escola_id]);
$proximas_reunioes = $proximas_reunioes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DADOS PARA CHECKBOXES
// ============================================

// Buscar todas as turmas da escola
$sql_turmas_all = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_tur = $conn->prepare($sql_turmas_all);
$stmt_tur->execute([':escola_id' => $escola_id]);
$todas_turmas = $stmt_tur->fetchAll(PDO::FETCH_ASSOC);

// Buscar todas as disciplinas
$sql_disc_all = "SELECT id, nome FROM disciplinas ORDER BY nome";
$todas_disciplinas = $conn->query($sql_disc_all)->fetchAll(PDO::FETCH_ASSOC);

// Buscar todos os professores da escola
$sql_prof_all = "
    SELECT p.id, u.nome, p.bi 
    FROM funcionarios p
    INNER JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.escola_id = :escola_id
    ORDER BY u.nome
";
$stmt_prof = $conn->prepare($sql_prof_all);
$stmt_prof->execute([':escola_id' => $escola_id]);
$todos_professores = $stmt_prof->fetchAll(PDO::FETCH_ASSOC);

// Bimestres disponíveis
$bimestres_disponiveis = [
    ['id' => 1, 'nome' => '1º Bimestre'],
    ['id' => 2, 'nome' => '2º Bimestre'],
    ['id' => 3, 'nome' => '3º Bimestre'],
    ['id' => 4, 'nome' => '4º Bimestre']
];

// Buscar sessões recentes
$stmt = $conn->prepare("
    SELECT s.*, t.nome as turma_nome, d.nome as disciplina_nome,
           (SELECT COUNT(*) FROM conselho_nota_participantes WHERE sessao_id = s.id) as total_participantes
    FROM conselho_nota_sessoes s
    INNER JOIN turmas t ON t.id = s.turma_id
    INNER JOIN disciplinas d ON d.id = s.disciplina_id
    WHERE s.escola_id = :escola_id AND s.status IN ('agendado', 'em_andamento')
    ORDER BY s.data_sessao DESC, s.created_at DESC
    LIMIT 10
");
$stmt->execute([':escola_id' => $escola_id]);
$sessoes_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR CRIAÇÃO DE SESSÕES DO CONSELHO (MÚLTIPLAS)
// ============================================
$msg_sessao = '';
$error_sessao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['criar_sessoes_conselho'])) {
    $turmas_selecionadas = $_POST['turmas'] ?? [];
    $disciplinas_selecionadas = $_POST['disciplinas'] ?? [];
    $bimestres_selecionados = $_POST['bimestres'] ?? [];
    $professores_selecionados = $_POST['professores'] ?? [];
    $data_sessao = $_POST['data_sessao'] ?? '';
    $hora_inicio = $_POST['hora_inicio'] ?? '14:00';
    $hora_fim = $_POST['hora_fim'] ?? '18:00';
    $titulo_personalizado = $_POST['titulo_personalizado'] ?? '';
    $descricao_personalizada = $_POST['descricao_personalizada'] ?? '';
    
    if (empty($turmas_selecionadas)) {
        $error_sessao = "⚠️ Selecione pelo menos uma turma.";
    } elseif (empty($disciplinas_selecionadas)) {
        $error_sessao = "⚠️ Selecione pelo menos uma disciplina.";
    } elseif (empty($bimestres_selecionados)) {
        $error_sessao = "⚠️ Selecione pelo menos um bimestre.";
    } elseif (empty($professores_selecionados)) {
        $error_sessao = "⚠️ Selecione pelo menos um professor participante.";
    } elseif (empty($data_sessao)) {
        $error_sessao = "⚠️ Informe a data da sessão.";
    } else {
        try {
            $conn->beginTransaction();
            
            $total_criadas = 0;
            $total_permissoes = 0;
            
            // Para cada combinação de turma, disciplina e bimestre
            foreach ($turmas_selecionadas as $turma_id) {
                foreach ($disciplinas_selecionadas as $disciplina_id) {
                    foreach ($bimestres_selecionados as $bimestre) {
                        
                        // Buscar nomes para o título
                        $turma_nome = '';
                        $disciplina_nome = '';
                        foreach ($todas_turmas as $t) {
                            if ($t['id'] == $turma_id) $turma_nome = $t['nome'];
                        }
                        foreach ($todas_disciplinas as $d) {
                            if ($d['id'] == $disciplina_id) $disciplina_nome = $d['nome'];
                        }
                        
                        $titulo = !empty($titulo_personalizado) 
                            ? $titulo_personalizado . " - $disciplina_nome - $turma_nome"
                            : "Conselho de Nota - $disciplina_nome - $turma_nome - {$bimestre}º Bimestre";
                        
                        $descricao = !empty($descricao_personalizada) 
                            ? $descricao_personalizada 
                            : "Sessão do Conselho de Nota para análise das notas da disciplina $disciplina_nome da turma $turma_nome referente ao {$bimestre}º Bimestre.";
                        
                        // Inserir sessão
                        $sql = "INSERT INTO conselho_nota_sessoes (
                                    coordenador_id, escola_id, ano_letivo_id, 
                                    turma_id, disciplina_id, bimestre, 
                                    titulo, descricao, data_sessao, 
                                    hora_inicio, hora_fim, status
                                ) VALUES (
                                    :coordenador_id, :escola_id, :ano_letivo_id,
                                    :turma_id, :disciplina_id, :bimestre,
                                    :titulo, :descricao, :data_sessao,
                                    :hora_inicio, :hora_fim, 'agendado'
                                )";
                        $stmt = $conn->prepare($sql);
                        $stmt->execute([
                            ':coordenador_id' => $professor_id,
                            ':escola_id' => $escola_id,
                            ':ano_letivo_id' => $ano_letivo_id,
                            ':turma_id' => $turma_id,
                            ':disciplina_id' => $disciplina_id,
                            ':bimestre' => $bimestre,
                            ':titulo' => $titulo,
                            ':descricao' => $descricao,
                            ':data_sessao' => $data_sessao,
                            ':hora_inicio' => $hora_inicio,
                            ':hora_fim' => $hora_fim
                        ]);
                        
                        $sessao_id = $conn->lastInsertId();
                        
                        // Adicionar participantes para esta sessão
                        foreach ($professores_selecionados as $prof_id) {
                            $sql_part = "INSERT IGNORE INTO conselho_nota_participantes (sessao_id, funcionario_id) 
                                         VALUES (:sessao_id, :professor_id)";
                            $stmt_part = $conn->prepare($sql_part);
                            $stmt_part->execute([
                                ':sessao_id' => $sessao_id, 
                                ':professor_id' => $prof_id
                            ]);
                        }
                        
                        // Registrar permissões automaticamente para os professores
                        foreach ($professores_selecionados as $prof_id) {
                            $sql_perm = "INSERT IGNORE INTO conselho_nota_permissoes 
                                        (coordenador_id, funcionario_id, escola_id, ano_letivo_id, ativo) 
                                        VALUES (:coordenador_id, :professor_id, :escola_id, :ano_letivo_id, 1)";
                            $stmt_perm = $conn->prepare($sql_perm);
                            $stmt_perm->execute([
                                ':coordenador_id' => $professor_id,
                                ':professor_id' => $prof_id,
                                ':escola_id' => $escola_id,
                                ':ano_letivo_id' => $ano_letivo_id
                            ]);
                            $total_permissoes++;
                        }
                        
                        $total_criadas++;
                    }
                }
            }
            
            $conn->commit();
            $msg_sessao = "✅ $total_criadas sessão(ões) do Conselho de Nota criada(s) com sucesso!<br>";
            $msg_sessao .= "📋 $total_permissoes permissão(ões) concedida(s) para os professores.";
            
            // Recarregar lista de sessões
            $stmt = $conn->prepare("
                SELECT s.*, t.nome as turma_nome, d.nome as disciplina_nome,
                       (SELECT COUNT(*) FROM conselho_nota_participantes WHERE sessao_id = s.id) as total_participantes
                FROM conselho_nota_sessoes s
                INNER JOIN turmas t ON t.id = s.turma_id
                INNER JOIN disciplinas d ON d.id = s.disciplina_id
                WHERE s.escola_id = :escola_id AND s.status IN ('agendado', 'em_andamento')
                ORDER BY s.data_sessao DESC, s.created_at DESC
                LIMIT 10
            ");
            $stmt->execute([':escola_id' => $escola_id]);
            $sessoes_recentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error_sessao = "Erro ao criar sessões: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR DISCIPLINAS POR TURMA (AJAX)
// ============================================
if (isset($_GET['ajax']) && $_GET['ajax'] == 'disciplinas_por_turma' && isset($_GET['turma_id'])) {
    $turma_id = (int)$_GET['turma_id'];
    
    // Buscar disciplinas associadas à turma
    $sql = "
        SELECT DISTINCT d.id, d.nome 
        FROM disciplinas d
        INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
        WHERE pdt.turma_id = :turma_id
        ORDER BY d.nome
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($disciplinas);
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Coordenação Pedagógica | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; border-radius: 15px 15px 0 0; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; border-radius: 15px; padding: 20px; text-align: center; transition: transform 0.3s; cursor: pointer; }
        .stat-card:hover { transform: translateY(-5px); }
        .stat-value { font-size: 2em; font-weight: bold; color: #006B3E; }
        .stat-label { color: #666; font-size: 0.85em; }
        .stat-icon { font-size: 2em; margin-bottom: 10px; }
        
        .comunicado-item, .reuniao-item, .sessao-item {
            border-left: 3px solid #006B3E;
            margin-bottom: 10px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            transition: all 0.3s;
        }
        .sessao-item { border-left-color: #6f42c1; background: #f8f0ff; }
        .comunicado-urgente { border-left-color: #dc3545; background: #fff5f5; }
        .comunicado-alta { border-left-color: #fd7e14; }
        .reuniao-item { border-left-color: #17a2b8; }
        
        .modulo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-top: 20px; }
        .modulo-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            text-decoration: none;
            color: #333;
            transition: all 0.3s;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: block;
        }
        .modulo-card:hover { transform: translateY(-5px); box-shadow: 0 5px 20px rgba(0,0,0,0.1); color: #006B3E; }
        .modulo-icon { font-size: 2.5em; margin-bottom: 15px; }
        .modulo-title { font-weight: bold; margin-bottom: 5px; }
        .modulo-desc { font-size: 0.75em; color: #666; }
        
        .badge-urgente { background: #dc3545; color: white; }
        .badge-alta { background: #fd7e14; color: white; }
        .badge-media { background: #ffc107; color: #000; }
        .badge-baixa { background: #28a745; color: white; }
        .badge-conselho { background: #6f42c1; color: white; }
        
        .form-check-input:checked {
            background-color: #006B3E;
            border-color: #006B3E;
        }
        .card-header .form-check-input {
            margin-top: 0;
            vertical-align: middle;
        }
        .select2-container--bootstrap-5 .select2-selection { border-radius: 8px; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-users"></i> Coordenação Pedagógica</h2>
            <div>
                <a href="comunicados.php" class="btn btn-primary btn-sm me-2">
                    <i class="fas fa-bullhorn"></i> Comunicados
                </a>
                <a href="reunioes.php" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-calendar-alt"></i> Reuniões
                </a>
                <a href="avaliacoes.php" class="btn btn-info btn-sm me-2">
                    <i class="fas fa-chart-line"></i> Avaliações
                </a>
                <a href="horarios.php" class="btn btn-warning btn-sm">
                    <i class="fas fa-clock"></i> Horários
                </a>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-bullhorn"></i></div><div class="stat-value"><?php echo $total_comunicados; ?></div><div class="stat-label">Comunicados Ativos</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div class="stat-value"><?php echo $total_reunioes; ?></div><div class="stat-label">Reuniões Agendadas</div></div>
            <div class="stat-card" onclick="$('#modalConselhoNota').modal('show');"><div class="stat-icon"><i class="fas fa-gavel"></i></div><div class="stat-value"><?php echo $total_sessoes_conselho; ?></div><div class="stat-label">Sessões Conselho</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-chart-line"></i></div><div class="stat-value"><?php echo $total_avaliacoes; ?></div><div class="stat-label">Avaliações</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-chalkboard-user"></i></div><div class="stat-value"><?php echo $total_professores; ?></div><div class="stat-label">Professores</div></div>
            <div class="stat-card"><div class="stat-icon"><i class="fas fa-users-group"></i></div><div class="stat-value"><?php echo $total_turmas; ?></div><div class="stat-label">Turmas Ativas</div></div>
        </div>
        
        <div class="row">
            <!-- Últimos Comunicados -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-bullhorn"></i> Últimos Comunicados</span>
                        <a href="comunicados.php" class="btn btn-sm btn-primary">Ver Todos</a>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($ultimos_comunicados)): ?>
                            <p class="text-center text-muted">Nenhum comunicado publicado</p>
                        <?php else: ?>
                            <?php foreach ($ultimos_comunicados as $com): ?>
                            <div class="comunicado-item comunicado-<?php echo $com['prioridade']; ?>">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <span class="badge badge-<?php echo $com['prioridade']; ?> mb-1"><?php echo ucfirst($com['prioridade']); ?></span>
                                        <strong><?php echo htmlspecialchars($com['titulo']); ?></strong>
                                        <p class="mb-0 small"><?php echo htmlspecialchars(substr($com['conteudo'], 0, 80)); ?>...</p>
                                        <small class="text-muted"><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($com['created_at'])); ?></small>
                                    </div>
                                    <span class="badge bg-secondary"><?php echo ucfirst($com['tipo']); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Próximas Reuniões -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-calendar-alt"></i> Próximas Reuniões</span>
                        <a href="reunioes.php" class="btn btn-sm btn-success">Ver Todos</a>
                    </div>
                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                        <?php if (empty($proximas_reunioes)): ?>
                            <p class="text-center text-muted">Nenhuma reunião agendada</p>
                        <?php else: ?>
                            <?php foreach ($proximas_reunioes as $reu): ?>
                            <div class="reuniao-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?php echo htmlspecialchars($reu['titulo']); ?></strong>
                                        <p class="mb-0 small"><?php echo htmlspecialchars(substr($reu['descricao'], 0, 60)); ?></p>
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($reu['data_reuniao'])); ?> |
                                            <i class="fas fa-map-marker-alt"></i> <?php echo $reu['local']; ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-info">Agendada</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Sessões do Conselho de Nota -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-gavel"></i> Conselho de Nota - Sessões Recentes</span>
                <button class="btn btn-sm btn-primary" onclick="$('#modalConselhoNota').modal('show');">
                    <i class="fas fa-plus"></i> Nova Sessão
                </button>
            </div>
            <div class="card-body">
                <?php if (empty($sessoes_recentes)): ?>
                    <p class="text-center text-muted">Nenhuma sessão do conselho criada</p>
                <?php else: ?>
                    <?php foreach ($sessoes_recentes as $sessao): ?>
                    <div class="sessao-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <span class="badge badge-conselho mb-1">Conselho de Nota</span>
                                <strong><?php echo htmlspecialchars($sessao['titulo']); ?></strong>
                                <p class="mb-0 small"><?php echo htmlspecialchars(substr($sessao['descricao'], 0, 80)); ?></p>
                                <small class="text-muted">
                                    <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($sessao['data_sessao'])); ?> |
                                    <i class="fas fa-clock"></i> <?php echo substr($sessao['hora_inicio'], 0, 5); ?> - <?php echo substr($sessao['hora_fim'], 0, 5); ?> |
                                    <i class="fas fa-flag-checkered"></i> <?php echo $sessao['bimestre']; ?>º Bimestre |
                                    <i class="fas fa-users"></i> <?php echo $sessao['total_participantes']; ?> participantes
                                </small>
                            </div>
                            <span class="badge bg-<?php echo $sessao['status'] == 'agendado' ? 'warning' : 'info'; ?>">
                                <?php echo $sessao['status'] == 'agendado' ? 'Agendado' : 'Em Andamento'; ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Módulos de Acesso Rápido -->
        <div class="card">
            <div class="card-header"><i class="fas fa-th-large"></i> Acesso Rápido</div>
            <div class="card-body">
                <div class="modulo-grid">
                    <a href="comunicados.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-bullhorn text-primary"></i></div>
                        <div class="modulo-title">Comunicados</div>
                        <div class="modulo-desc">Gerir comunicados internos</div>
                    </a>
                    <a href="reunioes.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-calendar-alt text-success"></i></div>
                        <div class="modulo-title">Reuniões</div>
                        <div class="modulo-desc">Agendar e gerir reuniões</div>
                    </a>
                    <a href="avaliacoes.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-chart-line text-info"></i></div>
                        <div class="modulo-title">Avaliações</div>
                        <div class="modulo-desc">Avaliações institucionais</div>
                    </a>
                    <a href="horarios.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-clock text-warning"></i></div>
                        <div class="modulo-title">Horários</div>
                        <div class="modulo-desc">Gestão de horários</div>
                    </a>
                    <a href="relatorios.php" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-chart-bar text-danger"></i></div>
                        <div class="modulo-title">Relatórios</div>
                        <div class="modulo-desc">Relatórios pedagógicos</div>
                    </a>
                    <a href="javascript:void(0)" onclick="$('#modalConselhoNota').modal('show');" class="modulo-card">
                        <div class="modulo-icon"><i class="fas fa-gavel" style="color: #6f42c1;"></i></div>
                        <div class="modulo-title">Conselho de Nota</div>
                        <div class="modulo-desc">Gerir sessões do conselho</div>
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- ============================================ -->
    <!-- MODAL CRIAR SESSÕES DO CONSELHO DE NOTA -->
    <!-- ============================================ -->
    <div class="modal fade" id="modalConselhoNota" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: #6f42c1; color: white;">
                    <h5 class="modal-title"><i class="fas fa-gavel"></i> Criar Sessões do Conselho de Nota</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formConselhoNota" onsubmit="return validarFormulario()">
                    <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                        <?php if ($msg_sessao): ?>
                            <div class="alert alert-success alert-dismissible fade show">
                                <?php echo $msg_sessao; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <?php if ($error_sessao): ?>
                            <div class="alert alert-danger alert-dismissible fade show">
                                <?php echo $error_sessao; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-primary text-white">
                                        <input type="checkbox" id="selecionar_todas_turmas" onclick="toggleAll('turma', this)">
                                        <label class="ms-2 mb-0">Turmas</label>
                                        <span class="badge bg-light text-dark ms-2" id="total_turmas_selecionadas">0</span>
                                    </div>
                                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                        <?php foreach ($todas_turmas as $turma): ?>
                                        <div class="form-check mb-2">
                                            <input type="checkbox" class="form-check-input turma-checkbox" 
                                                   name="turmas[]" value="<?php echo $turma['id']; ?>" 
                                                   id="turma_<?php echo $turma['id']; ?>"
                                                   data-nome="<?php echo htmlspecialchars($turma['nome']); ?>">
                                            <label class="form-check-label" for="turma_<?php echo $turma['id']; ?>">
                                                <?php echo htmlspecialchars($turma['nome']); ?> - <?php echo $turma['ano']; ?> (<?php echo $turma['turno']; ?>)
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-success text-white">
                                        <input type="checkbox" id="selecionar_todas_disciplinas" onclick="toggleAll('disciplina', this)" disabled>
                                        <label class="ms-2 mb-0">Disciplinas</label>
                                        <span class="badge bg-light text-dark ms-2" id="total_disciplinas_selecionadas">0</span>
                                    </div>
                                    <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                                        <div id="disciplinas_lista">
                                            <p class="text-muted text-center">Selecione uma turma para carregar as disciplinas...</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-warning text-dark">
                                        <input type="checkbox" id="selecionar_todos_bimestres" onclick="toggleAll('bimestre', this)">
                                        <label class="ms-2 mb-0">Bimestres</label>
                                        <span class="badge bg-dark text-white ms-2" id="total_bimestres_selecionadas">0</span>
                                    </div>
                                    <div class="card-body">
                                        <?php foreach ($bimestres_disponiveis as $bim): ?>
                                        <div class="form-check mb-2">
                                            <input type="checkbox" class="form-check-input bimestre-checkbox" 
                                                   name="bimestres[]" value="<?php echo $bim['id']; ?>" 
                                                   id="bimestre_<?php echo $bim['id']; ?>">
                                            <label class="form-check-label" for="bimestre_<?php echo $bim['id']; ?>">
                                                <?php echo $bim['nome']; ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-info text-white">
                                        <input type="checkbox" id="selecionar_todos_professores" onclick="toggleAll('professor', this)">
                                        <label class="ms-2 mb-0">Professores Participantes</label>
                                        <span class="badge bg-light text-dark ms-2" id="total_professores_selecionadas">0</span>
                                    </div>
                                    <div class="card-body" style="max-height: 250px; overflow-y: auto;">
                                        <?php foreach ($todos_professores as $prof): ?>
                                        <div class="form-check mb-2">
                                            <input type="checkbox" class="form-check-input professor-checkbox" 
                                                   name="professores[]" value="<?php echo $prof['id']; ?>" 
                                                   id="professor_<?php echo $prof['id']; ?>">
                                            <label class="form-check-label" for="professor_<?php echo $prof['id']; ?>">
                                                <?php echo htmlspecialchars($prof['nome']); ?>
                                                <?php if ($prof['bi']): ?>
                                                <small class="text-muted">(<?php echo $prof['bi']; ?>)</small>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="card">
                                    <div class="card-header bg-secondary text-white">
                                        <i class="fas fa-calendar-alt"></i> Configurações das Sessões
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Data da Sessão *</label>
                                            <input type="date" name="data_sessao" id="data_sessao" class="form-control" required>
                                            <small class="text-muted">Todas as sessões criadas terão esta mesma data</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Horário *</label>
                                            <div class="row">
                                                <div class="col-6">
                                                    <input type="time" name="hora_inicio" id="hora_inicio" class="form-control" value="14:00" required>
                                                    <small class="text-muted">Início</small>
                                                </div>
                                                <div class="col-6">
                                                    <input type="time" name="hora_fim" id="hora_fim" class="form-control" value="18:00" required>
                                                    <small class="text-muted">Fim</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Título (Opcional)</label>
                                            <input type="text" name="titulo_personalizado" id="titulo_personalizado" class="form-control" 
                                                   placeholder="Deixe em branco para título automático">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Descrição (Opcional)</label>
                                            <textarea name="descricao_personalizada" id="descricao_personalizada" class="form-control" rows="3" 
                                                      placeholder="Deixe em branco para descrição automática"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info mt-3" id="resumo_selecao">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Resumo da Seleção:</strong><br>
                            <span id="resumo_texto">Nenhum item selecionado</span>
                        </div>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Atenção:</strong><br>
                            <ul class="mb-0 mt-2">
                                <li>Será criada uma sessão para CADA combinação de (Turma x Disciplina x Bimestre) selecionada</li>
                                <li>Exemplo: 2 turmas x 3 disciplinas x 2 bimestres = 12 sessões criadas</li>
                                <li>Os professores selecionados terão permissão automática para TODAS as sessões criadas</li>
                                <li>As sessões serão criadas com status "Agendado"</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="criar_sessoes_conselho" class="btn btn-primary" id="btnCriarSessoes">
                            <i class="fas fa-plus"></i> Criar Sessões
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('.select2').select2({ theme: 'bootstrap-5', dropdownParent: $('#modalConselhoNota') });
            $('#data_sessao').attr('min', new Date().toISOString().split('T')[0]);
            atualizarContadores();
            atualizarResumo();
        });
        
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        function toggleAll(tipo, checkbox) {
            if (tipo === 'turma') {
                document.querySelectorAll('.turma-checkbox').forEach(cb => cb.checked = checkbox.checked);
                if (checkbox.checked) carregarDisciplinasPorTurmas();
                else {
                    document.getElementById('disciplinas_lista').innerHTML = '<p class="text-muted text-center">Selecione uma turma para carregar as disciplinas...</p>';
                    document.querySelectorAll('.disciplina-checkbox').forEach(cb => cb.remove());
                }
            } else if (tipo === 'disciplina') {
                document.querySelectorAll('.disciplina-checkbox').forEach(cb => cb.checked = checkbox.checked);
            } else if (tipo === 'bimestre') {
                document.querySelectorAll('.bimestre-checkbox').forEach(cb => cb.checked = checkbox.checked);
            } else if (tipo === 'professor') {
                document.querySelectorAll('.professor-checkbox').forEach(cb => cb.checked = checkbox.checked);
            }
            atualizarContadores();
            atualizarResumo();
        }
        
        function carregarDisciplinasPorTurmas() {
            let turmasSelecionadas = [];
            document.querySelectorAll('.turma-checkbox:checked').forEach(cb => turmasSelecionadas.push(cb.value));
            if (turmasSelecionadas.length === 0) return;
            
            let promessas = [];
            turmasSelecionadas.forEach(turmaId => {
                promessas.push($.ajax({
                    url: 'index.php',
                    method: 'GET',
                    data: { ajax: 'disciplinas_por_turma', turma_id: turmaId },
                    dataType: 'json'
                }));
            });
            
            Promise.all(promessas).then(resultados => {
                let disciplinasUnicas = new Map();
                resultados.forEach(disciplinas => {
                    disciplinas.forEach(disc => { if (!disciplinasUnicas.has(disc.id)) disciplinasUnicas.set(disc.id, disc); });
                });
                
                let html = '';
                Array.from(disciplinasUnicas.values()).sort((a,b) => a.nome.localeCompare(b.nome)).forEach(disc => {
                    html += `<div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input disciplina-checkbox" name="disciplinas[]" value="${disc.id}" id="disciplina_${disc.id}">
                        <label class="form-check-label" for="disciplina_${disc.id}">${disc.nome}</label>
                    </div>`;
                });
                html = html || '<p class="text-warning text-center">Nenhuma disciplina encontrada.</p>';
                document.getElementById('disciplinas_lista').innerHTML = html;
                document.querySelectorAll('.disciplina-checkbox').forEach(cb => cb.addEventListener('change', () => { atualizarContadores(); atualizarResumo(); }));
                document.getElementById('selecionar_todas_disciplinas').disabled = false;
                atualizarContadores();
                atualizarResumo();
            }).catch(error => {
                console.error('Erro:', error);
                document.getElementById('disciplinas_lista').innerHTML = '<p class="text-danger text-center">Erro ao carregar disciplinas.</p>';
            });
        }
        
        function atualizarContadores() {
            document.getElementById('total_turmas_selecionadas').innerText = document.querySelectorAll('.turma-checkbox:checked').length;
            document.getElementById('total_disciplinas_selecionadas').innerText = document.querySelectorAll('.disciplina-checkbox:checked').length;
            document.getElementById('total_bimestres_selecionadas').innerText = document.querySelectorAll('.bimestre-checkbox:checked').length;
            document.getElementById('total_professores_selecionadas').innerText = document.querySelectorAll('.professor-checkbox:checked').length;
        }
        
        function atualizarResumo() {
            let turmas = document.querySelectorAll('.turma-checkbox:checked').length;
            let disciplinas = document.querySelectorAll('.disciplina-checkbox:checked').length;
            let bimestres = document.querySelectorAll('.bimestre-checkbox:checked').length;
            let professores = document.querySelectorAll('.professor-checkbox:checked').length;
            let total = turmas * disciplinas * bimestres;
            document.getElementById('resumo_texto').innerHTML = `🏫 Turmas: ${turmas} | 📚 Disciplinas: ${disciplinas} | 📅 Bimestres: ${bimestres} | 👨‍🏫 Professores: ${professores}<hr><strong>📊 Serão criadas ${total} sessão(ões)</strong>`;
            document.getElementById('btnCriarSessoes').innerHTML = total > 0 ? `<i class="fas fa-plus"></i> Criar ${total} Sessão(ões)` : `<i class="fas fa-plus"></i> Criar Sessões`;
        }
        
        function validarFormulario() {
            if (document.querySelectorAll('.turma-checkbox:checked').length === 0) { alert('Selecione pelo menos uma turma.'); return false; }
            if (document.querySelectorAll('.disciplina-checkbox:checked').length === 0) { alert('Selecione pelo menos uma disciplina.'); return false; }
            if (document.querySelectorAll('.bimestre-checkbox:checked').length === 0) { alert('Selecione pelo menos um bimestre.'); return false; }
            if (document.querySelectorAll('.professor-checkbox:checked').length === 0) { alert('Selecione pelo menos um professor.'); return false; }
            if (!document.getElementById('data_sessao').value) { alert('Informe a data da sessão.'); return false; }
            let total = document.querySelectorAll('.turma-checkbox:checked').length * document.querySelectorAll('.disciplina-checkbox:checked').length * document.querySelectorAll('.bimestre-checkbox:checked').length;
            return confirm(`Você está prestes a criar ${total} sessão(ões). Deseja continuar?`);
        }
        
        document.querySelectorAll('.turma-checkbox').forEach(cb => cb.addEventListener('change', function() { carregarDisciplinasPorTurmas(); atualizarContadores(); atualizarResumo(); document.getElementById('selecionar_todas_turmas').checked = document.querySelectorAll('.turma-checkbox:checked').length === document.querySelectorAll('.turma-checkbox').length; }));
        $(document).on('change', '.disciplina-checkbox', function() { atualizarContadores(); atualizarResumo(); let total = document.querySelectorAll('.disciplina-checkbox').length; let sel = document.querySelectorAll('.disciplina-checkbox:checked').length; document.getElementById('selecionar_todas_disciplinas').checked = total === sel && total > 0; });
        document.querySelectorAll('.bimestre-checkbox').forEach(cb => cb.addEventListener('change', function() { atualizarContadores(); atualizarResumo(); document.getElementById('selecionar_todos_bimestres').checked = document.querySelectorAll('.bimestre-checkbox:checked').length === document.querySelectorAll('.bimestre-checkbox').length; }));
        document.querySelectorAll('.professor-checkbox').forEach(cb => cb.addEventListener('change', function() { atualizarContadores(); atualizarResumo(); document.getElementById('selecionar_todos_professores').checked = document.querySelectorAll('.professor-checkbox:checked').length === document.querySelectorAll('.professor-checkbox').length; }));
        
        <?php if ($msg_sessao): ?>
        setTimeout(function() { $('#modalConselhoNota').modal('hide'); location.reload(); }, 3000);
        <?php endif; ?>
    </script>
</body>
</html>