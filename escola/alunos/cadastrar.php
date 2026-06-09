<?php
// escola/alunos/cadastrar.php - Cadastro de Aluno com Melhorias
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
// FUNÇÕES AUXILIARES
// ============================================

// Função para gerar número de processo automático
function gerarNumeroProcesso($conn, $escola_id, $ano_letivo) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM estudantes 
        WHERE escola_id = :escola_id 
        AND ano_letivo = :ano
    ");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] + 1;
    
    return date('Y') . '/' . str_pad($escola_id, 3, '0', STR_PAD_LEFT) . '/' . str_pad($total, 5, '0', STR_PAD_LEFT);
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
    $fonte_size = $tamanho / 3;
    
    if (file_exists($fonte)) {
        $bbox = imagettfbbox($fonte_size, 0, $fonte, $iniciais);
        $x = ($tamanho - ($bbox[2] - $bbox[0])) / 2;
        $y = ($tamanho - ($bbox[1] - $bbox[7])) / 2;
        imagettftext($imagem, $fonte_size, 0, $x, $y, $text_color, $fonte, $iniciais);
    } else {
        $x = ($tamanho - imagefontwidth(5) * strlen($iniciais)) / 2;
        $y = ($tamanho - imagefontheight(5)) / 2;
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

// ============================================
// PROCESSAR AJAX
// ============================================

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

if (isset($_GET['acao']) && $_GET['acao'] == 'get_turma') {
    $turma_id = $_GET['turma_id'] ?? 0;
    $escola_id = $_SESSION['escola_id'] ?? 0;
    
    if ($turma_id && $escola_id) {
        $stmt = $conn->prepare("SELECT id, nome, ano, turno, sala FROM turmas WHERE id = :id AND escola_id = :escola_id");
        $stmt->execute([
            ':id' => $turma_id,
            ':escola_id' => $escola_id
        ]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($turma);
    } else {
        echo json_encode([]);
    }
    exit;
}


// Buscar níveis de ensino
$sql_niveis = "SELECT id, nome, ordem FROM niveis WHERE status = '1' AND escola_id = :escola_id ORDER BY ordem ASC";
$stmt_niveis = $conn->prepare($sql_niveis);
$stmt_niveis->execute([':escola_id' => $escola_id]);
$niveis = $stmt_niveis->fetchAll(PDO::FETCH_ASSOC);

// Buscar cursos
$sql_cursos = "SELECT id, nome, codigo, duracao_anos, nivel_id FROM cursos WHERE status = 'ativo' AND escola_id = :escola_id ORDER BY nome ASC";
$stmt_cursos = $conn->prepare($sql_cursos);
$stmt_cursos->execute([':escola_id' => $escola_id]);
$cursos = $stmt_cursos->fetchAll(PDO::FETCH_ASSOC);

// Agrupar cursos por nível
$cursos_por_nivel = [];
foreach ($cursos as $curso) {
    $cursos_por_nivel[$curso['nivel_id']][] = $curso;
}



// Buscar dados da escola (incluindo WhatsApp)
$sql_escola = "SELECT id, nome, telefone, whatsapp, email, endereco, logo 
               FROM escolas 
               WHERE id = :escola_id AND status = 'ativo'";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// Número de WhatsApp da escola (quem vai enviar)
$whatsapp_escola = $escola['whatsapp'] ?? $escola['telefone'] ?? '';




/**
 * Envia mensagem via WhatsApp usando o número da escola
 * @param string $telefone_destino - Número do encarregado
 * @param string $mensagem - Mensagem a ser enviada
 * @param string $whatsapp_escola - Número do WhatsApp da escola
 * @return array - Resultado do envio
 */
function enviarWhatsAppEscola($telefone_destino, $mensagem, $whatsapp_escola) {
    // Limpar números
    $telefone_destino = limparNumeroWhatsApp($telefone_destino);
    $whatsapp_escola = limparNumeroWhatsApp($whatsapp_escola);
    
    // Opção 1: Link direto (abre WhatsApp com número da escola como remetente)
    $url = "https://wa.me/{$telefone_destino}?text=" . urlencode($mensagem);
    
    // Opção 2: Usar API do WhatsApp Business (recomendado)
    // $api_url = "https://graph.facebook.com/v17.0/{$whatsapp_escola}/messages";
    
    return [
        'success' => true,
        'url' => $url,
        'telefone_destino' => $telefone_destino,
        'whatsapp_escola' => $whatsapp_escola,
        'mensagem' => $mensagem
    ];
}

function limparNumeroWhatsApp($numero) {
    // Remover caracteres não numéricos
    $numero = preg_replace('/[^0-9]/', '', $numero);
    
    // Remover 0 inicial se tiver
    if (substr($numero, 0, 1) == '0') {
        $numero = substr($numero, 1);
    }
    
    // Adicionar código de Angola se não tiver
    if (substr($numero, 0, 3) != '244') {
        $numero = '244' . $numero;
    }
    
    return $numero;
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
    $curso = $_POST['curso_id'] ?? '';
    $nivel = $_POST['nivel_id'] ?? '';
    $turno_id = $_POST['turno'] ?? '';
    $sala_id = $_POST['sala'] ?? '';
    
    // Upload da Foto
    $foto = null;
    $foto_capturada = $_POST['foto_capturada'] ?? '';
    
    if (!empty($foto_capturada)) {
        // Processar foto capturada pela webcam
        $foto_data = explode(',', $foto_capturada);
        if (count($foto_data) > 1) {
            $foto_decodificada = base64_decode($foto_data[1]);
            $foto_dir = __DIR__ . '/../../uploads/alunos/fotos/';
            if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
            $foto = 'foto_' . time() . '_' . uniqid() . '.png';
            file_put_contents($foto_dir . $foto, $foto_decodificada);
        }
    } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $foto = uploadArquivoAluno($_FILES['foto'], __DIR__ . '/../../uploads/alunos/fotos/', ['jpg','jpeg','png','gif','webp'], 2097152);
    }
    
    // Upload de Documentos
    $upload_dir_docs = __DIR__ . '/../../uploads/alunos/documentos/';
    $bi_documento = uploadArquivoAluno($_FILES['bi_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    $certificado_documento = uploadArquivoAluno($_FILES['certificado_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    $atestado_documento = uploadArquivoAluno($_FILES['atestado_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    $declaracao_documento = uploadArquivoAluno($_FILES['declaracao_documento'] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf'], 2097152);
    
    $outros_documentos = [];
    for ($i = 1; $i <= 3; $i++) {
        $doc = uploadArquivoAluno($_FILES["outro_documento_$i"] ?? null, $upload_dir_docs, ['jpg','jpeg','png','pdf','doc','docx'], 5242880);
        if ($doc) {
            $outros_documentos[] = $doc;
        }
    }
    $outros_documentos_json = json_encode($outros_documentos);
    
    // Gerar número de processo automático
    $numero_processo = gerarNumeroProcesso($conn, $escola_id, $ano_letivo);
    
    // Credenciais de acesso: usuário = número de processo, senha = BI
    $usuario_acesso = $numero_processo;
    $senha_acesso = !empty($bi_numero) ? $bi_numero : substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
    $senha_hash = password_hash($senha_acesso, PASSWORD_DEFAULT);
    $email_usuario = $email ?: $numero_processo . '@aluno.sige.ao';
    
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
        $stmt = $conn->prepare("SELECT ano, turno, sala,nome FROM turmas WHERE id = :id and escola_id=:escola_id");
        $stmt->execute([
            ':id' => $turma_id,
            ':escola_id' => $escola_id
            ]);
        $turma_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $ano_escolar = $turma_dados['ano'] ?? '';
    $turno = $turma_dados['turno'] ?? '';
    $sala = $turma_dados['sala'] ?? '';
    $turma_nome = $turma_dados['nome'] ?? '';
    
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
        
        // Verificar se número de processo já existe
        $stmt = $conn->prepare("SELECT id FROM estudantes WHERE numero_processo = :processo AND escola_id = :escola_id");
        $stmt->execute([':processo' => $numero_processo, ':escola_id' => $escola_id]);
        if ($stmt->fetch()) {
            throw new Exception("Número de processo já existe.");
        }
        
        // Criar usuário
        $stmt = $conn->prepare("
            INSERT INTO usuarios (escola_id, nome,usuario, email, senha, tipo, telefone, status, created_at)
            VALUES (:escola_id, :nome,:usuario, :email, :senha, 'aluno', :telefone, 'ativo', NOW())
        ");
        $stmt->execute([
            ':escola_id' => $escola_id,
            ':nome' => $nome_completo,
            ':usuario' => $numero_processo,
            ':email' => $email_usuario,
            ':senha' => $senha_hash,
            ':telefone' => $telefone
        ]);
        $usuario_id = $conn->lastInsertId();
        
        // Inserir estudante
        $stmt = $conn->prepare("
            INSERT INTO estudantes (
                usuario_id, escola_id, matricula,nome,senha,bi,bi_data_emissao, bi_local_emissao,
                pais_id, pais_nome, cidade_id, cidade_nome,
                provincia_id, provincia_nome, municipio_id, municipio_nome, comuna_id, comuna_nome,
                endereco, telefone, email, data_nascimento, genero, foto,
                pai_nome, pai_bi, pai_telefone, pai_profissao,
                mae_nome, mae_bi, mae_telefone, mae_profissao,
                encarregado_nome, encarregado_parentesco, encarregado_bi,
                encarregado_telefone, encarregado_email, encarregado_endereco,
                ano_letivo, ano_escolar,classe, curso, nivel,
                numero_processo, bi_documento, certificado_documento,
                atestado_documento, outros_documentos, declaracao_documento, created_at
            ) VALUES (
                :usuario_id, :escola_id, :matricula,:nome,:senha, :bi, :bi_data_emissao, :bi_local_emissao,
                :pais_id, :pais_nome, :cidade_id, :cidade_nome,
                :provincia_id, :provincia_nome, :municipio_id, :municipio_nome, :comuna_id, :comuna_nome,
                :endereco, :telefone, :email, :data_nascimento, :genero, :foto,
                :pai_nome, :pai_bi, :pai_telefone, :pai_profissao,
                :mae_nome, :mae_bi, :mae_telefone, :mae_profissao,
                :encarregado_nome, :encarregado_parentesco, :encarregado_bi,
                :encarregado_telefone, :encarregado_email, :encarregado_endereco,
                :ano_letivo, :ano_escolar,:classe, :curso, :nivel,
                :numero_processo, :bi_documento, :certificado_documento,
                :atestado_documento, :outros_documentos, :declaracao_documento, NOW()
            )
        ");
        
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':escola_id' => $escola_id,
            ':matricula' => $numero_processo,
            ':nome' => $nome_completo,
            ':senha' => $senha_hash,
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
            ':classe' => $ano_escolar ?: null,
            ':curso' => $curso ?: null,
            ':nivel' => $nivel ?: null,
            ':numero_processo' => $numero_processo,
            ':bi_documento' => $bi_documento,
            ':certificado_documento' => $certificado_documento,
            ':atestado_documento' => $atestado_documento,
            ':outros_documentos' => $outros_documentos_json,
            ':declaracao_documento' => $declaracao_documento
        ]);
        
        $estudante_id = $conn->lastInsertId();
        
        // Matricular na turma
        if ($turma_id) {
            $stmt = $conn->prepare("
                INSERT INTO matriculas (estudante_id, turma_id,turno,curso,sala,classe, ano_letivo, numero_processo, status, data_matricula, created_at)
                VALUES (:estudante_id, :turma_id,:turno,:curso,:sala,:classe,:ano_letivo, :numero_matricula, 'ativa', CURDATE(), NOW())
            ");
            $stmt->execute([
                ':estudante_id' => $estudante_id,
                ':turma_id' => $turma_id,
                 ':turno' => $turno_id ?: null,
                 ':curso' => $curso ?: null,
                 ':sala' => $sala_id ?: null,
                 ':classe' => $ano_escolar ?: null,
                ':ano_letivo' => $ano_letivo,
                ':numero_matricula' => $numero_processo
            ]);
        }
        
        $conn->commit();
        
        $success = "Aluno cadastrado com sucesso!<br>
                    Nº Processo: <strong>{$numero_processo}</strong><br>
                    Usuário de acesso: <strong>{$usuario_acesso}</strong><br>
                    Senha de acesso: <strong>{$senha_acesso}</strong><br>
                    E-mail alternativo: <strong>{$email_usuario}</strong>";

                   
// Chamar a função após salvar o aluno
 $documentos_salvos = processarDocumentos($conn, $estudante_id, $escola_id);
 
                            
// Após salvar o cadastro com sucesso

    // Dados do cadastro
    //$numero_processo = '2024' . str_pad($estudante_id, 6, '0', STR_PAD_LEFT);
    $usuario_acesso = $usuario_acesso;
    $senha_acesso = $senha_acesso;
    $email_usuario = $email_usuario;
    
    // Dados do encarregado
    $encarregado_nome = $_POST['encarregado_nome'];
    $encarregado_telefone = $_POST['encarregado_telefone'];
    $aluno_nome = $_POST['nome_completo'];
    $turma_nome = $turma_nome ?? 'Não atribuída';
    
    // Número do WhatsApp da escola (quem envia)
    $whatsapp_escola = $escola['whatsapp'] ?? '';
    
    // Construir mensagem personalizada (enviada DO número da escola)
    $mensagem = "🏫 *" . strtoupper($escola['nome']) . "* - CADASTRO REALIZADO!\n\n";
    $mensagem .= "Olá *" . strtoupper($encarregado_nome) . "*,\n\n";
    $mensagem .= "Seu educando(a) *{$aluno_nome}* foi cadastrado(a) com sucesso em nossa instituição.\n\n";
    $mensagem .= "📋 *Dados do Cadastro:*\n";
    $mensagem .= "├ Nº Processo: *{$numero_processo}*\n";
    $mensagem .= "├ Matrícula: *{$estudante_id}*\n";
    $mensagem .= "├ Turma: *{$turma_nome}*\n";
    $mensagem .= "└ Ano Letivo: *" . date('Y') . "*\n\n";
    $mensagem .= "🔐 *Credenciais de Acesso ao Portal do Aluno:*\n";
    $mensagem .= "├ Usuário: *{$usuario_acesso}*\n";
    $mensagem .= "├ Senha: *{$senha_acesso}*\n";
    $mensagem .= "└ E-mail: *{$email_usuario}*\n\n";
    $mensagem .= "📌 *Acesse o sistema:*\n";
    $mensagem .= "🌐 " . $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . "/sige_Plataforma/escola/aluno/login.php\n\n";
    $mensagem .= "🔐 *Recomendamos alterar a senha no primeiro acesso.*\n\n";
    $mensagem .= "📞 *Dúvidas?* Entre em contato conosco:\n";
    $mensagem .= "├ Telefone: {$escola['telefone']}\n";
    $mensagem .= "├ WhatsApp: {$whatsapp_escola}\n";
    $mensagem .= "└ E-mail: {$escola['email']}\n\n";
    $mensagem .= "🍀 *Bem-vindo(a) à família " . $escola['nome'] . "!*";
    
    // Enviar WhatsApp (do número da escola para o encarregado)
    if (!empty($encarregado_telefone)) {
        $resultado_whatsapp = enviarWhatsAppEscola($encarregado_telefone, $mensagem, $whatsapp_escola);
        
        // Mensagem de sucesso na tela
        $success = "Aluno cadastrado com sucesso!<br>
                    Nº Processo: <strong>{$numero_processo}</strong><br>
                    Usuário de acesso: <strong>{$usuario_acesso}</strong><br>
                    Senha de acesso: <strong>{$senha_acesso}</strong><br>
                    E-mail alternativo: <strong>{$email_usuario}</strong>";
        
        // Botão para enviar WhatsApp (usando número da escola)
        $success .= "<br><br>
                    <div class='alert alert-info'>
                        <i class='fab fa-whatsapp'></i> 
                        Uma mensagem será enviada do WhatsApp da escola para o número do encarregado.
                    </div>
                    <a href='{$resultado_whatsapp['url']}' target='_blank' class='btn btn-success'>
                        <i class='fab fa-whatsapp'></i> Enviar Credenciais via WhatsApp
                    </a>
                    <button onclick='copiarCredenciais()' class='btn btn-secondary ms-2'>
                        <i class='fas fa-copy'></i> Copiar Credenciais
                    </button>";
    }





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


// Processar documentos do aluno
function processarDocumentos($conn, $aluno_id, $escola_id) {
    $documentos = [
        'bi_documento' => 'BI / Documento de Identificação',
        'certificado_documento' => 'Certificado de Conclusão / Histórico',
        'atestado_documento' => 'Atestado Médico',
        'declaracao_documento' => 'Declaração de Matrícula',
        'outro_documento_1' => 'Outro Documento 1',
        'outro_documento_2' => 'Outro Documento 2',
        'outro_documento_3' => 'Outro Documento 3'
    ];
    
    $upload_dir = __DIR__ . '/../../uploads/documentos_aluno/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $documentos_salvos = [];
    
    foreach ($documentos as $campo => $nome_documento) {
        if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES[$campo];
            $extensao = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $tamanho = $file['size'];
            
            // Validar extensão
            $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            if (!in_array($extensao, $extensoes_permitidas)) {
                continue;
            }
            
            // Validar tamanho (max 5MB)
            if ($tamanho > 5 * 1024 * 1024) {
                continue;
            }
            
            // Gerar nome único
            $nome_arquivo = time() . '_' . $aluno_id . '_' . $campo . '.' . $extensao;
            $caminho = 'uploads/documentos_aluno/' . $nome_arquivo;
            $caminho_completo = $upload_dir . $nome_arquivo;
            
            // Mover arquivo
            if (move_uploaded_file($file['tmp_name'], $caminho_completo)) {
                // Verificar se já existe documento deste tipo
                $sql_check = "SELECT id FROM documentos_aluno 
                              WHERE aluno_id = :aluno_id AND tipo = :tipo AND escola_id = :escola_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':aluno_id' => $aluno_id,
                    ':tipo' => $campo,
                    ':escola_id' => $escola_id
                ]);
                
                if ($stmt_check->rowCount() > 0) {
                    // Atualizar documento existente
                    $sql = "UPDATE documentos_aluno 
                            SET nome = :nome, caminho = :caminho, tamanho = :tamanho, 
                                extensao = :extensao, data_upload = NOW(), status = 'ativo'
                            WHERE aluno_id = :aluno_id AND tipo = :tipo AND escola_id = :escola_id";
                } else {
                    // Inserir novo documento
                    $sql = "INSERT INTO documentos_aluno (escola_id, aluno_id, nome, tipo, caminho, tamanho, extensao, data_upload, status) 
                            VALUES (:escola_id, :aluno_id, :nome, :tipo, :caminho, :tamanho, :extensao, NOW(), 'ativo')";
                }
                
                $stmt = $conn->prepare($sql);
                $params = [
                    ':escola_id' => $escola_id,
                    ':aluno_id' => $aluno_id,
                    ':nome' => $nome_documento,
                    ':tipo' => $campo,
                    ':caminho' => $caminho,
                    ':tamanho' => $tamanho,
                    ':extensao' => $extensao
                ];
                
                if ($stmt_check->rowCount() > 0) {
                    unset($params[':escola_id']);
                    unset($params[':nome']);
                    $params[':tipo'] = $campo;
                }
                
                $stmt->execute($params);
                $documentos_salvos[] = $nome_documento;
            }
        }
    }
    
    return $documentos_salvos;
}

// Chamar a função após salvar o aluno
// $documentos_salvos = processarDocumentos($conn, $aluno_id, $escola_id);




?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
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
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .preview-img { width: 150px; height: 150px; object-fit: cover; border-radius: 10px; border: 2px solid #006B3E; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .webcam-container { position: relative; }
        #video { width: 100%; max-width: 400px; border-radius: 10px; border: 2px solid #006B3E; background: #000; }
        .document-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; }
        .badge-turma { background: #17a2b8; }
        .alert-no-turmas { background: #fff3cd; border-left: 4px solid #ffc107; }

        .list-group-item {
    cursor: pointer;
    transition: all 0.3s;
}

.list-group-item:hover {
    background-color: #e8f5e9;
    transform: translateX(5px);
}

.list-group-item-action:active {
    background-color: #c8e6c9;
}
    </style>
    <style>
.document-frame {
    position: absolute;
    border: 3px solid #00ff00;
    box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
    border-radius: 4px;
    transition: all 0.3s;
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { border-color: #00ff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); }
    50% { border-color: #ffff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.6); }
    100% { border-color: #00ff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); }
}

#scannerVideo {
    transform-origin: center center;
    transition: transform 0.3s;
}

.cropper-view-box,
.cropper-face {
    border-radius: 4px;
}

.cropper-line {
    background-color: #006B3E;
}

.cropper-point {
    background-color: #006B3E;
}

.cropper-dashed {
    border-color: #006B3E;
}
</style>

<style>
select:disabled {
    background-color: #e9ecef;
    cursor: not-allowed;
}

#curso_id option[value=""] {
    color: #6c757d;
}

.loading-cursos {
    background-image: url('data:image/svg+xml,...');
    background-repeat: no-repeat;
    background-position: right 10px center;
}
</style>


</head>
<body>
    <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-plus"></i> Cadastrar Aluno  </h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
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
                    Por favor, entre em contato com a área académica para cadastrar as turmas antes de matricular alunos.
                    <a href="../turmas/cadastrar.php" class="btn btn-sm btn-warning mt-2">Cadastrar Turma</a>
                </div>
                <?php endif; ?>
                
                <form method="POST" enctype="multipart/form-data" id="formAluno">
                    <input type="hidden" name="cadastrar_aluno" value="1">
                    
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
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> Se o encarregado for diferente dos pais, preencha os dados abaixo ou selecione um existente.
    </div>
    
    <!-- Botão para selecionar responsável -->
    <div class="mb-3">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalSelecionarResponsavel">
            <i class="fas fa-search"></i> Selecionar Encarregado Existente
        </button>
        <button type="button" class="btn btn-outline-secondary" id="btnLimparResponsavel">
            <i class="fas fa-eraser"></i> Limpar
        </button>
        <small class="text-muted ms-2">Clique para buscar um responsável já cadastrado</small>
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label>Nome do Encarregado</label>
                <input type="text" name="encarregado_nome" id="encarregado_nome" class="form-control">
                <input type="hidden" name="encarregado_id" id="encarregado_id" value="">
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <label>Parentesco</label>
                <select name="encarregado_parentesco" id="encarregado_parentesco" class="form-control">
                    <option value="">Selecione...</option>
                    <option value="Pai">Pai</option>
                    <option value="Mãe">Mãe</option>
                    <option value="Tio">Tio</option>
                    <option value="Tia">Tia</option>
                    <option value="Avô">Avô</option>
                    <option value="Avó">Avó</option>
                    <option value="Irmão">Irmão</option>
                    <option value="Irmã">Irmã</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <div class="mb-3">
                <label>BI do Encarregado</label>
                <input type="text" name="encarregado_bi" id="encarregado_bi" class="form-control">
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label>Telefone</label>
                <input type="text" name="encarregado_telefone" id="encarregado_telefone" class="form-control">
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-3">
                <label>E-mail</label>
                <input type="email" name="encarregado_email" id="encarregado_email" class="form-control">
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <div class="mb-3">
                <label>Endereço do Encarregado</label>
                <textarea name="encarregado_endereco" id="encarregado_endereco" class="form-control" rows="2"></textarea>
            </div>
        </div>
    </div>
</div>
                        
                        <!-- Dados Académicos -->
                        <div class="tab-pane fade" id="academicos">
                            <?php if ($tem_turmas): ?>
                            <div class="row">
                                <div class="col-md-6">
                                  <div class="mb-3">
    <label class="required">Ano Letivo</label>
    <select name="ano_letivo" class="form-control" required>
        <option value="">Selecione o ano letivo</option>
        <?php
        // Buscar anos letivos da tabela ano_letivo
        try {
            $sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo= 1 ORDER BY ano DESC";
            $stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
            $stmt_ano_letivo->execute();
            $anos_letivos = $stmt_ano_letivo->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($anos_letivos)) {
                // Fallback: Se não houver registros, buscar todos
                $sql_ano_letivo = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
                $stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
                $stmt_ano_letivo->execute();
                $anos_letivos = $stmt_ano_letivo->fetchAll(PDO::FETCH_ASSOC);
            }
            
            foreach ($anos_letivos as $ano_reg): ?>
                <option value="<?php echo $ano_reg['id']; ?>">
                    <?php echo htmlspecialchars($ano_reg['ano'] ?: 'Ano Letivo ' . $ano_reg['ano']); ?> (<?php echo $ano_reg['ano']; ?>)
                </option>
            <?php 
            endforeach;
        } catch (PDOException $e) {
            // Fallback: Se a tabela não existir, mostrar anos fixos
            for ($i = date('Y'); $i <= date('Y') + 5; $i++): ?>
                <option value="<?php echo $i; ?>" <?php echo $i == date('Y') ? 'selected' : ''; ?>>
                    Ano Letivos <?php echo $i; ?> (<?php echo $i; ?>)
                </option>
            <?php 
            endfor;
        }
        ?>
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
            <label>Nível de Ensino</label>
            <select name="nivel_id" id="nivel_id" class="form-control" required>
                <option value="">Selecione o nível de ensino...</option>
                <?php foreach ($niveis as $nivel): ?>
                <option value="<?php echo $nivel['id']; ?>" 
                        data-nome="<?php echo htmlspecialchars($nivel['nome']); ?>">
                    <?php echo htmlspecialchars($nivel['nome']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label>Curso</label>
            <select name="curso_id" id="curso_id" class="form-control" disabled>
                <option value="">Primeiro selecione o nível...</option>
            </select>
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
    <div class="alert alert-info mb-3">
        <i class="fas fa-info-circle"></i> 
        Documentos enviados ficarão disponíveis na ficha do aluno. 
        Formatos aceitos: JPG, PNG, PDF (Máx: 5MB)
    </div>
    
    <div class="row">
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                    <h6>BI / Documento de Identificação</h6>
                    <input type="file" name="bi_documento" id="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                    <div id="bi_preview" class="mt-2"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('bi_documento', 'bi_preview', 'bi')">
                        <i class="fas fa-camera"></i> Scanner BI
                    </button>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <i class="fas fa-certificate fa-3x text-success mb-2"></i>
                    <h6>Certificado de Conclusão / Histórico</h6>
                    <input type="file" name="certificado_documento" id="certificado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'certificado_preview')">
                    <div id="certificado_preview" class="mt-2"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('certificado_documento', 'certificado_preview', 'certificado')">
                        <i class="fas fa-camera"></i> Scanner Certificado
                    </button>
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
                    <input type="file" name="atestado_documento" id="atestado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'atestado_preview')">
                    <div id="atestado_preview" class="mt-2"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('atestado_documento', 'atestado_preview', 'atestado')">
                        <i class="fas fa-camera"></i> Scanner Atestado
                    </button>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                    <h6>Declaração de Matrícula</h6>
                    <input type="file" name="declaracao_documento" id="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'declaracao_preview')">
                    <div id="declaracao_preview" class="mt-2"></div>
                    <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('declaracao_documento', 'declaracao_preview', 'declaracao')">
                        <i class="fas fa-camera"></i> Scanner Declaração
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <h6 class="mt-3">Outros Documentos</h6>
    <div class="row" id="outros-documentos">
        <div class="col-md-4">
            <div class="mb-2">
                <input type="file" name="outro_documento_1" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro1_preview')">
                <div id="outro1_preview" class="mt-1"></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-2">
                <input type="file" name="outro_documento_2" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro2_preview')">
                <div id="outro2_preview" class="mt-1"></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="mb-2">
                <input type="file" name="outro_documento_3" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro3_preview')">
                <div id="outro3_preview" class="mt-1"></div>
            </div>
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
                            <i class="fas fa-save"></i> Cadastrar Aluno
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>





<!-- Modal para Selecionar Responsável -->
<div class="modal fade" id="modalSelecionarResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-users"></i> Selecionar Encarregado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Busca -->
                <div class="row mb-3">
                    <div class="col-md-8">
                        <input type="text" id="buscarResponsavel" class="form-control" placeholder="Buscar por nome, BI, telefone ou email...">
                    </div>
                    <div class="col-md-4">
                        <button class="btn btn-primary w-100" onclick="buscarResponsaveis()">
                            <i class="fas fa-search"></i> Buscar
                        </button>
                    </div>
                </div>
                
                <!-- Lista de Responsáveis -->
                <div id="listaResponsaveis" style="max-height: 400px; overflow-y: auto;">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-search fa-2x mb-2"></i>
                        <p>Digite um termo para buscar responsáveis</p>
                    </div>
                </div>
                
                <!-- Botão para criar novo -->
                <hr>
                <div class="text-center">
                    <button type="button" class="btn btn-outline-success" onclick="abrirModalNovoResponsavel()">
                        <i class="fas fa-plus-circle"></i> Cadastrar Novo Encarregado
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Cadastrar Novo Responsável -->
<div class="modal fade" id="modalNovoResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Cadastrar Novo Encarregado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formNovoResponsavel" method="POST">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Nome Completo *</label>
                                <input type="text" name="nome" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Parentesco *</label>
                                <select name="parentesco" class="form-control" required>
                                    <option value="">Selecione...</option>
                                    <option value="Pai">Pai</option>
                                    <option value="Mãe">Mãe</option>
                                    <option value="Tio">Tio</option>
                                    <option value="Tia">Tia</option>
                                    <option value="Avô">Avô</option>
                                    <option value="Avó">Avó</option>
                                    <option value="Irmão">Irmão</option>
                                    <option value="Irmã">Irmã</option>
                                    <option value="Outro">Outro</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>BI / NIF</label>
                                <input type="text" name="bi" class="form-control" placeholder="BI ou NIF">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Telefone Principal *</label>
                                <input type="text" name="telefone" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Telefone Alternativo</label>
                                <input type="text" name="telefone2" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Profissão</label>
                                <input type="text" name="profissao" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Estado Civil</label>
                                <select name="estado_civil" class="form-control">
                                    <option value="">Selecione</option>
                                    <option value="Solteiro">Solteiro(a)</option>
                                    <option value="Casado">Casado(a)</option>
                                    <option value="Divorciado">Divorciado(a)</option>
                                    <option value="Viúvo">Viúvo(a)</option>
                                    <option value="União Estável">União Estável</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Província</label>
                                <input type="text" name="provincia" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Município</label>
                                <input type="text" name="municipio" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Bairro</label>
                                <input type="text" name="bairro" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Endereço Completo</label>
                        <textarea name="endereco" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Observações</label>
                        <textarea name="observacoes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-success">Salvar e Selecionar</button>
                </div>
            </form>
        </div>
    </div>
</div>




<!-- Modal para Scanner de Documento -->
<div class="modal fade" id="modalScanner" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-camera"></i> Scanner de Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-8">
                        <div class="position-relative">
                            <video id="scannerVideo" width="100%" height="auto" autoplay style="background: #000; border-radius: 8px;"></video>
                            <canvas id="scannerCanvas" style="display: none;"></canvas>
                            <div id="scannerOverlay" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; pointer-events: none;">
                                <div class="document-frame" style="position: absolute; top: 15%; left: 10%; width: 80%; height: 70%; border: 3px solid #00ff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); border-radius: 4px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <h6>Preview</h6>
                                <div id="previewCaptura" style="min-height: 150px; background: #f8f9fa; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                    <i class="fas fa-camera fa-3x text-muted"></i>
                                </div>
                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary w-100 mb-2" onclick="tirarFotoScanner()">
                                        <i class="fas fa-camera"></i> Tirar Foto
                                    </button>
                                    <button type="button" class="btn btn-secondary w-100 mb-2" onclick="ajustarDocumento()">
                                        <i class="fas fa-crop-alt"></i> Ajustar Documento
                                    </button>
                                    <select id="documentoFormato" class="form-control form-control-sm mb-2" onchange="ajustarFrameDocumento()">
                                        <option value="A4">A4 (210x297mm)</option>
                                        <option value="A5">A5 (148x210mm)</option>
                                        <option value="Carta">Carta (216x279mm)</option>
                                        <option value="BI">BI/Documento</option>
                                        <option value="Certificado">Certificado</option>
                                    </select>
                                    <div class="btn-group w-100">
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="zoomIn()">
                                            <i class="fas fa-search-plus"></i> Zoom +
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="zoomOut()">
                                            <i class="fas fa-search-minus"></i> Zoom -
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-success" onclick="confirmarCaptura()">
                    <i class="fas fa-check"></i> Confirmar e Salvar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Ajuste Manual do Documento -->
<div class="modal fade" id="modalAjuste" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-crop-alt"></i> Ajustar Documento</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div style="position: relative; display: inline-block;">
                    <img id="imagemAjuste" src="" style="max-width: 100%; max-height: 500px; cursor: crosshair;">
                    <canvas id="canvasAjuste" style="display: none;"></canvas>
                </div>
                <div class="mt-3">
                    <p>Clique e arraste para selecionar a área do documento</p>
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="girarImagem()">
                            <i class="fas fa-undo-alt"></i> Girar
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="aplicarFiltro()">
                            <i class="fas fa-magic"></i> Melhorar
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="resetarAjuste()">
                            <i class="fas fa-undo"></i> Resetar
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="aplicarCorte()">Aplicar Corte</button>
            </div>
        </div>
    </div>
</div>



    
    <!-- Modal Novo País -->
    <div class="modal fade" id="modalNovoPais" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Adicionar Novo País</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body"><input type="text" id="novoPais" class="form-control" placeholder="Nome do País"></div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarPaisBtn">Salvar</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Cidade -->
    <div class="modal fade" id="modalNovaCidade" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Adicionar Nova Cidade</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3"><label>País</label><input type="text" id="cidadePais" class="form-control" readonly></div>
                    <div class="mb-3"><label>Nome da Cidade</label><input type="text" id="novaCidade" class="form-control"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarCidadeBtn">Salvar</button></div>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Província -->
    <div class="modal fade" id="modalNovaProvincia" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><input type="text" id="novaProvincia" class="form-control" placeholder="Nome da Província"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div>
    </div>
    
    <!-- Modal Novo Município -->
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Novo Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="mb-3"><label>Província</label><input type="text" id="municipioProvincia" class="form-control" readonly></div><div class="mb-3"><label>Nome do Município</label><input type="text" id="novoMunicipio" class="form-control"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div>
    </div>
    
    <!-- Modal Nova Comuna -->
    <div class="modal fade" id="modalNovaComuna" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="mb-3"><label>Município</label><input type="text" id="comunaMunicipio" class="form-control" readonly></div><div class="mb-3"><label>Nome da Comuna</label><input type="text" id="novaComuna" class="form-control"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
     <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Adicionar no head -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>


<script>
function copiarCredenciais() {
    const credenciais = `Usuário: <?php echo $usuario_acesso; ?>\nSenha: <?php echo $senha_acesso; ?>\nProcesso: <?php echo $numero_processo; ?>`;
    navigator.clipboard.writeText(credenciais);
    
    // Mostrar notificação
    Swal.fire({
        icon: 'success',
        title: 'Copiado!',
        text: 'Credenciais copiadas para a área de transferência',
        timer: 2000,
        showConfirmButton: false
    });
}
</script>



<script>
// Versão melhorada com mais funcionalidades
document.getElementById('nivel_id').addEventListener('change', function() {
    const nivelId = this.value;
    const nivelSelect = this;
    const cursoSelect = document.getElementById('curso_id');
    const nivelNome = nivelSelect.options[nivelSelect.selectedIndex]?.text || '';
    
    // Reset curso select
    cursoSelect.innerHTML = '<option value="">Carregando...</option>';
    cursoSelect.disabled = true;
    
    if (!nivelId) {
        cursoSelect.innerHTML = '<option value="">Selecione um nível primeiro</option>';
        return;
    }
    
    // Verificar se o nível requer curso
    const tiposSemCurso = ['infantil', 'primario', 'alfabetizacao'];
    const nivelTipo = nivelSelect.options[nivelSelect.selectedIndex]?.getAttribute('data-tipo') || '';
    
    if (tiposSemCurso.includes(nivelTipo) || nivelNome.toLowerCase().includes('iniciação') || nivelNome.toLowerCase().includes('primário')) {
        cursoSelect.innerHTML = '<option value="">Este nível não requer curso específico</option>';
        cursoSelect.disabled = true;
        
        // Adicionar campo hidden com valor 0
        if (!document.getElementById('curso_default')) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'curso_id';
            hidden.id = 'curso_default';
            hidden.value = '0';
            cursoSelect.parentNode.appendChild(hidden);
        }
        return;
    }
    
    // Remover hidden se existir
    const hiddenDefault = document.getElementById('curso_default');
    if (hiddenDefault) hiddenDefault.remove();
    
    // Buscar cursos via fetch
    fetch(`get_cursos.php?nivel_id=${nivelId}`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                cursoSelect.innerHTML = '<option value="">Erro ao carregar cursos</option>';
                return;
            }
            
            if (data.cursos.length === 0) {
                cursoSelect.innerHTML = '<option value="">Nenhum curso disponível para este nível</option>';
                cursoSelect.disabled = true;
                return;
            }
            
            let html = '<option value="">Selecione o curso...</option>';
            data.cursos.forEach(curso => {
                html += `<option value="${curso.id}" data-duracao="${curso.duracao_anos || ''}">
                            ${curso.codigo ? '[' + curso.codigo + '] ' : ''}${curso.nome}
                            ${curso.duracao_anos ? ' (' + curso.duracao_anos + ' anos)' : ''}
                        </option>`;
            });
            cursoSelect.innerHTML = html;
            cursoSelect.disabled = false;
        })
        .catch(error => {
            console.error('Error:', error);
            cursoSelect.innerHTML = '<option value="">Erro ao carregar cursos</option>';
        });
});

// Quando selecionar um curso, pode preencher outros campos
document.getElementById('curso_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const duracao = selectedOption?.getAttribute('data-duracao');
    
    if (duracao && document.getElementById('duracao_curso')) {
        document.getElementById('duracao_curso').value = duracao;
    }
});
</script>


<script>
let scannerStream = null;
let fotoCapturada = null;
let currentInputId = null;
let currentPreviewId = null;
let currentDocumentoNome = null;
let currentDocumentoTipo = null;
let cropper = null;
let currentZoom = 1;
let rotacaoAtual = 0;

// Configurações dos documentos
const documentoTipos = {
    'bi': {
        nome: 'BI_Documento',
        inputId: 'bi_documento',
        previewId: 'bi_preview',
        formatos: ['jpg', 'jpeg', 'png', 'pdf'],
        tamanhoMax: 5, // MB
        orientacao: 'retrato',
        resolucao: '300dpi'
    },
    'certificado': {
        nome: 'Certificado',
        inputId: 'certificado_documento',
        previewId: 'certificado_preview',
        formatos: ['jpg', 'jpeg', 'png', 'pdf'],
        tamanhoMax: 5,
        orientacao: 'paisagem',
        resolucao: '300dpi'
    },
    'atestado': {
        nome: 'Atestado',
        inputId: 'atestado_documento',
        previewId: 'atestado_preview',
        formatos: ['jpg', 'jpeg', 'png', 'pdf'],
        tamanhoMax: 5,
        orientacao: 'retrato',
        resolucao: '200dpi'
    },
    'declaracao': {
        nome: 'Declaracao',
        inputId: 'declaracao_documento',
        previewId: 'declaracao_preview',
        formatos: ['jpg', 'jpeg', 'png', 'pdf'],
        tamanhoMax: 5,
        orientacao: 'retrato',
        resolucao: '200dpi'
    }
};

// Abrir scanner com base no tipo de documento
function capturarDocumento(inputId, previewId, tipoDocumento) {
    currentInputId = inputId;
    currentPreviewId = previewId;
    currentDocumentoTipo = tipoDocumento;
    currentDocumentoNome = documentoTipos[tipoDocumento]?.nome || 'documento';
    
    // Configurar frame conforme o tipo de documento
    const modal = new bootstrap.Modal(document.getElementById('modalScanner'));
    modal.show();
    
    iniciarCamera();
    
    // Ajustar frame conforme orientação do documento
    setTimeout(() => {
        ajustarFramePorTipo(tipoDocumento);
    }, 500);
}

// Ajustar frame conforme o tipo de documento
function ajustarFramePorTipo(tipo) {
    const config = documentoTipos[tipo];
    if (!config) return;
    
    const frame = document.querySelector('.document-frame');
    if (!frame) return;
    
    if (config.orientacao === 'paisagem') {
        frame.style.width = '85%';
        frame.style.height = '60%';
        frame.style.left = '7.5%';
        frame.style.top = '20%';
    } else {
        frame.style.width = '60%';
        frame.style.height = '85%';
        frame.style.left = '20%';
        frame.style.top = '7.5%';
    }
}

// Iniciar câmera
async function iniciarCamera() {
    const video = document.getElementById('scannerVideo');
    
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Seu navegador não suporta acesso à câmera');
        }
        
        if (scannerStream) {
            scannerStream.getTracks().forEach(track => track.stop());
        }
        
        const constraints = {
            video: {
                width: { ideal: 1920 },
                height: { ideal: 1080 },
                facingMode: { exact: "environment" }
            }
        };
        
        try {
            scannerStream = await navigator.mediaDevices.getUserMedia(constraints);
        } catch (err) {
            scannerStream = await navigator.mediaDevices.getUserMedia({ video: true });
        }
        
        video.srcObject = scannerStream;
        await video.play();
        
    } catch (err) {
        console.error('Erro ao acessar câmera:', err);
        alert('Erro ao acessar a câmera: ' + err.message);
        const modal = bootstrap.Modal.getInstance(document.getElementById('modalScanner'));
        if (modal) modal.hide();
    }
}

