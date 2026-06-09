<?php
// escola/pedagogico/gerar_boletim.php - Gerar Boletim de Notas

require_once __DIR__ . '/../includes/auth.php';
$usuario = checkAuth();
$conn = getConnection();

// Verificar permissão
$sql_verifica = "
    SELECT f.*, u.tipo as usuario_tipo
    FROM funcionarios f
    INNER JOIN usuarios u ON u.id = f.usuario_id
    WHERE f.usuario_id = :usuario_id 
    AND u.tipo IN ('pedagogico', 'coordenador', 'diretor', 'admin_escola', 'admin', 'professor')
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':usuario_id' => $usuario['id']]);
$funcionario = $stmt_verifica->fetch(PDO::FETCH_ASSOC);

if (!$funcionario) {
    die('Acesso negado');
}

$escola_id = $funcionario['escola_id'];

// ============================================
// BUSCAR DADOS PARA O FORMULÁRIO
// ============================================

// DADOS DA ESCOLA
$sql_escola = "SELECT nome, endereco, telefone, email, nif, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ANOS LETIVOS
$sql_anos = "SELECT id, ano FROM ano_letivo ORDER BY ano DESC";
$stmt_anos = $conn->prepare($sql_anos);
$stmt_anos->execute();
$anos_letivos = $stmt_anos->fetchAll(PDO::FETCH_ASSOC);

// TURMAS
$sql_turmas = "
    SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
    FROM turmas t
    LEFT JOIN turnos tr ON tr.id = t.turno_id
    WHERE t.escola_id = :escola_id AND t.status = 'ativa'
    ORDER BY t.ano ASC, t.nome ASC
";
$stmt_turmas = $conn->prepare($sql_turmas);
$stmt_turmas->execute([':escola_id' => $escola_id]);
$turmas = $stmt_turmas->fetchAll(PDO::FETCH_ASSOC);

// PARÂMETROS DE FILTRO
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$modo_impressao = isset($_GET['modo']) ? $_GET['modo'] : 'individual'; // individual ou massa

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// BUSCAR ALUNOS DA TURMA
$alunos = [];
if ($turma_id > 0 && $ano_letivo_id > 0) {
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.data_nascimento,
            e.foto,
            t.nome as turma_nome,
            t.ano as turma_ano,
            tr.nome as turno_nome
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        INNER JOIN turmas t ON t.id = m.turma_id
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE m.turma_id = :turma_id 
        AND m.status = 'ativa'
        AND m.ano_letivo = :ano_letivo_id
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
}

