<?php
// escola/pedagogico/cadastrar_aluno.php - Cadastro de Aluno (COMPLETO)

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// ============================================
// VERIFICAR PERMISSÃO
// ============================================
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin')
    AND u.status = 'ativo'
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    include __DIR__ . '/access_denied.php';
    exit;
}

$funcionario_id = $funcionario['id'];
$escola_id = $funcionario['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano = $conn->prepare($sql_ano);
$stmt_ano->execute();
$ano_letivo = $stmt_ano->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo ? $ano_letivo['id'] : 1;
$ano_letivo_ano = $ano_letivo ? $ano_letivo['ano'] : date('Y');

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
$turmas->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo_ano]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);
$tem_turmas = !empty($turmas);

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

// Buscar dados da escola
$sql_escola = "SELECT nome, telefone, whatsapp, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
$whatsapp_escola = $escola['whatsapp'] ?? $escola['telefone'] ?? '';

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function gerarNumeroProcesso($conn, $escola_id, $ano_letivo_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM estudantes WHERE escola_id = :escola_id AND ano_letivo = :ano");
    $stmt->execute([':escola_id' => $escola_id, ':ano' => $ano_letivo_id]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] + 1;
    return date('Y') . '/' . str_pad($escola_id, 3, '0', STR_PAD_LEFT) . '/' . str_pad($total, 5, '0', STR_PAD_LEFT);
}



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

function uploadArquivoAluno($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
    if (!isset($arquivo) || $arquivo['error'] != 0) return null;
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $tipos_permitidos)) return false;
    if ($arquivo['size'] > $tamanho_maximo) return false;
    if (!is_dir($pasta)) mkdir($pasta, 0777, true);
    $nome_arquivo = time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($arquivo['tmp_name'], $pasta . $nome_arquivo)) return $nome_arquivo;
    return false;
}

function enviarWhatsAppEscola($telefone_destino, $mensagem, $whatsapp_escola) {
    $telefone_destino = limparNumeroWhatsApp($telefone_destino);
    $url = "https://wa.me/{$telefone_destino}?text=" . urlencode($mensagem);
    return ['success' => true, 'url' => $url, 'telefone_destino' => $telefone_destino];
}

function limparNumeroWhatsApp($numero) {
    $numero = preg_replace('/[^0-9]/', '', $numero);
    if (substr($numero, 0, 1) == '0') $numero = substr($numero, 1);
    if (substr($numero, 0, 3) != '244') $numero = '244' . $numero;
    return $numero;
}

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
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
    
    $documentos_salvos = [];
    
    foreach ($documentos as $campo => $nome_documento) {
        if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES[$campo];
            $extensao = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $tamanho = $file['size'];
            
            $extensoes_permitidas = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
            if (!in_array($extensao, $extensoes_permitidas)) continue;
            if ($tamanho > 5 * 1024 * 1024) continue;
            
            $nome_arquivo = time() . '_' . $aluno_id . '_' . $campo . '.' . $extensao;
            $caminho = 'uploads/documentos_aluno/' . $nome_arquivo;
            $caminho_completo = $upload_dir . $nome_arquivo;
            
            if (move_uploaded_file($file['tmp_name'], $caminho_completo)) {
                $sql_check = "SELECT id FROM documentos_aluno WHERE aluno_id = :aluno_id AND tipo = :tipo AND escola_id = :escola_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([':aluno_id' => $aluno_id, ':tipo' => $campo, ':escola_id' => $escola_id]);
                
                if ($stmt_check->rowCount() > 0) {
                    $sql = "UPDATE documentos_aluno SET nome = :nome, caminho = :caminho, tamanho = :tamanho, extensao = :extensao, data_upload = NOW() WHERE aluno_id = :aluno_id AND tipo = :tipo AND escola_id = :escola_id";
                } else {
                    $sql = "INSERT INTO documentos_aluno (escola_id, aluno_id, nome, tipo, caminho, tamanho, extensao, data_upload, status) VALUES (:escola_id, :aluno_id, :nome, :tipo, :caminho, :tamanho, :extensao, NOW(), 'ativo')";
                }
                
                $stmt = $conn->prepare($sql);
                $params = [':aluno_id' => $aluno_id, ':nome' => $nome_documento, ':tipo' => $campo, ':caminho' => $caminho, ':tamanho' => $tamanho, ':extensao' => $extensao];
                if ($stmt_check->rowCount() == 0) $params[':escola_id'] = $escola_id;
                $stmt->execute($params);
                $documentos_salvos[] = $nome_documento;
            }
        }
    }
    return $documentos_salvos;
}

// ============================================
// PROCESSAR AJAX
// ============================================

