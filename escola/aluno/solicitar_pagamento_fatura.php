<?php
// escola/aluno/financeiro/solicitar_pagamento_fatura.php - Solicitar Pagamento de Fatura

require_once __DIR__ . '/../../config/database.php';
session_start();

header('Content-Type: application/json');

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autenticado']);
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_email = $_SESSION['aluno_email'] ?? '';

$fatura_id = isset($_POST['fatura_id']) ? (int)$_POST['fatura_id'] : 0;
$metodo = isset($_POST['metodo']) ? $_POST['metodo'] : '';
$observacoes = isset($_POST['observacoes']) ? trim($_POST['observacoes']) : '';

if (!$fatura_id) {
    echo json_encode(['success' => false, 'message' => 'ID da fatura não informado']);
    exit;
}

// ==============================================
// BUSCAR DADOS DA FATURA
// ==============================================
$sql_fatura = "SELECT fp.*, e.nome as escola_nome, e.email as escola_email, e.telefone as escola_telefone
               FROM faturas_proforma fp
               CROSS JOIN escola e ON e.id = fp.escola_id
               WHERE fp.id = :fatura_id 
               AND fp.estudante_id = :aluno_id 
               AND fp.escola_id = :escola_id
               AND fp.status = 'pendente'";

$stmt_fatura = $conn->prepare($sql_fatura);
$stmt_fatura->execute([
    ':fatura_id' => $fatura_id,
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$fatura = $stmt_fatura->fetch(PDO::FETCH_ASSOC);

if (!$fatura) {
    echo json_encode(['success' => false, 'message' => 'Fatura não encontrada, já paga ou expirada']);
    exit;
}

// Verificar se a fatura não expirou
if (strtotime($fatura['data_validade']) < time()) {
    echo json_encode(['success' => false, 'message' => 'Esta fatura já expirou. Solicite uma nova fatura.']);
    exit;
}

// ==============================================
// BUSCAR ITENS DA FATURA
// ==============================================
$sql_itens = "SELECT * FROM faturas_proforma_itens WHERE fatura_id = :fatura_id";
$stmt_itens = $conn->prepare($sql_itens);
$stmt_itens->execute([':fatura_id' => $fatura_id]);
$itens = $stmt_itens->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// BUSCAR DADOS DO ALUNO
// ==============================================
$sql_aluno = "SELECT nome, matricula, email, telefone FROM estudantes WHERE id = :aluno_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':aluno_id' => $aluno_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

// ==============================================
// GERAR CÓDIGO DE SOLICITAÇÃO
// ==============================================
$codigo_solicitacao = 'SOL-' . strtoupper(uniqid()) . '-' . date('Ymd');

// ==============================================
// SALVAR SOLICITAÇÃO NO BANCO
// ==============================================
$sql_solicitacao = "INSERT INTO solicitacoes_pagamento 
                    (fatura_id, aluno_id, escola_id, codigo_solicitacao, metodo_pagamento, valor_total, observacoes, status, data_solicitacao, ip_address, user_agent)
                    VALUES 
                    (:fatura_id, :aluno_id, :escola_id, :codigo, :metodo, :valor, :obs, 'pendente', NOW(), :ip, :user_agent)";

$stmt_solicitacao = $conn->prepare($sql_solicitacao);
$stmt_solicitacao->execute([
    ':fatura_id' => $fatura_id,
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id,
    ':codigo' => $codigo_solicitacao,
    ':metodo' => $metodo ?: 'pendente',
    ':valor' => $fatura['total'],
    ':obs' => $observacoes,
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
]);

$solicitacao_id = $conn->lastInsertId();

// ==============================================
// ATUALIZAR STATUS DA FATURA (opcional)
// ==============================================
// Mantém como pendente até confirmação do pagamento

// ==============================================
// ENVIAR E-MAIL DE CONFIRMAÇÃO
// ==============================================
$email_enviado = false;
$email_erro = '';

if (!empty($aluno['email'])) {
    $assunto = "Solicitação de Pagamento - Fatura " . $fatura['numero_fatura'];
    
    $mensagem_html = "
    <!DOCTYPE html>
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #006B3E; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f8f9fa; }
            .fatura-info { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .total { font-size: 1.2em; color: #006B3E; font-weight: bold; }
            .footer { text-align: center; padding: 15px; font-size: 12px; color: #666; }
            .btn { background: #006B3E; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>" . htmlspecialchars($fatura['escola_nome'] ?? 'SIGE Angola') . "</h2>
                <p>Solicitação de Pagamento</p>
            </div>
            <div class='content'>
                <p>Olá, <strong>" . htmlspecialchars($aluno['nome']) . "</strong>!</p>
                <p>Sua solicitação de pagamento foi recebida com sucesso.</p>
                
                <div class='fatura-info'>
                    <h3>Detalhes da Solicitação</h3>
                    <p><strong>Código:</strong> {$codigo_solicitacao}</p>
                    <p><strong>Fatura:</strong> {$fatura['numero_fatura']}</p>
                    <p><strong>Data da Solicitação:</strong> " . date('d/m/Y H:i:s') . "</p>
                    <p><strong>Método de Pagamento:</strong> " . ucfirst($metodo ?: 'A definir') . "</p>
                    <p><strong>Valor Total:</strong> <span class='total'>" . number_format($fatura['total'], 2, ',', '.') . " KZ</span></p>
                </div>
                
                <h4>Próximos Passos:</h4>
                <ol>
                    <li>Aguardar o contato da secretaria financeira</li>
                    <li>Realizar o pagamento conforme orientações</li>
                    <li>Enviar o comprovante para o e-mail financeiro da escola</li>
                    <li>Aguardar a confirmação do pagamento</li>
                </ol>
                
                <div class='fatura-info'>
                    <h4>Informações Bancárias (se aplicável)</h4>
                    <p><strong>Banco:</strong> Banco Angolano de Investimentos (BAI)</p>
                    <p><strong>Agência:</strong> 0001 - Sede</p>
                    <p><strong>Conta:</strong> 123 456 789 001</p>
                    <p><strong>IBAN:</strong> AO06 0001 1234 5678 9012 3456 7</p>
                    <p><strong>SWIFT:</strong> BAI AO LU</p>
                    <p><strong>Beneficiário:</strong> " . htmlspecialchars($fatura['escola_nome'] ?? 'SIGE Angola') . "</p>
                    <p><strong>NIF:</strong> " . ($fatura['escola_nif'] ?? '---') . "</p>
                </div>
                
                <p style='margin-top: 20px;'>
                    <strong>Observações da sua solicitação:</strong><br>
                    " . nl2br(htmlspecialchars($observacoes ?: 'Nenhuma observação adicional.')) . "
                </p>
                
                <p>Se tiver alguma dúvida, entre em contato com a secretaria financeira da escola.</p>
                
                <div style='text-align: center; margin-top: 30px;'>
                    <a href='http://localhost/sige_Plataforma/escola/aluno/financeiro/ver_fatura_proforma.php?id={$fatura_id}' 
                       class='btn' style='color: white;'>Visualizar Fatura</a>
                </div>
            </div>
            <div class='footer'>
                <p>Este é um e-mail automático, por favor não responda.</p>
                <p>" . htmlspecialchars($fatura['escola_nome'] ?? 'SIGE Angola') . " - Sistema de Gestão Escolar</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    // Configuração do e-mail (ajuste conforme seu servidor)
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . ($fatura['escola_email'] ?? 'financeiro@sigeangola.com') . "\r\n";
    $headers .= "Reply-To: " . ($fatura['escola_email'] ?? 'financeiro@sigeangola.com') . "\r\n";
    
    $email_enviado = mail($aluno['email'], $assunto, $mensagem_html, $headers);
    
    if (!$email_enviado) {
        $email_erro = 'Não foi possível enviar o e-mail de confirmação.';
    }
}

// ==============================================
// REGISTRAR LOG DA SOLICITAÇÃO
// ==============================================
$sql_log = "INSERT INTO logs_solicitacoes (solicitacao_id, acao, detalhes, ip_address, data_acao) 
            VALUES (:solicitacao_id, 'criada', :detalhes, :ip, NOW())";
$stmt_log = $conn->prepare($sql_log);
$stmt_log->execute([
    ':solicitacao_id' => $solicitacao_id,
    ':detalhes' => "Solicitação de pagamento criada via área do aluno. Método: " . ($metodo ?: 'não informado'),
    ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
]);

// ==============================================
// NOTIFICAR SECRETARIA (opcional - pode ser implementado)
// ==============================================

// ==============================================
// RETORNAR RESPOSTA
// ==============================================
echo json_encode([
    'success' => true,
    'message' => 'Solicitação de pagamento enviada com sucesso!',
    'codigo' => $codigo_solicitacao,
    'solicitacao_id' => $solicitacao_id,
    'email_enviado' => $email_enviado,
    'email_erro' => $email_erro
]);
?>