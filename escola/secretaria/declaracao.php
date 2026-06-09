<?php
// escola/secretaria/declaracao.php - Emissão de Declarações

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

$is_secretaria = ($_SESSION['usuario_tipo'] == 'secretaria' || ($_SESSION['papel'] ?? '') == 'secretaria');
$is_admin = ($_SESSION['usuario_tipo'] == 'super_admin' || $_SESSION['usuario_tipo'] == 'admin_escola' || $_SESSION['usuario_tipo'] == 'diretor' || ($_SESSION['papel'] ?? '') == 'admin');

if (!$is_secretaria && !$is_admin) {
    header('Location: ../dashboard.php?msg=acesso_negado');
    exit;
}

// Buscar alunos
$sql_alunos = "SELECT e.id, e.nome, e.matricula, t.nome as turma_nome 
               FROM estudantes e 
               LEFT JOIN matriculas m ON m.estudante_id = e.id 
               LEFT JOIN turmas t ON t.id = m.turma_id 
               WHERE m.escola_id = :escola_id AND m.status = 'ativa' 
               GROUP BY e.id ORDER BY e.nome ASC";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':escola_id' => $escola_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Buscar escola
$sql_escola = "SELECT nome, endereco, telefone, email FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Declarações | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f5; }
        .main-content { margin-left: 280px; padding: 20px; min-height: 100vh; }
        @media (max-width: 768px) { .main-content { margin-left: 0; } }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .declaracao-preview { border: 1px solid #ddd; padding: 30px; background: white; border-radius: 10px; }
        @media print {
            body * { visibility: hidden; }
            .declaracao-preview, .declaracao-preview * { visibility: visible; }
            .declaracao-preview { position: absolute; top: 0; left: 0; width: 100%; margin: 0; padding: 20px; }
            .btn, .card-header, .no-print { display: none; }
        }
    </style>
</head>
<body>
    <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-file-signature"></i> Emissão de Declaração Escolar</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="formDeclaracao" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Aluno</label>
                        <select name="aluno_id" id="aluno_id" class="form-select" required>
                            <option value="">Selecione um aluno</option>
                            <?php foreach ($alunos as $aluno): ?>
                            <option value="<?php echo $aluno['id']; ?>" data-nome="<?php echo htmlspecialchars($aluno['nome']); ?>" data-turma="<?php echo $aluno['turma_nome']; ?>">
                                <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo $aluno['matricula']; ?> - <?php echo $aluno['turma_nome']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tipo de Declaração</label>
                        <select name="tipo" id="tipo" class="form-select" required>
                            <option value="matricula">Declaração de Matrícula</option>
                            <option value="frequencia">Declaração de Frequência</option>
                            <option value="escolaridade">Declaração de Escolaridade</option>
                            <option value="transferencia">Declaração de Transferência</option>
                        </select>
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Observações (opcional)</label>
                        <textarea name="observacoes" id="observacoes" class="form-control" rows="2" placeholder="Informações adicionais..."></textarea>
                    </div>
                    <div class="col-md-12">
                        <button type="button" class="btn btn-primary" onclick="gerarDeclaracao()">
                            <i class="fas fa-file-alt"></i> Gerar Declaração
                        </button>
                        <button type="button" class="btn btn-success" onclick="window.print()" style="display:none;" id="btnImprimir">
                            <i class="fas fa-print"></i> Imprimir
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div id="declaracaoContainer" style="display: none;">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-eye"></i> Visualização da Declaração</h5>
                </div>
                <div class="card-body">
                    <div id="declaracaoPreview" class="declaracao-preview"></div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function gerarDeclaracao() {
            var alunoId = $('#aluno_id').val();
            var alunoNome = $('#aluno_id option:selected').data('nome');
            var alunoTurma = $('#aluno_id option:selected').data('turma');
            var tipo = $('#tipo').val();
            var observacoes = $('#observacoes').val();
            var dataAtual = new Date().toLocaleDateString('pt-AO');
            
            if (!alunoId) {
                alert('Selecione um aluno');
                return;
            }
            
            var declaracaoHtml = '';
            var titulo = '';
            
            switch(tipo) {
                case 'matricula':
                    titulo = 'DECLARAÇÃO DE MATRÍCULA';
                    declaracaoHtml = `
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h2>${titulo}</h2>
                            <hr>
                        </div>
                        <p style="text-indent: 50px;">Declaramos para os devidos fins que o(a) aluno(a) <strong>${alunoNome}</strong> encontra-se regularmente matriculado(a) nesta Instituição de Ensino, no <strong>${alunoTurma}</strong>, no ano letivo de ${new Date().getFullYear()}.</p>
                        <p>Esta declaração é emitida a pedido do(a) interessado(a) para fins de comprovação de matrícula.</p>
                    `;
                    break;
                case 'frequencia':
                    titulo = 'DECLARAÇÃO DE FREQUÊNCIA';
                    declaracaoHtml = `
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h2>${titulo}</h2>
                            <hr>
                        </div>
                        <p style="text-indent: 50px;">Declaramos para os devidos fins que o(a) aluno(a) <strong>${alunoNome}</strong>, matriculado(a) no <strong>${alunoTurma}</strong> desta Instituição de Ensino, tem frequentado regularmente as aulas no presente ano letivo.</p>
                        <p>Sua frequência tem sido satisfatória, cumprindo com as obrigações escolares estabelecidas.</p>
                    `;
                    break;
                case 'escolaridade':
                    titulo = 'DECLARAÇÃO DE ESCOLARIDADE';
                    declaracaoHtml = `
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h2>${titulo}</h2>
                            <hr>
                        </div>
                        <p style="text-indent: 50px;">Declaramos para os devidos fins que o(a) aluno(a) <strong>${alunoNome}</strong> está cursando o <strong>${alunoTurma}</strong> nesta Instituição de Ensino, com previsão de conclusão para o final do ano letivo de ${new Date().getFullYear()}.</p>
                        <p>Durante o período letivo, o(a) aluno(a) vem demonstrando bom desempenho acadêmico.</p>
                    `;
                    break;
                case 'transferencia':
                    titulo = 'DECLARAÇÃO DE TRANSFERÊNCIA';
                    declaracaoHtml = `
                        <div style="text-align: center; margin-bottom: 30px;">
                            <h2>${titulo}</h2>
                            <hr>
                        </div>
                        <p style="text-indent: 50px;">Declaramos para os devidos fins que o(a) aluno(a) <strong>${alunoNome}</strong>, matriculado(a) no <strong>${alunoTurma}</strong> nesta Instituição de Ensino, está autorizado(a) a solicitar transferência para outra unidade de ensino, estando quite com todas as obrigações escolares.</p>
                        <p>A documentação escolar está disponível para ser retirada na Secretaria da Escola.</p>
                    `;
                    break;
            }
            
            var finalHtml = `
                <div style="font-family: 'Times New Roman', Times, serif; max-width: 800px; margin: 0 auto;">
                    <div style="text-align: center;">
                        <h2>${titulo}</h2>
                    </div>
                    ${declaracaoHtml}
                    ${observacoes ? `<p><strong>Observações:</strong> ${observacoes}</p>` : ''}
                    <div style="margin-top: 40px;">
                        <p style="text-align: center;">Emitido em ${<?php echo htmlspecialchars($escola['nome']); ?>}, ${dataAtual}</p>
                        <div style="margin-top: 50px; text-align: center;">
                            <hr style="width: 60%; margin: 0 auto;">
                            <p>Assinatura do Diretor</p>
                            <p><strong><?php echo htmlspecialchars($escola['nome']); ?></strong></p>
                        </div>
                    </div>
                    <div style="margin-top: 30px; font-size: 12px; text-align: center; color: #666;">
                        <hr>
                        <p><?php echo htmlspecialchars($escola['endereco'] ?? ''); ?> | Tel: <?php echo htmlspecialchars($escola['telefone'] ?? ''); ?></p>
                        <p>Documento emitido eletronicamente - Sistema SIGE Angola</p>
                    </div>
                </div>
            `;
            
            $('#declaracaoPreview').html(finalHtml);
            $('#declaracaoContainer').show();
            $('#btnImprimir').show();
            $('html, body').animate({ scrollTop: $('#declaracaoContainer').offset().top }, 500);
        }
    </script>
</body>
</html>