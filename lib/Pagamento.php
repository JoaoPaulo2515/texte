<?php
// lib/Pagamento.php - Classe de gerenciamento de pagamentos
/**
 * Classe Pagamento para integração com meios de pagamento
 * @package SIGE Angola
 * @version 1.0
 */

class Pagamento
{
    private $db;
    private $apiKey;
    private $apiUrl;
    
    // Meios de pagamento disponíveis em Angola
    const METODOS = [
        'dinheiro' => 'Dinheiro',
        'transferencia' => 'Transferência Bancária',
        'deposito' => 'Depósito Bancário',
        'multicaixa' => 'Multicaixa Express',
        'cartao' => 'Cartão de Crédito/Débito',
        'paypal' => 'PayPal'
    ];
    
    // Bancos em Angola
    const BANCOS = [
        'BFA' => 'Banco de Fomento Angola',
        'BAI' => 'Banco Angolano de Investimentos',
        'BIC' => 'Banco BIC',
        'KEVE' => 'Banco Keve',
        'SOL' => 'Banco Sol',
        'ECONOMICO' => 'Banco Económico',
        'MILLENNIUM' => 'Banco Millennium Atlântico',
        'YETU' => 'Banco Yetu'
    ];
    
    /**
     * Construtor
     */
    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->loadConfig();
    }
    
    /**
     * Carrega configurações de API
     */
    private function loadConfig()
    {
        // Configurações para Multicaixa Express (exemplo)
        $this->apiKey = defined('MULTICAIXA_API_KEY') ? MULTICAIXA_API_KEY : '';
        $this->apiUrl = defined('MULTICAIXA_API_URL') ? MULTICAIXA_API_URL : 'https://api.multicaixa.ao/v1';
    }
    
    /**
     * Registra um novo pagamento
     * @param array $data Dados do pagamento
     * @return int|bool ID do pagamento ou false
     */
    public function registrar($data)
    {
        $conn = $this->db->getConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO pagamentos (
                escola_id, assinatura_id, valor, referente,
                data_vencimento, metodo_pagamento, status, observacoes, created_at
            ) VALUES (
                :escola_id, :assinatura_id, :valor, :referente,
                :data_vencimento, :metodo_pagamento, 'pendente', :observacoes, NOW()
            )
        ");
        
        $stmt->execute([
            ':escola_id' => $data['escola_id'],
            ':assinatura_id' => $data['assinatura_id'],
            ':valor' => $data['valor'],
            ':referente' => $data['referente'],
            ':data_vencimento' => $data['data_vencimento'],
            ':metodo_pagamento' => $data['metodo_pagamento'],
            ':observacoes' => $data['observacoes'] ?? null
        ]);
        
        return $conn->lastInsertId();
    }
    
    /**
     * Confirma pagamento
     * @param int $pagamentoId
     * @param string $comprovante
     * @param string $codigoTransacao
     * @return bool
     */
    public function confirmar($pagamentoId, $comprovante = null, $codigoTransacao = null)
    {
        $conn = $this->db->getConnection();
        
        $conn->beginTransaction();
        
        $stmt = $conn->prepare("
            UPDATE pagamentos SET
                status = 'pago',
                data_pagamento = CURDATE(),
                comprovante = :comprovante,
                codigo_transacao = :codigo_transacao,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':id' => $pagamentoId,
            ':comprovante' => $comprovante,
            ':codigo_transacao' => $codigoTransacao
        ]);
        
        // Atualizar status da assinatura se necessário
        $stmt = $conn->prepare("
            UPDATE assinaturas a
            SET a.status = 'ativa'
            WHERE a.id = (SELECT assinatura_id FROM pagamentos WHERE id = :id)
        ");
        $stmt->execute([':id' => $pagamentoId]);
        
        $conn->commit();
        
        return true;
    }
    
    /**
     * Gera boleto bancário (integração com banco angolano)
     * @param array $dados
     * @return string URL do boleto
     */
    public function gerarBoleto($dados)
    {
        // Exemplo de integração com banco angolano (BFA, BAI, etc.)
        // Esta é uma implementação genérica - adaptar conforme API do banco
        
        $beneficiario = [
            'nome' => 'SIGE Angola',
            'cnpj' => '123456789',
            'banco' => $dados['banco'] ?? 'BFA',
            'agencia' => '0001',
            'conta' => '123456-7'
        ];
        
        $pagador = [
            'nome' => $dados['escola_nome'],
            'documento' => $dados['escola_nuit'] ?? '',
            'endereco' => $dados['escola_endereco'] ?? ''
        ];
        
        $boleto = [
            'numero' => $this->gerarNumeroBoleto(),
            'valor' => $dados['valor'],
            'data_vencimento' => $dados['data_vencimento'],
            'beneficiario' => $beneficiario,
            'pagador' => $pagador,
            'instrucoes' => 'Após o pagamento, o sistema será atualizado automaticamente em até 24h'
        ];
        
        // Salvar boleto no banco
        $conn = $this->db->getConnection();
        $stmt = $conn->prepare("
            INSERT INTO boletos (pagamento_id, numero, codigo_barras, url, created_at)
            VALUES (:pagamento_id, :numero, :codigo_barras, :url, NOW())
        ");
        
        $codigoBarras = $this->gerarCodigoBarras($boleto);
        $urlBoleto = "https://api.sige.ao/boletos/" . $boleto['numero'] . ".pdf";
        
        $stmt->execute([
            ':pagamento_id' => $dados['pagamento_id'],
            ':numero' => $boleto['numero'],
            ':codigo_barras' => $codigoBarras,
            ':url' => $urlBoleto
        ]);
        
        return $urlBoleto;
    }
    
    /**
     * Gera número de boleto
     * @return string
     */
    private function gerarNumeroBoleto()
    {
        return date('Ymd') . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * Gera código de barras
     * @param array $boleto
     * @return string
     */
    private function gerarCodigoBarras($boleto)
    {
        // Implementação simplificada
        return '1234567890123456789012345678901234567890';
    }
    
    /**
     * Verifica status de pagamento Multicaixa Express
     * @param string $referencia
     * @return array
     */
    public function verificarMulticaixa($referencia)
    {
        if (empty($this->apiKey)) {
            return ['status' => 'error', 'message' => 'API não configurada'];
        }
        
        // Integração com API Multicaixa Express
        $ch = curl_init($this->apiUrl . '/payment/' . $referencia);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $data = json_decode($response, true);
            return [
                'status' => $data['status'] == 'PAID' ? 'pago' : 'pendente',
                'data' => $data
            ];
        }
        
        return ['status' => 'error', 'message' => 'Erro na consulta'];
    }
    
    /**
     * Gera QR Code para pagamento Multicaixa
     * @param float $valor
     * @param string $descricao
     * @return string QR Code em base64
     */
    public function gerarQrCodeMulticaixa($valor, $descricao)
    {
        // Dados para pagamento Multicaixa Express
        $dados = [
            'valor' => $valor,
            'descricao' => $descricao,
            'referencia' => 'SIGE' . time(),
            'beneficiario' => 'SIGE Angola'
        ];
        
        // Gerar QR Code (usando API ou biblioteca)
        $qrCodeData = base64_encode(json_encode($dados));
        
        return $qrCodeData;
    }
    
    /**
     * Gera relatório de pagamentos
     * @param int $escolaId
     * @param string $dataInicio
     * @param string $dataFim
     * @return array
     */
    public function relatorioPagamentos($escolaId = null, $dataInicio = null, $dataFim = null)
    {
        $conn = $this->db->getConnection();
        
        $sql = "
            SELECT p.*, e.nome as escola_nome, e.subdominio,
                   a.tipo_cobranca, pl.nome as plano_nome
            FROM pagamentos p
            JOIN escolas e ON e.id = p.escola_id
            JOIN assinaturas a ON a.id = p.assinatura_id
            JOIN planos pl ON pl.id = a.plano_id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($escolaId) {
            $sql .= " AND p.escola_id = :escola_id";
            $params[':escola_id'] = $escolaId;
        }
        
        if ($dataInicio) {
            $sql .= " AND p.created_at >= :data_inicio";
            $params[':data_inicio'] = $dataInicio;
        }
        
        if ($dataFim) {
            $sql .= " AND p.created_at <= :data_fim";
            $params[':data_fim'] = $dataFim;
        }
        
        $sql .= " ORDER BY p.created_at DESC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calcula resumo financeiro
     * @param int $escolaId
     * @param int $ano
     * @return array
     */
    public function resumoFinanceiro($escolaId = null, $ano = null)
    {
        $conn = $this->db->getConnection();
        $ano = $ano ?? date('Y');
        
        $sql = "
            SELECT 
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as total_recebido,
                SUM(CASE WHEN status = 'pendente' THEN valor ELSE 0 END) as total_pendente,
                COUNT(CASE WHEN status = 'pago' THEN 1 END) as qtd_pagos,
                COUNT(CASE WHEN status = 'pendente' THEN 1 END) as qtd_pendentes,
                MONTH(data_pagamento) as mes
            FROM pagamentos
            WHERE YEAR(created_at) = :ano
        ";
        
        $params = [':ano' => $ano];
        
        if ($escolaId) {
            $sql .= " AND escola_id = :escola_id";
            $params[':escola_id'] = $escolaId;
        }
        
        $sql .= " GROUP BY MONTH(data_pagamento) ORDER BY mes ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}