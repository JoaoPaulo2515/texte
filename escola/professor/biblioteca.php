<?php
// escola/professor/biblioteca.php - Biblioteca Virtual do Professor

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

// Criar diretórios de upload
$upload_dirs = [
    __DIR__ . '/../../uploads/materiais/',
    __DIR__ . '/../../uploads/materiais/livros/',
    __DIR__ . '/../../uploads/materiais/apostilas/',
    __DIR__ . '/../../uploads/materiais/videos/',
    __DIR__ . '/../../uploads/materiais/exercicios/',
    __DIR__ . '/../../uploads/materiais/provas/'
];

foreach ($upload_dirs as $dir) {
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
}

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// INICIALIZAR VARIÁVEIS
// ============================================
$success = '';
$error = '';

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "
    SELECT p.id, u.nome as professor_nome, u.email
    FROM funcionarios p
    INNER JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :professor_id
";
$stmt_prof = $conn->prepare($sql_professor);
$stmt_prof->execute([':professor_id' => $professor_id]);
$professor_dados = $stmt_prof->fetch(PDO::FETCH_ASSOC);
$professor_nome = $professor_dados['professor_nome'] ?? '';

// ============================================
// AJAX: BUSCAR DADOS DO MATERIAL PARA VISUALIZAÇÃO
// ============================================
if (isset($_GET['get_material']) && isset($_GET['id'])) {
    $material_id = (int)$_GET['id'];
    
    $sql = "SELECT id, titulo, tipo, link_video, link_pdf, link, arquivo FROM materiais_didaticos WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $material_id, ':escola_id' => $escola_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($material) {
        echo json_encode(['success' => true, 'material' => $material]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Material não encontrado']);
    }
    exit;
}

// ============================================
// CONTAR VISUALIZAÇÃO VIA AJAX
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contar_visualizacao']) && isset($_POST['id'])) {
    $material_id = (int)$_POST['id'];
    $conn->prepare("UPDATE materiais_didaticos SET visualizacoes = visualizacoes + 1 WHERE id = :id")->execute([':id' => $material_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ============================================
// FUNÇÃO PARA VISUALIZAR MATERIAL
// ============================================
if (isset($_GET['visualizar']) && isset($_GET['id'])) {
    $material_id = (int)$_GET['id'];
    
    $sql = "SELECT * FROM materiais_didaticos WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $material_id, ':escola_id' => $escola_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($material) {
        $conn->prepare("UPDATE materiais_didaticos SET visualizacoes = visualizacoes + 1 WHERE id = :id")->execute([':id' => $material_id]);
        
        echo '<!DOCTYPE html>
        <html lang="pt-AO">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($material['titulo']) . ' - Biblioteca Virtual</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
            <style>
                body { background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%); font-family: "Segoe UI", Arial, sans-serif; }
                .header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; padding: 20px; margin-bottom: 20px; }
                .content-container { background: white; border-radius: 20px; padding: 30px; margin: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
                .material-info { background: #f8f9fa; padding: 20px; border-radius: 16px; margin-bottom: 20px; }
                .btn-link-material { border-radius: 50px; padding: 10px 24px; transition: all 0.3s; }
                .btn-link-material:hover { transform: translateY(-2px); }
                .video-container { position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden; border-radius: 16px; }
                .video-container iframe { position: absolute; top: 0; left: 0; width: 100%; height: 100%; border-radius: 16px; }
                .pdf-viewer { width: 100%; height: 700px; border: none; border-radius: 16px; }
                .text-content { font-size: 16px; line-height: 1.6; text-align: justify; }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="container-fluid">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div>
                            <h3><i class="fas fa-book-open me-2"></i> ' . htmlspecialchars($material['titulo']) . '</h3>
                            <small>' . ucfirst($material['tipo']) . ' - ' . htmlspecialchars($material['categoria']) . '</small>
                        </div>
                        <div>
                            <button class="btn btn-light btn-sm me-2" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
                            <button class="btn btn-light btn-sm" onclick="window.close()"><i class="fas fa-times"></i> Fechar</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="content-container">
                <div class="material-info">
                    <div class="row">
                        <div class="col-md-8">
                            <p><strong><i class="fas fa-tag me-2"></i> Tipo:</strong> ' . ucfirst($material['tipo']) . '</p>
                            <p><strong><i class="fas fa-user me-2"></i> Autor:</strong> ' . htmlspecialchars($material['autor'] ?? 'Não informado') . '</p>
                            <p><strong><i class="fas fa-building me-2"></i> Editora:</strong> ' . htmlspecialchars($material['editora'] ?? 'Não informada') . '</p>
                            <p><strong><i class="fas fa-calendar me-2"></i> Adicionado:</strong> ' . date('d/m/Y', strtotime($material['created_at'])) . '</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="d-flex gap-2 justify-content-end flex-wrap">
                                ' . (!empty($material['link_pdf']) ? '<a href="biblioteca.php?download=' . $material['id'] . '" class="btn btn-danger btn-link-material"><i class="fas fa-file-pdf me-2"></i> Baixar PDF</a>' : '') . '
                                ' . (!empty($material['link_video']) ? '<a href="' . $material['link_video'] . '" target="_blank" class="btn btn-info btn-link-material"><i class="fas fa-video me-2"></i> Ver Vídeo</a>' : '') . '
                                ' . (!empty($material['link']) ? '<a href="' . $material['link'] . '" target="_blank" class="btn btn-success btn-link-material"><i class="fas fa-download me-2"></i> Material Extra</a>' : '') . '
                            </div>
                        </div>
                    </div>
                </div>
                <div class="material-viewer">';
        
        if (!empty($material['link_video'])) {
            echo '<div class="video-container">
                    <iframe src="' . $material['link_video'] . '" frameborder="0" allowfullscreen></iframe>
                  </div>';
        } elseif (!empty($material['link_pdf'])) {
            echo '<iframe src="https://docs.google.com/viewer?url=' . urlencode($material['link_pdf']) . '&embedded=true" class="pdf-viewer"></iframe>';
        } elseif (!empty($material['conteudo'])) {
            echo '<div class="text-content">' . $material['conteudo'] . '</div>';
        } else {
            echo '<div class="alert alert-info text-center">
                    <i class="fas fa-info-circle fa-3x mb-3"></i>
                    <h5>Material disponível para download</h5>
                    <p>Clique nos botões acima para acessar o conteúdo.</p>
                  </div>';
        }
        
        echo '      </div>
            </div>
        </body>
        </html>';
        exit;
    }
}

// ============================================
// FUNÇÃO PARA BAIXAR MATERIAL
// ============================================
if (isset($_GET['download']) && isset($_GET['id'])) {
    $material_id = (int)$_GET['id'];
    
    $sql = "SELECT * FROM materiais_didaticos WHERE id = :id AND escola_id = :escola_id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $material_id, ':escola_id' => $escola_id]);
    $material = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($material) {
        $conn->prepare("UPDATE materiais_didaticos SET downloads = downloads + 1 WHERE id = :id")->execute([':id' => $material_id]);
        
        $link_pdf = $material['link_pdf'];
        $link = $material['link'];
        $arquivo = $material['arquivo'];
        
        if (!empty($link_pdf)) {
            header('Location: ' . $link_pdf);
            exit;
        } elseif (!empty($link)) {
            header('Location: ' . $link);
            exit;
        } elseif (!empty($arquivo) && file_exists(__DIR__ . '/../../' . $arquivo)) {
            $caminho = __DIR__ . '/../../' . $arquivo;
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($arquivo) . '"');
            header('Content-Length: ' . filesize($caminho));
            readfile($caminho);
            exit;
        } else {
            echo "<h3>Material não disponível para download</h3>";
            exit;
        }
    } else {
        echo "<h3>Material não encontrado</h3>";
        exit;
    }
}

