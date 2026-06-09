<?php
// escola/professor/minhas_turmas.php - Minhas Turmas

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// ============================================
// BUSCAR TURMAS DO PROFESSOR
// ============================================
$sql_turmas = "
    SELECT DISTINCT 
        t.id,
        t.nome,
        t.ano,
        t.turno,
        t.sala,
        t.capacidade,
        (SELECT COUNT(*) FROM matriculas m WHERE m.turma_id = t.id AND m.status = 'ativa') as total_alunos
    FROM professor_disciplina_turma pdt
    INNER JOIN turmas t ON t.id = pdt.turma_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY t.ano DESC, t.nome
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':professor_id' => $professor_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DO PROFESSOR
// ============================================
$sql_disciplinas = "
    SELECT DISTINCT 
        d.id,
        d.nome,
        d.codigo,
        d.carga_horaria
    FROM professor_disciplina_turma pdt
    INNER JOIN disciplinas d ON d.id = pdt.disciplina_id
    WHERE pdt.professor_id = :professor_id
    ORDER BY d.nome
";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute([':professor_id' => $professor_id]);
$disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DETALHES DA TURMA SELECIONADA
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$turma_detalhes = null;
$alunos_turma = [];

if ($turma_id > 0) {
    // Buscar detalhes da turma
    $sql_turma_detalhes = "
        SELECT 
            t.*,
            COUNT(DISTINCT m.estudante_id) as total_alunos_matriculados
        FROM turmas t
        LEFT JOIN matriculas m ON m.turma_id = t.id AND m.status = 'ativa'
        WHERE t.id = :turma_id AND t.escola_id = :escola_id
        GROUP BY t.id
    ";
    $stmt_detalhes = $conn->prepare($sql_turma_detalhes);
    $stmt_detalhes->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
    $turma_detalhes = $stmt_detalhes->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma (CORRIGIDO: usando estudantes em vez de alunos)
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.email,
            e.telefone,
            e.foto,
            e.data_nascimento,
            m.data_matricula
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function getProgressBar($percentual) {
    $cor = $percentual >= 75 ? 'success' : ($percentual >= 50 ? 'warning' : 'danger');
    return '<div class="progress" style="height: 8px;">
                <div class="progress-bar bg-' . $cor . '" style="width: ' . $percentual . '%;"></div>
            </div>';
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Turmas | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            transition: all 0.3s;
            z-index: 1000;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        
        .sidebar-header {
            padding: 25px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .sidebar-header .logo {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .sidebar-header h3 {
            font-size: 1.3em;
            margin-bottom: 5px;
        }
        
        .sidebar-header p {
            font-size: 0.8em;
            opacity: 0.8;
        }
        
        .nav-menu {
            list-style: none;
            padding: 20px 0;
            margin: 0;
        }
        
        .nav-item {
            margin-bottom: 5px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
            gap: 12px;
            font-size: 0.95em;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .nav-link.active {
            background: rgba(255,255,255,0.15);
            color: white;
            border-left: 4px solid #FFD700;
        }
        
        .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        .has-submenu {
            position: relative;
        }
        
        .has-submenu > .nav-link {
            cursor: pointer;
        }
        
        .has-submenu > .nav-link::after {
            content: '\f078';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            margin-left: auto;
            transition: transform 0.3s;
        }
        
        .has-submenu.open > .nav-link::after {
            transform: rotate(180deg);
        }
        
        .nav-submenu {
            list-style: none;
            padding-left: 55px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .has-submenu.open .nav-submenu {
            max-height: 500px;
        }
        
        .nav-submenu .nav-link {
            padding: 10px 25px;
            font-size: 0.85em;
        }
        
        .nav-submenu .nav-link i {
            font-size: 0.8em;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            background: #f5f7fb;
            min-height: 100vh;
        }
        
        .top-bar {
            background: white;
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .welcome-text h2 {
            font-size: 1.5em;
            margin-bottom: 5px;
        }
        
        .welcome-text p {
            color: #666;
            margin: 0;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.5em;
        }
        
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #006B3E;
            color: white;
            border: none;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                left: -280px;
            }
            .sidebar.open {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
            .menu-toggle {
                display: block;
            }
            .top-bar {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
        }
        
        /* Estilos originais da página */
        .turma-card {
            border-radius: 15px;
            transition: transform 0.3s, box-shadow 0.3s;
            cursor: pointer;
            margin-bottom: 20px;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .turma-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        }
        .turma-card.selected {
            border: 2px solid #006B3E;
            background: #f8f9fa;
        }
        .turma-header {
            background: linear-gradient(135deg, #006B3E 0%, #008B4E 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 15px;
        }
        .turma-body {
            padding: 15px;
        }
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #006B3E;
        }
        .aluno-foto {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 50%;
        }
        .table-alunos th {
            background: #006B3E;
            color: white;
        }
        .badge-turno {
            background: #17a2b8;
            color: white;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
        }
        .btn-ver-chamada {
            background: #17a2b8;
            color: white;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 12px;
        }
        .btn-ver-chamada:hover {
            background: #138496;
            color: white;
        }
        .btn-lancar-notas {
            background: #ffc107;
            color: #212529;
            border-radius: 20px;
            padding: 5px 15px;
            font-size: 12px;
        }
        .btn-lancar-notas:hover {
            background: #e0a800;
            color: #212529;
        }
        .disciplina-badge {
            background: #e9ecef;
            color: #495057;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin: 3px;
            display: inline-block;
        }
        .btn-voltar {
            background: #6c757d;
            color: white;
            border-radius: 25px;
            padding: 8px 20px;
            text-decoration: none;
            border: none;
        }
        .btn-voltar:hover {
            background: #5a6268;
            color: white;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>
    
    <!-- Sidebar com Menu Completo -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <h3>SIGE Angola</h3>
            <p>Área do Professor</p>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </a>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-graduation-cap"></i> Menu Acadêmico
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="minhas_turmas.php" class="nav-link active"><i class="fas fa-chalkboard"></i> Minhas Turmas</a></li>
                    <li class="nav-item"><a href="lancar_notas.php" class="nav-link"><i class="fas fa-book-open"></i> Lançar Notas</a></li>
                    <li class="nav-item"><a href="registrar_chamada.php" class="nav-link"><i class="fas fa-clipboard-list"></i> Registrar Chamada</a></li>
                    <li class="nav-item"><a href="atividades.php" class="nav-link"><i class="fas fa-tasks"></i> Atividades</a></li>
                    <li class="nav-item"><a href="meus_alunos.php" class="nav-link"><i class="fas fa-users"></i> Meus Alunos</a></li>
                </ul>
            </li>
            
            <li class="nav-item">
                <a href="meu_horario.php" class="nav-link">
                    <i class="fas fa-calendar-week"></i> Meu Horário
                </a>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-chart-line"></i> Relatórios
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="relatorios/index.php" class="nav-link"><i class="fas fa-home"></i> Index</a></li>
                    <li class="nav-item"><a href="relatorios/mini_pautas.php" class="nav-link"><i class="fas fa-file-alt"></i> Mini Pautas</a></li>
                    <li class="nav-item"><a href="relatorios/pautas_gerais.php" class="nav-link"><i class="fas fa-file-pdf"></i> Pautas Gerais</a></li>
                    <li class="nav-item"><a href="relatorios/estatistica_turma.php" class="nav-link"><i class="fas fa-chart-bar"></i> Estatística por Turma</a></li>
                    <li class="nav-item"><a href="relatorios/estatistica_disciplina.php" class="nav-link"><i class="fas fa-chart-pie"></i> Estatística por Disciplina</a></li>
                </ul>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-calendar-alt"></i> Agenda
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meus_horarios.php" class="nav-link"><i class="fas fa-clock"></i> Meus Horários</a></li>
                    <li class="nav-item"><a href="calendario_provas.php" class="nav-link"><i class="fas fa-calendar-check"></i> Calendário de Provas</a></li>
                </ul>
            </li>
            
            <li class="nav-item has-submenu">
                <a href="#" class="nav-link" onclick="toggleSubmenu(event)">
                    <i class="fas fa-coins"></i> Financeiro
                </a>
                <ul class="nav-submenu">
                    <li class="nav-item"><a href="meu_perfil.php" class="nav-link"><i class="fas fa-user-circle"></i> Meu Perfil</a></li>
                    <li class="nav-item"><a href="meu_salario.php" class="nav-link"><i class="fas fa-money-bill-wave"></i> Meu Salário</a></li>
                    <li class="nav-item"><a href="dividas_pagar.php" class="nav-link"><i class="fas fa-hand-holding-usd"></i> Dívidas a Pagar</a></li>
                    <li class="nav-item"><a href="dividas_receber.php" class="nav-link"><i class="fas fa-hand-holding-heart"></i> Dívidas a Receber</a></li>
                    <li class="nav-item"><a href="solicitar_vale.php" class="nav-link"><i class="fas fa-file-invoice-dollar"></i> Solicitar Vale</a></li>
                    <li class="nav-item"><a href="solicitar_ferias.php" class="nav-link"><i class="fas fa-umbrella-beach"></i> Solicitar Férias</a></li>
                </ul>
            </li>
            
            <li class="nav-item">
                <a href="conselho_nota.php" class="nav-link">
                    <i class="fas fa-chalkboard-user"></i> Conselho de Nota
                </a>
            </li>
            
            <li class="nav-item">
                <a href="chamada.php" class="nav-link">
                    <i class="fas fa-clipboard-list"></i> Chamada
                </a>
            </li>
            
            <li class="nav-item">
                <a href="lancamento_nota.php" class="nav-link">
                    <i class="fas fa-edit"></i> Lançamento de Nota
                </a>
            </li>
            
            <li class="nav-item">
                <a href="biblioteca.php" class="nav-link">
                    <i class="fas fa-book"></i> Biblioteca
                </a>
            </li>
            
            <li class="nav-item">
                <a href="proposta_prova.php" class="nav-link">
                    <i class="fas fa-file-alt"></i> Proposta de Prova
                </a>
            </li>
            
            <li class="nav-item">
                <a href="../../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Sair
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-chalkboard-user"></i> Minhas Turmas</h2>
            <a href="dashboard.php" class="btn-voltar btn">
                <i class="fas fa-arrow-left"></i> Voltar ao Dashboard
            </a>
        </div>
        
        <?php if (empty($turmas)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Você não está vinculado a nenhuma turma ainda.
                <br>Entre em contato com a coordenação da escola.
            </div>
        <?php else: ?>
        
        <!-- Lista de Turmas -->
        <div class="row mb-4">
            <?php foreach ($turmas as $turma): 
                $percentual = $turma['capacidade'] > 0 ? round(($turma['total_alunos'] / $turma['capacidade']) * 100, 1) : 0;
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card turma-card <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>" onclick="window.location.href='?turma_id=<?php echo $turma['id']; ?>'">
                    <div class="turma-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-0"><?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?></h5>
                                <small><i class="fas fa-clock"></i> <?php echo ucfirst($turma['turno']); ?></small>
                            </div>
                            <div>
                                <span class="badge-turno">
                                    <i class="fas fa-users"></i> <?php echo $turma['total_alunos']; ?>/<?php echo $turma['capacidade'] ?? '∞'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="turma-body">
                        <p class="mb-2">
                            <i class="fas fa-door-open"></i> Sala: <?php echo $turma['sala'] ?: 'Não definida'; ?>
                        </p>
                        <?php if ($turma['capacidade'] > 0): ?>
                        <div class="mb-2">
                            <small>Ocupação: <?php echo $percentual; ?>%</small>
                            <?php echo getProgressBar($percentual); ?>
                        </div>
                        <?php endif; ?>
                        <div class="text-center mt-3">
                            <button class="btn btn-ver-chamada" onclick="event.stopPropagation(); window.location.href='registrar_chamada.php?turma_id=<?php echo $turma['id']; ?>'">
                                <i class="fas fa-clipboard-list"></i> Chamada
                            </button>
                            <button class="btn btn-lancar-notas" onclick="event.stopPropagation(); window.location.href='lancar_notas.php?turma_id=<?php echo $turma['id']; ?>'">
                                <i class="fas fa-graduation-cap"></i> Notas
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Detalhes da Turma Selecionada -->
        <?php if ($turma_detalhes && !empty($alunos_turma)): ?>
        <div class="card">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0">
                    <i class="fas fa-users"></i> Alunos da Turma - <?php echo $turma_detalhes['ano'] . 'ª ' . htmlspecialchars($turma_detalhes['nome']); ?>
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover table-alunos">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Foto</th>
                                <th>Nome do Aluno</th>
                                <th>Matrícula</th>
                                <th>Data Nascimento</th>
                                <th>Contato</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alunos_turma as $index => $aluno): ?>
                            <tr>
                                <td class="text-center"><?php echo $index + 1; ?></td>
                                <td>
                                    <?php 
                                    $foto_path = '../../uploads/alunos/fotos/' . $aluno['foto'];
                                    if (!empty($aluno['foto']) && file_exists($foto_path)): ?>
                                        <img src="<?php echo $foto_path; ?>" class="aluno-foto">
                                    <?php else: ?>
                                        <img src="../../assets/images/avatar-padrao.png" class="aluno-foto">
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                 </td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?> </td>
                                <td><?php echo formatarData($aluno['data_nascimento']); ?> </td>
                                <td>
                                    <?php if ($aluno['email']): ?>
                                        <small><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($aluno['email']); ?></small><br>
                                    <?php endif; ?>
                                    <?php if ($aluno['telefone']): ?>
                                        <small><i class="fas fa-phone"></i> <?php echo htmlspecialchars($aluno['telefone']); ?></small>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <a href="ver_aluno.php?id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-eye"></i> Ver
                                    </a>
                                    <a href="lancar_notas.php?turma_id=<?php echo $turma_id; ?>&aluno_id=<?php echo $aluno['id']; ?>" class="btn btn-sm btn-warning">
                                        <i class="fas fa-graduation-cap"></i> Notas
                                    </a>
                                </td>
                             </tr>
                            <?php endforeach; ?>
                        </tbody>
                     </table>
                </div>
            </div>
        </div>
        <?php elseif ($turma_detalhes): ?>
        <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle"></i> Nenhum aluno encontrado nesta turma.
        </div>
        <?php endif; ?>
        
        <!-- Disciplinas do Professor -->
        <div class="card mt-4">
            <div class="card-header" style="background: #006B3E; color: white;">
                <h5 class="mb-0">
                    <i class="fas fa-book"></i> Minhas Disciplinas
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($disciplinas)): ?>
                    <p class="text-muted text-center">Nenhuma disciplina atribuída.</p>
                <?php else: ?>
                    <div class="d-flex flex-wrap">
                        <?php foreach ($disciplinas as $disciplina): ?>
                        <span class="disciplina-badge">
                            <i class="fas fa-book-open"></i> <?php echo htmlspecialchars($disciplina['nome']); ?>
                            <?php if ($disciplina['carga_horaria']): ?>
                            <small>(<?php echo $disciplina['carga_horaria']; ?>h)</small>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle submenu
        function toggleSubmenu(event) {
            if (event) event.preventDefault();
            const parent = event.currentTarget.closest('.has-submenu');
            if (parent) {
                parent.classList.toggle('open');
            }
        }
        
        // Toggle sidebar mobile
        document.getElementById('menuToggle').addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('open');
        });
        
        // Fechar sidebar ao clicar em link (mobile)
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) {
                    document.getElementById('sidebar').classList.remove('open');
                }
            });
        });
        
        // Manter submenus abertos baseado na URL atual
        const currentUrl = window.location.pathname;
        document.querySelectorAll('.nav-submenu .nav-link').forEach(link => {
            if (currentUrl.includes(link.getAttribute('href'))) {
                const parent = link.closest('.has-submenu');
                if (parent) parent.classList.add('open');
            }
        });
    </script>
</body>
</html>