$caminho_base = '/sige_Plataforma/uploads/alunos/';
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerar Boletim - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .btn-voltar { background: rgba(255,255,255,0.2); color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; }
        .card { background: white; border-radius: 12px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .card-header { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; padding: 12px 20px; font-weight: bold; }
        .card-body { padding: 20px; }
        .filtros-row { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end; }
        .filtro-group { flex: 1; min-width: 180px; }
        .filtro-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 12px; }
        .filtro-select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        .btn-filtrar { background: #27ae60; color: white; padding: 10px 25px; border: none; border-radius: 8px; cursor: pointer; }
        .btn-massa { background: #1e5799; color: white; padding: 10px 25px; border: none; border-radius: 8px; cursor: pointer; }
        .btn-massa:hover, .btn-filtrar:hover { opacity: 0.9; transform: translateY(-2px); }
        .table-alunos { width: 100%; border-collapse: collapse; }
        .table-alunos th { background: #f8f9fa; padding: 12px; text-align: center; border-bottom: 2px solid #1e5799; }
        .table-alunos td { padding: 10px; border-bottom: 1px solid #ecf0f1; text-align: center; vertical-align: middle; }
        .aluno-info { display: flex; align-items: center; gap: 10px; }
        .aluno-foto { width: 45px; height: 45px; border-radius: 50%; object-fit: cover; border: 2px solid #1e5799; }
        .aluno-foto-placeholder { width: 45px; height: 45px; border-radius: 50%; background: linear-gradient(135deg, #1e5799, #2c3e50); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        .btn-preview { background: #17a2b8; color: white; padding: 5px 12px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-selecionar { background: #6c757d; color: white; padding: 5px 12px; border: none; border-radius: 6px; cursor: pointer; }
        .btn-selecionar.selecionado { background: #28a745; }
        
        /* Modal */
        .modal-preview { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-preview-content { background: white; margin: 2% auto; width: 95%; max-width: 1400px; border-radius: 16px; max-height: 90vh; overflow-y: auto; }
        .modal-preview-header { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; padding: 15px 25px; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .close-modal { font-size: 28px; cursor: pointer; }
        .modal-preview-body { padding: 20px; }
        
        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 20000;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            color: white;
        }
        .loading-overlay .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #1e5799;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Boletim */
        .boletim-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e5799; padding-bottom: 15px; }
        .info-aluno-preview { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
        .table-boletim { width: 100%; border-collapse: collapse; font-size: 11px; }
        .table-boletim th { background: #1e5799; color: white; padding: 8px; text-align: center; }
        .table-boletim td { border: 1px solid #ddd; padding: 6px; text-align: center; vertical-align: middle; }
        .table-boletim td.text-start { text-align: left; }
        .bimestre-filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; justify-content: center; }
        .bimestre-btn { padding: 8px 20px; border: none; border-radius: 8px; cursor: pointer; background: #ecf0f1; transition: all 0.3s ease; }
        .bimestre-btn.active { background: #1e5799; color: white; }
        .bimestre-btn.disabled { opacity: 0.5; cursor: not-allowed; }
        .media-geral-preview { text-align: center; padding: 15px; background: #e8f4fd; border-radius: 8px; margin-top: 20px; }
        .legenda-notas { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 20px; font-size: 11px; border: 1px solid #ddd; }
        .status-aprovado { color: #27ae60; font-weight: bold; }
        .status-recuperacao { color: #f39c12; font-weight: bold; }
        .status-reprovado { color: #e74c3c; font-weight: bold; }
        .nota-alta { color: #27ae60; font-weight: bold; }
        .nota-baixa { color: #e74c3c; font-weight: bold; }
        .btn-imprimir { background: #28a745; color: white; border: none; padding: 10px 25px; border-radius: 8px; cursor: pointer; margin-top: 20px; }
        
        @media (max-width: 768px) {
            .table-boletim { font-size: 9px; }
            .table-boletim th, .table-boletim td { padding: 4px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-file-pdf"></i> Gerar Boletim</h1>
            <p>Visualize e imprima boletins de notas dos alunos</p>
        </div>
        <a href="index.php" class="btn-voltar">← Voltar</a>
    </div>
    
    <!-- Filtros -->
    <div class="card">
        <div class="card-header">Filtros</div>
        <div class="card-body">
            <form method="GET" action="" id="formFiltros">
                <div class="filtros-row">
                    <div class="filtro-group">
                        <label>Ano Letivo</label>
                        <select name="ano_letivo_id" class="filtro-select">
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Turma</label>
                        <select name="turma_id" class="filtro-select">
                            <option value="">Selecione</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">Buscar Alunos</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma_id > 0 && !empty($alunos)): ?>
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <span><i class="fas fa-users"></i> Alunos da Turma (<?php echo count($alunos); ?> alunos)</span>
                <div>
                    <button class="btn-massa" onclick="gerarBoletimMassa()">
                        <i class="fas fa-print"></i> Gerar Boletins em Massa (Todos os Alunos)
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-alunos">
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selecionarTodos" onchange="toggleSelecionarTodos()"></th>
                            <th>Aluno</th>
                            <th>Matrícula</th>
                            <th>BI</th>
                            <th>Turma</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos as $index => $aluno): 
                            $inicial = strtoupper(substr($aluno['nome'], 0, 1));
                            $foto_path = !empty($aluno['foto']) ? $caminho_base . $aluno['foto'] : '';
                        ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="selecionar-aluno" data-id="<?php echo $aluno['id']; ?>" data-nome="<?php echo htmlspecialchars($aluno['nome']); ?>" data-matricula="<?php echo htmlspecialchars($aluno['matricula']); ?>" data-bi="<?php echo htmlspecialchars($aluno['bi'] ?? ''); ?>" data-turma_ano="<?php echo $aluno['turma_ano']; ?>" data-turma_nome="<?php echo htmlspecialchars($aluno['turma_nome']); ?>" data-turno="<?php echo htmlspecialchars($aluno['turno_nome'] ?? ''); ?>" data-nascimento="<?php echo $aluno['data_nascimento'] ?? ''; ?>">
                                </td>
                                <td class="text-start">
                                    <div class="aluno-info">
                                        <?php if ($foto_path && file_exists($_SERVER['DOCUMENT_ROOT'] . $foto_path)): ?>
                                            <img src="<?php echo $foto_path; ?>" class="aluno-foto" alt="Foto">
                                        <?php else: ?>
                                            <div class="aluno-foto-placeholder"><?php echo $inicial; ?></div>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                    </div>
                                  </td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                                <td><?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?> (<?php echo ucfirst($aluno['turno_nome'] ?? ''); ?>)</td>
                                <td>
                                    <button class="btn-preview" onclick="abrirPreview(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>', '<?php echo addslashes($aluno['matricula']); ?>', '<?php echo addslashes($aluno['bi'] ?? ''); ?>', '<?php echo $aluno['turma_ano']; ?>', '<?php echo addslashes($aluno['turma_nome']); ?>', '<?php echo addslashes($aluno['turno_nome'] ?? ''); ?>', '<?php echo $aluno['data_nascimento'] ?? ''; ?>')">
                                        <i class="fas fa-eye"></i> Individual
                                    </button>
                                    <button class="btn-selecionar" onclick="selecionarAluno(<?php echo $index; ?>)">
                                        <i class="fas fa-check"></i> Selecionar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="card mt-3">
        <div class="card-header">
            <i class="fas fa-layer-group"></i> Boletins Selecionados
        </div>
        <div class="card-body">
            <div id="selecionadosLista" class="mb-3">
                <p class="text-muted">Nenhum aluno selecionado. Clique em "Selecionar" ou marque os checkboxes.</p>
            </div>
            <button class="btn-massa" onclick="gerarBoletinsSelecionados()" id="btnGerarSelecionados" style="display: none;">
                <i class="fas fa-print"></i> Gerar Boletins dos Selecionados
            </button>
        </div>
    </div>
    <?php elseif ($turma_id > 0 && empty($alunos)): ?>
        <div class="alert alert-info">Nenhum aluno encontrado para esta turma.</div>
    <?php endif; ?>
</div>

<!-- Modal -->
<div id="modalPreview" class="modal-preview">
    <div class="modal-preview-content">
        <div class="modal-preview-header">
            <h3>Pré-visualização do Boletim</h3>
            <span class="close-modal" onclick="fecharPreview()">&times;</span>
        </div>
        <div class="modal-preview-body" id="previewBody">
            <div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Carregando...</div>
        </div>
    </div>
</div>

<script>
    let bimestreAtual = 0;
    let disciplinas = [];
    let bimestres_liberados = [];
    let total_pagamentos = 0;
    let limiteAprovacao = 0;
    let escalaMax = 0;
    let anoLetivo = '';
    let isClasseExame = false;
    let dadosAluno = {};
    let escola = <?php echo json_encode($escola); ?>;
    let alunosSelecionados = [];
    
    function toggleSelecionarTodos() {
        const checkboxes = document.querySelectorAll('.selecionar-aluno');
        const selecionarTodos = document.getElementById('selecionarTodos');
        checkboxes.forEach(cb => {
            cb.checked = selecionarTodos.checked;
            if (selecionarTodos.checked) {
                adicionarAlunoSelecionado(cb);
            } else {
                removerAlunoSelecionado(cb);
            }
        });
        atualizarListaSelecionados();
    }
    
    function selecionarAluno(index) {
        const checkboxes = document.querySelectorAll('.selecionar-aluno');
        const cb = checkboxes[index];
        cb.checked = !cb.checked;
        if (cb.checked) {
            adicionarAlunoSelecionado(cb);
        } else {
            removerAlunoSelecionado(cb);
        }
        atualizarListaSelecionados();
        
        // Atualizar checkbox "Selecionar todos"
        const todos = document.querySelectorAll('.selecionar-aluno');
        const todosSelecionados = Array.from(todos).every(c => c.checked);
        document.getElementById('selecionarTodos').checked = todosSelecionados;
    }
    
    function adicionarAlunoSelecionado(cb) {
        const aluno = {
            id: cb.dataset.id,
            nome: cb.dataset.nome,
            matricula: cb.dataset.matricula,
            bi: cb.dataset.bi,
            turma_ano: cb.dataset.turma_ano,
            turma_nome: cb.dataset.turma_nome,
            turno: cb.dataset.turno,
            data_nascimento: cb.dataset.nascimento
        };
        
        if (!alunosSelecionados.find(a => a.id == aluno.id)) {
            alunosSelecionados.push(aluno);
        }
    }
    
    function removerAlunoSelecionado(cb) {
        const id = cb.dataset.id;
        alunosSelecionados = alunosSelecionados.filter(a => a.id != id);
    }
    
    function atualizarListaSelecionados() {
        const listaDiv = document.getElementById('selecionadosLista');
        const btnGerar = document.getElementById('btnGerarSelecionados');
        
        if (alunosSelecionados.length === 0) {
            listaDiv.innerHTML = '<p class="text-muted">Nenhum aluno selecionado. Clique em "Selecionar" ou marque os checkboxes.</p>';
            btnGerar.style.display = 'none';
        } else {
            let html = '<div class="row">';
            alunosSelecionados.forEach((aluno, idx) => {
                html += `
                    <div class="col-md-3 mb-2">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-light rounded">
                            <span><strong>${aluno.nome}</strong><br><small>${aluno.matricula}</small></span>
                            <button class="btn btn-sm btn-danger" onclick="removerSelecionado(${idx})">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            listaDiv.innerHTML = html;
            btnGerar.style.display = 'block';
        }
    }
    
    function removerSelecionado(index) {
        const aluno = alunosSelecionados[index];
        alunosSelecionados.splice(index, 1);
        
        // Desmarcar checkbox correspondente
        const checkboxes = document.querySelectorAll('.selecionar-aluno');
        checkboxes.forEach(cb => {
            if (cb.dataset.id == aluno.id) {
                cb.checked = false;
            }
        });
        
        atualizarListaSelecionados();
        
        const todos = document.querySelectorAll('.selecionar-aluno');
        const todosSelecionados = Array.from(todos).every(c => c.checked);
        document.getElementById('selecionarTodos').checked = todosSelecionados;
    }
    
    function gerarBoletinsSelecionados() {
        if (alunosSelecionados.length === 0) {
            alert('Selecione pelo menos um aluno para gerar os boletins.');
            return;
        }
        gerarBoletimMassa(alunosSelecionados);
    }
    
    function gerarBoletimMassa(alunosLista = null) {
        const alunosParaGerar = alunosLista || <?php echo json_encode($alunos); ?>;
        
        if (alunosParaGerar.length === 0) {
            alert('Nenhum aluno encontrado para gerar os boletins.');
            return;
        }
        
        // Mostrar loading
        const loadingDiv = document.createElement('div');
        loadingDiv.className = 'loading-overlay';
        loadingDiv.id = 'loadingOverlay';
        loadingDiv.innerHTML = `
            <div class="spinner"></div>
            <h4>Gerando boletins...</h4>
            <p id="loadingProgress">Processando 0 de ${alunosParaGerar.length} alunos</p>
        `;
        document.body.appendChild(loadingDiv);
        
        let processados = 0;
        let boletinsHtml = [];
        
        function processarProximo() {
            if (processados >= alunosParaGerar.length) {
                // Finalizar
                document.getElementById('loadingProgress').innerHTML = 'Finalizando...';
                setTimeout(() => {
                    document.getElementById('loadingOverlay').remove();
                    const janela = window.open('', '_blank');
                    janela.document.write(gerarHtmlCompleto(boletinsHtml));
                    janela.document.close();
                }, 500);
                return;
            }
            
            const aluno = alunosParaGerar[processados];
            document.getElementById('loadingProgress').innerHTML = `Processando ${processados + 1} de ${alunosParaGerar.length}: ${aluno.nome}`;
            
            const formData = new URLSearchParams();
            formData.append('aluno_id', aluno.id);
            formData.append('turma_id', <?php echo $turma_id; ?>);
            formData.append('ano_letivo_id', <?php echo $ano_letivo_id; ?>);
            
            fetch('ajax_buscar_notas_boletim.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const boletimHtml = gerarBoletimCompleto(aluno, data);
                    boletinsHtml.push(boletimHtml);
                }
                processados++;
                processarProximo();
            })
            .catch(error => {
                console.error('Erro ao processar aluno:', aluno.nome, error);
                processados++;
                processarProximo();
            });
        }
        
        processarProximo();
    }
    
    function gerarBoletimCompleto(aluno, data) {
        const disc = data.disciplinas;
        const limite = data.limite_aprovacao;
        const escala = data.escala_max;
        const isExame = data.is_classe_exame;
        const bimLiberados = data.bimestres_liberados;
        
        // Calcular médias
        let somaMedias = 0;
        let totalDisc = 0;
        disc.forEach(d => {
            let m1 = calcularMediaFinalDisciplina(d, 1, isExame);
            let m2 = calcularMediaFinalDisciplina(d, 2, isExame);
            let m3 = calcularMediaFinalDisciplina(d, 3, isExame);
            let m4 = calcularMediaFinalDisciplina(d, 4, isExame);
            let mediaAnual = (m1 + m2 + m3 + m4) / 4;
            if (mediaAnual > 0) {
                somaMedias += mediaAnual;
                totalDisc++;
            }
        });
        let mediaGeral = totalDisc > 0 ? (somaMedias / totalDisc).toFixed(1) : '0.0';
        let statusGeral = parseFloat(mediaGeral) >= limite ? 'Aprovado' : (parseFloat(mediaGeral) >= limite * 0.7 ? 'Recuperação' : (parseFloat(mediaGeral) > 0 ? 'Reprovado' : 'Pendente'));
        
        let tabelaHtml = `
            <div style="page-break-after: always; margin-bottom: 40px;">
                <div class="boletim-header">
                    <h2>${escola.nome}</h2>
                    <p>${escola.endereco || ''}</p>
                    <p>Ano Letivo: ${data.ano_letivo}</p>
                    <h4>BOLETIM DE NOTAS</h4>
                </div>
                
                <div class="info-aluno-preview">
                    <div class="row">
                        <div class="col-md-6"><p><strong>Nome:</strong> ${aluno.nome}</p></div>
                        <div class="col-md-6"><p><strong>Matrícula:</strong> ${aluno.matricula}</p></div>
                        <div class="col-md-6"><p><strong>Turma:</strong> ${aluno.turma_ano}ª - ${aluno.turma_nome}</p></div>
                        <div class="col-md-6"><p><strong>BI:</strong> ${aluno.bi || 'N/A'}</p></div>
                    </div>
                </div>
                
                <table class="table-boletim">
                    <thead>
                        <tr><th rowspan="2">Disciplina</th>
                        <th colspan="3">1º Bim</th><th colspan="3">2º Bim</th>
                        <th colspan="3">3º Bim</th><th colspan="3">4º Bim</th>
                        <th rowspan="2">Média</th><th rowspan="2">Status</th></tr>
                        <tr><th>MAC</th><th>NPT</th><th>MF</th>
                        <th>MAC</th><th>NPT</th><th>MF</th>
                        <th>MAC</th><th>NPT</th><th>MF</th>
                        <th>MAC</th><th>NPT</th><th>MF</th></tr>
                    </thead>
                    <tbody>
        `;
        
        disc.forEach(d => {
            tabelaHtml += `<tr><td class="text-start"><strong>${d.disciplina_nome}</strong></td>`;
            for (let b = 1; b <= 4; b++) {
                let mac = parseFloat(d['mac_' + b]) || 0;
                let npt = parseFloat(d['npt_' + b]) || 0;
                let media = calcularMediaFinalDisciplina(d, b, isExame);
                let liberado = bimLiberados.includes(b);
                
                if (!liberado) {
                    tabelaHtml += `<td colspan="3" style="background:#f8f9fa; text-align:center;">🔒</td>`;
                } else {
                    let macClass = mac >= limite ? 'nota-alta' : (mac > 0 ? 'nota-baixa' : '');
                    let nptClass = npt >= limite ? 'nota-alta' : (npt > 0 ? 'nota-baixa' : '');
                    let mediaClass = media >= limite ? 'nota-alta' : (media > 0 ? 'nota-baixa' : '');
                    let macText = mac > 0 ? mac.toFixed(1) : '-';
                    let nptText = npt > 0 ? npt.toFixed(1) : '-';
                    let mediaText = media > 0 ? media.toFixed(1) : '-';
                    tabelaHtml += `<td class="${macClass}">${macText}</td>
                                   <td class="${nptClass}">${nptText}</td>
                                   <td class="${mediaClass}"><strong>${mediaText}</strong></td>`;
                }
            }
            let m1 = calcularMediaFinalDisciplina(d, 1, isExame);
            let m2 = calcularMediaFinalDisciplina(d, 2, isExame);
            let m3 = calcularMediaFinalDisciplina(d, 3, isExame);
            let m4 = calcularMediaFinalDisciplina(d, 4, isExame);
            let mediaAnual = (m1 + m2 + m3 + m4) / 4;
            let statusDisc = mediaAnual >= limite ? 'Aprovado' : (mediaAnual >= limite * 0.7 ? 'Recuperação' : (mediaAnual > 0 ? 'Reprovado' : 'Pendente'));
            let statusClass = statusDisc === 'Aprovado' ? 'status-aprovado' : (statusDisc === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
            let mediaText = mediaAnual > 0 ? mediaAnual.toFixed(1) : '-';
            tabelaHtml += `<td><strong>${mediaText}</strong></td><td class="${statusClass}">${statusDisc}</td></tr>`;
        });
        
        let statusClassGeral = statusGeral === 'Aprovado' ? 'status-aprovado' : (statusGeral === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
        
        tabelaHtml += `
                    </tbody>
                </table>
                
                <div class="media-geral-preview">
                    <strong>MÉDIA GERAL:</strong> ${mediaGeral} pontos &nbsp;&nbsp;&nbsp;
                    <strong>STATUS:</strong> <span class="${statusClassGeral}">${statusGeral}</span><br>
                    <small>Escala: 0-${escala} | Mínimo aprovação: ${limite} pontos</small>
                </div>
                
                <div class="legenda-notas">
                    <h6>Legenda das Notas</h6>
                    <div class="row">
                        <div class="col-md-3"><span class="nota-alta">MAC</span> - Média Atividades Classe</div>
                        <div class="col-md-3"><span class="nota-baixa">NPT</span> - Nota Prova Trimestral</div>
                        <div class="col-md-3"><span class="nota-alta">MF</span> - Média Final</div>
                        <div class="col-md-3"><span class="nota-alta">🔒</span> - Bimestre bloqueado</div>
                    </div>
                    ${isExame ? '<div class="mt-2"><small>⚠️ Classes de Exame (6ª, 9ª, 12ª): 3º Bimestre = 40% MAC + 60% Exame</small></div>' : ''}
                    <div class="mt-2"><small>📌 Cálculo da Média Final: (MAC + NPT) / 2</small></div>
                    <div class="mt-2 text-muted small">Documento gerado eletronicamente por SIGE em ${new Date().toLocaleString()}</div>
                </div>
            </div>
        `;
        
        return tabelaHtml;
    }
    
    function calcularMediaFinalDisciplina(disc, bim, isClasseExame) {
        let mac = parseFloat(disc['mac_' + bim]) || 0;
        let npt = parseFloat(disc['npt_' + bim]) || 0;
        let exameNormal = parseFloat(disc['exame_normal_' + bim]) || 0;
        let exameRecurso = parseFloat(disc['exame_recurso_' + bim]) || 0;
        let exameEspecial = parseFloat(disc['exame_especial_' + bim]) || 0;
        let exameOral = parseFloat(disc['exame_oral_' + bim]) || 0;
        let exameEscrito = parseFloat(disc['exame_escrito_' + bim]) || 0;
        
        let mediaParcial = (mac + npt) / 2;
        
        if (bim == 3 && isClasseExame) {
            if (exameRecurso > 0) return exameRecurso;
            if (disc.is_lingua) {
                let mediaExame = 0;
                if (exameOral > 0 && exameEscrito > 0) mediaExame = (exameOral + exameEscrito) / 2;
                else if (exameOral > 0) mediaExame = exameOral;
                else if (exameEscrito > 0) mediaExame = exameEscrito;
                return (mac * 0.4) + (mediaExame * 0.6);
            } else {
                if (exameNormal > 0) return (mac * 0.4) + (exameNormal * 0.6);
                return mac;
            }
        }
        
        if (exameRecurso > 0) return (mediaParcial + exameRecurso) / 2;
        if (exameNormal > 0) return (mediaParcial + exameNormal) / 2;
        if (exameEspecial > 0) return exameEspecial;
        return mediaParcial;
    }
    
    function gerarHtmlCompleto(boletinsHtml) {
        return `
            <!DOCTYPE html>
            <html>
            <head>
                <title>Boletins da Turma</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    * { margin: 0; padding: 0; box-sizing: border-box; }
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; padding: 20px; }
                    .boletim-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e5799; padding-bottom: 15px; }
                    .info-aluno-preview { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
                    .table-boletim { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10px; }
                    .table-boletim th { background: #1e5799; color: white; padding: 6px; text-align: center; }
                    .table-boletim td { border: 1px solid #ddd; padding: 4px; text-align: center; }
                    .table-boletim td.text-start { text-align: left; }
                    .media-geral-preview { text-align: center; padding: 12px; background: #e8f4fd; border-radius: 8px; margin-top: 15px; }
                    .legenda-notas { background: #f8f9fa; padding: 12px; border-radius: 8px; margin-top: 15px; font-size: 10px; }
                    .status-aprovado { color: #27ae60; font-weight: bold; }
                    .status-recuperacao { color: #f39c12; font-weight: bold; }
                    .status-reprovado { color: #e74c3c; font-weight: bold; }
                    .nota-alta { color: #27ae60; font-weight: bold; }
                    .nota-baixa { color: #e74c3c; font-weight: bold; }
                    @media print {
                        body { padding: 0; margin: 0; }
                        .page-break { page-break-after: always; }
                    }
                </style>
            </head>
            <body>
                ${boletinsHtml.join('')}
                <script>
                    window.onload = function() { setTimeout(() => { window.print(); setTimeout(() => window.close(), 500); }, 1000); };
                <\/script>
            </body>
            </html>
        `;
    }
    
    function abrirPreview(alunoId, nome, matricula, bi, turmaAno, turmaNome, turno, dataNascimento) {
        const modal = document.getElementById('modalPreview');
        const previewBody = document.getElementById('previewBody');
        
        dadosAluno = { nome, matricula, bi, turma_ano: turmaAno, turma_nome: turmaNome, turno, data_nascimento: dataNascimento };
        
        previewBody.innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Carregando notas...</div>';
        modal.style.display = 'block';
        
        const formData = new URLSearchParams();
        formData.append('aluno_id', alunoId);
        formData.append('turma_id', <?php echo $turma_id; ?>);
        formData.append('ano_letivo_id', <?php echo $ano_letivo_id; ?>);
        
        fetch('ajax_buscar_notas_boletim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                disciplinas = data.disciplinas;
                bimestres_liberados = data.bimestres_liberados;
                total_pagamentos = data.total_pagamentos;
                limiteAprovacao = data.limite_aprovacao;
                escalaMax = data.escala_max;
                anoLetivo = data.ano_letivo;
                isClasseExame = data.is_classe_exame;
                renderizarPreview();
            } else {
                previewBody.innerHTML = `<div class="alert alert-danger">Erro: ${data.message || 'Dados não encontrados'}</div>`;
            }
        })
        .catch(error => {
            previewBody.innerHTML = `<div class="alert alert-danger">Erro de conexão: ${error.message}</div>`;
        });
    }
    
    function calcularMediaFinal(disc, bim) {
        let mac = parseFloat(disc['mac_' + bim]) || 0;
        let npt = parseFloat(disc['npt_' + bim]) || 0;
        let exameNormal = parseFloat(disc['exame_normal_' + bim]) || 0;
        let exameRecurso = parseFloat(disc['exame_recurso_' + bim]) || 0;
        let exameEspecial = parseFloat(disc['exame_especial_' + bim]) || 0;
        let exameOral = parseFloat(disc['exame_oral_' + bim]) || 0;
        let exameEscrito = parseFloat(disc['exame_escrito_' + bim]) || 0;
        
        let mediaParcial = (mac + npt) / 2;
        
        if (bim == 3 && isClasseExame) {
            if (exameRecurso > 0) return exameRecurso;
            if (disc.is_lingua) {
                let mediaExame = 0;
                if (exameOral > 0 && exameEscrito > 0) mediaExame = (exameOral + exameEscrito) / 2;
                else if (exameOral > 0) mediaExame = exameOral;
                else if (exameEscrito > 0) mediaExame = exameEscrito;
                return (mac * 0.4) + (mediaExame * 0.6);
            } else {
                if (exameNormal > 0) return (mac * 0.4) + (exameNormal * 0.6);
                return mac;
            }
        }
        
        if (exameRecurso > 0) return (mediaParcial + exameRecurso) / 2;
        if (exameNormal > 0) return (mediaParcial + exameNormal) / 2;
        if (exameEspecial > 0) return exameEspecial;
        return mediaParcial;
    }
    
    function renderizarPreview() {
        const previewBody = document.getElementById('previewBody');
        
        if (bimestres_liberados.length === 0) {
            previewBody.innerHTML = `
                <div class="alert alert-warning text-center">
                    <i class="fas fa-lock fa-3x mb-3"></i>
                    <h5>Boletim Bloqueado</h5>
                    <p>O boletim deste aluno não está disponível porque não há registo de pagamento.</p>
                </div>
            `;
            return;
        }
        
        let somaMedias = 0;
        let totalDisciplinas = 0;
        disciplinas.forEach(disc => {
            let m1 = calcularMediaFinal(disc, 1);
            let m2 = calcularMediaFinal(disc, 2);
            let m3 = calcularMediaFinal(disc, 3);
            let m4 = calcularMediaFinal(disc, 4);
            let mediaAnual = (m1 + m2 + m3 + m4) / 4;
            if (mediaAnual > 0) {
                somaMedias += mediaAnual;
                totalDisciplinas++;
            }
        });
        let mediaGeral = totalDisciplinas > 0 ? (somaMedias / totalDisciplinas).toFixed(1) : '0.0';
        let statusGeral = parseFloat(mediaGeral) >= limiteAprovacao ? 'Aprovado' : (parseFloat(mediaGeral) >= limiteAprovacao * 0.7 ? 'Recuperação' : (parseFloat(mediaGeral) > 0 ? 'Reprovado' : 'Pendente'));
        
        let html = `
            <div class="boletim-preview">
                <div class="boletim-header">
                    <h2>${escola.nome}</h2>
                    <p>${escola.endereco || ''}</p>
                    <p>Ano Letivo: ${anoLetivo}</p>
                    <h4>BOLETIM DE NOTAS</h4>
                </div>
                
                <div class="info-aluno-preview">
                    <div class="row">
                        <div class="col-md-6"><p><strong>Nome:</strong> ${dadosAluno.nome}</p></div>
                        <div class="col-md-6"><p><strong>Matrícula:</strong> ${dadosAluno.matricula}</p></div>
                        <div class="col-md-6"><p><strong>Turma:</strong> ${dadosAluno.turma_ano}ª - ${dadosAluno.turma_nome}</p></div>
                        <div class="col-md-6"><p><strong>BI:</strong> ${dadosAluno.bi || 'N/A'}</p></div>
                    </div>
                </div>
        `;
        
        if (total_pagamentos >= 2) {
            html += `<div class="alert alert-success text-center mb-3"><i class="fas fa-check-circle"></i> Boletim liberado (${total_pagamentos} pagamentos registados)</div>`;
        } else if (total_pagamentos >= 1) {
            html += `<div class="alert alert-warning text-center mb-3"><i class="fas fa-info-circle"></i> Boletim parcialmente liberado (apenas 1º Bimestre)</div>`;
        }
        
        html += `<div class="bimestre-filters">
            <button class="bimestre-btn ${bimestreAtual === 0 ? 'active' : ''}" onclick="filtrarBimestre(0)">Todos</button>`;
        for (let b = 1; b <= 4; b++) {
            let liberado = bimestres_liberados.includes(b);
            if (liberado) {
                html += `<button class="bimestre-btn ${bimestreAtual === b ? 'active' : ''}" onclick="filtrarBimestre(${b})">${b}º Bimestre</button>`;
            } else {
                html += `<button class="bimestre-btn disabled" disabled>${b}º Bimestre 🔒</button>`;
            }
        }
        html += `</div>`;
        
        html += `
            <table class="table-boletim">
                <thead><tr><th rowspan="2">Disciplina</th>
                <th colspan="3">1º Bim</th><th colspan="3">2º Bim</th>
                <th colspan="3">3º Bim</th><th colspan="3">4º Bim</th>
                <th rowspan="2">Média</th><th rowspan="2">Status</th></tr>
                <tr><th>MAC</th><th>NPT</th><th>MF</th>
                <th>MAC</th><th>NPT</th><th>MF</th>
                <th>MAC</th><th>NPT</th><th>MF</th>
                <th>MAC</th><th>NPT</th><th>MF</th></tr></thead>
                <tbody>
        `;
        
        disciplinas.forEach(disc => {
            let medias = [];
            for (let b = 1; b <= 4; b++) medias[b] = calcularMediaFinal(disc, b);
            let mediaAnual = (medias[1] + medias[2] + medias[3] + medias[4]) / 4;
            let statusDisc = mediaAnual >= limiteAprovacao ? 'Aprovado' : (mediaAnual >= limiteAprovacao * 0.7 ? 'Recuperação' : (mediaAnual > 0 ? 'Reprovado' : 'Pendente'));
            let statusClass = statusDisc === 'Aprovado' ? 'status-aprovado' : (statusDisc === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
            
            html += `<tr><td class="text-start"><strong>${disc.disciplina_nome}</strong></td>`;
            for (let b = 1; b <= 4; b++) {
                let liberado = bimestres_liberados.includes(b);
                let mac = parseFloat(disc['mac_' + b]) || 0;
                let npt = parseFloat(disc['npt_' + b]) || 0;
                let media = medias[b];
                
                if (!liberado && bimestreAtual !== 0 && bimestreAtual !== b) {
                    html += `<td colspan="3" style="background:#f8f9fa;">🔒</td>`;
                    continue;
                }
                
                let macClass = mac >= limiteAprovacao ? 'nota-alta' : (mac > 0 ? 'nota-baixa' : '');
                let nptClass = npt >= limiteAprovacao ? 'nota-alta' : (npt > 0 ? 'nota-baixa' : '');
                let mediaClass = media >= limiteAprovacao ? 'nota-alta' : (media > 0 ? 'nota-baixa' : '');
                let macText = mac > 0 ? mac.toFixed(1) : '-';
                let nptText = npt > 0 ? npt.toFixed(1) : '-';
                let mediaText = media > 0 ? media.toFixed(1) : '-';
                
                if (bimestreAtual === 0 || bimestreAtual === b) {
                    html += `<td class="${macClass}">${macText}</td>
                             <td class="${nptClass}">${nptText}</td>
                             <td class="${mediaClass}"><strong>${mediaText}</strong></td>`;
                } else {
                    html += `<td colspan="3" style="background:#f8f9fa;">&nbsp;</td>`;
                }
            }
            let mediaAnualText = mediaAnual > 0 ? mediaAnual.toFixed(1) : '-';
            let mediaAnualClass = mediaAnual >= limiteAprovacao ? 'nota-alta' : (mediaAnual > 0 ? 'nota-baixa' : '');
            html += `<td class="${mediaAnualClass}"><strong>${mediaAnualText}</strong><td>
                     <td class="${statusClass}">${statusDisc}</td></tr>`;
        });
        
        let statusGeralClass = statusGeral === 'Aprovado' ? 'status-aprovado' : (statusGeral === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
        
        html += `
                </tbody>
            </table>
            
            <div class="media-geral-preview">
                <strong>MÉDIA GERAL:</strong> ${mediaGeral} pontos &nbsp;&nbsp;&nbsp;
                <strong>STATUS:</strong> <span class="${statusGeralClass}">${statusGeral}</span><br>
                <small>Escala: 0-${escalaMax} | Mínimo aprovação: ${limiteAprovacao} pontos</small>
            </div>
            
            <div class="legenda-notas">
                <h6>Legenda das Notas</h6>
                <div class="row">
                    <div class="col-md-3"><span class="nota-alta">MAC</span> - Média Atividades Classe</div>
                    <div class="col-md-3"><span class="nota-baixa">NPT</span> - Nota Prova Trimestral</div>
                    <div class="col-md-3"><span class="nota-alta">MF</span> - Média Final</div>
                    <div class="col-md-3"><span class="nota-alta">🔒</span> - Bimestre bloqueado (sem pagamento)</div>
                </div>
                ${isClasseExame ? '<div class="mt-2"><small>⚠️ Classes de Exame (6ª, 9ª, 12ª): 3º Bimestre = 40% MAC + 60% Exame</small></div>' : ''}
                <div class="mt-2"><small>📌 Exames complementares substituem a média quando disponíveis</small></div>
                <div class="mt-2"><small>📌 Cálculo da Média Final: (MAC + NPT) / 2</small></div>
            </div>
            
            <div class="text-center">
                <button class="btn-imprimir" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
            </div>
        `;
        
        previewBody.innerHTML = html;
    }
    
    function filtrarBimestre(bimestre) {
        bimestreAtual = bimestre;
        renderizarPreview();
    }
    
    function fecharPreview() {
        document.getElementById('modalPreview').style.display = 'none';
    }
    
    document.getElementById('formFiltros')?.addEventListener('change', function() {
        this.submit();
    });
</script>
</body>
</html>