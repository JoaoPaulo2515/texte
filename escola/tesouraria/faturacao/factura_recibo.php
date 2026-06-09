<?php
// escola/tesouraria/faturacao/factura_recibo.php - Emissão de Factura/Recibo

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$usuario_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_tipo = $_SESSION['usuario_tipo'] ?? 'admin';
$papel = $_SESSION['papel'] ?? 'admin';

// Verificar permissões
$is_admin = ($usuario_tipo == 'super_admin' || $usuario_tipo == 'admin_escola' || $usuario_tipo == 'diretor' || $papel == 'admin');
$is_financeiro = ($papel == 'financeiro' || $is_admin);

if (!$is_financeiro && !$is_admin) {
    header('Location: ../../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR EMISSÃO
// ============================================
$success = '';
$error = '';
$documento_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'emitir') {
        $tipo_documento = $_POST['tipo_documento'];
        $aluno_id = (int)$_POST['aluno_id'];
        $itens = json_decode($_POST['itens'], true);
        $subtotal = floatval(str_replace(',', '.', str_replace('.', '', $_POST['subtotal'] ?? '0')));
        $desconto = floatval(str_replace(',', '.', str_replace('.', '', $_POST['desconto'] ?? '0')));
        $total = floatval(str_replace(',', '.', str_replace('.', '', $_POST['total'] ?? '0')));
        $forma_pagamento = $_POST['forma_pagamento'];
        $numero_referencia = trim($_POST['numero_referencia'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        if ($aluno_id <= 0) {
            $error = "Selecione um aluno.";
        } elseif (empty($itens)) {
            $error = "Adicione pelo menos um item.";
        } elseif ($total <= 0) {
            $error = "Valor total inválido.";
        } else {
            // Buscar dados do aluno
            $sql_aluno = "SELECT nome, matricula, endereco, nif as aluno_nif FROM estudantes WHERE id = :id AND escola_id = :escola_id";
            $stmt_aluno = $conn->prepare($sql_aluno);
            $stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
            $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
            
            if (!$aluno) {
                $error = "Aluno não encontrado.";
            } else {
                // Buscar dados da escola
                $sql_escola = "SELECT nome, endereco, telefone, email, nif, capital_social FROM escolas WHERE id = :id";
                $stmt_escola = $conn->prepare($sql_escola);
                $stmt_escola->execute([':id' => $escola_id]);
                $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
                
                // Gerar número do documento
                $ano = date('Y');
                $sql_num = "SELECT COUNT(*) as total FROM facturas WHERE escola_id = :escola_id AND YEAR(created_at) = :ano";
                $stmt_num = $conn->prepare($sql_num);
                $stmt_num->execute([':escola_id' => $escola_id, ':ano' => $ano]);
                $total_docs = $stmt_num->fetch(PDO::FETCH_ASSOC)['total'];
                $numero_doc = str_pad($total_docs + 1, 5, '0', STR_PAD_LEFT);
                
                if ($tipo_documento == 'factura') {
                    $numero_documento = "FT " . $numero_doc . "/" . $ano;
                    $titulo = "FACTURA";
                } else {
                    $numero_documento = "FR " . $numero_doc . "/" . $ano;
                    $titulo = "FACTURA RECIBO";
                }
                
                // Preparar dados do documento
                $documento_data = [
                    'numero' => $numero_documento,
                    'titulo' => $titulo,
                    'data_emissao' => date('d/m/Y H:i:s'),
                    'aluno_nome' => $aluno['nome'],
                    'aluno_matricula' => $aluno['matricula'],
                    'aluno_endereco' => $aluno['endereco'] ?? '',
                    'aluno_nif' => $aluno['aluno_nif'] ?? '999999999',
                    'itens' => $itens,
                    'subtotal' => $subtotal,
                    'desconto' => $desconto,
                    'total' => $total,
                    'forma_pagamento' => $forma_pagamento,
                    'numero_referencia' => $numero_referencia,
                    'observacoes' => $observacoes,
                    'escola_nome' => $escola['nome'] ?? 'SIGE Angola',
                    'escola_endereco' => $escola['endereco'] ?? '',
                    'escola_telefone' => $escola['telefone'] ?? '',
                    'escola_email' => $escola['email'] ?? '',
                    'escola_nif' => $escola['nif'] ?? '5000000000',
                    'escola_capital' => $escola['capital_social'] ?? '500.000,00 Kz',
                    'usuario' => $usuario_nome
                ];
                
                // Salvar no banco
                $sql_insert = "INSERT INTO facturas (escola_id, numero_factura, tipo, aluno_id, aluno_nome, aluno_nif, subtotal, desconto, total, forma_pagamento, numero_referencia, observacoes, itens, usuario_id, created_at) 
                               VALUES (:escola_id, :numero, :tipo, :aluno_id, :aluno_nome, :aluno_nif, :subtotal, :desconto, :total, :forma_pagamento, :numero_referencia, :observacoes, :itens, :usuario_id, NOW())";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->execute([
                    ':escola_id' => $escola_id,
                    ':numero' => $numero_documento,
                    ':tipo' => $tipo_documento,
                    ':aluno_id' => $aluno_id,
                    ':aluno_nome' => $aluno['nome'],
                    ':aluno_nif' => $aluno['aluno_nif'] ?? '999999999',
                    ':subtotal' => $subtotal,
                    ':desconto' => $desconto,
                    ':total' => $total,
                    ':forma_pagamento' => $forma_pagamento,
                    ':numero_referencia' => $numero_referencia,
                    ':observacoes' => $observacoes,
                    ':itens' => json_encode($itens),
                    ':usuario_id' => $usuario_id
                ]);
                
                $success = "Documento emitido com sucesso! Nº: $numero_documento";
            }
        }
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar alunos
$sql_alunos = "SELECT id, nome, matricula FROM estudantes WHERE escola_id = :escola_id AND status = 'ativo' ORDER BY nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar tipos de pagamento
$sql_tipos = "SELECT id, nome, icone FROM tipos_pagamento WHERE ativo = 1 ORDER BY ordem ASC";
$stmt_tipos = $conn->prepare($sql_tipos);
$stmt_tipos->execute();
$tipos_pagamento = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoedaFactura($valor) {
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getFormaPagamentoTexto($forma) {
    switch ($forma) {
        case 'dinheiro': return 'DINHEIRO';
        case 'transferencia': return 'TRANSFERÊNCIA BANCÁRIA';
        case 'deposito': return 'DEPÓSITO';
        case 'cheque': return 'CHEQUE';
        case 'multicaixa': return 'MULTICAIXA';
        default: return strtoupper($forma);
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Factura/Recibo | Faturação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .menu-toggle { display: block; } }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .factura-preview {
            background: white;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 10pt;
        }
        
        .factura-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .factura-title {
            font-size: 16pt;
            font-weight: bold;
            text-align: center;
            margin: 10px 0;
        }
        
        .factura-linha {
            display: flex;
            margin-bottom: 5px;
        }
        
        .factura-label {
            width: 130px;
            font-weight: bold;
        }
        
        .factura-valor {
            flex: 1;
        }
        
        .factura-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        
        .factura-table th,
        .factura-table td {
            border-bottom: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        
        .factura-table th {
            background: #f0f0f0;
        }
        
        .factura-table td:last-child,
        .factura-table th:last-child {
            text-align: right;
        }
        
        .factura-total {
            text-align: right;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 2px solid #000;
        }
        
        .factura-assinatura {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
        }
        
        .factura-assinatura-item {
            text-align: center;
            width: 45%;
        }
        
        .factura-linha-assinatura {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 5px;
        }
        
        .factura-rodape {
            text-align: center;
            font-size: 8pt;
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        
        .item-row {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 10px;
        }
        
        .print-only { display: none; }
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: none; }
            .factura-preview { padding: 0; border: none; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include '../menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice"></i> Emissão de Factura/Recibo</h2>
                <p class="text-muted">Emissão de documentos fiscais para alunos</p>
            </div>
            <div class="no-print">
                <a href="../index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <!-- Formulário -->
            <div class="col-md-6">
                <div class="card no-print">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-edit"></i> Dados do Documento</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formDocumento">
                            <input type="hidden" name="action" value="emitir">
                            <input type="hidden" name="itens" id="itens_json">
                            <input type="hidden" name="subtotal" id="subtotal">
                            <input type="hidden" name="total" id="total">
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Tipo de Documento</label>
                                    <select name="tipo_documento" id="tipo_documento" class="form-select" required>
                                        <option value="factura">📄 FACTURA</option>
                                        <option value="factura_recibo">📄 FACTURA RECIBO</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Aluno <span class="text-danger">*</span></label>
                                    <select name="aluno_id" id="aluno_id" class="form-select" required>
                                        <option value="">Selecione um aluno</option>
                                        <?php foreach ($alunos as $aluno): ?>
                                        <option value="<?php echo $aluno['id']; ?>" data-nome="<?php echo htmlspecialchars($aluno['nome']); ?>" data-matricula="<?php echo $aluno['matricula']; ?>">
                                            <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Itens da Factura</label>
                                <div id="itens_lista"></div>
                                <button type="button" class="btn btn-sm btn-secondary mt-2" onclick="adicionarItem()">
                                    <i class="fas fa-plus"></i> Adicionar Item
                                </button>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Forma de Pagamento</label>
                                    <select name="forma_pagamento" class="form-select">
                                        <option value="dinheiro">💵 Dinheiro</option>
                                        <option value="transferencia">🏦 Transferência Bancária</option>
                                        <option value="deposito">💰 Depósito</option>
                                        <option value="cheque">📄 Cheque</option>
                                        <option value="multicaixa">💳 Multicaixa</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Nº de Referência</label>
                                    <input type="text" name="numero_referencia" class="form-control" placeholder="Nº do comprovativo">
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-file-invoice"></i> Emitir Documento
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Pré-visualização -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-eye"></i> Pré-visualização</h5>
                    </div>
                    <div class="card-body">
                        <div id="previewFactura" class="factura-preview">
                            <div class="factura-header">
                                <strong id="preview_empresa">SIGE ANGOLA</strong><br>
                                <small id="preview_empresa_dados">Sistema Integrado de Gestão Escolar</small>
                            </div>
                            
                            <div class="factura-title">
                                <strong id="preview_titulo">FACTURA</strong>
                            </div>
                            
                            <div class="factura-linha">
                                <div class="factura-label">Nº do Documento:</div>
                                <div class="factura-valor"><strong id="preview_numero">---/----</strong></div>
                            </div>
                            <div class="factura-linha">
                                <div class="factura-label">Data de Emissão:</div>
                                <div class="factura-valor" id="preview_data"><?php echo date('d/m/Y H:i:s'); ?></div>
                            </div>
                            
                            <hr>
                            
                            <div class="factura-linha">
                                <div class="factura-label">Cliente:</div>
                                <div class="factura-valor"><strong id="preview_cliente">---</strong></div>
                            </div>
                            <div class="factura-linha">
                                <div class="factura-label">NIF:</div>
                                <div class="factura-valor" id="preview_nif">---</div>
                            </div>
                            
                            <hr>
                            
                            <table class="factura-table" id="preview_tabela">
                                <thead>
                                    <tr><th>Descrição</th><th>Qtd</th><th>Preço Unit.</th><th>Total</th></tr>
                                </thead>
                                <tbody>
                                    <tr><td colspan="4" class="text-center">Nenhum item adicionado</td></tr>
                                </tbody>
                            </table>
                            
                            <div class="factura-total">
                                <div class="factura-linha">
                                    <div class="factura-label">Subtotal:</div>
                                    <div class="factura-valor" id="preview_subtotal">0,00 Kz</div>
                                </div>
                                <div class="factura-linha">
                                    <div class="factura-label">Desconto:</div>
                                    <div class="factura-valor" id="preview_desconto">0,00 Kz</div>
                                </div>
                                <div class="factura-linha">
                                    <div class="factura-label"><strong>TOTAL:</strong></div>
                                    <div class="factura-valor"><strong id="preview_total">0,00 Kz</strong></div>
                                </div>
                            </div>
                            
                            <div class="factura-linha">
                                <div class="factura-label">Forma de Pagamento:</div>
                                <div class="factura-valor" id="preview_forma">---</div>
                            </div>
                            
                            <div class="factura-linha" id="row_referencia" style="display:none;">
                                <div class="factura-label">Nº Referência:</div>
                                <div class="factura-valor" id="preview_referencia">---</div>
                            </div>
                            
                            <div class="factura-assinatura">
                                <div class="factura-assinatura-item">
                                    <div class="factura-linha-assinatura"></div>
                                    <div>Assinatura do Cliente</div>
                                </div>
                                <div class="factura-assinatura-item">
                                    <div class="factura-linha-assinatura"></div>
                                    <div>Assinatura do Emitente</div>
                                </div>
                            </div>
                            
                            <div class="factura-rodape">
                                Documento emitido por computador - Válido para todos os efeitos legais<br>
                                <span id="preview_usuario"><?php echo $usuario_nome; ?></span> - <?php echo date('d/m/Y H:i:s'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Adicionar Item -->
    <div class="modal fade no-print" id="modalItem" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-box"></i> Adicionar Item</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <input type="text" id="item_descricao" class="form-control" placeholder="Ex: Mensalidade de Fevereiro">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Quantidade</label>
                            <input type="number" id="item_qtd" class="form-control" value="1" min="1">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Preço Unitário (Kz)</label>
                            <input type="text" id="item_preco" class="form-control valor" placeholder="0,00">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="adicionarItemLista()">Adicionar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itens = [];
        
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('.valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
        });
        
        function adicionarItem() {
            $('#item_descricao').val('');
            $('#item_qtd').val('1');
            $('#item_preco').val('');
            new bootstrap.Modal(document.getElementById('modalItem')).show();
        }
        
        function adicionarItemLista() {
            let descricao = $('#item_descricao').val();
            let qtd = parseInt($('#item_qtd').val());
            let preco = parseFloat($('#item_preco').val().replace(/\./g, '').replace(',', '.'));
            
            if (!descricao) {
                alert('Informe a descrição do item');
                return;
            }
            if (isNaN(preco) || preco <= 0) {
                alert('Informe um preço válido');
                return;
            }
            
            itens.push({
                descricao: descricao,
                quantidade: qtd,
                preco: preco,
                total: qtd * preco
            });
            
            atualizarPreview();
            bootstrap.Modal.getInstance(document.getElementById('modalItem')).hide();
        }
        
        function removerItem(index) {
            itens.splice(index, 1);
            atualizarPreview();
        }
        
        function atualizarPreview() {
            // Atualizar dados do aluno
            let alunoId = $('#aluno_id').val();
            let alunoNome = $('#aluno_id option:selected').data('nome') || '---';
            $('#preview_cliente').text(alunoNome);
            
            // Atualizar tipo de documento
            let tipo = $('#tipo_documento').val();
            if (tipo === 'factura') {
                $('#preview_titulo').text('FACTURA');
            } else {
                $('#preview_titulo').text('FACTURA RECIBO');
            }
            
            // Atualizar tabela de itens
            let html = '';
            let subtotal = 0;
            
            itens.forEach((item, index) => {
                let total = item.quantidade * item.preco;
                subtotal += total;
                html += `
                    <tr>
                        <td>${item.descricao}</td>
                        <td class="text-center">${item.quantidade}</td>
                        <td class="text-end">${formatarMoeda(item.preco)}</td>
                        <td class="text-end">${formatarMoeda(total)}</td>
                    </tr>
                `;
            });
            
            if (itens.length === 0) {
                html = '<tr><td colspan="4" class="text-center">Nenhum item adicionado</td></tr>';
            }
            
            $('#preview_tabela tbody').html(html);
            $('#preview_subtotal').text(formatarMoeda(subtotal));
            $('#preview_total').text(formatarMoeda(subtotal));
            
            // Atualizar campos hidden
            $('#subtotal').val(subtotal.toFixed(2));
            $('#total').val(subtotal.toFixed(2));
            $('#itens_json').val(JSON.stringify(itens));
            
            // Atualizar forma de pagamento
            let forma = $('select[name="forma_pagamento"] option:selected').text();
            $('#preview_forma').text(forma);
            
            // Referência
            let ref = $('input[name="numero_referencia"]').val();
            if (ref) {
                $('#row_referencia').show();
                $('#preview_referencia').text(ref);
            } else {
                $('#row_referencia').hide();
            }
        }
        
        function formatarMoeda(valor) {
            return valor.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + ' Kz';
        }
        
        $('#aluno_id, #tipo_documento, select[name="forma_pagamento"], input[name="numero_referencia"]').on('change input', function() {
            atualizarPreview();
        });
        
        // Inicializar
        atualizarPreview();
    </script>
</body>
</html>