if (isset($_GET['acao']) && $_GET['acao'] == 'get_cidades') {
    $pais_id = $_GET['pais_id'] ?? 0;
    if ($pais_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM cidades WHERE pais_id = :pais_id ORDER BY nome");
        $stmt->execute([':pais_id' => $pais_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else { echo json_encode([]); }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia_id = $_GET['provincia_id'] ?? 0;
    if ($provincia_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = :provincia_id ORDER BY nome");
        $stmt->execute([':provincia_id' => $provincia_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else { echo json_encode([]); }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio_id = $_GET['municipio_id'] ?? 0;
    if ($municipio_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = :municipio_id ORDER BY nome");
        $stmt->execute([':municipio_id' => $municipio_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else { echo json_encode([]); }
    exit;
}

if (isset($_GET['acao']) && $_GET['acao'] == 'get_turma') {
    $turma_id = $_GET['turma_id'] ?? 0;
    if ($turma_id) {
        $stmt = $conn->prepare("SELECT ano, turno, sala FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $turma_id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    } else { echo json_encode([]); }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_pais') {
    $novo_pais = $_POST['novo_pais'] ?? '';
    if ($novo_pais) {
        $stmt = $conn->prepare("INSERT IGNORE INTO paises (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $novo_pais]);
        echo json_encode(['success' => true, 'pais' => $novo_pais]);
    } else { echo json_encode(['success' => false]); }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_cidade') {
    $nova_cidade = $_POST['nova_cidade'] ?? '';
    $pais_id = $_POST['pais_id'] ?? 0;
    if ($nova_cidade && $pais_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO cidades (nome, pais_id) VALUES (:nome, :pais_id)");
        $stmt->execute([':nome' => $nova_cidade, ':pais_id' => $pais_id]);
        echo json_encode(['success' => true, 'cidade' => $nova_cidade]);
    } else { echo json_encode(['success' => false]); }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_provincia') {
    $nova_provincia = $_POST['nova_provincia'] ?? '';
    if ($nova_provincia) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_provincias (nome) VALUES (:nome)");
        $stmt->execute([':nome' => $nova_provincia]);
        echo json_encode(['success' => true, 'provincia' => $nova_provincia]);
    } else { echo json_encode(['success' => false]); }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_municipio') {
    $novo_municipio = $_POST['novo_municipio'] ?? '';
    $provincia_id = $_POST['provincia_id'] ?? 0;
    if ($novo_municipio && $provincia_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_municipios (nome, provincia_id) VALUES (:nome, :provincia_id)");
        $stmt->execute([':nome' => $novo_municipio, ':provincia_id' => $provincia_id]);
        echo json_encode(['success' => true, 'municipio' => $novo_municipio]);
    } else { echo json_encode(['success' => false]); }
    exit;
}

if (isset($_POST['acao']) && $_POST['acao'] == 'add_comuna') {
    $nova_comuna = $_POST['nova_comuna'] ?? '';
    $municipio_id = $_POST['municipio_id'] ?? 0;
    if ($nova_comuna && $municipio_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_comunas (nome, municipio_id) VALUES (:nome, :municipio_id)");
        $stmt->execute([':nome' => $nova_comuna, ':municipio_id' => $municipio_id]);
        echo json_encode(['success' => true, 'comuna' => $nova_comuna]);
    } else { echo json_encode(['success' => false]); }
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
    $pais_id = $_POST['pais_id'] ?? null;
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
    $encarregado_id = $_POST['encarregado_id'] ?? '';
    $encarregado_nome = $_POST['encarregado_nome'] ?? '';
    $encarregado_parentesco = $_POST['encarregado_parentesco'] ?? '';
    $encarregado_bi = $_POST['encarregado_bi'] ?? '';
    $encarregado_telefone = $_POST['encarregado_telefone'] ?? '';
    $encarregado_email = $_POST['encarregado_email'] ?? '';
    $encarregado_endereco = $_POST['encarregado_endereco'] ?? '';
    
    // Dados Académicos
    $turma_id = $_POST['turma_id'] ?? '';
    $nivel_id = $_POST['nivel_id'] ?? '';
    $curso_id = $_POST['curso_id'] ?? '';
    
    // Upload da Foto
    $foto = null;
    $foto_capturada = $_POST['foto_capturada'] ?? '';
    
    if (!empty($foto_capturada)) {
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
        if ($doc) $outros_documentos[] = $doc;
    }
    $outros_documentos_json = json_encode($outros_documentos);
    
    // Gerar número de processo
    $numero_processo = gerarNumeroProcesso($conn, $escola_id, $ano_letivo_id);
    
    // Credenciais
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
    $turma_nome = '';
    if ($turma_id) {
        $stmt = $conn->prepare("SELECT ano, turno, sala, nome FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $turma_id]);
        $turma_dados = $stmt->fetch(PDO::FETCH_ASSOC);
        $turma_nome = $turma_dados['nome'] ?? '';
    }
    
    $ano_escolar = $turma_dados['ano'] ?? '';
    $turno = $turma_dados['turno'] ?? '';
    $sala = $turma_dados['sala'] ?? '';
    
    // Criar avatar se não houver foto
    if (!$foto) $foto = criarAvatarAluno($nome_completo);
    
    try {
        $conn->beginTransaction();
        
        // Verificar se BI já existe
        if ($bi_numero) {
            $stmt = $conn->prepare("SELECT id FROM estudantes WHERE bi = :bi AND escola_id = :escola_id");
            $stmt->execute([':bi' => $bi_numero, ':escola_id' => $escola_id]);
            if ($stmt->fetch()) throw new Exception("BI já cadastrado no sistema.");
        }
        
        // Criar usuário
        $stmt = $conn->prepare("INSERT INTO usuarios (escola_id, nome, usuario, email, senha, tipo, telefone, status, created_at) VALUES (:escola_id, :nome, :usuario, :email, :senha, 'aluno', :telefone, 'ativo', NOW())");
        $stmt->execute([':escola_id' => $escola_id, ':nome' => $nome_completo, ':usuario' => $numero_processo, ':email' => $email_usuario, ':senha' => $senha_hash, ':telefone' => $telefone]);
        $usuario_id = $conn->lastInsertId();
        
        // Inserir estudante
        $stmt = $conn->prepare("INSERT INTO estudantes (usuario_id, escola_id, matricula, nome, senha, bi, bi_data_emissao, bi_local_emissao, pais_id, pais_nome, cidade_id, cidade_nome, provincia_id, provincia_nome, municipio_id, municipio_nome, comuna_id, comuna_nome, endereco, telefone, email, data_nascimento, genero, foto, pai_nome, pai_bi, pai_telefone, pai_profissao, mae_nome, mae_bi, mae_telefone, mae_profissao, encarregado_nome, encarregado_parentesco, encarregado_bi, encarregado_telefone, encarregado_email, encarregado_endereco, ano_letivo, ano_escolar, classe, curso, nivel, numero_processo, bi_documento, certificado_documento, atestado_documento, outros_documentos, declaracao_documento, created_at) VALUES (:usuario_id, :escola_id, :matricula, :nome, :senha, :bi, :bi_data_emissao, :bi_local_emissao, :pais_id, :pais_nome, :cidade_id, :cidade_nome, :provincia_id, :provincia_nome, :municipio_id, :municipio_nome, :comuna_id, :comuna_nome, :endereco, :telefone, :email, :data_nascimento, :genero, :foto, :pai_nome, :pai_bi, :pai_telefone, :pai_profissao, :mae_nome, :mae_bi, :mae_telefone, :mae_profissao, :encarregado_nome, :encarregado_parentesco, :encarregado_bi, :encarregado_telefone, :encarregado_email, :encarregado_endereco, :ano_letivo, :ano_escolar, :classe, :curso, :nivel, :numero_processo, :bi_documento, :certificado_documento, :atestado_documento, :outros_documentos, :declaracao_documento, NOW())");
        $stmt->execute([
            ':usuario_id' => $usuario_id, ':escola_id' => $escola_id, ':matricula' => $numero_processo, ':nome' => $nome_completo, ':senha' => $senha_hash,
            ':bi' => $bi_numero, ':bi_data_emissao' => $bi_data_emissao, ':bi_local_emissao' => $bi_local_emissao,
            ':pais_id' => $pais_id, ':pais_nome' => $pais_nome, ':cidade_id' => $cidade_id, ':cidade_nome' => $cidade_nome,
            ':provincia_id' => $provincia_id, ':provincia_nome' => $provincia_nome, ':municipio_id' => $municipio_id, ':municipio_nome' => $municipio_nome, ':comuna_id' => $comuna_id, ':comuna_nome' => $comuna_nome,
            ':endereco' => $endereco, ':telefone' => $telefone, ':email' => $email, ':data_nascimento' => $data_nascimento, ':genero' => $genero, ':foto' => $foto,
            ':pai_nome' => $pai_nome, ':pai_bi' => $pai_bi, ':pai_telefone' => $pai_telefone, ':pai_profissao' => $pai_profissao,
            ':mae_nome' => $mae_nome, ':mae_bi' => $mae_bi, ':mae_telefone' => $mae_telefone, ':mae_profissao' => $mae_profissao,
            ':encarregado_nome' => $encarregado_nome, ':encarregado_parentesco' => $encarregado_parentesco, ':encarregado_bi' => $encarregado_bi, ':encarregado_telefone' => $encarregado_telefone, ':encarregado_email' => $encarregado_email, ':encarregado_endereco' => $encarregado_endereco,
            ':ano_letivo' => $ano_letivo_id, ':ano_escolar' => $ano_escolar, ':classe' => $ano_escolar, ':curso' => $curso_id, ':nivel' => $nivel_id,
            ':numero_processo' => $numero_processo, ':bi_documento' => $bi_documento, ':certificado_documento' => $certificado_documento,
            ':atestado_documento' => $atestado_documento, ':outros_documentos' => $outros_documentos_json, ':declaracao_documento' => $declaracao_documento
        ]);
        $estudante_id = $conn->lastInsertId();
        
        // Matricular na turma
        if ($turma_id) {
            $stmt = $conn->prepare("INSERT INTO matriculas (estudante_id, turma_id, ano_letivo, numero_processo, status, data_matricula, created_at) VALUES (:estudante_id, :turma_id, :ano_letivo, :numero_processo, 'ativa', CURDATE(), NOW())");
            $stmt->execute([':estudante_id' => $estudante_id, ':turma_id' => $turma_id, ':ano_letivo' => $ano_letivo_id, ':numero_processo' => $numero_processo]);
        }
        
        $conn->commit();
        
        $success = "Aluno cadastrado com sucesso!<br>Nº Processo: <strong>{$numero_processo}</strong><br>Usuário: <strong>{$usuario_acesso}</strong><br>Senha: <strong>{$senha_acesso}</strong><br>E-mail: <strong>{$email_usuario}</strong>";
        
        // Processar documentos
        $documentos_salvos = processarDocumentos($conn, $estudante_id, $escola_id);
        
        // ============================================
        // BUSCAR E INSERIR PAGAMENTOS OBRIGATÓRIOS
        // ============================================
        
        // Buscar pagamentos obrigatórios para a série do aluno
        $sql_pagamentos = "
            SELECT 
                po.*,
                tp.nome as tipo_nome,
                tp.descricao as tipo_categoria
            FROM pagamentos_obrigatorios po
            INNER JOIN tipos_pagamento tp ON tp.id = po.tipo_pagamento_id
            WHERE po.escola_id = :escola_id
            AND po.ano_letivo_id = :ano_letivo_id
            AND po.ativo = 1
            AND (
                po.series_afetadas LIKE :serie
            )
            ORDER BY po.data_vencimento ASC
        ";
        $stmt_pagamentos = $conn->prepare($sql_pagamentos);
        $stmt_pagamentos->execute([
            ':escola_id' => $escola_id,
            ':ano_letivo_id' => $ano_letivo_id,
            ':serie' => '%"' . $ano_escolar . '"%',
        ]);
        $pagamentos_obrigatorios = $stmt_pagamentos->fetchAll(PDO::FETCH_ASSOC);
        
        $total_matricula = 0;
        $total_mensalidades = 0;
        $total_taxas = 0;
        $valores_inseridos = [];
        
        foreach ($pagamentos_obrigatorios as $pagamento) {
            $valor = floatval($pagamento['valor']);
            $mes_referencia = $pagamento['mes_referencia'];
            $ano_referencia = $pagamento['ano_referencia'] ?? date('Y');
            $data_vencimento = $pagamento['data_vencimento'];
            $tipo_categoria = $pagamento['tipo_categoria'];
            $tipo_nome = $pagamento['tipo_nome'];
            
            if ($tipo_categoria == 'mensalidade') {
                $sql_check = "SELECT id FROM mensalidades WHERE aluno_id = :aluno_id AND mes_referencia = :mes AND ano_referencia = :ano AND escola_id = :escola_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':aluno_id' => $estudante_id,
                    ':mes' => $mes_referencia,
                    ':ano' => $ano_referencia,
                    ':escola_id' => $escola_id
                ]);
                
                if ($stmt_check->rowCount() == 0) {
                    $sql_insert = "INSERT INTO mensalidades (escola_id, aluno_id, turma_id, ano_letivo_id, mes_referencia, ano_referencia, valor_total, data_vencimento, status) 
                                  VALUES (:escola_id, :aluno_id, :turma_id, :ano_letivo_id, :mes, :ano, :valor, :data_vencimento, 'pendente')";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':escola_id' => $escola_id,
                        ':aluno_id' => $estudante_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':mes' => $mes_referencia,
                        ':ano' => $ano_referencia,
                        ':valor' => $valor,
                        ':data_vencimento' => $data_vencimento
                    ]);
                    $total_mensalidades += $valor;
                } else {
                    $stmt_existente = $conn->prepare("SELECT valor_total FROM mensalidades WHERE aluno_id = :aluno_id AND mes_referencia = :mes AND ano_referencia = :ano");
                    $stmt_existente->execute([
                        ':aluno_id' => $estudante_id,
                        ':mes' => $mes_referencia,
                        ':ano' => $ano_referencia
                    ]);
                    $existente = $stmt_existente->fetch(PDO::FETCH_ASSOC);
                    $total_mensalidades += $existente['valor_total'];
                }
            } else {
                $sql_check = "SELECT id FROM outros_pagamentos WHERE aluno_id = :aluno_id AND tipo_pagamento_id = :tipo_id AND ano_referencia = :ano AND escola_id = :escola_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':aluno_id' => $estudante_id,
                    ':tipo_id' => $pagamento['tipo_pagamento_id'],
                    ':ano' => $ano_referencia,
                    ':escola_id' => $escola_id
                ]);
                
                if ($stmt_check->rowCount() == 0) {
                    $sql_insert = "INSERT INTO outros_pagamentos (escola_id, tipo_pagamento_id, aluno_id, turma_id, ano_letivo_id, mes_referencia, ano_referencia, valor_total, data_vencimento, status) 
                                  VALUES (:escola_id, :tipo_id, :aluno_id, :turma_id, :ano_letivo_id, :mes, :ano, :valor, :data_vencimento, 'pendente')";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':escola_id' => $escola_id,
                        ':tipo_id' => $pagamento['tipo_pagamento_id'],
                        ':aluno_id' => $estudante_id,
                        ':turma_id' => $turma_id,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':mes' => $mes_referencia,
                        ':ano' => $ano_referencia,
                        ':valor' => $valor,
                        ':data_vencimento' => $data_vencimento
                    ]);
                }
                
                if ($tipo_categoria == 'matricula') {
                    $total_matricula += $valor;
                } else {
                    $total_taxas += $valor;
                }
            }
            
            $valores_inseridos[] = [
                'tipo' => $tipo_nome,
                'categoria' => $tipo_categoria,
                'valor' => $valor,
                'mes' => $mes_referencia,
                'data_vencimento' => $data_vencimento
            ];
        }
        
        $total_geral = $total_matricula + $total_mensalidades + $total_taxas;
        $desconto_vista = $total_geral * 0.10;
        $total_com_desconto = $total_geral - $desconto_vista;
        
        // ============================================
        // ENVIAR WHATSAPP COM COMPROVATIVO
        // ============================================
        
       // $pdf_url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/sige_Plataforma/escola/pedagogico/gerar_comprovativo_matricula.php?matricula_id=' . $estudante_id;
        $pdf_url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/sige_Plataforma/escola/pedagogico/gerar_comprovativo_matricula.php?estudante_id=' . $estudante_id;
        // Construir lista de pagamentos para mensagem
        $lista_pagamentos = "";
        foreach ($valores_inseridos as $valor) {
            $lista_pagamentos .= "├ " . $valor['tipo'] . ": " . number_format($valor['valor'], 2, ',', '.') . " Kz\n";
        }
        
        $mensagem = "🏫 *" . strtoupper($escola['nome']) . "* - CADASTRO REALIZADO!\n\n";
        $mensagem .= "Olá *" . strtoupper($encarregado_nome) . "*,\n\n";
        $mensagem .= "Seu educando(a) *{$nome_completo}* foi cadastrado(a) com sucesso em nossa instituição.\n\n";
        $mensagem .= "📋 *Dados do Cadastro:*\n";
        $mensagem .= "├ Nº Processo: *{$numero_processo}*\n";
        $mensagem .= "├ Matrícula: *{$estudante_id}*\n";
        $mensagem .= "├ Turma: *{$turma_nome}*\n";
        $mensagem .= "└ Ano Letivo: *" . date('Y') . "*\n\n";
        $mensagem .= "💰 *RESUMO DE PAGAMENTOS:*\n";
        $mensagem .= "├ Matrícula: *" . number_format($total_matricula, 2, ',', '.') . " Kz*\n";
        $mensagem .= "├ Mensalidades (10x): *" . number_format($total_mensalidades, 2, ',', '.') . " Kz*\n";
        $mensagem .= "├ Outras Taxas: *" . number_format($total_taxas, 2, ',', '.') . " Kz*\n";
        $mensagem .= "├ *TOTAL GERAL:* *" . number_format($total_geral, 2, ',', '.') . " Kz*\n";
        $mensagem .= "├ Pagamento à vista: *" . number_format($total_com_desconto, 2, ',', '.') . " Kz* (10% desc)\n";
        $mensagem .= "└ Parcelado: *" . number_format($total_mensalidades / 10, 2, ',', '.') . " Kz/mês*\n\n";
        $mensagem .= "📅 *Primeira parcela vence em:* " . date('d/m/Y', strtotime('+30 days')) . "\n\n";
        $mensagem .= "🔐 *Credenciais de Acesso:*\n";
        $mensagem .= "├ Usuário: *{$usuario_acesso}*\n";
        $mensagem .= "├ Senha: *{$senha_acesso}*\n";
        $mensagem .= "└ E-mail: *{$email_usuario}*\n\n";
        $mensagem .= "📌 *Acesse o sistema:*\n";
        $mensagem .= "🌐 " . $_SERVER['REQUEST_SCHEME'] . "://" . $_SERVER['HTTP_HOST'] . "/sige_Plataforma/escola/aluno/login.php\n\n";
        $mensagem .= "📄 *Comprovativo de Matrícula:*\n";
        $mensagem .= "📎 " . $pdf_url . "\n\n";
        $mensagem .= "🔐 *Recomendamos alterar a senha no primeiro acesso.*\n\n";
        $mensagem .= "📞 *Dúvidas?* Entre em contato conosco:\n";
        $mensagem .= "├ Telefone: {$escola['telefone']}\n";
        $mensagem .= "├ WhatsApp: {$whatsapp_escola}\n";
        $mensagem .= "└ E-mail: {$escola['email']}\n\n";
        $mensagem .= "🍀 *Bem-vindo(a) à família " . $escola['nome'] . "!*";
        
        $telefone_limpo = limparNumeroWhatsApp($encarregado_telefone);
        $url_whatsapp = "https://wa.me/{$telefone_limpo}?text=" . urlencode($mensagem);
        
        $success .= "<br><br>
            <div class='alert alert-info'>
                <i class='fas fa-file-pdf'></i> 
                <strong>Comprovativo de Matrícula:</strong> Um PDF com todos os dados do aluno e valores a pagar foi gerado.
            </div>
            <div class='btn-group'>
                <a href='{$pdf_url}' target='_blank' class='btn btn-danger'>
                    <i class='fas fa-file-pdf'></i> Baixar Comprovativo PDF
                </a>
                <a href='{$url_whatsapp}' target='_blank' class='btn btn-success'>
                    <i class='fab fa-whatsapp'></i> Enviar por WhatsApp
                </a>
                <button onclick='copiarCredenciais()' class='btn btn-secondary'>
                    <i class='fas fa-copy'></i> Copiar Credenciais
                </button>
            </div>";
        
    } catch (Exception $e) {
        // CORREÇÃO: Verificar se há transação ativa antes de fazer rollback
        try {
            if ($conn && $conn->inTransaction()) {
                $conn->rollBack();
            }
        } catch (Exception $rollbackError) {
            // Se o rollback falhar, apenas ignoramos e logamos o erro
            error_log("Rollback error: " . $rollbackError->getMessage());
        }
        $error = $e->getMessage();
    }
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Aluno | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --primary-green: #006B3E; --primary-dark: #1A2A6C; --primary-gradient: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); --card-shadow: 0 10px 40px rgba(0,0,0,0.08); --transition: all 0.3s ease; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; min-height: 100vh; }
        
        .sidebar { position: fixed; left: 0; top: 0; width: 280px; height: 100vh; background: linear-gradient(180deg, #0a2b2c 0%, #0d3b2e 50%, #0a2b2c 100%); color: white; transition: all 0.3s ease; z-index: 1000; overflow-y: auto; box-shadow: 2px 0 10px rgba(0,0,0,0.1); }
        .sidebar-header { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2em; margin-bottom: 10px; }
        .sidebar-header h3 { font-size: 1.2em; margin-bottom: 5px; }
        .sidebar-header p { font-size: 0.7em; opacity: 0.7; }
        .nav-menu { list-style: none; padding: 10px 0; margin: 0; }
        .nav-item { margin-bottom: 2px; }
        .nav-link { display: flex; align-items: center; padding: 10px 20px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; transition: all 0.3s; font-size: 0.9rem; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-link i { width: 22px; text-align: center; }
        .has-submenu > .nav-link { position: relative; }
        .has-submenu > .nav-link::after { content: '\f107'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; right: 20px; transition: transform 0.3s; }
        .has-submenu.open > .nav-link::after { transform: rotate(180deg); }
        .nav-submenu { list-style: none; padding-left: 35px; max-height: 0; overflow: hidden; transition: max-height 0.3s ease; }
        .has-submenu.open .nav-submenu { max-height: 500px; }
        .nav-submenu .nav-link { padding: 8px 20px; font-size: 0.85rem; }
        .nav-submenu .nav-link i { width: 20px; font-size: 0.8rem; }
        
        .main-content { margin-left: 280px; padding: 20px 30px; min-height: 100vh; }
        .top-bar { background: white; border-radius: 16px; padding: 15px 25px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid var(--primary-green); }
        .top-bar h2 { font-size: 1.4rem; font-weight: 700; background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%); background-clip: text; -webkit-background-clip: text; color: transparent; }
        .card { background: white; border-radius: 20px; margin-bottom: 25px; border: none; box-shadow: var(--card-shadow); overflow: hidden; }
        .card-header { background: var(--primary-gradient); color: white; border-radius: 20px 20px 0 0; padding: 15px 25px; border: none; }
        .card-header .nav-tabs { border-bottom: none; margin-bottom: -15px; }
        .card-header .nav-link { color: rgba(255,255,255,0.8); border: none; padding: 8px 20px; border-radius: 30px; transition: var(--transition); }
        .card-header .nav-link:hover { color: white; background: rgba(255,255,255,0.1); }
        .card-header .nav-link.active { background: white; color: var(--primary-green); }
        
        .form-control, .form-select { border-radius: 12px; border: 2px solid #e9ecef; padding: 10px 15px; transition: var(--transition); }
        .form-control:focus, .form-select:focus { border-color: var(--primary-green); box-shadow: 0 0 0 3px rgba(0,107,62,0.1); }
        .btn-primary { background: var(--primary-gradient); border: none; border-radius: 12px; padding: 10px 24px; font-weight: 600; transition: var(--transition); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); }
        .btn-secondary { background: #6c757d; border: none; border-radius: 12px; padding: 10px 24px; font-weight: 600; transition: var(--transition); }
        .btn-outline-primary { border-color: var(--primary-green); color: var(--primary-green); border-radius: 12px; }
        .btn-outline-primary:hover { background: var(--primary-gradient); border-color: transparent; color: white; }
        
        .preview-img { width: 150px; height: 150px; object-fit: cover; border-radius: 16px; border: 3px solid var(--primary-green); cursor: pointer; transition: transform 0.3s ease; }
        .preview-img:hover { transform: scale(1.05); }
        .modal-content { border-radius: 20px; border: none; overflow: hidden; }
        .modal-header { background: var(--primary-gradient); color: white; border: none; }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .webcam-container { position: relative; }
        #video { width: 100%; max-width: 400px; border-radius: 10px; border: 2px solid #006B3E; background: #000; }
        .document-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; }
        .menu-toggle { display: none; position: fixed; top: 15px; left: 15px; z-index: 1001; background: #006B3E; color: white; border: none; width: 45px; height: 45px; border-radius: 8px; cursor: pointer; font-size: 1.2em; box-shadow: 0 2px 10px rgba(0,0,0,0.2); }
        .document-frame { position: absolute; border: 3px solid #00ff00; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); border-radius: 4px; animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { border-color: #00ff00; } 50% { border-color: #ffff00; } 100% { border-color: #00ff00; } }
        
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; padding: 20px; } .menu-toggle { display: block; } .top-bar { flex-direction: column; gap: 15px; text-align: center; } .card-header .nav-tabs { flex-direction: column; gap: 8px; } .card-header .nav-link { text-align: center; } }
    </style>
</head>
<body>

<?php include __DIR__ . '/includes/menu_pedagogico.php'; ?>
</br></br></br>
<div class="main-content">
    <div class="top-bar">
        <h2><i class="fas fa-user-plus"></i> Cadastrar Aluno</h2>
        <a href="listar_alunos.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
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
                <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Nenhuma turma cadastrada! <a href="../turmas/criar_turma.php" class="btn btn-sm btn-warning">Cadastrar Turma</a></div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" id="formAluno">
                <input type="hidden" name="cadastrar_aluno" value="1">
                <input type="hidden" name="foto_capturada" id="foto_capturada" value="">
                <input type="hidden" name="encarregado_id" id="encarregado_id" value="">
                
                <div class="tab-content">
                    <!-- Dados Pessoais -->
                    <div class="tab-pane fade show active" id="dadosPessoais">
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label class="required">Nome Completo</label><input type="text" name="nome_completo" class="form-control" required></div></div>
                            <div class="col-md-3"><div class="mb-3"><label>Data de Nascimento</label><input type="date" name="data_nascimento" class="form-control"></div></div>
                            <div class="col-md-3"><div class="mb-3"><label>Género</label><select name="genero" class="form-control"><option value="">Selecione...</option><option value="M">Masculino</option><option value="F">Feminino</option></select></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4"><div class="mb-3"><label>Nº do BI</label><input type="text" name="bi_numero" class="form-control" placeholder="Ex: 001234567LA042"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label>Data de Emissão do BI</label><input type="date" name="bi_data_emissao" class="form-control"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label>Local de Emissão</label><input type="text" name="bi_local_emissao" class="form-control" placeholder="Ex: Luanda"></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="mb-3"><label>País</label><div class="input-group"><select name="pais_id" id="pais_id" class="form-control"><option value="">Selecione...</option><?php foreach ($paises as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoPais"><i class="fas fa-plus"></i></button></div></div></div>
                            <div class="col-md-6"><div class="mb-3"><label>Cidade / Naturalidade</label><div class="input-group"><select name="cidade_id" id="cidade_id" class="form-control" disabled><option value="">Primeiro selecione o país</option></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaCidade"><i class="fas fa-plus"></i></button></div></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4"><div class="mb-3"><label>Província</label><div class="input-group"><select name="provincia_id" id="provincia_id" class="form-control"><option value="">Selecione...</option><?php foreach ($provincias as $p): ?><option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?></option><?php endforeach; ?></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaProvincia"><i class="fas fa-plus"></i></button></div></div></div>
                            <div class="col-md-4"><div class="mb-3"><label>Município</label><div class="input-group"><select name="municipio_id" id="municipio_id" class="form-control" disabled><option value="">Primeiro selecione a província</option></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoMunicipio"><i class="fas fa-plus"></i></button></div></div></div>
                            <div class="col-md-4"><div class="mb-3"><label>Comuna</label><div class="input-group"><select name="comuna_id" id="comuna_id" class="form-control" disabled><option value="">Primeiro selecione o município</option></select><button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaComuna"><i class="fas fa-plus"></i></button></div></div></div>
                        </div>
                        <div class="mb-3"><label>Endereço Completo</label><textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº"></textarea></div>
                        <div class="row"><div class="col-md-6"><div class="mb-3"><label>Telefone</label><input type="text" name="telefone" class="form-control" placeholder="9xx xxx xxx"></div></div><div class="col-md-6"><div class="mb-3"><label>E-mail</label><input type="email" name="email" class="form-control" placeholder="exemplo@email.com"><small class="text-muted">Opcional</small></div></div></div>
                    </div>
                    
                    <!-- Filiação -->
                    <div class="tab-pane fade" id="filiacao">
                        <h5 class="mb-3">Dados do Pai</h5>
                        <div class="row"><div class="col-md-6"><div class="mb-3"><label>Nome Completo do Pai</label><input type="text" name="pai_nome" class="form-control"></div></div><div class="col-md-3"><div class="mb-3"><label>BI do Pai</label><input type="text" name="pai_bi" class="form-control"></div></div><div class="col-md-3"><div class="mb-3"><label>Telefone do Pai</label><input type="text" name="pai_telefone" class="form-control"></div></div><div class="col-md-12"><div class="mb-3"><label>Profissão do Pai</label><input type="text" name="pai_profissao" class="form-control"></div></div></div>
                        <h5 class="mb-3 mt-4">Dados da Mãe</h5>
                        <div class="row"><div class="col-md-6"><div class="mb-3"><label>Nome Completo da Mãe</label><input type="text" name="mae_nome" class="form-control"></div></div><div class="col-md-3"><div class="mb-3"><label>BI da Mãe</label><input type="text" name="mae_bi" class="form-control"></div></div><div class="col-md-3"><div class="mb-3"><label>Telefone da Mãe</label><input type="text" name="mae_telefone" class="form-control"></div></div><div class="col-md-12"><div class="mb-3"><label>Profissão da Mãe</label><input type="text" name="mae_profissao" class="form-control"></div></div></div>
                    </div>
                    
                    <!-- Encarregado COM SELEÇÃO -->
                    <div class="tab-pane fade" id="encarregado">
                        <div class="alert alert-info"><i class="fas fa-info-circle"></i> Se o encarregado for diferente dos pais, preencha os dados abaixo ou selecione um existente.</div>
                        
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
                            <div class="col-md-6"><div class="mb-3"><label>Nome do Encarregado</label><input type="text" name="encarregado_nome" id="encarregado_nome" class="form-control"></div></div>
                            <div class="col-md-6"><div class="mb-3"><label>Parentesco</label><select name="encarregado_parentesco" id="encarregado_parentesco" class="form-control"><option value="">Selecione...</option><option value="Pai">Pai</option><option value="Mãe">Mãe</option><option value="Tio">Tio</option><option value="Tia">Tia</option><option value="Avô">Avô</option><option value="Avó">Avó</option><option value="Irmão">Irmão</option><option value="Irmã">Irmã</option><option value="Outro">Outro</option></select></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-4"><div class="mb-3"><label>BI do Encarregado</label><input type="text" name="encarregado_bi" id="encarregado_bi" class="form-control"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label>Telefone</label><input type="text" name="encarregado_telefone" id="encarregado_telefone" class="form-control"></div></div>
                            <div class="col-md-4"><div class="mb-3"><label>E-mail</label><input type="email" name="encarregado_email" id="encarregado_email" class="form-control"></div></div>
                        </div>
                        <div class="row"><div class="col-md-12"><div class="mb-3"><label>Endereço do Encarregado</label><textarea name="encarregado_endereco" id="encarregado_endereco" class="form-control" rows="2"></textarea></div></div></div>
                    </div>
                    
                    <!-- Dados Académicos -->
                    <div class="tab-pane fade" id="academicos">
                        <div class="row"><div class="col-md-6"><div class="mb-3"><label>Ano Letivo</label><select name="ano_letivo" class="form-control"><option value="<?php echo $ano_letivo_ano; ?>"><?php echo $ano_letivo_ano; ?></option></select></div></div><div class="col-md-6"><div class="mb-3"><label class="required">Turma</label><select name="turma_id" id="turma_id" class="form-control" required><option value="">Selecione...</option><?php foreach ($turmas as $t): ?><option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['nome']); ?> (<?php echo $t['ano']; ?> - <?php echo ucfirst($t['turno']); ?>)</option><?php endforeach; ?></select></div></div></div>
                        <div class="row"><div class="col-md-4"><div class="mb-3"><label>Classe / Ano</label><input type="text" id="ano_escolar" class="form-control" readonly disabled></div></div><div class="col-md-4"><div class="mb-3"><label>Turno</label><input type="text" id="turno" class="form-control" readonly disabled></div></div><div class="col-md-4"><div class="mb-3"><label>Sala</label><input type="text" id="sala" class="form-control" readonly disabled></div></div></div>
                        <div class="row"><div class="col-md-6"><div class="mb-3"><label>Nível de Ensino</label><select name="nivel_id" id="nivel_id" class="form-control"><option value="">Selecione...</option><?php foreach ($niveis as $n): ?><option value="<?php echo $n['id']; ?>"><?php echo htmlspecialchars($n['nome']); ?></option><?php endforeach; ?></select></div></div><div class="col-md-6"><div class="mb-3"><label>Curso</label><select name="curso_id" id="curso_id" class="form-control" disabled><option value="">Primeiro selecione o nível...</option></select></div></div></div>
                    </div>
                    
                    <!-- Documentos -->
                    <div class="tab-pane fade" id="documentos">
                        <div class="alert alert-info mb-3"><i class="fas fa-info-circle"></i> Documentos aceitos: JPG, PNG, PDF (Máx: 5MB)</div>
                        <div class="row">
                            <div class="col-md-6"><div class="card mb-3"><div class="card-body text-center"><i class="fas fa-id-card fa-3x text-primary mb-2"></i><h6>BI / Documento</h6><input type="file" name="bi_documento" id="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')"><div id="bi_preview" class="mt-2"></div><button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('bi_documento', 'bi_preview', 'bi')"><i class="fas fa-camera"></i> Scanner BI</button></div></div></div>
                            <div class="col-md-6"><div class="card mb-3"><div class="card-body text-center"><i class="fas fa-certificate fa-3x text-success mb-2"></i><h6>Certificado</h6><input type="file" name="certificado_documento" id="certificado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'certificado_preview')"><div id="certificado_preview" class="mt-2"></div><button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('certificado_documento', 'certificado_preview', 'certificado')"><i class="fas fa-camera"></i> Scanner Certificado</button></div></div></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><div class="card mb-3"><div class="card-body text-center"><i class="fas fa-stethoscope fa-3x text-info mb-2"></i><h6>Atestado Médico</h6><input type="file" name="atestado_documento" id="atestado_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'atestado_preview')"><div id="atestado_preview" class="mt-2"></div><button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('atestado_documento', 'atestado_preview', 'atestado')"><i class="fas fa-camera"></i> Scanner Atestado</button></div></div></div>
                            <div class="col-md-6"><div class="card mb-3"><div class="card-body text-center"><i class="fas fa-file-alt fa-3x text-warning mb-2"></i><h6>Declaração</h6><input type="file" name="declaracao_documento" id="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'declaracao_preview')"><div id="declaracao_preview" class="mt-2"></div><button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('declaracao_documento', 'declaracao_preview', 'declaracao')"><i class="fas fa-camera"></i> Scanner Declaração</button></div></div></div>
                        </div>
                        <h6 class="mt-3">Outros Documentos</h6>
                        <div class="row" id="outros-documentos">
                            <div class="col-md-4"><div class="mb-2"><input type="file" name="outro_documento_1" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro1_preview')"><div id="outro1_preview" class="mt-1"></div></div></div>
                            <div class="col-md-4"><div class="mb-2"><input type="file" name="outro_documento_2" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro2_preview')"><div id="outro2_preview" class="mt-1"></div></div></div>
                            <div class="col-md-4"><div class="mb-2"><input type="file" name="outro_documento_3" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro3_preview')"><div id="outro3_preview" class="mt-1"></div></div></div>
                        </div>
                        <div class="text-center mt-2"><button type="button" class="btn btn-sm btn-outline-primary" onclick="adicionarCampoDocumento()"><i class="fas fa-plus"></i> Adicionar documento</button></div>
                    </div>
                    
                    <!-- Foto -->
                    <div class="tab-pane fade" id="foto">
                        <div class="row">
                            <div class="col-md-6 text-center"><h5>Upload de Foto</h5><input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*" onchange="previewFoto(this)"><div class="preview-container"><img id="fotoPreview" src="../../assets/images/avatar-padrao.png" class="preview-img"></div></div>
                            <div class="col-md-6 text-center"><h5>Capturar com Webcam</h5><div class="webcam-container"><video id="video" width="100%" autoplay></video><button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar Foto</button><button type="button" id="recarregarCamBtn" class="btn btn-secondary btn-sm mt-2">Recarregar Câmara</button><canvas id="canvas" style="display:none;"></canvas></div></div>
                        </div>
                    </div>
                </div>
                
                <div class="text-center mt-4">
                    <button type="submit" class="btn btn-primary btn-lg px-5" <?php echo !$tem_turmas ? 'disabled' : ''; ?>><i class="fas fa-save"></i> Cadastrar Aluno</button>
                    <a href="listar_alunos.php" class="btn btn-secondary btn-lg px-5 ms-2"><i class="fas fa-times"></i> Cancelar</a>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modais -->
<div class="modal fade" id="modalNovoPais" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Adicionar País</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novoPais" class="form-control" placeholder="Nome do País"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarPaisBtn">Salvar</button></div></div></div></div>
<div class="modal fade" id="modalNovaCidade" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Adicionar Cidade</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>País</label><input type="text" id="cidadePais" class="form-control" readonly></div><div class="mb-3"><label>Nome da Cidade</label><input type="text" id="novaCidade" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarCidadeBtn">Salvar</button></div></div></div></div>
<div class="modal fade" id="modalNovaProvincia" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Adicionar Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaProvincia" class="form-control" placeholder="Nome da Província"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div></div>
<div class="modal fade" id="modalNovoMunicipio" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Adicionar Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Província</label><input type="text" id="municipioProvincia" class="form-control" readonly></div><div class="mb-3"><label>Nome do Município</label><input type="text" id="novoMunicipio" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div></div>
<div class="modal fade" id="modalNovaComuna" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5>Adicionar Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="mb-3"><label>Município</label><input type="text" id="comunaMunicipio" class="form-control" readonly></div><div class="mb-3"><label>Nome da Comuna</label><input type="text" id="novaComuna" class="form-control"></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div></div>

<!-- Modal para Selecionar Encarregado Existente -->
<div class="modal fade" id="modalSelecionarResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="fas fa-users"></i> Selecionar Encarregado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="row mb-3"><div class="col-md-8"><input type="text" id="buscarResponsavel" class="form-control" placeholder="Buscar por nome, BI, telefone ou email..."></div><div class="col-md-4"><button class="btn btn-primary w-100" onclick="buscarResponsaveis()"><i class="fas fa-search"></i> Buscar</button></div></div>
                <div id="listaResponsaveis" style="max-height: 400px; overflow-y: auto;"><div class="text-center text-muted py-4"><i class="fas fa-search fa-2x mb-2"></i><p>Digite um termo para buscar responsáveis</p></div></div>
                <hr><div class="text-center"><button type="button" class="btn btn-outline-success" onclick="abrirModalNovoResponsavel()"><i class="fas fa-plus-circle"></i> Cadastrar Novo Encarregado</button></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>

<!-- Modal para Selecionar Responsável -->
<!-- Modal para Selecionar Encarregado Existente (MODIFICADO) -->
<div class="modal fade" id="modalSelecionarResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="fas fa-users"></i> Selecionar Encarregado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
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
                
                <!-- Lista de Responsáveis com botão Editar -->
                <div id="listaResponsaveis" style="max-height: 400px; overflow-y: auto;">
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-search fa-2x mb-2"></i>
                        <p>Digite um termo para buscar responsáveis</p>
                    </div>
                </div>
                
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

<!-- Modal para Editar Encarregado -->
<div class="modal fade" id="modalEditarResponsavel" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fas fa-user-edit"></i> Editar Encarregado</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="formEditarResponsavel" method="POST">
                <input type="hidden" name="editar_responsavel" value="1">
                <input type="hidden" name="responsavel_id" id="editar_responsavel_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Nome Completo *</label>
                                <input type="text" name="nome" id="editar_nome" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Parentesco *</label>
                                <select name="parentesco" id="editar_parentesco" class="form-control" required>
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
                                <input type="text" name="bi" id="editar_bi" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Telefone Principal *</label>
                                <input type="text" name="telefone" id="editar_telefone" class="form-control" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Telefone Alternativo</label>
                                <input type="text" name="telefone2" id="editar_telefone2" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label>Email</label>
                                <input type="email" name="email" id="editar_email" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Profissão</label>
                                <input type="text" name="profissao" id="editar_profissao" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label>Estado Civil</label>
                                <select name="estado_civil" id="editar_estado_civil" class="form-control">
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
                                <input type="text" name="provincia" id="editar_provincia" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Município</label>
                                <input type="text" name="municipio" id="editar_municipio" class="form-control">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label>Bairro</label>
                                <input type="text" name="bairro" id="editar_bairro" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label>Endereço Completo</label>
                        <textarea name="endereco" id="editar_endereco" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label>Observações</label>
                        <textarea name="observacoes" id="editar_observacoes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-warning">Atualizar Dados</button>
                </div>
            </form>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Variáveis globais
let scannerStream = null;
let fotoCapturada = null;
let currentInputId = null;
let currentPreviewId = null;
let currentDocumentoTipo = null;
let cropper = null;
let currentZoom = 1;
let documentoCounter = 4;

const documentoTipos = {
    'bi': { nome: 'BI_Documento', inputId: 'bi_documento', previewId: 'bi_preview', tamanhoMax: 5, orientacao: 'retrato' },
    'certificado': { nome: 'Certificado', inputId: 'certificado_documento', previewId: 'certificado_preview', tamanhoMax: 5, orientacao: 'paisagem' },
    'atestado': { nome: 'Atestado', inputId: 'atestado_documento', previewId: 'atestado_preview', tamanhoMax: 5, orientacao: 'retrato' },
    'declaracao': { nome: 'Declaracao', inputId: 'declaracao_documento', previewId: 'declaracao_preview', tamanhoMax: 5, orientacao: 'retrato' }
};

// Menu Toggle
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    if (menuToggle && sidebar) {
        menuToggle.addEventListener('click', function(e) {
            e.preventDefault();
            sidebar.classList.toggle('open');
            const icon = menuToggle.querySelector('i');
            if (icon) icon.classList.toggle('fa-bars', !sidebar.classList.contains('open'));
            if (icon) icon.classList.toggle('fa-times', sidebar.classList.contains('open'));
        });
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('open');
                    const icon = menuToggle.querySelector('i');
                    if (icon) icon.classList.remove('fa-times'), icon.classList.add('fa-bars');
                }
            }
        });
    }
    document.querySelectorAll('.has-submenu > .nav-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const parentLi = this.closest('.has-submenu');
            document.querySelectorAll('.has-submenu').forEach(item => { if (item !== parentLi) item.classList.remove('open'); });
            parentLi.classList.toggle('open');
        });
    });
    const currentUrl = window.location.pathname;
    document.querySelectorAll('.has-submenu').forEach(menu => {
        const links = menu.querySelectorAll('.nav-submenu a');
        if (Array.from(links).some(link => currentUrl.includes(link.getAttribute('href')))) menu.classList.add('open');
    });
});

