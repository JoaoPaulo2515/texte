<?php
// escola/professor/importar_questoes.php - Importar Questões em Lote

require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];
$funcionario_id = $professor['funcionario_id'] ?? $professor['professor_id'];
$professor_nome = $professor['professor_nome'] ?? 'Professor';

// ============================================
// VARIÁVEIS
// ============================================
$mensagem = '';
$tipo_mensagem = '';
$total_importadas = 0;
$total_erros = 0;
$erros_lista = [];

// ============================================
// PROCESSAR IMPORTAÇÃO
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['arquivo'])) {
    $arquivo = $_FILES['arquivo'];
    $tipo = $_POST['tipo_importacao'] ?? 'csv';
    
    // Validar arquivo
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        $mensagem = 'Erro no upload do arquivo.';
        $tipo_mensagem = 'danger';
    } elseif ($arquivo['size'] > 5 * 1024 * 1024) { // 5MB
        $mensagem = 'Arquivo muito grande. Máximo 5MB.';
        $tipo_mensagem = 'danger';
    } else {
        $extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));
        
        if ($tipo == 'csv' && $extensao != 'csv') {
            $mensagem = 'Formato inválido. Envie um arquivo CSV.';
            $tipo_mensagem = 'danger';
        } elseif ($tipo == 'excel' && !in_array($extensao, ['xlsx', 'xls'])) {
            $mensagem = 'Formato inválido. Envie um arquivo Excel (.xlsx ou .xls).';
            $tipo_mensagem = 'danger';
        } else {
            // Processar arquivo
            if ($tipo == 'csv') {
                $resultado = processarCSV($arquivo['tmp_name']);
            } else {
                $resultado = processarExcel($arquivo['tmp_name']);
            }
            
            $total_importadas = $resultado['importadas'];
            $total_erros = $resultado['erros'];
            $erros_lista = $resultado['erros_lista'];
            
            if ($total_importadas > 0) {
                $mensagem = "Importação concluída! $total_importadas questões importadas com sucesso.";
                $tipo_mensagem = 'success';
            }
            
            if ($total_erros > 0) {
                $mensagem .= " $total_erros questões com erro.";
                if ($tipo_mensagem != 'success') $tipo_mensagem = 'warning';
            }
        }
    }
}

// ============================================
// FUNÇÕES DE PROCESSAMENTO
// ============================================

function processarCSV($caminho) {
    global $conn, $funcionario_id, $escola_id;
    
    $importadas = 0;
    $erros = 0;
    $erros_lista = [];
    
    if (($handle = fopen($caminho, 'r')) !== false) {
        // Ler cabeçalho
        $cabecalho = fgetcsv($handle, 0, ';');
        if (!$cabecalho) {
            $cabecalho = fgetcsv($handle, 0, ',');
        }
        
        $linha_num = 1;
        
        while (($data = fgetcsv($handle, 0, ';')) !== false) {
            $linha_num++;
            
            // Se não tiver dados suficientes, pular
            if (count($data) < 3) {
                $erros++;
                $erros_lista[] = "Linha $linha_num: Dados insuficientes";
                continue;
            }
            
            // Mapear colunas
            $enunciado = trim($data[0] ?? '');
            $tipo = trim($data[1] ?? 'multipla_escolha');
            $pontuacao = (float)($data[2] ?? 1.00);
            $alternativas = [];
            
            // Para múltipla escolha, capturar alternativas (colunas 3 a 7)
            if ($tipo == 'multipla_escolha') {
                for ($i = 3; $i <= 7; $i++) {
                    if (isset($data[$i]) && !empty(trim($data[$i]))) {
                        $alternativas[] = trim($data[$i]);
                    }
                }
                $correta = isset($data[8]) ? (int)$data[8] : 0;
            } elseif ($tipo == 'verdadeiro_falso') {
                $correta = isset($data[3]) ? (int)$data[3] : 0;
            }
            
            $dica = $data[9] ?? '';
            $imagem = $data[10] ?? '';
            $video_url = $data[11] ?? '';
            
            if (empty($enunciado)) {
                $erros++;
                $erros_lista[] = "Linha $linha_num: Enunciado vazio";
                continue;
            }
            
            try {
                // Inserir questão
                $sql = "INSERT INTO online_provas_questoes 
                        (prova_id, disciplina_id, enunciado, tipo, pontuacao, imagem, video_url, dica, ordem, created_at) 
                        VALUES (0, 0, :enunciado, :tipo, :pontuacao, :imagem, :video_url, :dica, 0, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':enunciado' => $enunciado,
                    ':tipo' => $tipo,
                    ':pontuacao' => $pontuacao,
                    ':imagem' => $imagem,
                    ':video_url' => $video_url,
                    ':dica' => $dica
                ]);
                
                $questao_id = $conn->lastInsertId();
                
                // Inserir alternativas
                if ($tipo == 'multipla_escolha' && !empty($alternativas)) {
                    $sql_alt = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                                VALUES (:questao_id, :texto, :correta, :ordem)";
                    $stmt_alt = $conn->prepare($sql_alt);
                    
                    foreach ($alternativas as $idx => $texto) {
                        $is_correta = ($correta == $idx) ? 1 : 0;
                        $stmt_alt->execute([
                            ':questao_id' => $questao_id,
                            ':texto' => $texto,
                            ':correta' => $is_correta,
                            ':ordem' => $idx
                        ]);
                    }
                } elseif ($tipo == 'verdadeiro_falso') {
                    $sql_v = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                              VALUES (:questao_id, 'Verdadeiro', :correta_v, 1)";
                    $stmt_v = $conn->prepare($sql_v);
                    $correta_v = ($correta == 0) ? 1 : 0;
                    $stmt_v->execute([
                        ':questao_id' => $questao_id,
                        ':correta_v' => $correta_v
                    ]);
                    
                    $sql_f = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                              VALUES (:questao_id, 'Falso', :correta_f, 2)";
                    $stmt_f = $conn->prepare($sql_f);
                    $correta_f = ($correta == 1) ? 1 : 0;
                    $stmt_f->execute([
                        ':questao_id' => $questao_id,
                        ':correta_f' => $correta_f
                    ]);
                }
                
                $importadas++;
                
            } catch (PDOException $e) {
                $erros++;
                $erros_lista[] = "Linha $linha_num: " . $e->getMessage();
            }
        }
        
        fclose($handle);
    }
    
    return [
        'importadas' => $importadas,
        'erros' => $erros,
        'erros_lista' => $erros_lista
    ];
}