// Tirar foto
function tirarFotoScanner() {
    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    const context = canvas.getContext('2d');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    context.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    // Aplicar correções específicas para o tipo de documento
    aplicarCorrecoesEspecificas(canvas);
    
    fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
    
    const previewDiv = document.getElementById('previewCaptura');
    previewDiv.innerHTML = `<img src="${fotoCapturada}" style="max-width: 100%; max-height: 150px; border-radius: 8px;">`;
    
    const btnTirarFoto = document.querySelector('#modalScanner .btn-primary');
    if (btnTirarFoto) {
        btnTirarFoto.innerHTML = '<i class="fas fa-check"></i> Foto tirada!';
        setTimeout(() => {
            btnTirarFoto.innerHTML = '<i class="fas fa-camera"></i> Tirar Foto';
        }, 2000);
    }
}

// Aplicar correções específicas para cada tipo de documento
function aplicarCorrecoesEspecificas(canvas) {
    const ctx = canvas.getContext('2d');
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    
    let brightness = 20;
    let contrast = 30;
    
    // Ajustes específicos por tipo de documento
    switch(currentDocumentoTipo) {
        case 'bi':
            brightness = 25;
            contrast = 35;
            break;
        case 'certificado':
            brightness = 15;
            contrast = 25;
            break;
        case 'atestado':
            brightness = 20;
            contrast = 30;
            break;
        case 'declaracao':
            brightness = 20;
            contrast = 30;
            break;
    }
    
    for (let i = 0; i < data.length; i += 4) {
        data[i] = Math.min(255, Math.max(0, data[i] + brightness));
        data[i+1] = Math.min(255, Math.max(0, data[i+1] + brightness));
        data[i+2] = Math.min(255, Math.max(0, data[i+2] + brightness));
        
        let factor = (259 * (contrast + 255)) / (255 * (259 - contrast));
        data[i] = factor * (data[i] - 128) + 128;
        data[i+1] = factor * (data[i+1] - 128) + 128;
        data[i+2] = factor * (data[i+2] - 128) + 128;
    }
    
    ctx.putImageData(imageData, 0, 0);
}