// Relacionamentos em cascata
$('#pais_id').change(function() {
    var paisId = $(this).val();
    if (paisId) {
        $.ajax({ url: window.location.href, method: 'GET', data: { acao: 'get_cidades', pais_id: paisId }, success: function(data) {
            var cidades = JSON.parse(data);
            var options = '<option value="">Selecione...</option>';
            for (var i = 0; i < cidades.length; i++) options += '<option value="' + cidades[i].id + '">' + cidades[i].nome + '</option>';
            $('#cidade_id').html(options).prop('disabled', false);
        }, error: function() { $('#cidade_id').html('<option value="">Erro</option>'); } });
    } else { $('#cidade_id').html('<option value="">Selecione país</option>').prop('disabled', true); }
});

$('#provincia_id').change(function() {
    var provinciaId = $(this).val();
    if (provinciaId) {
        $.ajax({ url: window.location.href, method: 'GET', data: { acao: 'get_municipios', provincia_id: provinciaId }, success: function(data) {
            var municipios = JSON.parse(data);
            var options = '<option value="">Selecione...</option>';
            for (var i = 0; i < municipios.length; i++) options += '<option value="' + municipios[i].id + '">' + municipios[i].nome + '</option>';
            $('#municipio_id').html(options).prop('disabled', false);
        }, error: function() { $('#municipio_id').html('<option value="">Erro</option>'); } });
    } else { $('#municipio_id').html('<option value="">Selecione província</option>').prop('disabled', true); $('#comuna_id').prop('disabled', true); }
});

