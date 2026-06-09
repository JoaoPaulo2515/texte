<?php
// escola/rh/funcionarios/cadastrar.php - Cadastro de Funcionário com Documentos e Scanner
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$ano_letivo = date('Y');

// ============================================
// FUNÇÕES DE VALIDAÇÃO (CONTEXTO ANGOLANO)
// ============================================

// Função para gerar número de processo
function gerarNumeroProcessoFuncionario($conn, $escola_id) {
    $ano = date('Y');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM funcionarios WHERE escola_id = ? AND YEAR(created_at) = ?");
    $stmt->execute([$escola_id, $ano]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
    return "FUNC/{$escola_id}/{$ano}/{$sequencial}";
}

// Função para validar BI de Angola
function validarBIAngola($bi) {
    $bi = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bi));
    if (preg_match('/^[0-9]{9}[A-Z]{2}[0-9]{3}$/', $bi)) {
        return $bi;
    }
    return false;
}

// Função para validar telefone Angola
function validarTelefoneAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (preg_match('/^9[1-9][0-9]{7}$/', $telefone)) {
        return $telefone;
    }
    return false;
}

// Função para validar NIF (NUIT) Angola
function validarNUIT($nuit) {
    $nuit = preg_replace('/[^0-9]/', '', $nuit);
    if (preg_match('/^[0-9]{9}$/', $nuit)) {
        return $nuit;
    }
    return false;
}

// Função para detectar formato do papel
function detectarFormatoPapel($caminho_imagem) {
    if (!file_exists($caminho_imagem)) return 'Outro';
    try {
        list($largura, $altura) = getimagesize($caminho_imagem);
        $ratio = max($largura, $altura) / min($largura, $altura);
        if ($ratio > 1.4 && $ratio < 1.45) return 'A4';
        if ($ratio > 1.29 && $ratio < 1.32) return 'A5';
        if ($ratio > 1.4 && $ratio < 1.42) return 'A3';
        if ($ratio > 1.29 && $ratio < 1.3) return 'Carta';
        return 'Outro';
    } catch (Exception $e) {
        return 'Outro';
    }
}

// Função para processar imagem do scanner
function processarImagemScanner($imagem_base64) {
    $data = explode(',', $imagem_base64);
    if (count($data) > 1) {
        return base64_decode($data[1]);
    }
    return null;
}

// Função para criar avatar padrão
function criarAvatarFuncionario($nome, $tamanho = 200) {
    $avatar_dir = __DIR__ . '/../../../uploads/avatares/';
    if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0777, true);
    
    $iniciais = strtoupper(substr($nome, 0, 2));
    $cores = ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#6f42c1', '#fd7e14'];
    $cor = $cores[abs(crc32($nome)) % count($cores)];
    
    $imagem = imagecreate($tamanho, $tamanho);
    $bg_color = imagecolorallocate($imagem, hexdec(substr($cor, 1, 2)), hexdec(substr($cor, 3, 2)), hexdec(substr($cor, 5, 2)));
    $text_color = imagecolorallocate($imagem, 255, 255, 255);
    
    imagefill($imagem, 0, 0, $bg_color);
    
    $fonte = __DIR__ . '/../../../assets/fonts/arial.ttf';
    if (!file_exists($fonte)) {
        $fonte = 5;
        $texto = $iniciais;
        $x = ($tamanho - imagefontwidth($fonte) * strlen($texto)) / 2;
        $y = ($tamanho - imagefontheight($fonte)) / 2;
        imagestring($imagem, $fonte, $x, $y, $texto, $text_color);
    } else {
        $fonte_size = $tamanho / 3;
        $bbox = imagettfbbox($fonte_size, 0, $fonte, $iniciais);
        $x = ($tamanho - ($bbox[2] - $bbox[0])) / 2;
        $y = ($tamanho - ($bbox[1] - $bbox[7])) / 2;
        imagettftext($imagem, $fonte_size, 0, $x, $y, $text_color, $fonte, $iniciais);
    }
    
    $nome_avatar = 'avatar_func_' . time() . '_' . uniqid() . '.png';
    imagepng($imagem, $avatar_dir . $nome_avatar);
    imagedestroy($imagem);
    
    return $nome_avatar;
}

// Função para upload de arquivo
function uploadArquivoFuncionario($arquivo, $pasta, $tipos_permitidos = ['jpg','jpeg','png','pdf'], $tamanho_maximo = 5242880) {
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
// BUSCAR DADOS PARA COMBOBOXES
// ============================================

// Verificar e criar tabelas se necessário
try {
    $check = $conn->query("SHOW TABLES LIKE 'angola_provincias'");
    if ($check->rowCount() == 0) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS angola_provincias (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100) NOT NULL UNIQUE
            )
        ");
        $provincias_default = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Namibe', 'Uíge', 'Zaire'];
        foreach ($provincias_default as $p) {
            $conn->exec("INSERT IGNORE INTO angola_provincias (nome) VALUES ('$p')");
        }
    }
    
    $check = $conn->query("SHOW TABLES LIKE 'angola_municipios'");
    if ($check->rowCount() == 0) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS angola_municipios (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100) NOT NULL,
                provincia_id INT NOT NULL,
                FOREIGN KEY (provincia_id) REFERENCES angola_provincias(id) ON DELETE CASCADE
            )
        ");
    }
    
    $check = $conn->query("SHOW TABLES LIKE 'angola_comunas'");
    if ($check->rowCount() == 0) {
        $conn->exec("
            CREATE TABLE IF NOT EXISTS angola_comunas (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nome VARCHAR(100) NOT NULL,
                municipio_id INT NOT NULL,
                FOREIGN KEY (municipio_id) REFERENCES angola_municipios(id) ON DELETE CASCADE
            )
        ");
    }
} catch (PDOException $e) {}