function sanitize_filename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $filename);
    return $filename;
}

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->query($sql_ano);
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;
$ano_atual = $ano_letivo['ano'] ?? date('Y');

// ============================================
// VERIFICAR E CRIAR TABELAS NECESSÁRIAS
// ============================================

// Tabela de materiais didáticos
$check = $conn->query("SHOW TABLES LIKE 'materiais_didaticos'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE materiais_didaticos (
            id INT PRIMARY KEY AUTO_INCREMENT,
            escola_id INT NOT NULL,
            titulo VARCHAR(200) NOT NULL,
            descricao TEXT,
            tipo VARCHAR(50) DEFAULT 'material',
            categoria VARCHAR(50),
            disciplina_id INT,
            ano_letivo_id INT,
            autor VARCHAR(100),
            editora VARCHAR(100),
            arquivo VARCHAR(255),
            link VARCHAR(500),
            link_pdf VARCHAR(500),
            link_video VARCHAR(500),
            capa VARCHAR(255),
            data_publicacao DATE,
            downloads INT DEFAULT 0,
            visualizacoes INT DEFAULT 0,
            avaliacao_media DECIMAL(3,2) DEFAULT 0,
            destaque TINYINT DEFAULT 0,
            status VARCHAR(20) DEFAULT 'ativo',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (escola_id) REFERENCES escolas(id),
            FOREIGN KEY (disciplina_id) REFERENCES disciplinas(id),
            FOREIGN KEY (ano_letivo_id) REFERENCES ano_letivo(id)
        )
    ");
}

// Tabela de empréstimos
$check = $conn->query("SHOW TABLES LIKE 'emprestimo_materiais'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE emprestimo_materiais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            material_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            data_emprestimo DATE NOT NULL,
            data_devolucao_prevista DATE NOT NULL,
            data_devolucao_real DATE,
            quantidade INT DEFAULT 1,
            status VARCHAR(20) DEFAULT 'emprestado',
            observacao TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (material_id) REFERENCES materiais_didaticos(id),
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        )
    ");
}

// Tabela de favoritos
$check = $conn->query("SHOW TABLES LIKE 'favoritos_materiais'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE favoritos_materiais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            material_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_material_funcionario (material_id, funcionario_id),
            FOREIGN KEY (material_id) REFERENCES materiais_didaticos(id),
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        )
    ");
}