$('#municipio_id').change(function() {
    var municipioId = $(this).val();
    if (municipioId) {
        $.ajax({ url: window.location.href, method: 'GET', data: { acao: 'get_comunas', municipio_id: municipioId }, success: function(data) {
            var comunas = JSON.parse(data);
            var options = '<option value="">Selecione...</option>';
            for (var i = 0; i < comunas.length; i++) options += '<option value="' + comunas[i].id + '">' + comunas[i].nome + '</option>';
            $('#comuna_id').html(options).prop('disabled', false);
        }, error: function() { $('#comuna_id').html('<option value="">Erro</option>'); } });
    } else { $('#comuna_id').html('<option value="">Selecione município</option>').prop('disabled', true); }
});

$('#turma_id').change(function() {
    var turmaId = $(this).val();
    if (turmaId) {
        $.ajax({ url: window.location.href, method: 'GET', data: { acao: 'get_turma', turma_id: turmaId }, success: function(data) {
            var turma = JSON.parse(data);
            if (turma) { $('#ano_escolar').val(turma.ano); $('#turno').val(turma.turno == 'manha' ? 'Manhã' : 'Tarde'); $('#sala').val(turma.sala || ''); }
        } });
    }
});

$('#nivel_id').change(function() {
    var nivelId = $(this).val();
    var cursoSelect = $('#curso_id');
    cursoSelect.html('<option value="">Carregando...</option>');
    if (!nivelId) { cursoSelect.html('<option value="">Selecione o nível</option>'); return; }
    fetch('get_cursos.php?nivel_id=' + nivelId).then(r => r.json()).then(data => {
        if (!data.success) { cursoSelect.html('<option value="">Erro</option>'); return; }
        if (data.cursos.length === 0) { cursoSelect.html('<option value="">Nenhum curso</option>').prop('disabled', true); return; }
        let html = '<option value="">Selecione...</option>';
        data.cursos.forEach(curso => { html += `<option value="${curso.id}">${curso.codigo ? '[' + curso.codigo + '] ' : ''}${curso.nome}</option>`; });
        cursoSelect.html(html).prop('disabled', false);
    }).catch(() => { cursoSelect.html('<option value="">Erro</option>'); });
});