// Confirmar captura e salvar no campo correto
function confirmarCaptura() {
    if (!fotoCapturada) {
        alert('Tire uma foto primeiro!');
        return;
    }
    
    // Validar formato e tamanho
    validarECarregarDocumento(fotoCapturada);
}

// Validar e carregar documento
function validarECarregarDocumento(base64Image) {
    const config = documentoTipos[currentDocumentoTipo];
    if (!config) {
        mostrarMensagem('Tipo de documento inválido', 'error');
        return;
    }
    
    // Verificar tamanho aproximado (base64 aumenta ~33%)
    const sizeInBytes = Math.ceil(base64Image.length * 0.75);
    const sizeInMB = sizeInBytes / (1024 * 1024);
    
    if (sizeInMB > config.tamanhoMax) {
        mostrarMensagem(`O documento é muito grande! Tamanho máximo: ${config.tamanhoMax}MB`, 'error');
        return;
    }
    
    // Converter base64 para blob
    fetch(base64Image)
        .then(res => res.blob())
        .then(blob => {
            // Validar tipo de arquivo
            if (!blob.type.match(/image\/(jpeg|png)/)) {
                mostrarMensagem('Formato não suportado. Use JPG ou PNG.', 'error');
                return;
            }
            
            // Criar nome do arquivo
            const timestamp = Date.now();
            const fileName = `${config.nome}_${timestamp}.jpg`;
            const file = new File([blob], fileName, { type: 'image/jpeg' });
            
            // Carregar no input correto
            carregarNoInput(file, currentInputId, currentPreviewId);
            
            // Fechar modais
            fecharTodosModais();
            
            mostrarMensagem(`${config.nome} capturado e anexado com sucesso!`, 'success');
        })
        .catch(error => {
            console.error('Erro:', error);
            mostrarMensagem('Erro ao processar o documento', 'error');
        });
}