// Tabela de avaliações
$check = $conn->query("SHOW TABLES LIKE 'avaliacoes_materiais'");
if ($check->rowCount() == 0) {
    $conn->exec("
        CREATE TABLE avaliacoes_materiais (
            id INT PRIMARY KEY AUTO_INCREMENT,
            material_id INT NOT NULL,
            funcionario_id INT NOT NULL,
            nota INT NOT NULL CHECK (nota >= 1 AND nota <= 5),
            comentario TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_avaliacao_material (material_id, funcionario_id),
            FOREIGN KEY (material_id) REFERENCES materiais_didaticos(id),
            FOREIGN KEY (funcionario_id) REFERENCES funcionarios(id)
        )
    ");
}

// ============================================
// BUSCAR ID DO FUNCIONARIO CORRETAMENTE
// ============================================

$sql_func = "SELECT f.id 
             FROM funcionarios f 
             INNER JOIN professores p ON p.usuario_id = f.usuario_id 
             WHERE p.id = :professor_id 
             LIMIT 1";
$stmt_func = $conn->prepare($sql_func);
$stmt_func->execute([':professor_id' => $professor_id]);
$funcionario_data = $stmt_func->fetch(PDO::FETCH_ASSOC);

if ($funcionario_data) {
    $funcionario_id = $funcionario_data['id'];
} else {
    $sql_func2 = "SELECT id FROM funcionarios WHERE escola_id = :escola_id LIMIT 1";
    $stmt_func2 = $conn->prepare($sql_func2);
    $stmt_func2->execute([':escola_id' => $escola_id]);
    $funcionario_data2 = $stmt_func2->fetch(PDO::FETCH_ASSOC);
    
    if ($funcionario_data2) {
        $funcionario_id = $funcionario_data2['id'];
    } else {
        $funcionario_id = 0;
        $sql_insert = "INSERT INTO funcionarios (escola_id, nome, cargo, created_at) 
                       VALUES (:escola_id, 'Professor Temporário', 'Professor', NOW())";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->execute([':escola_id' => $escola_id]);
        $funcionario_id = $conn->lastInsertId();
    }
}

// ============================================
// PROCESSAR AÇÕES (FAVORITAR, AVALIAR, SOLICITAR EMPRÉSTIMO)
// ============================================

// Favoritar/Desfavoritar
if (isset($_GET['favoritar']) && isset($_GET['id'])) {
    $material_id = (int)$_GET['id'];
    $check = $conn->prepare("SELECT id FROM favoritos_materiais WHERE material_id = :material_id AND funcionario_id = :funcionario_id");
    $check->execute([':material_id' => $material_id, ':funcionario_id' => $funcionario_id]);
    
    if ($check->rowCount() > 0) {
        $conn->prepare("DELETE FROM favoritos_materiais WHERE material_id = :material_id AND funcionario_id = :funcionario_id")->execute([':material_id' => $material_id, ':funcionario_id' => $funcionario_id]);
        $success = "Material removido dos favoritos!";
    } else {
        $conn->prepare("INSERT INTO favoritos_materiais (material_id, funcionario_id) VALUES (:material_id, :funcionario_id)")->execute([':material_id' => $material_id, ':funcionario_id' => $funcionario_id]);
        $success = "Material adicionado aos favoritos!";
    }
}

// Processar avaliação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['avaliar'])) {
    $material_id = (int)$_POST['material_id'];
    $nota = (int)$_POST['nota'];
    $comentario = $_POST['comentario'] ?? '';
    
    if ($funcionario_id > 0) {
        $check = $conn->prepare("SELECT id FROM avaliacoes_materiais WHERE material_id = :material_id AND funcionario_id = :funcionario_id");
        $check->execute([':material_id' => $material_id, ':funcionario_id' => $funcionario_id]);
        
        if ($check->rowCount() > 0) {
            $stmt = $conn->prepare("UPDATE avaliacoes_materiais SET nota = :nota, comentario = :comentario WHERE material_id = :material_id AND funcionario_id = :funcionario_id");
        } else {
            $stmt = $conn->prepare("INSERT INTO avaliacoes_materiais (material_id, funcionario_id, nota, comentario) VALUES (:material_id, :funcionario_id, :nota, :comentario)");
        }
        $stmt->execute([
            ':material_id' => $material_id,
            ':funcionario_id' => $funcionario_id,
            ':nota' => $nota,
            ':comentario' => $comentario
        ]);
        
        $conn->prepare("UPDATE materiais_didaticos SET avaliacao_media = (SELECT AVG(nota) FROM avaliacoes_materiais WHERE material_id = :material_id) WHERE id = :material_id")
            ->execute([':material_id' => $material_id]);
        $success = "Avaliação registrada com sucesso!";
    } else {
        $error = "Erro: Funcionário não encontrado. Faça login novamente.";
    }
}

