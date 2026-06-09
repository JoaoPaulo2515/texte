<?php
// escola/rh/recrutamento/novo_candidato.php - Cadastro de Candidato a Vaga
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// FUNÇÕES DE VALIDAÇÃO (CONTEXTO ANGOLANO)
// ============================================

// Validar BI de Angola
function validarBIAngola($bi) {
    $bi = strtoupper(preg_replace('/[^A-Z0-9]/', '', $bi));
    // Formato: 9 números + 2 letras + 3 números
    if (preg_match('/^[0-9]{9}[A-Z]{2}[0-9]{3}$/', $bi)) {
        return $bi;
    }
    return false;
}

// Validar telefone Angola
function validarTelefoneAngola($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    // Redes Angola: 91, 92, 93, 94, 95, 96, 97, 98, 99
    if (preg_match('/^9[1-9][0-9]{7}$/', $telefone)) {
        return $telefone;
    }
    return false;
}

// Validar email
function validarEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Obter operadora de telefone
function getOperadoraTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) >= 2) {
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

// Formatar telefone para exibição
function formatarTelefone($telefone) {
    $telefone = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($telefone) == 9) {
        return substr($telefone, 0, 3) . ' ' . substr($telefone, 3, 3) . ' ' . substr($telefone, 6, 3);
    }
    return $telefone;
}

// Função para upload de currículo
function uploadCurriculo($arquivo, $candidato_nome) {
    if (!isset($arquivo) || $arquivo['error'] != 0) {
        return null;
    }
    
    $extensoes_permitidas = ['pdf', 'doc', 'docx'];
    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, $extensoes_permitidas)) {
        return false;
    }
    
    $tamanho_maximo = 5242880; // 5MB
    if ($arquivo['size'] > $tamanho_maximo) {
        return false;
    }
    
    $upload_dir = __DIR__ . '/../../../uploads/rh/candidatos/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $nome_limpo = preg_replace('/[^a-zA-Z0-9]/', '_', $candidato_nome);
    $nome_arquivo = 'curriculo_' . $nome_limpo . '_' . time() . '_' . uniqid() . '.' . $ext;
    
    if (move_uploaded_file($arquivo['tmp_name'], $upload_dir . $nome_arquivo)) {
        return 'uploads/rh/candidatos/' . $nome_arquivo;
    }
    
    return false;
}

