<?php
// escola/secretaria/matricula.php - Cadastro de Aluno com Scanner Inteligente

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

// Verificar se há turmas cadastradas
$tem_turmas = !empty($turmas);

// ============================================
// ADICIONADO: BUSCAR CURSOS, NÍVEIS E ANOS LETIVOS DO BANCO
// ============================================

// Buscar cursos do banco de dados
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

// Buscar níveis do banco de dados
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

// Buscar anos letivos do banco de dados
$sql_anos = "SELECT id, ano, ativo FROM ano_letivo ORDER BY ano DESC";
$anos_letivos = $conn->query($sql_anos)->fetchAll(PDO::FETCH_ASSOC);
if (empty($anos_letivos)) {
    $anos_letivos = [
        ['id' => 1, 'ano' => date('Y'), 'ativo' => 1],
        ['id' => 2, 'ano' => date('Y')-1, 'ativo' => 0]
    ];
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

// Função para gerar número de matrícula automático
function gerarNumeroMatricula($conn, $escola_id, $ano_letivo) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM estudantes 
        WHERE escola_id = :escola_id 
        AND YEAR(created_at) = :ano
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] + 1;
    
    return $ano_letivo . '/' . str_pad($escola_id, 3, '0', STR_PAD_LEFT) . '/' . str_pad($total, 5, '0', STR_PAD_LEFT);
}

// Função para criar avatar padrão
function criarAvatarAluno($nome, $tamanho = 200) {
    $avatar_dir = __DIR__ . '/../../uploads/avatares/';
    if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0777, true);
    
    $iniciais = strtoupper(substr($nome, 0, 2));
    $cores = ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#ffc107', '#dc3545'];
    $cor = $cores[abs(crc32($nome)) % count($cores)];
    
    $imagem = imagecreate($tamanho, $tamanho);
    $bg_color = imagecolorallocate($imagem, hexdec(substr($cor, 1, 2)), hexdec(substr($cor, 3, 2)), hexdec(substr($cor, 5, 2)));
    $text_color = imagecolorallocate($imagem, 255, 255, 255);
    
    imagefill($imagem, 0, 0, $bg_color);
    
    $fonte = __DIR__ . '/../../assets/fonts/arial.ttf';
    // CORREÇÃO AQUI: converter para inteiro
    $fonte_size = (int)round($tamanho / 3);
    
    if (file_exists($fonte)) {
        $bbox = imagettfbbox($fonte_size, 0, $fonte, $iniciais);
        $x = ($tamanho - ($bbox[2] - $bbox[0])) / 2;
        $y = ($tamanho - ($bbox[1] - $bbox[7])) / 2;
        imagettftext($imagem, $fonte_size, 0, (int)$x, (int)$y, $text_color, $fonte, $iniciais);
    } else {
        $font_width = imagefontwidth(5);
        $font_height = imagefontheight(5);
        $x = (int)(($tamanho - $font_width * strlen($iniciais)) / 2);
        $y = (int)(($tamanho - $font_height) / 2);
        imagestring($imagem, 5, $x, $y, $iniciais, $text_color);
    }
    
    $nome_avatar = 'avatar_' . time() . '_' . uniqid() . '.png';
    imagepng($imagem, $avatar_dir . $nome_avatar);
    imagedestroy($imagem);
    
    return $nome_avatar;
}

// Função para upload de arquivo
function uploadArquivoAluno($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
    if (!isset($arquivo) || $arquivo['error'] != 0) {
        return null;
    }
    
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $tipos_permitidos)) {
        return false;
    }
    
    if ($arquivo['size'] > $tamanho_maximo) {
        return false;
    }
    
    if (!is_dir($pasta)) {
        mkdir($pasta, 0777, true);
    }
    
    $nome_arquivo = time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($arquivo['tmp_name'], $pasta . $nome_arquivo)) {
        return $nome_arquivo;
    }
    
    return false;
}

// Função para salvar imagem base64 como arquivo
function salvarImagemBase64($base64, $pasta, $prefixo) {
    if (!preg_match('/^data:image\/(\w+);base64,/', $base64, $type)) {
        return false;
    }
    
    $base64 = substr($base64, strpos($base64, ',') + 1);
    $base64 = base64_decode($base64);
    
    if (!is_dir($pasta)) {
        mkdir($pasta, 0777, true);
    }
    
    $ext = $type[1];
    $nome_arquivo = $prefixo . '_' . time() . '_' . uniqid() . '.' . $ext;
    file_put_contents($pasta . $nome_arquivo, $base64);
    
    return $nome_arquivo;
}

// ============================================
// PROCESSAR AJAX
// ============================================