// Solicitar empréstimo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitar_emprestimo'])) {
    $material_id = (int)$_POST['material_id'];
    $data_devolucao_prevista = $_POST['data_devolucao_prevista'];
    $quantidade = (int)$_POST['quantidade'];
    $observacao = $_POST['observacao'] ?? '';
    
    if ($funcionario_id > 0) {
        $check_func = $conn->prepare("SELECT id FROM funcionarios WHERE id = :id");
        $check_func->execute([':id' => $funcionario_id]);
        
        if ($check_func->rowCount() > 0) {
            $check_material = $conn->prepare("SELECT id, titulo FROM materiais_didaticos WHERE id = :id");
            $check_material->execute([':id' => $material_id]);
            
            if ($check_material->rowCount() > 0) {
                $stmt = $conn->prepare("INSERT INTO emprestimo_materiais (material_id, funcionario_id, data_emprestimo, data_devolucao_prevista, quantidade, observacao, status) VALUES (:material_id, :funcionario_id, CURDATE(), :data_devolucao_prevista, :quantidade, :observacao, 'emprestado')");
                $result = $stmt->execute([
                    ':material_id' => $material_id,
                    ':funcionario_id' => $funcionario_id,
                    ':data_devolucao_prevista' => $data_devolucao_prevista,
                    ':quantidade' => $quantidade,
                    ':observacao' => $observacao
                ]);
                
                if ($result) {
                    $success = "✅ Solicitação de empréstimo realizada com sucesso!";
                } else {
                    $error = "❌ Erro ao registrar empréstimo. Tente novamente.";
                }
            } else {
                $error = "❌ Material não encontrado.";
            }
        } else {
            $error = "❌ Funcionário não encontrado. Faça login novamente.";
        }
    } else {
        $error = "❌ Sessão inválida. Faça login novamente.";
    }
}

// ============================================
// BUSCAR CATEGORIAS E FILTROS
// ============================================
$categorias = $conn->query("SELECT DISTINCT categoria FROM materiais_didaticos WHERE categoria IS NOT NULL AND status = 'ativo' ORDER BY categoria")->fetchAll(PDO::FETCH_ASSOC);
$tipos = $conn->query("SELECT DISTINCT tipo FROM materiais_didaticos WHERE tipo IS NOT NULL AND status = 'ativo' ORDER BY tipo")->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR MATERIAIS COM FILTROS
// ============================================
$categoria_filtro = $_GET['categoria'] ?? '';
$tipo_filtro = $_GET['tipo'] ?? '';
$busca = $_GET['busca'] ?? '';
$disciplina_filtro = (int)($_GET['disciplina'] ?? 0);
$destaques = isset($_GET['destaques']) ? true : false;

$sql_materiais = "
    SELECT m.*, 
           d.nome as disciplina_nome,
           (SELECT COUNT(*) FROM favoritos_materiais WHERE material_id = m.id) as total_favoritos,
           (SELECT COUNT(*) FROM avaliacoes_materiais WHERE material_id = m.id) as total_avaliacoes,
           (SELECT COUNT(*) FROM emprestimo_materiais WHERE material_id = m.id AND status = 'emprestado') as emprestados,
           (SELECT COUNT(*) FROM favoritos_materiais WHERE material_id = m.id AND funcionario_id = :funcionario_id) as is_favorito
    FROM materiais_didaticos m
    LEFT JOIN disciplinas d ON d.id = m.disciplina_id
    WHERE m.escola_id = :escola_id AND m.status = 'ativo'
";

$params = [':funcionario_id' => $funcionario_id, ':escola_id' => $escola_id];

if (!empty($categoria_filtro)) {
    $sql_materiais .= " AND m.categoria = :categoria";
    $params[':categoria'] = $categoria_filtro;
}
if (!empty($tipo_filtro)) {
    $sql_materiais .= " AND m.tipo = :tipo";
    $params[':tipo'] = $tipo_filtro;
}
if (!empty($busca)) {
    $sql_materiais .= " AND (m.titulo LIKE :busca OR m.descricao LIKE :busca OR m.autor LIKE :busca)";
    $params[':busca'] = "%$busca%";
}
if ($disciplina_filtro > 0) {
    $sql_materiais .= " AND m.disciplina_id = :disciplina_id";
    $params[':disciplina_id'] = $disciplina_filtro;
}
if ($destaques) {
    $sql_materiais .= " AND m.destaque = 1";
}

$sql_materiais .= " ORDER BY m.destaque DESC, m.visualizacoes DESC, m.created_at DESC LIMIT 24";

$stmt = $conn->prepare($sql_materiais);
$stmt->execute($params);
$materiais = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar materiais em destaque para o carrossel
$sql_destaques = "
    SELECT m.*, d.nome as disciplina_nome
    FROM materiais_didaticos m
    LEFT JOIN disciplinas d ON d.id = m.disciplina_id
    WHERE m.escola_id = :escola_id AND m.status = 'ativo' AND m.destaque = 1
    ORDER BY m.visualizacoes DESC
    LIMIT 6