// Carregar arquivo no input e mostrar preview
function carregarNoInput(file, inputId, previewId) {
    const inputFile = document.getElementById(inputId);
    if (!inputFile) {
        console.error('Input não encontrado:', inputId);
        return;
    }
    
    // Criar DataTransfer
    const dataTransfer = new DataTransfer();
    dataTransfer.items.add(file);
    inputFile.files = dataTransfer.files;
    
    // Disparar evento change
    const event = new Event('change', { bubbles: true });
    inputFile.dispatchEvent(event);
    
    // Mostrar preview personalizado
    mostrarPreviewPersonalizado(file, previewId);
}

// Mostrar preview personalizado
function mostrarPreviewPersonalizado(file, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    
    preview.innerHTML = '';
    
    const reader = new FileReader();
    reader.onload = function(e) {
        const fileSize = (file.size / 1024 / 1024).toFixed(2);
        const config = documentoTipos[currentDocumentoTipo];
        const tipoNome = config ? config.nome : 'Documento';
        
        preview.innerHTML = `
            <div class="position-relative d-inline-block">
                <img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px; border: 2px solid #006B3E;">
                <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                        onclick="removerDocumento('${previewId}', '${currentInputId}')">
                    <i class="fas fa-times"></i>
                </button>
                <div class="mt-1">
                    <small class="text-success">
                        <i class="fas fa-check-circle"></i> ${tipoNome} capturado
                    </small>
                    <br><small class="text-muted">${file.name} (${fileSize} MB)</small>
                </div>
            </div>
        `;
    };
    reader.readAsDataURL(file);
}

