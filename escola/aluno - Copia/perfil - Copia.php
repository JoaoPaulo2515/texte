<?php
// escola/aluno/perfil.php - Perfil do Aluno

require_once __DIR__ . '/../../config/database.php';
session_start();

// Verificar se o usuário está logado como aluno
if (!isset($_SESSION['aluno_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$aluno_id = $_SESSION['aluno_id'];
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno com informações completas
$sql_aluno = "SELECT e.*, 
                     u.email, 
                     u.telefone as user_telefone,
                     es.nome as escola_nome,
                     es.logo as escola_logo,
                     es.endereco as escola_endereco,
                     es.telefone as escola_telefone
              FROM estudantes e
              LEFT JOIN usuarios u ON e.usuario_id = u.id
              LEFT JOIN escolas es ON e.escola_id = es.id
              WHERE e.id = :aluno_id AND e.escola_id = :escola_id";
$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([
    ':aluno_id' => $aluno_id,
    ':escola_id' => $escola_id
]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: ../login.php');
    exit;
}

// Buscar turma atual do aluno
$sql_turma = "SELECT t.id, t.nome, t.ano, t.sala, t.turno,
                     GROUP_CONCAT(DISTINCT d.nome SEPARATOR ', ') as disciplinas
              FROM turmas t
              JOIN matriculas m ON m.turma_id = t.id
              LEFT JOIN professor_disciplina_turma pdt ON pdt.turma_id = t.id
              LEFT JOIN disciplinas d ON d.id = pdt.disciplina_id
              WHERE m.estudante_id = :aluno_id 
              AND m.status = 'ativa'
              AND t.status = 'ativa'
              GROUP BY t.id
              LIMIT 1";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':aluno_id' => $aluno_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Buscar ano letivo atual
$sql_ano_letivo = "SELECT id, ano, data_inicio, data_fim 
                   FROM anos_letivos 
                   WHERE escola_id = :escola_id AND status = 'ativo' 
                   LIMIT 1";
$stmt_ano_letivo = $conn->prepare($sql_ano_letivo);
$stmt_ano_letivo->execute([':escola_id' => $escola_id]);
$ano_letivo = $stmt_ano_letivo->fetch(PDO::FETCH_ASSOC);

// Buscar responsáveis do aluno
$sql_responsaveis = "SELECT r.nome, r.parentesco, r.telefone, r.email, r.bi
                     FROM responsaveis r
                     JOIN aluno_responsavel ar ON ar.responsavel_id = r.id
                     WHERE ar.aluno_id = :aluno_id";
$stmt_responsaveis = $conn->prepare($sql_responsaveis);
$stmt_responsaveis->execute([':aluno_id' => $aluno_id]);
$responsaveis = $stmt_responsaveis->fetchAll(PDO::FETCH_ASSOC);

// Buscar estatísticas
$sql_stats = "SELECT 
                COUNT(DISTINCT m.id) as total_matriculas,
                COUNT(DISTINCT a.id) as total_ocorrencias,
                COUNT(DISTINCT p.id) as total_pagamentos,
                SUM(p.valor) as total_pago,
                COUNT(DISTINCT t.id) as total_tarefas,
                AVG(CASE WHEN r.nota IS NOT NULL THEN r.nota END) as media_notas
              FROM estudantes e
              LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
              LEFT JOIN ocorrencias a ON a.aluno_id = e.id
              LEFT JOIN pagamentos p ON p.assinatura_id = e.id AND p.status = 'confirmado'
              LEFT JOIN tarefas t ON t.turma_id = m.turma_id
              LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = e.id
              WHERE e.id = :aluno_id";
$stmt_stats = $conn->prepare($sql_stats);
$stmt_stats->execute([':aluno_id' => $aluno_id]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Buscar últimas tarefas do aluno
$sql_ultimas_tarefas = "SELECT t.id, t.titulo, t.data_entrega, 
                               d.nome as disciplina_nome,
                               r.status as resposta_status, r.nota
                        FROM tarefas t
                        JOIN disciplinas d ON d.id = t.disciplina_id
                        JOIN turmas tur ON tur.id = t.turma_id
                        JOIN matriculas m ON m.turma_id = tur.id
                        LEFT JOIN tarefas_respostas r ON r.tarefa_id = t.id AND r.aluno_id = m.estudante_id
                        WHERE m.estudante_id = :aluno_id 
                        AND m.status = 'ativa'
                        ORDER BY t.data_entrega DESC
                        LIMIT 5";
$stmt_ultimas_tarefas = $conn->prepare($sql_ultimas_tarefas);
$stmt_ultimas_tarefas->execute([':aluno_id' => $aluno_id]);
$ultimas_tarefas = $stmt_ultimas_tarefas->fetchAll(PDO::FETCH_ASSOC);

// Função para formatar data
function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

// Função para calcular idade
function calcularIdade($data_nascimento) {
    if (empty($data_nascimento)) return '-';
    $idade = date_diff(date_create($data_nascimento), date_create('today'));
    return $idade->y . ' anos';
}

// Função para formatar moeda
function formatarMoeda($valor) {
    if (empty($valor)) return '0,00 Kz';
    return number_format($valor, 2, ',', '.') . ' Kz';
}

include 'includes/menu_aluno.php';
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meu Perfil | Área do Aluno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
        }
        
        .profile-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            border-radius: 15px;
            padding: 30px;
            color: white;
            margin-bottom: 30px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid white;
            object-fit: cover;
        }
        
        .info-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            height: 100%;
        }
        
        .info-card h5 {
            color: #006B3E;
            border-bottom: 2px solid #e0e0e0;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .info-row {
            margin-bottom: 15px;
        }
        
        .info-label {
            font-weight: bold;
            color: #666;
            display: inline-block;
            width: 140px;
        }
        
        .info-value {
            color: #333;
        }
        
        .stat-box {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s;
            height: 100%;
        }
        
        .stat-box:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
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
            .menu-toggle {
                display: block;
            }
        }
        
        .badge-status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .btn-edit {
            background: #006B3E;
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .btn-edit:hover {
            background: #004d2e;
            color: white;
            transform: translateY(-2px);
        }
        
        .tarefa-item {
            transition: all 0.3s;
        }
        .tarefa-item:hover {
            background: #f8f9fa;
            transform: translateX(5px);
        }
        
        @media print {
            .no-print {
                display: none;
            }
            .profile-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    <?php include 'includes/menu_aluno.php'; ?>
    
    <div class="main-content">
        <!-- Cabeçalho do Perfil -->
        <div class="profile-header">
            <div class="row align-items-center">
                <div class="col-md-2 text-center">
                    <?php if (!empty($aluno['foto'])): ?>
                        <img src="<?php echo $aluno['foto']; ?>" class="profile-avatar" alt="Foto do Aluno">
                    <?php else: ?>
                        <div class="profile-avatar bg-light d-flex align-items-center justify-content-center mx-auto">
                            <i class="fas fa-user-graduate fa-4x text-success"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-7">
                    <h2><?php echo htmlspecialchars($aluno['nome']); ?></h2>
                    <p class="mb-1"><i class="fas fa-id-card"></i> Matrícula: <?php echo $aluno['matricula']; ?></p>
                    <p class="mb-1"><i class="fas fa-graduation-cap"></i> Turma: <?php echo $turma['ano'] . 'ª ' . ($turma['nome'] ?? 'Não atribuída'); ?></p>
                    <p><i class="fas fa-calendar-alt"></i> Status: 
                        <span class="badge-status" style="background: <?php echo $aluno['status'] == 'ativo' ? '#28a745' : '#dc3545'; ?>; color: white;">
                            <?php echo ucfirst($aluno['status']); ?>
                        </span>
                    </p>
                </div>
                <div class="col-md-3 text-md-end">
                    <button class="btn-edit" onclick="editProfile()">
                        <i class="fas fa-edit"></i> Editar Perfil
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Estatísticas Rápidas -->
        <div class="row mb-4">
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-box">
                    <i class="fas fa-book fa-2x text-primary mb-2"></i>
                    <div class="stat-number"><?php echo $stats['total_matriculas'] ?? 0; ?></div>
                    <div>Matrículas</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-box">
                    <i class="fas fa-tasks fa-2x text-info mb-2"></i>
                    <div class="stat-number"><?php echo $stats['total_tarefas'] ?? 0; ?></div>
                    <div>Tarefas</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-box">
                    <i class="fas fa-credit-card fa-2x text-success mb-2"></i>
                    <div class="stat-number"><?php echo $stats['total_pagamentos'] ?? 0; ?></div>
                    <div>Pagamentos</div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="stat-box">
                    <i class="fas fa-chart-line fa-2x text-warning mb-2"></i>
                    <div class="stat-number"><?php echo number_format($stats['media_notas'] ?? 0, 1); ?></div>
                    <div>Média de Notas</div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <!-- Informações Pessoais -->
            <div class="col-md-6">
                <div class="info-card">
                    <h5><i class="fas fa-user"></i> Informações Pessoais</h5>
                    <div class="info-row">
                        <span class="info-label">Nome Completo:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['nome']); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Data de Nascimento:</span>
                        <span class="info-value"><?php echo formatarData($aluno['data_nascimento']); ?> (<?php echo calcularIdade($aluno['data_nascimento']); ?>)</span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Gênero:</span>
                        <span class="info-value"><?php echo ucfirst($aluno['genero'] ?? 'Não informado'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nacionalidade:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['nacionalidade'] ?? 'Angolana'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">BI / Passaporte:</span>
                        <span class="info-value"><?php echo $aluno['bi'] ?? 'Não informado'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Nº de Aluno:</span>
                        <span class="info-value"><?php echo $aluno['matricula']; ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Informações de Contato -->
            <div class="col-md-6">
                <div class="info-card">
                    <h5><i class="fas fa-address-book"></i> Contato</h5>
                    <div class="info-row">
                        <span class="info-label">Telefone:</span>
                        <span class="info-value"><?php echo $aluno['telefone'] ?? $aluno['user_telefone'] ?? 'Não informado'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Email:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['email'] ?? 'Não informado'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Endereço:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['endereco'] ?? 'Não informado'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Cidade/Província:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['cidade'] ?? 'Luanda'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Informações da Escola -->
            <div class="col-md-6">
                <div class="info-card">
                    <h5><i class="fas fa-school"></i> Informações da Escola</h5>
                    <div class="info-row">
                        <span class="info-label">Escola:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'Não informado'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Ano Letivo:</span>
                        <span class="info-value"><?php echo $ano_letivo['ano'] ?? date('Y'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Período Letivo:</span>
                        <span class="info-value">
                            <?php 
                            if ($ano_letivo['data_inicio'] && $ano_letivo['data_fim']) {
                                echo formatarData($ano_letivo['data_inicio']) . ' a ' . formatarData($ano_letivo['data_fim']);
                            } else {
                                echo 'Em andamento';
                            }
                            ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Telefone Escola:</span>
                        <span class="info-value"><?php echo $aluno['escola_telefone'] ?? 'Não informado'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Endereço Escola:</span>
                        <span class="info-value"><?php echo htmlspecialchars($aluno['escola_endereco'] ?? 'Não informado'); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Informações Acadêmicas -->
            <div class="col-md-6">
                <div class="info-card">
                    <h5><i class="fas fa-graduation-cap"></i> Informações Acadêmicas</h5>
                    <div class="info-row">
                        <span class="info-label">Turma Atual:</span>
                        <span class="info-value"><?php echo $turma['ano'] . 'ª ' . ($turma['nome'] ?? 'Não atribuída'); ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Sala:</span>
                        <span class="info-value"><?php echo $turma['sala'] ?? 'Não informada'; ?></span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Turno:</span>
                        <span class="info-value"><?php echo $turma['turno'] ?? 'Não informado'; ?></span>
                    </div>
                    <?php if (!empty($turma['disciplinas'])): ?>
                    <div class="info-row">
                        <span class="info-label">Disciplinas:</span>
                        <span class="info-value"><?php echo htmlspecialchars($turma['disciplinas']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Últimas Tarefas -->
            <div class="col-md-12">
                <div class="info-card">
                    <h5><i class="fas fa-tasks"></i> Últimas Tarefas</h5>
                    <?php if (empty($ultimas_tarefas)): ?>
                        <p class="text-muted text-center">Nenhuma tarefa encontrada.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Tarefa</th>
                                        <th>Disciplina</th>
                                        <th>Data Entrega</th>
                                        <th>Status</th>
                                        <th>Nota</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ultimas_tarefas as $tarefa): ?>
                                    <tr class="tarefa-item">
                                        <td><?php echo htmlspecialchars($tarefa['titulo']); ?></td>
                                        <td>
                                            <i class="fas fa-book" style="color: <?php echo $tarefa['disciplina_cor'] ?? '#006B3E'; ?>"></i>
                                            <?php echo htmlspecialchars($tarefa['disciplina_nome']); ?>
                                        </td>
                                        <td><?php echo formatarData($tarefa['data_entrega']); ?></td>
                                        <td>
                                            <?php if ($tarefa['resposta_status'] == 'corrigido'): ?>
                                                <span class="badge bg-success">Corrigida</span>
                                            <?php elseif ($tarefa['resposta_status'] == 'entregue'): ?>
                                                <span class="badge bg-warning">Entregue</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Pendente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($tarefa['nota'] !== null): ?>
                                                <strong><?php echo number_format($tarefa['nota'], 1); ?></strong>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Responsáveis -->
            <div class="col-md-12">
                <div class="info-card">
                    <h5><i class="fas fa-users"></i> Responsáveis</h5>
                    <?php if (empty($responsaveis)): ?>
                        <p class="text-muted text-center">Nenhum responsável cadastrado.</p>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($responsaveis as $responsavel): ?>
                            <div class="col-md-6 mb-3">
                                <div class="p-3 border rounded">
                                    <strong><i class="fas fa-user"></i> <?php echo htmlspecialchars($responsavel['nome']); ?></strong>
                                    <small class="text-muted">(<?php echo $responsavel['parentesco']; ?>)</small>
                                    <div class="mt-2">
                                        <div><i class="fas fa-phone"></i> <?php echo $responsavel['telefone']; ?></div>
                                        <div><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($responsavel['email']); ?></div>
                                        <?php if ($responsavel['cpf']): ?>
                                        <div><i class="fas fa-id-card"></i> CPF: <?php echo $responsavel['cpf']; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Editar Perfil -->
    <div class="modal fade" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Editar Perfil</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="editProfileForm" method="POST" action="update_profile.php">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Telefone</label>
                                <input type="tel" class="form-control" name="telefone" value="<?php echo $aluno['telefone'] ?? ''; ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo $aluno['email'] ?? ''; ?>">
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Endereço</label>
                                <textarea class="form-control" name="endereco" rows="2"><?php echo $aluno['endereco'] ?? ''; ?></textarea>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Senha (deixe em branco para manter)</label>
                                <input type="password" class="form-control" name="senha" id="senha">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Confirmar Senha</label>
                                <input type="password" class="form-control" name="confirmar_senha" id="confirmar_senha">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Salvar Alterações</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle menu mobile
        document.getElementById('menuToggle')?.addEventListener('click', function() {
            document.querySelector('.sidebar')?.classList.toggle('active');
            document.querySelector('.main-content')?.classList.toggle('active');
        });
        
        // Função para editar perfil
        function editProfile() {
            new bootstrap.Modal(document.getElementById('editProfileModal')).show();
        }
        
        // Validar senha no formulário
        $('#editProfileForm').on('submit', function(e) {
            let senha = $('#senha').val();
            let confirmar = $('#confirmar_senha').val();
            
            if (senha !== confirmar) {
                e.preventDefault();
                alert('As senhas não coincidem!');
                return false;
            }
            
            return true;
        });
    </script>
</body>
</html>