function processarExcel($caminho) {
    global $conn, $funcionario_id, $escola_id;
    
    $importadas = 0;
    $erros = 0;
    $erros_lista = [];
    
    // Verificar se a classe PhpSpreadsheet está disponível
    if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        return [
            'importadas' => 0,
            'erros' => 1,
            'erros_lista' => ['Biblioteca PhpSpreadsheet não encontrada. Instale via composer: composer require phpoffice/phpspreadsheet']
        ];
    }
    
    try {
        require_once __DIR__ . '/../../vendor/autoload.php';
        
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($caminho);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        $linha_num = 0;
        foreach ($rows as $index => $row) {
            $linha_num++;
            if ($index == 0) continue; // Pular cabeçalho
            
            if (count($row) < 3) {
                $erros++;
                $erros_lista[] = "Linha $linha_num: Dados insuficientes";
                continue;
            }
            
            $enunciado = trim($row[0] ?? '');
            $tipo = trim($row[1] ?? 'multipla_escolha');
            $pontuacao = (float)($row[2] ?? 1.00);
            $alternativas = [];
            
            if ($tipo == 'multipla_escolha') {
                for ($i = 3; $i <= 7; $i++) {
                    if (isset($row[$i]) && !empty(trim($row[$i]))) {
                        $alternativas[] = trim($row[$i]);
                    }
                }
                $correta = isset($row[8]) ? (int)$row[8] : 0;
            } elseif ($tipo == 'verdadeiro_falso') {
                $correta = isset($row[3]) ? (int)$row[3] : 0;
            } else {
                $correta = 0;
            }
            
            $dica = $row[9] ?? '';
            $imagem = $row[10] ?? '';
            $video_url = $row[11] ?? '';
            
            if (empty($enunciado)) {
                $erros++;
                $erros_lista[] = "Linha $linha_num: Enunciado vazio";
                continue;
            }
            
            try {
                $sql = "INSERT INTO online_provas_questoes 
                        (prova_id, disciplina_id, enunciado, tipo, pontuacao, imagem, video_url, dica, ordem, created_at) 
                        VALUES (0, 0, :enunciado, :tipo, :pontuacao, :imagem, :video_url, :dica, 0, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([
                    ':enunciado' => $enunciado,
                    ':tipo' => $tipo,
                    ':pontuacao' => $pontuacao,
                    ':imagem' => $imagem,
                    ':video_url' => $video_url,
                    ':dica' => $dica
                ]);
                
                $questao_id = $conn->lastInsertId();
                
                if ($tipo == 'multipla_escolha' && !empty($alternativas)) {
                    $sql_alt = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                                VALUES (:questao_id, :texto, :correta, :ordem)";
                    $stmt_alt = $conn->prepare($sql_alt);
                    
                    foreach ($alternativas as $idx => $texto) {
                        $is_correta = ($correta == $idx) ? 1 : 0;
                        $stmt_alt->execute([
                            ':questao_id' => $questao_id,
                            ':texto' => $texto,
                            ':correta' => $is_correta,
                            ':ordem' => $idx
                        ]);
                    }
                } elseif ($tipo == 'verdadeiro_falso') {
                    $sql_v = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                              VALUES (:questao_id, 'Verdadeiro', :correta_v, 1)";
                    $stmt_v = $conn->prepare($sql_v);
                    $correta_v = ($correta == 0) ? 1 : 0;
                    $stmt_v->execute([
                        ':questao_id' => $questao_id,
                        ':correta_v' => $correta_v
                    ]);
                    
                    $sql_f = "INSERT INTO online_provas_alternativas (questao_id, texto, correta, ordem) 
                              VALUES (:questao_id, 'Falso', :correta_f, 2)";
                    $stmt_f = $conn->prepare($sql_f);
                    $correta_f = ($correta == 1) ? 1 : 0;
                    $stmt_f->execute([
                        ':questao_id' => $questao_id,
                        ':correta_f' => $correta_f
                    ]);
                }
                
                $importadas++;
                
            } catch (PDOException $e) {
                $erros++;
                $erros_lista[] = "Linha $linha_num: " . $e->getMessage();
            }
        }
        
    } catch (Exception $e) {
        return [
            'importadas' => 0,
            'erros' => 1,
            'erros_lista' => ['Erro ao processar Excel: ' . $e->getMessage()]
        ];
    }
    
    return [
        'importadas' => $importadas,
        'erros' => $erros,
        'erros_lista' => $erros_lista
    ];
}