";
$stmt_destaques = $conn->prepare($sql_destaques);
$stmt_destaques->execute([':escola_id' => $escola_id]);
$materiais_destaque = $stmt_destaques->fetchAll(PDO::FETCH_ASSOC);

// Buscar disciplinas
$sql_disciplinas = "SELECT id, nome FROM disciplinas ORDER BY nome";
$disciplinas = $conn->query($sql_disciplinas)->fetchAll(PDO::FETCH_ASSOC);

// Buscar meus empréstimos
$sql_meus_emprestimos = "
    SELECT e.*, m.titulo as material_titulo, m.tipo as material_tipo, m.capa
    FROM emprestimo_materiais e
    INNER JOIN materiais_didaticos m ON m.id = e.material_id
    WHERE e.funcionario_id = :funcionario_id
    ORDER BY e.data_emprestimo DESC
    LIMIT 10
";
$stmt_emprestimos = $conn->prepare($sql_meus_emprestimos);
$stmt_emprestimos->execute([':funcionario_id' => $funcionario_id]);
$meus_emprestimos = $stmt_emprestimos->fetchAll(PDO::FETCH_ASSOC);

// Buscar meus favoritos
$sql_meus_favoritos = "
    SELECT m.*, d.nome as disciplina_nome
    FROM favoritos_materiais f
    INNER JOIN materiais_didaticos m ON m.id = f.material_id
    LEFT JOIN disciplinas d ON d.id = m.disciplina_id
    WHERE f.funcionario_id = :funcionario_id AND m.status = 'ativo'
    ORDER BY f.created_at DESC
    LIMIT 6
";
$stmt_favoritos = $conn->prepare($sql_meus_favoritos);
$stmt_favoritos->execute([':funcionario_id' => $funcionario_id]);
$meus_favoritos = $stmt_favoritos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getTipoIcone($tipo) {
    switch ($tipo) {
        case 'livro': return '<i class="fas fa-book"></i>';
        case 'apostila': return '<i class="fas fa-file-alt"></i>';
        case 'video': return '<i class="fas fa-video"></i>';
        case 'apresentacao': return '<i class="fas fa-chalkboard"></i>';
        case 'exercicio': return '<i class="fas fa-pencil-alt"></i>';
        case 'prova': return '<i class="fas fa-file-pdf"></i>';
        default: return '<i class="fas fa-file"></i>';
    }
}

function getStatusEmprestimoBadge($status, $data_devolucao_prevista) {
    if ($status == 'devolvido') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Devolvido</span>';
    } elseif (strtotime($data_devolucao_prevista) < time()) {
        return '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle"></i> Atrasado</span>';
    } else {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Emprestado</span>';
    }
}

