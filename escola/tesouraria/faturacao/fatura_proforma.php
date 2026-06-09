<?php
// escola/tesouraria/faturacao/fatura_proforma.php - Gestão de Faturas Pró-Forma

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: ../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// BUSCAR ESTUDANTES
// ============================================
$sql_estudantes = "SELECT e.id, e.nome, e.matricula, e.email, e.telefone, t.nome as turma_nome 
                   FROM estudantes e 
                   LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
                   LEFT JOIN turmas t ON t.id = m.turma_id
                   WHERE e.escola_id = :escola_id 
                   ORDER BY e.nome ASC";
$stmt_estudantes = $conn->prepare($sql_estudantes);
$stmt_estudantes->execute([':escola_id' => $escola_id]);
$estudantes = $stmt_estudantes->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// PROCESSAR NOVA FATURA PRÓ-FORMA
// ============================================
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['emitir_fatura'])) {
    $estudante_id = (int)$_POST['estudante_id'];
    $data_emissao = $_POST['data_emissao'] ?? date('Y-m-d');
    $data_validade = $_POST['data_validade'] ?? date('Y-m-d', strtotime('+30 days'));
    $observacoes = trim($_POST['observacoes'] ?? '');
    $produtos = $_POST['produtos'] ?? [];
    $desconto = floatval(str_replace(',', '.', str_replace('.', '', $_POST['desconto'] ?? '0')));
    
    if ($estudante_id <= 0) {
        $error = "Selecione um estudante.";
    } elseif (empty($produtos) || count($produtos) == 0) {
        $error = "Adicione pelo menos um produto/serviço.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Gerar número da fatura
            $numero_fatura = 'PF-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Calcular totais
            $subtotal = 0;
            foreach ($produtos as $produto) {
                $quantidade = floatval(str_replace(',', '.', $produto['quantidade'] ?? 1));
                $valor_unitario = floatval(str_replace(',', '.', $produto['valor_unitario'] ?? 0));
                $subtotal += $quantidade * $valor_unitario;
            }
            
            $valor_iva = $subtotal * 0.14; // 14% IVA
            $total = $subtotal + $valor_iva - $desconto;
            
            // Inserir fatura com estudante_id
            $sql = "INSERT INTO faturas_proforma 
                    (escola_id, numero_fatura, estudante_id, data_emissao, data_validade, 
                     subtotal, iva, desconto, total, observacoes, status, usuario_id, created_at) 
                    VALUES 
                    (:escola_id, :numero_fatura, :estudante_id, :data_emissao, :data_validade, 
                     :subtotal, :iva, :desconto, :total, :observacoes, 'pendente', :usuario_id, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':escola_id' => $escola_id,
                ':numero_fatura' => $numero_fatura,
                ':estudante_id' => $estudante_id,
                ':data_emissao' => $data_emissao,
                ':data_validade' => $data_validade,
                ':subtotal' => $subtotal,
                ':iva' => $valor_iva,
                ':desconto' => $desconto,
                ':total' => $total,
                ':observacoes' => $observacoes,
                ':usuario_id' => $usuario_id
            ]);
            $fatura_id = $conn->lastInsertId();
            
            // Inserir itens da fatura
            foreach ($produtos as $produto) {
                $descricao = trim($produto['descricao'] ?? '');
                $quantidade = floatval(str_replace(',', '.', $produto['quantidade'] ?? 1));
                $valor_unitario = floatval(str_replace(',', '.', $produto['valor_unitario'] ?? 0));
                $total_item = $quantidade * $valor_unitario;
                
                $sql_item = "INSERT INTO fatura_proforma_itens 
                            (fatura_id, descricao, quantidade, valor_unitario, total) 
                            VALUES (:fatura_id, :descricao, :quantidade, :valor_unitario, :total)";
                $stmt_item = $conn->prepare($sql_item);
                $stmt_item->execute([
                    ':fatura_id' => $fatura_id,
                    ':descricao' => $descricao,
                    ':quantidade' => $quantidade,
                    ':valor_unitario' => $valor_unitario,
                    ':total' => $total_item
                ]);
            }
            
            $conn->commit();
            $success = "Fatura Pró-Forma #$numero_fatura emitida com sucesso!";
            
            // Redirecionar para visualizar
            header("Location: visualizar_fatura.php?id=$fatura_id&tipo=proforma");
            exit;
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao emitir fatura: " . $e->getMessage();
        }
    }
}

