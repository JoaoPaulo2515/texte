<?php
// escola/secretaria/editar_aluno.php - Editar Aluno

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo = date('Y');

// Verificar se o ID do aluno foi passado
$aluno_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($aluno_id <= 0) {
    header('Location: lista_alunos.php?erro=ID do aluno inválido');
    exit;
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.bi_data_emissao,
        e.bi_local_emissao,
        e.data_nascimento,
        e.genero,
        e.email,
        e.telefone,
        e.endereco,
        e.foto,
        e.status as aluno_status,
        e.pais_id,
        e.pais_nome,
        e.cidade_id,
        e.cidade_nome,
        e.provincia_id,
        e.provincia_nome,
        e.municipio_id,
        e.municipio_nome,
        e.comuna_id,
        e.comuna_nome,
        e.pai_nome,
        e.pai_bi,
        e.pai_telefone,
        e.pai_profissao,
        e.mae_nome,
        e.mae_bi,
        e.mae_telefone,
        e.mae_profissao,
        e.encarregado_nome,
        e.encarregado_parentesco,
        e.encarregado_bi,
        e.encarregado_telefone,
        e.encarregado_email,
        e.encarregado_endereco,
        e.ano_letivo as aluno_ano_letivo,
        e.ano_escolar,
        e.curso,
        e.nivel,
        e.classe as aluno_classe,
        e.bi_documento,
        e.certificado_documento,
        e.atestado_documento,
        e.declaracao_documento,
        e.outros_documentos,
        e.usuario_id,
        m.id as matricula_id,
        m.turma_id,
        m.turno as matricula_turno,
        m.sala as matricula_sala,
        m.classe as matricula_classe,
        m.curso as matricula_curso,
        m.nivel as matricula_nivel,
        m.ano_letivo as matricula_ano,
        t.nome as turma_nome
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE e.id = :aluno_id AND e.escola_id = :escola_id
";

$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: lista_alunos.php?erro=Aluno não encontrado');
    exit;
}

// ============================================
// BUSCAR DADOS PARA COMBOBOXES
// ============================================

// Buscar países
$paises = $conn->query("SELECT id, nome FROM paises ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
if (empty($paises)) {
    $paises = [
        ['id' => 1, 'nome' => 'Angola'],
        ['id' => 2, 'nome' => 'Portugal'],
        ['id' => 3, 'nome' => 'Brasil'],
        ['id' => 4, 'nome' => 'Cabo Verde'],
        ['id' => 5, 'nome' => 'São Tomé e Príncipe'],
        ['id' => 6, 'nome' => 'Moçambique'],
        ['id' => 7, 'nome' => 'Guiné-Bissau'],
        ['id' => 8, 'nome' => 'Timor-Leste']
    ];
}

// Buscar províncias
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
if (empty($provincias)) {
    $provincias = [
        ['id' => 1, 'nome' => 'Luanda'],
        ['id' => 2, 'nome' => 'Benguela'],
        ['id' => 3, 'nome' => 'Huíla'],
        ['id' => 4, 'nome' => 'Bié'],
        ['id' => 5, 'nome' => 'Malanje']
    ];
}

// Buscar turmas do ano letivo atual
$turmas = $conn->prepare("
    SELECT t.id, t.nome, t.ano, t.turno, t.sala, t.ano_letivo
    FROM turmas t
    WHERE t.escola_id = :escola_id AND t.ano_letivo = :ano AND t.status = 'ativa'
    ORDER BY t.nome
");
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);
$tem_turmas = !empty($turmas);

// Buscar cursos
$sql_cursos = "SELECT id, nome FROM cursos WHERE status = 1 ORDER BY nome";
$cursos = $conn->query($sql_cursos)->fetchAll(PDO::FETCH_ASSOC);
if (empty($cursos)) {
    $cursos = [
        ['id' => 1, 'nome' => 'Ciências'],
        ['id' => 2, 'nome' => 'Humanidades'],
        ['id' => 3, 'nome' => 'Informática'],
        ['id' => 4, 'nome' => 'Administração'],
        ['id' => 5, 'nome' => 'Enfermagem']
    ];
}

// Buscar níveis
$sql_niveis = "SELECT id, nome, ordem FROM niveis WHERE status = 1 ORDER BY ordem";
$niveis = $conn->query($sql_niveis)->fetchAll(PDO::FETCH_ASSOC);
if (empty($niveis)) {
    $niveis = [
        ['id' => 1, 'nome' => 'Ensino Infantil', 'ordem' => 1],
        ['id' => 2, 'nome' => 'Ensino Fundamental I', 'ordem' => 2],
        ['id' => 3, 'nome' => 'Ensino Fundamental II', 'ordem' => 3],
        ['id' => 4, 'nome' => 'Ensino Médio', 'ordem' => 4],
        ['id' => 5, 'nome' => 'Ensino Superior', 'ordem' => 5]
    ];
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function uploadArquivoAluno($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
    if (!isset($arquivo) || $arquivo['error'] != 0) return null;
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $tipos_permitidos) || $arquivo['size'] > $tamanho_maximo) return false;
    if (!is_dir($pasta)) mkdir($pasta, 0777, true);
    $nome_arquivo = time() . '_' . uniqid() . '.' . $ext;
    return move_uploaded_file($arquivo['tmp_name'], $pasta . $nome_arquivo) ? $nome_arquivo : false;
}

function salvarImagemBase64($base64, $pasta, $prefixo) {
    if (!preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) return false;
    $base64 = substr($base64, strpos($base64, ',') + 1);
    $base64 = base64_decode($base64);
    if (!is_dir($pasta)) mkdir($pasta, 0777, true);
    $ext = $type[1];
    $nome_arquivo = $prefixo . '_' . time() . '_' . uniqid() . '.' . $ext;
    file_put_contents($pasta . $nome_arquivo, $base64);
    return $nome_arquivo;
}

function formatarDataInput($data) {
    if (empty($data)) return '';
    return date('Y-m-d', strtotime($data));
}

// ============================================
// PROCESSAR AJAX
// ============================================