// Remover documento
function removerDocumento(previewId, inputId) {
    if (confirm('Remover este documento?')) {
        const preview = document.getElementById(previewId);
        if (preview) preview.innerHTML = '';
        
        const input = document.getElementById(inputId);
        if (input) input.value = '';
        
        mostrarMensagem('Documento removido com sucesso!', 'info');
    }
}

// Fechar todos os modais
function fecharTodosModais() {
    const modais = ['modalScanner', 'modalAjuste'];
    modais.forEach(modalId => {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    });
    
    // Parar câmera
    if (scannerStream) {
        scannerStream.getTracks().forEach(track => track.stop());
        scannerStream = null;
    }
    
    // Limpar variáveis
    fotoCapturada = null;
    currentZoom = 1;
    const video = document.getElementById('scannerVideo');
    if (video) video.style.transform = 'scale(1)';
    document.getElementById('previewCaptura').innerHTML = '<i class="fas fa-camera fa-3x text-muted"></i>';
}

// Preview de documento via upload
function previewDocumento(input, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    
    preview.innerHTML = '';
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileType = file.type;
        const fileName = file.name;
        const fileSize = (file.size / 1024 / 1024).toFixed(2);
        const extension = fileName.split('.').pop().toLowerCase();
        
        // Validar extensão
        const allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
        if (!allowedExtensions.includes(extension)) {
            alert('Formato de arquivo não permitido. Use: JPG, PNG ou PDF');
            input.value = '';
            return;
        }
        
        // Validar tamanho (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            alert('O arquivo é muito grande! Máximo 5MB.');
            input.value = '';
            return;
        }
        
        if (fileType.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.innerHTML = `
                    <div class="position-relative d-inline-block">
                        <img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px;">
                        <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                                onclick="removerPreview('${previewId}', this.closest('.position-relative'), '${input.id}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <small class="text-muted d-block">${fileName} (${fileSize} MB)</small>
                `;
            };
            reader.readAsDataURL(file);
        } else {
            preview.innerHTML = `
                <div class="position-relative d-inline-block text-center p-2 border rounded">
                    <i class="fas fa-file-pdf fa-3x text-danger"></i>
                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                            onclick="removerPreview('${previewId}', this.closest('.position-relative'), '${input.id}')">
                        <i class="fas fa-times"></i>
                    </button>
                    <div><small>${fileName}</small></div>
                    <small class="text-muted">(${fileSize} MB)</small>
                </div>
            `;
        }
    }
}