// Preview de foto
function previewFoto(input) {
    const file = input.files[0];
    if (file) { const reader = new FileReader(); reader.onload = e => $('#fotoPreview').attr('src', e.target.result); reader.readAsDataURL(file); }
}

function previewDocumento(input, previewId) {
    const preview = document.getElementById(previewId);
    if (!preview) return;
    preview.innerHTML = '';
    if (input.files && input.files[0]) {
        const file = input.files[0];
        const reader = new FileReader();
        reader.onload = e => { preview.innerHTML = `<div class="position-relative d-inline-block"><img src="${e.target.result}" class="img-thumbnail" style="max-height:100px"><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerDocumento('${previewId}', '${input.id}')"><i class="fas fa-times"></i></button></div><small>${file.name}</small>`; };
        reader.readAsDataURL(file);
    }
}

function removerDocumento(previewId, inputId) {
    Swal.fire({ title: 'Confirmar', text: 'Remover documento?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545', confirmButtonText: 'Remover' }).then(result => {
        if (result.isConfirmed) { document.getElementById(previewId).innerHTML = ''; document.getElementById(inputId).value = ''; Swal.fire('Removido!', '', 'success'); }
    });
}

function adicionarCampoDocumento() {
    const container = document.getElementById('outros-documentos');
    const newCol = document.createElement('div'); newCol.className = 'col-md-4';
    newCol.innerHTML = `<div class="mb-2 position-relative"><input type="file" name="outro_documento_${documentoCounter}" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro${documentoCounter}_preview')"><div id="outro${documentoCounter}_preview" class="mt-1"></div><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="removerCampoDocumento(this)"><i class="fas fa-times"></i></button></div>`;
    container.appendChild(newCol);
    documentoCounter++;
}
function removerCampoDocumento(btn) { btn.closest('.col-md-4').remove(); }

