<?php
// escola/config/ajax_permissoes.php - AJAX para permissões
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Carregar permissões por tipo
if (isset($_POST['acao']) && $_POST['acao'] == 'carregar_permissoes_tipo') {
    $tipo = $_POST['tipo'];
    
    // Buscar módulos
    $modulos = $conn->query("SELECT * FROM modulos_sistema WHERE ativo = 1 ORDER BY ordem")->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar permissões do tipo
    $stmt = $conn->prepare("SELECT * FROM permissoes_padrao WHERE tipo_usuario = :tipo");
    $stmt->execute([':tipo' => $tipo]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $permissoes = [];
    foreach ($perms as $p) {
        $permissoes[$p['modulo_id']][] = $p['permissao'];
    }
    
    $tipos_permissoes = [
        'visualizar' => 'Visualizar',
        'criar' => 'Criar',
        'editar' => 'Editar',
        'excluir' => 'Excluir',
        'exportar' => 'Exportar',
        'imprimir' => 'Imprimir'
    ];
    
    echo json_encode([
        'success' => true,
        'modulos' => $modulos,
        'permissoes' => $permissoes,
        'tipos_permissoes' => $tipos_permissoes
    ]);
    exit;
}

// Copiar permissões
if (isset($_POST['acao']) && $_POST['acao'] == 'copiar_permissoes') {
    $origem = (int)$_POST['origem'];
    $destinos = $_POST['destinos'];
    
    // Buscar permissões do usuário origem
    $stmt = $conn->prepare("SELECT * FROM permissoes_usuario WHERE usuario_id = :usuario_id");
    $stmt->execute([':usuario_id' => $origem]);
    $permissoes_origem = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = 0;
    foreach ($destinos as $destino) {
        // Limpar permissões do destino
        $conn->prepare("DELETE FROM permissoes_usuario WHERE usuario_id = :usuario_id")->execute([':usuario_id' => $destino]);
        
        // Copiar permissões
        foreach ($permissoes_origem as $perm) {
            $conn->prepare("INSERT INTO permissoes_usuario (usuario_id, modulo_id, permissao, concedido) VALUES (:usuario, :modulo, :permissao, 1)")
                ->execute([':usuario' => $destino, ':modulo' => $perm['modulo_id'], ':permissao' => $perm['permissao']]);
            $count++;
        }
    }
    
    echo json_encode(['success' => true, 'message' => "Permissões copiadas para " . count($destinos) . " usuário(s). $count permissões aplicadas."]);
    exit;
}

// Aplicar permissões padrão por tipo
if (isset($_POST['acao']) && $_POST['acao'] == 'aplicar_permissoes_tipo') {
    $tipo = $_POST['tipo'];
    $usuarios = isset($_POST['usuarios']) ? $_POST['usuarios'] : [];
    
    // Buscar permissões padrão do tipo
    $stmt = $conn->prepare("SELECT * FROM permissoes_padrao WHERE tipo_usuario = :tipo");
    $stmt->execute([':tipo' => $tipo]);
    $permissoes_padrao = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Definir quais usuários afetar
    if (empty($usuarios)) {
        $stmt = $conn->prepare("SELECT id FROM usuarios WHERE tipo = :tipo AND escola_id = :escola_id");
        $stmt->execute([':tipo' => $tipo, ':escola_id' => $escola_id]);
        $usuarios = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    $count = 0;
    foreach ($usuarios as $usuario_id) {
        // Limpar permissões existentes
        $conn->prepare("DELETE FROM permissoes_usuario WHERE usuario_id = :usuario_id")->execute([':usuario_id' => $usuario_id]);
        
        // Aplicar permissões padrão
        foreach ($permissoes_padrao as $perm) {
            $conn->prepare("INSERT INTO permissoes_usuario (usuario_id, modulo_id, permissao, concedido) VALUES (:usuario, :modulo, :permissao, 1)")
                ->execute([':usuario' => $usuario_id, ':modulo' => $perm['modulo_id'], ':permissao' => $perm['permissao']]);
            $count++;
        }
    }
    
    echo json_encode(['success' => true, 'message' => "Permissões aplicadas a " . count($usuarios) . " usuário(s). $count permissões configuradas."]);
    exit;
}

// Salvar cargo
if (isset($_POST['acao']) && $_POST['acao'] == 'salvar_cargo') {
    $nome = $_POST['nome'];
    $descricao = $_POST['descricao'];
    $nivel = (int)$_POST['nivel'];
    
    $stmt = $conn->prepare("INSERT INTO cargos_sistema (escola_id, nome, descricao, nivel) VALUES (:escola_id, :nome, :descricao, :nivel)");
    $stmt->execute([':escola_id' => $escola_id, ':nome' => $nome, ':descricao' => $descricao, ':nivel' => $nivel]);
    
    echo json_encode(['success' => true]);
    exit;
}

// Excluir cargo
if (isset($_POST['acao']) && $_POST['acao'] == 'excluir_cargo') {
    $id = (int)$_POST['id'];
    $conn->prepare("DELETE FROM cargos_sistema WHERE id = :id AND escola_id = :escola_id")->execute([':id' => $id, ':escola_id' => $escola_id]);
    
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação inválida']);
exit;
?>