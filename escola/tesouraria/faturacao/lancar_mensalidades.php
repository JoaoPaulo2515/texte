<?php
// escola/tesouraria/faturacao/lancar_mensalidades.php - Lançamento de Mensalidades

require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
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
    header('Location: ../../login.php?msg=acesso_negado');
    exit;
}

// ============================================
// PROCESSAR LANÇAMENTO DE MENSALIDADES
// ============================================
$success = '';
$error = '';
$alunos_selecionados = [];

// Buscar anos letivos
$sql_anos = "SELECT id, ano,ativo FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// Buscar turmas
$sql_turmas = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// Buscar alunos quando selecionada uma turma via AJAX ou POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buscar_alunos'])) {
    $turma_id = (int)$_POST['turma_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    
    $sql_alunos = "SELECT e.id, e.nome, e.matricula 
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND e.status = 'ativo'
                   ORDER BY e.nome ASC";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar informações do ano letivo
    $sql_ano_info = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano_info = $conn->prepare($sql_ano_info);
    $stmt_ano_info->execute([':id' => $ano_letivo_id]);
    $ano_info = $stmt_ano_info->fetch(PDO::FETCH_ASSOC);
}

// Processar lançamento em lote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lancar_mensalidades'])) {
    $turma_id = (int)$_POST['turma_id'];
    $ano_letivo_id = (int)$_POST['ano_letivo_id'];
    $mes_referencia = (int)$_POST['mes_referencia'];
    $ano_referencia = (int)$_POST['ano_referencia'];
    $valor = floatval(str_replace(',', '.', str_replace('.', '', $_POST['valor'] ?? '0')));
    $data_vencimento = $_POST['data_vencimento'];
    $alunos_ids = isset($_POST['alunos_ids']) ? $_POST['alunos_ids'] : [];
    
    if ($turma_id <= 0) {
        $error = "Selecione uma turma.";
    } elseif (empty($alunos_ids)) {
        $error = "Selecione pelo menos um aluno.";
    } elseif ($valor <= 0) {
        $error = "Valor da mensalidade inválido.";
    } else {
        try {
            $conn->beginTransaction();
            $contador = 0;
            
            foreach ($alunos_ids as $aluno_id) {
                // Verificar se já existe mensalidade para este período
                $sql_check = "SELECT id FROM mensalidades 
                              WHERE escola_id = :escola_id 
                              AND aluno_id = :aluno_id 
                              AND mes_referencia = :mes 
                              AND ano_letivo_id = :ano_letivo_id";
                $stmt_check = $conn->prepare($sql_check);
                $stmt_check->execute([
                    ':escola_id' => $escola_id,
                    ':aluno_id' => $aluno_id,
                    ':mes' => $mes_referencia,
                    ':ano_letivo_id' => $ano_letivo_id
                ]);
                
                if (!$stmt_check->fetch()) {
                    $sql_insert = "INSERT INTO mensalidades (
                                        escola_id, aluno_id, turma_id, mes_referencia, 
                                        ano_referencia, ano_letivo_id, valor_total, 
                                        valor_pago, status, data_vencimento, created_at
                                    ) VALUES (
                                        :escola_id, :aluno_id, :turma_id, :mes, 
                                        :ano, :ano_letivo_id, :valor, 
                                        0, 'pendente', :data_vencimento, NOW()
                                    )";
                    $stmt_insert = $conn->prepare($sql_insert);
                    $stmt_insert->execute([
                        ':escola_id' => $escola_id,
                        ':aluno_id' => $aluno_id,
                        ':turma_id' => $turma_id,
                        ':mes' => $mes_referencia,
                        ':ano' => $ano_referencia,
                        ':ano_letivo_id' => $ano_letivo_id,
                        ':valor' => $valor,
                        ':data_vencimento' => $data_vencimento
                    ]);
                    $contador++;
                }
            }
            
            $conn->commit();
            
            if ($contador == 0) {
                $error = "Nenhuma mensalidade nova foi lançada. Os alunos já possuem mensalidades para este período.";
            } else {
                $success = "$contador mensalidade(s) lançada(s) com sucesso!";
                // Limpar seleção após sucesso
                $alunos_selecionados = [];
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Erro ao lançar mensalidades: " . $e->getMessage();
        }
    }
}

// Buscar mês atual
$mes_atual = date('m');
$ano_atual = date('Y');

function getMesNome($mes) {
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    return $meses[$mes] ?? '-';
}