// Remover preview
function removerPreview(previewId, element, inputId) {
    if (confirm('Remover este documento?')) {
        if (element) element.remove();
        document.getElementById(previewId).innerHTML = '';
        if (inputId) document.getElementById(inputId).value = '';
    }
}

// Mostrar mensagem
function mostrarMensagem(mensagem, tipo) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${tipo === 'success' ? 'success' : tipo === 'error' ? 'danger' : 'info'} alert-dismissible fade show position-fixed`;
    alertDiv.style.top = '20px';
    alertDiv.style.right = '20px';
    alertDiv.style.zIndex = '9999';
    alertDiv.style.minWidth = '300px';
    alertDiv.innerHTML = `
        ${mensagem}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);
    
    setTimeout(() => {
        alertDiv.remove();
    }, 3000);
}

// Zoom
function zoomIn() {
    const video = document.getElementById('scannerVideo');
    currentZoom += 0.1;
    video.style.transform = `scale(${currentZoom})`;
}

function zoomOut() {
    const video = document.getElementById('scannerVideo');
    currentZoom = Math.max(0.5, currentZoom - 0.1);
    video.style.transform = `scale(${currentZoom})`;
}

// Ajustar documento
function ajustarDocumento() {
    if (!fotoCapturada) {
        alert('Tire uma foto primeiro!');
        return;
    }
    
    document.getElementById('imagemAjuste').src = fotoCapturada;
    const modal = new bootstrap.Modal(document.getElementById('modalAjuste'));
    modal.show();
}

