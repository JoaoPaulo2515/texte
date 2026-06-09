<?php
// escola/aluno/biblioteca/reservas.php - Reservas de Livros do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o aluno está logado
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];
$aluno_nome = $_SESSION['aluno_nome'] ?? 'Aluno';
$aluno_matricula = $_SESSION['aluno_matricula'] ?? '';

// Definir título da página
$titulo_pagina = 'Minhas Reservas';

// Buscar turma do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano 
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              WHERE m.estudante_id = :aluno_id AND m.status = 'ativa'
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Filtros
$status_filtro = isset($_GET['status']) ? $_GET['status'] : 'todos';
$busca = isset($_GET['busca']) ? $_GET['busca'] : '';

// ==============================================
// BUSCAR RESERVAS DO ALUNO
// ==============================================
$sql_reservas = "SELECT 
                    r.id,
                    r.data_reserva,
                    r.data_validade,
                    r.status,
                    r.notificacao_enviada,
                    r.observacoes,
                    a.id as acervo_id,
                    a.titulo,
                    a.autor,
                    a.editora,
                    a.edicao,
                    a.ano_publicacao,
                    a.isbn,
                    a.codigo_barras,
                    a.sinopse,
                    a.capa,
                    a.quantidade_disponivel,
                    a.localizacao,
                    u.nome as bibliotecario_nome
                FROM reservas r
                INNER JOIN acervo_livros a ON a.id = r.acervo_id AND a.escola_id = r.escola_id
                LEFT JOIN usuarios u ON u.id = r.bibliotecario_id
                WHERE r.aluno_id = :aluno_id 
                AND r.escola_id = :escola_id";

if ($status_filtro != 'todos') {
    $sql_reservas .= " AND r.status = :status";
}
if (!empty($busca)) {
    $sql_reservas .= " AND (a.titulo LIKE :busca OR a.autor LIKE :busca OR a.isbn LIKE :busca)";
}

$sql_reservas .= " ORDER BY r.status = 'ativa' DESC, r.data_validade ASC, r.data_reserva DESC";

$stmt_reservas = $conn->prepare($sql_reservas);
$params = [':aluno_id' => $aluno_id, ':escola_id' => $escola_id];
if ($status_filtro != 'todos') {
    $params[':status'] = $status_filtro;
}
if (!empty($busca)) {
    $params[':busca'] = "%$busca%";
}
$stmt_reservas->execute($params);
$reservas = $stmt_reservas->fetchAll(PDO::FETCH_ASSOC);

// ==============================================
// ESTATÍSTICAS
// ==============================================
$total_reservas = count($reservas);
$total_ativas = 0;
$total_expiradas = 0;
$total_canceladas = 0;
$total_convertidas = 0;

foreach ($reservas as $res) {
    if ($res['status'] == 'ativa') {
        $total_ativas++;
        // Verificar se expirou
        if (strtotime($res['data_validade']) < time()) {
            $total_expiradas++;
        }
    } elseif ($res['status'] == 'cancelada') {
        $total_canceladas++;
    } elseif ($res['status'] == 'convertida') {
        $total_convertidas++;
    }
}

// ==============================================
// FUNÇÕES AUXILIARES
// ==============================================
function getStatusReservaBadge($status, $data_validade = null) {
    if ($status == 'convertida') {
        return '<span class="badge bg-success"><i class="fas fa-check-circle"></i> Convertida em Empréstimo</span>';
    } elseif ($status == 'cancelada') {
        return '<span class="badge bg-danger"><i class="fas fa-times-circle"></i> Cancelada</span>';
    } elseif ($status == 'ativa') {
        if ($data_validade && strtotime($data_validade) < time()) {
            return '<span class="badge bg-secondary"><i class="fas fa-clock"></i> Expirada</span>';
        }
        return '<span class="badge bg-warning text-dark"><i class="fas fa-calendar-check"></i> Ativa</span>';
    }
    return '<span class="badge bg-secondary">' . $status . '</span>';
}

function formatarData($data, $formato = 'd/m/Y H:i') {
    if (empty($data)) return '-';
    return date($formato, strtotime($data));
}

function calcularDiasRestantes($data_validade) {
    if (empty($data_validade)) return '-';
    $hoje = new DateTime();
    $validade = new DateTime($data_validade);
    $diferenca = $hoje->diff($validade);
    
    if ($hoje > $validade) {
        return '<span class="text-danger">Expirada há ' . $diferenca->days . ' dias</span>';
    } else {
        $dias = $diferenca->days;
        if ($dias == 0) {
            return '<span class="text-warning">Expira hoje!</span>';
        }
        return '<span class="text-success">' . $dias . ' dias restantes</span>';
    }
}