function formatarMoeda($valor) {
    return number_format($valor, 2, ',', '.') . ' Kz';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lançar Mensalidades | Faturação | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        }
        
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-voltar { background: #6c757d; color: white; border-radius: 25px; padding: 8px 20px; text-decoration: none; border: none; display: inline-block; }
        .btn-voltar:hover { background: #5a6268; color: white; }
        
        .modal-header-custom { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; }
        
        .student-checkbox {
            cursor: pointer;
            transition: all 0.2s;
        }
        .student-checkbox:hover {
            background: #e8f5e9;
        }
        .student-checkbox.selected {
            background: #d4edda;
        }
        
        .select-all-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 10px 15px;
            margin-bottom: 15px;
        }
        
        .info-summary {
            background: #e8f5e9;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-calendar-dollar"></i> Lançar Mensalidades</h2>
                <p class="text-muted">Lançamento de mensalidades por turma e aluno</p>
            </div>
            <div>
                <a href="../index.php" class="btn-voltar">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <!-- Seleção de Turma e Configurações -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-school"></i> Selecionar Turma e Configurar Mensalidade</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="formBuscarAlunos">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ano Letivo <span class="text-danger">*</span></label>
                            <select name="ano_letivo_id" id="ano_letivo_id" class="form-select" required>
                                <option value="">Selecione o ano letivo</option>
                                <?php foreach ($anos_letivos as $al): ?>
                                <option value="<?php echo $al['id']; ?>" <?php echo ($al['ativo'] == 1) ? 'selected' : ''; ?>>
                                    <?php echo $al['ano']; ?> (<?php echo $al['ativo']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Turma <span class="text-danger">*</span></label>
                            <select name="turma_id" id="turma_id" class="form-select" required>
                                <option value="">Selecione uma turma</option>
                                <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>"><?php echo $t['ano'] . 'ª - ' . htmlspecialchars($t['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <button type="submit" name="buscar_alunos" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Buscar Alunos
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Lista de Alunos e Lançamento -->
        <?php if (isset($alunos) && !empty($alunos)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-users"></i> Alunos da Turma: <?php echo $turma_info['ano'] . 'ª - ' . htmlspecialchars($turma_info['nome']); ?></h5>
                <small>Ano Letivo: <?php echo $ano_info['ano']; ?></small>
            </div>
            <div class="card-body">
                <form method="POST" id="formLancarMensalidades">
                    <input type="hidden" name="turma_id" value="<?php echo $turma_id; ?>">
                    <input type="hidden" name="ano_letivo_id" value="<?php echo $ano_letivo_id; ?>">
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Mês Referência <span class="text-danger">*</span></label>
                            <select name="mes_referencia" class="form-select" required>
                                <?php for($m=1; $m<=12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $mes_atual ? 'selected' : ''; ?>>
                                    <?php echo getMesNome($m); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Ano Referência <span class="text-danger">*</span></label>
                            <select name="ano_referencia" class="form-select" required>
                                <?php for($a = date('Y')-1; $a <= date('Y')+1; $a++): ?>
                                <option value="<?php echo $a; ?>" <?php echo $a == $ano_atual ? 'selected' : ''; ?>>
                                    <?php echo $a; ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Valor da Mensalidade <span class="text-danger">*</span></label>
                            <input type="text" name="valor" id="valor_mensalidade" class="form-control" required placeholder="0,00">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Data de Vencimento <span class="text-danger">*</span></label>
                            <input type="date" name="data_vencimento" id="data_vencimento" class="form-control" required>
                        </div>
                    </div>
                    
                    <div class="select-all-card">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="selecionar_todos">
                                    <label class="form-check-label fw-bold" for="selecionar_todos">
                                        <i class="fas fa-check-double"></i> Selecionar Todos os Alunos
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 text-end">
                                <span id="contador_selecionados" class="badge bg-primary">0 alunos selecionados</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th width="50"><input type="checkbox" id="selecionar_todos_tabela"></th>
                                    <th>Nome do Aluno</th>
                                    <th>Matrícula</th>
                                    <th>Situação</th>
                                    <th>Status Mensalidade</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($alunos as $aluno): 
                                    // Verificar se já tem mensalidade para este período
                                    $sql_verificar = "SELECT id, status FROM mensalidades 
                                                      WHERE aluno_id = :aluno_id 
                                                      AND mes_referencia = :mes 
                                                      AND ano_letivo_id = :ano_letivo_id
                                                      LIMIT 1";
                                    $stmt_verificar = $conn->prepare($sql_verificar);
                                    $stmt_verificar->execute([
                                        ':aluno_id' => $aluno['id'],
                                        ':mes' => $mes_atual,
                                        ':ano_letivo_id' => $ano_letivo_id
                                    ]);
                                    $existe = $stmt_verificar->fetch(PDO::FETCH_ASSOC);
                                    $tem_mensalidade = $existe ? true : false;
                                    $status_mensalidade = $existe ? $existe['status'] : '';
                                ?>
                                <tr class="student-checkbox">
                                    <td>
                                        <input type="checkbox" name="alunos_ids[]" value="<?php echo $aluno['id']; ?>" 
                                               class="aluno-checkbox" <?php echo $tem_mensalidade ? 'disabled' : ''; ?>>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                    </td>
                                    <td><?php echo $aluno['matricula']; ?></td>
                                    <td>
                                        <span class="badge bg-success">Ativo</span>
                                    </td>
                                    <td>
                                        <?php if ($tem_mensalidade): ?>
                                            <span class="badge bg-warning text-dark">
                                                <i class="fas fa-check-circle"></i> Já possui mensalidade (<?php echo $status_mensalidade; ?>)
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">
                                                <i class="fas fa-hourglass-half"></i> Pendente
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="info-summary">
                        <div class="row">
                            <div class="col-md-6">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Resumo:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Total de alunos na turma: <strong><?php echo count($alunos); ?></strong></li>
                                    <li>Alunos que já possuem mensalidade: <strong>
                                        <?php 
                                        $com_mensalidade = 0;
                                        foreach ($alunos as $a) {
                                            $stmt_ver = $conn->prepare("SELECT id FROM mensalidades WHERE aluno_id = :aluno_id AND mes_referencia = :mes AND ano_letivo_id = :ano_letivo_id LIMIT 1");
                                            $stmt_ver->execute([':aluno_id' => $a['id'], ':mes' => $mes_atual, ':ano_letivo_id' => $ano_letivo_id]);
                                            if ($stmt_ver->fetch()) $com_mensalidade++;
                                        }
                                        echo $com_mensalidade;
                                        ?>
                                    </strong></li>
                                    <li>Alunos disponíveis para lançamento: <strong><?php echo count($alunos) - $com_mensalidade; ?></strong></li>
                                </ul>
                            </div>
                            <div class="col-md-6 text-end">
                                <div class="alert alert-info mb-0">
                                    <i class="fas fa-lightbulb"></i>
                                    <strong>Dica:</strong> Alunos que já possuem mensalidade para o período selecionado não podem ser selecionados novamente.
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end mt-4">
                        <button type="submit" name="lancar_mensalidades" class="btn btn-primary btn-lg">
                            <i class="fas fa-save"></i> Lançar Mensalidades Selecionadas
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Instruções -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-question-circle"></i> Instruções</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="text-center">
                            <i class="fas fa-school fa-2x text-primary mb-2"></i>
                            <h6>1. Selecione a Turma</h6>
                            <p class="small text-muted">Escolha o ano letivo e a turma para carregar os alunos</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <i class="fas fa-cog fa-2x text-primary mb-2"></i>
                            <h6>2. Configure a Mensalidade</h6>
                            <p class="small text-muted">Defina mês, ano, valor e data de vencimento</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <i class="fas fa-check-circle fa-2x text-primary mb-2"></i>
                            <h6>3. Selecione e Lance</h6>
                            <p class="small text-muted">Marque os alunos e clique em lançar mensalidades</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Formatar valor monetário
        function formatarMoeda(valor) {
            let v = valor.replace(/\D/g, '');
            v = (v / 100).toFixed(2) + '';
            v = v.replace('.', ',');
            v = v.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return v;
        }
        
        $('#valor_mensalidade').on('input', function() {
            $(this).val(formatarMoeda($(this).val()));
        });
        
        // Setar data de vencimento padrão (dia 10 do próximo mês)
        let dataPadrao = new Date();
        dataPadrao.setMonth(dataPadrao.getMonth() + 1);
        dataPadrao.setDate(10);
        $('#data_vencimento').val(dataPadrao.toISOString().split('T')[0]);
        
        // Selecionar todos os alunos
        $('#selecionar_todos, #selecionar_todos_tabela').on('change', function() {
            let isChecked = $(this).is(':checked');
            $('.aluno-checkbox:not(:disabled)').prop('checked', isChecked);
            atualizarContador();
        });
        
        // Atualizar contador de selecionados
        $('.aluno-checkbox').on('change', function() {
            atualizarContador();
        });
        
        function atualizarContador() {
            let total = $('.aluno-checkbox:not(:disabled)').length;
            let selecionados = $('.aluno-checkbox:not(:disabled):checked').length;
            $('#contador_selecionados').text(selecionados + ' de ' + total + ' alunos selecionados');
            
            // Atualizar checkbox de selecionar todos
            if (selecionados === total && total > 0) {
                $('#selecionar_todos, #selecionar_todos_tabela').prop('checked', true);
            } else {
                $('#selecionar_todos, #selecionar_todos_tabela').prop('checked', false);
            }
        }
        
        // Efeito hover nas linhas
        $('.student-checkbox').hover(
            function() { $(this).addClass('selected'); },
            function() { $(this).removeClass('selected'); }
        );
        
        // Inicializar Select2 para selects grandes (se necessário)
        $('#turma_id, #ano_letivo_id').select2({
            theme: 'bootstrap-5',
            placeholder: 'Selecione...'
        });
        
        // Inicializar contador
        atualizarContador();
    </script>
</body>
</html>