// ============================================
// BUSCAR PRODUTOS/SERVIÇOS
// ============================================
$sql_produtos = "SELECT id, nome, descricao, preco FROM produtos WHERE escola_id = :escola_id AND ativo = 1 ORDER BY nome ASC";
$stmt_produtos = $conn->prepare($sql_produtos);
$stmt_produtos->execute([':escola_id' => $escola_id]);
$produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

// Buscar últimas faturas pró-forma com dados do estudante
$sql_faturas = "SELECT fp.*, e.nome as estudante_nome, e.matricula 
                FROM faturas_proforma fp
                JOIN estudantes e ON e.id = fp.estudante_id
                WHERE fp.escola_id = :escola_id 
                ORDER BY fp.created_at DESC 
                LIMIT 10";
$stmt_faturas = $conn->prepare($sql_faturas);
$stmt_faturas->execute([':escola_id' => $escola_id]);
$ultimas_faturas = $stmt_faturas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

function getStatusFaturaBadge($status) {
    switch ($status) {
        case 'pendente':
            return '<span class="badge bg-warning text-dark"><i class="fas fa-clock"></i> Pendente</span>';
        case 'aprovado':
            return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Aprovado</span>';
        case 'rejeitado':
            return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Rejeitado</span>';
        case 'convertida':
            return '<span class="badge bg-info"><i class="fas fa-file-invoice"></i> Convertida</span>';
        default:
            return '<span class="badge bg-secondary">' . $status . '</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fatura Pró-Forma | Tesouraria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content-tesouraria {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content-tesouraria { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .item-row { background: #f8f9fa; margin-bottom: 10px; padding: 10px; border-radius: 8px; }
        .btn-remover-item { color: #dc3545; cursor: pointer; }
        .btn-remover-item:hover { color: #bd2130; }
        
        .total-value { font-size: 1.2em; font-weight: bold; color: #006B3E; }
        .fatura-card { transition: all 0.3s ease; }
        .fatura-card:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .estudante-info { font-size: 0.85rem; color: #6c757d; }
        
        .valor-unitario-disabled {
            background-color: #e9ecef;
            cursor: not-allowed;
        }
    </style>
</head>
<body>
    <?php include '../menu_tesouraria.php'; ?>
    
    <div class="main-content-tesouraria">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-invoice"></i> Fatura Pró-Forma</h2>
                <p class="text-muted">Emissão de faturas pró-forma para estudantes</p>
            </div>
            <div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalNovaFatura">
                    <i class="fas fa-plus"></i> Nova Fatura Pró-Forma
                </button>
                <a href="../index.php" class="btn-voltar ms-2">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Últimas Faturas -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Últimas Faturas Pró-Forma</h5>
            </div>
            <div class="card-body">
                <?php if (empty($ultimas_faturas)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle"></i> Nenhuma fatura pró-forma emitida ainda.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Nº Fatura</th>
                                    <th>Estudante</th>
                                    <th>Matrícula</th>
                                    <th>Data Emissão</th>
                                    <th>Validade</th>
                                    <th>Total</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ultimas_faturas as $fat): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($fat['numero_fatura']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($fat['estudante_nome']); ?></td>
                                    <td><?php echo htmlspecialchars($fat['matricula']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($fat['data_emissao'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($fat['data_validade'])); ?></td>
                                    <td class="text-success fw-bold"><?php echo formatarMoeda($fat['total']); ?></td>
                                    <td><?php echo getStatusFaturaBadge($fat['status']); ?></td>
                                    <td>
                                        <a href="visualizar_fatura.php?id=<?php echo $fat['id']; ?>&tipo=proforma" class="btn btn-sm btn-info" target="_blank">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="imprimir_fatura.php?id=<?php echo $fat['id']; ?>&tipo=proforma" class="btn btn-sm btn-secondary" target="_blank">
                                            <i class="fas fa-print"></i>
                                        </a>
                                     </row>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modal Nova Fatura Pró-Forma -->
    <div class="modal fade" id="modalNovaFatura" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-custom">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Emitir Nova Fatura Pró-Forma</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formFatura">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-user-graduate"></i> Dados do Estudante</h6>
                                <div class="mb-3">
                                    <label class="form-label">Estudante <span class="text-danger">*</span></label>
                                    <select name="estudante_id" id="estudante_id" class="form-select" required>
                                        <option value="">Selecione um estudante</option>
                                        <?php foreach ($estudantes as $est): ?>
                                        <option value="<?php echo $est['id']; ?>" 
                                                data-nome="<?php echo htmlspecialchars($est['nome']); ?>"
                                                data-matricula="<?php echo htmlspecialchars($est['matricula']); ?>"
                                                data-email="<?php echo htmlspecialchars($est['email']); ?>"
                                                data-telefone="<?php echo htmlspecialchars($est['telefone']); ?>">
                                            <?php echo htmlspecialchars($est['nome']); ?> (<?php echo $est['matricula']; ?> - <?php echo $est['turma_nome']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="infoEstudante" class="alert alert-info" style="display: none;">
                                    <strong><i class="fas fa-info-circle"></i> Informações do Estudante</strong><br>
                                    <span id="infoNome"></span><br>
                                    <span id="infoMatricula"></span><br>
                                    <span id="infoEmail"></span><br>
                                    <span id="infoTelefone"></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="mb-3"><i class="fas fa-calendar-alt"></i> Dados da Fatura</h6>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Data de Emissão</label>
                                        <input type="date" name="data_emissao" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Data de Validade</label>
                                        <input type="date" name="data_validade" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Observações</label>
                                    <textarea name="observacoes" class="form-control" rows="3" placeholder="Informações adicionais..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6 class="mb-3"><i class="fas fa-box"></i> Produtos/Serviços</h6>
                        <div id="listaProdutos">
                            <div class="item-row row" id="item-1">
                                <div class="col-md-5">
                                    <label class="form-label">Produto/Serviço <span class="text-danger">*</span></label>
                                    <select name="produtos[0][produto_id]" class="form-select select-produto" data-item="1" required>
                                        <option value="">Selecione um produto</option>
                                        <?php foreach ($produtos as $prod): ?>
                                        <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco']; ?>" data-nome="<?php echo htmlspecialchars($prod['nome']); ?>">
                                            <?php echo htmlspecialchars($prod['nome']); ?> - <?php echo formatarMoeda($prod['preco']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="produtos[0][descricao]" class="descricao-hidden" value="">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Quantidade</label>
                                    <input type="text" name="produtos[0][quantidade]" class="form-control quantidade" value="1" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Valor Unitário</label>
                                    <input type="text" name="produtos[0][valor_unitario]" class="form-control valor-unitario valor-unitario-disabled" value="0,00" readonly disabled required>
                                </div>
                                <div class="col-md-2">
                                                                     <label class="form-label">Total</label>
                                    <input type="text" class="form-control total-item" readonly style="background:#e9ecef;">
                                </div>
                                <div class="col-md-1 text-center">
                                    <label class="form-label">&nbsp;</label>
                                    <div><i class="fas fa-trash-alt btn-remover-item text-danger" style="font-size: 1.2em;"></i></div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnAdicionarItem">
                                <i class="fas fa-plus"></i> Adicionar Item
                            </button>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-8 offset-md-4">
                                <div class="row mb-2">
                                    <div class="col-md-6 text-end"><strong>Subtotal:</strong></div>
                                    <div class="col-md-6"><input type="text" id="subtotal" class="form-control form-control-sm text-end bg-light" readonly style="background:#e9ecef;"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-md-6 text-end"><strong>IVA (14%):</strong></div>
                                    <div class="col-md-6"><input type="text" id="iva" class="form-control form-control-sm text-end bg-light" readonly style="background:#e9ecef;"></div>
                                </div>
                                <div class="row mb-2">
                                    <div class="col-md-6 text-end"><strong>Desconto:</strong></div>
                                    <div class="col-md-6"><input type="text" name="desconto" id="desconto" class="form-control form-control-sm text-end" value="0,00"></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6 text-end"><strong>Total:</strong></div>
                                    <div class="col-md-6"><input type="text" id="total" class="form-control form-control-sm text-end bg-success text-white fw-bold" readonly style="background:#006B3E; color:white;"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Nota:</strong> A fatura pró-forma é um orçamento e não tem valor fiscal. Para emitir a fatura definitiva, converta este documento.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="emitir_fatura" class="btn btn-primary">Emitir Fatura Pró-Forma</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCounter = 1;
        
        // Mostrar informações do estudante ao selecionar
        $('#estudante_id').change(function() {
            let selected = $(this).find('option:selected');
            let nome = selected.data('nome');
            let matricula = selected.data('matricula');
            let email = selected.data('email');
            let telefone = selected.data('telefone');
            
            if (selected.val()) {
                $('#infoNome').html('<i class="fas fa-user"></i> <strong>Nome:</strong> ' + nome);
                $('#infoMatricula').html('<i class="fas fa-id-card"></i> <strong>Matrícula:</strong> ' + matricula);
                $('#infoEmail').html('<i class="fas fa-envelope"></i> <strong>Email:</strong> ' + (email || 'Não informado'));
                $('#infoTelefone').html('<i class="fas fa-phone"></i> <strong>Telefone:</strong> ' + (telefone || 'Não informado'));
                $('#infoEstudante').show();
            } else {
                $('#infoEstudante').hide();
            }
        });
        
        // Função para formatar valor como moeda
        function formatarMoedaInput(valor) {
            let v = valor.toString().replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        // Função para converter moeda para número
        function converterMoedaParaNumero(valor) {
            if (!valor) return 0;
            return parseFloat(valor.toString().replace(/\./g, '').replace(',', '.'));
        }
        
        // Função para carregar produto selecionado
        function carregarProduto(selectElement, itemId) {
            let selectedOption = $(selectElement).find('option:selected');
            let preco = selectedOption.data('preco');
            let nome = selectedOption.data('nome');
            
            if (preco && selectElement.val()) {
                // Atualizar campo valor unitário (desabilitado)
                $(`#item-${itemId} .valor-unitario`).val(formatarMoedaInput(preco.toString()));
                // Atualizar campo descrição hidden
                $(`#item-${itemId} .descricao-hidden`).val(nome);
                // Atualizar total do item
                atualizarTotalItem(itemId);
            } else {
                $(`#item-${itemId} .valor-unitario`).val('0,00');
                $(`#item-${itemId} .descricao-hidden`).val('');
                atualizarTotalItem(itemId);
            }
            
            // Recalcular todos os totais
            atualizarTotais();
        }
        
        // Calcular total de um item específico
        function atualizarTotalItem(itemId) {
            let quantidade = $(`#item-${itemId} .quantidade`).val();
            let valorUnitario = $(`#item-${itemId} .valor-unitario`).val();
            
            let qtd = parseFloat(quantidade.toString().replace(',', '.')) || 0;
            let valor = converterMoedaParaNumero(valorUnitario);
            let totalItem = qtd * valor;
            
            $(`#item-${itemId} .total-item`).val(formatarMoedaInput(totalItem.toFixed(2)));
        }
        
        // Atualizar todos os totais
        function atualizarTotais() {
            let subtotal = 0;
            
            $('.item-row').each(function() {
                let totalItem = converterMoedaParaNumero($(this).find('.total-item').val());
                subtotal += totalItem;
            });
            
            let iva = subtotal * 0.14;
            let desconto = converterMoedaParaNumero($('#desconto').val()) || 0;
            let total = subtotal + iva - desconto;
            
            $('#subtotal').val(formatarMoedaInput(subtotal.toFixed(2)));
            $('#iva').val(formatarMoedaInput(iva.toFixed(2)));
            $('#total').val(formatarMoedaInput(total.toFixed(2)));
        }
        
        // Adicionar novo item
        $('#btnAdicionarItem').click(function() {
            itemCounter++;
            let newItem = `
                <div class="item-row row" id="item-${itemCounter}">
                    <div class="col-md-5">
                        <label class="form-label">Produto/Serviço <span class="text-danger">*</span></label>
                        <select name="produtos[${itemCounter-1}][produto_id]" class="form-select select-produto" data-item="${itemCounter}" required>
                            <option value="">Selecione um produto</option>
                            <?php foreach ($produtos as $prod): ?>
                            <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco']; ?>" data-nome="<?php echo htmlspecialchars($prod['nome']); ?>">
                                <?php echo htmlspecialchars($prod['nome']); ?> - <?php echo formatarMoeda($prod['preco']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="produtos[${itemCounter-1}][descricao]" class="descricao-hidden" value="">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Quantidade</label>
                        <input type="text" name="produtos[${itemCounter-1}][quantidade]" class="form-control quantidade" value="1" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Valor Unitário</label>
                        <input type="text" name="produtos[${itemCounter-1}][valor_unitario]" class="form-control valor-unitario valor-unitario-disabled" value="0,00" readonly disabled required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Total</label>
                        <input type="text" class="form-control total-item" readonly style="background:#e9ecef;">
                    </div>
                    <div class="col-md-1 text-center">
                        <label class="form-label">&nbsp;</label>
                        <div><i class="fas fa-trash-alt btn-remover-item text-danger" style="font-size: 1.2em;"></i></div>
                    </div>
                </div>
            `;
            $('#listaProdutos').append(newItem);
            $('.select-produto').on('change', function() {
                let itemId = $(this).data('item');
                carregarProduto(this, itemId);
            });
            $('.quantidade').on('input', function() {
                let itemId = $(this).closest('.item-row').attr('id').split('-')[1];
                atualizarTotalItem(itemId);
                atualizarTotais();
            });
        });
        
        // Remover item
        $(document).on('click', '.btn-remover-item', function() {
            if ($('.item-row').length > 1) {
                $(this).closest('.item-row').remove();
                atualizarTotais();
            } else {
                alert('É necessário manter pelo menos um item na fatura.');
            }
        });
        
        // Eventos
        $(document).on('input', '.quantidade', function() {
            let itemId = $(this).closest('.item-row').attr('id').split('-')[1];
            atualizarTotalItem(itemId);
            atualizarTotais();
        });
        
        $(document).on('change', '.select-produto', function() {
            let itemId = $(this).data('item');
            carregarProduto(this, itemId);
        });
        
        $('#desconto').on('input', function() {
            let valor = $(this).val();
            $(this).val(formatarMoedaInput(valor));
            atualizarTotais();
        });
        
        // Inicializar eventos
        function inicializarEventos() {
            $('.select-produto').each(function() {
                let itemId = $(this).data('item');
                if ($(this).val()) {
                    carregarProduto(this, itemId);
                }
                $(this).off('change').on('change', function() {
                    let itemId = $(this).data('item');
                    carregarProduto(this, itemId);
                });
            });
            
            $('.quantidade').off('input').on('input', function() {
                let itemId = $(this).closest('.item-row').attr('id').split('-')[1];
                atualizarTotalItem(itemId);
                atualizarTotais();
            });
        }
        
        // Inicializar
        $(document).ready(function() {
            inicializarEventos();
            atualizarTotais();
            
            // Fechar modal e resetar formulário
            $('#modalNovaFatura').on('hidden.bs.modal', function() {
                $('#formFatura')[0].reset();
                $('#infoEstudante').hide();
                $('#listaProdutos').html(`
                    <div class="item-row row" id="item-1">
                        <div class="col-md-5">
                            <label class="form-label">Produto/Serviço <span class="text-danger">*</span></label>
                            <select name="produtos[0][produto_id]" class="form-select select-produto" data-item="1" required>
                                <option value="">Selecione um produto</option>
                                <?php foreach ($produtos as $prod): ?>
                                <option value="<?php echo $prod['id']; ?>" data-preco="<?php echo $prod['preco']; ?>" data-nome="<?php echo htmlspecialchars($prod['nome']); ?>">
                                    <?php echo htmlspecialchars($prod['nome']); ?> - <?php echo formatarMoeda($prod['preco']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="produtos[0][descricao]" class="descricao-hidden" value="">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Quantidade</label>
                            <input type="text" name="produtos[0][quantidade]" class="form-control quantidade" value="1" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Valor Unitário</label>
                            <input type="text" name="produtos[0][valor_unitario]" class="form-control valor-unitario valor-unitario-disabled" value="0,00" readonly disabled required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Total</label>
                            <input type="text" class="form-control total-item" readonly style="background:#e9ecef;">
                        </div>
                        <div class="col-md-1 text-center">
                            <label class="form-label">&nbsp;</label>
                            <div><i class="fas fa-trash-alt btn-remover-item text-danger" style="font-size: 1.2em;"></i></div>
                        </div>
                    </div>
                `);
                itemCounter = 1;
                inicializarEventos();
                atualizarTotais();
            });
        });
    </script>
</body>
</html>