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

// Buscar dados da turma
if (isset($_GET['acao']) && $_GET['acao'] == 'get_turma') {
    $turma_id = $_GET['turma_id'] ?? 0;
    if ($turma_id) {
        $stmt = $conn->prepare("SELECT id, nome, ano, turno, sala FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $turma_id]);
        $turma = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($turma);
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
    $curso = $_POST['curso'] ?? '';
    $nivel = $_POST['nivel'] ?? '';
    
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
        $stmt = $conn->prepare("SELECT ano, turno, sala FROM turmas WHERE id = :id");
        $stmt->execute([':id' => $turma_id]);
        $turma_dados = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $ano_escolar = $turma_dados['ano'] ?? '';
    $turno = $turma_dados['turno'] ?? '';
    $sala = $turma_dados['sala'] ?? '';
    
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
                usuario_id, escola_id, matricula, bi, bi_data_emissao, bi_local_emissao,
                pais_id, pais_nome, cidade_id, cidade_nome,
                provincia_id, provincia_nome, municipio_id, municipio_nome, comuna_id, comuna_nome,
                endereco, telefone, email, data_nascimento, genero, foto,
                pai_nome, pai_bi, pai_telefone, pai_profissao,
                mae_nome, mae_bi, mae_telefone, mae_profissao,
                encarregado_nome, encarregado_parentesco, encarregado_bi,
                encarregado_telefone, encarregado_email, encarregado_endereco,
                ano_letivo, ano_escolar, curso, nivel,
                numero_processo, bi_documento, certificado_documento,
                atestado_documento, outros_documentos, declaracao_documento, created_at
            ) VALUES (
                :usuario_id, :escola_id, :matricula, :bi, :bi_data_emissao, :bi_local_emissao,
                :pais_id, :pais_nome, :cidade_id, :cidade_nome,
                :provincia_id, :provincia_nome, :municipio_id, :municipio_nome, :comuna_id, :comuna_nome,
                :endereco, :telefone, :email, :data_nascimento, :genero, :foto,
                :pai_nome, :pai_bi, :pai_telefone, :pai_profissao,
                :mae_nome, :mae_bi, :mae_telefone, :mae_profissao,
                :encarregado_nome, :encarregado_parentesco, :encarregado_bi,
                :encarregado_telefone, :encarregado_email, :encarregado_endereco,
                :ano_letivo, :ano_escolar, :curso, :nivel,
                :numero_processo, :bi_documento, :certificado_documento,
                :atestado_documento, :outros_documentos, :declaracao_documento, NOW()
            )
        ");
        
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':escola_id' => $escola_id,
            ':matricula' => $numero_processo,
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
                INSERT INTO matriculas (estudante_id, turma_id,turno,sala, ano_letivo, numero_processo, status, data_matricula, created_at)
                VALUES (:estudante_id, :turma_id,:turno,:sala, :ano_letivo, :numero_matricula, 'ativa', CURDATE(), NOW())
            ");
            $stmt->execute([
                ':estudante_id' => $estudante_id,
                ':turma_id' => $turma_id,
                 ':turno' => $turno ?: null,
                 ':sala' => $sala ?: null,
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
                                        <label>Curso</label>
                                        <input type="text" name="curso" class="form-control" placeholder="Ex: Ciências, Humanidades, etc.">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nível</label>
                                        <select name="nivel" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="Iniciação">Iniciação</option>
                                            <option value="I Ciclo">I Ciclo</option>
                                            <option value="II Ciclo">II Ciclo</option>
                                            <option value="III Ciclo">III Ciclo</option>
                                            <option value="Pré-Universitário">Pré-Universitário</option>
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
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="card mb-3">
                                        <div class="card-body text-center">
                                            <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                                            <h6>BI / Documento de Identificação</h6>
                                            <input type="file" name="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                                            <div id="bi_preview" class="mt-2"></div>
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('bi_documento', 'bi_preview')">
                                                <i class="fas fa-camera"></i> Capturar
                                            </button>
                                            <small class="text-muted d-block">PDF, JPG, PNG (Max: 2MB)</small>
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
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('certificado_documento', 'certificado_preview')">
                                                <i class="fas fa-camera"></i> Capturar
                                            </button>
                                            <small class="text-muted d-block">PDF, JPG, PNG (Max: 2MB)</small>
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
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('atestado_documento', 'atestado_preview')">
                                                <i class="fas fa-camera"></i> Capturar
                                            </button>
                                            <small class="text-muted d-block">PDF, JPG, PNG (Max: 2MB)</small>
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
                                            <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="capturarDocumento('declaracao_documento', 'declaracao_preview')">
                                                <i class="fas fa-camera"></i> Capturar
                                            </button>
                                            <small class="text-muted d-block">PDF, JPG, PNG (Max: 2MB)</small>
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
        function previewDocumento(input, previewId) {
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
        
        function capturarDocumento(inputName, previewId) {
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