// Buscar províncias
$provincias = $conn->query("SELECT id, nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
if (empty($provincias)) {
    $provincias = [
        ['id' => 1, 'nome' => 'Luanda'], ['id' => 2, 'nome' => 'Benguela'], ['id' => 3, 'nome' => 'Huíla'],
        ['id' => 4, 'nome' => 'Bié'], ['id' => 5, 'nome' => 'Malanje']
    ];
}

// Buscar bancos
try {
    $bancos = $conn->prepare("SELECT id, nome, codigo FROM escola_contas_bancarias WHERE escola_id = ? AND status = 'ativo' ORDER BY nome");
    $bancos->execute([$escola_id]);
    $bancos = $bancos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $bancos = [];
}

// Buscar tipos de funcionário
$tipos_funcionario = ['professor', 'administrativo', 'auxiliar', 'seguranca', 'limpeza', 'manutencao', 'motorista', 'outro'];

// Buscar cargos
$cargos = [
    'Diretor', 'Vice-Diretor', 'Coordenador Pedagógico', 'Chefe de Departamento',
    'Professor Auxiliar', 'Professor Assistente', 'Professor Principal',
    'Secretário', 'Assistente Administrativo', 'Contabilista', 'Tesoureiro',
    'Auxiliar de Limpeza', 'Segurança', 'Motorista', 'Manutenção', 'Porteiro'
];

$estadosCivis = ['Solteiro(a)', 'Casado(a)', 'Divorciado(a)', 'Viúvo(a)', 'União de Facto'];
$tiposContrato = ['Efetivo', 'Contratado', 'Estágio Profissional', 'Temporário', 'Prestador de Serviços'];
$habilitacoes = [
    '6ª Classe', '9ª Classe', '12ª Classe', 'Formação de Professores (IMED)',
    'Bacharelato', 'Licenciatura', 'Pós-Graduação', 'Mestrado', 'Doutoramento',
    'Curso de Especialização', 'Curso de Formação Contínua'
];
$niveis_escolaridade = ['Primário', 'Secundário', 'Médio', 'Superior'];

$error = '';
$success = '';

// ============================================
// PROCESSAR AJAX
// ============================================

// Buscar municípios por província
if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia_id = $_GET['provincia_id'] ?? 0;
    if ($provincia_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_municipios WHERE provincia_id = ? ORDER BY nome");
        $stmt->execute([$provincia_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

// Buscar comunas por município
if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio_id = $_GET['municipio_id'] ?? 0;
    if ($municipio_id) {
        $stmt = $conn->prepare("SELECT id, nome FROM angola_comunas WHERE municipio_id = ? ORDER BY nome");
        $stmt->execute([$municipio_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

// Adicionar nova província
if (isset($_POST['acao']) && $_POST['acao'] == 'add_provincia') {
    $nova_provincia = trim($_POST['nova_provincia'] ?? '');
    if ($nova_provincia) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_provincias (nome) VALUES (?)");
        $stmt->execute([$nova_provincia]);
        $id = $conn->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id, 'nome' => $nova_provincia]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Adicionar novo município
if (isset($_POST['acao']) && $_POST['acao'] == 'add_municipio') {
    $novo_municipio = trim($_POST['novo_municipio'] ?? '');
    $provincia_id = $_POST['provincia_id'] ?? 0;
    if ($novo_municipio && $provincia_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_municipios (nome, provincia_id) VALUES (?, ?)");
        $stmt->execute([$novo_municipio, $provincia_id]);
        $id = $conn->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id, 'nome' => $novo_municipio]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Adicionar nova comuna
if (isset($_POST['acao']) && $_POST['acao'] == 'add_comuna') {
    $nova_comuna = trim($_POST['nova_comuna'] ?? '');
    $municipio_id = $_POST['municipio_id'] ?? 0;
    if ($nova_comuna && $municipio_id) {
        $stmt = $conn->prepare("INSERT IGNORE INTO angola_comunas (nome, municipio_id) VALUES (?, ?)");
        $stmt->execute([$nova_comuna, $municipio_id]);
        $id = $conn->lastInsertId();
        echo json_encode(['success' => true, 'id' => $id, 'nome' => $nova_comuna]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

// Processar documento do scanner
if (isset($_POST['acao']) && $_POST['acao'] == 'upload_documento_scanner') {
    header('Content-Type: application/json');
    $tipo_documento = $_POST['tipo_documento'] ?? '';
    $imagem_base64 = $_POST['imagem'] ?? '';
    
    if ($tipo_documento && $imagem_base64) {
        $imagem_data = processarImagemScanner($imagem_base64);
        if ($imagem_data) {
            $temp_file = tempnam(sys_get_temp_dir(), 'scan_');
            file_put_contents($temp_file, $imagem_data);
            $formato = detectarFormatoPapel($temp_file);
            unlink($temp_file);
            echo json_encode(['success' => true, 'formato' => $formato, 'imagem' => $imagem_base64]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao processar imagem']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Dados incompletos']);
    }
    exit;
}

// ============================================
// PROCESSAR FORMULÁRIO
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cadastrar_funcionario'])) {
    // Dados Pessoais
    $nome_completo = trim($_POST['nome_completo'] ?? '');
    $data_nascimento = $_POST['data_nascimento'] ?? '';
    $genero = $_POST['genero'] ?? '';
    $bi_numero = trim($_POST['bi_numero'] ?? '');
    $bi_emissao = $_POST['bi_emissao'] ?? '';
    $bi_validade = $_POST['bi_validade'] ?? '';
    $nuit = trim($_POST['nuit'] ?? '');
    $nacionalidade = $_POST['nacionalidade'] ?? 'Angolana';
    $naturalidade = trim($_POST['naturalidade'] ?? '');
    $provincia_id = $_POST['provincia_id'] ?? null;
    $municipio_id = $_POST['municipio_id'] ?? null;
    $comuna_id = $_POST['comuna_id'] ?? null;
    $endereco = trim($_POST['endereco'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $estado_civil = $_POST['estado_civil'] ?? '';
    $nome_pai = trim($_POST['nome_pai'] ?? '');
    $nome_mae = trim($_POST['nome_mae'] ?? '');
    $telefone_emergencia = trim($_POST['telefone_emergencia'] ?? '');
    $nome_emergencia = trim($_POST['nome_emergencia'] ?? '');
    $nivel_escolaridade = $_POST['nivel_escolaridade'] ?? '';
    
    // Dados Profissionais
    $tipo_funcionario = $_POST['tipo_funcionario'] ?? '';
    $cargo = $_POST['cargo'] ?? '';
    $data_admissao = $_POST['data_admissao'] ?? date('Y-m-d');
    $tipo_contrato = $_POST['tipo_contrato'] ?? 'Efetivo';
    $data_fim_contrato = $_POST['data_fim_contrato'] ?? null;
    $habilitacao = $_POST['habilitacao'] ?? '';
    $formacao = trim($_POST['formacao'] ?? '');
    $formacao_descricao = trim($_POST['formacao_descricao'] ?? '');
    $experiencia_anos = $_POST['experiencia_anos'] ?? 0;
    
    // Dados Bancários e Documentos
    $banco_id = $_POST['banco_id'] ?? null;
    $numero_conta = trim($_POST['numero_conta'] ?? '');
    $iban = trim($_POST['iban'] ?? '');
    $swift = trim($_POST['swift'] ?? '');
    $num_seguranca_social = trim($_POST['num_seguranca_social'] ?? '');
    $carteira_profissional = trim($_POST['carteira_profissional'] ?? '');
    
    // Validar campos obrigatórios
    $bi_validado = null;
    if (!empty($bi_numero)) {
        $bi_validado = validarBIAngola($bi_numero);
        if (!$bi_validado) {
            $error = "BI inválido! Formato: 9 números + 2 letras + 3 números (Ex: 123456789AA001)";
        }
    }
    
    $telefone_validado = validarTelefoneAngola($telefone);
    if (!$telefone_validado && $error == '') {
        $error = "Telefone inválido! Deve ser um número da Unitel, Africell ou Movicel (9XX XXX XXX)";
    }
    
    if (!empty($nuit) && !validarNUIT($nuit) && $error == '') {
        $error = "NUIT/NIF inválido! Deve ter 9 dígitos.";
    }
    
    if (!$error) {
        try {
            $conn->beginTransaction();
            
            // Verificar se BI já existe
            if ($bi_validado) {
                $stmt = $conn->prepare("SELECT id FROM funcionarios WHERE bi = ? AND escola_id = ?");
                $stmt->execute([$bi_validado, $escola_id]);
                if ($stmt->fetch()) {
                    throw new Exception("BI já cadastrado no sistema.");
                }
            }
            
            // Gerar número de processo
            $numero_processo = gerarNumeroProcessoFuncionario($conn, $escola_id);
            
            // Criar usuário automaticamente
            $email_usuario = !empty($email) ? $email : $numero_processo . '@sige.ao';
            $senha = $bi_validado ?: substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("
                INSERT INTO usuarios (escola_id, nome, email, usuario, senha, tipo, telefone, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'ativo', NOW())
            ");
            $stmt->execute([$escola_id, $nome_completo, $email_usuario, $numero_processo, $senha_hash, $tipo_funcionario, $telefone_validado]);
            $usuario_id = $conn->lastInsertId();
            
            // Upload da Foto
            $foto = null;
            $foto_capturada = $_POST['foto_capturada'] ?? '';
            
            if (!empty($foto_capturada)) {
                $foto_data = explode(',', $foto_capturada);
                if (count($foto_data) > 1) {
                    $foto_dir = __DIR__ . '/../../../uploads/funcionarios/fotos/';
                    if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
                    $foto = 'foto_func_' . time() . '_' . uniqid() . '.png';
                    file_put_contents($foto_dir . $foto, base64_decode($foto_data[1]));
                }
            } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $foto = uploadArquivoFuncionario($_FILES['foto'], __DIR__ . '/../../../uploads/funcionarios/fotos/', ['jpg','jpeg','png','gif','webp'], 2097152);
            }
            
            // Criar avatar se não houver foto
            if (!$foto) {
                $foto = criarAvatarFuncionario($nome_completo);
            }
            
            // Buscar nomes para localidades
            $provincia_nome = '';
            $municipio_nome = '';
            $comuna_nome = '';
            $banco_nome = '';
            $banco_codigo = '';
            
            if ($provincia_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_provincias WHERE id = ?");
                $stmt->execute([$provincia_id]);
                $provincia_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($municipio_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_municipios WHERE id = ?");
                $stmt->execute([$municipio_id]);
                $municipio_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($comuna_id) {
                $stmt = $conn->prepare("SELECT nome FROM angola_comunas WHERE id = ?");
                $stmt->execute([$comuna_id]);
                $comuna_nome = $stmt->fetch(PDO::FETCH_ASSOC)['nome'] ?? '';
            }
            if ($banco_id) {
                try {
                    $stmt = $conn->prepare("SELECT nome, codigo FROM escola_contas_bancarias WHERE id = ? AND escola_id = ?");
                    $stmt->execute([$banco_id, $escola_id]);
                    $banco_dados = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($banco_dados) {
                        $banco_nome = $banco_dados['nome'] ?? '';
                        $banco_codigo = $banco_dados['codigo'] ?? '';
                    }
                } catch (PDOException $e) {}
            }
            
            // INSERIR FUNCIONÁRIO
            $stmt = $conn->prepare("
                INSERT INTO funcionarios (
                    usuario_id, escola_id, numero_processo, nome, tipo_funcionario, cargo,
                    bi, bi_emissao, bi_validade, nuit, nacionalidade, naturalidade,
                    provincia_nome, municipio_nome, comuna_nome, endereco,
                    data_nascimento, genero, estado_civil, nome_pai, nome_mae,
                    telefone, telefone_emergencia, nome_emergencia, email,
                    nivel_escolaridade, data_admissao, tipo_contrato, data_fim_contrato,
                    habilitacao, formacao, formacao_descricao, experiencia_anos,
                    banco_id, banco_nome, banco_codigo, numero_conta, iban, swift,
                    num_seguranca_social, carteira_profissional, foto, status, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, 'ativo', NOW()
                )
            ");
            
            $stmt->execute([
                $usuario_id, $escola_id, $numero_processo, $nome_completo, $tipo_funcionario, $cargo,
                $bi_validado, !empty($bi_emissao) ? $bi_emissao : null, !empty($bi_validade) ? $bi_validade : null,
                !empty($nuit) ? $nuit : null, $nacionalidade, !empty($naturalidade) ? $naturalidade : null,
                !empty($provincia_nome) ? $provincia_nome : null, !empty($municipio_nome) ? $municipio_nome : null,
                !empty($comuna_nome) ? $comuna_nome : null, !empty($endereco) ? $endereco : null,
                !empty($data_nascimento) ? $data_nascimento : null, !empty($genero) ? $genero : null,
                !empty($estado_civil) ? $estado_civil : null, !empty($nome_pai) ? $nome_pai : null,
                !empty($nome_mae) ? $nome_mae : null,
                $telefone_validado, !empty($telefone_emergencia) ? $telefone_emergencia : null,
                !empty($nome_emergencia) ? $nome_emergencia : null, !empty($email) ? $email : null,
                !empty($nivel_escolaridade) ? $nivel_escolaridade : null,
                $data_admissao, $tipo_contrato, !empty($data_fim_contrato) ? $data_fim_contrato : null,
                !empty($habilitacao) ? $habilitacao : null, !empty($formacao) ? $formacao : null,
                !empty($formacao_descricao) ? $formacao_descricao : null, $experiencia_anos ?: 0,
                !empty($banco_id) ? $banco_id : null, !empty($banco_nome) ? $banco_nome : null,
                !empty($banco_codigo) ? $banco_codigo : null, !empty($numero_conta) ? $numero_conta : null,
                !empty($iban) ? $iban : null, !empty($swift) ? $swift : null,
                !empty($num_seguranca_social) ? $num_seguranca_social : null,
                !empty($carteira_profissional) ? $carteira_profissional : null,
                $foto
            ]);
            
            $funcionario_id = $conn->lastInsertId();
            
            // ============================================
            // PROCESSAR DOCUMENTOS
            // ============================================
            
            $upload_dir_docs = __DIR__ . '/../../../uploads/funcionarios/documentos/' . $funcionario_id . '/';
            if (!is_dir($upload_dir_docs)) mkdir($upload_dir_docs, 0777, true);
            
            // Array de documentos
            $documentos = [
                'bi_documento' => 'BI / Documento de Identificação',
                'diploma_documento' => 'Diploma / Certificado',
                'certificacoes_documento' => 'Certificações / Cursos',
                'declaracao_documento' => 'Declaração / Comprovativo',
                'contrato_documento' => 'Contrato de Trabalho',
                'atestado_medico_documento' => 'Atestado Médico',
                'carteira_profissional_documento' => 'Carteira Profissional',
                'seguranca_social_documento' => 'Comprovativo Segurança Social'
            ];
            
            foreach ($documentos as $campo => $label) {
                if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] == 0) {
                    $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
                    $temp_file = $_FILES[$campo]['tmp_name'];
                    $formato = detectarFormatoPapel($temp_file);
                    $nome_arquivo = str_replace('_documento', '', $campo) . '_' . time() . '_' . uniqid() . '.' . $ext;
                    
                    if (move_uploaded_file($temp_file, $upload_dir_docs . $nome_arquivo)) {
                        $stmt = $conn->prepare("
                            INSERT INTO funcionarios_documentos (funcionario_id, tipo_documento, nome_arquivo, caminho_arquivo, formato_papel, tamanho_arquivo)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $funcionario_id,
                            str_replace('_documento', '', $campo),
                            $nome_arquivo,
                            'uploads/funcionarios/documentos/' . $funcionario_id . '/' . $nome_arquivo,
                            $formato,
                            $_FILES[$campo]['size']
                        ]);
                    }
                }
            }
            
            // Processar documentos do scanner
            if (isset($_POST['documentos_scanner']) && !empty($_POST['documentos_scanner'])) {
                $documentos_scanner = json_decode($_POST['documentos_scanner'], true);
                foreach ($documentos_scanner as $doc) {
                    $tipo = $doc['tipo'];
                    $imagem_base64 = $doc['imagem'];
                    $formato = $doc['formato'];
                    
                    $imagem_data = processarImagemScanner($imagem_base64);
                    if ($imagem_data) {
                        $nome_arquivo = $tipo . '_scanner_' . time() . '_' . uniqid() . '.png';
                        file_put_contents($upload_dir_docs . $nome_arquivo, $imagem_data);
                        
                        $stmt = $conn->prepare("
                            INSERT INTO funcionarios_documentos (funcionario_id, tipo_documento, nome_arquivo, caminho_arquivo, formato_papel)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $funcionario_id, $tipo, $nome_arquivo,
                            'uploads/funcionarios/documentos/' . $funcionario_id . '/' . $nome_arquivo,
                            $formato
                        ]);
                    }
                }
            }
            
            $conn->commit();
            
            $success = "
                <div class='alert alert-success'>
                    <h5><i class='fas fa-check-circle'></i> Funcionário cadastrado com sucesso!</h5>
                    <hr>
                    <div class='row'>
                        <div class='col-md-6'>
                            <p><strong>Número de Processo:</strong> {$numero_processo}</p>
                            <p><strong>Nome:</strong> " . htmlspecialchars($nome_completo) . "</p>
                            <p><strong>Telefone:</strong> " . htmlspecialchars($telefone_validado) . "</p>
                        </div>
                        <div class='col-md-6'>
                            <p><strong>Usuário de acesso:</strong> {$email_usuario}</p>
                            <p><strong>Senha temporária:</strong> " . ($bi_validado ?: $senha) . "</p>
                            <p><strong>Cargo:</strong> " . htmlspecialchars($cargo) . "</p>
                        </div>
                    </div>
                    <div class='alert alert-warning mt-2'>
                        <i class='fas fa-exclamation-triangle'></i> 
                        Recomenda-se que o funcionário altere a senha no primeiro acesso.
                    </div>
                </div>
            ";
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Cadastrar Funcionário | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ==============================================
           DESIGN MODERNO MELHORADO
           ============================================== */
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(180deg, #0d2a3e 0%, #0a1a2e 50%, #0d2a3e 100%);
            color: white;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 4px 0 30px rgba(0, 0, 0, 0.2);
            border-radius: 0 24px 24px 0;
        }
        
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.08); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.25); border-radius: 10px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255,255,255,0.4); }
        
        .sidebar-header {
            padding: 30px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0));
        }
        
        .sidebar-header .logo { font-size: 3em; margin-bottom: 12px; transition: transform 0.3s ease; }
        .sidebar-header .logo:hover { transform: scale(1.05); }
        .sidebar-header h3 { font-size: 1.4em; margin-bottom: 5px; font-weight: 700; letter-spacing: 1px; }
        .sidebar-header p { font-size: 0.75em; opacity: 0.7; letter-spacing: 1px; }
        
        .user-info-sidebar {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.08);
            font-size: 0.8em;
            line-height: 1.6;
            background: rgba(255,255,255,0.05);
            border-radius: 16px;
            padding: 15px;
            transition: all 0.3s ease;
        }
        
        .user-info-sidebar:hover { background: rgba(255,255,255,0.08); }
        .user-info-sidebar i { width: 24px; margin-right: 8px; opacity: 0.7; }
        
        .nav-menu {
            list-style: none;
            padding: 20px 12px;
            margin: 0;
        }
        
        .nav-item { margin-bottom: 6px; }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 18px;
            color: rgba(255,255,255,0.75);
            text-decoration: none;
            gap: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
            border-radius: 14px;
            font-weight: 500;
            font-size: 0.95rem;
        }
        
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: white;
            transform: translateX(5px);
        }
        
        .nav-link i { width: 24px; text-align: center; font-size: 1.2em; }
        
        .has-submenu {
            position: relative;
        }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s ease;
            font-size: 0.75rem;
            opacity: 0.7;
        }
        
        .has-submenu.open > .nav-link::after { transform: rotate(180deg); }
        .has-submenu.open > .nav-link { 
            background: rgba(255,255,255,0.1); 
            border-radius: 14px 14px 12px 12px;
            margin-bottom: 5px;
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 50px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .has-submenu.open .nav-submenu { 
            max-height: 800px; 
            overflow-y: auto;
            margin-bottom: 8px;
        }
        
        .nav-submenu .nav-link {
            padding: 10px 18px;
            font-size: 0.85em;
            border-radius: 12px;
            margin: 3px 0;
        }
        
        .nav-submenu .nav-link:hover { 
            background: rgba(255,255,255,0.08); 
            transform: translateX(5px);
        }
        
        .nav-submenu .nav-link i { font-size: 0.9em; width: 20px; }
        
        /* Main Content */
        .main-content {
            margin-left: 280px;
            margin-top: 70px;
            margin-bottom: 45px;
            padding: 25px 30px;
            background: #f5f7fb;
            min-height: calc(100vh - 115px);
        }
        
        .top-bar {
            background: white;
            border-radius: 20px;
            padding: 18px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .top-bar h2 {
            font-size: 1.3em;
            font-weight: 700;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            margin: 0;
        }
        
        .card {
            background: white;
            border-radius: 24px;
            border: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.03), 0 1px 2px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
            overflow: hidden;
            margin-bottom: 25px;
        }
        
        .card:hover { transform: translateY(-4px); box-shadow: 0 20px 30px -12px rgba(0,0,0,0.15); }
        
        .card-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            padding: 18px 25px;
            border-bottom: none;
        }
        
        .card-header .nav-tabs {
            border-bottom: none;
            margin-bottom: -18px;
        }
        
        .card-header .nav-link {
            color: rgba(255,255,255,0.7);
            background: transparent;
            border: none;
            padding: 10px 20px;
            border-radius: 12px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .card-header .nav-link:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        
        .card-header .nav-link.active {
            color: white;
            background: rgba(255,255,255,0.2);
        }
        
        .card-body { padding: 25px; }
        
        /* Formulário */
        .form-group { margin-bottom: 20px; }
        
        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: #2c3e50;
            margin-bottom: 8px;
            display: block;
        }
        
        .required:after {
            content: "*";
            color: #dc3545;
            margin-left: 5px;
        }
        
        .form-control, .form-select {
            width: 100%;
            padding: 10px 15px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #006B3E;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0,107,62,0.1);
        }
        
        /* Botões */
        .btn-primary {
            background: linear-gradient(135deg, #006B3E, #1A2A6C);
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,107,62,0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 12px;
            padding: 10px 20px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-secondary:hover {
            transform: translateY(-2px);
            background: #5a6268;
        }
        
        /* Documentos Cards */
        .documento-card {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
            border: 1px solid #e9ecef;
        }
        
        .documento-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        
        .document-preview {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 10px;
            border: 2px solid #006B3E;
            cursor: pointer;
        }
        
        /* Scanner */
        .scanner-video {
            width: 100%;
            border-radius: 16px;
            background: #000;
            border: 2px solid #006B3E;
        }
        
        .webcam-container {
            background: #f8f9fa;
            border-radius: 16px;
            padding: 15px;
            text-align: center;
        }
        
        #video {
            width: 100%;
            max-width: 400px;
            border-radius: 16px;
            border: 2px solid #006B3E;
            background: #000;
            margin-bottom: 15px;
        }
        
        .preview-img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #006B3E;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        /* Help Icon */
        .help-icon {
            font-size: 0.9em;
            margin-left: 8px;
            cursor: pointer;
            color: #17a2b8;
            transition: all 0.3s;
        }
        
        .help-icon:hover {
            color: #006B3E;
            transform: scale(1.1);
        }
        
        /* Modal */
        .modal-ajuda .modal-header {
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border-radius: 20px 20px 0 0;
        }
        
        .modal-content {
            border-radius: 20px;
            border: none;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        
        /* Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 18px;
            left: 20px;
            z-index: 1001;
            background: linear-gradient(135deg, #1A2A6C 0%, #006B3E 100%);
            color: white;
            border: none;
            width: 45px;
            height: 45px;
            border-radius: 14px;
            cursor: pointer;
            font-size: 1.2em;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
            transition: all 0.3s;
        }
        
        .menu-toggle:hover { transform: scale(1.05); }
        
        /* Alertas */
        .alert {
            border-radius: 16px;
            border: none;
            padding: 15px 20px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        
        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        
        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border-left: 4px solid #17a2b8;
        }
        
        /* Footer */
        .footer-escola {
            position: fixed;
            bottom: 0;
            right: 0;
            left: 280px;
            background: rgba(255,255,255,0.98);
            backdrop-filter: blur(10px);
            padding: 12px 35px;
            font-size: 0.7em;
            color: #666;
            border-top: 1px solid rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 998;
            transition: all 0.3s;
        }
        
        .footer-left, .footer-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .footer-left span:hover, .footer-right span:hover { color: #006B3E; }
        
        /* Responsivo */
        @media (max-width: 768px) {
            .sidebar { left: -280px; }
            .sidebar.open { left: 0; border-radius: 0; }
            .main-content { margin-left: 0; margin-top: 70px; padding: 20px; }
            .footer-escola { left: 0; padding: 10px 20px; flex-direction: column; gap: 8px; }
            .menu-toggle { display: block; }
            .top-bar { flex-direction: column; gap: 15px; text-align: center; }
            .card-header .nav-tabs { flex-direction: column; gap: 5px; }
            .card-header .nav-link { text-align: center; }
            .footer-left, .footer-right { justify-content: center; width: 100%; }
        }
        
        @media (max-width: 480px) {
            .main-content { padding: 15px; }
            .card-body { padding: 15px; }
        }
    </style>
</head>
<body>

<?php include '../../menu_escola.php'; ?>

<!-- Main Content -->
<div class="main-content">
    <div class="top-bar">
        <h2><i class="fas fa-user-plus"></i> Cadastrar Funcionário</h2>
        <a href="listar.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div class="card">
        <div class="card-header">
            <ul class="nav nav-tabs card-header-tabs" id="funcionarioTabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">Dados Pessoais</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosProfissionais">Dados Profissionais</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosBancarios">Dados Bancários</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#documentos">Documentos</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#foto">Foto</button></li>
            </ul>
        </div>
        <div class="card-body">
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <?php echo $success; ?>
            <?php endif; ?>
            
            <?php if (!$success): ?>
            <form method="POST" enctype="multipart/form-data" id="formFuncionario">
                <input type="hidden" name="cadastrar_funcionario" value="1">
                <input type="hidden" name="documentos_scanner" id="documentos_scanner" value="[]">
                
                <div class="tab-content">
                    <!-- Dados Pessoais -->
                    <div class="tab-pane fade show active" id="dadosPessoais">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label required">Nome Completo</label>
                                    <input type="text" name="nome_completo" class="form-control" required>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">Data de Nascimento</label>
                                    <input type="date" name="data_nascimento" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label class="form-label">Género</label>
                                    <select name="genero" class="form-select">
                                        <option value="">Selecione...</option>
                                        <option value="M">Masculino</option>
                                        <option value="F">Feminino</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Nº do BI</label>
                                    <input type="text" name="bi_numero" id="bi_numero" class="form-control" 
                                           placeholder="123456789AA001" pattern="[0-9]{9}[A-Za-z]{2}[0-9]{3}" 
                                           oninput="this.value = this.value.toUpperCase()" style="text-transform: uppercase;">
                                    <small class="text-muted">Formato: 9 números + 2 letras + 3 números</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Data de Emissão do BI</label>
                                    <input type="date" name="bi_emissao" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Validade do BI</label>
                                    <input type="date" name="bi_validade" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">NUIT (NIF)</label>
                                    <input type="text" name="nuit" class="form-control" placeholder="9 dígitos">
                                    <small class="text-muted">Número de Identificação Fiscal (9 dígitos)</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Nacionalidade</label>
                                    <input type="text" name="nacionalidade" class="form-control" value="Angolana">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Naturalidade</label>
                                    <input type="text" name="naturalidade" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Estado Civil</label>
                                    <select name="estado_civil" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($estadosCivis as $ec): ?>
                                            <option value="<?php echo $ec; ?>"><?php echo $ec; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Nível de Escolaridade</label>
                                    <select name="nivel_escolaridade" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($niveis_escolaridade as $ne): ?>
                                            <option value="<?php echo $ne; ?>"><?php echo $ne; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Nº Segurança Social</label>
                                    <input type="text" name="num_seguranca_social" class="form-control" placeholder="Número de inscrição na Segurança Social">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Província</label>
                                    <div class="input-group">
                                        <select name="provincia_id" id="provincia_id" class="form-select">
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
                                <div class="form-group">
                                    <label class="form-label">Município</label>
                                    <div class="input-group">
                                        <select name="municipio_id" id="municipio_id" class="form-select" disabled>
                                            <option value="">Primeiro selecione a província</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovoMunicipio">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label class="form-label">Comuna</label>
                                    <div class="input-group">
                                        <select name="comuna_id" id="comuna_id" class="form-select" disabled>
                                            <option value="">Primeiro selecione o município</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalNovaComuna">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label class="form-label">Endereço Completo</label>
                                    <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº, Referência"></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label required">Telefone</label>
                                    <input type="tel" name="telefone" id="telefone" class="form-control" required placeholder="923456789">
                                    <small class="text-muted" id="operadora-info"></small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">E-mail</label>
                                    <input type="email" name="email" class="form-control" placeholder="exemplo@email.com">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Contacto de Emergência</label>
                                    <input type="tel" name="telefone_emergencia" class="form-control" placeholder="9XX XXX XXX">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Nome do Contacto de Emergência</label>
                                    <input type="text" name="nome_emergencia" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Nome do Pai</label>
                                    <input type="text" name="nome_pai" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Nome da Mãe</label>
                                    <input type="text" name="nome_mae" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dados Profissionais -->
                    <div class="tab-pane fade" id="dadosProfissionais">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Tipo de Funcionário</label>
                                    <select name="tipo_funcionario" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($tipos_funcionario as $tipo): ?>
                                            <option value="<?php echo $tipo; ?>"><?php echo ucfirst($tipo); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Cargo/Função</label>
                                    <select name="cargo" class="form-select" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($cargos as $cargo): ?>
                                            <option value="<?php echo $cargo; ?>"><?php echo $cargo; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Data de Admissão</label>
                                    <input type="date" name="data_admissao" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Tipo de Contrato</label>
                                    <select name="tipo_contrato" class="form-select">
                                        <?php foreach ($tiposContrato as $tc): ?>
                                            <option value="<?php echo $tc; ?>"><?php echo $tc; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Data Fim do Contrato (se aplicável)</label>
                                    <input type="date" name="data_fim_contrato" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Anos de Experiência</label>
                                    <input type="number" name="experiencia_anos" class="form-control" min="0" step="0.5" placeholder="0">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Habilitação Literária</label>
                                    <select name="habilitacao" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php foreach ($habilitacoes as $h): ?>
                                            <option value="<?php echo $h; ?>"><?php echo $h; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Formação Académica / Cursos</label>
                                    <input type="text" name="formacao" class="form-control">
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Descrição da Formação</label>
                            <textarea name="formacao_descricao" class="form-control" rows="3" placeholder="Descreva a formação académica, cursos, especializações..."></textarea>
                        </div>
                    </div>
                    
                    <!-- Dados Bancários -->
                    <div class="tab-pane fade" id="dadosBancarios">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Informações bancárias para processamento salarial
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Banco</label>
                                    <select name="banco_id" id="banco_id" class="form-select">
                                        <option value="">Selecione...</option>
                                        <?php if (!empty($bancos)): ?>
                                            <?php foreach ($bancos as $banco): ?>
                                                <option value="<?php echo $banco['id']; ?>">
                                                    <?php echo htmlspecialchars($banco['nome']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="">Nenhum banco cadastrado</option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">Número da Conta</label>
                                    <input type="text" name="numero_conta" class="form-control" placeholder="Número da conta bancária">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">IBAN</label>
                                    <input type="text" name="iban" class="form-control" placeholder="AO06.0000.0000.0000.0000.0000.000">
                                    <small class="text-muted">Código Internacional de Conta Bancária</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">SWIFT/BIC</label>
                                    <input type="text" name="swift" class="form-control" placeholder="Código SWIFT do banco">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Documentos -->
                    <div class="tab-pane fade" id="documentos">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-id-card fa-3x text-primary mb-2"></i>
                                        <h6>BI / Documento de Identificação</h6>
                                        <input type="file" name="bi_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'bi_preview')">
                                        <div id="bi_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('bi', 'bi_preview', 'bi_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-graduation-cap fa-3x text-success mb-2"></i>
                                        <h6>Diploma / Certificado</h6>
                                        <input type="file" name="diploma_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'diploma_preview')">
                                        <div id="diploma_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('diploma', 'diploma_preview', 'diploma_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-certificate fa-3x text-info mb-2"></i>
                                        <h6>Certificações / Cursos</h6>
                                        <input type="file" name="certificacoes_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'certificacoes_preview')">
                                        <div id="certificacoes_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('certificacao', 'certificacoes_preview', 'certificacoes_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-file-alt fa-3x text-warning mb-2"></i>
                                        <h6>Declaração / Comprovativo</h6>
                                        <input type="file" name="declaracao_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'declaracao_preview')">
                                        <div id="declaracao_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('declaracao', 'declaracao_preview', 'declaracao_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-file-contract fa-3x text-secondary mb-2"></i>
                                        <h6>Contrato de Trabalho</h6>
                                        <input type="file" name="contrato_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'contrato_preview')">
                                        <div id="contrato_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('contrato', 'contrato_preview', 'contrato_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-stethoscope fa-3x text-danger mb-2"></i>
                                        <h6>Atestado Médico</h6>
                                        <input type="file" name="atestado_medico_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'atestado_preview')">
                                        <div id="atestado_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('atestado_medico', 'atestado_preview', 'atestado_medico_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-id-card fa-3x text-dark mb-2"></i>
                                        <h6>Carteira Profissional</h6>
                                        <input type="file" name="carteira_profissional_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'carteira_preview')">
                                        <div id="carteira_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('carteira_profissional', 'carteira_preview', 'carteira_profissional_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="documento-card">
                                    <div class="text-center">
                                        <i class="fas fa-shield-alt fa-3x text-primary mb-2"></i>
                                        <h6>Comprovativo Segurança Social</h6>
                                        <input type="file" name="seguranca_social_documento" class="form-control form-control-sm" accept=".jpg,.jpeg,.png,.pdf" onchange="previewDocumento(this, 'seguranca_preview')">
                                        <div id="seguranca_preview" class="mt-2"></div>
                                        <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="abrirScanner('seguranca_social', 'seguranca_preview', 'seguranca_social_documento')">
                                            <i class="fas fa-camera"></i> Scanner
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <h6 class="mt-3">Outros Documentos</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <input type="file" name="outro_documento_1" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro1_preview')">
                                <div id="outro1_preview" class="mt-1"></div>
                            </div>
                            <div class="col-md-4">
                                <input type="file" name="outro_documento_2" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro2_preview')">
                                <div id="outro2_preview" class="mt-1"></div>
                            </div>
                            <div class="col-md-4">
                                <input type="file" name="outro_documento_3" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx" onchange="previewDocumento(this, 'outro3_preview')">
                                <div id="outro3_preview" class="mt-1"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Foto -->
                    <div class="tab-pane fade" id="foto">
                        <div class="row">
                            <div class="col-md-6 text-center">
                                <h5 class="mb-3">Upload de Foto</h5>
                                <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*" onchange="previewFoto(this)">
                                <div class="preview-container">
                                    <img id="fotoPreview" src="../../../assets/images/avatar-padrao.png" class="preview-img">
                                </div>
                            </div>
                            <div class="col-md-6 text-center">
                                <h5 class="mb-3">Capturar com Webcam</h5>
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
                    <button type="submit" class="btn btn-primary btn-lg px-5">
                        <i class="fas fa-save"></i> Cadastrar Funcionário
                    </button>
                    <a href="listar.php" class="btn btn-secondary btn-lg px-5 ms-2">
                        <i class="fas fa-times"></i> Cancelar
                    </a>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Footer -->
<div class="footer-escola">
    <div class="footer-left">
        <span><i class="fas fa-building"></i> SIGE Angola</span>
        <span><i class="fas fa-code-branch"></i> Versão 2.5.0</span>
    </div>
    <div class="footer-right">
        <span><i class="fas fa-copyright"></i> <?php echo date('Y'); ?> SIGE Angola - Sistema Integrado de Gestão Escolar</span>
    </div>
</div>

<!-- Modais -->
<div class="modal fade" id="scannerModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-camera"></i> Scanner de Documentos</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-3">
                    <span id="scannerTipoTexto" class="badge bg-info p-2">Documento</span>
                </div>
                <video id="scanner-video" class="scanner-video" autoplay></video>
                <canvas id="scanner-canvas" class="scanner-canvas" style="display:none;"></canvas>
                <div class="text-center mt-3">
                    <button class="btn btn-success" onclick="capturarDocumento()"><i class="fas fa-camera"></i> Capturar</button>
                    <button class="btn btn-danger" onclick="fecharScanner()"><i class="fas fa-times"></i> Cancelar</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNovaProvincia" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Nova Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaProvincia" class="form-control" placeholder="Nome da Província"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div></div>

<div class="modal fade" id="modalNovoMunicipio" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Novo Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="municipioProvincia" class="form-control mb-2" readonly><input type="text" id="novoMunicipio" class="form-control" placeholder="Nome do Município"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div></div>

<div class="modal fade" id="modalNovaComuna" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Nova Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="comunaMunicipio" class="form-control mb-2" readonly><input type="text" id="novaComuna" class="form-control" placeholder="Nome da Comuna"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div></div>

<div class="modal fade modal-ajuda" id="modalAjuda" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-question-circle"></i> Ajuda - Cadastro de Funcionário</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info"><i class="fas fa-info-circle"></i> <strong>Base Legal:</strong> Lei Geral do Trabalho (Lei 7/15, de 15 de Junho) e Código Fiscal Angolano.</div>
                <h6><i class="fas fa-id-card"></i> Documentos Obrigatórios</h6>
                <ul><li><strong>BI:</strong> 9 números + 2 letras + 3 números</li><li><strong>NUIT/NIF:</strong> 9 dígitos</li><li><strong>Nº Segurança Social</strong></li><li><strong>Carteira Profissional</strong></li></ul>
                <h6><i class="fas fa-phone"></i> Contactos</h6>
                <ul><li>Telefone: Unitel (91,92,93), Africell (94,95,96), Movicel (97,98,99)</li></ul>
                <div class="alert alert-warning mt-3">
                    Após o cadastro, será gerado: Número de Processo, Usuário de acesso e Senha temporária (número do BI)
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Menu Toggle
    document.getElementById('menuToggle')?.addEventListener('click', function() {
        document.getElementById('sidebar').classList.toggle('open');
    });
    
    // ============================================
    // FUNÇÃO CORRETA PARA ALTERNAR SUBMENUS
    // ============================================
    document.querySelectorAll('.has-submenu > a').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var parentLi = this.closest('.has-submenu');
            if (parentLi) {
                parentLi.classList.toggle('open');
            }
        });
    });
    
    // Detectar operadora
    function detectarOperadora() {
        const tel = document.getElementById('telefone').value.replace(/[^0-9]/g, '');
        const info = document.getElementById('operadora-info');
        if (tel.length >= 2) {
            const op = {'91':'UNITEL','92':'UNITEL','93':'UNITEL','94':'AFRICELL','95':'AFRICELL','96':'AFRICELL','97':'MOVICEL','98':'MOVICEL','99':'MOVICEL'}[tel.substring(0,2)];
            info.innerHTML = op ? `<span class="badge bg-success">${op}</span>` : '<span class="text-danger">Número inválido</span>';
        } else { info.innerHTML = ''; }
    }
    document.getElementById('telefone').addEventListener('input', detectarOperadora);
    document.getElementById('bi_numero').addEventListener('input', function() { this.value = this.value.toUpperCase(); });
    
    // Preview documentos
    function previewDocumento(input, previewId) {
        const preview = document.getElementById(previewId);
        if (preview) preview.innerHTML = '';
        if (input.files && input.files[0]) {
            const file = input.files[0];
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (preview) preview.innerHTML = `<img src="${e.target.result}" class="document-preview">`;
                };
                reader.readAsDataURL(file);
            } else {
                if (preview) preview.innerHTML = `<span class="badge bg-info"><i class="fas fa-file-pdf"></i> ${file.name.substring(0,15)}</span>`;
            }
        }
    }
    
    function previewFoto(input) {
        if (input.files && input.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) { document.getElementById('fotoPreview').src = e.target.result; };
            reader.readAsDataURL(input.files[0]);
        }
    }
    
    // Província, Município, Comuna
    document.getElementById('provincia_id')?.addEventListener('change', function() {
        const id = this.value;
        if (id) {
            fetch(`cadastrar.php?acao=get_municipios&provincia_id=${id}`)
                .then(res => res.json())
                .then(data => {
                    let opts = '<option value="">Selecione...</option>';
                    data.forEach(m => opts += `<option value="${m.id}">${m.nome}</option>`);
                    document.getElementById('municipio_id').innerHTML = opts;
                    document.getElementById('municipio_id').disabled = false;
                    document.getElementById('comuna_id').innerHTML = '<option value="">Selecione o município</option>';
                    document.getElementById('comuna_id').disabled = true;
                });
        } else {
            document.getElementById('municipio_id').innerHTML = '<option value="">Selecione a província</option>';
            document.getElementById('municipio_id').disabled = true;
            document.getElementById('comuna_id').innerHTML = '<option value="">Selecione o município</option>';
            document.getElementById('comuna_id').disabled = true;
        }
    });
    
    document.getElementById('municipio_id')?.addEventListener('change', function() {
        const id = this.value;
        if (id) {
            fetch(`cadastrar.php?acao=get_comunas&municipio_id=${id}`)
                .then(res => res.json())
                .then(data => {
                    let opts = '<option value="">Selecione...</option>';
                    data.forEach(c => opts += `<option value="${c.id}">${c.nome}</option>`);
                    document.getElementById('comuna_id').innerHTML = opts;
                    document.getElementById('comuna_id').disabled = false;
                });
        } else {
            document.getElementById('comuna_id').innerHTML = '<option value="">Selecione o município</option>';
            document.getElementById('comuna_id').disabled = true;
        }
    });
    
    // Scanner
    let currentTipo = null, currentPreviewId = null, currentFileInput = null;
    let scannerStream = null, scannerModal = null;
    let documentosScanner = [];
    
    function abrirScanner(tipo, previewId, fileInputName) {
        currentTipo = tipo; currentPreviewId = previewId; currentFileInput = fileInputName;
        document.getElementById('scannerTipoTexto').innerHTML = tipo.replace('_', ' ').toUpperCase();
        scannerModal = new bootstrap.Modal(document.getElementById('scannerModal'));
        scannerModal.show();
        navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
            .then(s => { scannerStream = s; document.getElementById('scanner-video').srcObject = s; })
            .catch(e => alert("Erro ao acessar câmera: " + e.message));
    }
    
    function capturarDocumento() {
        const video = document.getElementById('scanner-video');
        const canvas = document.getElementById('scanner-canvas');
        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        const imgData = canvas.toDataURL('image/jpeg', 0.9);
        
        fetch('cadastrar.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `acao=upload_documento_scanner&tipo_documento=${currentTipo}&imagem=${encodeURIComponent(imgData)}`
        })
        .then(res => res.json())
        .then(r => {
            if (r.success) {
                documentosScanner.push({ tipo: currentTipo, imagem: r.imagem, formato: r.formato });
                document.getElementById('documentos_scanner').value = JSON.stringify(documentosScanner);
                const preview = document.getElementById(currentPreviewId);
                if (preview) preview.innerHTML = `<div style="position:relative"><img src="${r.imagem}" style="max-width:60px"><span class="badge bg-info formato-badge">${r.formato}</span></div>`;
                fetch(r.imagem).then(res => res.blob()).then(blob => {
                    const file = new File([blob], currentTipo + '_' + Date.now() + '.jpg', { type: 'image/jpeg' });
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    document.querySelector(`input[name="${currentFileInput}"]`).files = dt.files;
                });
                alert(`Documento capturado! Formato: ${r.formato}`);
                fecharScanner();
            } else alert("Erro: " + (r.error || 'Tente novamente'));
        });
    }
    
    function fecharScanner() {
        if (scannerStream) scannerStream.getTracks().forEach(t => t.stop());
        if (scannerModal) scannerModal.hide();
    }
    
    // Salvar província, município, comuna
    document.getElementById('salvarProvinciaBtn')?.addEventListener('click', function() {
        const nome = document.getElementById('novaProvincia').value;
        if (nome) {
            fetch('cadastrar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `acao=add_provincia&nova_provincia=${encodeURIComponent(nome)}`
            })
            .then(res => res.json())
            .then(r => {
                if (r.success) {
                    const select = document.getElementById('provincia_id');
                    select.innerHTML += `<option value="${r.id}">${r.nome}</option>`;
                    select.value = r.id;
                    select.dispatchEvent(new Event('change'));
                    bootstrap.Modal.getInstance(document.getElementById('modalNovaProvincia')).hide();
                    document.getElementById('novaProvincia').value = '';
                }
            });
        }
    });
    
    document.getElementById('salvarMunicipioBtn')?.addEventListener('click', function() {
        const nome = document.getElementById('novoMunicipio').value;
        const provId = document.getElementById('provincia_id').value;
        if (nome && provId) {
            fetch('cadastrar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `acao=add_municipio&novo_municipio=${encodeURIComponent(nome)}&provincia_id=${provId}`
            })
            .then(res => res.json())
            .then(r => {
                if (r.success) {
                    const select = document.getElementById('municipio_id');
                    select.innerHTML += `<option value="${r.id}">${r.nome}</option>`;
                    select.value = r.id;
                    select.dispatchEvent(new Event('change'));
                    bootstrap.Modal.getInstance(document.getElementById('modalNovoMunicipio')).hide();
                    document.getElementById('novoMunicipio').value = '';
                }
            });
        }
    });
    
    document.getElementById('salvarComunaBtn')?.addEventListener('click', function() {
        const nome = document.getElementById('novaComuna').value;
        const munId = document.getElementById('municipio_id').value;
        if (nome && munId) {
            fetch('cadastrar.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `acao=add_comuna&nova_comuna=${encodeURIComponent(nome)}&municipio_id=${munId}`
            })
            .then(res => res.json())
            .then(r => {
                if (r.success) {
                    const select = document.getElementById('comuna_id');
                    select.innerHTML += `<option value="${r.id}">${r.nome}</option>`;
                    select.value = r.id;
                    bootstrap.Modal.getInstance(document.getElementById('modalNovaComuna')).hide();
                    document.getElementById('novaComuna').value = '';
                }
            });
        }
    });
    
    document.getElementById('modalNovoMunicipio')?.addEventListener('show.bs.modal', function() {
        document.getElementById('municipioProvincia').value = document.getElementById('provincia_id')?.options[document.getElementById('provincia_id').selectedIndex]?.text || 'Selecione uma província';
    });
    
    document.getElementById('modalNovaComuna')?.addEventListener('show.bs.modal', function() {
        document.getElementById('comunaMunicipio').value = document.getElementById('municipio_id')?.options[document.getElementById('municipio_id').selectedIndex]?.text || 'Selecione um município';
    });
    
    // Webcam
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    let webcamStream = null;
    
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(s => { webcamStream = s; video.srcObject = s; })
        .catch(e => console.log(e));
    
    document.getElementById('recarregarCamBtn')?.addEventListener('click', function() {
        if (webcamStream) webcamStream.getTracks().forEach(t => t.stop());
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(s => { webcamStream = s; video.srcObject = s; });
    });
    
    document.getElementById('capturarBtn')?.addEventListener('click', function() {
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        const data = canvas.toDataURL('image/png');
        document.getElementById('fotoPreview').src = data;
        fetch(data).then(r => r.blob()).then(b => {
            const f = new File([b], 'foto.png', { type: 'image/png' });
            const dt = new DataTransfer();
            dt.items.add(f);
            document.getElementById('fotoInput').files = dt.files;
        });
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'foto_capturada';
        hiddenInput.value = data;
        document.getElementById('formFuncionario').appendChild(hiddenInput);
    });
    
    // Validação do formulário
    document.getElementById('formFuncionario')?.addEventListener('submit', function(e) {
        const bi = document.querySelector('input[name="bi_numero"]').value;
        if (bi && !/^[0-9]{9}[A-Z]{2}[0-9]{3}$/.test(bi.toUpperCase())) {
            e.preventDefault();
            alert('BI inválido!');
            return false;
        }
        const tel = document.querySelector('input[name="telefone"]').value;
        if (!/^9[1-9][0-9]{7}$/.test(tel)) {
            e.preventDefault();
            alert('Telefone inválido!');
            return false;
        }
        return true;
    });
    
    // Manter submenus abertos baseado na URL atual
    document.addEventListener('DOMContentLoaded', function() {
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.has-submenu').forEach(menu => {
            const links = menu.querySelectorAll('.nav-submenu a');
            if (Array.from(links).some(link => currentUrl.includes(link.getAttribute('href')))) {
                menu.classList.add('open');
            }
        });
        
        // Menu RH específico
        if (currentUrl.includes('rh/funcionarios/cadastrar.php') || currentUrl.includes('rh/funcionarios/listar.php')) {
            const rhMenu = document.getElementById('menuRH');
            if (rhMenu) rhMenu.classList.add('open');
        }
    });
</script>
</body>
</html>