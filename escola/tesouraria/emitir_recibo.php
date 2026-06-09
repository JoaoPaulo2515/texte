<?php
// escola/tesouraria/emitir_recibo.php - Emissão de Recibo Individual

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
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
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR EMISSÃO DE RECIBO
// ============================================
$success = '';
$error = '';
$recibo_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'emitir') {
        $aluno_id = (int)$_POST['aluno_id'];
        $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
        $descricao = trim($_POST['descricao']);
        $data_pagamento = $_POST['data_pagamento'];
        $forma_pagamento = $_POST['forma_pagamento'];
        $numero_referencia = trim($_POST['numero_referencia'] ?? '');
        $quem_pagou = trim($_POST['quem_pagou'] ?? '');
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        if ($aluno_id <= 0) {
            $error = "Selecione um aluno.";
        } elseif ($valor <= 0) {
            $error = "Valor inválido.";
        } elseif (empty($descricao)) {
            $error = "Informe a descrição do pagamento.";
        } else {
            // Buscar dados do aluno
            $sql_aluno = "SELECT nome, matricula, endereco FROM estudantes WHERE id = :id AND escola_id = :escola_id";
            $stmt_aluno = $conn->prepare($sql_aluno);
            $stmt_aluno->execute([':id' => $aluno_id, ':escola_id' => $escola_id]);
            $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
            
            if (!$aluno) {
                $error = "Aluno não encontrado.";
            } else {
                // Gerar número do recibo
                $sql_num = "SELECT COUNT(*) as total FROM recibos WHERE escola_id = :escola_id AND YEAR(created_at) = :ano";
                $stmt_num = $conn->prepare($sql_num);
                $stmt_num->execute([':escola_id' => $escola_id, ':ano' => date('Y')]);
                $total = $stmt_num->fetch(PDO::FETCH_ASSOC)['total'];
                $numero_recibo = str_pad($total + 1, 6, '0', STR_PAD_LEFT) . '/' . date('Y');
                
                // Buscar dados da escola
                $sql_escola = "SELECT nome, endereco, telefone, email, nif FROM escolas WHERE id = :id";
                $stmt_escola = $conn->prepare($sql_escola);
                $stmt_escola->execute([':id' => $escola_id]);
                $escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
                
                // Preparar dados do recibo
                $recibo_data = [
                    'numero' => $numero_recibo,
                    'data_emissao' => date('d/m/Y H:i:s'),
                    'data_pagamento' => date('d/m/Y', strtotime($data_pagamento)),
                    'aluno_nome' => $aluno['nome'],
                    'aluno_matricula' => $aluno['matricula'],
                    'aluno_endereco' => $aluno['endereco'] ?? '',
                    'valor' => $valor,
                    'descricao' => $descricao,
                    'forma_pagamento' => $forma_pagamento,
                    'numero_referencia' => $numero_referencia,
                    'quem_pagou' => $quem_pagou,
                    'observacoes' => $observacoes,
                    'escola_nome' => $escola['nome'] ?? 'SIGE Angola',
                    'escola_endereco' => $escola['endereco'] ?? '',
                    'escola_telefone' => $escola['telefone'] ?? '',
                    'escola_email' => $escola['email'] ?? '',
                    'escola_nif' => $escola['nif'] ?? '',
                    'usuario' => $usuario_nome
                ];
                
                // Salvar no banco (opcional)
                $sql_insert = "INSERT INTO recibos (escola_id, numero_recibo, aluno_id, valor, descricao, data_pagamento, forma_pagamento, numero_referencia, quem_pagou, observacoes, usuario_id, created_at) 
                               VALUES (:escola_id, :numero_recibo, :aluno_id, :valor, :descricao, :data_pagamento, :forma_pagamento, :numero_referencia, :quem_pagou, :observacoes, :usuario_id, NOW())";
                $stmt_insert = $conn->prepare($sql_insert);
                $stmt_insert->execute([
                    ':escola_id' => $escola_id,
                    ':numero_recibo' => $numero_recibo,
                    ':aluno_id' => $aluno_id,
                    ':valor' => $valor,
                    ':descricao' => $descricao,
                    ':data_pagamento' => $data_pagamento,
                    ':forma_pagamento' => $forma_pagamento,
                    ':numero_referencia' => $numero_referencia,
                    ':quem_pagou' => $quem_pagou,
                    ':observacoes' => $observacoes,
                    ':usuario_id' => $usuario_id
                ]);
                
                $success = "Recibo emitido com sucesso! Nº: $numero_recibo";
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

// Buscar recibos recentes
$sql_recibos = "SELECT r.*, e.nome as aluno_nome, e.matricula 
                FROM recibos r
                JOIN estudantes e ON e.id = r.aluno_id
                WHERE r.escola_id = :escola_id 
                ORDER BY r.id DESC 
                LIMIT 10";
$stmt_recibos = $conn->prepare($sql_recibos);
$stmt_recibos->execute([':escola_id' => $escola_id]);
$recibos_recentes = $stmt_recibos->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarMoedaRecibo($valor) {
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

function valorPorExtenso($valor) {
    $valor = round($valor, 2);
    $inteiro = floor($valor);
    $centavos = round(($valor - $inteiro) * 100);
    
    $extenso = numeroPorExtenso($inteiro);
    $extenso .= $inteiro == 1 ? ' Kwanzas' : ' Kwanzas';
    
    if ($centavos > 0) {
        $extenso .= ' e ' . numeroPorExtenso($centavos) . ' centavos';
    }
    
    return ucfirst($extenso);
}

function numeroPorExtenso($numero) {
    $unidades = ['', 'um', 'dois', 'três', 'quatro', 'cinco', 'seis', 'sete', 'oito', 'nove'];
    $especiais = ['dez', 'onze', 'doze', 'treze', 'quatorze', 'quinze', 'dezesseis', 'dezessete', 'dezoito', 'dezenove'];
    $dezenas = ['', '', 'vinte', 'trinta', 'quarenta', 'cinquenta', 'sessenta', 'setenta', 'oitenta', 'noventa'];
    $centenas = ['', 'cem', 'duzentos', 'trezentos', 'quatrocentos', 'quinhentos', 'seiscentos', 'setecentos', 'oitocentos', 'novecentos'];
    
    if ($numero == 0) return 'zero';
    if ($numero < 0) return 'menos ' . numeroPorExtenso(abs($numero));
    
    if ($numero >= 1000) {
        $milhares = floor($numero / 1000);
        $resto = $numero % 1000;
        $texto = ($milhares == 1 ? 'um mil' : numeroPorExtenso($milhares) . ' mil');
        if ($resto > 0) {
            $texto .= ($resto < 100 ? ' e ' : ' ') . numeroPorExtenso($resto);
        }
        return $texto;
    }
    
    if ($numero >= 100) {
        $centena = floor($numero / 100);
        $resto = $numero % 100;
        if ($centena == 1 && $resto == 0) return 'cem';
        $texto = $centenas[$centena];
        if ($resto > 0) {
            $texto .= ' e ' . numeroPorExtenso($resto);
        }
        return $texto;
    }
    
    if ($numero >= 20) {
        $dezena = floor($numero / 10);
        $unidade = $numero % 10;
        if ($unidade == 0) return $dezenas[$dezena];
        return $dezenas[$dezena] . ' e ' . $unidades[$unidade];
    }
    
    if ($numero >= 10) {
        return $especiais[$numero - 10];
    }
    
    return $unidades[$numero];
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir Recibo | Tesouraria | SIGE Angola</title>
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
        
        .recibo-preview {
            background: white;
            padding: 30px;
            border: 1px solid #ddd;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
        }
        
        .recibo-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .recibo-title {
            font-size: 18pt;
            font-weight: bold;
            text-align: center;
            margin: 20px 0;
        }
        
        .recibo-linha {
            display: flex;
            margin-bottom: 10px;
        }
        
        .recibo-label {
            width: 150px;
            font-weight: bold;
        }
        
        .recibo-valor {
            flex: 1;
        }
        
        .recibo-assinatura {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
        }
        
        .recibo-assinatura-item {
            text-align: center;
            width: 45%;
        }
        
        .recibo-linha-assinatura {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 5px;
            width: 100%;
        }
        
        .recibo-rodape {
            text-align: center;
            font-size: 9pt;
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px dashed #ccc;
        }
        
        .print-only { display: none; }
        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .print-only { display: block; }
            .main-content { margin-left: 0; padding: 0; }
            .card { box-shadow: none; border: none; }
            .recibo-preview { padding: 0; border: none; }
        }
    </style>
</head>
<body>
    <button class="menu-toggle no-print" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'menu_tesouraria.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-receipt"></i> Emitir Recibo</h2>
                <p class="text-muted">Emissão de recibos para alunos e responsáveis</p>
            </div>
            <div class="no-print">
                <a href="index.php" class="btn-voltar">
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
            <!-- Formulário de Emissão -->
            <div class="col-md-6">
                <div class="card no-print">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-edit"></i> Dados do Recibo</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formRecibo">
                            <input type="hidden" name="action" value="emitir">
                            
                            <div class="mb-3">
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
                            
                            <div class="mb-3">
                                <label class="form-label">Valor <span class="text-danger">*</span></label>
                                <input type="text" name="valor" id="valor" class="form-control" required placeholder="0,00">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Descrição <span class="text-danger">*</span></label>
                                <textarea name="descricao" id="descricao" class="form-control" rows="2" required placeholder="Ex: Pagamento de mensalidade referente a Fevereiro/2024"></textarea>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Data de Pagamento</label>
                                    <input type="date" name="data_pagamento" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
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
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Nº de Referência</label>
                                <input type="text" name="numero_referencia" class="form-control" placeholder="Nº do comprovativo, cheque, transferência...">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Pago por</label>
                                <input type="text" name="quem_pagou" class="form-control" placeholder="Nome de quem efetuou o pagamento (Pai, Mãe, Responsável...)">
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Observações</label>
                                <textarea name="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-receipt"></i> Emitir Recibo
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Pré-visualização do Recibo -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-eye"></i> Pré-visualização</h5>
                    </div>
                    <div class="card-body">
                        <div id="previewRecibo" class="recibo-preview">
                            <div class="recibo-header">
                                <strong id="preview_escola_nome">SIGE Angola</strong><br>
                                <small id="preview_escola_dados">Sistema Integrado de Gestão Escolar</small>
                            </div>
                            
                            <div class="recibo-title">
                                <strong>RECIBO DE PAGAMENTO</strong>
                            </div>
                            
                            <div class="recibo-linha">
                                <div class="recibo-label">Nº do Recibo:</div>
                                <div class="recibo-valor"><strong id="preview_numero">---/----</strong></div>
                            </div>
                            <div class="recibo-linha">
                                <div class="recibo-label">Data de Emissão:</div>
                                <div class="recibo-valor" id="preview_data_emissao"><?php echo date('d/m/Y H:i:s'); ?></div>
                            </div>
                            <div class="recibo-linha">
                                <div class="recibo-label">Data de Pagamento:</div>
                                <div class="recibo-valor" id="preview_data_pagamento"><?php echo date('d/m/Y'); ?></div>
                            </div>
                            
                            <hr>
                            
                            <div class="recibo-linha">
                                <div class="recibo-label">Recebemos de:</div>
                                <div class="recibo-valor"><strong id="preview_aluno_nome">---</strong></div>
                            </div>
                            <div class="recibo-linha">
                                <div class="recibo-label">Matrícula:</div>
                                <div class="recibo-valor" id="preview_aluno_matricula">---</div>
                            </div>
                            
                            <hr>
                            
                            <div class="recibo-linha">
                                <div class="recibo-label">A importância de:</div>
                                <div class="recibo-valor"><strong id="preview_valor">---</strong></div>
                            </div>
                            <div class="recibo-linha">
                                <div class="recibo-label">Por Extenso:</div>
                                <div class="recibo-valor" id="preview_extenso">---</div>
                            </div>
                            
                            <hr>
                            
                            <div class="recibo-linha">
                                <div class="recibo-label">Referente a:</div>
                                <div class="recibo-valor" id="preview_descricao">---</div>
                            </div>
                            
                            <div class="recibo-linha">
                                <div class="recibo-label">Forma de Pagamento:</div>
                                <div class="recibo-valor" id="preview_forma">---</div>
                            </div>
                            <div class="recibo-linha" id="row_referencia" style="display:none;">
                                <div class="recibo-label">Nº Referência:</div>
                                <div class="recibo-valor" id="preview_referencia">---</div>
                            </div>
                            <div class="recibo-linha" id="row_pagador" style="display:none;">
                                <div class="recibo-label">Pago por:</div>
                                <div class="recibo-valor" id="preview_quem_pagou">---</div>
                            </div>
                            
                            <div class="recibo-assinatura">
                                <div class="recibo-assinatura-item">
                                    <div class="recibo-linha-assinatura"></div>
                                    <div>Assinatura do Cliente</div>
                                </div>
                                <div class="recibo-assinatura-item">
                                    <div class="recibo-linha-assinatura"></div>
                                    <div>Assinatura do Recebedor</div>
                                </div>
                            </div>
                            
                            <div class="recibo-rodape">
                                Documento emitido por computador - Válido como recibo de pagamento<br>
                                <span id="preview_usuario"><?php echo $usuario_nome; ?></span> - <?php echo date('d/m/Y H:i:s'); ?>
                            </div>
                        </div>
                        
                        <div class="text-center mt-3 no-print">
                            <button class="btn btn-info" id="btnImprimir" style="display:none;" onclick="imprimirRecibo()">
                                <i class="fas fa-print"></i> Imprimir Recibo
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recibos Recentes -->
        <div class="card mt-4 no-print">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Últimos Recibos Emitidos</h5>
            </div>
            <div class="card-body">
                <?php if (empty($recibos_recentes)): ?>
                    <div class="alert alert-info text-center">Nenhum recibo emitido ainda.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr><th>Nº Recibo</th><th>Aluno</th><th>Valor</th><th>Data</th><th>Descrição</th><th>Ações</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recibos_recentes as $rec): ?>
                                <tr>
                                    <td><strong><?php echo $rec['numero_recibo']; ?></strong></td>
                                    <td><?php echo htmlspecialchars($rec['aluno_nome']); ?><br><small><?php echo $rec['matricula']; ?></small></td>
                                    <td><?php echo formatarMoedaRecibo($rec['valor']); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($rec['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars(substr($rec['descricao'], 0, 50)); ?>..</small></td>
                                    <td>
                                        <button class="btn btn-sm btn-info" onclick="visualizarRecibo(<?php echo $rec['id']; ?>)">
                                            <i class="fas fa-eye"></i> Ver
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function formatarValor(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('#valor').on('input', function() {
            $(this).val(formatarValor($(this).val()));
            atualizarPreview();
        });
        
        $('#aluno_id').on('change', function() {
            atualizarPreview();
        });
        
        $('#descricao').on('input', function() {
            $('#preview_descricao').text($(this).val() || '---');
        });
        
        $('input[name="data_pagamento"]').on('change', function() {
            let data = $(this).val();
            if (data) {
                let partes = data.split('-');
                $('#preview_data_pagamento').text(partes[2] + '/' + partes[1] + '/' + partes[0]);
            }
        });
        
        $('select[name="forma_pagamento"]').on('change', function() {
            let forma = $(this).find('option:selected').text();
            $('#preview_forma').text(forma || '---');
        });
        
        $('input[name="numero_referencia"]').on('input', function() {
            let ref = $(this).val();
            if (ref) {
                $('#row_referencia').show();
                $('#preview_referencia').text(ref);
            } else {
                $('#row_referencia').hide();
            }
        });
        
        $('input[name="quem_pagou"]').on('input', function() {
            let pagador = $(this).val();
            if (pagador) {
                $('#row_pagador').show();
                $('#preview_quem_pagou').text(pagador);
            } else {
                $('#row_pagador').hide();
            }
        });
        
        function atualizarPreview() {
            let alunoId = $('#aluno_id').val();
            let alunoNome = $('#aluno_id option:selected').data('nome') || '---';
            let alunoMatricula = $('#aluno_id option:selected').data('matricula') || '---';
            let valor = $('#valor').val() || '0,00';
            let valorNumerico = parseFloat(valor.replace(/\./g, '').replace(',', '.')) || 0;
            let descricao = $('#descricao').val() || '---';
            let forma = $('select[name="forma_pagamento"] option:selected').text();
            let referencia = $('input[name="numero_referencia"]').val();
            let pagador = $('input[name="quem_pagou"]').val();
            
            $('#preview_aluno_nome').text(alunoNome);
            $('#preview_aluno_matricula').text(alunoMatricula);
            $('#preview_valor').text(valor + ' Kz');
            $('#preview_descricao').text(descricao);
            $('#preview_forma').text(forma);
            
            // Valor por extenso
            if (valorNumerico > 0) {
                $.ajax({
                    url: 'ajax_extenso.php',
                    method: 'POST',
                    data: { valor: valorNumerico },
                    success: function(response) {
                        $('#preview_extenso').text(response);
                    },
                    error: function() {
                        $('#preview_extenso').text(valorNumerico.toLocaleString('pt-BR') + ' Kwanzas');
                    }
                });
            } else {
                $('#preview_extenso').text('---');
            }
            
            if (referencia) {
                $('#row_referencia').show();
                $('#preview_referencia').text(referencia);
            } else {
                $('#row_referencia').hide();
            }
            
            if (pagador) {
                $('#row_pagador').show();
                $('#preview_quem_pagou').text(pagador);
            } else {
                $('#row_pagador').hide();
            }
        }
        
        function visualizarRecibo(id) {
            window.open('ver_recibo.php?id=' + id, '_blank');
        }
        
        function imprimirRecibo() {
            window.print();
        }
        
        // Atualizar número do recibo após emitir
        <?php if ($recibo_data): ?>
        $('#preview_numero').text('<?php echo $recibo_data['numero']; ?>');
        $('#btnImprimir').show();
        <?php endif; ?>
        
        // Inicializar preview
        atualizarPreview();
    </script>
</body>
</html>