// Modais
$('#salvarPaisBtn').click(function() {
    var novoPais = $('#novoPais').val();
    if (novoPais) {
        Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.ajax({ url: window.location.href, method: 'POST', data: { acao: 'add_pais', novo_pais: novoPais }, dataType: 'json', success: function(data) {
            if (data.success) { $('#pais_id').append('<option value="' + data.pais + '">' + data.pais + '</option>'); $('#pais_id').val(data.pais); $('#modalNovoPais').modal('hide'); $('#novoPais').val(''); Swal.fire('Sucesso!', 'País adicionado!', 'success'); }
            else { Swal.fire('Erro!', 'Erro ao adicionar', 'error'); }
        }, error: function() { Swal.fire('Erro!', 'Erro de conexão', 'error'); } });
    } else { Swal.fire('Atenção!', 'Digite o nome do país', 'warning'); }
});

$('#salvarCidadeBtn').click(function() {
    var novaCidade = $('#novaCidade').val();
    var paisId = $('#pais_id').val();
    if (novaCidade && paisId) {
        Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.ajax({ url: window.location.href, method: 'POST', data: { acao: 'add_cidade', nova_cidade: novaCidade, pais_id: paisId }, dataType: 'json', success: function(data) {
            if (data.success) { $('#cidade_id').append('<option value="' + data.cidade + '">' + data.cidade + '</option>'); $('#cidade_id').val(data.cidade); $('#modalNovaCidade').modal('hide'); $('#novaCidade').val(''); Swal.fire('Sucesso!', 'Cidade adicionada!', 'success'); }
            else { Swal.fire('Erro!', 'Erro ao adicionar', 'error'); }
        }, error: function() { Swal.fire('Erro!', 'Erro de conexão', 'error'); } });
    } else { Swal.fire('Atenção!', 'Preencha os campos', 'warning'); }
});

