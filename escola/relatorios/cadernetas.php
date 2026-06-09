<?php
// escola/relatorios/cadernetas.php - Caderneta de Notas dos Alunos

require_once __DIR__ . '/../../config/database.php';
session_start();

// ============================================
// VERIFICAÇÃO DE AUTENTICAÇÃO
// ============================================
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../login.php');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];
$usuario_id = $_SESSION['usuario_id'];
$escola_nome = $_SESSION['usuario_nome'] ?? 'Escola';

// ============================================
// VARIÁVEIS DE FILTRO
// ============================================
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$acao = isset($_GET['acao']) ? $_GET['acao'] : 'listar';

// ============================================
// BUSCAR ANOS LETIVOS
// ============================================
$sql_anos = "SELECT id, ano FROM ano_letivo WHERE escola_id = :escola_id ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute([':escola_id' => $escola_id]);
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

if (empty($anos_letivos)) {
    $anos_letivos = [['id' => 1, 'ano' => date('Y')]];
}
if ($ano_letivo_id == 0) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// ============================================
// BUSCAR TURMAS DA ESCOLA
// ============================================
$sql_turmas = "SELECT id, nome, ano, turno FROM turmas WHERE escola_id = :escola_id ORDER BY ano, nome";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// BUSCAR DISCIPLINAS DA TURMA
// ============================================
$disciplinas_turma = [];
if ($turma_id > 0) {
    $sql_disciplinas = "SELECT DISTINCT d.id, d.nome, d.codigo
                        FROM disciplinas d
                        INNER JOIN professor_disciplina_turma pdt ON pdt.disciplina_id = d.id
                        WHERE pdt.turma_id = :turma_id
                        ORDER BY d.nome";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas_turma = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
}

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
function getStatusNota($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'text-secondary', 'icone' => 'fa-minus-circle'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'text-success', 'icone' => 'fa-check-circle'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'text-warning', 'icone' => 'fa-exclamation-triangle'];
    return ['texto' => 'Reprovado', 'classe' => 'text-danger', 'icone' => 'fa-times-circle'];
}

function isClasseExame($ano_turma) {
    $classes_exame = [6, 9, 12];
    return in_array($ano_turma, $classes_exame);
}

function isLinguagem($disciplina_nome) {
    $linguagens = ['Português', 'Inglês', 'Língua Portuguesa', 'Língua Inglesa', 'Portuguese', 'English'];
    $disciplina_lower = strtolower($disciplina_nome);
    foreach ($linguagens as $ling) {
        if (strpos($disciplina_lower, strtolower($ling)) !== false) {
            return true;
        }
    }
    return false;
}

// ============================================
// BUSCAR DADOS DA TURMA E ALUNOS
// ============================================
$turma_info = null;
$alunos = [];
$disciplina_info = null;
$notas_alunos = [];