function getCorDataValidade($data_validade) {
    if (empty($data_validade)) return '';
    $dias = (strtotime($data_validade) - time()) / 86400;
    if ($dias < 0) return 'text-danger fw-bold';
    if ($dias == 0) return 'text-warning fw-bold';
    if ($dias <= 2) return 'text-warning';
    return 'text-success';
}


?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?> | Área do Aluno</title>
    <style>
        .card {
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            height: 100%;
        }
        .stat-value {
            font-size: 1.8em;
            font-weight: bold;
        }
        
        .reserva-card {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s ease;
            border: 1px solid #e0e0e0;
            overflow: hidden;
        }
        .reserva-card:hover {
            box-shadow: 0 5px 20px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        .reserva-header {
            padding: 15px 20px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            cursor: pointer;
            background: #f8f9fa;
        }
        .reserva-body {
            padding: 20px;
            display: none;
        }
        .reserva-body.show {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        .reserva-footer {
            background: #f8f9fa;
            padding: 12px 20px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .toggle-icon {
            transition: transform 0.3s;
        }
        .toggle-icon.rotated {
            transform: rotate(180deg);
        }
        
        .capa-material {
            width: 100px;
            height: 130px;
            object-fit: cover;
            border-radius: 8px;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-cancelar-reserva {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 12px;
            border-radius: 5px;
            font-size: 0.8rem;
            cursor: pointer;
        }
        .btn-cancelar-reserva:hover {
            background: #c82333;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in {
            animation: fadeIn 0.5s ease-out;
        }
        
        .btn-ajuda {
            position: fixed;
            bottom: 80px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-ajuda:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.3);
        }
        
        .modal-ajuda {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
        }
        .modal-ajuda.show {
            display: flex;
        }
        .modal-ajuda-content {
            background: white;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            animation: fadeInUp 0.3s ease;
        }
        .modal-ajuda-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-ajuda-body {
            padding: 20px;
        }
        .modal-ajuda-close {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
        }
        .ajuda-item {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .ajuda-item:last-child {
            border-bottom: none;
        }
        .ajuda-titulo {
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 8px;
        }
        .ajuda-texto {
            color: #666;
            font-size: 0.9rem;
            line-height: 1.4;
        }
        .ajuda-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e8f5e9;
            border-radius: 8px;
            text-align: center;
            line-height: 30px;
            margin-right: 10px;
            color: #006B3E;
            font-weight: bold;
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media print {
            .btn-ajuda, .filtros-card, .btn-imprimir, .menu-aluno { display: none; }
            .reserva-card { break-inside: avoid; page-break-inside: avoid; }
            .reserva-body { display: block !important; }
        }
    </style>
</head>
<body>
<?php include 'includes/menu_aluno.php'; ?>
<button class="btn-ajuda" id="btnAjuda"><i class="fas fa-question fa-lg"></i></button>

<div class="modal-ajuda" id="modalAjuda">
    <div class="modal-ajuda-content">
        <div class="modal-ajuda-header">
            <h5 class="mb-0"><i class="fas fa-question-circle"></i> Ajuda - Minhas Reservas</h5>
            <button class="modal-ajuda-close" id="closeAjuda">&times;</button>
        </div>
        <div class="modal-ajuda-body">
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">1</span> Sobre esta página</div>
                <div class="ajuda-texto">Esta página exibe todas as suas reservas de livros na biblioteca.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">2</span> Status das Reservas</div>
                <div class="ajuda-texto">
                    <span class="badge bg-warning text-dark">Ativa</span> - Aguardando disponibilidade<br>
                    <span class="badge bg-success">Convertida</span> - Reserva convertida em empréstimo<br>
                    <span class="badge bg-secondary">Expirada</span> - Prazo de reserva vencido<br>
                    <span class="badge bg-danger">Cancelada</span> - Reserva cancelada
                </div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">3</span> Validade da Reserva</div>
                <div class="ajuda-texto">As reservas têm validade de 48 horas. Após esse prazo, expiram automaticamente.</div>
            </div>
            <div class="ajuda-item">
                <div class="ajuda-titulo"><span class="ajuda-badge">4</span> Cancelamento</div>
                <div class="ajuda-texto">Você pode cancelar uma reserva ativa a qualquer momento.</div>
            </div>
        </div>
    </div>
</div>

<div class="main-content-aluno">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1"><i class="fas fa-calendar-check"></i> Minhas Reservas</h4>
            <p class="text-muted mb-0">Acompanhe suas reservas de livros</p>
        </div>
        <div>
            <a href="acervo.php" class="btn btn-primary">
                <i class="fas fa-search"></i> Consultar Acervo
            </a>
            <button class="btn btn-secondary ms-2" onclick="window.print();">
                <i class="fas fa-print"></i> Imprimir
            </button>
        </div>
    </div>
    
    <!-- Informações do Aluno -->
    <div class="card border-0 shadow-sm mb-4 fade-in">
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <small class="text-muted"><i class="fas fa-user-graduate"></i> Aluno</small>
                    <h6 class="mb-0"><?php echo htmlspecialchars($aluno_nome); ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-id-card"></i> Matrícula</small>
                    <h6 class="mb-0"><?php echo $aluno_matricula; ?></h6>
                </div>
                <div class="col-md-3">
                    <small class="text-muted"><i class="fas fa-users"></i> Turma</small>
                    <h6 class="mb-0"><?php echo ($turma['ano'] ?? '') . 'ª - ' . htmlspecialchars($turma['nome'] ?? 'Não atribuída'); ?></h6>
                </div>
                <div class="col-md-2">
                    <small class="text-muted"><i class="fas fa-calendar-check"></i> Total Reservas</small>
                    <h6 class="mb-0"><?php echo $total_reservas; ?></h6>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row g-3 mb-4 fade-in">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-warning"><?php echo $total_ativas; ?></div>
                <div class="stat-label"><i class="fas fa-clock text-warning"></i> Reservas Ativas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-secondary"><?php echo $total_expiradas; ?></div>
                <div class="stat-label"><i class="fas fa-hourglass-end text-secondary"></i> Expiradas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-success"><?php echo $total_convertidas; ?></div>
                <div class="stat-label"><i class="fas fa-exchange-alt text-success"></i> Convertidas</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-value text-danger"><?php echo $total_canceladas; ?></div>
                <div class="stat-label"><i class="fas fa-times-circle text-danger"></i> Canceladas</div>
            </div>
        </div>
    </div>
    
    <!-- Filtros -->
    <div class="card border-0 shadow-sm mb-4 fade-in filtros-card">
        <div class="card-header bg-white fw-bold"><i class="fas fa-filter"></i> Filtros</div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <option value="todos" <?php echo $status_filtro == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="ativa" <?php echo $status_filtro == 'ativa' ? 'selected' : ''; ?>>Ativas</option>
                        <option value="convertida" <?php echo $status_filtro == 'convertida' ? 'selected' : ''; ?>>Convertidas</option>
                        <option value="cancelada" <?php echo $status_filtro == 'cancelada' ? 'selected' : ''; ?>>Canceladas</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">Buscar</label>
                    <input type="text" name="busca" class="form-control" placeholder="Título, autor ou ISBN..." value="<?php echo htmlspecialchars($busca); ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i> Filtrar</button>
                    <a href="reservas.php" class="btn btn-outline-secondary ms-2 w-100"><i class="fas fa-eraser"></i> Limpar</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Lista de Reservas -->
    <?php if (empty($reservas)): ?>
        <div class="alert alert-info text-center fade-in">
            <i class="fas fa-info-circle fa-3x mb-3"></i>
            <h5>Nenhuma reserva encontrada</h5>
            <p>Você ainda não realizou nenhuma reserva na biblioteca.</p>
            <a href="acervo.php" class="btn btn-primary mt-2">
                <i class="fas fa-search"></i> Consultar Acervo
            </a>
        </div>
    <?php else: ?>
        <div class="reservas-list">
            <?php foreach ($reservas as $res): 
                $is_expirada = ($res['status'] == 'ativa' && strtotime($res['data_validade']) < time());
                $pode_cancelar = ($res['status'] == 'ativa' && !$is_expirada);
            ?>
            <div class="reserva-card fade-in" id="reserva-<?php echo $res['id']; ?>">
                <div class="reserva-header" onclick="toggleReserva(<?php echo $res['id']; ?>)">
                    <div class="d-flex align-items-center gap-3">
                        <i class="fas fa-bookmark fa-lg text-primary"></i>
                        <div>
                            <h6 class="mb-0"><?php echo htmlspecialchars($res['titulo']); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($res['autor']); ?></small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-3">
                        <?php echo getStatusReservaBadge($res['status'], $res['data_validade']); ?>
                        <i class="fas fa-chevron-down toggle-icon" id="toggle-icon-<?php echo $res['id']; ?>"></i>
                    </div>
                </div>
                
                <div class="reserva-body" id="reserva-body-<?php echo $res['id']; ?>">
                    <div class="row">
                        <div class="col-md-3 text-center">
                            <?php if (!empty($res['capa'])): ?>
                                <img src="<?php echo $res['capa']; ?>" class="capa-material" alt="Capa do Livro">
                            <?php else: ?>
                                <div class="capa-material mx-auto bg-light">
                                    <i class="fas fa-book fa-4x text-secondary"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-9">
                            <div class="row">
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="130"><strong>Título:</strong></td>
                                            <td><?php echo htmlspecialchars($res['titulo']); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Autor:</strong></td>
                                            <td><?php echo htmlspecialchars($res['autor'] ?: 'Não informado'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Editora:</strong></td>
                                            <td><?php echo htmlspecialchars($res['editora'] ?: 'Não informada'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Edição:</strong></td>
                                            <td><?php echo htmlspecialchars($res['edicao'] ?: '1ª'); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Ano:</strong></td>
                                            <td><?php echo $res['ano_publicacao'] ?: '-'; ?></td>
                                        </tr>
                                        <?php if ($res['isbn']): ?>
                                        <tr>
                                            <td><strong>ISBN:</strong></td>
                                            <td><?php echo $res['isbn']; ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                                <div class="col-md-6">
                                    <table class="table table-sm table-borderless">
                                        <tr>
                                            <td width="130"><strong>Data Reserva:</strong></td>
                                            <td><?php echo formatarData($res['data_reserva']); ?></td>
                                        </tr>
                                        <tr>
                                            <td><strong>Validade:</strong></td>
                                            <td class="<?php echo getCorDataValidade($res['data_validade']); ?>">
                                                <?php echo formatarData($res['data_validade']); ?>
                                                <small>(<?php echo calcularDiasRestantes($res['data_validade']); ?>)</small>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td><strong>Localização:</strong></td>
                                            <td><?php echo $res['localizacao'] ?: 'Biblioteca Central'; ?></td>
                                        </tr>
                                        <?php if ($res['bibliotecario_nome']): ?>
                                        <tr>
                                            <td><strong>Bibliotecário:</strong></td>
                                            <td><?php echo htmlspecialchars($res['bibliotecario_nome']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                        <?php if ($res['observacoes']): ?>
                                        <tr>
                                            <td><strong>Observações:</strong></td>
                                            <td><?php echo htmlspecialchars($res['observacoes']); ?></td>
                                        </tr>
                                        <?php endif; ?>
                                    </table>
                                </div>
                            </div>
                            
                            <?php if (!empty($res['sinopse'])): ?>
                            <div class="mt-3">
                                <strong>Sinopse:</strong>
                                <div class="mt-1 text-muted small">
                                    <?php echo nl2br(htmlspecialchars(substr($res['sinopse'], 0, 200))); ?>
                                    <?php if (strlen($res['sinopse']) > 200): ?>...<?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="reserva-footer">
                    <div>
                        <?php if ($is_expirada): ?>
                            <span class="text-danger"><i class="fas fa-exclamation-triangle"></i> Reserva expirada. Faça uma nova reserva se desejar.</span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php if ($pode_cancelar): ?>
                            <button class="btn-cancelar-reserva" onclick="cancelarReserva(<?php echo $res['id']; ?>)">
                                <i class="fas fa-times"></i> Cancelar Reserva
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Botão de ajuda
    const btnAjuda = document.getElementById('btnAjuda');
    const modalAjuda = document.getElementById('modalAjuda');
    const closeAjuda = document.getElementById('closeAjuda');
    
    btnAjuda.addEventListener('click', function() { modalAjuda.classList.add('show'); });
    closeAjuda.addEventListener('click', function() { modalAjuda.classList.remove('show'); });
    modalAjuda.addEventListener('click', function(e) { if (e.target === modalAjuda) modalAjuda.classList.remove('show'); });
    
    // Função para expandir/colapsar reserva
    function toggleReserva(id) {
        const body = document.getElementById('reserva-body-' + id);
        const icon = document.getElementById('toggle-icon-' + id);
        
        body.classList.toggle('show');
        icon.classList.toggle('rotated');
    }
    
    // Função para cancelar reserva
    function cancelarReserva(id) {
        if (confirm('Tem certeza que deseja cancelar esta reserva?')) {
            $.ajax({
                url: 'cancelar_reserva.php',
                method: 'POST',
                data: { id: id },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('Reserva cancelada com sucesso!');
                        location.reload();
                    } else {
                        alert('Erro: ' + response.message);
                    }
                },
                error: function() {
                    alert('Erro ao cancelar reserva. Tente novamente.');
                }
            });
        }
    }
    
    // Auto-submit ao pressionar Enter na busca
    document.querySelector('input[name="busca"]')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            this.form.submit();
        }
    });
    
    // Expandir reserva específica via URL
    const urlParams = new URLSearchParams(window.location.search);
    const resId = urlParams.get('res');
    if (resId) {
        const body = document.getElementById('reserva-body-' + resId);
        const icon = document.getElementById('toggle-icon-' + resId);
        if (body) {
            body.classList.add('show');
            icon.classList.add('rotated');
            document.getElementById('reserva-' + resId)?.scrollIntoView({ behavior: 'smooth' });
        }
    }
</script>
</body>
</html>