$('#salvarProvinciaBtn').click(function() {
    var novaProvincia = $('#novaProvincia').val();
    if (novaProvincia) {
        Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.ajax({ url: window.location.href, method: 'POST', data: { acao: 'add_provincia', nova_provincia: novaProvincia }, dataType: 'json', success: function(data) {
            if (data.success) { $('#provincia_id').append('<option value="' + data.provincia + '">' + data.provincia + '</option>'); $('#provincia_id').val(data.provincia); $('#modalNovaProvincia').modal('hide'); $('#novaProvincia').val(''); Swal.fire('Sucesso!', 'Província adicionada!', 'success'); }
            else { Swal.fire('Erro!', 'Erro ao adicionar', 'error'); }
        }, error: function() { Swal.fire('Erro!', 'Erro de conexão', 'error'); } });
    } else { Swal.fire('Atenção!', 'Digite o nome da província', 'warning'); }
});

$('#salvarMunicipioBtn').click(function() {
    var novoMunicipio = $('#novoMunicipio').val();
    var provinciaId = $('#provincia_id').val();
    if (novoMunicipio && provinciaId) {
        Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.ajax({ url: window.location.href, method: 'POST', data: { acao: 'add_municipio', novo_municipio: novoMunicipio, provincia_id: provinciaId }, dataType: 'json', success: function(data) {
            if (data.success) { $('#municipio_id').append('<option value="' + data.municipio + '">' + data.municipio + '</option>'); $('#municipio_id').val(data.municipio); $('#modalNovoMunicipio').modal('hide'); $('#novoMunicipio').val(''); Swal.fire('Sucesso!', 'Município adicionado!', 'success'); }
            else { Swal.fire('Erro!', 'Erro ao adicionar', 'error'); }
        }, error: function() { Swal.fire('Erro!', 'Erro de conexão', 'error'); } });
    } else { Swal.fire('Atenção!', 'Preencha os campos', 'warning'); }
});

$('#salvarComunaBtn').click(function() {
    var novaComuna = $('#novaComuna').val();
    var municipioId = $('#municipio_id').val();
    if (novaComuna && municipioId) {
        Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading(), allowOutsideClick: false });
        $.ajax({ url: window.location.href, method: 'POST', data: { acao: 'add_comuna', nova_comuna: novaComuna, municipio_id: municipioId }, dataType: 'json', success: function(data) {
            if (data.success) { $('#comuna_id').append('<option value="' + data.comuna + '">' + data.comuna + '</option>'); $('#comuna_id').val(data.comuna); $('#modalNovaComuna').modal('hide'); $('#novaComuna').val(''); Swal.fire('Sucesso!', 'Comuna adicionada!', 'success'); }
            else { Swal.fire('Erro!', 'Erro ao adicionar', 'error'); }
        }, error: function() { Swal.fire('Erro!', 'Erro de conexão', 'error'); } });
    } else { Swal.fire('Atenção!', 'Preencha os campos', 'warning'); }
});

// Scanner de Documentos
function capturarDocumento(inputId, previewId, tipoDocumento) {
    currentInputId = inputId; currentPreviewId = previewId; currentDocumentoTipo = tipoDocumento;
    new bootstrap.Modal(document.getElementById('modalScanner')).show();
    iniciarCameraScanner();
}
async function iniciarCameraScanner() {
    const video = document.getElementById('scannerVideo');
    try {
        if (scannerStream) scannerStream.getTracks().forEach(track => track.stop());
        scannerStream = await navigator.mediaDevices.getUserMedia({ video: true });
        video.srcObject = scannerStream;
        await video.play();
    } catch (err) { alert('Erro ao acessar câmera'); }
}
function tirarFotoScanner() {
    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    canvas.width = video.videoWidth; canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    fotoCapturada = canvas.toDataURL('image/jpeg', 0.9);
    document.getElementById('previewCaptura').innerHTML = `<img src="${fotoCapturada}" style="max-width:100%; max-height:150px; border-radius:8px;">`;
}
function confirmarCaptura() {
    if (!fotoCapturada) { alert('Tire uma foto primeiro!'); return; }
    const config = documentoTipos[currentDocumentoTipo];
    fetch(fotoCapturada).then(res => res.blob()).then(blob => {
        const file = new File([blob], `${config.nome}_${Date.now()}.jpg`, { type: 'image/jpeg' });
        const dataTransfer = new DataTransfer(); dataTransfer.items.add(file);
        const inputFile = document.getElementById(currentInputId);
        inputFile.files = dataTransfer.files;
        previewDocumento(inputFile, currentPreviewId);
        fecharModaisScanner();
        Swal.fire('Sucesso!', 'Documento capturado!', 'success');
    });
}
function fecharModaisScanner() {
    const modal = bootstrap.Modal.getInstance(document.getElementById('modalScanner'));
    if (modal) modal.hide();
    if (scannerStream) scannerStream.getTracks().forEach(track => track.stop());
    scannerStream = null; fotoCapturada = null;
}
function ajustarDocumento() { if (!fotoCapturada) { alert('Tire uma foto primeiro!'); return; } document.getElementById('imagemAjuste').src = fotoCapturada; new bootstrap.Modal(document.getElementById('modalAjuste')).show(); setTimeout(() => { if (cropper) cropper.destroy(); cropper = new Cropper(document.getElementById('imagemAjuste'), { aspectRatio: NaN, viewMode: 1, dragMode: 'crop', cropBoxMovable: true, cropBoxResizable: true }); }, 100); }
function aplicarCorte() { if (cropper) { const canvas = cropper.getCroppedCanvas(); fotoCapturada = canvas.toDataURL('image/jpeg', 0.9); document.getElementById('previewCaptura').innerHTML = `<img src="${fotoCapturada}" style="max-width:100%; max-height:150px;">`; bootstrap.Modal.getInstance(document.getElementById('modalAjuste')).hide(); cropper.destroy(); cropper = null; Swal.fire('Sucesso!', 'Documento ajustado!', 'success'); } }
function girarImagem() { if (cropper) cropper.rotate(90); }
function aplicarFiltro() { if (cropper) cropper.setDragMode('move'); }
function resetarAjuste() { if (cropper) cropper.reset(); }

// Webcam
const video = document.getElementById('video');
const canvas = document.getElementById('canvas');
const capturarBtn = document.getElementById('capturarBtn');
const recarregarCamBtn = document.getElementById('recarregarCamBtn');
let stream = null;

function iniciarWebcam() {
    if (stream) stream.getTracks().forEach(track => track.stop());
    navigator.mediaDevices.getUserMedia({ video: true }).then(mediaStream => { stream = mediaStream; video.srcObject = stream; }).catch(() => alert('Não foi possível acessar a câmara.'));
}
iniciarWebcam();
recarregarCamBtn?.addEventListener('click', iniciarWebcam);
capturarBtn?.addEventListener('click', function() {
    canvas.width = video.videoWidth; canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const fotoData = canvas.toDataURL('image/png');
    $('#fotoPreview').attr('src', fotoData);
    fetch(fotoData).then(res => res.blob()).then(blob => {
        const file = new File([blob], 'foto_capturada_' + Date.now() + '.png', { type: 'image/png' });
        const dataTransfer = new DataTransfer(); dataTransfer.items.add(file);
        document.getElementById('fotoInput').files = dataTransfer.files;
    });
    document.getElementById('foto_capturada').value = fotoData;
});

function copiarCredenciais() {
    const credenciais = `Usuário: <?php echo isset($usuario_acesso) ? $usuario_acesso : ''; ?>\nSenha: <?php echo isset($senha_acesso) ? $senha_acesso : ''; ?>`;
    navigator.clipboard.writeText(credenciais);
    Swal.fire({ icon: 'success', title: 'Copiado!', timer: 2000, showConfirmButton: false });
}