// Buscar categorias para o formulário
$sql_categorias = "SELECT * FROM online_provas_categorias WHERE escola_id = :escola_id ORDER BY nome";
$stmt_categorias = $conn->prepare($sql_categorias);
$stmt_categorias->execute([':escola_id' => $escola_id]);
$categorias = $stmt_categorias->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Importar Questões | Professor | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        
        .main-content { margin-left: 280px; margin-top: 60px; padding: 20px; min-height: calc(100vh - 60px); }
        @media (max-width: 768px) { .main-content { margin-left: 0; margin-top: 70px; padding: 15px; } }
        
        .page-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 20px; padding: 20px 25px; margin-bottom: 25px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .page-header h4 { margin: 0; font-size: 1.5rem; font-weight: 700; }
        .page-header p { margin: 8px 0 0; opacity: 0.85; font-size: 0.9rem; }
        
        .upload-card { background: white; border-radius: 20px; padding: 30px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); text-align: center; transition: all 0.3s ease; }
        .upload-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .upload-icon { width: 80px; height: 80px; background: rgba(0,107,62,0.1); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .upload-icon i { font-size: 40px; color: #006B3E; }
        .drop-zone { border: 2px dashed #e9ecef; border-radius: 16px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.3s ease; }
        .drop-zone:hover { border-color: #006B3E; background: #f8f9fa; }
        .drop-zone.dragover { border-color: #006B3E; background: #e8f5e9; }
        
        .info-card { background: white; border-radius: 20px; padding: 25px; margin-bottom: 25px; box-shadow: 0 2px 15px rgba(0,0,0,0.05); }
        .info-card h6 { font-weight: 700; margin-bottom: 15px; color: #333; }
        .table-exemplo { background: #f8f9fa; border-radius: 12px; overflow-x: auto; }
        .table-exemplo table { width: 100%; font-size: 0.8rem; }
        .table-exemplo th, .table-exemplo td { padding: 8px; border: 1px solid #dee2e6; }
        .table-exemplo th { background: #e9ecef; font-weight: 600; }
        
        .badge-erro { background: #dc3545; color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
        .badge-sucesso { background: #28a745; color: white; padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
        
        .btn-voltar { background: #6c757d; color: white; border: none; padding: 10px 25px; border-radius: 40px; font-weight: 600; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn-voltar:hover { background: #5a6268; transform: translateY(-2px); color: white; }
        .btn-importar { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border: none; padding: 12px 30px; border-radius: 40px; font-weight: 600; transition: all 0.3s ease; }
        .btn-importar:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,107,62,0.3); }
        
        .progress-import { display: none; margin-top: 20px; }
        
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeInUp 0.5s ease-out; }
        
        @media (max-width: 768px) { .table-exemplo { overflow-x: scroll; } }
    </style>
</head>
<body>
    <?php include 'includes/menu_professor.php'; ?>
    
    <div class="main-content">
        <div class="container-fluid">
            <!-- Cabeçalho -->
            <div class="page-header fade-in">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4><i class="fas fa-upload me-2"></i> Importar Questões</h4>
                        <p>Importe questões em lote a partir de arquivos CSV ou Excel</p>
                    </div>
                    <div>
                        <a href="gerenciar_questoes.php" class="btn-voltar">
                            <i class="fas fa-arrow-left"></i> Voltar
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($mensagem): ?>
                <div class="alert alert-<?php echo $tipo_mensagem; ?> alert-dismissible fade show fade-in" role="alert">
                    <i class="fas fa-<?php echo $tipo_mensagem == 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                    <?php echo $mensagem; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Lista de Erros -->
            <?php if (!empty($erros_lista) && $total_erros > 0): ?>
            <div class="info-card fade-in">
                <h6><i class="fas fa-exclamation-triangle text-danger"></i> Erros na Importação (<?php echo $total_erros; ?>)</h6>
                <div style="max-height: 200px; overflow-y: auto;">
                    <ul class="mb-0">
                        <?php foreach ($erros_lista as $erro): ?>
                        <li class="text-danger small"><?php echo htmlspecialchars($erro); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <!-- Formulário de Upload -->
                    <div class="upload-card fade-in">
                        <div class="upload-icon">
                            <i class="fas fa-file-upload"></i>
                        </div>
                        <h5>Importar Arquivo</h5>
                        <p class="text-muted">Selecione um arquivo CSV ou Excel com as questões</p>
                        
                        <form method="POST" enctype="multipart/form-data" id="formImportar">
                            <div class="mb-3">
                                <label class="form-label">Tipo de Arquivo</label>
                                <select name="tipo_importacao" id="tipo_importacao" class="form-select">
                                    <option value="csv">CSV (separado por vírgula ou ponto e vírgula)</option>
                                    <option value="excel">Excel (.xlsx, .xls)</option>
                                </select>
                            </div>
                            
                            <div class="drop-zone" id="dropZone">
                                <i class="fas fa-cloud-upload-alt fa-3x text-muted mb-3"></i>
                                <p><strong>Arraste e solte o arquivo aqui</strong></p>
                                <p class="text-muted small">ou clique para selecionar</p>
                                <input type="file" name="arquivo" id="arquivo" accept=".csv,.xlsx,.xls" style="display: none;">
                                <div id="arquivo-nome" class="small text-success mt-2"></div>
                            </div>
                            
                            <div class="progress-import" id="progressImport">
                                <div class="text-center">
                                    <i class="fas fa-spinner fa-spin fa-2x text-primary mb-2"></i>
                                    <p>Importando questões... Aguarde</p>
                                </div>
                            </div>
                            
                            <div class="mt-4">
                                <button type="submit" class="btn-importar w-100" id="btnImportar">
                                    <i class="fas fa-upload"></i> Importar Questões
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <!-- Modelo de Arquivo -->
                    <div class="info-card fade-in">
                        <h6><i class="fas fa-download"></i> Modelo de Arquivo</h6>
                        <p>Baixe o modelo para preencher suas questões:</p>
                        <a href="download_modelo_csv.php" class="btn btn-outline-success btn-sm me-2">
                            <i class="fas fa-file-csv"></i> Modelo CSV
                        </a>
                        <a href="download_modelo_excel.php" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-file-excel"></i> Modelo Excel
                        </a>
                        
                        <hr>
                        
                        <h6><i class="fas fa-info-circle"></i> Estrutura do Arquivo</h6>
                        <div class="table-exemplo mt-3">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Coluna</th>
                                        <th>Campo</th>
                                        <th>Descrição</th>
                                        <th>Obrigatório</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>A</td><td>Enunciado</td><td>Texto da questão</td><td><span class="badge bg-danger">Sim</span></td></tr>
                                    <tr><td>B</td><td>Tipo</td><td>multipla_escolha, verdadeiro_falso, dissertativa</td><td><span class="badge bg-danger">Sim</span></td></tr>
                                    <tr><td>C</td><td>Pontuação</td><td>Valor da questão (ex: 1.00, 2.00)</td><td><span class="badge bg-danger">Sim</span></td></tr>
                                    <tr><td>D-H</td><td>Alternativas</td><td>Para múltipla escolha (até 5 alternativas)</td><td><span class="badge bg-warning">Opcional</span></td></tr>
                                    <tr><td>I</td><td>Correta</td><td>Índice da alternativa correta (0-4)</td><td><span class="badge bg-warning">Opcional</span></td></tr>
                                    <tr><td>J</td><td>Dica</td><td>Dica para ajudar o aluno</td><td><span class="badge bg-secondary">Opcional</span></td></tr>
                                    <tr><td>K</td><td>Imagem URL</td><td>URL da imagem da questão</td><td><span class="badge bg-secondary">Opcional</span></td></tr>
                                    <tr><td>L</td><td>Vídeo URL</td><td>URL do vídeo da questão</td><td><span class="badge bg-secondary">Opcional</span></td></tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-lightbulb"></i> <strong>Dicas:</strong>
                            <ul class="mb-0 mt-2">
                                <li>Para questões dissertativas, preencha apenas as colunas A, B, C</li>
                                <li>Para verdadeiro/falso, a coluna I deve ser 0 (Verdadeiro) ou 1 (Falso)</li>
                                <li>O arquivo deve ter no máximo 5MB</li>
                                <li>Limite de 500 questões por importação</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Estatísticas da Última Importação -->
            <?php if ($total_importadas > 0): ?>
            <div class="info-card fade-in">
                <h6><i class="fas fa-chart-bar"></i> Resumo da Importação</h6>
                <div class="row">
                    <div class="col-md-4 text-center">
                        <div class="p-3 bg-light rounded">
                            <h2 class="text-success mb-0"><?php echo $total_importadas; ?></h2>
                            <small>Questões importadas</small>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="p-3 bg-light rounded">
                            <h2 class="text-danger mb-0"><?php echo $total_erros; ?></h2>
                            <small>Questões com erro</small>
                        </div>
                    </div>
                    <div class="col-md-4 text-center">
                        <div class="p-3 bg-light rounded">
                            <h2 class="text-primary mb-0"><?php echo $total_importadas + $total_erros; ?></h2>
                            <small>Total processadas</small>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-3">
                    <a href="gerenciar_questoes.php" class="btn btn-primary">
                        <i class="fas fa-eye"></i> Ver Questões Importadas
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const dropZone = document.getElementById('dropZone');
        const fileInput = document.getElementById('arquivo');
        const fileName = document.getElementById('arquivo-nome');
        const btnImportar = document.getElementById('btnImportar');
        const progressImport = document.getElementById('progressImport');
        const form = document.getElementById('formImportar');
        
        // Clique na drop zone para abrir seletor de arquivo
        dropZone.addEventListener('click', () => fileInput.click());
        
        // Prevenir comportamento padrão de drag/drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        // Adicionar classe quando arquivo é arrastado sobre a área
        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.add('dragover'), false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => dropZone.classList.remove('dragover'), false);
        });
        
        // Processar arquivo solto
        dropZone.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            updateFileName(files[0]);
        }
        
        // Processar arquivo selecionado
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                updateFileName(this.files[0]);
            } else {
                fileName.innerHTML = '';
            }
        });
        
        function updateFileName(file) {
            const tipo = document.getElementById('tipo_importacao').value;
            const extensao = file.name.split('.').pop().toLowerCase();
            
            if (tipo === 'csv' && extensao !== 'csv') {
                fileName.innerHTML = '<span class="text-danger">Erro: O arquivo deve ser CSV</span>';
                fileInput.value = '';
                return;
            }
            
            if (tipo === 'excel' && !['xlsx', 'xls'].includes(extensao)) {
                fileName.innerHTML = '<span class="text-danger">Erro: O arquivo deve ser Excel (.xlsx ou .xls)</span>';
                fileInput.value = '';
                return;
            }
            
            if (file.size > 5 * 1024 * 1024) {
                fileName.innerHTML = '<span class="text-danger">Erro: Arquivo muito grande (máximo 5MB)</span>';
                fileInput.value = '';
                return;
            }
            
            fileName.innerHTML = '<i class="fas fa-check-circle text-success"></i> ' + file.name;
        }
        
        // Mostrar progresso ao enviar formulário
        form.addEventListener('submit', function() {
            if (fileInput.files.length === 0) {
                alert('Selecione um arquivo para importar.');
                return false;
            }
            
            btnImportar.disabled = true;
            btnImportar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando...';
            progressImport.style.display = 'block';
        });
        
        // Trocar tipo de arquivo
        document.getElementById('tipo_importacao').addEventListener('change', function() {
            const accept = this.value === 'csv' ? '.csv' : '.xlsx,.xls';
            fileInput.setAttribute('accept', accept);
            fileInput.value = '';
            fileName.innerHTML = '';
        });
        
        // Animações
        const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);
        
        document.querySelectorAll('.upload-card, .info-card, .page-header').forEach(el => {
            el.classList.remove('fade-in');
            observer.observe(el);
        });
    </script>
</body>
</html>