function getRatingStars($nota) {
    $stars = '';
    $full = floor($nota);
    $half = ($nota - $full) >= 0.5;
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $full) {
            $stars .= '<i class="fas fa-star text-warning"></i>';
        } elseif ($half && $i == $full + 1) {
            $stars .= '<i class="fas fa-star-half-alt text-warning"></i>';
        } else {
            $stars .= '<i class="far fa-star text-muted"></i>';
        }
    }
    return $stars;
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Biblioteca Virtual | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
            --orange: #fd7e14;
            --purple: #6f42c1;
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
            content: '📚';
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
        .btn-voltar {
            border-radius: 50px;
            padding: 10px 24px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border: none;
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn-voltar:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            color: white;
        }

        /* ============================================
           FILTER SIDEBAR
        ============================================ */
        .filter-sidebar {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
        }

        .filter-sidebar:hover {
            transform: translateY(-3px);
            box-shadow: var(--card-shadow-hover);
        }

        .filter-title {
            font-weight: 700;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--primary-green);
            color: var(--primary-green);
            font-size: 1.1rem;
        }

        .filter-title i {
            margin-right: 10px;
        }

        .filter-sidebar .form-label {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            margin-bottom: 8px;
        }

        .filter-sidebar .form-control,
        .filter-sidebar .form-select {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            transition: var(--transition);
        }

        .filter-sidebar .form-control:focus,
        .filter-sidebar .form-select:focus {
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 107, 62, 0.1);
        }

        /* ============================================
           MATERIAL CARD
        ============================================ */
        .material-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 25px;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
        }

        .material-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--card-shadow-hover);
        }

        .material-capa {
            height: 180px;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            position: relative;
            overflow: hidden;
        }

        .material-capa::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 150px;
            height: 150px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
        }

        .material-body {
            padding: 20px;
        }

        .material-titulo {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 10px;
            height: 45px;
            overflow: hidden;
            color: #212529;
        }

        .material-desc {
            font-size: 0.8rem;
            color: #6c757d;
            height: 60px;
            overflow: hidden;
            margin-bottom: 12px;
            line-height: 1.4;
        }

        .material-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #adb5bd;
            padding-top: 10px;
            border-top: 1px solid #e9ecef;
        }

        /* ============================================
           EMPRÉSTIMO CARD
        ============================================ */
        .emprestimo-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #fff 100%);
            border-radius: 16px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid var(--warning);
            transition: var(--transition);
        }

        .emprestimo-card:hover {
            transform: translateX(5px);
            box-shadow: var(--card-shadow);
        }

        /* ============================================
           CARROSSEL
        ============================================ */
        .carousel-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: var(--card-shadow);
        }

        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            background-color: var(--primary-green);
            border-radius: 50%;
            padding: 20px;
        }

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

        .badge-destaque {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);
            color: white;
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 10;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.2);
        }

        /* ============================================
           BOTÃO FAVORITO
        ============================================ */
        .btn-favorito {
            background: none;
            border: none;
            cursor: pointer;
            transition: var(--transition);
            font-size: 1.2rem;
        }

        .btn-favorito:hover {
            transform: scale(1.2);
        }

        /* ============================================
           RATING INPUT
        ============================================ */
        .rating-input {
            display: flex;
            flex-direction: row-reverse;
            justify-content: flex-end;
            gap: 8px;
        }

        .rating-input input {
            display: none;
        }

        .rating-input label {
            font-size: 30px;
            color: #dee2e6;
            cursor: pointer;
            transition: var(--transition);
        }

        .rating-input input:checked ~ label {
            color: #ffc107;
        }

        .rating-input label:hover,
        .rating-input label:hover ~ label {
            color: #ffc107;
            transform: scale(1.1);
        }

        /* ============================================
           MODAL
        ============================================ */
        .modal-header-custom {
            background: var(--primary-gradient);
            color: white;
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
            .material-capa {
                height: 140px;
                font-size: 36px;
            }
            
            .material-titulo {
                font-size: 0.9rem;
                height: auto;
            }
            
            .filter-sidebar {
                margin-bottom: 20px;
            }
        }

        /* ============================================
           IMPRESSÃO
        ============================================ */
        @media print {
            .no-print, .btn-voltar, .filter-sidebar, .carousel-control-prev,
            .carousel-control-next, .btn-favorito {
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
            
            .material-card {
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
                    <h2><i class="fas fa-book me-2"></i> Biblioteca Virtual</h2>
                    <p>Acesse materiais didáticos, livros, vídeos e recursos educacionais</p>
                </div>
                <div class="no-print">
                    <a href="dashboard.php" class="btn-voltar">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </a>
                </div>
            </div>
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
        
        <!-- Carrossel de Destaques -->
        <?php if (!empty($materiais_destaque)): ?>
        <div class="card mb-4 fade-in-up">
            <div class="card-header bg-primary text-white" style="background: var(--primary-gradient) !important; border-radius: 20px 20px 0 0;">
                <i class="fas fa-star me-2"></i> Materiais em Destaque
            </div>
            <div class="card-body" style="background: white; border-radius: 0 0 20px 20px;">
                <div id="carrosselDestaques" class="carousel slide" data-bs-ride="carousel">
                    <div class="carousel-inner">
                        <?php $chunks = array_chunk($materiais_destaque, 3); ?>
                        <?php foreach ($chunks as $index => $chunk): ?>
                        <div class="carousel-item <?php echo $index == 0 ? 'active' : ''; ?>">
                            <div class="row">
                                <?php foreach ($chunk as $material): ?>
                                <div class="col-md-4">
                                    <div class="material-card position-relative">
                                        <?php if ($material['destaque']): ?>
                                        <div class="badge-destaque"><i class="fas fa-star"></i> Destaque</div>
                                        <?php endif; ?>
                                        <div class="material-capa">
                                            <?php echo getTipoIcone($material['tipo']); ?>
                                        </div>
                                        <div class="material-body">
                                            <h6 class="material-titulo"><?php echo htmlspecialchars($material['titulo']); ?></h6>
                                            <p class="material-desc"><?php echo htmlspecialchars(substr($material['descricao'] ?? '', 0, 80)); ?>...</p>
                                            <div class="material-stats">
                                                <span><i class="fas fa-download"></i> <?php echo $material['downloads']; ?></span>
                                                <span><i class="fas fa-eye"></i> <?php echo $material['visualizacoes']; ?></span>
                                                <span><?php echo getRatingStars($material['avaliacao_media']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button class="carousel-control-prev" type="button" data-bs-target="#carrosselDestaques" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Anterior</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#carrosselDestaques" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Próximo</span>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Sidebar de Filtros -->
            <div class="col-md-3">
                <div class="filter-sidebar slide-in-left">
                    <div class="filter-title"><i class="fas fa-search"></i> Filtros</div>
                    <form method="GET" id="formFiltros">
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-search"></i> Pesquisar</label>
                            <input type="text" name="busca" class="form-control" placeholder="Título, autor..." value="<?php echo htmlspecialchars($busca); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-tag"></i> Categoria</label>
                            <select name="categoria" class="form-select" onchange="this.form.submit()">
                                <option value="">Todas</option>
                                <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat['categoria']); ?>" <?php echo $categoria_filtro == $cat['categoria'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['categoria']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-file-alt"></i> Tipo</label>
                            <select name="tipo" class="form-select" onchange="this.form.submit()">
                                <option value="">Todos</option>
                                <?php foreach ($tipos as $t): ?>
                                <option value="<?php echo htmlspecialchars($t['tipo']); ?>" <?php echo $tipo_filtro == $t['tipo'] ? 'selected' : ''; ?>><?php echo ucfirst(htmlspecialchars($t['tipo'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label"><i class="fas fa-book"></i> Disciplina</label>
                            <select name="disciplina" class="form-select" onchange="this.form.submit()">
                                <option value="0">Todas</option>
                                <?php foreach ($disciplinas as $disc): ?>
                                <option value="<?php echo $disc['id']; ?>" <?php echo $disciplina_filtro == $disc['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($disc['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" name="destaques" class="form-check-input" id="destaques" value="1" <?php echo $destaques ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <label class="form-check-label" for="destaques">
                                <i class="fas fa-star text-warning"></i> Apenas em destaque
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100" style="background: var(--primary-gradient); border: none; border-radius: 50px; padding: 10px;">
                            <i class="fas fa-filter"></i> Aplicar Filtros
                        </button>
                        <a href="biblioteca.php" class="btn btn-secondary w-100 mt-2" style="border-radius: 50px;">
                            <i class="fas fa-undo"></i> Limpar Filtros
                        </a>
                    </form>
                </div>
                
                <!-- Meus Empréstimos -->
                <?php if (!empty($meus_emprestimos)): ?>
                <div class="filter-sidebar mt-3 slide-in-left delay-1">
                    <div class="filter-title"><i class="fas fa-hand-holding"></i> Meus Empréstimos</div>
                    <?php foreach ($meus_emprestimos as $emp): ?>
                    <div class="emprestimo-card">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <strong><?php echo htmlspecialchars($emp['material_titulo']); ?></strong>
                            <?php echo getStatusEmprestimoBadge($emp['status'], $emp['data_devolucao_prevista']); ?>
                        </div>
                        <small><i class="fas fa-calendar-alt"></i> Empréstimo: <?php echo formatarData($emp['data_emprestimo']); ?></small><br>
                        <small><i class="fas fa-calendar-check"></i> Devolução prevista: <?php echo formatarData($emp['data_devolucao_prevista']); ?></small>
                        <?php if ($emp['data_devolucao_real']): ?>
                        <br><small><i class="fas fa-check-circle text-success"></i> Devolvido em: <?php echo formatarData($emp['data_devolucao_real']); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <!-- Meus Favoritos -->
                <?php if (!empty($meus_favoritos)): ?>
                <div class="filter-sidebar mt-3 slide-in-left delay-2">
                    <div class="filter-title"><i class="fas fa-heart text-danger"></i> Meus Favoritos</div>
                    <?php foreach ($meus_favoritos as $fav): ?>
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                        <small><?php echo htmlspecialchars(substr($fav['titulo'], 0, 30)); ?></small>
                        <a href="biblioteca.php?favoritar=1&id=<?php echo $fav['id']; ?>" class="text-danger">
                            <i class="fas fa-heart"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Grid de Materiais -->
            <div class="col-md-9">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="text-muted"><i class="fas fa-th-large me-2"></i> Materiais Disponíveis (<?php echo count($materiais); ?>)</h5>
                </div>
                
                <?php if (empty($materiais)): ?>
                <div class="alert alert-info text-center fade-in-up">
                    <i class="fas fa-info-circle fa-2x mb-2 d-block"></i>
                    <p>Nenhum material encontrado com os filtros selecionados.</p>
                </div>
                <?php else: ?>
                <div class="row">
                    <?php foreach ($materiais as $index => $material): ?>
                    <div class="col-md-6 col-lg-4 fade-in-up" style="animation-delay: <?php echo ($index % 6) * 0.05; ?>s">
                        <div class="material-card position-relative">
                            <?php if ($material['destaque']): ?>
                            <div class="badge-destaque"><i class="fas fa-star"></i> Destaque</div>
                            <?php endif; ?>
                            <div class="material-capa">
                                <?php echo getTipoIcone($material['tipo']); ?>
                            </div>
                            <div class="material-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <h6 class="material-titulo"><?php echo htmlspecialchars($material['titulo']); ?></h6>
                                    <a href="biblioteca.php?favoritar=1&id=<?php echo $material['id']; ?>" class="btn-favorito">
                                        <i class="fas fa-heart <?php echo $material['is_favorito'] ? 'text-danger' : 'text-muted'; ?>"></i>
                                    </a>
                                </div>
                                <p class="material-desc"><?php echo htmlspecialchars(substr($material['descricao'] ?? '', 0, 80)); ?>...</p>
                                <div class="mb-2">
                                    <?php echo getRatingStars($material['avaliacao_media']); ?>
                                    <small class="text-muted">(<?php echo $material['total_avaliacoes']; ?>)</small>
                                </div>
                                <div class="material-stats">
                                    <span><i class="fas fa-download"></i> <?php echo $material['downloads']; ?></span>
                                    <span><i class="fas fa-eye"></i> <?php echo $material['visualizacoes']; ?></span>
                                    <span><i class="fas fa-heart"></i> <?php echo $material['total_favoritos']; ?></span>
                                </div>
                                <hr class="my-2">
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-primary flex-grow-1" style="border-radius: 30px;" onclick="visualizarMaterial(<?php echo $material['id']; ?>)">
                                        <i class="fas fa-eye"></i> Visualizar
                                    </button>
                                    <button class="btn btn-sm btn-outline-success" style="border-radius: 30px;" data-bs-toggle="modal" data-bs-target="#modalAvaliar" onclick="setMaterialAvaliar(<?php echo $material['id']; ?>, '<?php echo addslashes($material['titulo']); ?>')">
                                        <i class="fas fa-star"></i>
                                    </button>
                                    <?php if ($material['tipo'] == 'livro' || $material['tipo'] == 'apostila'): ?>
                                    <button class="btn btn-sm btn-outline-warning" style="border-radius: 30px;" data-bs-toggle="modal" data-bs-target="#modalEmprestimo" onclick="setMaterialEmprestimo(<?php echo $material['id']; ?>, '<?php echo addslashes($material['titulo']); ?>')">
                                        <i class="fas fa-hand-holding"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal de Avaliação -->
    <div class="modal fade" id="modalAvaliar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-star me-2"></i> Avaliar Material</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="material_id" id="avaliar_material_id">
                        <p><strong id="avaliar_material_titulo"></strong></p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Sua nota *</label>
                            <div class="rating-input">
                                <input type="radio" name="nota" value="5" id="star5"><label for="star5">★</label>
                                <input type="radio" name="nota" value="4" id="star4"><label for="star4">★</label>
                                <input type="radio" name="nota" value="3" id="star3"><label for="star3">★</label>
                                <input type="radio" name="nota" value="2" id="star2"><label for="star2">★</label>
                                <input type="radio" name="nota" value="1" id="star1"><label for="star1">★</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Comentário</label>
                            <textarea name="comentario" class="form-control" rows="3" placeholder="Deixe seu comentário sobre este material..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="avaliar" class="btn btn-primary">Enviar Avaliação</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal de Empréstimo -->
    <div class="modal fade" id="modalEmprestimo" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #fd7e14 0%, #e66a00 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-hand-holding me-2"></i> Solicitar Empréstimo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="material_id" id="emprestimo_material_id">
                        <p><strong id="emprestimo_material_titulo"></strong></p>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Data de Devolução Prevista *</label>
                            <input type="date" name="data_devolucao_prevista" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Quantidade</label>
                            <input type="number" name="quantidade" class="form-control" value="1" min="1" max="5">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Observação</label>
                            <textarea name="observacao" class="form-control" rows="2" placeholder="Observações adicionais..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="solicitar_emprestimo" class="btn btn-warning">Solicitar Empréstimo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setMaterialAvaliar(id, titulo) {
            document.getElementById('avaliar_material_id').value = id;
            document.getElementById('avaliar_material_titulo').innerText = titulo;
        }
        
        function setMaterialEmprestimo(id, titulo) {
            document.getElementById('emprestimo_material_id').value = id;
            document.getElementById('emprestimo_material_titulo').innerText = titulo;
            
            let dataMinima = new Date();
            dataMinima.setDate(dataMinima.getDate() + 7);
            let dataInput = document.querySelector('#modalEmprestimo input[name="data_devolucao_prevista"]');
            dataInput.min = dataMinima.toISOString().split('T')[0];
            dataInput.value = dataMinima.toISOString().split('T')[0];
        }
        
        function visualizarMaterial(id) {
            $.ajax({
                url: 'biblioteca.php',
                method: 'GET',
                data: { get_material: 1, id: id },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        var material = data.material;
                        
                        if (material.link_video && material.link_video != '') {
                            window.open(material.link_video, '_blank');
                        } else if (material.link_pdf && material.link_pdf != '') {
                            window.open(material.link_pdf, '_blank');
                        } else if (material.link && material.link != '') {
                            window.open(material.link, '_blank');
                        } else {
                            alert('Este material não está disponível para visualização online.');
                        }
                    }
                }
            });
        }
        
        // Contar visualização
        $(document).ready(function() {
            $('.btn-outline-primary').click(function(e) {
                var card = $(this).closest('.material-card');
                var favoritoLink = card.find('.btn-favorito').attr('href');
                if (favoritoLink) {
                    var id = favoritoLink.split('=')[1];
                    if (id) {
                        $.ajax({
                            url: 'biblioteca.php',
                            method: 'POST',
                            data: { contar_visualizacao: 1, id: id }
                        });
                    }
                }
            });
            
            // Animações ao scroll
            const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                        observer.unobserve(entry.target);
                    }
                });
            }, observerOptions);
            
            document.querySelectorAll('.material-card, .filter-sidebar').forEach(el => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(30px)';
                el.style.transition = 'all 0.6s ease-out';
                observer.observe(el);
            });
        });
    </script>
</body>
</html>