$('#modalNovaCidade').on('show.bs.modal', function() { $('#cidadePais').val($('#pais_id option:selected').text()); });
$('#modalNovoMunicipio').on('show.bs.modal', function() { $('#municipioProvincia').val($('#provincia_id option:selected').text()); });
$('#modalNovaComuna').on('show.bs.modal', function() { $('#comunaMunicipio').val($('#municipio_id option:selected').text()); });
</script>






<script>
// Enviar formulário novo responsável

        $('#btnLimparResponsavel').click(function() {
            $('#encarregado_id').val(''); $('#encarregado_nome').val(''); $('#encarregado_parentesco').val(''); $('#encarregado_bi').val(''); $('#encarregado_telefone').val(''); $('#encarregado_email').val(''); $('#encarregado_endereco').val('');
            Swal.fire('Limpo!', 'Campos do encarregado limpos.', 'info');
        });

        document.getElementById('formNovoResponsavel')?.addEventListener('submit', function(e) {
            e.preventDefault();
            Swal.fire({ title: 'A processar...', didOpen: () => Swal.showLoading() });
            fetch('salvar_responsavel.php', { method: 'POST', body: new FormData(this) }).then(r=>r.json()).then(data=>{
                if(data.success) {
                    bootstrap.Modal.getInstance(document.getElementById('modalNovoResponsavel')).hide();
                    selecionarResponsavel(data.id, data.nome, data.parentesco, data.bi, data.telefone, data.email, data.endereco);
                    Swal.fire('Sucesso!', 'Responsável cadastrado e selecionado.', 'success');
                } else { Swal.fire('Erro!', data.message || 'Erro ao cadastrar', 'error'); }
            }).catch(()=>{ Swal.fire('Erro!', 'Erro ao cadastrar responsável', 'error'); });
        });


document.getElementById('formNovoResponsavel').addEventListener('submit', function(e) {
    e.preventDefault();
    
    // Mostrar loading apenas no botão
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    submitBtn.disabled = true;
    
    const formData = new FormData(this);
    formData.append('escola_id', <?php echo $escola_id ?? 1; ?>);
    
    fetch('salvar_responsavel.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Restaurar botão
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
        
        if (data.success) {
            // Fechar TODOS os modais
            const modais = ['modalNovoResponsavel', 'modalSelecionarResponsavel'];
            modais.forEach(modalId => {
                const modalElement = document.getElementById(modalId);
                if (modalElement) {
                    const modal = bootstrap.Modal.getInstance(modalElement);
                    if (modal) {
                        modal.hide();
                    }
                }
            });
            
            // REMOVER TODOS OS BACKDROPS
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                backdrop.remove();
            });
            
            // RESTAURAR O BODY
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
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
            }).then(() => {
                // Garantir que após o SweetAlert fechar, tudo esteja normal
                document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
                    backdrop.remove();
                });
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
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
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
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
    
    // Fechar TODOS os modais
    const modais = ['modalSelecionarResponsavel', 'modalNovoResponsavel'];
    modais.forEach(modalId => {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) {
                modal.hide();
            }
        }
    });
    
    // REMOVER TODOS OS BACKDROPS
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.remove();
    });
    
    // RESTAURAR O BODY
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Usar toast em vez de SweetAlert (menos invasivo)
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 p-3';
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        <div class="toast show" role="alert">
            <div class="toast-header bg-success text-white">
                <i class="fas fa-check-circle me-2"></i>
                <strong class="me-auto">Sucesso</strong>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
            </div>
            <div class="toast-body">
                Responsável ${nome} selecionado com sucesso!
            </div>
        </div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 2000);
}

// Função global para limpar tudo
function limparTudo() {
    // Remover todos os backdrops
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => {
        backdrop.remove();
    });
    
    // Restaurar o body
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    // Remover qualquer classe modal-open residual
    document.body.classList.remove('modal-open');
    
    // Garantir que o scroll volte
    document.body.style.overflow = 'auto';
    document.documentElement.style.overflow = 'auto';
}

// Chamar a função em todos os eventos de fechamento de modal
document.querySelectorAll('.modal').forEach(modal => {
    modal.addEventListener('hidden.bs.modal', function() {
        setTimeout(limparTudo, 50);
    });
});

// Também chamar quando SweetAlert fechar
if (typeof Swal !== 'undefined') {
    const originalSwalFire = Swal.fire;
    Swal.fire = function(...args) {
        return originalSwalFire.apply(this, args).then((result) => {
            setTimeout(limparTudo, 50);
            return result;
        });
    };
}

// Função para buscar responsáveis

function buscarResponsaveis() {
    const termo = document.getElementById('buscarResponsavel').value;
    if (termo.length < 2) {
        Swal.fire('Atenção!', 'Digite pelo menos 2 caracteres', 'warning');
        return;
    }
    const container = document.getElementById('listaResponsaveis');
    container.innerHTML = '<div class="text-center py-4"><i class="fas fa-spinner fa-spin fa-2x"></i><p>Buscando...</p></div>';
    fetch(`buscar_responsaveis.php?termo=${encodeURIComponent(termo)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data && data.data.length > 0) {
                let html = '<div class="list-group">';
                data.data.forEach(resp => {
                    html += `
                        <div class="list-group-item list-group-item-action" style="cursor: pointer;">
                            <div class="d-flex justify-content-between align-items-center">
                                <div onclick="selecionarResponsavel(${resp.id}, '${escapeHtml(resp.nome)}', '${escapeHtml(resp.parentesco || '')}', '${escapeHtml(resp.bi || '')}', '${escapeHtml(resp.telefone || '')}', '${escapeHtml(resp.email || '')}', '${escapeHtml(resp.endereco || '')}')" style="flex:1;">
                                    <strong>${escapeHtml(resp.nome)}</strong>
                                    <br><small class="text-muted">
                                        ${escapeHtml(resp.parentesco || 'Sem parentesco')} | 
                                        ${escapeHtml(resp.telefone || 'Sem telefone')}
                                    </small>
                                </div>
                                <div class="btn-group">
                                    <button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation(); editarResponsavel(${resp.id})" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-success" onclick="event.stopPropagation(); selecionarResponsavel(${resp.id}, '${escapeHtml(resp.nome)}', '${escapeHtml(resp.parentesco || '')}', '${escapeHtml(resp.bi || '')}', '${escapeHtml(resp.telefone || '')}', '${escapeHtml(resp.email || '')}', '${escapeHtml(resp.endereco || '')}')" title="Selecionar">
                                        <i class="fas fa-check-circle"></i>
                                    </button>
                                </div>
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
        .catch(() => { container.innerHTML = '<div class="alert alert-danger">Erro ao buscar responsáveis. Tente novamente.</div>'; });
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


// Função para editar responsável
let responsavelEditando = null;

function editarResponsavel(id) {
    Swal.fire({
        title: 'Carregando...',
        text: 'Buscando dados do responsável',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`buscar_responsavel_por_id.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            Swal.close();
            if (data.success && data.responsavel) {
                const resp = data.responsavel;
                
                document.getElementById('editar_responsavel_id').value = resp.id;
                document.getElementById('editar_nome').value = resp.nome || '';
                document.getElementById('editar_parentesco').value = resp.parentesco || '';
                document.getElementById('editar_bi').value = resp.bi || '';
                document.getElementById('editar_telefone').value = resp.telefone || '';
                document.getElementById('editar_telefone2').value = resp.telefone2 || '';
                document.getElementById('editar_email').value = resp.email || '';
                document.getElementById('editar_profissao').value = resp.profissao || '';
                document.getElementById('editar_estado_civil').value = resp.estado_civil || '';
                document.getElementById('editar_provincia').value = resp.provincia || '';
                document.getElementById('editar_municipio').value = resp.municipio || '';
                document.getElementById('editar_bairro').value = resp.bairro || '';
                document.getElementById('editar_endereco').value = resp.endereco || '';
                document.getElementById('editar_observacoes').value = resp.observacoes || '';
                
                const modal = new bootstrap.Modal(document.getElementById('modalEditarResponsavel'));
                modal.show();
            } else {
                Swal.fire('Erro!', 'Não foi possível carregar os dados do responsável', 'error');
            }
        })
        .catch(error => {
            Swal.close();
            Swal.fire('Erro!', 'Erro ao carregar dados: ' + error, 'error');
        });
}

// Formulário de editar responsável
document.getElementById('formEditarResponsavel').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
    submitBtn.disabled = true;
    
    const formData = new FormData(this);
    
    fetch('salvar_responsavel.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('modalEditarResponsavel')).hide();
                
                // Recarregar a busca se estiver aberta
                const termo = document.getElementById('buscarResponsavel').value;
                if (termo.length >= 2) {
                    buscarResponsaveis();
                }
                
                Swal.fire('Sucesso!', 'Dados do responsável atualizados!', 'success');
            } else {
                Swal.fire('Erro!', data.message || 'Erro ao atualizar', 'error');
            }
        })
        .catch(error => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            Swal.fire('Erro!', 'Erro de conexão', 'error');
        });
});




</script>




</body>
</html>