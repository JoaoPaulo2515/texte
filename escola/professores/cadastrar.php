<?php
// escola/professores/cadastrar.php - Cadastro de Professor com Documentos e Scanner
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

// Função para gerar número de processo
function gerarNumeroProcessoProfessor($conn, $escola_id) {
    $ano = date('Y');
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM professores WHERE escola_id = ? AND YEAR(created_at) = ?");
    $stmt->execute([$escola_id, $ano]);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $sequencial = str_pad($total + 1, 4, '0', STR_PAD_LEFT);
    return "PROF/{$escola_id}/{$ano}/{$sequencial}";
}

// Função para validar BI de Angola
function validarBIAngola($bi) {
    $bi = strtoupper(preg_replace('/[^A-Z0-9]/', '', $bi));
    // Formato: 9 números + 2 letras + 3 números
    if (preg_match('/^[0-9]{9}[A-Z]{2}[0-9]{3}$/', $bi)) {
        return $bi;
    }
    return false;
}

// Função para validar telefone Angola
function validarTelefoneAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    // Redes Angola: 91, 92, 93, 94, 95, 96, 97, 98, 99
    if (preg_match('/^9[1-9][0-9]{7}$/', $telefone)) {
        return $telefone;
    }
    return false;
}

// Função para detectar formato do papel
function detectarFormatoPapel($caminho_imagem) {
    if (!file_exists($caminho_imagem)) return 'Outro';
    
    try {
        list($largura, $altura) = getimagesize($caminho_imagem);
        $ratio = max($largura, $altura) / min($largura, $altura);
        
        // Proporções típicas
        if ($ratio > 1.4 && $ratio < 1.45) return 'A4';
        if ($ratio > 1.29 && $ratio < 1.32) return 'A5';
        if ($ratio > 1.4 && $ratio < 1.42) return 'A3';
        if ($ratio > 1.29 && $ratio < 1.3) return 'Carta';
        
        return 'Outro';
    } catch (Exception $e) {
        return 'Outro';
    }
}

// Função para processar imagem do scanner (remover fundo)
function processarImagemScanner($imagem_base64) {
    $data = explode(',', $imagem_base64);
    if (count($data) > 1) {
        $imagem_decodificada = base64_decode($data[1]);
        return $imagem_decodificada;
    }
    return null;
}

// NOVA FUNÇÃO: Formatar número de telefone para exibição
function formatarTelefoneAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

// NOVA FUNÇÃO: Obter operadora de telefone
function getOperadoraTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) >= 9) {
        $prefixo = substr($telefone, 0, 2);
        $operadoras = [
            '91' => 'UNITEL', '92' => 'UNITEL', '93' => 'UNITEL',
            '94' => 'AFRICELL', '95' => 'AFRICELL', '96' => 'AFRICELL',
            '97' => 'MOVICEL', '98' => 'MOVICEL', '99' => 'MOVICEL'
        ];
        return $operadoras[$prefixo] ?? 'DESCONHECIDA';
    }
    return 'DESCONHECIDA';
}