// Salvar imagem escaneada (recebida do scanner JS)
if (isset($_POST['acao']) && $_POST['acao'] == 'salvar_imagem_scanner') {
    $imagem_base64 = $_POST['imagem'] ?? '';
    $tipo_documento = $_POST['tipo_documento'] ?? '';
    
    if ($imagem_base64 && $tipo_documento) {
        $pasta_docs = __DIR__ . '/../../uploads/alunos/documentos/';
        $nome_arquivo = salvarImagemBase64($imagem_base64, $pasta_docs, $tipo_documento);
        
        if ($nome_arquivo) {
            echo json_encode(['success' => true, 'arquivo' => $nome_arquivo]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar imagem']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    }
    exit;
}

// ADICIONADO: Buscar dados completos da turma (turno, sala, classe)
if (isset($_GET['acao']) && $_GET['acao'] == 'get_turma_completa') {
    $turma_id = $_GET['turma_id'] ?? 0;
    
    if ($turma_id) {
        $stmt = $conn->prepare("
            SELECT 
                id, 
                nome, 
                ano, 
                turno, 
                sala,
                CASE 
                    WHEN turno = 'manha' THEN 'Manhã'
                    WHEN turno = 'tarde' THEN 'Tarde'
                    WHEN turno = 'noite' THEN 'Noite'
                    ELSE turno
                END AS turno_formatado
            FROM turmas 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $turma_id]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($turma) {
            echo json_encode([
                'success' => true,
                'turno' => $turma['turno_formatado'],
                'sala' => $turma['sala'] ?? 'Não definida',
                'classe' => $turma['ano'] ?? '',
                'ano' => $turma['ano']
            ]);
        } else {
            echo json_encode(['success' => false]);
        }
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Adicionar novo país
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

// Adicionar nova naturalidade/cidade
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

// Adicionar nova província
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

// Adicionar novo município
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

// Adicionar nova comuna
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

// Buscar municípios por província
if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia_id = $_GET['provincia_id'] ?? 0;
    if ($provincia_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
        $stmt->execute([':provincia_id' => $provincia_id]);
        $municipios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($municipios);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Buscar comunas por município
if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio_id = $_GET['municipio_id'] ?? 0;
    if ($municipio_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
        $stmt->execute([':municipio_id' => $municipio_id]);
        $comunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comunas);
    } else {
        echo json_encode([]);
    }
    exit;
}

// Buscar cidades por país
if (isset($_GET['acao']) && $_GET['acao'] == 'get_cidades') {
    $pais_id = $_GET['pais_id'] ?? 0;
    if ($pais_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM cidades WHERE pais_id = :pais_id ORDER BY nome");
        $stmt->execute([':pais_id' => $pais_id]);
        $cidades = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($cidades);
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_aluno'])) {
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
    $ano_letivo = $_POST['ano_letivo'] ?? date('Y');
    
    // ADICIONADO: Buscar curso, nivel e classe dos comboboxes
    $curso = $_POST['curso'] ?? '';
    $nivel = $_POST['nivel'] ?? '';
    $classe = $_POST['classe'] ?? '';
    
    // Upload da Foto
    $foto = null;
    $foto_capturada = $_POST['foto_capturada'] ?? '';
    
    if (!empty($foto_capturada)) {
        $foto_dir = __DIR__ . '/../../uploads/alunos/fotos/';
        if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
        $foto = 'foto_' . time() . '_' . uniqid() . '.png';
        file_put_contents($foto_dir . $foto, base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $foto_capturada)));
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $foto = uploadArquivoAluno($_FILES['foto'], __DIR__ . '/../../uploads/alunos/fotos/', ['jpg','jpeg','png','gif','webp'], 2097152);
    }
    
    // Upload de Documentos (via scanner ou upload normal)
    $upload_dir_docs = __DIR__ . '/../../uploads/alunos/documentos/';
    
    $bi_documento = $_POST['bi_documento_scanner'] ?? '';
    if (!$bi_documento && isset($_FILES['bi_documento']) && $_FILES['bi_documento']['error'] == 0) {
        $bi_documento = uploadArquivoAluno($_FILES['bi_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    }
    
    $certificado_documento = $_POST['certificado_documento_scanner'] ?? '';
    if (!$certificado_documento && isset($_FILES['certificado_documento']) && $_FILES['certificado_documento']['error'] == 0) {
        $certificado_documento = uploadArquivoAluno($_FILES['certificado_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    }
    
    $atestado_documento = $_POST['atestado_documento_scanner'] ?? '';
    if (!$atestado_documento && isset($_FILES['atestado_documento']) && $_FILES['atestado_documento']['error'] == 0) {
        $atestado_documento = uploadArquivoAluno($_FILES['atestado_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    }
    
    $declaracao_documento = $_POST['declaracao_documento_scanner'] ?? '';
    if (!$declaracao_documento && isset($_FILES['declaracao_documento']) && $_FILES['declaracao_documento']['error'] == 0) {
        $declaracao_documento = uploadArquivoAluno($_FILES['declaracao_documento'], $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    }
    
    $outros_documentos = [];
    for ($i = 1; $i <= 3; $i++) {
        $doc = uploadArquivoAluno($_FILES["outro_documento_$i"] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf','doc','docx'], 5242880);
        if ($doc) {
            $outros_documentos[] = $doc;
        }
    }
    $outros_documentos_json = json_encode($outros_documentos);
    
    // Gerar número de matrícula automático
    $numero_matricula = gerarNumeroMatricula($conn, $escola_id, $ano_letivo);
    
    // Credenciais de acesso
    $usuario_acesso = $numero_matricula;
    $senha_acesso = !empty($bi_numero) ? $bi_numero : substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $senha_hash = password_hash($senha_acesso, PASSWORD_DEFAULT);
    $email_usuario = $email ?: $numero_matricula . '@aluno.sige.ao';
    
    // Buscar nomes para IDs
    $pais_nome = '';
    $cidade_nome = '';
    $provincia_nome = '';
    $municipio_nome = '';
    $comuna_nome = '';
    
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
    
    // Buscar dados da turma
    $turma_dados = null;
    if ($turma_id) {
        $stmt = $conn->prepare("SELECT ano, turno, sala FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $turma_id]);
        $turma_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $ano_escolar = $turma_dados['ano'] ?? '';
    $turno = $turma_dados['turno'] ?? '';
    $sala = $turma_dados['sala'] ?? '';
    
    // ADICIONADO: Se classe não foi definida, usar ano_escolar
    if (empty($classe)) {
        $classe = $ano_escolar;
    }
    
    // Criar avatar se não houver foto
    if (!$foto) {
        $foto = criarAvatarAluno($nome_completo);
    }
    
    try {
        $conn->beginTransaction();
        
        // Verificar se BI já existe
        if ($bi_numero) {
            $stmt = $conn->prepare("SELECT id FROM estudantes WHERE bi = :bi AND escola_id = :escola_id");
            $stmt->execute([':bi' => $bi_numero, ':escola_id' => $escola_id]);
            if ($stmt->fetch()) {
                throw new Exception("BI já cadastrado no sistema.");
            }
        }
        
        // Verificar se número de matrícula já existe
        $stmt = $conn->prepare("SELECT id FROM estudantes WHERE matricula = :matricula AND escola_id = :escola_id");
        $stmt->execute([':matricula' => $numero_matricula, ':escola_id' => $escola_id]);
        if ($stmt->fetch()) {
            throw new Exception("Número de matrícula já existe.");
        }
        
        // Criar usuário
        $stmt = $conn->prepare("
            INSERT INTO usuarios (escola_id, nome, email, senha, tipo, telefone, status, created_at)
            VALUES (:escola_id, :nome, :email, :senha, 'aluno', :telefone, 'ativo', NOW())
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome_completo,
            ':email' => $email_usuario,
            ':senha' => $senha_hash,
            ':telefone' => $telefone
        ]);
        $usuario_id = $conn->lastInsertId();
        
        // Inserir estudante (ADICIONADO: campos curso, nivel, classe)
        $stmt = $conn->prepare("
            INSERT INTO estudantes (
                usuario_id, escola_id,nome, matricula, bi, bi_data_emissao, bi_local_emissao,
                pais_id, pais_nome, cidade_id, cidade_nome,
                provincia_id, provincia_nome, municipio_id, municipio_nome, comuna_id, comuna_nome,
                endereco, telefone, email, data_nascimento, genero, foto,
                pai_nome, pai_bi, pai_telefone, pai_profissao,
                mae_nome, mae_bi, mae_telefone, mae_profissao,
                encarregado_nome, encarregado_parentesco, encarregado_bi,
                encarregado_telefone, encarregado_email, encarregado_endereco,
                ano_letivo, ano_escolar, curso, nivel, classe,
                numero_processo, bi_documento, certificado_documento,
                atestado_documento, outros_documentos, declaracao_documento, created_at
            ) VALUES (
                :usuario_id, :escola_id, :nome, :matricula, :bi, :bi_data_emissao, :bi_local_emissao,
                :pais_id, :pais_nome, :cidade_id, :cidade_nome,
                :provincia_id, :provincia_nome, :municipio_id, :municipio_nome, :comuna_id, :comuna_nome,
                :endereco, :telefone, :email, :data_nascimento, :genero, :foto,
                :pai_nome, :pai_bi, :pai_telefone, :pai_profissao,
                :mae_nome, :mae_bi, :mae_telefone, :mae_profissao,
                :encarregado_nome, :encarregado_parentesco, :encarregado_bi,
                :encarregado_telefone, :encarregado_email, :encarregado_endereco,
                :ano_letivo, :ano_escolar, :curso, :nivel, :classe,
                :numero_processo, :bi_documento, :certificado_documento,
                :atestado_documento, :outros_documentos, :declaracao_documento, NOW()
            )
        ");
        
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':escola_id' => $escola_id,
             ':nome' => $nome_completo,  // <-- ADICIONE ESTA LINHA
            ':matricula' => $numero_matricula,
            ':bi' => $bi_numero ?: null,
            ':bi_data_emissao' => $bi_data_emissao ?: null,
            ':bi_local_emissao' => $bi_local_emissao ?: null,
            ':pais_id' => $pais_id ?: null,
            ':pais_nome' => $pais_nome ?: null,
            ':cidade_id' => $cidade_id ?: null,
            ':cidade_nome' => $cidade_nome ?: null,
            ':provincia_id' => $provincia_id ?: null,
            ':provincia_nome' => $provincia_nome ?: null,
            ':municipio_id' => $municipio_id ?: null,
            ':municipio_nome' => $municipio_nome ?: null,
            ':comuna_id' => $comuna_id ?: null,
            ':comuna_nome' => $comuna_nome ?: null,
            ':endereco' => $endereco ?: null,
            ':telefone' => $telefone ?: null,
            ':email' => $email ?: null,
            ':data_nascimento' => $data_nascimento ?: null,
            ':genero' => $genero ?: null,
            ':foto' => $foto,
            ':pai_nome' => $pai_nome ?: null,
            ':pai_bi' => $pai_bi ?: null,
            ':pai_telefone' => $pai_telefone ?: null,
            ':pai_profissao' => $pai_profissao ?: null,
            ':mae_nome' => $mae_nome ?: null,
            ':mae_bi' => $mae_bi ?: null,
            ':mae_telefone' => $mae_telefone ?: null,
            ':mae_profissao' => $mae_profissao ?: null,
            ':encarregado_nome' => $encarregado_nome ?: null,
            ':encarregado_parentesco' => $encarregado_parentesco ?: null,
            ':encarregado_bi' => $encarregado_bi ?: null,
            ':encarregado_telefone' => $encarregado_telefone ?: null,
            ':encarregado_email' => $encarregado_email ?: null,
            ':encarregado_endereco' => $encarregado_endereco ?: null,
            ':ano_letivo' => $ano_letivo,
            ':ano_escolar' => $ano_escolar ?: null,
            ':curso' => $curso ?: null,
            ':nivel' => $nivel ?: null,
            ':classe' => $classe ?: null,
            ':numero_processo' => $numero_matricula,
            ':bi_documento' => $bi_documento,
            ':certificado_documento' => $certificado_documento,
            ':atestado_documento' => $atestado_documento,
            ':outros_documentos' => $outros_documentos_json,
            ':declaracao_documento' => $declaracao_documento
        ]);
        
        $estudante_id = $conn->lastInsertId();
        
        // Matricular na turma (ADICIONADO: campos classe, curso, nivel)
        $stmt = $conn->prepare("
            INSERT INTO matriculas (
                estudante_id, turma_id, turno, sala, classe, curso, nivel,
                ano_letivo, numero_processo, status, data_matricula, created_at
            ) VALUES (
                :estudante_id, :turma_id, :turno, :sala, :classe, :curso, :nivel,
                :ano_letivo, :numero_processo, 'ativa', CURDATE(), NOW()
            )
        ");
        $stmt->execute([
            ':estudante_id' => $estudante_id,
            ':turma_id' => $turma_id,
            ':turno' => $turno,
            ':sala' => $sala,
            ':classe' => $classe ?: $ano_escolar,
            ':curso' => $curso ?: null,
            ':nivel' => $nivel ?: null,
            ':ano_letivo' => $ano_letivo,
            ':numero_processo' => $numero_matricula
        ]);
        
        $conn->commit();
        
        $success = "Matrícula realizada com sucesso!<br>
                    Nº Matrícula: <strong>{$numero_matricula}</strong><br>
                    Usuário de acesso: <strong>{$usuario_acesso}</strong><br>
                    Senha de acesso: <strong>{$senha_acesso}</strong><br>
                    E-mail alternativo: <strong>{$email_usuario}</strong>";
        
        // Log
        $stmt = $conn->prepare("
            INSERT INTO logs_sistema (usuario_id, acao, tabela, registro_id, ip, created_at)
            VALUES (:usuario_id, 'cadastrar_aluno', 'estudantes', :registro_id, :ip, NOW())
        ");
        $stmt->execute([
            ':usuario_id' => $_SESSION['usuario_id'],
            ':registro_id' => $estudante_id,
            ':ip' => $_SERVER['REMOTE_ADDR']
        ]);
        
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
    <title>Matrícula | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Biblioteca para detecção de bordas e correção de perspectiva -->
    <script src="https://cdn.jsdelivr.net/npm/opencv.js@1.2.1/opencv.js"></script>
    <style>
        /* TODO O SEU CSS ORIGINAL CONTINUA AQUI - NÃO MUDEI NADA */
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        /* Sidebar */
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
        .badge-turma { background: #17a2b8; }
        .alert-no-turmas { background: #fff3cd; border-left: 4px solid #ffc107; }
        
        /* Scanner Inteligente */
        .scanner-modal .modal-content { border-radius: 20px; overflow: hidden; }
        .scanner-container {
            position: relative;
            background: #000;
            border-radius: 15px;
            overflow: hidden;
        }
        #scannerVideo {
            width: 100%;
            max-height: 500px;
            object-fit: cover;
            background: #000;
            transform: scaleX(-1);
        }
        .scanner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        .scanner-frame {
            width: 85%;
            height: 70%;
            border: 2px solid rgba(0, 255, 100, 0.8);
            border-radius: 15px;
            box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { border-color: rgba(0, 255, 100, 0.6); box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5); }
            50% { border-color: rgba(0, 255, 100, 1); box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.3); }
            100% { border-color: rgba(0, 255, 100, 0.6); box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5); }
        }
        .scanner-controls {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            z-index: 10;
        }
        .btn-scanner {
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(10px);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 30px;
            margin: 0 10px;
        }
        .btn-scanner-primary {
            background: #006B3E;
            color: white;
        }
        .btn-scanner:hover { opacity: 0.9; }
        .scanner-result {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 10px;
        }
        .scanner-result img {
            max-width: 100%;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .document-badge {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
        }
        .btn-capture-doc {
            background: #006B3E;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8em;
        }
        .btn-capture-doc:hover { background: #004d2d; color: white; }
        
        /* Indicador de detecção */
        .detection-indicator {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.6);
            color: #00ff64;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.7em;
            font-family: monospace;
            z-index: 20;
        }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-plus"></i> Nova Matrícula</h2>
            <a href="alunos_matriculados.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
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
                
                <?php if (!$tem_turmas): ?>
                <div class="alert alert-warning alert-no-turmas">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Nenhuma turma cadastrada para o ano letivo <?php echo $ano_letivo; ?>!</strong><br>
                    Por favor, cadastre as turmas antes de realizar matrículas.
                    <a href="../turmas/cadastrar.php" class="btn btn-sm btn-warning mt-2">Cadastrar Turma</a>
                </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" id="formAluno">
                    <input type="hidden" name="cadastrar_aluno" value="1">
                    <input type="hidden" name="bi_documento_scanner" id="bi_documento_scanner" value="">
                    <input type="hidden" name="certificado_documento_scanner" id="certificado_documento_scanner" value="">
                    <input type="hidden" name="atestado_documento_scanner" id="atestado_documento_scanner" value="">
                    <input type="hidden" name="declaracao_documento_scanner" id="declaracao_documento_scanner" value="">
                    
                    <div class="tab-content">
                        <!-- Dados Pessoais -->
                        <div class="tab-pane fade show active" id="dadosPessoais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Nome Completo</label>
                                        <input type="text" name="nome_completo" class="form-control" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Data de Nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="mb-3">
                                        <label>Género</label>
                                        <select name="genero" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="M">Masculino</option>
                                            <option value="F">Feminino</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nº do BI</label>
                                        <input type="text" name="bi_numero" class="form-control" placeholder="Ex: 001234567LA042">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Data de Emissão do BI</label>
                                        <input type="date" name="bi_data_emissao" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Local de Emissão</label>
                                        <input type="text" name="bi_local_emissao" class="form-control" placeholder="Ex: Luanda">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>País</label>
                                        <div class="input-group">
                                            <select name="pais_id" id="pais_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($paises as $p): ?>
                                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPais">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Cidade / Naturalidade</label>
                                        <div class="input-group">
                                            <select name="cidade_id" id="cidade_id" class="form-control" disabled>
                                                <option value="">Primeiro selecione o país</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaCidade">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Província</label>
                                        <div class="input-group">
                                            <select name="provincia_id" id="provincia_id" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($provincias as $p): ?>
                                                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaProvincia">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Município</label>
                                        <div class="input-group">
                                            <select name="municipio_id" id="municipio_id" class="form-control" disabled>
                                                <option value="">Primeiro selecione a província</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoMunicipio">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Comuna</label>
                                        <div class="input-group">
                                            <select name="comuna_id" id="comuna_id" class="form-control" disabled>
                                                <option value="">Primeiro selecione o município</option>
                                            </select>
                                            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaComuna">
                                                <i class="fas fa-plus"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Endereço Completo</label>
                                <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Telefone</label>
                                        <input type="text" name="telefone" class="form-control" placeholder="9xx xxx xxx">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" placeholder="exemplo@email.com">
                                        <small class="text-muted">Opcional</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filiação -->
                        <div class="tab-pane fade" id="filiacao">
                            <h5 class="mb-3">Dados do Pai</h5>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome Completo do Pai</label><input type="text" name="pai_nome" class="form-control"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>BI do Pai</label><input type="text" name="pai_bi" class="form-control"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Telefone do Pai</label><input type="text" name="pai_telefone" class="form-control"></div></div>
                                <div class="col-md-12"><div class="mb-3"><label>Profissão do Pai</label><input type="text" name="pai_profissao" class="form-control"></div></div>
                            </div>
                            
                            <h5 class="mb-3 mt-4">Dados da Mãe</h5>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome Completo da Mãe</label><input type="text" name="mae_nome" class="form-control"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>BI da Mãe</label><input type="text" name="mae_bi" class="form-control"></div></div>
                                <div class="col-md-3"><div class="mb-3"><label>Telefone da Mãe</label><input type="text" name="mae_telefone" class="form-control"></div></div>
                                <div class="col-md-12"><div class="mb-3"><label>Profissão da Mãe</label><input type="text" name="mae_profissao" class="form-control"></div></div>
                            </div>
                        </div>
                        
                        <!-- Encarregado -->
                        <div class="tab-pane fade" id="encarregado">
                            <div class="alert alert-info"><i class="fas fa-info-circle"></i> Se o encarregado for diferente dos pais, preencha os dados abaixo.</div>
                            <div class="row">
                                <div class="col-md-6"><div class="mb-3"><label>Nome do Encarregado</label><input type="text" name="encarregado_nome" class="form-control"></div></div>
                                <div class="col-md-6"><div class="mb-3"><label>Parentesco</label><select name="encarregado_parentesco" class="form-control"><option value="">Selecione...</option><option value="Pai">Pai</option><option value="Mãe">Mãe</option><option value="Tio">Tio</option><option value="Tia">Tia</option><option value="Avô">Avô</option><option value="Avó">Avó</option><option value="Irmão">Irmão</option><option value="Irmã">Irmã</option><option value="Outro">Outro</option></select></div></div>
                            </div>
                            <div class="row">
                                <div class="col-md-4"><div class="mb-3"><label>BI do Encarregado</label><input type="text" name="encarregado_bi" class="form-control"></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>Telefone</label><input type="text" name="encarregado_telefone" class="form-control"></div></div>
                                <div class="col-md-4"><div class="mb-3"><label>E-mail</label><input type="email" name="encarregado_email" class="form-control"></div></div>
                            </div>
                            <div class="mb-3"><label>Endereço do Encarregado</label><textarea name="encarregado_endereco" class="form-control" rows="2"></textarea></div>
                        </div>
                        
                        <!-- Dados Académicos -->
                        <div class="tab-pane fade" id="academicos">
                            <?php if ($tem_turmas): ?>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Ano Letivo</label>
                                        <select name="ano_letivo" class="form-control" required>
                                            <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>><?php echo $i; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Turma</label>
                                        <select name="turma_id" id="turma_id" class="form-control" required>
                                            <option value="">Selecione...</option>
                                            <?php foreach ($turmas as $t): ?>
                                            <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nome']); ?> (<?php echo $t['ano']; ?> - <?php echo ucfirst($t['turno']); ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- ADICIONADO: Linha para Curso e Nível -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Curso</label>
                                        <select name="curso" id="select_curso" class="form-control">
                                            <option value="">Selecione o curso...</option>
                                            <?php foreach ($cursos as $curso): ?>
                                            <option value="<?php echo htmlspecialchars($curso['nome']); ?>"><?php echo htmlspecialchars($curso['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nível de Ensino</label>
                                        <select name="nivel" id="select_nivel" class="form-control">
                                            <option value="">Selecione o nível...</option>
                                            <?php foreach ($niveis as $nivel): ?>
                                            <option value="<?php echo htmlspecialchars($nivel['nome']); ?>"><?php echo htmlspecialchars($nivel['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <!-- FIM ADICIONADO -->
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Classe / Ano</label>
                                        <input type="text" name="ano_escolar" id="ano_escolar" class="form-control" readonly disabled>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Turno</label>
                                        <input type="text" name="turno" id="turno" class="form-control" readonly disabled>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Sala</label>
                                        <input type="text" name="sala" id="sala" class="form-control" readonly disabled>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Classe (texto livre)</label>
                                        <input type="text" name="classe" class="form-control" placeholder="Ex: 10ª Classe">
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
                        
                        <!-- Documentos com Scanner Inteligente -->
                        <div class="tab-pane fade" id="documentos">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                                            <h6>BI / Documento de Identificação</h6>
                                            <input type="file" name="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                                            <div id="bi_preview" class="mt-2"></div>
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
                                            <div id="certificado_preview" class="mt-2"></div>
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
                                            <div id="atestado_preview" class="mt-2"></div>
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
                                            <div id="declaracao_preview" class="mt-2"></div>
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
                                    <h5>Upload de Foto</h5>
                                    <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*" onchange="previewFoto(this)">
                                    <div class="preview-container">
                                        <img id="fotoPreview" src="../../assets/images/avatar-padrao.png" class="preview-img">
                                    </div>
                                </div>
                                <div class="col-md-6 text-center">
                                    <h5>Capturar com Webcam</h5>
                                    <div class="webcam-container">
                                        <video id="video" width="100%" autoplay></video>
                                        <button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar Foto</button>
                                        <button type="button" id="recarregarCamBtn" class="btn btn-secondary btn-sm mt-2">Recarregar Câmara</button>
                                        <canvas id="canvas" style="display:none;"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5" <?php echo !$tem_turmas ? 'disabled' : ''; ?>>
                            <i class="fas fa-save"></i> Realizar Matrícula
                        </button>
                        <a href="alunos_matriculados.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Scanner Inteligente -->
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
    
    <!-- Modais para adicionar itens -->
    <div class="modal fade" id="modalNovoPais" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Novo País</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="text" id="novoPais" class="form-control" placeholder="Nome do País"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarPaisBtn">Salvar</button></div></div></div>
    </div>
    
    <div class="modal fade" id="modalNovaCidade" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Cidade</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="mb-3"><label>País</label><input type="text" id="cidadePais" class="form-control" readonly></div><div class="mb-3"><label>Nome da Cidade</label><input type="text" id="novaCidade" class="form-control"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarCidadeBtn">Salvar</button></div></div></div>
    </div>
    
    <div class="modal fade" id="modalNovaProvincia" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="text" id="novaProvincia" class="form-control" placeholder="Nome da Província"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div>
    </div>
    
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Novo Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="mb-3"><label>Província</label><input type="text" id="municipioProvincia" class="form-control" readonly></div><div class="mb-3"><label>Nome do Município</label><input type="text" id="novoMunicipio" class="form-control"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div>
    </div>
    
    <div class="modal fade" id="modalNovaComuna" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="mb-3"><label>Município</label><input type="text" id="comunaMunicipio" class="form-control" readonly></div><div class="mb-3"><label>Nome da Comuna</label><input type="text" id="novaComuna" class="form-control"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // TODO O SEU JAVASCRIPT ORIGINAL CONTINUA AQUI - NÃO MUDEI NADA
        
        // Variáveis globais
        let currentTargetInput = null;
        let currentTargetPreview = null;
        let scannerStream = null;
        let scannedImageData = null;
        
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
        
        // ============================================
        // RELACIONAMENTOS EM CASCATA
        // ============================================
        
        $('#pais_id').change(function() {
            var paisId = $(this).val();
            if (paisId) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'GET',
                    data: { acao: 'get_cidades', pais_id: paisId },
                    success: function(data) {
                        var cidades = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < cidades.length; i++) {
                            options += '<option value="' + cidades[i].id + '">' + cidades[i].nome + '</option>';
                        }
                        $('#cidade_id').html(options);
                        $('#cidade_id').prop('disabled', false);
                    }
                });
            } else {
                $('#cidade_id').html('<option value="">Primeiro selecione o país</option>').prop('disabled', true);
            }
        });
        
        $('#provincia_id').change(function() {
            var provinciaId = $(this).val();
            if (provinciaId) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'GET',
                    data: { acao: 'get_municipios', provincia_id: provinciaId },
                    success: function(data) {
                        var municipios = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < municipios.length; i++) {
                            options += '<option value="' + municipios[i].id + '">' + municipios[i].nome + '</option>';
                        }
                        $('#municipio_id').html(options);
                        $('#municipio_id').prop('disabled', false);
                    }
                });
            } else {
                $('#municipio_id').html('<option value="">Primeiro selecione a província</option>').prop('disabled', true);
                $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        $('#municipio_id').change(function() {
            var municipioId = $(this).val();
            if (municipioId) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'GET',
                    data: { acao: 'get_comunas', municipio_id: municipioId },
                    success: function(data) {
                        var comunas = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < comunas.length; i++) {
                            options += '<option value="' + comunas[i].id + '">' + comunas[i].nome + '</option>';
                        }
                        $('#comuna_id').html(options);
                        $('#comuna_id').prop('disabled', false);
                    }
                });
            } else {
                $('#comuna_id').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // ============================================
        // CARREGAR TURNO, SALA E CLASSE AO SELECIONAR TURMA - ADICIONADO
        // ============================================
        
        $('#turma_id').change(function() {
            var turmaId = $(this).val();
            
            if (turmaId) {
                // Mostrar loading
                $('#turno').val('Carregando...');
                $('#sala').val('Carregando...');
                $('#ano_escolar').val('Carregando...');
                
                $.ajax({
                    url: 'matricula.php',
                    method: 'GET',
                    data: { 
                        acao: 'get_turma_completa', 
                        turma_id: turmaId 
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success) {
                            $('#turno').val(data.turno);
                            $('#sala').val(data.sala);
                            $('#ano_escolar').val(data.classe);
                            // Preencher também o campo classe se estiver vazio
                            if ($('input[name="classe"]').val() == '') {
                                $('input[name="classe"]').val(data.classe);
                            }
                        } else {
                            $('#turno').val('');
                            $('#sala').val('');
                            $('#ano_escolar').val('');
                        }
                    },
                    error: function() {
                        $('#turno').val('Erro ao carregar');
                        $('#sala').val('Erro ao carregar');
                        $('#ano_escolar').val('Erro ao carregar');
                    }
                });
            } else {
                $('#turno').val('');
                $('#sala').val('');
                $('#ano_escolar').val('');
            }
        });
        
        // ============================================
        // PREVIEW DE FOTO
        // ============================================
        function previewFoto(input) {
            const file = input.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) { $('#fotoPreview').attr('src', e.target.result); };
                reader.readAsDataURL(file);
            }
        }
        
        // ============================================
        // PREVIEW DE DOCUMENTOS
        // ============================================
        function previewDocumento(input, previewId) {
            const file = input.files[0];
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) { 
                    $('#' + previewId).html('<img src="' + e.target.result + '" class="document-preview" onclick="window.open(this.src)">');
                };
                reader.readAsDataURL(file);
            } else if (file) {
                $('#' + previewId).html('<span class="badge bg-info"><i class="fas fa-file-pdf"></i> ' + file.name + '</span>');
            }
        }
        
        // ============================================
        // SCANNER INTELIGENTE
        // ============================================
        
        let detectionInterval = null;
        let lastDetectionTime = 0;
        
        async function iniciarScanner() {
            if (scannerStream) {
                scannerStream.getTracks().forEach(track => track.stop());
            }
            
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { facingMode: { exact: "environment" } } 
                });
                scannerStream = stream;
                const video = document.getElementById('scannerVideo');
                video.srcObject = stream;
                video.setAttribute('playsinline', true);
                await video.play();
                iniciarDetecaoDocumento();
            } catch (err) {
                try {
                    const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                    scannerStream = stream;
                    const video = document.getElementById('scannerVideo');
                    video.srcObject = stream;
                    video.setAttribute('playsinline', true);
                    await video.play();
                    iniciarDetecaoDocumento();
                } catch (err2) {
                    console.error("Erro ao acessar câmara:", err2);
                    alert('Não foi possível acessar a câmara. Verifique as permissões.');
                }
            }
        }
        
        function pararScanner() {
            if (detectionInterval) {
                clearInterval(detectionInterval);
                detectionInterval = null;
            }
            if (scannerStream) {
                scannerStream.getTracks().forEach(track => track.stop());
                scannerStream = null;
            }
        }
        
        function detectarDocumento(video) {
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            
            let bordasEncontradas = 0;
            
            for (let y = 0; y < canvas.height; y += 20) {
                for (let x = 0; x < canvas.width; x += 20) {
                    const idx = (y * canvas.width + x) * 4;
                    const r = data[idx];
                    const g = data[idx + 1];
                    const b = data[idx + 2];
                    const brilho = (r + g + b) / 3;
                    
                    if (x > 0 && x < canvas.width - 1) {
                        const idxPrev = (y * canvas.width + (x - 1)) * 4;
                        const brilhoPrev = (data[idxPrev] + data[idxPrev + 1] + data[idxPrev + 2]) / 3;
                        if (Math.abs(brilho - brilhoPrev) > 50) {
                            bordasEncontradas++;
                        }
                    }
                }
            }
            
            return { detectado: bordasEncontradas > 50, confianca: Math.min(100, Math.floor(bordasEncontradas / 2)) };
        }
        
        function iniciarDetecaoDocumento() {
            const video = document.getElementById('scannerVideo');
            const indicator = document.getElementById('detectionIndicator');
            
            if (detectionInterval) clearInterval(detectionInterval);
            
            detectionInterval = setInterval(() => {
                if (video && video.videoWidth > 0 && video.videoHeight > 0) {
                    const resultado = detectarDocumento(video);
                    
                    if (resultado.detectado) {
                        indicator.innerHTML = '<i class="fas fa-check-circle"></i> Documento detectado! (' + resultado.confianca + '%)';
                        indicator.style.backgroundColor = 'rgba(0,107,62,0.8)';
                        indicator.style.color = 'white';
                        lastDetectionTime = Date.now();
                    } else {
                        if (Date.now() - lastDetectionTime > 1000) {
                            indicator.innerHTML = '<i class="fas fa-search"></i> Posicione o documento no centro';
                            indicator.style.backgroundColor = 'rgba(0,0,0,0.6)';
                            indicator.style.color = '#00ff64';
                        }
                    }
                }
            }, 500);
        }
        
        function capturarImagemScanner() {
            const video = document.getElementById('scannerVideo');
            const canvas = document.createElement('canvas');
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const data = imageData.data;
            
            for (let i = 0; i < data.length; i += 4) {
                let r = data[i];
                let g = data[i + 1];
                let b = data[i + 2];
                
                r = Math.min(255, Math.max(0, (r - 128) * 1.3 + 128));
                g = Math.min(255, Math.max(0, (g - 128) * 1.3 + 128));
                b = Math.min(255, Math.max(0, (b - 128) * 1.3 + 128));
                
                data[i] = r;
                data[i + 1] = g;
                data[i + 2] = b;
            }
            
            ctx.putImageData(imageData, 0, 0);
            
            scannedImageData = canvas.toDataURL('image/jpeg', 0.95);
            document.getElementById('scannedImage').src = scannedImageData;
            document.querySelector('.scanner-container').style.display = 'none';
            document.getElementById('scannerResult').style.display = 'block';
            
            pararScanner();
        }
        
        function aceitarDocumento() {
            if (scannedImageData && currentTargetInput) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'POST',
                    data: { 
                        acao: 'salvar_imagem_scanner', 
                        imagem: scannedImageData,
                        tipo_documento: currentTargetInput
                    },
                    success: function(data) {
                        const result = JSON.parse(data);
                        if (result.success) {
                            $('#' + currentTargetInput).val(result.arquivo);
                            $('#' + currentTargetPreview).html('<img src="../../uploads/alunos/documentos/' + result.arquivo + '" class="document-preview" onclick="window.open(this.src)"><br><small class="text-success">Documento digitalizado com sucesso!</small>');
                            $('#modalScanner').modal('hide');
                        } else {
                            alert('Erro ao salvar documento: ' + (result.error || 'Erro desconhecido'));
                        }
                    },
                    error: function() {
                        alert('Erro ao enviar documento para o servidor');
                    }
                });
            }
        }
        
        function abrirScanner(targetInput, targetPreview) {
            currentTargetInput = targetInput;
            currentTargetPreview = targetPreview;
            scannedImageData = null;
            lastDetectionTime = 0;
            
            document.querySelector('.scanner-container').style.display = 'block';
            document.getElementById('scannerResult').style.display = 'none';
            document.getElementById('detectionIndicator').innerHTML = '<i class="fas fa-search"></i> Aguardando documento...';
            document.getElementById('detectionIndicator').style.backgroundColor = 'rgba(0,0,0,0.6)';
            
            iniciarScanner();
            $('#modalScanner').modal('show');
        }
        
        $('#recarregarCamScannerBtn').click(function() { iniciarScanner(); });
        $('#capturarScannerBtn').click(function() { capturarImagemScanner(); });
        $('#aceitarScannerBtn').click(function() { aceitarDocumento(); });
        $('#novamenteScannerBtn').click(function() { document.querySelector('.scanner-container').style.display = 'block'; document.getElementById('scannerResult').style.display = 'none'; iniciarScanner(); });
        $('#modalScanner').on('hidden.bs.modal', function() { pararScanner(); });
        
        // ============================================
        // WEBCAM PARA FOTO
        // ============================================
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const capturarBtn = document.getElementById('capturarBtn');
        const recarregarCamBtn = document.getElementById('recarregarCamBtn');
        let stream = null;
        
        function iniciarWebcam() {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
            navigator.mediaDevices.getUserMedia({ video: true })
                .then(function(mediaStream) {
                    stream = mediaStream;
                    video.srcObject = stream;
                })
                .catch(function(err) {
                    console.log("Erro ao acessar webcam: " + err);
                    alert('Não foi possível acessar a câmara. Verifique as permissões.');
                });
        }
        
        iniciarWebcam();
        
        recarregarCamBtn.addEventListener('click', function() {
            iniciarWebcam();
        });
        
        capturarBtn.addEventListener('click', function() {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            const fotoData = canvas.toDataURL('image/png');
            $('#fotoPreview').attr('src', fotoData);
            
            fetch(fotoData)
                .then(res => res.blob())
                .then(blob => {
                    const file = new File([blob], 'foto_capturada_' + Date.now() + '.png', { type: 'image/png' });
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    document.getElementById('fotoInput').files = dataTransfer.files;
                });
            
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'foto_capturada';
            hiddenInput.value = fotoData;
            document.getElementById('formAluno').appendChild(hiddenInput);
        });
        
        // ============================================
        // MODAIS - ADICIONAR ITENS
        // ============================================
        
        $('#salvarPaisBtn').click(function() {
            var novoPais = $('#novoPais').val();
            if (novoPais) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'POST',
                    data: { acao: 'add_pais', novo_pais: novoPais },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#pais_id').append('<option value="' + result.pais + '">' + result.pais + '</option>');
                            $('#pais_id').val(result.pais);
                            $('#modalNovoPais').modal('hide');
                            $('#novoPais').val('');
                        }
                    }
                });
            }
        });
        
        $('#salvarCidadeBtn').click(function() {
            var novaCidade = $('#novaCidade').val();
            var paisId = $('#pais_id').val();
            if (novaCidade && paisId) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'POST',
                    data: { acao: 'add_cidade', nova_cidade: novaCidade, pais_id: paisId },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#cidade_id').append('<option value="' + result.cidade + '">' + result.cidade + '</option>');
                            $('#cidade_id').val(result.cidade);
                            $('#modalNovaCidade').modal('hide');
                            $('#novaCidade').val('');
                        }
                    }
                });
            }
        });
        
        $('#salvarProvinciaBtn').click(function() {
            var novaProvincia = $('#novaProvincia').val();
            if (novaProvincia) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'POST',
                    data: { acao: 'add_provincia', nova_provincia: novaProvincia },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#provincia_id').append('<option value="' + result.provincia + '">' + result.provincia + '</option>');
                            $('#provincia_id').val(result.provincia);
                            $('#modalNovaProvincia').modal('hide');
                            $('#novaProvincia').val('');
                        }
                    }
                });
            }
        });
        
        $('#salvarMunicipioBtn').click(function() {
            var novoMunicipio = $('#novoMunicipio').val();
            var provinciaId = $('#provincia_id').val();
            if (novoMunicipio && provinciaId) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'POST',
                    data: { acao: 'add_municipio', novo_municipio: novoMunicipio, provincia_id: provinciaId },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#municipio_id').append('<option value="' + result.municipio + '">' + result.municipio + '</option>');
                            $('#municipio_id').val(result.municipio);
                            $('#modalNovoMunicipio').modal('hide');
                            $('#novoMunicipio').val('');
                        }
                    }
                });
            }
        });
        
        $('#salvarComunaBtn').click(function() {
            var novaComuna = $('#novaComuna').val();
            var municipioId = $('#municipio_id').val();
            if (novaComuna && municipioId) {
                $.ajax({
                    url: 'matricula.php',
                    method: 'POST',
                    data: { acao: 'add_comuna', nova_comuna: novaComuna, municipio_id: municipioId },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#comuna_id').append('<option value="' + result.comuna + '">' + result.comuna + '</option>');
                            $('#comuna_id').val(result.comuna);
                            $('#modalNovaComuna').modal('hide');
                            $('#novaComuna').val('');
                        }
                    }
                });
            }
        });
        
        $('#modalNovaCidade').on('show.bs.modal', function() {
            var paisNome = $('#pais_id option:selected').text();
            if (paisNome) $('#cidadePais').val(paisNome);
            else alert('Selecione um país primeiro!');
        });
        
        $('#modalNovoMunicipio').on('show.bs.modal', function() {
            var provinciaNome = $('#provincia_id option:selected').text();
            if (provinciaNome) $('#municipioProvincia').val(provinciaNome);
            else alert('Selecione uma província primeiro!');
        });
        
        $('#modalNovaComuna').on('show.bs.modal', function() {
            var municipioNome = $('#municipio_id option:selected').text();
            if (municipioNome) $('#comunaMunicipio').val(municipioNome);
            else alert('Selecione um município primeiro!');
        });
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('secretaria')) {
            $('#menuSecretaria').addClass('open');
            $('#submenuSecretaria').addClass('show');
        }
    </script>
</body>
</html>