if ($turma_id > 0) {
    // Buscar informações da turma
    $sql_turma = "SELECT id, nome, ano, turno FROM turmas WHERE id = :id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id 
                   AND m.status = 'ativa' 
                   AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    if ($disciplina_id > 0) {
        // Buscar informações da disciplina
        $sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
        $stmt_disciplina = $conn->prepare($sql_disciplina);
        $stmt_disciplina->execute([':id' => $disciplina_id]);
        $disciplina_info = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);
        
        $is_exame_classe = isClasseExame($turma_info['ano']);
        $is_linguagem = isLinguagem($disciplina_info['nome']);
        
        // Buscar notas dos alunos
        foreach ($alunos as $aluno) {
            $sql_notas = "SELECT mac, npt, exame_normal, exame_oral, exame_escrito, media_final
                         FROM notas 
                         WHERE estudante_id = :estudante_id 
                         AND disciplina_id = :disciplina_id 
                         AND bimestre = :trimestre
                         AND ano_letivo_id = :ano_letivo_id";
            $stmt_notas = $conn->prepare($sql_notas);
            $stmt_notas->execute([
                ':estudante_id' => $aluno['id'],
                ':disciplina_id' => $disciplina_id,
                ':trimestre' => $trimestre,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            $nota_data = $stmt_notas->fetch(PDO::FETCH_ASSOC);
            
            $mac = $nota_data ? (float)$nota_data['mac'] : null;
            $npt = $nota_data ? (float)$nota_data['npt'] : null;
            $exame_normal = $nota_data ? (float)$nota_data['exame_normal'] : null;
            $exame_oral = $nota_data ? (float)$nota_data['exame_oral'] : null;
            $exame_escrita = $nota_data ? (float)$nota_data['exame_escrito'] : null;
            $media_final = $nota_data ? (float)$nota_data['media_final'] : null;
            
            // Calcular média se necessário
            if ($media_final === null) {
                if ($is_exame_classe && $trimestre == 3) {
                    if ($is_linguagem) {
                        $valores = [];
                        if ($mac !== null) $valores[] = $mac;
                        if ($exame_oral !== null) $valores[] = $exame_oral;
                        if ($exame_escrita !== null) $valores[] = $exame_escrita;
                        $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                    } else {
                        $valores = [];
                        if ($mac !== null) $valores[] = $mac;
                        if ($exame_normal !== null) $valores[] = $exame_normal;
                        $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                    }
                } else {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($npt !== null) $valores[] = $npt;
                    $media_final = !empty($valores) ? round(array_sum($valores) / count($valores), 2) : null;
                }
            }
            
            $status = getStatusNota($media_final);
            
            $notas_alunos[] = [
                'aluno_id' => $aluno['id'],
                'nome' => $aluno['nome'],
                'matricula' => $aluno['matricula'],
                'genero' => $aluno['genero'],
                'mac' => $mac,
                'npt' => $npt,
                'exame_normal' => $exame_normal,
                'exame_oral' => $exame_oral,
                'exame_escrita' => $exame_escrita,
                'media_final' => $media_final,
                'status_texto' => $status['texto'],
                'status_classe' => $status['classe']
            ];
        }
    }
}

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Caderneta de Notas | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.4/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f0f2f5;
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
        
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        .caderneta-header {
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .table-caderneta th {
            background: #006B3E;
            color: white;
            text-align: center;
            vertical-align: middle;
        }
        
        .table-caderneta td {
            text-align: center;
            vertical-align: middle;
        }
        
        .nota-cell {
            font-weight: bold;
            font-size: 14px;
        }
        
        .btn-print, .btn-excel {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
        }
        
        .btn-excel {
            background: #28a745;
        }
        
        .btn-excel:hover { background: #1e7e34; }
        .btn-print:hover { background: #138496; }
        
        .disciplina-card {
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .disciplina-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .disciplina-card.active {
            background: #006B3E;
            color: white;
            border-color: #006B3E;
        }
        
        .disciplina-card.active .text-muted {
            color: rgba(255,255,255,0.8) !important;
        }
        
        @media print {
            .no-print {
                display: none !important;
            }
            .sidebar {
                display: none;
            }
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            .caderneta-header {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .table-caderneta th {
                background: #006B3E;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        .trimestre-btn {
            margin: 0 5px;
        }
        
        .trimestre-btn.active {
            background: #006B3E;
            color: white;
            border-color: #006B3E;
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-book"></i> Caderneta de Notas</h2>
            <div class="no-print">
                <?php if ($disciplina_id > 0 && !empty($notas_alunos)): ?>
                    <button onclick="exportarExcel()" class="btn btn-excel">
                        <i class="fas fa-file-excel"></i> Exportar Excel
                    </button>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-print">
                    <i class="fas fa-print"></i> Imprimir
                </button>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar no-print">
            <form method="GET" class="row align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Ano Letivo</label>
                    <select name="ano_letivo" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($anos_letivos as $ano): ?>
                        <option value="<?php echo $ano['id']; ?>" <?php echo $ano_letivo_id == $ano['id'] ? 'selected' : ''; ?>>
                            <?php echo $ano['ano']; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Turma</label>
                    <select name="turma_id" class="form-select" id="turma_id" onchange="this.form.submit()">
                        <option value="0">Selecione uma turma...</option>
                        <?php foreach ($turmas as $turma): ?>
                        <option value="<?php echo $turma['id']; ?>" <?php echo $turma_id == $turma['id'] ? 'selected' : ''; ?>>
                            <?php echo $turma['ano'] . 'ª - ' . $turma['nome'] . ' (' . ucfirst($turma['turno']) . ')'; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Trimestre</label>
                    <select name="trimestre" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $trimestre == 1 ? 'selected' : ''; ?>>1º Trimestre</option>
                        <option value="2" <?php echo $trimestre == 2 ? 'selected' : ''; ?>>2º Trimestre</option>
                        <option value="3" <?php echo $trimestre == 3 ? 'selected' : ''; ?>>3º Trimestre</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Seleção de Disciplina -->
        <?php if ($turma_id > 0 && !empty($disciplinas_turma)): ?>
        <div class="row mb-4 no-print">
            <div class="col-12">
                <label class="form-label fw-bold mb-2"><i class="fas fa-book"></i> Selecione a Disciplina</label>
                <div class="row">
                    <?php foreach ($disciplinas_turma as $disc): ?>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <a href="?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disc['id']; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>&trimestre=<?php echo $trimestre; ?>" 
                           class="btn btn-outline-primary w-100 disciplina-card <?php echo $disciplina_id == $disc['id'] ? 'active' : ''; ?>">
                            <i class="fas fa-book"></i> <?php echo htmlspecialchars($disc['nome']); ?>
                            <?php if ($disc['codigo']): ?>
                                <br><small class="text-muted"><?php echo $disc['codigo']; ?></small>
                            <?php endif; ?>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Caderneta -->
        <?php if ($turma_id > 0 && $disciplina_id > 0 && !empty($notas_alunos)): 
            $is_exame_classe = isClasseExame($turma_info['ano']);
            $is_linguagem = isLinguagem($disciplina_info['nome']);
        ?>
        
        <div class="caderneta-header">
            <div class="row">
                <div class="col-md-6">
                    <h4 class="mb-1"><?php echo htmlspecialchars($disciplina_info['nome']); ?></h4>
                    <p class="mb-0">
                        <i class="fas fa-school"></i> Turma: <?php echo $turma_info['ano'] . 'ª ' . $turma_info['nome']; ?> (<?php echo ucfirst($turma_info['turno']); ?>)<br>
                        <i class="fas fa-calendar-alt"></i> Ano Letivo: <?php echo $anos_letivos[array_search($ano_letivo_id, array_column($anos_letivos, 'id'))]['ano']; ?> - <?php echo $trimestre; ?>º Trimestre
                    </p>
                </div>
                <div class="col-md-6 text-end">
                    <?php if ($is_exame_classe && $trimestre == 3): ?>
                        <span class="badge bg-warning text-dark p-2">Classe de Exame</span>
                        <?php if ($is_linguagem): ?>
                            <span class="badge bg-info p-2">Disciplina de Língua</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-bordered table-caderneta" id="tabelaCaderneta">
                <thead>
                    <tr>
                        <th rowspan="2" width="5%">#</th>
                        <th rowspan="2" width="12%">Matrícula</th>
                        <th rowspan="2" width="25%">Aluno</th>
                        <th rowspan="2" width="8%">Gênero</th>
                        <th colspan="2">Avaliações</th>
                        <?php if ($is_exame_classe && $trimestre == 3): ?>
                            <th colspan="<?php echo $is_linguagem ? '2' : '1'; ?>">Exame</th>
                        <?php endif; ?>
                        <th rowspan="2" width="10%">Média Final</th>
                        <th rowspan="2" width="12%">Status</th>
                    </tr>
                    <tr>
                        <th width="8%">MAC</th>
                        <th width="8%">NPT</th>
                        <?php if ($is_exame_classe && $trimestre == 3): ?>
                            <?php if ($is_linguagem): ?>
                                <th width="8%">Exame Oral</th>
                                <th width="8%">Exame Escrito</th>
                            <?php else: ?>
                                <th width="8%">Exame Normal</th>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notas_alunos as $index => $aluno): ?>
                    <tr>
                        <td class="text-center"><?php echo $index + 1; ?></td>
                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                        <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                        <td class="text-center">
                            <?php if ($aluno['genero'] == 'masculino'): ?>
                                <i class="fas fa-mars text-primary"></i> M
                            <?php else: ?>
                                <i class="fas fa-venus text-danger"></i> F
                            <?php endif; ?>
                        </td>
                        <td class="nota-cell"><?php echo $aluno['mac'] !== null ? number_format($aluno['mac'], 1, ',', '.') : '---'; ?></td>
                        <td class="nota-cell"><?php echo $aluno['npt'] !== null ? number_format($aluno['npt'], 1, ',', '.') : '---'; ?></td>
                        <?php if ($is_exame_classe && $trimestre == 3): ?>
                            <?php if ($is_linguagem): ?>
                                <td class="nota-cell"><?php echo $aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 1, ',', '.') : '---'; ?></td>
                                <td class="nota-cell"><?php echo $aluno['exame_escrita'] !== null ? number_format($aluno['exame_escrita'], 1, ',', '.') : '---'; ?></td>
                            <?php else: ?>
                                <td class="nota-cell"><?php echo $aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 1, ',', '.') : '---'; ?></td>
                            <?php endif; ?>
                        <?php endif; ?>
                        <td class="nota-cell fw-bold">
                            <?php echo $aluno['media_final'] !== null ? number_format($aluno['media_final'], 1, ',', '.') : '---'; ?>
                        </td>
                        <td class="<?php echo $aluno['status_classe']; ?>">
                            <i class="fas <?php echo $aluno['status_texto'] == 'Aprovado' ? 'fa-check-circle' : ($aluno['status_texto'] == 'Exame' ? 'fa-exclamation-triangle' : ($aluno['status_texto'] == 'Reprovado' ? 'fa-times-circle' : 'fa-minus-circle')); ?>"></i>
                            <?php echo $aluno['status_texto']; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="alert alert-info mt-3 no-print">
            <i class="fas fa-info-circle"></i> 
            <strong>Legenda:</strong>
            <span class="text-success">● Aprovado (≥14)</span> | 
            <span class="text-warning">● Exame (10-13)</span> | 
            <span class="text-danger">● Reprovado (<10)</span> |
            <span class="text-secondary">● Sem nota</span>
        </div>
        
        <?php elseif ($turma_id > 0 && $disciplina_id > 0 && empty($notas_alunos)): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Nenhum aluno encontrado nesta turma.
            </div>
        <?php elseif ($turma_id > 0 && $disciplina_id == 0): ?>
            <div class="alert alert-info text-center">
                <i class="fas fa-info-circle"></i> Selecione uma disciplina para visualizar a caderneta.
            </div>
        <?php elseif ($turma_id == 0): ?>
            <div class="alert alert-secondary text-center">
                <i class="fas fa-filter"></i> Selecione uma turma para visualizar a caderneta.
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $('#tabelaCaderneta').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.4/i18n/pt-PT.json'
            },
            order: [[2, 'asc']],
            pageLength: 25
        });
        
        function exportarExcel() {
            window.location.href = 'exportar_excel_caderneta.php?turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&trimestre=<?php echo $trimestre; ?>&ano_letivo=<?php echo $ano_letivo_id; ?>';
        }
    </script>
</body>
</html>