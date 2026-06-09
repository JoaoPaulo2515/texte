<?php
// escola/alunos/upload_documentos.php

require_once __DIR__ . '/../../config/database.php';
session_start();

header('Content-Type: application/json');

// Verificar se o usuário está logado
if (!isset($_SESSION['aluno_id']) && !isset($_SESSION['professor_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();

// Determinar quem está logado
if (isset($_SESSION['aluno_id'])) {
    $aluno_id = $_SESSION['aluno_id'];
    $escola_id = $_SESSION['escola_id'];
} elseif (isset($_SESSION['professor_id'])) {
    $aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
    $escola_id = $_SESSION['escola_id'];
} else {
    $aluno_id = isset($_POST['aluno_id']) ? (int)$_POST['aluno_id'] : 0;
    $escola_id = $_SESSION['escola_id'];
}

// Configuração dos documentos
$documentos_config = [
    'bi_documento' => [
        'tipo' => 'bi',
        'nome' => 'BI / Documento de Identificação',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf']
    ],
    'certificado_documento' => [
        'tipo' => 'certificado',
        'nome' => 'Certificado de Conclusão / Histórico',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf']
    ],
    'atestado_documento' => [
        'tipo' => 'atestado',
        'nome' => 'Atestado Médico',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf']
    ],
    'declaracao_documento' => [
        'tipo' => 'declaracao',
        'nome' => 'Declaração de Matrícula',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf']
    ],
    'outro_documento_1' => [
        'tipo' => 'outro_1',
        'nome' => 'Outro Documento 1',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']
    ],
    'outro_documento_2' => [
        'tipo' => 'outro_2',
        'nome' => 'Outro Documento 2',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']
    ],
    'outro_documento_3' => [
        'tipo' => 'outro_3',
        'nome' => 'Outro Documento 3',
        'max_size' => 5 * 1024 * 1024,
        'extensoes' => ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']
    ]
];

// Criar diretório se não existir
$upload_dir = __DIR__ . '/../../../uploads/documentos_aluno/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$documentos_salvos = [];
$erros = [];

// Loop através de cada documento
foreach ($documentos_config as $campo => $config) {
    // Verificar se o arquivo foi enviado
    if (isset($_FILES[$campo]) && $_FILES[$campo]['error'] == UPLOAD_ERR_OK) {
        $file = $_FILES[$campo];
        $extensao = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $tamanho = $file['size'];
        
        // Validar extensão
        if (!in_array($extensao, $config['extensoes'])) {
            $erros[] = "{$config['nome']}: Extensão não permitida. Use: " . implode(', ', $config['extensoes']);
            continue;
        }
        
        // Validar tamanho
        if ($tamanho > $config['max_size']) {
            $max_mb = $config['max_size'] / (1024 * 1024);
            $erros[] = "{$config['nome']}: Arquivo muito grande. Máximo: {$max_mb}MB";
            continue;
        }
        
        // Gerar nome único
        $nome_arquivo = time() . '_' . $aluno_id . '_' . $config['tipo'] . '_' . uniqid() . '.' . $extensao;
        $caminho_relativo = 'uploads/documentos_aluno/' . $nome_arquivo;
        $caminho_completo = $upload_dir . $nome_arquivo;
        
        // Mover arquivo
        if (move_uploaded_file($file['tmp_name'], $caminho_completo)) {
            try {
                // Verificar se já existe documento deste tipo
                $sql_check = "SELECT id FROM documentos_aluno 
                              WHERE aluno_id = :aluno_id AND tipo = :tipo AND escola_id = :escola_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':aluno_id' => $aluno_id,
                    ':tipo' => $config['tipo'],
                    ':escola_id' => $escola_id
                ]);
                
                $existente = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($existente) {
                    // Pegar o caminho antigo para deletar
                    $sql_old = "SELECT caminho FROM documentos_aluno WHERE id = :id";
                    $stmt_old = $conn->prepare($sql_old);
                    $stmt_old->execute([':id' => $existente['id']]);
                    $old = $stmt_old->fetch(PDO::FETCH_ASSOC);
                    
                    if ($old && file_exists(__DIR__ . '/../../..' . $old['caminho'])) {
                        unlink(__DIR__ . '/../../..' . $old['caminho']);
                    }
                    
                    // UPDATE documento existente
                    $sql = "UPDATE documentos_aluno 
                            SET nome = :nome, 
                                tipo = :tipo,
                                caminho = :caminho, 
                                tamanho = :tamanho, 
                                extensao = :extensao, 
                                data_upload = NOW(), 
                                status = 'ativo' 
                            WHERE id = :id";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':id' => $existente['id'],
                        ':nome' => $config['nome'],
                        ':tipo' => $config['tipo'],
                        ':caminho' => $caminho_relativo,
                        ':tamanho' => $tamanho,
                        ':extensao' => $extensao
                    ]);
                    $documento_id = $existente['id'];
                    $mensagem = "atualizado";
                } else {
                    // INSERT novo documento
                    $sql = "INSERT INTO documentos_aluno (escola_id, aluno_id, nome, tipo, caminho, tamanho, extensao, data_upload, status) 
                            VALUES (:escola_id, :aluno_id, :nome, :tipo, :caminho, :tamanho, :extensao, NOW(), 'ativo')";
                    $stmt = $conn->prepare($sql);
                    $stmt->execute([
                        ':escola_id' => $escola_id,
                        ':aluno_id' => $aluno_id,
                        ':nome' => $config['nome'],
                        ':tipo' => $config['tipo'],
                        ':caminho' => $caminho_relativo,
                        ':tamanho' => $tamanho,
                        ':extensao' => $extensao
                    ]);
                    $documento_id = $conn->lastInsertId();
                    $mensagem = "inserido";
                }
                
                $documentos_salvos[] = [
                    'campo' => $campo,
                    'tipo' => $config['tipo'],
                    'nome' => $config['nome'],
                    'id' => $documento_id,
                    'caminho' => $caminho_relativo,
                    'extensao' => $extensao,
                    'tamanho' => $tamanho,
                    'status' => $mensagem
                ];
                
            } catch (PDOException $e) {
                $erros[] = "{$config['nome']}: Erro ao salvar - " . $e->getMessage();
            }
        } else {
            $erros[] = "{$config['nome']}: Erro ao mover arquivo";
        }
    }
}

// Retornar resposta
if (count($documentos_salvos) > 0) {
    echo json_encode([
        'success' => true,
        'message' => count($documentos_salvos) . " documento(s) processado(s) com sucesso",
        'documentos' => $documentos_salvos,
        'erros' => $erros
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Nenhum documento foi processado',
        'erros' => $erros
    ]);
}
?>