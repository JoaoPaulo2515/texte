<?php
// escola/secretaria/ajax_gerar_numero.php - Gerar número automático de certificado

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autorizado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$tipo = $_POST['tipo'] ?? 'conclusao';

function gerarNumeroCertificado($conn, $escola_id, $tipo) {
    $prefixos = [
        'conclusao' => 'CERT-CON',
        'frequencia' => 'CERT-FRE',
        'aproveitamento' => 'CERT-APR',
        'participacao' => 'CERT-PAR',
        'estagio' => 'CERT-EST'
    ];
    
    $prefixo = $prefixos[$tipo] ?? 'CERT';
    $ano = date('Y');
    
    $sql = "SELECT numero_certificado FROM certificados 
            WHERE escola_id = :escola_id 
            AND numero_certificado LIKE :prefixo 
            ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':escola_id' => $escola_id,
        ':prefixo' => $prefixo . '-' . $ano . '-%'
    ]);
    $ultimo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($ultimo) {
        $partes = explode('-', $ultimo['numero_certificado']);
        $numero = (int)end($partes);
        $novo_numero = str_pad($numero + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $novo_numero = '0001';
    }
    
    return $prefixo . '-' . $ano . '-' . $novo_numero;
}

$numero = gerarNumeroCertificado($conn, $escola_id, $tipo);
echo json_encode(['success' => true, 'numero' => $numero]);
?>