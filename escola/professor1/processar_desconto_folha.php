<?php
// escola/professor/processar_desconto_folha.php - Processar descontos em folha

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR ANO LETIVO ATIVO
// ============================================
$sql_ano_letivo = "SELECT id, ano FROM ano_letivo WHERE ativo = 1 LIMIT 1";
$stmt_ano_letivo = $conn->query($sql_ano_letivo);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);
$ano_letivo_id = $ano_letivo['id'] ?? 1;

$mes_atual = (int)date('m');
$ano_atual = (int)date('Y');

// ============================================
// BUSCAR DÍVIDAS VENCIDAS QUE AINDA NÃO FORAM PROCESSADAS
// ============================================
$sql_dividas = "
    SELECT * FROM dividas 
    WHERE professor_id = :professor_id 
    AND status != 'pago'
    AND (desconto_folha = 1 OR desconto_folha IS NULL)
    AND (processado_folha = 0 OR processado_folha IS NULL)
    AND data_vencimento < CURDATE()
    AND (valor_original - COALESCE(valor_pago, 0)) > 0
";

$stmt_dividas = $conn->prepare($sql_dividas);
$stmt_dividas->execute([':professor_id' => $professor_id]);
$dividas_vencidas = $stmt_dividas->fetchAll(PDO::FETCH_ASSOC);

$total_processado = 0;
$total_valor = 0;

foreach ($dividas_vencidas as $divida) {
    $valor_restante = $divida['valor_original'] - ($divida['valor_pago'] ?? 0);
    
    // Buscar ou criar processamento da folha para o mês atual
    $sql_processamento = "
        SELECT id FROM folha_processamento_funcionarios 
        WHERE funcionario_id = :funcionario_id 
        AND mes_competencia = :mes 
        AND ano_competencia = :ano
        LIMIT 1
    ";
    
    // Buscar funcionario_id do professor
    $sql_func = "SELECT id FROM funcionarios WHERE usuario_id = (SELECT usuario_id FROM professores WHERE id = :professor_id)";
    $stmt_func = $conn->prepare($sql_func);
    $stmt_func->execute([':professor_id' => $professor_id]);
    $funcionario = $stmt_func->fetch(PDO::FETCH_ASSOC);
    $funcionario_id = $funcionario['id'] ?? null;
    
    if ($funcionario_id) {
        $stmt_proc = $conn->prepare($sql_processamento);
        $stmt_proc->execute([
            ':funcionario_id' => $funcionario_id,
            ':mes' => $mes_atual,
            ':ano' => $ano_atual
        ]);
        $processamento = $stmt_proc->fetch(PDO::FETCH_ASSOC);
        
        if ($processamento) {
            $processamento_id = $processamento['id'];
            
            // Atualizar o processamento com o desconto
            $sql_update = "
                UPDATE folha_processamento_funcionarios 
                SET 
                    desconto_emprestimo = COALESCE(desconto_emprestimo, 0) + :valor,
                    total_descontos = COALESCE(total_descontos, 0) + :valor,
                    salario_liquido = COALESCE(salario_liquido, 0) - :valor,
                    updated_at = NOW()
                WHERE id = :processamento_id
            ";
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->execute([
                ':valor' => $valor_restante,
                ':processamento_id' => $processamento_id
            ]);
        } else {
            // Criar novo processamento
            $sql_insert = "
                INSERT INTO folha_processamento_funcionarios (
                    funcionario_id, mes_competencia, ano_competencia, 
                    desconto_emprestimo, total_descontos, salario_liquido,
                    status, created_at
                ) VALUES (
                    :funcionario_id, :mes, :ano,
                    :valor, :valor, -:valor,
                    'processado', NOW()
                )
            ";
            $stmt_insert = $conn->prepare($sql_insert);
            $stmt_insert->execute([
                ':funcionario_id' => $funcionario_id,
                ':mes' => $mes_atual,
                ':ano' => $ano_atual,
                ':valor' => $valor_restante
            ]);
            $processamento_id = $conn->lastInsertId();
        }
        
        // Atualizar a dívida como processada
        $sql_update_divida = "
            UPDATE dividas SET 
                processado_folha = 1,
                mes_processamento = :mes,
                ano_processamento = :ano,
                processamento_id = :processamento_id,
                status = 'processado_folha'
            WHERE id = :divida_id
        ";
        $stmt_update_divida = $conn->prepare($sql_update_divida);
        $stmt_update_divida->execute([
            ':mes' => $mes_atual,
            ':ano' => $ano_atual,
            ':processamento_id' => $processamento_id,
            ':divida_id' => $divida['id']
        ]);
        
        $total_processado++;
        $total_valor += $valor_restante;
    }
}

echo json_encode([
    'success' => true,
    'message' => "Processado $total_processado dívidas no valor total de KZ " . number_format($total_valor, 2, ',', '.'),
    'total_processado' => $total_processado,
    'total_valor' => $total_valor
]);
?>