// Iniciar cropper
function iniciarCropper() {
    const img = document.getElementById('imagemAjuste');
    if (cropper) {
        cropper.destroy();
    }
    
    cropper = new Cropper(img, {
        aspectRatio: NaN,
        viewMode: 1,
        dragMode: 'crop',
        cropBoxMovable: true,
        cropBoxResizable: true,
        guides: true,
        center: true,
        highlight: true,
        background: true
    });
}

// Aplicar corte
function aplicarCorte() {
    if (cropper) {
        const canvas = cropper.getCroppedCanvas();
        fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
        
        const previewDiv = document.getElementById('previewCaptura');
        previewDiv.innerHTML = `<img src="${fotoCapturada}" style="max-width: 100%; max-height: 150px; border-radius: 8px;">`;
        
        const modalAjuste = bootstrap.Modal.getInstance(document.getElementById('modalAjuste'));
        if (modalAjuste) {
            modalAjuste.hide();
        }
        
        if (cropper) {
            cropper.destroy();
            cropper = null;
        }
        
        mostrarMensagem('Documento ajustado com sucesso!', 'success');
    }
}

// Girar imagem
function girarImagem() {
    rotacaoAtual = (rotacaoAtual + 90) % 360;
    const img = document.getElementById('imagemAjuste');
    img.style.transform = `rotate(${rotacaoAtual}deg)`;
    
    if (cropper) {
        cropper.setData({ rotate: rotacaoAtual });
    }
}

// Aplicar filtro
function aplicarFiltro() {
    const img = document.getElementById('imagemAjuste');
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imageData.data;
    
    for (let i = 0; i < data.length; i += 4) {
        const gray = (data[i] + data[i+1] + data[i+2]) / 3;
        const contrast = 1.5;
        const brightness = 20;
        
        let newColor = (gray - 128) * contrast + 128 + brightness;
        newColor = Math.min(255, Math.max(0, newColor));
        
        data[i] = newColor;
        data[i+1] = newColor;
        data[i+2] = newColor;
    }
    
    ctx.putImageData(imageData, 0, 0);
    fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
    document.getElementById('imagemAjuste').src = fotoCapturada;
    
    setTimeout(() => iniciarCropper(), 100);
    mostrarMensagem('Filtro de melhoria aplicado!', 'success');
}

function resetarAjuste() {
    rotacaoAtual = 0;
    document.getElementById('imagemAjuste').style.transform = 'rotate(0deg)';
    
    if (cropper) {
        cropper.reset();
        cropper.setData({ rotate: 0 });
    }
}

// Eventos dos modais
document.getElementById('modalScanner')?.addEventListener('shown.bs.modal', function() {
    currentZoom = 1;
    const video = document.getElementById('scannerVideo');
    if (video) video.style.transform = 'scale(1)';
});

document.getElementById('modalAjuste')?.addEventListener('shown.bs.modal', function() {
    setTimeout(() => iniciarCropper(), 100);
});

document.getElementById('modalAjuste')?.addEventListener('hidden.bs.modal', function() {
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    rotacaoAtual = 0;
});
</script>


<script>
// Enviar formulário novo responsável
document.getElementById('formNovoResponsavel').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Mostrar loading
    Swal.fire({
        title: 'A processar...',
        text: 'Aguardando resposta do servidor',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    const formData = new FormData(this);
    formData.append('escola_id', <?php echo $escola_id ?? 1; ?>);
    
    fetch('salvar_responsavel.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Fechar modal de cadastro
            const modalNovo = bootstrap.Modal.getInstance(document.getElementById('modalNovoResponsavel'));
            if (modalNovo) {
                modalNovo.hide();
            }
            
            // Fechar modal de busca (se estiver aberto)
            const modalBusca = bootstrap.Modal.getInstance(document.getElementById('modalSelecionarResponsavel'));
            if (modalBusca) {
                modalBusca.hide();
            }
            
            // Limpar o formulário
            document.getElementById('formNovoResponsavel').reset();
            
            // Selecionar automaticamente o recém-criado
            selecionarResponsavel(
                data.id, 
                data.nome, 
                data.parentesco || '', 
                data.bi || '', 
                data.telefone || '', 
                data.email || '', 
                data.endereco || ''
            );
            
            // Mostrar sucesso
            Swal.fire({
                icon: 'success',
                title: 'Cadastrado!',
                text: 'Responsável cadastrado e selecionado com sucesso!',
                timer: 2000,
                showConfirmButton: false
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Erro',
                text: data.message || 'Erro ao cadastrar responsável'
            });
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        Swal.fire({
            icon: 'error',
            title: 'Erro',
            text: 'Erro ao cadastrar responsável. Tente novamente.'
        });
    });
});

// Função para selecionar responsável e preencher campos
function selecionarResponsavel(id, nome, parentesco, bi, telefone, email, endereco) {
    // Preencher os campos do formulário principal
    document.getElementById('encarregado_id').value = id;
    document.getElementById('encarregado_nome').value = nome;
    document.getElementById('encarregado_parentesco').value = parentesco;
    document.getElementById('encarregado_bi').value = bi;
    document.getElementById('encarregado_telefone').value = telefone;
    document.getElementById('encarregado_email').value = email;
    document.getElementById('encarregado_endereco').value = endereco;
    
    // Disparar evento change para atualizar validações
    ['encarregado_nome', 'encarregado_parentesco', 'encarregado_bi', 'encarregado_telefone', 'encarregado_email', 'encarregado_endereco'].forEach(id => {
        const element = document.getElementById(id);
        if (element) {
            const event = new Event('change', { bubbles: true });
            element.dispatchEvent(event);
        }
    });
}

