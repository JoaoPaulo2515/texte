<?php
// escola/secretaria/emitir_certificado.php - Emitir Certificado do Aluno

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Verificar se o ID do aluno foi passado
$estudante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($estudante_id <= 0) {
    header('Location: lista_alunos.php?erro=ID do aluno inválido');
    exit;
}

// ============================================
// BUSCAR DADOS DO ALUNO
// ============================================
$sql_aluno = "
    SELECT 
        e.id,
        e.nome,
        e.matricula,
        e.bi,
        e.data_nascimento,
        e.genero,
        e.email,
        e.telefone,
        e.endereco,
        e.foto,
        e.status as aluno_status,
        e.pais_nome,
        e.cidade_nome,
        e.provincia_nome,
        e.municipio_nome,
        e.comuna_nome,
        e.pai_nome,
        e.mae_nome,
        e.encarregado_nome,
        e.encarregado_parentesco,
        e.ano_letivo,
        e.ano_escolar,
        e.curso,
        e.nivel,
        e.classe,
        e.created_at as data_cadastro,
        m.id as matricula_id,
        m.turma_id,
        m.turno,
        m.sala,
        m.classe as matricula_classe,
        m.curso as matricula_curso,
        m.nivel as matricula_nivel,
        m.ano_letivo as matricula_ano,
        m.numero_processo,
        m.status as matricula_status,
        m.data_matricula,
        t.nome as turma_nome,
        t.ano as turma_ano,
        es.nome as escola_nome,
        es.nome as escola_razao,
        es.logo as escola_logo,
        es.endereco as escola_endereco,
        es.telefone as escola_telefone,
        es.email as escola_email
    FROM estudantes e
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    LEFT JOIN escolas es ON es.id = e.escola_id
    WHERE e.id = :estudante_id AND e.escola_id = :escola_id
";

$stmt_aluno = $conn->prepare($sql_aluno);
$stmt_aluno->execute([':estudante_id' => $estudante_id, ':escola_id' => $escola_id]);
$aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: lista_alunos.php?erro=Aluno não encontrado');
    exit;
}

// ============================================
// BUSCAR NOTAS DO ALUNO
// ============================================
$sql_notas = "
    SELECT 
        n.id,
        n.disciplina_id,
        n.bimestre,
        n.mac,
        n.npt,
        n.exame_normal,
        n.exame_recurso,
        n.exame_especial,
        n.media_final,
        n.status as nota_status,
        d.nome as disciplina_nome
    FROM notas n
    INNER JOIN disciplinas d ON d.id = n.disciplina_id
    WHERE n.estudante_id = :estudante_id AND n.ano_letivo_id = (SELECT id FROM ano_letivo WHERE ativo = 1 LIMIT 1)
    ORDER BY n.bimestre, d.nome
";

$stmt_notas = $conn->prepare($sql_notas);
$stmt_notas->execute([':estudante_id' => $estudante_id]);
$notas = $stmt_notas->fetchAll(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================

function formatarData($data) {
    if (empty($data)) return '-';
    return date('d/m/Y', strtotime($data));
}

function formatarDataExtenso($data) {
    if (empty($data)) return '';
    
    $meses = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
    ];
    
    $timestamp = strtotime($data);
    $dia = date('d', $timestamp);
    $mes = (int)date('m', $timestamp);
    $ano = date('Y', $timestamp);
    
    return $dia . ' de ' . $meses[$mes] . ' de ' . $ano;
}

function getSituacaoAluno($media_final) {
    if ($media_final >= 10) {
        return ['texto' => 'Aprovado', 'classe' => 'aprovado'];
    } elseif ($media_final >= 7) {
        return ['texto' => 'Recuperação', 'classe' => 'recuperacao'];
    } else {
        return ['texto' => 'Reprovado', 'classe' => 'reprovado'];
    }
}

function getMediaGeral($notas) {
    if (empty($notas)) return 0;
    $soma = 0;
    foreach ($notas as $nota) {
        $soma += $nota['media_final'] ?? 0;
    }
    return round($soma / count($notas), 1);
}