// Buscar vagas ativas
$stmt = $conn->prepare("SELECT id, titulo, cargo, tipo_contrato, quantidade FROM vagas_emprego WHERE escola_id = ? AND status = 'aberta' ORDER BY data_abertura DESC");
$stmt->execute([$escola_id]);
$vagas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Processar formulário
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validar campos obrigatórios
    $vaga_id = $_POST['vaga_id'] ?? 0;
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    $bi = trim($_POST['bi'] ?? '');
    $data_nascimento = $_POST['data_nascimento'] ?? '';
    $genero = $_POST['genero'] ?? '';
    $provincia = $_POST['provincia'] ?? '';
    $municipio = $_POST['municipio'] ?? '';
    $habilitacao = $_POST['habilitacao'] ?? '';
    $experiencia = $_POST['experiencia'] ?? '';
    $pretensao_salarial = $_POST['pretensao_salarial'] ?? '';
    
    // Validar vaga
    if (!$vaga_id) {
        $error = "Selecione uma vaga para candidatura.";
    }
    
    // Validar nome
    if (strlen($nome) < 3) {
        $error = "Nome completo é obrigatório (mínimo 3 caracteres).";
    }
    
    // Validar email
    if (!empty($email) && !validarEmail($email)) {
        $error = "E-mail inválido.";
    }
    
    // Validar telefone
    $telefone_validado = validarTelefoneAngola($telefone);
    if (!$telefone_validado) {
        $error = "Telefone inválido! Deve ser um número da Unitel, Africell ou Movicel (9XX XXX XXX). Ex: 923456789";
    }
    
    // Validar BI
    $bi_validado = validarBIAngola($bi);
    if (!$bi_validado) {
        $error = "BI inválido! Formato: 9 números + 2 letras + 3 números. Ex: 123456789AA001";
    }
    
    // Validar currículo
    $curriculo_path = null;
    if (isset($_FILES['curriculo']) && $_FILES['curriculo']['error'] == 0) {
        $curriculo_path = uploadCurriculo($_FILES['curriculo'], $nome);
        if ($curriculo_path === false) {
            $error = "Erro ao enviar currículo. Formatos permitidos: PDF, DOC, DOCX (Max: 5MB)";
        }
    } else {
        $error = "O currículo é obrigatório.";
    }
    
    if (!$error) {
        try {
            // Verificar se já existe candidatura para esta vaga com este BI
            $stmt = $conn->prepare("SELECT id FROM candidatos WHERE vaga_id = ? AND bi = ?");
            $stmt->execute([$vaga_id, $bi_validado]);
            if ($stmt->fetch()) {
                throw new Exception("Já existe uma candidatura para esta vaga com este BI.");
            }
            
            // Verificar se já existe candidatura para esta vaga com este email
            if (!empty($email)) {
                $stmt = $conn->prepare("SELECT id FROM candidatos WHERE vaga_id = ? AND email = ?");
                $stmt->execute([$vaga_id, $email]);
                if ($stmt->fetch()) {
                    throw new Exception("Já existe uma candidatura para esta vaga com este e-mail.");
                }
            }
            
            // Inserir candidato
            $stmt = $conn->prepare("
                INSERT INTO candidatos (
                    vaga_id, nome, email, telefone, bi, data_nascimento, genero,
                    provincia, municipio, habilitacao, experiencia, pretensao_salarial,
                    curriculo, status, data_candidatura
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', NOW())
            ");
            
            $stmt->execute([
                $vaga_id,
                $nome,
                $email ?: null,
                $telefone_validado,
                $bi_validado,
                $data_nascimento ?: null,
                $genero ?: null,
                $provincia ?: null,
                $municipio ?: null,
                $habilitacao ?: null,
                $experiencia ?: null,
                $pretensao_salarial ?: null,
                $curriculo_path
            ]);
            
            $candidato_id = $conn->lastInsertId();
            
            // Processar documentos adicionais
            $upload_dir_docs = __DIR__ . '/../../../uploads/rh/candidatos/documentos/' . $candidato_id . '/';
            if (!is_dir($upload_dir_docs)) {
                mkdir($upload_dir_docs, 0777, true);
            }
            
            $documentos = [
                'certificado_habilitacoes' => $_FILES['certificado_habilitacoes'] ?? null,
                'certificado_formacao' => $_FILES['certificado_formacao'] ?? null,
                'carta_recomendacao' => $_FILES['carta_recomendacao'] ?? null,
                'outro_documento' => $_FILES['outro_documento'] ?? null
            ];
            
            foreach ($documentos as $tipo => $arquivo) {
                if ($arquivo && $arquivo['error'] == 0) {
                    $ext = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
                    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                        $nome_arquivo = $tipo . '_' . time() . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($arquivo['tmp_name'], $upload_dir_docs . $nome_arquivo)) {
                            $stmt = $conn->prepare("
                                INSERT INTO candidatos_documentos (candidato_id, tipo_documento, nome_arquivo, caminho_arquivo)
                                VALUES (?, ?, ?, ?)
                            ");
                            $stmt->execute([
                                $candidato_id,
                                $tipo,
                                $nome_arquivo,
                                'uploads/rh/candidatos/documentos/' . $candidato_id . '/' . $nome_arquivo
                            ]);
                        }
                    }
                }
            }
            
            $operadora = getOperadoraTelefone($telefone_validado);
            $telefone_formatado = formatarTelefone($telefone_validado);
            
            $success = "
                <div class='alert alert-success'>
                    <h5><i class='fas fa-check-circle'></i> Candidatura submetida com sucesso!</h5>
                    <hr>
                    <div class='row'>
                        <div class='col-md-6'>
                            <p><strong><i class='fas fa-user'></i> Candidato:</strong> " . htmlspecialchars($nome) . "</p>
                            <p><strong><i class='fas fa-id-card'></i> BI:</strong> " . htmlspecialchars($bi_validado) . "</p>
                            <p><strong><i class='fas fa-phone'></i> Telefone:</strong> {$telefone_formatado} ({$operadora})</p>
                        </div>
                        <div class='col-md-6'>
                            <p><strong><i class='fas fa-briefcase'></i> Vaga:</strong> " . htmlspecialchars($_POST['vaga_titulo'] ?? '') . "</p>
                            <p><strong><i class='fas fa-calendar'></i> Data:</strong> " . date('d/m/Y H:i') . "</p>
                            <p><strong><i class='fas fa-hourglass-half'></i> Status:</strong> <span class='badge bg-warning'>Pendente</span></p>
                        </div>
                    </div>
                    <div class='alert alert-info mt-2'>
                        <i class='fas fa-info-circle'></i> 
                        O seu currículo será analisado pela equipa de RH. Entraremos em contacto através do telefone ou e-mail fornecido.
                    </div>
                </div>
            ";
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Buscar províncias
$provincias = $conn->query("SELECT DISTINCT nome FROM angola_provincias ORDER BY nome")->fetchAll(PDO::FETCH_COLUMN);
if (empty($provincias)) {
    $provincias = ['Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 'Namibe', 'Uíge', 'Zaire'];
}

$habilitacoes = [
    '6ª Classe', '9ª Classe', '12ª Classe', 'Formação de Professores (IMED)',
    'Bacharelato', 'Licenciatura', 'Pós-Graduação', 'Mestrado', 'Doutoramento',
    'Curso de Especialização', 'Curso de Formação Contínua', 'Outro'
];

$generos = ['M' => 'Masculino', 'F' => 'Feminino'];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nova Candidatura | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; }
        .nav-menu { list-style: none; padding: 20px 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .required:after { content: "*"; color: red; margin-left: 5px; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .info-legislacao { background: #e8f5e9; border-left: 4px solid #006B3E; }
        .vaga-info { background: #f8f9fa; border-radius: 10px; padding: 15px; margin-bottom: 20px; }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-plus"></i> Nova Candidatura</h2>
            <a href="candidatos.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <?php echo $success; ?>
        <?php endif; ?>
        
        <?php if (!$success): ?>
        <div class="alert alert-info info-legislacao">
            <i class="fas fa-gavel"></i> <strong>Lei Geral do Trabalho (Lei 7/15, de 15 de Junho)</strong><br>
            Preencha todos os campos obrigatórios. Os seus dados serão tratados conforme a legislação angolana de proteção de dados.
        </div>
        
        <form method="POST" enctype="multipart/form-data" id="formCandidato">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-briefcase"></i> Dados da Vaga
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="required">Vaga pretendida</label>
                                <select name="vaga_id" id="vaga_id" class="form-control" required>
                                    <option value="">Selecione uma vaga...</option>
                                    <?php foreach ($vagas as $vaga): ?>
                                        <option value="<?php echo $vaga['id']; ?>" data-titulo="<?php echo htmlspecialchars($vaga['titulo']); ?>">
                                            <?php echo htmlspecialchars($vaga['titulo']); ?> - <?php echo $vaga['cargo']; ?> (<?php echo $vaga['tipo_contrato']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="vagaInfo" class="vaga-info" style="display: none;">
                                <small class="text-muted">Informações da vaga selecionada</small>
                                <div id="vagaDetalhes"></div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-user"></i> Dados Pessoais
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="required">Nome Completo</label>
                                <input type="text" name="nome" class="form-control" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Data de Nascimento</label>
                                        <input type="date" name="data_nascimento" class="form-control">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Género</label>
                                        <select name="genero" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($generos as $key => $g): ?>
                                                <option value="<?php echo $key; ?>"><?php echo $g; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">BI/Nº de Identificação</label>
                                        <input type="text" name="bi" class="form-control" required 
                                               placeholder="123456789AA001" pattern="[0-9]{9}[A-Z]{2}[0-9]{3}">
                                        <small class="text-muted">Formato: 9 números + 2 letras + 3 números</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Habilitações Literárias</label>
                                        <select name="habilitacao" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($habilitacoes as $h): ?>
                                                <option value="<?php echo $h; ?>"><?php echo $h; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-address-card"></i> Contacto e Localização
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label>E-mail</label>
                                <input type="email" name="email" class="form-control" placeholder="exemplo@email.com">
                                <small class="text-muted">Será usado para notificações sobre a candidatura</small>
                            </div>
                            <div class="mb-3">
                                <label class="required">Telefone</label>
                                <input type="tel" name="telefone" id="telefone" class="form-control" required 
                                       placeholder="923456789" pattern="9[1-9][0-9]{7}">
                                <small class="text-muted" id="operadora-info"></small>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Província</label>
                                        <select name="provincia" id="provincia" class="form-control">
                                            <option value="">Selecione...</option>
                                            <?php foreach ($provincias as $p): ?>
                                                <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Município</label>
                                        <select name="municipio" id="municipio" class="form-control" disabled>
                                            <option value="">Primeiro selecione a província</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-file-alt"></i> Informações Profissionais
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label>Experiência Profissional</label>
                                <textarea name="experiencia" class="form-control" rows="4" placeholder="Descreva sua experiência profissional, empresas onde trabalhou, funções exercidas..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label>Pretensão Salarial (Kz)</label>
                                <input type="number" name="pretensao_salarial" class="form-control" placeholder="Ex: 250000">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-folder-open"></i> Documentos
                        </div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Formatos aceites:</strong> PDF, DOC, DOCX, JPG, PNG. Tamanho máximo: 5MB por arquivo.
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="required">Currículo Vitae</label>
                                        <input type="file" name="curriculo" class="form-control" accept=".pdf,.doc,.docx" required>
                                        <small class="text-muted">Formatos: PDF, DOC, DOCX (obrigatório)</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Certificado de Habilitações</label>
                                        <input type="file" name="certificado_habilitacoes" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Certificados de Formação</label>
                                        <input type="file" name="certificado_formacao" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label>Carta de Recomendação</label>
                                        <input type="file" name="carta_recomendacao" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="mb-3">
                                        <label>Outros Documentos</label>
                                        <input type="file" name="outro_documento" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card mt-3">
                <div class="card-header">
                    <i class="fas fa-check-circle"></i> Declaração
                </div>
                <div class="card-body">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="declaracao" required>
                        <label class="form-check-label" for="declaracao">
                            Declaro que as informações prestadas são verdadeiras e autorizo o tratamento dos meus dados pessoais para efeitos do processo de recrutamento, conforme a Lei Geral do Trabalho de Angola.
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <button type="submit" class="btn btn-primary btn-lg px-5">
                    <i class="fas fa-paper-plane"></i> Submeter Candidatura
                </button>
                <a href="candidatos.php" class="btn btn-secondary btn-lg px-5 ms-2">
                    <i class="fas fa-times"></i> Cancelar
                </a>
            </div>
        </form>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
                const submenu = parent.querySelector('.nav-submenu');
                if (submenu) submenu.classList.toggle('show');
            }
        }
        
        // Detetar operadora de telefone
        function detectarOperadora() {
            const telefone = document.getElementById('telefone').value.replace(/[^0-9]/g, '');
            const operadoraInfo = document.getElementById('operadora-info');
            
            if (telefone.length >= 2) {
                const prefixo = telefone.substring(0, 2);
                let operadora = '';
                let classe = '';
                
                if (['91','92','93'].includes(prefixo)) {
                    operadora = 'UNITEL';
                    classe = 'text-success';
                } else if (['94','95','96'].includes(prefixo)) {
                    operadora = 'AFRICELL';
                    classe = 'text-primary';
                } else if (['97','98','99'].includes(prefixo)) {
                    operadora = 'MOVICEL';
                    classe = 'text-info';
                } else {
                    operadora = 'Número inválido';
                    classe = 'text-danger';
                }
                
                operadoraInfo.innerHTML = `<span class="${classe}"><i class="fas fa-sim-card"></i> ${operadora}</span>`;
            } else {
                operadoraInfo.innerHTML = '';
            }
        }
        
        document.getElementById('telefone').addEventListener('input', detectarOperadora);
        
        // Mostrar informações da vaga selecionada
        $('#vaga_id').change(function() {
            const selectedOption = $(this).find('option:selected');
            const titulo = selectedOption.data('titulo') || selectedOption.text();
            const vagaInfo = $('#vagaInfo');
            
            if ($(this).val()) {
                $('#vagaDetalhes').html(`
                    <p class="mb-0"><strong><i class="fas fa-briefcase"></i> Título:</strong> ${titulo}</p>
                    <p class="mb-0"><strong><i class="fas fa-file-contract"></i> Tipo de Contrato:</strong> ${selectedOption.text().split('(')[1]?.replace(')', '') || 'N/A'}</p>
                `);
                vagaInfo.show();
                
                // Adicionar campo hidden com título da vaga
                if ($('input[name="vaga_titulo"]').length === 0) {
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'vaga_titulo',
                        value: titulo
                    }).appendTo('#formCandidato');
                } else {
                    $('input[name="vaga_titulo"]').val(titulo);
                }
            } else {
                vagaInfo.hide();
            }
        });
        
        // Carregar municípios por província
        $('#provincia').change(function() {
            var provincia = $(this).val();
            if (provincia) {
                $.ajax({
                    url: 'novo_candidato.php',
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
                    }
                });
            } else {
                $('#municipio').html('<option value="">Primeiro selecione a província</option>').prop('disabled', true);
            }
        });
    </script>
</body>
</html>