// Função para buscar responsáveis
function buscarResponsaveis() {
    const termo = document.getElementById('buscarResponsavel').value;
    if (termo.length < 2) {
        Swal.fire({
            icon: 'warning',
            title: 'Atenção',
            text: 'Digite pelo menos 2 caracteres para buscar',
            timer: 2000,
            showConfirmButton: false
        });
        return;
    }
    
    const container = document.getElementById('listaResponsaveis');
    container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i><p class="mt-2">Buscando...</p></div>';
    
    fetch(`buscar_responsaveis.php?termo=${encodeURIComponent(termo)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                let html = '<div class="list-group">';
                data.data.forEach(resp => {
                    html += `
                        <div class="list-group-item list-group-item-action" style="cursor: pointer;" 
                             onclick="selecionarResponsavelComFechamento(${resp.id}, '${escapeHtml(resp.nome)}', '${escapeHtml(resp.parentesco || '')}', '${escapeHtml(resp.bi || '')}', '${escapeHtml(resp.telefone || '')}', '${escapeHtml(resp.email || '')}', '${escapeHtml(resp.endereco || '')}')">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong>${escapeHtml(resp.nome)}</strong>
                                    <br><small class="text-muted">
                                        ${escapeHtml(resp.parentesco || 'Sem parentesco')} | 
                                        ${escapeHtml(resp.telefone || 'Sem telefone')}
                                    </small>
                                </div>
                                <i class="fas fa-chevron-right text-success"></i>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            } else {
                container.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-user-slash fa-2x mb-2"></i>
                        <p>Nenhum responsável encontrado com "${escapeHtml(termo)}"</p>
                        <button class="btn btn-outline-success btn-sm" onclick="abrirModalNovoResponsavel()">
                            <i class="fas fa-plus-circle"></i> Cadastrar Novo
                        </button>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            container.innerHTML = '<div class="alert alert-danger">Erro ao buscar responsáveis. Tente novamente.</div>';
        });
}

// Função para selecionar e fechar modal
function selecionarResponsavelComFechamento(id, nome, parentesco, bi, telefone, email, endereco) {
    // Selecionar o responsável
    selecionarResponsavel(id, nome, parentesco, bi, telefone, email, endereco);
    
    // Fechar o modal de busca
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalSelecionarResponsavel'));
    if (modal) {
        modal.hide();
    }
    
    // Mostrar feedback
    Swal.fire({
        icon: 'success',
        title: 'Selecionado!',
        text: `O responsável ${nome} foi selecionado com sucesso.`,
        timer: 1500,
        showConfirmButton: false
    });
}

// Abrir modal novo responsável
function abrirModalNovoResponsavel() {
    // Fechar modal de busca
    const modalBusca = bootstrap.Modal.getInstance(document.getElementById('modalSelecionarResponsavel'));
    if (modalBusca) {
        modalBusca.hide();
    }
    
    // Limpar formulário
    document.getElementById('formNovoResponsavel').reset();
    
    // Abrir modal de cadastro
    const modalNovo = new bootstrap.Modal(document.getElementById('modalNovoResponsavel'));
    modalNovo.show();
}

// Limpar campos do responsável
document.getElementById('btnLimparResponsavel').addEventListener('click', function() {
    document.getElementById('encarregado_id').value = '';
    document.getElementById('encarregado_nome').value = '';
    document.getElementById('encarregado_parentesco').value = '';
    document.getElementById('encarregado_bi').value = '';
    document.getElementById('encarregado_telefone').value = '';
    document.getElementById('encarregado_email').value = '';
    document.getElementById('encarregado_endereco').value = '';
    
    Swal.fire({
        icon: 'info',
        title: 'Campos Limpos',
        text: 'Os campos do encarregado foram limpos.',
        timer: 1500,
        showConfirmButton: false
    });
});

// Buscar ao pressionar Enter
document.getElementById('buscarResponsavel').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        buscarResponsaveis();
    }
});

// Função auxiliar para escapar HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Quando o modal de busca for aberto, focar no campo de busca
document.getElementById('modalSelecionarResponsavel').addEventListener('shown.bs.modal', function() {
    document.getElementById('buscarResponsavel').focus();
});

// Quando o modal de cadastro for fechado, limpar o formulário
document.getElementById('modalNovoResponsavel').addEventListener('hidden.bs.modal', function() {
    document.getElementById('formNovoResponsavel').reset();
});
</script>

<script>
let documentoCounter = 4;

function adicionarCampoDocumento() {
    const container = document.getElementById('outros-documentos');
    const newCol = document.createElement('div');
    newCol.className = 'col-md-4';
    newCol.innerHTML = `
        <div class="mb-2 position-relative">
            <input type="file" name="outro_documento_${documentoCounter}" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro${documentoCounter}_preview')">
            <div id="outro${documentoCounter}_preview" class="mt-1"></div>
            <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" 
                    onclick="removerCampoDocumento(this)" style="margin-top: -8px; margin-right: -8px;">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    container.appendChild(newCol);
    documentoCounter++;
}

function removerCampoDocumento(btn) {
    btn.closest('.col-md-4').remove();
}

function removerDocumento(btn, inputId, previewId) {
    if (confirm('Deseja remover este documento?')) {
        document.getElementById(inputId).value = '';
        document.getElementById(previewId).innerHTML = '';
        
        // Opcional: enviar requisição para remover do servidor
        // fetch('remover_documento.php', { method: 'POST', body: JSON.stringify({ tipo: inputId }) });
    }
}

function visualizarDocumento(caminho) {
    window.open(caminho, '_blank');
}
</script>
    <script>
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
        
        // Carregar cidades por país
        $('#pais_id').change(function() {
            var paisId = $(this).val();
            if (paisId) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Carregar municípios por província
        $('#provincia_id').change(function() {
            var provinciaId = $(this).val();
            if (provinciaId) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Carregar comunas por município
        $('#municipio_id').change(function() {
            var municipioId = $(this).val();
            if (municipioId) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Carregar dados da turma
        $('#turma_id').change(function() {
            var turmaId = $(this).val();
            if (turmaId) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_turma', turma_id: turmaId },
                    success: function(data) {
                        var turma = JSON.parse(data);
                        if (turma) {
                            $('#ano_escolar').val(turma.ano);
                            $('#turno').val(turma.turno == 'manha' ? 'Manhã' : (turma.turno == 'tarde' ? 'Tarde' : 'Noite'));
                            $('#sala').val(turma.sala || 'Não definida');
                        }
                    }
                });
            } else {
                $('#ano_escolar').val('');
                $('#turno').val('');
                $('#sala').val('');
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
        function previewDocumento1(input, previewId) {
            const file = input.files[0];
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) { 
                    $('#' + previewId).html('<img src="' + e.target.result + '" class="document-preview">');
                };
                reader.readAsDataURL(file);
            } else if (file) {
                $('#' + previewId).html('<span class="badge bg-info"><i class="fas fa-file-pdf"></i> ' + file.name + '</span>');
            }
        }
        
        function capturarDocumento1(inputName, previewId) {
            // Implementar captura de documento via webcam
            alert('Funcionalidade de captura de documento em desenvolvimento');
        }
        
        // ============================================
        // WEBCAM
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
            
            // Criar um arquivo Blob a partir da foto capturada
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
        
        // Salvar novo país
        $('#salvarPaisBtn').click(function() {
            var novoPais = $('#novoPais').val();
            if (novoPais) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Salvar nova cidade
        $('#salvarCidadeBtn').click(function() {
            var novaCidade = $('#novaCidade').val();
            var paisId = $('#pais_id').val();
            if (novaCidade && paisId) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Salvar nova província
        $('#salvarProvinciaBtn').click(function() {
            var novaProvincia = $('#novaProvincia').val();
            if (novaProvincia) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Salvar novo município
        $('#salvarMunicipioBtn').click(function() {
            var novoMunicipio = $('#novoMunicipio').val();
            var provinciaId = $('#provincia_id').val();
            if (novoMunicipio && provinciaId) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Salvar nova comuna
        $('#salvarComunaBtn').click(function() {
            var novaComuna = $('#novaComuna').val();
            var municipioId = $('#municipio_id').val();
            if (novaComuna && municipioId) {
                $.ajax({
                    url: 'cadastrar.php',
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
        
        // Preencher modais
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
        if (currentPage.includes('alunos')) { $('#menuAlunos').addClass('open'); $('#submenuAlunos').addClass('show'); }
    </script>
</body>
</html>