// NOVA FUNÇÃO: Validar email
function validarEmailAngola($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Buscar dados existentes para comboboxes
$provincias = $conn->query("SELECT DISTINCT nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
if (empty($provincias)) {
    $provincias = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Namibe', 'Uíge', 'Zaire'];
}

// Buscar disciplinas para atribuição
$disciplinas = $conn->prepare("SELECT id, nome, carga_horaria FROM disciplinas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas
$turmas = $conn->prepare("SELECT id, nome, turno FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY nome");
$turmas->execute([':escola_id' => $escola_id]);
$turmas = $turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar cargos/habilitações literárias
$cargos = [
    'Professor Auxiliar', 'Professor Assistente', 'Professor Principal', 
    'Professor Coordenador', 'Professor Sénior', 'Mestre', 'Doutor',
    'Monitor', 'Tutor', 'Instrutor', 'Formador'
];

$habilitacoes = [
    '6ª Classe', '9ª Classe', '12ª Classe', 'Formação de Professores (IMED)',
    'Bacharelato', 'Licenciatura', 'Pós-Graduação', 'Mestrado', 'Doutoramento',
    'Curso de Especialização', 'Curso de Formação Contínua'
];

$estadosCivis = ['Solteiro(a)', 'Casado(a)', 'Divorciado(a)', 'Viúvo(a)', 'União de Facto'];

$religioes = ['Católica', 'Evangélica', 'Metodista', 'Assembleia de Deus', 'Universal', 'ADRA', 'Outra', 'Sem religião'];

$error = '';
$success = '';

// Função para criar avatar padrão
function criarAvatarProfessor($nome, $tamanho = 200) {
    $avatar_dir = __DIR__ . '/../../uploads/avatares/';
    if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0777, true);
    
    $iniciais = strtoupper(substr($nome, 0, 2));
    $cores = ['#006B3E', '#1A2A6C', '#28a745', '#17a2b8', '#6f42c1', '#fd7e14'];
    $cor = $cores[abs(crc32($nome)) % count($cores)];
    
    $imagem = imagecreate($tamanho, $tamanho);
    $bg_color = imagecolorallocate($imagem, hexdec(substr($cor, 1, 2)), hexdec(substr($cor, 3, 2)), hexdec(substr($cor, 5, 2)));
    $text_color = imagecolorallocate($imagem, 255, 255, 255);
    
    imagefill($imagem, 0, 0, $bg_color);
    
    $fonte = __DIR__ . '/../../assets/fonts/arial.ttf';
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
    
    $nome_avatar = 'avatar_prof_' . time() . '_' . uniqid() . '.png';
    imagepng($imagem, $avatar_dir . $nome_avatar);
    imagedestroy($imagem);
    
    return $nome_avatar;
}

// Processar adição via AJAX
if (isset($_POST['acao'])) {
    $response = ['success' => false];
    
    if ($_POST['acao'] == 'add_provincia') {
        $nova_provincia = $_POST['nova_provincia'] ?? '';
        if ($nova_provincia) {
            $stmt = $conn->prepare("INSERT IGNORE INTO angola_provincias (nome) VALUES (?)");
            $stmt->execute([$nova_provincia]);
            $response = ['success' => true, 'provincia' => $nova_provincia];
        }
    }
    
    if ($_POST['acao'] == 'add_municipio') {
        $novo_municipio = $_POST['novo_municipio'] ?? '';
        $provincia_nome = $_POST['provincia_nome'] ?? '';
        if ($novo_municipio && $provincia_nome) {
            $stmt = $conn->prepare("SELECT id FROM angola_provincias WHERE nome = ?");
            $stmt->execute([$provincia_nome]);
            $provincia = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($provincia) {
                $stmt = $conn->prepare("INSERT IGNORE INTO angola_municipios (nome, provincia_id) VALUES (?, ?)");
                $stmt->execute([$novo_municipio, $provincia['id']]);
                $response = ['success' => true, 'municipio' => $novo_municipio];
            }
        }
    }
    
    if ($_POST['acao'] == 'add_comuna') {
        $nova_comuna = $_POST['nova_comuna'] ?? '';
        $municipio_nome = $_POST['municipio_nome'] ?? '';
        if ($nova_comuna && $municipio_nome) {
            $stmt = $conn->prepare("SELECT id FROM angola_municipios WHERE nome = ?");
            $stmt->execute([$municipio_nome]);
            $municipio = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($municipio) {
                $stmt = $conn->prepare("INSERT IGNORE INTO angola_comunas (nome, municipio_id) VALUES (?, ?)");
                $stmt->execute([$nova_comuna, $municipio['id']]);
                $response = ['success' => true, 'comuna' => $nova_comuna];
            }
        }
    }
    
    if ($_POST['acao'] == 'upload_documento_scanner') {
        $tipo_documento = $_POST['tipo_documento'] ?? '';
        $imagem_base64 = $_POST['imagem'] ?? '';
        
        if ($tipo_documento && $imagem_base64) {
            $imagem_data = processarImagemScanner($imagem_base64);
            if ($imagem_data) {
                $temp_file = tempnam(sys_get_temp_dir(), 'scan_');
                file_put_contents($temp_file, $imagem_data);
                $formato = detectarFormatoPapel($temp_file);
                unlink($temp_file);
                
                $response = ['success' => true, 'formato' => $formato, 'imagem' => $imagem_base64];
            }
        }
    }
    
    echo json_encode($response);
    exit;
}

// Buscar municípios por província
if (isset($_GET['acao']) && $_GET['acao'] == 'get_municipios') {
    $provincia = $_GET['provincia'] ?? '';
    if ($provincia) {
        $stmt = $conn->prepare("
            SELECT m.id, m.nome 
            FROM angola_municipios m
            JOIN angola_provincias p ON p.id = m.provincia_id
            WHERE p.nome = ?
            ORDER BY m.nome
        ");
        $stmt->execute([$provincia]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

// Buscar comunas por município
if (isset($_GET['acao']) && $_GET['acao'] == 'get_comunas') {
    $municipio = $_GET['municipio'] ?? '';
    if ($municipio) {
        $stmt = $conn->prepare("
            SELECT c.id, c.nome 
            FROM angola_comunas c
            JOIN angola_municipios m ON m.id = c.municipio_id
            WHERE m.nome = ?
            ORDER BY c.nome
        ");
        $stmt->execute([$municipio]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

// Processar cadastro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['acao'])) {
    // Validar campos obrigatórios
    $nome_completo = $_POST['nome_completo'] ?? '';
    $bi_numero = $_POST['bi_numero'] ?? '';
    $telefone = $_POST['telefone'] ?? '';
    
    // Validar BI
    $bi_validado = validarBIAngola($bi_numero);
    if (!$bi_validado) {
        $error = "BI inválido! Formato correto: 9 números + 2 letras + 3 números (Ex: 123456789AA001)";
    }
    
    // Validar telefone
    $telefone_validado = validarTelefoneAngola($telefone);
    if (!$telefone_validado && $error == '') {
        $error = "Telefone inválido! Deve ser um número da Unitel, Africell ou Movicel (9XX XXX XXX)";
    }
    
    // NOVA VALIDAÇÃO: Validar email se fornecido
    $email = $_POST['email'] ?? '';
    if (!empty($email) && !validarEmailAngola($email)) {
        $error = "E-mail inválido!";
    }
    
    if (!$error) {
        try {
            $conn->beginTransaction();
            
            // Verificar se BI já existe
            $stmt = $conn->prepare("SELECT id FROM professores WHERE bi = ? AND escola_id = ?");
            $stmt->execute([$bi_validado, $escola_id]);
            if ($stmt->fetch()) {
                throw new Exception("BI já cadastrado no sistema.");
            }
            
            // Verificar se email já existe
            if (!empty($email)) {
                $stmt = $conn->prepare("SELECT id FROM professores WHERE email = ? AND escola_id = ?");
                $stmt->execute([$email, $escola_id]);
                if ($stmt->fetch()) {
                    throw new Exception("E-mail já cadastrado no sistema.");
                }
            }
            
            // Gerar número de processo
            $numero_processo = gerarNumeroProcessoProfessor($conn, $escola_id);
            
            // Criar usuário automaticamente
            $email_usuario = $email ?: $numero_processo . '@sige.ao';
            $senha = $bi_validado; // Senha = número do BI
            $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
            
            $stmt = $conn->prepare("
                INSERT INTO usuarios (escola_id, nome, email, senha, tipo, telefone, status, created_at)
                VALUES (?, ?, ?, ?, 'professor', ?, 'ativo', NOW())
            ");
            $stmt->execute([$escola_id, $nome_completo, $email_usuario, $senha_hash, $telefone_validado]);
            $usuario_id = $conn->lastInsertId();
            
            // Upload da Foto
            $foto = null;
            $foto_capturada = $_POST['foto_capturada'] ?? '';
            
            if (!empty($foto_capturada)) {
                $foto_data = explode(',', $foto_capturada);
                if (count($foto_data) > 1) {
                    $foto_dir = __DIR__ . '/../../uploads/professores/fotos/';
                    if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
                    $foto = 'foto_prof_' . time() . '_' . uniqid() . '.png';
                    file_put_contents($foto_dir . $foto, base64_decode($foto_data[1]));
                }
            } elseif (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                $ext = strtolower(pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
                    $foto_dir = __DIR__ . '/../../uploads/professores/fotos/';
                    if (!is_dir($foto_dir)) mkdir($foto_dir, 0777, true);
                    $foto = 'foto_prof_' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['foto']['tmp_name'], $foto_dir . $foto);
                }
            }
            
            // Criar avatar se não houver foto
            if (!$foto) {
                $foto = criarAvatarProfessor($nome_completo);
            }
            
            // NOVOS CAMPOS: Dados adicionais
            $cargo = $_POST['cargo'] ?? null;
            $habilitacao = $_POST['habilitacao'] ?? null;
            $estado_civil = $_POST['estado_civil'] ?? null;
            $religiao = $_POST['religiao'] ?? null;
            $nome_pai = $_POST['nome_pai'] ?? null;
            $nome_mae = $_POST['nome_mae'] ?? null;
            $telefone_emergencia = $_POST['telefone_emergencia'] ?? null;
            $nome_emergencia = $_POST['nome_emergencia'] ?? null;
            $banco = $_POST['banco'] ?? null;
            $iban = $_POST['iban'] ?? null;
            $data_inicio = $_POST['data_inicio'] ?? null;
            $tipo_contrato = $_POST['tipo_contrato'] ?? 'Efetivo';
            
            // Validar telefone de emergência se fornecido
            $telefone_emergencia_validado = null;
            if (!empty($telefone_emergencia)) {
                $telefone_emergencia_validado = validarTelefoneAngola($telefone_emergencia);
            }
            
            // Inserir professor com todos os campos
            $stmt = $conn->prepare("
                INSERT INTO professores (
                    usuario_id, escola_id, numero_processo, nome, especialidade, formacao, data_admissao,
                    bi, bi_emissao, bi_validade, nuit, nacionalidade, naturalidade,
                    provincia, municipio, comuna, endereco, data_nascimento, genero, foto,
                    telefone, email, status, created_at,
                    cargo, habilitacao, estado_civil, religiao, nome_pai, nome_mae,
                    telefone_emergencia, nome_emergencia, banco, iban, data_inicio, tipo_contrato
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ativo', NOW(),
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $usuario_id, $escola_id, $numero_processo, $nome_completo,
                $_POST['especialidade'] ?: null,
                $_POST['formacao'] ?: null,
                $_POST['data_admissao'] ?: date('Y-m-d'),
                $bi_validado,
                $_POST['bi_emissao'] ?: null,
                $_POST['bi_validade'] ?: null,
                $_POST['nuit'] ?: null,
                $_POST['nacionalidade'] ?: 'Angolana',
                $_POST['naturalidade'] ?: null,
                $_POST['provincia'] ?: null,
                $_POST['municipio'] ?: null,
                $_POST['comuna'] ?: null,
                $_POST['endereco'] ?: null,
                $_POST['data_nascimento'] ?: null,
                $_POST['genero'] ?: null,
                $foto,
                $telefone_validado,
                $email ?: null,
                $cargo,
                $habilitacao,
                $estado_civil,
                $religiao,
                $nome_pai,
                $nome_mae,
                $telefone_emergencia_validado,
                $nome_emergencia,
                $banco,
                $iban,
                $data_inicio,
                $tipo_contrato
            ]);
            
            $professor_id = $conn->lastInsertId();
            
            // Processar documentos enviados (incluindo "outros")
            $documentos_upload = [
                'bi_documento' => ['tipo' => 'bi', 'label' => 'BI'],
                'diploma_documento' => ['tipo' => 'diploma', 'label' => 'Diploma'],
                'certificacoes_documento' => ['tipo' => 'certificacao', 'label' => 'Certificação'],
                'declaracao_documento' => ['tipo' => 'declaracao', 'label' => 'Declaração'],
                'outro_documento' => ['tipo' => 'outro', 'label' => 'Outro Documento']
            ];
            
            $upload_dir_docs = __DIR__ . '/../../uploads/professores/documentos/' . $professor_id . '/';
            if (!is_dir($upload_dir_docs)) mkdir($upload_dir_docs, 0777, true);
            
            foreach ($documentos_upload as $campo => $info) {
                if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] == 0) {
                    $ext = strtolower(pathinfo($_FILES[$campo]['name'], PATHINFO_EXTENSION));
                    $temp_file = $_FILES[$campo]['tmp_name'];
                    $formato = detectarFormatoPapel($temp_file);
                    $nome_arquivo = $info['tipo'] . '_' . time() . '_' . uniqid() . '.' . $ext;
                    
                    if (move_uploaded_file($temp_file, $upload_dir_docs . $nome_arquivo)) {
                        $stmt = $conn->prepare("
                            INSERT INTO professores_documentos (professor_id, tipo_documento, nome_arquivo, caminho_arquivo, formato_papel, tamanho_arquivo)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $professor_id,
                            $info['tipo'],
                            $nome_arquivo,
                            'uploads/professores/documentos/' . $professor_id . '/' . $nome_arquivo,
                            $formato,
                            $_FILES[$campo]['size']
                        ]);
                    }
                }
            }
            
            // Processar documentos do scanner (enviados via AJAX)
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
                            INSERT INTO professores_documentos (professor_id, tipo_documento, nome_arquivo, caminho_arquivo, formato_papel)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $professor_id,
                            $tipo,
                            $nome_arquivo,
                            'uploads/professores/documentos/' . $professor_id . '/' . $nome_arquivo,
                            $formato
                        ]);
                    }
                }
            }
            
            // Atribuir disciplinas se selecionadas
            $carga_horaria_total = 0;
            if (isset($_POST['disciplinas']) && is_array($_POST['disciplinas'])) {
                foreach ($_POST['disciplinas'] as $index => $disciplina_id) {
                    $turma_id = $_POST['turmas'][$index] ?? null;
                    $carga_horaria = $_POST['carga_horaria_disciplina'][$index] ?? 0;
                    
                    if ($disciplina_id) {
                        $stmt = $conn->prepare("
                            INSERT INTO professor_disciplinas (professor_id, disciplina_id, turma_id, ano_letivo, carga_horaria, status)
                            VALUES (?, ?, ?, ?, ?, 'ativa')
                        ");
                        $stmt->execute([$professor_id, $disciplina_id, $turma_id, $ano_letivo, $carga_horaria]);
                        $carga_horaria_total += $carga_horaria;
                    }
                }
            }
            
            // Atualizar carga horária total do professor
            if ($carga_horaria_total > 0) {
                $stmt = $conn->prepare("UPDATE professores SET carga_horaria = ? WHERE id = ?");
                $stmt->execute([$carga_horaria_total, $professor_id]);
            }
            
            $conn->commit();
            
            $operadora = getOperadoraTelefone($telefone_validado);
            $telefone_formatado = formatarTelefoneAngola($telefone_validado);
            
            $success = "
                <div class='alert alert-success'>
                    <h5><i class='fas fa-check-circle'></i> Professor cadastrado com sucesso!</h5>
                    <hr>
                    <div class='row'>
                        <div class='col-md-6'>
                            <p><strong><i class='fas fa-id-card'></i> Número de Processo:</strong> {$numero_processo}</p>
                            <p><strong><i class='fas fa-user'></i> Nome:</strong> " . htmlspecialchars($nome_completo) . "</p>
                            <p><strong><i class='fas fa-phone'></i> Telefone:</strong> {$telefone_formatado} ({$operadora})</p>
                        </div>
                        <div class='col-md-6'>
                            <p><strong><i class='fas fa-envelope'></i> Usuário de acesso:</strong> {$email_usuario}</p>
                            <p><strong><i class='fas fa-key'></i> Senha temporária:</strong> {$bi_validado}</p>
                            <p><strong><i class='fas fa-clock'></i> Carga Horária Total:</strong> {$carga_horaria_total} horas/semana</p>
                        </div>
                    </div>
                    <div class='alert alert-warning mt-2'>
                        <i class='fas fa-exclamation-triangle'></i> 
                        <strong>Aviso:</strong> Recomenda-se que o professor altere a senha no primeiro acesso.
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
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
        
        .documento-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .documento-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .scanner-video {
            width: 100%;
            border-radius: 10px;
            background: #000;
        }
        .scanner-canvas {
            display: none;
        }
        .formato-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 8px;
            border-radius: 5px;
            font-size: 11px;
        }
        .disciplina-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        .info-badge {
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 15px;
        }
        .operadora-unitel { background: #E30613; color: white; }
        .operadora-africell { background: #F26522; color: white; }
        .operadora-movicel { background: #00A3E0; color: white; }
    </style>
</head>
<body>
   
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-plus"></i> Cadastrar Professor</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="professorTabs" role="tablist">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#dadosPessoais">Dados Pessoais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosProfissionais">Dados Profissionais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#dadosAdicionais">Dados Adicionais</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#documentos">Documentos</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#disciplinas">Disciplinas</button></li>
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
                <form method="POST" enctype="multipart/form-data" id="formProfessor">
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
                                        <label class="required">Nº do BI</label>
                                        <input type="text" name="bi_numero" class="form-control" required 
                                               placeholder="123456789AA001" pattern="[0-9]{9}[A-Z]{2}[0-9]{3}">
                                        <small class="text-muted">Formato: 9 números + 2 letras + 3 números</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Data de Emissão do BI</label>
                                        <input type="date" name="bi_emissao" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Validade do BI</label>
                                        <input type="date" name="bi_validade" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>NUIT (NIF)</label>
                                        <input type="text" name="nuit" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Nacionalidade</label>
                                        <input type="text" name="nacionalidade" class="form-control" value="Angolana">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Naturalidade</label>
                                        <input type="text" name="naturalidade" class="form-control">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NOVO: Estado Civil e Religião -->
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Estado Civil</label>
                                        <select name="estado_civil" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($estadosCivis as $ec): ?>
                                                <option value="<?php echo $ec; ?>"><?php echo $ec; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Religião</label>
                                        <select name="religiao" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($religioes as $r): ?>
                                                <option value="<?php echo $r; ?>"><?php echo $r; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Naturalidade (Cidade/Província)</label>
                                        <input type="text" name="naturalidade" class="form-control" placeholder="Ex: Luanda, Benguela...">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NOVO: Nome dos Pais -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Pai</label>
                                        <input type="text" name="nome_pai" class="form-control" placeholder="Nome completo do pai">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome da Mãe</label>
                                        <input type="text" name="nome_mae" class="form-control" placeholder="Nome completo da mãe">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label>Província</label>
                                        <div class="input-group">
                                            <select name="provincia" id="provincia" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($provincias as $p): ?>
                                                <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
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
                                            <select name="municipio" id="municipio" class="form-control" disabled>
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
                                            <select name="comuna" id="comuna" class="form-control" disabled>
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
                                <textarea name="endereco" class="form-control" rows="2" placeholder="Rua, Bairro, Nº, Referência..."></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Telefone</label>
                                        <input type="tel" name="telefone" id="telefone" class="form-control" required 
                                               placeholder="923456789" pattern="9[1-9][0-9]{7}">
                                        <small class="text-muted" id="operadora-info"></small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>E-mail</label>
                                        <input type="email" name="email" class="form-control" placeholder="professor@email.com">
                                        <small class="text-muted">Opcional. Será usado para acesso ao sistema</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NOVO: Contacto de Emergência -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Contacto de Emergência</label>
                                        <input type="tel" name="telefone_emergencia" class="form-control" placeholder="9XX XXX XXX">
                                        <small class="text-muted">Número para contacto em caso de emergência</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nome do Contacto de Emergência</label>
                                        <input type="text" name="nome_emergencia" class="form-control" placeholder="Nome da pessoa para contacto">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Dados Profissionais -->
                        <div class="tab-pane fade" id="dadosProfissionais">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Especialidade</label>
                                        <input type="text" name="especialidade" class="form-control" placeholder="Ex: Matemática, Português, Física...">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data de Admissão</label>
                                        <input type="date" name="data_admissao" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NOVO: Cargo e Habilitação -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Cargo/Função</label>
                                        <select name="cargo" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($cargos as $c): ?>
                                                <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Habilitação Literária</label>
                                        <select name="habilitacao" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($habilitacoes as $h): ?>
                                                <option value="<?php echo $h; ?>"><?php echo $h; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NOVO: Dados Bancários -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Banco</label>
                                        <select name="banco" class="form-control">
                                            <option value="">Selecione...</option>
                                            <option value="BAI">BAI - Banco Angolano de Investimentos</option>
                                            <option value="BFA">BFA - Banco de Fomento Angola</option>
                                            <option value="BIC">BIC - Banco BIC</option>
                                            <option value="KEVE">Banco Keve</option>
                                            <option value="SOL">Banco Sol</option>
                                            <option value="ECONOMICO">Banco Económico</option>
                                            <option value="MILENIUM">Banco Millenium Atlântico</option>
                                            <option value="VTB">Banco VTB</option>
                                            <option value="YETU">Banco Yetu</option>
                                            <option value="STANDARD">Standard Bank</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>IBAN/Nº de Conta</label>
                                        <input type="text" name="iban" class="form-control" placeholder="Número da conta bancária">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- NOVO: Contrato -->
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data de Início do Contrato</label>
                                        <input type="date" name="data_inicio" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Tipo de Contrato</label>
                                        <select name="tipo_contrato" class="form-control">
                                            <option value="Efetivo">Efetivo</option>
                                            <option value="Contratado">Contratado</option>
                                            <option value="Estágio">Estágio Profissional</option>
                                            <option value="Temporário">Temporário</option>
                                            <option value="Voluntário">Voluntário</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Formação Académica</label>
                                <textarea name="formacao" class="form-control" rows="3" placeholder="Licenciatura, Mestrado, Especializações, Cursos..."></textarea>
                            </div>
                        </div>
                        
                        <!-- NOVA ABA: Dados Adicionais -->
                        <div class="tab-pane fade" id="dadosAdicionais">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Informações complementares para o cadastro do professor
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Nº de Segurança Social</label>
                                        <input type="text" name="seguranca_social" class="form-control" placeholder="Número de inscrição na Segurança Social">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Carteira Profissional</label>
                                        <input type="text" name="carteira_profissional" class="form-control" placeholder="Número da carteira profissional">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data da 1ª Nomeação</label>
                                        <input type="date" name="data_nomeacao" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Tempo de Serviço (anos)</label>
                                        <input type="number" name="tempo_servico" class="form-control" placeholder="Anos de serviço" min="0" step="0.5">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Observações</label>
                                        <textarea name="observacoes" class="form-control" rows="3" placeholder="Informações adicionais sobre o professor..."></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Documentos (com opção OUTROS) -->
                        <div class="tab-pane fade" id="documentos">
                            <div id="documentos-container">
                                <div class="documento-card">
                                    <div class="row align-items-center">
                                        <div class="col-md-3">
                                            <label>Tipo de Documento</label>
                                            <select class="form-control tipo-documento" required>
                                                <option value="bi">BI / Documento de Identificação</option>
                                                <option value="diploma">Diploma / Certificado</option>
                                                <option value="certificacao">Certificação / Curso</option>
                                                <option value="declaracao">Declaração / Comprovativo</option>
                                                <option value="outro">Outro Documento</option>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label>Upload de Arquivo</label>
                                            <input type="file" class="form-control file-upload" accept=".jpg,.jpeg,.png,.pdf">
                                        </div>
                                        <div class="col-md-3">
                                            <label>Scanner em Tempo Real</label>
                                            <button type="button" class="btn btn-info btn-scanner w-100" onclick="abrirScanner(this)">
                                                <i class="fas fa-camera"></i> Scanner
                                            </button>
                                        </div>
                                        <div class="col-md-2 text-center">
                                            <div class="preview-documento"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-success mt-2" onclick="adicionarDocumento()">
                                <i class="fas fa-plus"></i> Adicionar Documento
                            </button>
                            <input type="hidden" name="documentos_scanner" id="documentos_scanner" value="[]">
                        </div>
                        
                        <!-- Disciplinas -->
                        <div class="tab-pane fade" id="disciplinas">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> A carga horária será calculada automaticamente com base nas disciplinas selecionadas
                            </div>
                            <div id="disciplinas-container">
                                <div class="disciplina-item">
                                    <div class="row">
                                        <div class="col-md-5">
                                            <label>Disciplina</label>
                                            <select name="disciplinas[]" class="form-control disciplina-select" required>
                                                <option value="">Selecione...</option>
                                                <?php foreach ($disciplinas as $d): ?>
                                                <option value="<?php echo $d['id']; ?>" data-carga="<?php echo $d['carga_horaria']; ?>">
                                                    <?php echo htmlspecialchars($d['nome']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label>Turma</label>
                                            <select name="turmas[]" class="form-control">
                                                <option value="">Selecione...</option>
                                                <?php foreach ($turmas as $t): ?>
                                                <option value="<?php echo $t['id']; ?>">
                                                    <?php echo htmlspecialchars($t['nome'] . ' - ' . $t['turno']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label>Carga Horária (h/semana)</label>
                                            <input type="number" name="carga_horaria_disciplina[]" class="form-control carga-disciplina" readonly>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-success mt-2" onclick="adicionarDisciplina()">
                                <i class="fas fa-plus"></i> Adicionar Disciplina
                            </button>
                            <div class="alert alert-secondary mt-3">
                                <strong>Carga Horária Total:</strong> <span id="cargaHorariaTotal">0</span> horas/semana
                            </div>
                        </div>
                        
                        <!-- Foto -->
                        <div class="tab-pane fade" id="foto">
                            <div class="row">
                                <div class="col-md-6 text-center">
                                    <h5>Upload de Foto</h5>
                                    <input type="file" name="foto" id="fotoInput" class="form-control mb-3" accept="image/*">
                                    <div class="preview-container">
                                        <img id="fotoPreview" src="../../assets/images/avatar-prof-padrao.png" class="preview-img">
                                    </div>
                                </div>
                                <div class="col-md-6 text-center">
                                    <h5>Capturar com Webcam</h5>
                                    <video id="video" width="100%" autoplay style="border-radius: 10px;"></video>
                                    <button type="button" id="capturarBtn" class="btn btn-primary btn-sm mt-2">Capturar Foto</button>
                                    <canvas id="canvas" style="display:none;"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg px-5">
                            <i class="fas fa-save"></i> Cadastrar Professor
                        </button>
                        <a href="index.php" class="btn btn-secondary btn-lg px-5 ms-2">
                            <i class="fas fa-times"></i> Cancelar
                        </a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modais para adicionar localidades -->
    <div class="modal fade" id="modalNovaProvincia" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Província</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="novaProvincia" class="form-control" placeholder="Nome da Província"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarProvinciaBtn">Salvar</button></div></div></div></div>
    
    <div class="modal fade" id="modalNovoMunicipio" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Novo Município</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="municipioProvincia" class="form-control mb-2" readonly><input type="text" id="novoMunicipio" class="form-control" placeholder="Nome do Município"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarMunicipioBtn">Salvar</button></div></div></div></div>
    
    <div class="modal fade" id="modalNovaComuna" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Adicionar Nova Comuna</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="text" id="comunaMunicipio" class="form-control mb-2" readonly><input type="text" id="novaComuna" class="form-control" placeholder="Nome da Comuna"></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="button" class="btn btn-primary" id="salvarComunaBtn">Salvar</button></div></div></div></div>
    
    <!-- Modal Scanner -->
    <div class="modal fade" id="scannerModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-camera"></i> Scanner de Documentos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-3">
                        <select id="scannerTipoDocumento" class="form-control w-50 d-inline-block">
                            <option value="bi">BI</option>
                            <option value="diploma">Diploma</option>
                            <option value="certificacao">Certificação</option>
                            <option value="declaracao">Declaração</option>
                            <option value="outro">Outro Documento</option>
                        </select>
                    </div>
                    <div id="scanner-container" style="position: relative;">
                        <video id="scanner-video" class="scanner-video" autoplay></video>
                        <canvas id="scanner-canvas" class="scanner-canvas"></canvas>
                        <div class="text-center mt-3">
                            <button class="btn btn-success" onclick="capturarDocumento()">
                                <i class="fas fa-camera"></i> Capturar e Processar
                            </button>
                            <button class="btn btn-danger" onclick="fecharScanner()">
                                <i class="fas fa-times"></i> Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentScannerButton = null;
        let scannerStream = null;
        let scannerModal = null;
        let documentosScanner = [];
        
        // Menu toggle
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Detectar operadora de telefone
        function detectarOperadora() {
            const telefone = document.getElementById('telefone').value.replace(/[^0-9]/g, '');
            const operadoraInfo = document.getElementById('operadora-info');
            
            if (telefone.length >= 2) {
                const prefixo = telefone.substring(0, 2);
                let operadora = '';
                let classe = '';
                
                if (['91','92','93'].includes(prefixo)) {
                    operadora = 'UNITEL';
                    classe = 'operadora-unitel';
                } else if (['94','95','96'].includes(prefixo)) {
                    operadora = 'AFRICELL';
                    classe = 'operadora-africell';
                } else if (['97','98','99'].includes(prefixo)) {
                    operadora = 'MOVICEL';
                    classe = 'operadora-movicel';
                } else {
                    operadora = 'Número inválido';
                    classe = '';
                }
                
                if (operadora !== 'Número inválido') {
                    operadoraInfo.innerHTML = `<span class="badge ${classe} info-badge">${operadora}</span>`;
                } else {
                    operadoraInfo.innerHTML = '<span class="text-danger">Número inválido para Angola</span>';
                }
            } else {
                operadoraInfo.innerHTML = '';
            }
        }
        
        document.getElementById('telefone').addEventListener('input', detectarOperadora);
        
        // Calcular carga horária total
        function calcularCargaTotal() {
            let total = 0;
            document.querySelectorAll('.carga-disciplina').forEach(input => {
                total += parseFloat(input.value) || 0;
            });
            document.getElementById('cargaHorariaTotal').innerText = total;
        }
        
        // Preview da foto
        document.getElementById('fotoInput').onchange = function(evt) {
            const [file] = this.files;
            if (file) {
                document.getElementById('fotoPreview').src = URL.createObjectURL(file);
            }
        };
        
        // Webcam para foto
        const video = document.getElementById('video');
        const canvas = document.getElementById('canvas');
        const capturarBtn = document.getElementById('capturarBtn');
        let webcamStream = null;
        
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(mediaStream) {
                webcamStream = mediaStream;
                video.srcObject = mediaStream;
            })
            .catch(function(err) {
                console.log("Erro ao acessar webcam: " + err);
            });
        
        capturarBtn.addEventListener('click', function() {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            const fotoData = canvas.toDataURL('image/png');
            document.getElementById('fotoPreview').src = fotoData;
            
            const oldInput = document.querySelector('input[name="foto_capturada"]');
            if (oldInput) oldInput.remove();
            
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'foto_capturada';
            hiddenInput.value = fotoData;
            document.getElementById('formProfessor').appendChild(hiddenInput);
        });
        
        // Carregar municípios
        $('#provincia').change(function() {
            var provincia = $(this).val();
            if (provincia) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_municipios', provincia: provincia },
                    success: function(data) {
                        var municipios = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < municipios.length; i++) {
                            options += '<option value="' + municipios[i].nome + '">' + municipios[i].nome + '</option>';
                        }
                        $('#municipio').html(options);
                        $('#municipio').prop('disabled', false);
                        $('#comuna').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
                    }
                });
            } else {
                $('#municipio').html('<option value="">Primeiro selecione a província</option>').prop('disabled', true);
                $('#comuna').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Carregar comunas
        $('#municipio').change(function() {
            var municipio = $(this).val();
            if (municipio) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'GET',
                    data: { acao: 'get_comunas', municipio: municipio },
                    success: function(data) {
                        var comunas = JSON.parse(data);
                        var options = '<option value="">Selecione...</option>';
                        for (var i = 0; i < comunas.length; i++) {
                            options += '<option value="' + comunas[i].nome + '">' + comunas[i].nome + '</option>';
                        }
                        $('#comuna').html(options);
                        $('#comuna').prop('disabled', false);
                    }
                });
            } else {
                $('#comuna').html('<option value="">Primeiro selecione o município</option>').prop('disabled', true);
            }
        });
        
        // Adicionar documento
        function adicionarDocumento() {
            const container = document.getElementById('documentos-container');
            const template = document.querySelector('.documento-card').cloneNode(true);
            template.querySelector('.tipo-documento').value = 'outro';
            template.querySelector('.file-upload').value = '';
            template.querySelector('.preview-documento').innerHTML = '';
            container.appendChild(template);
        }
        
        // Adicionar disciplina
        function adicionarDisciplina() {
            const container = document.getElementById('disciplinas-container');
            const template = document.querySelector('.disciplina-item').cloneNode(true);
            template.querySelector('.disciplina-select').value = '';
            template.querySelector('select[name="turmas[]"]').value = '';
            template.querySelector('.carga-disciplina').value = '';
            container.appendChild(template);
        }
        
        // Auto calcular carga horária
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('disciplina-select')) {
                const row = e.target.closest('.disciplina-item');
                const carga = e.target.options[e.target.selectedIndex]?.dataset.carga || 0;
                row.querySelector('.carga-disciplina').value = carga;
                calcularCargaTotal();
            }
        });
        
        // Preview de documentos upload
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('file-upload')) {
                const previewDiv = e.target.closest('.row').querySelector('.preview-documento');
                previewDiv.innerHTML = '';
                
                if (e.target.files && e.target.files[0]) {
                    const reader = new FileReader();
                    reader.onload = function(ev) {
                        const img = document.createElement('img');
                        img.src = ev.target.result;
                        img.style.maxWidth = '50px';
                        img.style.borderRadius = '5px';
                        previewDiv.appendChild(img);
                    };
                    reader.readAsDataURL(e.target.files[0]);
                }
            }
        });
        
        // Scanner functions
        function abrirScanner(button) {
            currentScannerButton = button;
            scannerModal = new bootstrap.Modal(document.getElementById('scannerModal'));
            scannerModal.show();
            
            const video = document.getElementById('scanner-video');
            
            navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } })
                .then(function(mediaStream) {
                    scannerStream = mediaStream;
                    video.srcObject = mediaStream;
                    video.play();
                })
                .catch(function(err) {
                    alert("Erro ao acessar câmera: " + err.message);
                });
        }
        
        function capturarDocumento() {
            const video = document.getElementById('scanner-video');
            const canvas = document.getElementById('scanner-canvas');
            const context = canvas.getContext('2d');
            const tipoDocumento = document.getElementById('scannerTipoDocumento').value;
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            const imagemData = canvas.toDataURL('image/jpeg', 0.9);
            
            $.ajax({
                url: 'cadastrar.php',
                method: 'POST',
                data: {
                    acao: 'upload_documento_scanner',
                    tipo_documento: tipoDocumento,
                    imagem: imagemData
                },
                success: function(response) {
                    const result = JSON.parse(response);
                    if (result.success) {
                        documentosScanner.push({
                            tipo: tipoDocumento,
                            imagem: result.imagem,
                            formato: result.formato
                        });
                        document.getElementById('documentos_scanner').value = JSON.stringify(documentosScanner);
                        
                        const documentoCard = currentScannerButton.closest('.documento-card');
                        const previewDiv = documentoCard.querySelector('.preview-documento');
                        previewDiv.innerHTML = `
                            <div style="position: relative;">
                                <img src="${result.imagem}" style="max-width: 50px; border-radius: 5px;">
                                <span class="badge bg-info formato-badge">${result.formato}</span>
                            </div>
                        `;
                        
                        alert(`Documento capturado! Formato detectado: ${result.formato}`);
                        fecharScanner();
                    } else {
                        alert("Erro ao processar documento");
                    }
                }
            });
        }
        
        function fecharScanner() {
            if (scannerStream) {
                scannerStream.getTracks().forEach(track => track.stop());
                scannerStream = null;
            }
            if (scannerModal) {
                scannerModal.hide();
            }
        }
        
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
                            $('#provincia').append('<option value="' + result.provincia + '">' + result.provincia + '</option>');
                            $('#provincia').val(result.provincia);
                            $('#modalNovaProvincia').modal('hide');
                            $('#novaProvincia').val('');
                            $('#provincia').trigger('change');
                        }
                    }
                });
            }
        });
        
        // Salvar novo município
        $('#salvarMunicipioBtn').click(function() {
            var novoMunicipio = $('#novoMunicipio').val();
            var provincia = $('#provincia').val();
            if (novoMunicipio && provincia) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: { acao: 'add_municipio', novo_municipio: novoMunicipio, provincia_nome: provincia },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#municipio').append('<option value="' + result.municipio + '">' + result.municipio + '</option>');
                            $('#municipio').val(result.municipio);
                            $('#modalNovoMunicipio').modal('hide');
                            $('#novoMunicipio').val('');
                            $('#municipio').trigger('change');
                        }
                    }
                });
            }
        });
        
        // Salvar nova comuna
        $('#salvarComunaBtn').click(function() {
            var novaComuna = $('#novaComuna').val();
            var municipio = $('#municipio').val();
            if (novaComuna && municipio) {
                $.ajax({
                    url: 'cadastrar.php',
                    method: 'POST',
                    data: { acao: 'add_comuna', nova_comuna: novaComuna, municipio_nome: municipio },
                    success: function(data) {
                        var result = JSON.parse(data);
                        if (result.success) {
                            $('#comuna').append('<option value="' + result.comuna + '">' + result.comuna + '</option>');
                            $('#comuna').val(result.comuna);
                            $('#modalNovaComuna').modal('hide');
                            $('#novaComuna').val('');
                        }
                    }
                });
            }
        });
        
        // Preencher dados dos modais
        $('#modalNovoMunicipio').on('show.bs.modal', function() {
            var provincia = $('#provincia').val();
            if (provincia) {
                $('#municipioProvincia').val(provincia);
            } else {
                alert('Selecione uma província primeiro!');
                return false;
            }
        });
        
        $('#modalNovaComuna').on('show.bs.modal', function() {
            var municipio = $('#municipio').val();
            if (municipio) {
                $('#comunaMunicipio').val(municipio);
            } else {
                alert('Selecione um município primeiro!');
                return false;
            }
        });
        
        // Validação do formulário
        document.getElementById('formProfessor')?.addEventListener('submit', function(e) {
            const bi = document.querySelector('input[name="bi_numero"]').value;
            const biPattern = /^[0-9]{9}[A-Z]{2}[0-9]{3}$/;
            if (!biPattern.test(bi.toUpperCase())) {
                e.preventDefault();
                alert('BI inválido! Formato: 9 números + 2 letras + 3 números. Ex: 123456789AA001');
                return false;
            }
            
            const telefone = document.querySelector('input[name="telefone"]').value;
            const telPattern = /^9[1-9][0-9]{7}$/;
            if (!telPattern.test(telefone)) {
                e.preventDefault();
                alert('Telefone inválido! Deve ser um número da Unitel, Africell ou Movicel (9XX XXX XXX)');
                return false;
            }
            
            return true;
        });
        
        // Inicializar cálculo de carga total
        calcularCargaTotal();
    </script>
</body>
</html>