// Processar formulário
$tipo_certificado = isset($_POST['tipo_certificado']) ? $_POST['tipo_certificado'] : 'declaracao';
$output_format = isset($_POST['output_format']) ? $_POST['output_format'] : 'html';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gerar_certificado'])) {
    if ($output_format == 'pdf') {
        // Redirecionar para gerar PDF
        header('Location: gerar_pdf_certificado.php?id=' . $estudante_id . '&tipo=' . $tipo_certificado);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emitir Certificado | Secretaria | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
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
        
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white; border-radius: 15px 15px 0 0; padding: 15px 20px; }
        .btn-primary { background: #006B3E; border: none; }
        .btn-primary:hover { background: #004d2d; }
        .btn-success { background: #28a745; border: none; }
        .btn-success:hover { background: #1e7e34; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        
        /* Estilos do Certificado */
        .certificado-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .certificado-bordas {
            border: 2px solid #006B3E;
            padding: 30px;
            position: relative;
        }
        .certificado-bordas:before {
            content: '';
            position: absolute;
            top: 10px;
            left: 10px;
            right: 10px;
            bottom: 10px;
            border: 1px solid #006B3E;
            pointer-events: none;
        }
        .certificado-titulo {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            color: #006B3E;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .certificado-subtitulo {
            text-align: center;
            font-size: 16px;
            color: #666;
            margin-bottom: 30px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        .certificado-corpo {
            font-size: 14px;
            line-height: 1.8;
            text-align: justify;
        }
        .certificado-assinatura {
            margin-top: 40px;
            text-align: center;
        }
        .certificado-assinatura-linha {
            width: 200px;
            border-top: 1px solid #000;
            margin: 0 auto 10px auto;
        }
        .foto-aluno-certificado {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #006B3E;
            margin-bottom: 15px;
        }
        .nota-aprovado { color: #28a745; font-weight: bold; }
        .nota-reprovado { color: #dc3545; font-weight: bold; }
        .nota-recuperacao { color: #ffc107; font-weight: bold; }
    </style>
</head>
<body>
    
     <?php include '../menu_escola.php'; ?>
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-certificate"></i> Emitir Certificado</h2>
            <div>
                <a href="ver_aluno.php?id=<?php echo $estudante_id; ?>" class="btn btn-info">
                    <i class="fas fa-eye"></i> Ver Aluno
                </a>
                <a href="lista_alunos.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Voltar
                </a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-cog"></i> Opções</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="formCertificado">
                            <div class="mb-3">
                                <label class="form-label">Tipo de Documento</label>
                                <select name="tipo_certificado" id="tipo_certificado" class="form-select" onchange="atualizarPreview()">
                                    <option value="declaracao">Declaração de Matrícula</option>
                                    <option value="certificado_conclusao">Certificado de Conclusão</option>
                                    <option value="atestado_frequencia">Atestado de Frequência</option>
                                    <option value="historico_notas">Histórico de Notas</option>
                                    <option value="transferencia">Declaração de Transferência</option>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">Formato de Saída</label>
                                <select name="output_format" class="form-select">
                                    <option value="html">Visualizar (HTML)</option>
                                    <option value="pdf">Baixar (PDF)</option>
                                </select>
                            </div>
                            
                            <hr>
                            
                            <div class="mb-3">
                                <label class="form-label">Dados do Aluno</label>
                                <div class="small">
                                    <strong>Nome:</strong> <?php echo htmlspecialchars($aluno['nome'] ?? ''); ?><br>
                                    <strong>Matrícula:</strong> <?php echo htmlspecialchars($aluno['matricula'] ?? ''); ?><br>
                                    <strong>BI:</strong> <?php echo htmlspecialchars($aluno['bi'] ?? 'Não informado'); ?><br>
                                    <strong>Turma:</strong> <?php echo htmlspecialchars($aluno['turma_nome'] ?? 'Não atribuída'); ?><br>
                                    <strong>Classe:</strong> <?php echo htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? ''); ?>
                                </div>
                            </div>
                            
                            <button type="submit" name="gerar_certificado" class="btn btn-primary w-100">
                                <i class="fas fa-download"></i> Gerar Documento
                            </button>
                        </form>
                    </div>
                </div>
                
                <div class="card mt-3">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-line"></i> Resumo Académico</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <strong>Média Geral:</strong>
                            <span class="badge bg-primary fs-6"><?php echo getMediaGeral($notas); ?> valores</span>
                        </div>
                        <div class="mb-2">
                            <strong>Disciplinas:</strong>
                            <span class="badge bg-info"><?php echo count($notas); ?></span>
                        </div>
                        <div class="mb-2">
                            <strong>Situação:</strong>
                            <?php 
                            $situacao = getSituacaoAluno(getMediaGeral($notas));
                            $badge_class = $situacao['classe'] == 'aprovado' ? 'success' : ($situacao['classe'] == 'recuperacao' ? 'warning' : 'danger');
                            ?>
                            <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $situacao['texto']; ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="certificado-container" id="certificadoPreview">
                    <?php if ($tipo_certificado == 'declaracao'): ?>
                    <!-- Declaração de Matrícula -->
                    <div class="certificado-bordas">
                        <div class="text-center mb-3">
                            <?php if (!empty($aluno['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $aluno['escola_logo'])): ?>
                                <img src="../../uploads/escolas/logos/<?php echo $aluno['escola_logo']; ?>" style="height: 80px;">
                            <?php else: ?>
                                <i class="fas fa-school fa-3x text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="certificado-titulo">DECLARAÇÃO DE MATRÍCULA</div>
                        <div class="certificado-subtitulo"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA'); ?></div>
                        
                        <div class="certificado-corpo">
                            <p>Ao presente instrumento declaramos para os devidos fins que o(a) aluno(a) <strong><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></strong>, 
                            portador(a) do Bilhete de Identidade nº <strong><?php echo htmlspecialchars($aluno['bi'] ?? '_______________'); ?></strong>, 
                            encontra-se regularmente matriculado(a) nesta instituição de ensino no ano letivo de <strong><?php echo htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')); ?></strong>, 
                            na <strong><?php echo htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? ''); ?></strong> classe, 
                            turma <strong><?php echo htmlspecialchars($aluno['turma_nome'] ?? ''); ?></strong>, 
                            no turno <strong><?php echo htmlspecialchars($aluno['turno'] ?? ''); ?></strong>.</p>
                            
                            <p>O presente documento serve para os fins que se fizerem necessários, nomeadamente para comprovação de matrícula.</p>
                            
                            <p><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA'); ?>, aos <?php echo formatarDataExtenso(date('Y-m-d')); ?>.</p>
                        </div>
                        
                        <div class="certificado-assinatura">
                            <div class="certificado-assinatura-linha"></div>
                            <p>Secretaria Académica</p>
                            <p><small>Carimbo e Assinatura</small></p>
                        </div>
                    </div>
                    
                    <?php elseif ($tipo_certificado == 'certificado_conclusao'): ?>
                    <!-- Certificado de Conclusão -->
                    <div class="certificado-bordas">
                        <div class="text-center mb-3">
                            <?php if (!empty($aluno['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $aluno['escola_logo'])): ?>
                                <img src="../../uploads/escolas/logos/<?php echo $aluno['escola_logo']; ?>" style="height: 80px;">
                            <?php else: ?>
                                <i class="fas fa-graduation-cap fa-3x text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="certificado-titulo">CERTIFICADO DE CONCLUSÃO</div>
                        <div class="certificado-subtitulo"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA'); ?></div>
                        
                        <div class="certificado-corpo">
                            <p>Certificamos que <strong><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></strong>, 
                            filho(a) de <strong><?php echo htmlspecialchars($aluno['pai_nome'] ?? ''); ?></strong> e 
                            <strong><?php echo htmlspecialchars($aluno['mae_nome'] ?? ''); ?></strong>, 
                            nascido(a) aos <?php echo formatarDataExtenso($aluno['data_nascimento'] ?? ''); ?>, 
                            portador(a) do BI nº <strong><?php echo htmlspecialchars($aluno['bi'] ?? '_______________'); ?></strong>, 
                            concluiu com aproveitamento o <strong><?php echo htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? ''); ?></strong> 
                            do <strong><?php echo htmlspecialchars($aluno['nivel'] ?? 'Ensino'); ?></strong>, 
                            no ano letivo de <strong><?php echo htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')); ?></strong>.</p>
                            
                            <p>A média final do aluno foi de <strong><?php echo getMediaGeral($notas); ?> valores</strong>, 
                            tendo sido considerado(a) <strong><?php echo $situacao['texto']; ?></strong>.</p>
                            
                            <p>Para constar, emitimos o presente certificado.</p>
                            
                            <p><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA'); ?>, aos <?php echo formatarDataExtenso(date('Y-m-d')); ?>.</p>
                        </div>
                        
                        <div class="certificado-assinatura">
                            <div class="row">
                                <div class="col-6">
                                    <div class="certificado-assinatura-linha"></div>
                                    <p>Director Pedagógico</p>
                                </div>
                                <div class="col-6">
                                    <div class="certificado-assinatura-linha"></div>
                                    <p>Secretário</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($tipo_certificado == 'atestado_frequencia'): ?>
                    <!-- Atestado de Frequência -->
                    <div class="certificado-bordas">
                        <div class="text-center mb-3">
                            <?php if (!empty($aluno['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $aluno['escola_logo'])): ?>
                                <img src="../../uploads/escolas/logos/<?php echo $aluno['escola_logo']; ?>" style="height: 80px;">
                            <?php else: ?>
                                <i class="fas fa-clipboard-list fa-3x text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="certificado-titulo">ATESTADO DE FREQUÊNCIA</div>
                        <div class="certificado-subtitulo"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA'); ?></div>
                        
                        <div class="certificado-corpo">
                            <p>Atestamos para os devidos fins que o(a) aluno(a) <strong><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></strong>, 
                            está regularmente matriculado(a) e frequenta com assiduidade o <strong><?php echo htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? ''); ?></strong> 
                            desta instituição de ensino, no ano letivo de <strong><?php echo htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')); ?></strong>, 
                            no turno <strong><?php echo htmlspecialchars($aluno['turno'] ?? ''); ?></strong>.</p>
                            
                            <p>O presente atestado é emitido a pedido do interessado para os fins que se fizerem necessários.</p>
                            
                            <p><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA'); ?>, aos <?php echo formatarDataExtenso(date('Y-m-d')); ?>.</p>
                        </div>
                        
                        <div class="certificado-assinatura">
                            <div class="certificado-assinatura-linha"></div>
                            <p>Secretaria Académica</p>
                        </div>
                    </div>
                    
                    <?php elseif ($tipo_certificado == 'historico_notas'): ?>
                    <!-- Histórico de Notas -->
                    <div class="certificado-bordas">
                        <div class="text-center mb-3">
                            <?php if (!empty($aluno['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $aluno['escola_logo'])): ?>
                                <img src="../../uploads/escolas/logos/<?php echo $aluno['escola_logo']; ?>" style="height: 80px;">
                            <?php else: ?>
                                <i class="fas fa-chart-line fa-3x text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="certificado-titulo">HISTÓRICO DE NOTAS</div>
                        <div class="certificado-subtitulo"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA'); ?></div>
                        
                        <div class="certificado-corpo">
                            <p><strong>Aluno(a):</strong> <?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></p>
                            <p><strong>Nº de Matrícula:</strong> <?php echo htmlspecialchars($aluno['matricula'] ?? ''); ?></p>
                            <p><strong>Classe:</strong> <?php echo htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? ''); ?></p>
                            <p><strong>Ano Letivo:</strong> <?php echo htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')); ?></p>
                            
                            <table class="table table-bordered table-sm mt-3">
                                <thead class="table-light">
                                    <tr>
                                        <th>Disciplina</th>
                                        <th>MAC</th>
                                        <th>NPT</th>
                                        <th>Exame</th>
                                        <th>Média</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $disciplinas_unicas = [];
                                    foreach ($notas as $nota):
                                        if (!in_array($nota['disciplina_nome'], $disciplinas_unicas)):
                                            $disciplinas_unicas[] = $nota['disciplina_nome'];
                                            $situacao_disciplina = getSituacaoAluno($nota['media_final'] ?? 0);
                                            $classe_nota = $situacao_disciplina['classe'];
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($nota['disciplina_nome']); ?></td>
                                        <td><?php echo number_format($nota['mac'] ?? 0, 1); ?></td>
                                        <td><?php echo number_format($nota['npt'] ?? 0, 1); ?></td>
                                        <td><?php echo number_format($nota['exame_normal'] ?? 0, 1); ?></td>
                                        <td><strong class="nota-<?php echo $classe_nota; ?>"><?php echo number_format($nota['media_final'] ?? 0, 1); ?></strong></td>
                                        <td><span class="badge bg-<?php echo $classe_nota == 'aprovado' ? 'success' : ($classe_nota == 'recuperacao' ? 'warning' : 'danger'); ?>">
                                            <?php echo $situacao_disciplina['texto']; ?>
                                        </span></td>
                                    </tr>
                                    <?php 
                                        endif;
                                    endforeach; 
                                    ?>
                                </tbody>
                                <tfoot class="table-light">
                                    <tr>
                                        <th colspan="4">Média Geral</th>
                                        <th colspan="2"><strong><?php echo number_format(getMediaGeral($notas), 1); ?> valores</strong></th>
                                    </tr>
                                </tfoot>
                             </table>
                            
                            <p class="mt-3"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA'); ?>, aos <?php echo formatarDataExtenso(date('Y-m-d')); ?>.</p>
                        </div>
                        
                        <div class="certificado-assinatura">
                            <div class="certificado-assinatura-linha"></div>
                            <p>Secretaria Académica</p>
                        </div>
                    </div>
                    
                    <?php else: ?>
                    <!-- Declaração de Transferência -->
                    <div class="certificado-bordas">
                        <div class="text-center mb-3">
                            <?php if (!empty($aluno['escola_logo']) && file_exists('../../uploads/escolas/logos/' . $aluno['escola_logo'])): ?>
                                <img src="../../uploads/escolas/logos/<?php echo $aluno['escola_logo']; ?>" style="height: 80px;">
                            <?php else: ?>
                                <i class="fas fa-exchange-alt fa-3x text-primary"></i>
                            <?php endif; ?>
                        </div>
                        <div class="certificado-titulo">DECLARAÇÃO DE TRANSFERÊNCIA</div>
                        <div class="certificado-subtitulo"><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'ESCOLA'); ?></div>
                        
                        <div class="certificado-corpo">
                            <p>Declaramos que o(a) aluno(a) <strong><?php echo htmlspecialchars($aluno['nome'] ?? ''); ?></strong>, 
                            portador(a) do BI nº <strong><?php echo htmlspecialchars($aluno['bi'] ?? '_______________'); ?></strong>, 
                            esteve matriculado(a) nesta instituição de ensino no ano letivo de <strong><?php echo htmlspecialchars($aluno['matricula_ano'] ?? $aluno['ano_letivo'] ?? date('Y')); ?></strong>, 
                            na <strong><?php echo htmlspecialchars($aluno['matricula_classe'] ?? $aluno['ano_escolar'] ?? ''); ?></strong> classe.</p>
                            
                            <p>O aluno está autorizado a transferir-se para outra instituição de ensino, não tendo nenhum débito pendente com esta escola.</p>
                            
                            <p><?php echo htmlspecialchars($aluno['escola_nome'] ?? 'A ESCOLA'); ?>, aos <?php echo formatarDataExtenso(date('Y-m-d')); ?>.</p>
                        </div>
                        
                        <div class="certificado-assinatura">
                            <div class="certificado-assinatura-linha"></div>
                            <p>Secretaria Académica</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        
        function atualizarPreview() {
            const tipo = $('#tipo_certificado').val();
            // Recarregar a página com o novo tipo
            const form = $('#formCertificado');
            form.attr('target', '_blank');
            form.submit();
        }
        
        const currentPage = window.location.pathname;
        if (currentPage.includes('secretaria')) {
            $('#menuSecretaria').addClass('open');
            $('#submenuSecretaria').addClass('show');
        }
    </script>
</body>
</html>