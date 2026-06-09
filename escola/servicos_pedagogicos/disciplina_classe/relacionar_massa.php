<?php
// escola/servicos_pedagogicos/disciplina_classe/relacionar_massa.php - Relacionamento em Massa
require_once __DIR__ . '/../../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// ============================================
// PROCESSAR RELACIONAMENTO EM MASSA
// ============================================

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    // Relacionar várias disciplinas a uma classe
    if ($acao == 'relacionar_disciplinas_classe') {
        $classe_id = $_POST['classe_id'];
        $disciplinas = $_POST['disciplinas'] ?? [];
        $carga_horaria = $_POST['carga_horaria'];
        $ano_letivo = $_POST['ano_letivo'];
        $periodo = $_POST['periodo'];
        
        $count = 0;
        foreach ($disciplinas as $disciplina_id) {
            // Verificar se já existe
            $check = $conn->prepare("
                SELECT id FROM disciplina_classe 
                WHERE disciplina_id = :disciplina_id AND classe_id = :classe_id AND ano_letivo = :ano_letivo
            ");
            $check->execute([
                ':disciplina_id' => $disciplina_id,
                ':classe_id' => $classe_id,
                ':ano_letivo' => $ano_letivo
            ]);
            
            if ($check->rowCount() == 0) {
                $stmt = $conn->prepare("
                    INSERT INTO disciplina_classe (escola_id, disciplina_id, classe_id, carga_horaria, ano_letivo, periodo, status)
                    VALUES (:escola_id, :disciplina_id, :classe_id, :carga_horaria, :ano_letivo, :periodo, 'ativo')
                ");
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':disciplina_id' => $disciplina_id,
                    ':classe_id' => $classe_id,
                    ':carga_horaria' => $carga_horaria,
                    ':ano_letivo' => $ano_letivo,
                    ':periodo' => $periodo
                ]);
                $count++;
            }
        }
        
        $_SESSION['mensagem'] = "$count disciplina(s) relacionada(s) à classe com sucesso!";
        header("Location: index.php");
        exit;
    }
    
    // Relacionar várias classes a uma disciplina
    if ($acao == 'relacionar_classes_disciplina') {
        $disciplina_id = $_POST['disciplina_id'];
        $classes = $_POST['classes'] ?? [];
        $carga_horaria = $_POST['carga_horaria'];
        $ano_letivo = $_POST['ano_letivo'];
        $periodo = $_POST['periodo'];
        
        $count = 0;
        foreach ($classes as $classe_id) {
            // Verificar se já existe
            $check = $conn->prepare("
                SELECT id FROM disciplina_classe 
                WHERE disciplina_id = :disciplina_id AND classe_id = :classe_id AND ano_letivo = :ano_letivo
            ");
            $check->execute([
                ':disciplina_id' => $disciplina_id,
                ':classe_id' => $classe_id,
                ':ano_letivo' => $ano_letivo
            ]);
            
            if ($check->rowCount() == 0) {
                $stmt = $conn->prepare("
                    INSERT INTO disciplina_classe (escola_id, disciplina_id, classe_id, carga_horaria, ano_letivo, periodo, status)
                    VALUES (:escola_id, :disciplina_id, :classe_id, :carga_horaria, :ano_letivo, :periodo, 'ativo')
                ");
                $stmt->execute([
                    ':escola_id' => $escola_id,
                    ':disciplina_id' => $disciplina_id,
                    ':classe_id' => $classe_id,
                    ':carga_horaria' => $carga_horaria,
                    ':ano_letivo' => $ano_letivo,
                    ':periodo' => $periodo
                ]);
                $count++;
            }
        }
        
        $_SESSION['mensagem'] = "$count classe(s) relacionada(s) à disciplina com sucesso!";
        header("Location: index.php");
        exit;
    }
    
    // Relacionamento cruzado (todas disciplinas com todas classes)
    if ($acao == 'relacionar_cruzado') {
        $disciplinas = $_POST['disciplinas'] ?? [];
        $classes = $_POST['classes'] ?? [];
        $carga_horaria = $_POST['carga_horaria'];
        $ano_letivo = $_POST['ano_letivo'];
        $periodo = $_POST['periodo'];
        
        $count = 0;
        foreach ($disciplinas as $disciplina_id) {
            foreach ($classes as $classe_id) {
                // Verificar se já existe
                $check = $conn->prepare("
                    SELECT id FROM disciplina_classe 
                    WHERE disciplina_id = :disciplina_id AND classe_id = :classe_id AND ano_letivo = :ano_letivo
                ");
                $check->execute([
                    ':disciplina_id' => $disciplina_id,
                    ':classe_id' => $classe_id,
                    ':ano_letivo' => $ano_letivo
                ]);
                
                if ($check->rowCount() == 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO disciplina_classe (escola_id, disciplina_id, classe_id, carga_horaria, ano_letivo, periodo, status)
                        VALUES (:escola_id, :disciplina_id, :classe_id, :carga_horaria, :ano_letivo, :periodo, 'ativo')
                    ");
                    $stmt->execute([
                        ':escola_id' => $escola_id,
                        ':disciplina_id' => $disciplina_id,
                        ':classe_id' => $classe_id,
                        ':carga_horaria' => $carga_horaria,
                        ':ano_letivo' => $ano_letivo,
                        ':periodo' => $periodo
                    ]);
                    $count++;
                }
            }
        }
        
        $_SESSION['mensagem'] = "$count relação(ões) criada(s) com sucesso!";
        header("Location: index.php");
        exit;
    }
    
    // Relacionamento por faixa de classes (ex: todas classes do 1º ciclo)
    if ($acao == 'relacionar_faixa_classes') {
        $disciplinas = $_POST['disciplinas'] ?? [];
        $classe_inicio = $_POST['classe_inicio'];
        $classe_fim = $_POST['classe_fim'];
        $carga_horaria = $_POST['carga_horaria'];
        $ano_letivo = $_POST['ano_letivo'];
        $periodo = $_POST['periodo'];
        
        // Buscar classes na faixa
        $stmt_classes = $conn->prepare("
            SELECT id FROM classes 
            WHERE escola_id = :escola_id 
                AND ordem BETWEEN :inicio AND :fim
                AND status = 'ativa'
        ");
        $stmt_classes->execute([
            ':escola_id' => $escola_id,
            ':inicio' => $classe_inicio,
            ':fim' => $classe_fim
        ]);
        $classes_faixa = $stmt_classes->fetchAll(PDO::FETCH_COLUMN);
        
        $count = 0;
        foreach ($disciplinas as $disciplina_id) {
            foreach ($classes_faixa as $classe_id) {
                $check = $conn->prepare("
                    SELECT id FROM disciplina_classe 
                    WHERE disciplina_id = :disciplina_id AND classe_id = :classe_id AND ano_letivo = :ano_letivo
                ");
                $check->execute([
                    ':disciplina_id' => $disciplina_id,
                    ':classe_id' => $classe_id,
                    ':ano_letivo' => $ano_letivo
                ]);
                
                if ($check->rowCount() == 0) {
                    $stmt = $conn->prepare("
                        INSERT INTO disciplina_classe (escola_id, disciplina_id, classe_id, carga_horaria, ano_letivo, periodo, status)
                        VALUES (:escola_id, :disciplina_id, :classe_id, :carga_horaria, :ano_letivo, :periodo, 'ativo')
                    ");
                    $stmt->execute([
                        ':escola_id' => $escola_id,
                        ':disciplina_id' => $disciplina_id,
                        ':classe_id' => $classe_id,
                        ':carga_horaria' => $carga_horaria,
                        ':ano_letivo' => $ano_letivo,
                        ':periodo' => $periodo
                    ]);
                    $count++;
                }
            }
        }
        
        $_SESSION['mensagem'] = "$count relação(ões) criada(s) para a faixa de classes!";
        header("Location: index.php");
        exit;
    }
}

// ============================================
// BUSCAR DADOS
// ============================================

// Buscar disciplinas
$disciplinas = $conn->prepare("
    SELECT id, nome, codigo, carga_horaria as ch_padrao 
    FROM disciplinas 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY nome
");
$disciplinas->execute([':escola_id' => $escola_id]);
$disciplinas = $disciplinas->fetchAll(PDO::FETCH_ASSOC);

// Buscar classes
$classes = $conn->prepare("
    SELECT id, nome, ordem 
    FROM classes 
    WHERE escola_id = :escola_id AND status = 'ativa' 
    ORDER BY ordem, nome
");
$classes->execute([':escola_id' => $escola_id]);
$classes = $classes->fetchAll(PDO::FETCH_ASSOC);

// Obter ordens mínima e máxima para faixa
$ordem_min = !empty($classes) ? min(array_column($classes, 'ordem')) : 1;
$ordem_max = !empty($classes) ? max(array_column($classes, 'ordem')) : 13;

$mensagem = $_SESSION['mensagem'] ?? '';
unset($_SESSION['mensagem']);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relacionar em Massa - Disciplina-Classe | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar-header .logo { font-size: 2.5em; margin-bottom: 10px; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .nav-submenu { list-style: none; padding-left: 50px; margin: 0; display: none; }
        .nav-submenu.show { display: block; }
        .nav-item.has-submenu > .nav-link { position: relative; }
        .nav-item.has-submenu > .nav-link:after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 25px;
            transition: transform 0.3s;
        }
        .nav-item.has-submenu.open > .nav-link:after { transform: rotate(180deg); }
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        .checkbox-group { max-height: 400px; overflow-y: auto; border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-top: 10px; }
        .checkbox-item { margin-bottom: 8px; }
        .checkbox-item input { margin-right: 10px; }
        .select-all { margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        .nav-tabs .nav-link { color: #006B3E; }
        .nav-tabs .nav-link.active { background-color: #006B3E; color: white; border-color: #006B3E; }
        
        .faixa-container {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 10px;
        }
    </style>
</head>
<body>
   
     <?php include '../../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-layer-group"></i> Relacionamento em Massa</h2>
            <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
        
        <?php if ($mensagem): ?>
            <div class="alert alert-success alert-dismissible fade show"><?php echo $mensagem; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="massaTabs" role="tablist">
                    <li class="nav-item">
                        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#disciplinasParaClasse">
                            <i class="fas fa-book"></i> Disciplinas → Classe
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#classesParaDisciplina">
                            <i class="fas fa-layer-group"></i> Classes → Disciplina
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#relacionamentoCruzado">
                            <i class="fas fa-link"></i> Relacionamento Cruzado
                        </button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#faixaClasses">
                            <i class="fas fa-chart-line"></i> Por Faixa de Classes
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content">
                    
                    <!-- Tab 1: Disciplinas para uma Classe -->
                    <div class="tab-pane fade show active" id="disciplinasParaClasse">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Selecione uma classe e várias disciplinas para relacionar.
                            Todas as turmas desta classe herdarão estas disciplinas.
                        </div>
                        <form method="POST">
                            <input type="hidden" name="acao" value="relacionar_disciplinas_classe">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Selecione a Classe</label>
                                    <select name="classe_id" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($classes as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Ano Letivo</label>
                                    <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Período</label>
                                    <select name="periodo" class="form-control" required>
                                        <option value="1º Bimestre">1º Bimestre</option>
                                        <option value="2º Bimestre">2º Bimestre</option>
                                        <option value="3º Bimestre">3º Bimestre</option>
                                        <option value="4º Bimestre">4º Bimestre</option>
                                        <option value="Anual">Anual</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Selecione as Disciplinas</label>
                                <div class="select-all">
                                    <input type="checkbox" id="selectAllDisciplinas" onclick="toggleAll('disciplinas', this.checked)">
                                    <label for="selectAllDisciplinas">Selecionar Todas</label>
                                </div>
                                <div class="checkbox-group">
                                    <?php foreach ($disciplinas as $d): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="disciplinas[]" value="<?php echo $d['id']; ?>" id="disc_<?php echo $d['id']; ?>">
                                        <label for="disc_<?php echo $d['id']; ?>">
                                            <strong><?php echo htmlspecialchars($d['codigo']); ?></strong> - <?php echo htmlspecialchars($d['nome']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-link"></i> Relacionar Disciplinas à Classe
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab 2: Classes para uma Disciplina -->
                    <div class="tab-pane fade" id="classesParaDisciplina">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> Selecione uma disciplina e várias classes para relacionar.
                        </div>
                        <form method="POST">
                            <input type="hidden" name="acao" value="relacionar_classes_disciplina">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Selecione a Disciplina</label>
                                    <select name="disciplina_id" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php foreach ($disciplinas as $d): ?>
                                        <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['codigo']); ?> - <?php echo htmlspecialchars($d['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Ano Letivo</label>
                                    <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Período</label>
                                    <select name="periodo" class="form-control" required>
                                        <option value="1º Bimestre">1º Bimestre</option>
                                        <option value="2º Bimestre">2º Bimestre</option>
                                        <option value="3º Bimestre">3º Bimestre</option>
                                        <option value="4º Bimestre">4º Bimestre</option>
                                        <option value="Anual">Anual</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label>Selecione as Classes</label>
                                <div class="select-all">
                                    <input type="checkbox" id="selectAllClasses" onclick="toggleAll('classes', this.checked)">
                                    <label for="selectAllClasses">Selecionar Todas</label>
                                </div>
                                <div class="checkbox-group">
                                    <?php foreach ($classes as $c): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="classes[]" value="<?php echo $c['id']; ?>" id="classe_<?php echo $c['id']; ?>">
                                        <label for="classe_<?php echo $c['id']; ?>">
                                            <?php echo htmlspecialchars($c['nome']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-link"></i> Relacionar Classes à Disciplina
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab 3: Relacionamento Cruzado -->
                    <div class="tab-pane fade" id="relacionamentoCruzado">
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i> 
                            <strong>Atenção:</strong> Esta opção irá relacionar TODAS as disciplinas selecionadas com TODAS as classes selecionadas.
                            Isso pode gerar muitas relações. Use com cuidado!
                        </div>
                        <form method="POST">
                            <input type="hidden" name="acao" value="relacionar_cruzado">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Ano Letivo</label>
                                    <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label>Período</label>
                                <select name="periodo" class="form-control" required>
                                    <option value="1º Bimestre">1º Bimestre</option>
                                    <option value="2º Bimestre">2º Bimestre</option>
                                    <option value="3º Bimestre">3º Bimestre</option>
                                    <option value="4º Bimestre">4º Bimestre</option>
                                    <option value="Anual">Anual</option>
                                </select>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <label>Selecione as Disciplinas</label>
                                    <div class="select-all">
                                        <input type="checkbox" id="selectAllDisciplinasCruzado" onclick="toggleAll('disciplinasCruzado', this.checked)">
                                        <label for="selectAllDisciplinasCruzado">Selecionar Todas</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <?php foreach ($disciplinas as $d): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="disciplinas[]" value="<?php echo $d['id']; ?>" id="disc_cruzado_<?php echo $d['id']; ?>">
                                            <label for="disc_cruzado_<?php echo $d['id']; ?>">
                                                <strong><?php echo htmlspecialchars($d['codigo']); ?></strong> - <?php echo htmlspecialchars($d['nome']); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label>Selecione as Classes</label>
                                    <div class="select-all">
                                        <input type="checkbox" id="selectAllClassesCruzado" onclick="toggleAll('classesCruzado', this.checked)">
                                        <label for="selectAllClassesCruzado">Selecionar Todas</label>
                                    </div>
                                    <div class="checkbox-group">
                                        <?php foreach ($classes as $c): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="classes[]" value="<?php echo $c['id']; ?>" id="classe_cruzado_<?php echo $c['id']; ?>">
                                            <label for="classe_cruzado_<?php echo $c['id']; ?>">
                                                <?php echo htmlspecialchars($c['nome']); ?>
                                            </label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="text-center mt-4">
                                <button type="submit" class="btn btn-danger btn-lg" onclick="return confirm('Tem certeza que deseja criar todas estas relações? Isso pode gerar muitas combinações.')">
                                    <i class="fas fa-link"></i> Relacionamento Cruzado
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tab 4: Por Faixa de Classes -->
                    <div class="tab-pane fade" id="faixaClasses">
                        <div class="alert alert-success">
                            <i class="fas fa-chart-line"></i> 
                            <strong>Relacionamento por Faixa:</strong> Selecione uma faixa de classes (ex: 1ª à 6ª Classe) e as disciplinas serão relacionadas a todas as classes dessa faixa.
                        </div>
                        <form method="POST">
                            <input type="hidden" name="acao" value="relacionar_faixa_classes">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Classe Inicial</label>
                                    <select name="classe_inicio" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php for ($i = $ordem_min; $i <= $ordem_max; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?>ª Classe</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Classe Final</label>
                                    <select name="classe_fim" class="form-control" required>
                                        <option value="">Selecione...</option>
                                        <?php for ($i = $ordem_min; $i <= $ordem_max; $i++): ?>
                                        <option value="<?php echo $i; ?>"><?php echo $i; ?>ª Classe</option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Carga Horária (horas/semana)</label>
                                    <input type="number" name="carga_horaria" class="form-control" value="4" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Ano Letivo</label>
                                    <input type="text" name="ano_letivo" class="form-control" value="<?php echo date('Y') . '/' . (date('Y')+1); ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label>Período</label>
                                <select name="periodo" class="form-control" required>
                                    <option value="1º Bimestre">1º Bimestre</option>
                                    <option value="2º Bimestre">2º Bimestre</option>
                                    <option value="3º Bimestre">3º Bimestre</option>
                                    <option value="4º Bimestre">4º Bimestre</option>
                                    <option value="Anual">Anual</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label>Selecione as Disciplinas</label>
                                <div class="select-all">
                                    <input type="checkbox" id="selectAllDisciplinasFaixa" onclick="toggleAll('disciplinasFaixa', this.checked)">
                                    <label for="selectAllDisciplinasFaixa">Selecionar Todas</label>
                                </div>
                                <div class="checkbox-group">
                                    <?php foreach ($disciplinas as $d): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="disciplinas[]" value="<?php echo $d['id']; ?>" id="disc_faixa_<?php echo $d['id']; ?>">
                                        <label for="disc_faixa_<?php echo $d['id']; ?>">
                                            <strong><?php echo htmlspecialchars($d['codigo']); ?></strong> - <?php echo htmlspecialchars($d['nome']); ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="text-center">
                                <button type="submit" class="btn btn-info btn-lg">
                                    <i class="fas fa-chart-line"></i> Relacionar por Faixa
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        function toggleSubmenu(event) {
            event.preventDefault();
            const parentLi = $(event.currentTarget).closest('.has-submenu');
            const submenu = parentLi.find('.nav-submenu');
            $('.has-submenu').not(parentLi).removeClass('open');
            $('.nav-submenu').not(submenu).removeClass('show');
            parentLi.toggleClass('open');
            submenu.toggleClass('show');
        }
        
        function toggleAll(group, checked) {
            if (group === 'disciplinas') {
                $('input[name="disciplinas[]"]').prop('checked', checked);
            } else if (group === 'classes') {
                $('input[name="classes[]"]').prop('checked', checked);
            } else if (group === 'disciplinasCruzado') {
                $('input[name="disciplinas[]"]').prop('checked', checked);
            } else if (group === 'classesCruzado') {
                $('input[name="classes[]"]').prop('checked', checked);
            } else if (group === 'disciplinasFaixa') {
                $('input[name="disciplinas[]"]').prop('checked', checked);
            }
        }
        
        // Validação de faixa (início deve ser menor ou igual ao fim)
        $('select[name="classe_inicio"], select[name="classe_fim"]').change(function() {
            var inicio = parseInt($('select[name="classe_inicio"]').val());
            var fim = parseInt($('select[name="classe_fim"]').val());
            if (inicio && fim && inicio > fim) {
                alert('A classe inicial deve ser menor ou igual à classe final!');
                $('select[name="classe_fim"]').val(inicio);
            }
        });
    </script>
</body>
</html>