if (isset($_POST['acao']) && $_POST['acao'] == 'salvar_imagem_scanner') {
    $imagem_base64 = $_POST['imagem'] ?? '';
    $tipo_documento = $_POST['tipo_documento'] ?? '';
    if ($imagem_base64 && $tipo_documento) {
        $pasta_docs = __DIR__ . '/../../uploads/alunos/documentos/';
        $nome_arquivo = salvarImagemBase64($imagem_base64, $pasta_docs, $tipo_documento);
        echo json_encode($nome_arquivo ? ['success' => true, 'arquivo' => $nome_arquivo] : ['success' => false, 'error' => 'Erro ao salvar']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_turma_completa') {
    $turma_id = $_GET['turma_id'] ?? 0;
    if ($turma_id) {
        $stmt = $conn->prepare("SELECT id, nome, ano, turno, sala, CASE WHEN turno = 'manha' THEN 'Manhã' WHEN turno = 'tarde' THEN 'Tarde' WHEN turno = 'noite' THEN 'Noite' ELSE turno END AS turno_formatado FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $turma_id]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($turma ? ['success' => true, 'turno' => $turma['turno_formatado'], 'sala' => $turma['sala'] ?? 'Não definida', 'classe' => $turma['ano'] ?? '', 'ano' => $turma['ano']] : ['success' => false]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_pais') {
    $novo_pais = $_POST['novo_pais'] ?? '';
    if ($novo_pais) {
        $stmt = $conn->prepare("INSERT IGNORE INTO paises (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $novo_pais]);
        echo json_encode(['success' => true, 'pais' => $novo_pais]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_cidade') {
    $nova_cidade = $_POST['nova_cidade'] ?? '';
    $pais_id = $_POST['pais_id'] ?? 0;
    if ($nova_cidade && $pais_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO cidades (nome, pais_id) VALUES (:nome, :pais_id)");
        $stmt->execute([':nome' => $nova_cidade, ':pais_id' => $pais_id]);
        echo json_encode(['success' => true, 'cidade' => $nova_cidade]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_provincia') {
    $nova_provincia = $_POST['nova_provincia'] ?? '';
    if ($nova_provincia) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_provincias (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $nova_provincia]);
        echo json_encode(['success' => true, 'provincia' => $nova_provincia]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_municipio') {
    $novo_municipio = $_POST['novo_municipio'] ?? '';
    $provincia_id = $_POST['provincia_id'] ?? 0;
    if ($novo_municipio && $provincia_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_municipios (nome, provincia_id) VALUES (:nome, :provincia_id)");
        $stmt->execute([':nome' => $novo_municipio, ':provincia_id' => $provincia_id]);
        echo json_encode(['success' => true, 'municipio' => $novo_municipio]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_comuna') {
    $nova_comuna = $_POST['nova_comuna'] ?? '';
    $municipio_id = $_POST['municipio_id'] ?? 0;
    if ($nova_comuna && $municipio_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_comunas (nome, municipio_id) VALUES (:nome, :municipio_id)");
        $stmt->execute([':nome' => $nova_comuna, ':municipio_id' => $municipio_id]);
        echo json_encode(['success' => true, 'comuna' => $nova_comuna]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia_id = $_GET['provincia_id'] ?? 0;
    if ($provincia_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
        $stmt->execute([':provincia_id' => $provincia_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio_id = $_GET['municipio_id'] ?? 0;
    if ($municipio_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
        $stmt->execute([':municipio_id' => $municipio_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_cidades') {
    $pais_id = $_GET['pais_id'] ?? 0;
    if ($pais_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM cidades WHERE pais_id = :pais_id ORDER BY nome");
        $stmt->execute([':pais_id' => $pais_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

// ============================================
// PROCESSAR FORMULÁRIO
// ============================================

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_aluno'])) {
    try {
        $conn->beginTransaction();
        
        // Dados Pessoais
        $nome_completo = $_POST['nome_completo'] ?? '';
        $data_nascimento = $_POST['data_nascimento'] ?? '';
        $genero = $_POST['genero'] ?? '';
        $bi_numero = $_POST['bi_numero'] ?? '';
        $bi_data_emissao = $_POST['bi_data_emissao'] ?? '';
        $bi_local_emissao = $_POST['bi_local_emissao'] ?? '';
        $pais_id = $_POST['pais_id'] ?? 1;
        $cidade_id = $_POST['cidade_id'] ?? null;
        $provincia_id = $_POST['provincia_id'] ?? null;
        $municipio_id = $_POST['municipio_id'] ?? null;
        $comuna_id = $_POST['comuna_id'] ?? null;
        $endereco = $_POST['endereco'] ?? '';
        $telefone = $_POST['telefone'] ?? '';
        $email = $_POST['email'] ?? '';
        $status = $_POST['status'] ?? 'ativo';
        
        // Dados dos Pais
        $pai_nome = $_POST['pai_nome'] ?? '';
        $pai_bi = $_POST['pai_bi'] ?? '';
        $pai_telefone = $_POST['pai_telefone'] ?? '';
        $pai_profissao = $_POST['pai_profissao'] ?? '';
        $mae_nome = $_POST['mae_nome'] ?? '';
        $mae_bi = $_POST['mae_bi'] ?? '';
        $mae_telefone = $_POST['mae_telefone'] ?? '';
        $mae_profissao = $_POST['mae_profissao'] ?? '';
        
        // Encarregado
        $encarregado_nome = $_POST['encarregado_nome'] ?? '';
        $encarregado_parentesco = $_POST['encarregado_parentesco'] ?? '';
        $encarregado_bi = $_POST['encarregado_bi'] ?? '';
        $encarregado_telefone = $_POST['encarregado_telefone'] ?? '';
        $encarregado_email = $_POST['encarregado_email'] ?? '';
        $encarregado_endereco = $_POST['encarregado_endereco'] ?? '';
        
        // Dados Académicos
        $turma_id = $_POST['turma_id'] ?? '';
        $ano_letivo_selecionado = $_POST['ano_letivo'] ?? date('Y');
        $curso = $_POST['curso'] ?? '';
        $nivel = $_POST['nivel'] ?? '';
        $classe = $_POST['classe'] ?? '';
        
        // Upload da Foto
        $foto = $aluno['foto'];
        $foto_capturada = $_POST['foto_capturada'] ?? '';
        
        if (!empty($foto_capturada)) {
            $foto_dir = __DIR__ . '/../../uploads/alunos/fotos/';
            if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
            $foto = 'foto_' . time() . '_' . uniqid() . '.png';
            file_put_contents($foto_dir . $foto, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $foto_capturada)));
        } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
            $foto = uploadArquivoAluno($_FILES['foto'], __DIR__ . '/../../uploads/alunos/fotos/', ['jpg','jpeg','png','gif','webp'], 2097152);
        }
        
        // Upload de Documentos
        $upload_dir_docs = __DIR__ . '/../../uploads/alunos/documentos/';
        
        $bi_documento = $_POST['bi_documento_scanner'] ?? $aluno['bi_documento'];
        if (!$bi_documento && isset($_FILES['bi_documento']) && $_FILES['bi_documento']['error'] == 0) {
            $bi_documento = uploadArquivoAluno($_FILES['bi_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
        }
        
        $certificado_documento = $_POST['certificado_documento_scanner'] ?? $aluno['certificado_documento'];
        if (!$certificado_documento && isset($_FILES['certificado_documento']) && $_FILES['certificado_documento']['error'] == 0) {
            $certificado_documento = uploadArquivoAluno($_FILES['certificado_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
        }
        
        $atestado_documento = $_POST['atestado_documento_scanner'] ?? $aluno['atestado_documento'];
        if (!$atestado_documento && isset($_FILES['atestado_documento']) && $_FILES['atestado_documento']['error'] == 0) {
            $atestado_documento = uploadArquivoAluno($_FILES['atestado_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
        }
        
        $declaracao_documento = $_POST['declaracao_documento_scanner'] ?? $aluno['declaracao_documento'];
        if (!$declaracao_documento && isset($_FILES['declaracao_documento']) && $_FILES['declaracao_documento']['error'] == 0) {
            $declaracao_documento = uploadArquivoAluno($_FILES['declaracao_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
        }
        
        $outros_documentos = [];
        if ($aluno['outros_documentos']) {
            $outros_documentos = json_decode($aluno['outros_documentos'], true) ?: [];
        }
        for ($i = 1; $i <= 3; $i++) {
            $doc = uploadArquivoAluno($_FILES["outro_documento_$i"] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf','doc','docx'], 5242880);
            if ($doc) $outros_documentos[] = $doc;
        }
        $outros_documentos_json = json_encode($outros_documentos);
        
        // Buscar dados da turma
        $turma_dados = null;
        $ano_escolar = '';
        $turno = '';
        $sala = '';
        
        if ($turma_id) {
            $stmt = $conn->prepare("SELECT ano, turno, sala FROM turmas WHERE id = :id");
            $stmt->execute([':id' => $turma_id]);
            $turma_dados = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($turma_dados) {
                $ano_escolar = $turma_dados['ano'];
                $turno = $turma_dados['turno'] == 'manha' ? 'Manhã' : ($turma_dados['turno'] == 'tarde' ? 'Tarde' : 'Noite');
                $sala = $turma_dados['sala'] ?? '';
            }
        }
        
        // Buscar nomes para IDs
        $pais_nome = $cidade_nome = $provincia_nome = $municipio_nome = $comuna_nome = '';
        if ($pais_id) {
            $stmt = $conn->prepare("SELECT nome FROM paises WHERE id = :id");
            $stmt->execute([':id' => $pais_id]);
            $pais_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
        }
        if ($cidade_id) {
            $stmt = $conn->prepare("SELECT nome FROM cidades WHERE id = :id");
            $stmt->execute([':id' => $cidade_id]);
            $cidade_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
        }
        if ($provincia_id) {
            $stmt = $conn->prepare("SELECT nome FROM angola_provincias WHERE id = :id");
            $stmt->execute([':id' => $provincia_id]);
            $provincia_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
        }
        if ($municipio_id) {
            $stmt = $conn->prepare("SELECT nome FROM angola_municipios WHERE id = :id");
            $stmt->execute([':id' => $municipio_id]);
            $municipio_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
        }
        if ($comuna_id) {
            $stmt = $conn->prepare("SELECT nome FROM angola_comunas WHERE id = :id");
            $stmt->execute([':id' => $comuna_id]);
            $comuna_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
        }
        
        // 1. ATUALIZAR TABELA USUARIOS
        if (!empty($aluno['usuario_id'])) {
            $stmt_user = $conn->prepare("UPDATE usuarios SET nome = :nome, email = :email, telefone = :telefone, updated_at = NOW() WHERE id = :usuario_id AND escola_id = :escola_id");
            $stmt_user->execute([
                ':nome' => $nome_completo,
                ':email' => $email ?: $aluno['matricula'] . '@aluno.sige.ao',
                ':telefone' => $telefone,
                ':usuario_id' => $aluno['usuario_id'],
                ':escola_id' => $escola_id
            ]);
        }
        
        // 2. ATUALIZAR TABELA ESTUDANTES
        $stmt_update = $conn->prepare("
            UPDATE estudantes SET 
                nome = :nome, data_nascimento = :data_nascimento, genero = :genero,
                bi = :bi, bi_data_emissao = :bi_data_emissao, bi_local_emissao = :bi_local_emissao,
                pais_id = :pais_id, pais_nome = :pais_nome, cidade_id = :cidade_id, cidade_nome = :cidade_nome,
                provincia_id = :provincia_id, provincia_nome = :provincia_nome,
                municipio_id = :municipio_id, municipio_nome = :municipio_nome,
                comuna_id = :comuna_id, comuna_nome = :comuna_nome,
                endereco = :endereco, telefone = :telefone, email = :email, status = :status,
                pai_nome = :pai_nome, pai_bi = :pai_bi, pai_telefone = :pai_telefone, pai_profissao = :pai_profissao,
                mae_nome = :mae_nome, mae_bi = :mae_bi, mae_telefone = :mae_telefone, mae_profissao = :mae_profissao,
                encarregado_nome = :encarregado_nome, encarregado_parentesco = :encarregado_parentesco,
                encarregado_bi = :encarregado_bi, encarregado_telefone = :encarregado_telefone,
                encarregado_email = :encarregado_email, encarregado_endereco = :encarregado_endereco,
                ano_letivo = :ano_letivo, ano_escolar = :ano_escolar, curso = :curso, nivel = :nivel, classe = :classe,
                foto = :foto, bi_documento = :bi_documento, certificado_documento = :certificado_documento,
                atestado_documento = :atestado_documento, declaracao_documento = :declaracao_documento,
                outros_documentos = :outros_documentos, updated_at = NOW()
            WHERE id = :id AND escola_id = :escola_id
        ");
        
        $stmt_update->execute([
            ':id' => $aluno_id, ':escola_id' => $escola_id, ':nome' => $nome_completo,
            ':data_nascimento' => $data_nascimento ?: null, ':genero' => $genero ?: null,
            ':bi' => $bi_numero ?: null, ':bi_data_emissao' => $bi_data_emissao ?: null,
            ':bi_local_emissao' => $bi_local_emissao ?: null, ':pais_id' => $pais_id ?: null,
            ':pais_nome' => $pais_nome ?: null, ':cidade_id' => $cidade_id ?: null,
            ':cidade_nome' => $cidade_nome ?: null, ':provincia_id' => $provincia_id ?: null,
            ':provincia_nome' => $provincia_nome ?: null, ':municipio_id' => $municipio_id ?: null,
            ':municipio_nome' => $municipio_nome ?: null, ':comuna_id' => $comuna_id ?: null,
            ':comuna_nome' => $comuna_nome ?: null, ':endereco' => $endereco ?: null,
            ':telefone' => $telefone ?: null, ':email' => $email ?: null, ':status' => $status,
            ':pai_nome' => $pai_nome ?: null, ':pai_bi' => $pai_bi ?: null,
            ':pai_telefone' => $pai_telefone ?: null, ':pai_profissao' => $pai_profissao ?: null,
            ':mae_nome' => $mae_nome ?: null, ':mae_bi' => $mae_bi ?: null,
            ':mae_telefone' => $mae_telefone ?: null, ':mae_profissao' => $mae_profissao ?: null,
            ':encarregado_nome' => $encarregado_nome ?: null,
            ':encarregado_parentesco' => $encarregado_parentesco ?: null,
            ':encarregado_bi' => $encarregado_bi ?: null,
            ':encarregado_telefone' => $encarregado_telefone ?: null,
            ':encarregado_email' => $encarregado_email ?: null,
            ':encarregado_endereco' => $encarregado_endereco ?: null,
            ':ano_letivo' => $ano_letivo_selecionado, ':ano_escolar' => $ano_escolar ?: null,
            ':curso' => $curso ?: null, ':nivel' => $nivel ?: null,
            ':classe' => $classe ?: $ano_escolar, ':foto' => $foto,
            ':bi_documento' => $bi_documento, ':certificado_documento' => $certificado_documento,
            ':atestado_documento' => $atestado_documento, ':declaracao_documento' => $declaracao_documento,
            ':outros_documentos' => $outros_documentos_json
        ]);
        
        // 3. ATUALIZAR MATRÍCULA ATIVA
        if ($turma_id) {
            if ($aluno['matricula_id']) {
                $stmt_mat = $conn->prepare("UPDATE matriculas SET turma_id = :turma_id, turno = :turno, sala = :sala, classe = :classe, curso = :curso, nivel = :nivel, ano_letivo = :ano_letivo, updated_at = NOW() WHERE id = :matricula_id");
                $stmt_mat->execute([
                    ':turma_id' => $turma_id, ':turno' => $turno, ':sala' => $sala,
                    ':classe' => $classe ?: $ano_escolar, ':curso' => $curso ?: null,
                    ':nivel' => $nivel ?: null, ':ano_letivo' => $ano_letivo_selecionado,
                    ':matricula_id' => $aluno['matricula_id']
                ]);
            } else {
                $stmt_mat = $conn->prepare("INSERT INTO matriculas (estudante_id, turma_id, turno, sala, classe, curso, nivel, ano_letivo, numero_matricula, status, data_matricula, created_at) VALUES (:estudante_id, :turma_id, :turno, :sala, :classe, :curso, :nivel, :ano_letivo, :numero_matricula, 'ativa', CURDATE(), NOW())");
                $stmt_mat->execute([
                    ':estudante_id' => $aluno_id, ':turma_id' => $turma_id,
                    ':turno' => $turno, ':sala' => $sala, ':classe' => $classe ?: $ano_escolar,
                    ':curso' => $curso ?: null, ':nivel' => $nivel ?: null,
                    ':ano_letivo' => $ano_letivo_selecionado, ':numero_matricula' => $aluno['matricula']
                ]);
            }
        }
        
        $conn->commit();
        $success = "Dados do aluno atualizados com sucesso!";
        
        // Recarregar dados
        $stmt_aluno->execute([':aluno_id' => $aluno_id, ':escola_id' => $escola_id]);
        $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Editar Aluno | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/opencv.js@1.2.1/opencv.js"></script>
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
        .nav-item.has-submenu > .nav-link:after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 25px; transition: transform 0.3s; }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .preview-img { width: 150px; height: 150px; object-fit: cover; border-radius: 10px; border: 2px solid #006B3E; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .webcam-container { position: relative; }
        #video { width: 100%; max-width: 400px; border-radius: 10px; border: 2px solid #006B3E; background: #000; }
        .document-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; }
        
        .scanner-container { position: relative; background: #000; border-radius: 15px; overflow: hidden; }
        #scannerVideo { width: 100%; max-height: 500px; object-fit: cover; background: #000; transform: scaleX(-1); }
        .scanner-overlay { position: absolute; top: 0; left: 0; right: 0; bottom: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
        .scanner-frame { width: 85%; height: 70%; border: 2px solid rgba(0, 255, 100, 0.8); border-radius: 15px; box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5); animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { border-color: rgba(0, 255, 100, 0.6); } 50% { border-color: rgba(0, 255, 100, 1); } 100% { border-color: rgba(0, 255, 100, 0.6); } }
        .scanner-controls { position: absolute; bottom: 20px; left: 0; right: 0; text-align: center; z-index: 10; }
        .btn-scanner { background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(10px); color: white; border: none; padding: 12px 30px; border-radius: 30px; margin: 0 10px; }
        .btn-scanner-primary { background: #006B3E; color: white; }
        .detection-indicator { position: absolute; top: 10px; right: 10px; background: rgba(0,0,0,0.6); color: #00ff64; padding: 5px 10px; border-radius: 20px; font-size: 0.7em; z-index: 20; }
        .btn-capture-doc { background: #006B3E; color: white; border: none; padding: 5px 12px; border-radius: 20px; font-size: 0.8em; }
        .btn-capture-doc:hover { background: #004d2d; }
        .document-badge {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            font-size: 0.85em;
        }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-edit"></i> Editar Aluno</h2>
            <div>
                <a href="ver_aluno.php?id=<?php echo $aluno_id; ?>" class="btn btn-info"><i class="fas fa-eye"></i> Ver Perfil</a>
                <a href="lista_alunos.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="alunoTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">Dados Pessoais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#filiacao">Filiação</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#encarregado">Encarregado</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#academicos">Dados Académicos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#documentos">Documentos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#foto">Foto</button></li>
                </ul>
            </div>
            <div class="card-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" id="formAluno">
                    <input type="hidden" name="editar_aluno" value="1">
                    <input type="hidden" name="bi_documento_scanner" id="bi_documento_scanner" value="<?php echo $aluno['bi_documento']; ?>">
                    <input type="hidden" name="certificado_documento_scanner" id="certificado_documento_scanner" value="<?php echo $aluno['certificado_documento']; ?>">
                    <input type="hidden" name="atestado_documento_scanner" id="atestado_documento_scanner" value="<?php echo $aluno['atestado_documento']; ?>">
                    <input type="hidden" name="declaracao_documento_scanner" id="declaracao_documento_scanner" value="<?php echo $aluno['declaracao_documento']; ?>">
                    
                    <div class="tab-content">
                        <!-- Dados Pessoais -->
                        <div class="tab-pane fade show active" id="dadosPessoais">
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label class="required">Nome Completo</label><input type="text" name="nome_completo" class="form-control" value="<?php echo htmlspecialchars($aluno['nome'] ?? ''); ?>" required></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Data Nascimento</label><input type="date" name="data_nascimento" class="form-control" value="<?php echo formatarDataInput($aluno['data_nascimento'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Género</label><select name="genero" class="form-control"><option value="">Selecione...</option><option value="M" <?php echo ($aluno['genero'] ?? '') == 'M' ? 'selected' : ''; ?>>Masculino</option><option value="F" <?php echo ($aluno['genero'] ?? '') == 'F' ? 'selected' : ''; ?>>Feminino</option></select></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-4"><div class="mb-3"><label>Nº do BI</label><input type="text" name="bi_numero" class="form-control" value="<?php echo htmlspecialchars($aluno['bi'] ?? ''); ?>"></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>Data Emissão</label><input type="date" name="bi_data_emissao" class="form-control" value="<?php echo formatarDataInput($aluno['bi_data_emissao'] ?? ''); ?>"></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>Local Emissão</label><input type="text" name="bi_local_emissao" class="form-control" value="<?php echo htmlspecialchars($aluno['bi_local_emissao'] ?? ''); ?>"></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>País</label><div class="input-group"><select name="pais_id" id="pais_id" class="form-control"><option value="">Selecione...</option><?php foreach($paises as $p): ?><option value="<?php echo $p['id']; ?>" <?php echo ($aluno['pais_id'] ?? '') == $p['id'] ? 'selected' : ''; ?>><?php echo $p['nome']; ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPais"><i class="fas fa-plus"></i></button></div></div></div>
                                <div class="col-md-6"><div class="mb-3"><label>Cidade</label><div class="input-group"><select name="cidade_id" id="cidade_id" class="form-control" disabled><option value="">Selecione país primeiro</option></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaCidade"><i class="fas fa-plus"></i></button></div></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-4"><div class="mb-3"><label>Província</label><div class="input-group"><select name="provincia_id" id="provincia_id" class="form-control"><option value="">Selecione...</option><?php foreach($provincias as $p): ?><option value="<?php echo $p['id']; ?>" <?php echo ($aluno['provincia_id'] ?? '') == $p['id'] ? 'selected' : ''; ?>><?php echo $p['nome']; ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaProvincia"><i class="fas fa-plus"></i></button></div></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>Município</label><div class="input-group"><select name="municipio_id" id="municipio_id" class="form-control" disabled><option value="">Selecione província primeiro</option></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoMunicipio"><i class="fas fa-plus"></i></button></div></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>Comuna</label><div class="input-group"><select name="comuna_id" id="comuna_id" class="form-control" disabled><option value="">Selecione município primeiro</option></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaComuna"><i class="fas fa-plus"></i></button></div></div></div>
                            </div>
                            <div class="mb-3"><label>Endereço</label><textarea name="endereco" class="form-control" rows="2"><?php echo htmlspecialchars($aluno['endereco'] ?? ''); ?></textarea></div>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Telefone</label><input type="text" name="telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['telefone'] ?? ''); ?>"></div></div>
                                <div class="col-md-6"><div class="mb-3"><label>E-mail</label><input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($aluno['email'] ?? ''); ?>"></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Status</label><select name="status" class="form-control"><option value="ativo" <?php echo ($aluno['aluno_status'] ?? '') == 'ativo' ? 'selected' : ''; ?>>Ativo</option><option value="inativo" <?php echo ($aluno['aluno_status'] ?? '') == 'inativo' ? 'selected' : ''; ?>>Inativo</option><option value="transferido" <?php echo ($aluno['aluno_status'] ?? '') == 'transferido' ? 'selected' : ''; ?>>Transferido</option><option value="concluido" <?php echo ($aluno['aluno_status'] ?? '') == 'concluido' ? 'selected' : ''; ?>>Concluído</option></select></div></div>
                            </div>
                        </div>
                        
                        <!-- Filiação -->
                        <div class="tab-pane fade" id="filiacao">
                            <h5>Dados do Pai</h5>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome</label><input type="text" name="pai_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_nome'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>BI</label><input type="text" name="pai_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_bi'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Telefone</label><input type="text" name="pai_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_telefone'] ?? ''); ?>"></div></div>
                                <div class="col-md-12"><div class="mb-3"><label>Profissão</label><input type="text" name="pai_profissao" class="form-control" value="<?php echo htmlspecialchars($aluno['pai_profissao'] ?? ''); ?>"></div></div>
                            </div>
                            <h5>Dados da Mãe</h5>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome</label><input type="text" name="mae_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_nome'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>BI</label><input type="text" name="mae_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_bi'] ?? ''); ?>"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Telefone</label><input type="text" name="mae_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_telefone'] ?? ''); ?>"></div></div>
                                <div class="col-md-12"><div class="mb-3"><label>Profissão</label><input type="text" name="mae_profissao" class="form-control" value="<?php echo htmlspecialchars($aluno['mae_profissao'] ?? ''); ?>"></div></div>
                            </div>
                        </div>
                        
                        <!-- Encarregado -->
                        <div class="tab-pane fade" id="encarregado">
                            <div class="alert alert-info"><i class="fas fa-info-circle"></i> Se o encarregado for diferente dos pais, preencha abaixo.</div>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome</label><input type="text" name="encarregado_nome" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_nome'] ?? ''); ?>"></div></div>
                                <div class="col-md-6"><div class="mb-3"><label>Parentesco</label><select name="encarregado_parentesco" class="form-control"><option value="">Selecione</option><option value="Pai" <?php echo ($aluno['encarregado_parentesco'] ?? '') == 'Pai' ? 'selected' : ''; ?>>Pai</option><option value="Mãe" <?php echo ($aluno['encarregado_parentesco'] ?? '') == 'Mãe' ? 'selected' : ''; ?>>Mãe</option><option value="Tio" <?php echo ($aluno['encarregado_parentesco'] ?? '') == 'Tio' ? 'selected' : ''; ?>>Tio</option><option value="Avô" <?php echo ($aluno['encarregado_parentesco'] ?? '') == 'Avô' ? 'selected' : ''; ?>>Avô</option></select></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-4"><div class="mb-3"><label>BI</label><input type="text" name="encarregado_bi" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_bi'] ?? ''); ?>"></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>Telefone</label><input type="text" name="encarregado_telefone" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_telefone'] ?? ''); ?>"></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>E-mail</label><input type="email" name="encarregado_email" class="form-control" value="<?php echo htmlspecialchars($aluno['encarregado_email'] ?? ''); ?>"></div></div>
                            </div>
                            <div class="mb-3"><label>Endereço</label><textarea name="encarregado_endereco" class="form-control" rows="2"><?php echo htmlspecialchars($aluno['encarregado_endereco'] ?? ''); ?></textarea></div>
                        </div>
                        
                        <!-- Dados Académicos -->
                        <div class="tab-pane fade" id="academicos">
                            <?php if ($tem_turmas): ?>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="required">Ano Letivo</label>
                                        <select name="ano_letivo" class="form-control" required>
                                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo ($aluno['aluno_ano_letivo'] ?? date('Y')) == $i ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Curso</label>
                                        <select name="curso" id="select_curso" class="form-control">
                                            <option value="">Selecione o curso...</option>
                                            <?php foreach ($cursos as $curso_item): ?>
                                            <option value="<?php echo htmlspecialchars($curso_item['nome']); ?>" <?php echo ($aluno['curso'] ?? '') == $curso_item['nome'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($curso_item['nome']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Classe / Ano</label>
                                        <input type="text" name="ano_escolar" id="ano_escolar" class="form-control" readonly value="<?php echo htmlspecialchars($aluno['ano_escolar'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label class="required">Turma</label>
                                        <select name="turma_id" id="turma_id" class="form-control" required>
                                            <option value="">Selecione...</option>
                                            <?php foreach ($turmas as $t): ?>
                                            <option value="<?php echo $t['id']; ?>" <?php echo ($aluno['turma_id'] ?? '') == $t['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['nome']); ?> (<?php echo $t['ano']; ?>º Ano - <?php echo ucfirst($t['turno']); ?>)
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nível de Ensino</label>
                                        <select name="nivel" id="select_nivel" class="form-control">
                                            <option value="">Selecione o nível...</option>
                                            <?php foreach ($niveis as $nivel_item): ?>
                                            <option value="<?php echo htmlspecialchars($nivel_item['nome']); ?>" <?php echo ($aluno['nivel'] ?? '') == $nivel_item['nome'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($nivel_item['nome']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Sala</label>
                                        <input type="text" name="sala" id="sala" class="form-control" readonly value="<?php echo htmlspecialchars($aluno['sala'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Classe (texto livre)</label>
                                        <input type="text" name="classe" class="form-control" value="<?php echo htmlspecialchars($aluno['classe'] ?? $aluno['ano_escolar'] ?? ''); ?>" placeholder="Ex: 5º Ano">
                                        <small class="text-muted">Opcional - preenchido automaticamente ao selecionar turma</small>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle"></i>
                                <strong>Nenhuma turma cadastrada para o ano letivo <?php echo $ano_letivo; ?>!</strong><br>
                                <a href="../turmas/cadastrar.php" class="btn btn-sm btn-warning mt-2">Cadastrar Turma</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Documentos -->
                        <div class="tab-pane fade" id="documentos">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                                            <h6>BI / Documento de Identificação</h6>
                                            <input type="file" name="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                                            <div id="bi_preview" class="mt-2">
                                                <?php if (!empty($aluno['bi_documento'])): ?>
                                                    <div class="document-badge">
                                                        <i class="fas fa-check-circle text-success"></i>
                                                        Documento digitalizado com sucesso!
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-capture-doc mt-2" onclick="abrirScanner('bi_documento_scanner', 'bi_preview')">
                                                <i class="fas fa-camera"></i> Digitalizar Documento
                                            </button>
                                            <small class="text-muted d-block">O scanner detecta automaticamente o documento e remove o fundo</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-certificate fa-3x text-success mb-2"></i>
                                            <h6>Certificado de Conclusão / Histórico</h6>
                                            <input type="file" name="certificado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'certificado_preview')">
                                            <div id="certificado_preview" class="mt-2">
                                                <?php if (!empty($aluno['certificado_documento'])): ?>
                                                    <div class="document-badge">
                                                        <i class="fas fa-check-circle text-success"></i>
                                                        Documento digitalizado com sucesso!
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-capture-doc mt-2" onclick="abrirScanner('certificado_documento_scanner', 'certificado_preview')">
                                                <i class="fas fa-camera"></i> Digitalizar Documento
                                            </button>
                                            <small class="text-muted d-block">O scanner detecta automaticamente o documento e remove o fundo</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-stethoscope fa-3x text-info mb-2"></i>
                                            <h6>Atestado Médico</h6>
                                            <input type="file" name="atestado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'atestado_preview')">
                                            <div id="atestado_preview" class="mt-2">
                                                <?php if (!empty($aluno['atestado_documento'])): ?>
                                                    <div class="document-badge">
                                                        <i class="fas fa-check-circle text-success"></i>
                                                        Documento digitalizado com sucesso!
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-capture-doc mt-2" onclick="abrirScanner('atestado_documento_scanner', 'atestado_preview')">
                                                <i class="fas fa-camera"></i> Digitalizar Documento
                                            </button>
                                            <small class="text-muted d-block">O scanner detecta automaticamente o documento e remove o fundo</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                                            <h6>Declaração de Matrícula</h6>
                                            <input type="file" name="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'declaracao_preview')">
                                            <div id="declaracao_preview" class="mt-2">
                                                <?php if (!empty($aluno['declaracao_documento'])): ?>
                                                    <div class="document-badge">
                                                        <i class="fas fa-check-circle text-success"></i>
                                                        Documento digitalizado com sucesso!
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-capture-doc mt-2" onclick="abrirScanner('declaracao_documento_scanner', 'declaracao_preview')">
                                                <i class="fas fa-camera"></i> Digitalizar Documento
                                            </button>
                                            <small class="text-muted d-block">O scanner detecta automaticamente o documento e remove o fundo</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <h6 class="mt-3">Outros Documentos</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <input type="file" name="outro_documento_1" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro1_preview')">
                                    <div id="outro1_preview" class="mt-1"></div>
                                </div>
                                <div class="col-md-4">
                                    <input type="file" name="outro_documento_2" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro2_preview')">
                                    <div id="outro2_preview" class="mt-1"></div>
                                </div>
                                <div class="col-md-4">
                                    <input type="file" name="outro_documento_3" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro3_preview')">
                                    <div id="outro3_preview" class="mt-1"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Foto -->
                        <div class="tab-pane fade" id="foto">
                            <div class="row">
                                <div class="col-md-6 text-center">
                                    <h5>Foto Atual</h5>
                                    <?php if (!empty($aluno['foto']) && file_exists('../../uploads/alunos/fotos/' . $aluno['foto'])): ?>
                                        <img src="../../uploads/alunos/fotos/<?php echo $aluno['foto']; ?>" class="preview-img">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="preview-img">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6 text-center">
                                    <h5>Nova Foto</h5>
                                    <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*" onchange="previewFoto(this)">
                                    <div><img id="fotoPreview" src="../../assets/images/avatar-padrao.png" class="preview-img"></div>
                                    <h5 class="mt-3">Capturar Webcam</h5>
                                    <div class="webcam-container">
                                        <video id="video" width="100%" autoplay></video>
                                        <button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar</button>
                                        <button type="button" id="recarregarCamBtn" class="btn btn-secondary btn-sm mt-2">Recarregar</button>
                                        <canvas id="canvas" style="display:none;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5"><i class="fas fa-save"></i> Salvar Alterações</button>
                        <a href="ver_aluno.php?id=<?php echo $aluno_id; ?>" class="btn btn-secondary btn-lg px-5 ms-2"><i class="fas fa-times"></i> Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Scanner -->
    <div class="modal fade scanner-modal" id="modalScanner" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-camera"></i> Digitalizar Documento</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0">
                    <div class="scanner-container">
                        <video id="scannerVideo" autoplay playsinline></video>
                        <div class="scanner-overlay">
                            <div class="scanner-frame"></div>
                        </div>
                        <div class="detection-indicator" id="detectionIndicator">
                            <i class="fas fa-search"></i> Aguardando documento...
                        </div>
                        <div class="scanner-controls">
                            <button type="button" id="recarregarCamScannerBtn" class="btn-scanner">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <button type="button" id="capturarScannerBtn" class="btn-scanner btn-scanner-primary">
                                <i class="fas fa-camera"></i> Capturar
                            </button>
                        </div>
                    </div>
                    <div id="scannerResult" class="scanner-result text-center" style="display: none;">
                        <p class="mb-2"><i class="fas fa-check-circle text-success"></i> Documento digitalizado com sucesso!</p>
                        <img id="scannedImage" src="" style="max-width: 100%; max-height: 250px; border-radius: 10px;">
                        <div class="mt-3">
                            <button type="button" id="aceitarScannerBtn" class="btn btn-success">Aceitar</button>
                            <button type="button" id="novamenteScannerBtn" class="btn btn-secondary">Digitalizar Novamente</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modais -->
    <div class="modal fade" id="modalNovoPais" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Novo País</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novoPais" class="form-control"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="salvarPaisBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovaCidade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Nova Cidade</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaCidade" class="form-control"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="salvarCidadeBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovaProvincia" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Nova Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaProvincia" class="form-control"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Novo Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novoMunicipio" class="form-control"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div></div>
    <div class="modal fade" id="modalNovaComuna" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Nova Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaComuna" class="form-control"></div><div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div></div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentTargetInput = null, currentTargetPreview = null, scannerStream = null, scannedImageData = null;
        
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        function toggleSubmenu(e) { e.preventDefault(); $('#submenuSecretaria').toggleClass('show'); }
        
        function previewFoto(input) { if(input.files && input.files[0]) { var reader = new FileReader(); reader.onload = function(e) { $('#fotoPreview').attr('src', e.target.result); }; reader.readAsDataURL(input.files[0]); } }
        function previewDocumento(input, previewId) { if(input.files && input.files[0] && input.files[0].type.startsWith('image/')) { var reader = new FileReader(); reader.onload = function(e) { $('#'+previewId).html('<img src="'+e.target.result+'" class="document-preview" onclick="window.open(this.src)">'); }; reader.readAsDataURL(input.files[0]); } }
        
        $('#pais_id').change(function() { var paisId=$(this).val(); if(paisId) $.ajax({url:'editar_aluno.php',method:'GET',data:{acao:'get_cidades',pais_id:paisId},success:function(data){ var cidades=JSON.parse(data); var options='<option value="">Selecione...</option>'; for(var i=0;i<cidades.length;i++) options+='<option value="'+cidades[i].id+'">'+cidades[i].nome+'</option>'; $('#cidade_id').html(options).prop('disabled',false); } }); else $('#cidade_id').html('<option value="">Selecione país primeiro</option>').prop('disabled',true); });
        $('#provincia_id').change(function() { var provinciaId=$(this).val(); if(provinciaId) $.ajax({url:'editar_aluno.php',method:'GET',data:{acao:'get_municipios',provincia_id:provinciaId},success:function(data){ var municipios=JSON.parse(data); var options='<option value="">Selecione...</option>'; for(var i=0;i<municipios.length;i++) options+='<option value="'+municipios[i].id+'">'+municipios[i].nome+'</option>'; $('#municipio_id').html(options).prop('disabled',false); } }); else $('#municipio_id').html('<option value="">Selecione província primeiro</option>').prop('disabled',true); });
        $('#municipio_id').change(function() { var municipioId=$(this).val(); if(municipioId) $.ajax({url:'editar_aluno.php',method:'GET',data:{acao:'get_comunas',municipio_id:municipioId},success:function(data){ var comunas=JSON.parse(data); var options='<option value="">Selecione...</option>'; for(var i=0;i<comunas.length;i++) options+='<option value="'+comunas[i].id+'">'+comunas[i].nome+'</option>'; $('#comuna_id').html(options).prop('disabled',false); } }); else $('#comuna_id').html('<option value="">Selecione município primeiro</option>').prop('disabled',true); });
        
        $('#turma_id').change(function() { var turmaId=$(this).val(); if(turmaId) { $('#turno').val('Carregando...'); $('#sala').val('Carregando...'); $('#ano_escolar').val('Carregando...'); $.ajax({url:'editar_aluno.php',method:'GET',data:{acao:'get_turma_completa',turma_id:turmaId},dataType:'json',success:function(data){ if(data.success){ $('#turno').val(data.turno); $('#sala').val(data.sala); $('#ano_escolar').val(data.classe); if($('input[name="classe"]').val()=='') $('input[name="classe"]').val(data.classe); } else { $('#turno').val(''); $('#sala').val(''); $('#ano_escolar').val(''); } } }); } else { $('#turno').val(''); $('#sala').val(''); $('#ano_escolar').val(''); } });
        
        if($('#pais_id').val()) { $('#pais_id').trigger('change'); setTimeout(function() { $('#cidade_id').val('<?php echo $aluno['cidade_id']; ?>'); }, 500); }
        if($('#provincia_id').val()) { $('#provincia_id').trigger('change'); setTimeout(function() { $('#municipio_id').val('<?php echo $aluno['municipio_id']; ?>'); $('#municipio_id').trigger('change'); setTimeout(function() { $('#comuna_id').val('<?php echo $aluno['comuna_id']; ?>'); }, 300); }, 300); }
        
        async function iniciarScanner() { if(scannerStream) scannerStream.getTracks().forEach(t=>t.stop()); try { const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:"environment"}}); scannerStream=stream; document.getElementById('scannerVideo').srcObject=stream; await document.getElementById('scannerVideo').play(); } catch(err) { try { const stream=await navigator.mediaDevices.getUserMedia({video:true}); scannerStream=stream; document.getElementById('scannerVideo').srcObject=stream; } catch(err2){ alert('Erro ao acessar câmara'); } } }
        function pararScanner() { if(scannerStream) { scannerStream.getTracks().forEach(t=>t.stop()); scannerStream=null; } }
        function capturarImagemScanner() { const video=document.getElementById('scannerVideo'); const canvas=document.createElement('canvas'); canvas.width=video.videoWidth; canvas.height=video.videoHeight; canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height); scannedImageData=canvas.toDataURL('image/jpeg',0.95); document.getElementById('scannedImage').src=scannedImageData; document.querySelector('.scanner-container').style.display='none'; document.getElementById('scannerResult').style.display='block'; pararScanner(); }
        function aceitarDocumento() { if(scannedImageData && currentTargetInput) { $.ajax({url:'editar_aluno.php',method:'POST',data:{acao:'salvar_imagem_scanner',imagem:scannedImageData,tipo_documento:currentTargetInput},success:function(data){ var result=JSON.parse(data); if(result.success){ $('#'+currentTargetInput).val(result.arquivo); $('#'+currentTargetPreview).html('<img src="../../uploads/alunos/documentos/'+result.arquivo+'" class="document-preview"><br><small class="text-success">✓ Digitalizado</small>'); $('#modalScanner').modal('hide'); } else alert('Erro ao salvar'); } }); } }
        function abrirScanner(targetInput, targetPreview) { currentTargetInput=targetInput; currentTargetPreview=targetPreview; scannedImageData=null; document.querySelector('.scanner-container').style.display='block'; document.getElementById('scannerResult').style.display='none'; iniciarScanner(); $('#modalScanner').modal('show'); }
        $('#recarregarCamScannerBtn').click(function(){ iniciarScanner(); }); $('#capturarScannerBtn').click(function(){ capturarImagemScanner(); }); $('#aceitarScannerBtn').click(function(){ aceitarDocumento(); }); $('#novamenteScannerBtn').click(function(){ document.querySelector('.scanner-container').style.display='block'; document.getElementById('scannerResult').style.display='none'; iniciarScanner(); }); $('#modalScanner').on('hidden.bs.modal', function(){ pararScanner(); });
         
        const video=document.getElementById('video'), canvas=document.getElementById('canvas'); let stream=null;
        function iniciarWebcam() { if(stream) stream.getTracks().forEach(t=>t.stop()); navigator.mediaDevices.getUserMedia({video:true}).then(s=>{stream=s;video.srcObject=s;}).catch(()=>alert('Erro ao acessar webcam')); }
        iniciarWebcam();
        $('#recarregarCamBtn').click(function(){ iniciarWebcam(); });
        $('#capturarBtn').click(function(){ canvas.width=video.videoWidth; canvas.height=video.videoHeight; canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height); const fotoData=canvas.toDataURL('image/png'); $('#fotoPreview').attr('src',fotoData); fetch(fotoData).then(res=>res.blob()).then(blob=>{ const file=new File([blob],'foto.png',{type:'image/png'}); const dt=new DataTransfer(); dt.items.add(file); document.getElementById('fotoInput').files=dt.files; }); const hidden=document.createElement('input'); hidden.type='hidden'; hidden.name='foto_capturada'; hidden.value=fotoData; document.getElementById('formAluno').appendChild(hidden); });
        
        $('#salvarPaisBtn').click(function(){ var novoPais=$('#novoPais').val(); if(novoPais) $.ajax({url:'editar_aluno.php',method:'POST',data:{acao:'add_pais',novo_pais:novoPais},success:function(data){ var r=JSON.parse(data); if(r.success){$('#pais_id').append('<option value="'+r.pais+'">'+r.pais+'</option>');$('#pais_id').val(r.pais);$('#modalNovoPais').modal('hide');} } }); });
        $('#salvarCidadeBtn').click(function(){ var novaCidade=$('#novaCidade').val(), paisId=$('#pais_id').val(); if(novaCidade&&paisId) $.ajax({url:'editar_aluno.php',method:'POST',data:{acao:'add_cidade',nova_cidade:novaCidade,pais_id:paisId},success:function(data){ var r=JSON.parse(data); if(r.success){$('#cidade_id').append('<option value="'+r.cidade+'">'+r.cidade+'</option>');$('#cidade_id').val(r.cidade);$('#modalNovaCidade').modal('hide');} } }); });
        $('#salvarProvinciaBtn').click(function(){ var novaProvincia=$('#novaProvincia').val(); if(novaProvincia) $.ajax({url:'editar_aluno.php',method:'POST',data:{acao:'add_provincia',nova_provincia:novaProvincia},success:function(data){ var r=JSON.parse(data); if(r.success){$('#provincia_id').append('<option value="'+r.provincia+'">'+r.provincia+'</option>');$('#provincia_id').val(r.provincia);$('#modalNovaProvincia').modal('hide');} } }); });
        $('#salvarMunicipioBtn').click(function(){ var novoMunicipio=$('#novoMunicipio').val(), provinciaId=$('#provincia_id').val(); if(novoMunicipio&&provinciaId) $.ajax({url:'editar_aluno.php',method:'POST',data:{acao:'add_municipio',novo_municipio:novoMunicipio,provincia_id:provinciaId},success:function(data){ var r=JSON.parse(data); if(r.success){$('#municipio_id').append('<option value="'+r.municipio+'">'+r.municipio+'</option>');$('#municipio_id').val(r.municipio);$('#modalNovoMunicipio').modal('hide');} } }); });
        $('#salvarComunaBtn').click(function(){ var novaComuna=$('#novaComuna').val(), municipioId=$('#municipio_id').val(); if(novaComuna&&municipioId) $.ajax({url:'editar_aluno.php',method:'POST',data:{acao:'add_comuna',nova_comuna:novaComuna,municipio_id:municipioId},success:function(data){ var r=JSON.parse(data); if(r.success){$('#comuna_id').append('<option value="'+r.comuna+'">'+r.comuna+'</option>');$('#comuna_id').val(r.comuna);$('#modalNovaComuna').modal('hide');} } }); });
        
        const currentPage = window.location.pathname;
        if(currentPage.includes('secretaria')) { $('#menuSecretaria').addClass('open'); $('#submenuSecretaria').addClass('show'); }
    </script>
</body>
</html>