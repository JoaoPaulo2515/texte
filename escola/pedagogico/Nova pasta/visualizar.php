<?php
// escola/alunos/visualizar.php - Visualizar detalhes do aluno
require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['escola_id'])) {
    header('Location: ../../index.php?page=login');
    exit;
}

$id = $_GET['id'] ?? 0;
$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar dados do aluno
$stmt = $conn->prepare("
    SELECT e.*, u.nome, u.email as usuario_email, u.telefone as usuario_telefone, u.status as usuario_status,
           t.id as turma_id, t.nome as turma_nome, m.status as matricula_status, m.data_matricula
    FROM estudantes e
    JOIN usuarios u ON u.id = e.usuario_id
    LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
    LEFT JOIN turmas t ON t.id = m.turma_id
    WHERE e.id = :id AND e.escola_id = :escola_id
");
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$aluno = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$aluno) {
    header('Location: index.php?error=Aluno não encontrado');
    exit;
}

// Buscar notas do aluno
$stmt = $conn->prepare("
    SELECT d.nome as disciplina_nome, n.*
    FROM notas n
    JOIN disciplinas d ON d.id = n.disciplina_id
    JOIN matriculas m ON m.id = n.estudante_id
    WHERE m.estudante_id = :estudante_id
    ORDER BY d.nome, n.bimestre
");
$stmt->execute([':estudante_id' => $id]);
$notas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar frequência
$stmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN presente = 1 THEN 1 END) as presentes,
        COUNT(CASE WHEN presente = 0 THEN 1 END) as faltas,
        COUNT(*) as total
    FROM presencas p
    JOIN matriculas m ON m.id = p.matricula_id
    WHERE m.estudante_id = :estudante_id
");
$stmt->execute([':estudante_id' => $id]);
$frequencia = $stmt->fetch(PDO::FETCH_ASSOC);

$percentual_presenca = $frequencia['total'] > 0 ? round(($frequencia['presentes'] / $frequencia['total']) * 100, 1) : 0;

// Buscar documentos
$outros_docs = json_decode($aluno['outros_documentos'], true) ?: [];

// Províncias para exibição
$provincias_lista = [
    'Bengo', 'Benguela', 'Bié', 'Cabinda', 'Cuando Cubango', 
    'Cuanza Norte', 'Cuanza Sul', 'Cunene', 'Huambo', 'Huíla', 
    'Luanda', 'Lunda Norte', 'Lunda Sul', 'Malanje', 'Moxico', 
    'Namibe', 'Uíge', 'Zaire'
];
?>

<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($aluno['nome']); ?> | SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background: #f0f2f5; }
        .sidebar {
            position: fixed; left: 0; top: 0; width: 280px; height: 100vh;
            background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
            color: white; transition: all 0.3s; z-index: 1000; overflow-y: auto;
        }
        .sidebar-header { padding: 25px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link { display: flex; align-items: center; padding: 12px 25px; color: rgba(255,255,255,0.8); text-decoration: none; gap: 12px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 280px; padding: 20px; }
        .top-bar { background: white; border-radius: 10px; padding: 15px 20px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card { background: white; border-radius: 15px; margin-bottom: 20px; border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .card-header { background: white; border-bottom: 1px solid #eee; padding: 15px 20px; font-weight: bold; }
        .profile-img { width: 150px; height: 150px; object-fit: cover; border-radius: 50%; border: 3px solid #006B3E; }
        .info-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .info-label { width: 180px; font-weight: 600; color: #555; }
        .info-value { flex: 1; color: #333; }
        .btn-primary { background: #006B3E; border: none; }
        .menu-toggle { display: none; position: fixed; top: 20px; left: 20px; z-index: 1001; background: #006B3E; color: white; border: none; width: 40px; height: 40px; border-radius: 8px; cursor: pointer; }
        @media (max-width: 768px) { .sidebar { left: -280px; } .sidebar.open { left: 0; } .main-content { margin-left: 0; } .menu-toggle { display: block; } }
        .document-link { margin: 5px; display: inline-block; }
        .nota-card { text-align: center; padding: 10px; margin: 5px; border-radius: 8px; }
        .nota-aprovado { background: #d4edda; color: #155724; }
        .nota-reprovado { background: #f8d7da; color: #721c24; }
        .nota-recuperacao { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
     <?php include '../menu_escola.php'; ?>
   
    
    <div class="main-content">
        <div class="top-bar">
            <h2><i class="fas fa-user-graduate"></i> Perfil do Aluno</h2>
            <div>
                <a href="editar.php?id=<?php echo $aluno['id']; ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Editar</a>
                <a href="excluir.php?id=<?php echo $aluno['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Tem certeza que deseja excluir este aluno?')"><i class="fas fa-trash"></i> Excluir</a>
                <a href="index.php" class="btn btn-secondary btn-sm"><i class="fas fa-arrow-left"></i> Voltar</a>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="card text-center p-4">
                    <?php 
                    $foto_path = '../../uploads/alunos/fotos/' . $aluno['foto'];
                    if ($aluno['foto'] && file_exists($foto_path)) {
                        echo '<img src="' . $foto_path . '" class="profile-img mx-auto mb-3">';
                    } else {
                        echo '<img src="../../assets/images/avatar-padrao.png" class="profile-img mx-auto mb-3">';
                    }
                    ?>
                    <h4><?php echo htmlspecialchars($aluno['nome']); ?></h4>
                    <p class="text-muted">Matrícula: <?php echo $aluno['matricula']; ?></p>
                    <p><span class="badge bg-success"><?php echo $aluno['matricula_status'] == 'ativa' ? 'Ativo' : 'Inativo'; ?></span></p>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-simple"></i> Resumo Académico</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Turma:</div><div class="info-value"><?php echo $aluno['turma_nome'] ?? 'Não matriculado'; ?></div></div>
                        <div class="info-row"><div class="info-label">Data Matrícula:</div><div class="info-value"><?php echo $aluno['data_matricula'] ? date('d/m/Y', strtotime($aluno['data_matricula'])) : '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Ano Letivo:</div><div class="info-value"><?php echo $aluno['ano_letivo']; ?></div></div>
                        <div class="info-row"><div class="info-label">Presenças:</div><div class="info-value"><?php echo $percentual_presenca; ?>%</div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-pie"></i> Desempenho por Disciplina</div>
                    <div class="card-body">
                        <canvas id="desempenhoChart" height="250"></canvas>
                    </div>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header"><i class="fas fa-user"></i> Dados Pessoais</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome Completo:</div><div class="info-value"><?php echo htmlspecialchars($aluno['nome']); ?></div></div>
                        <div class="info-row"><div class="info-label">Data de Nascimento:</div><div class="info-value"><?php echo $aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Género:</div><div class="info-value"><?php echo $aluno['genero'] == 'M' ? 'Masculino' : ($aluno['genero'] == 'F' ? 'Feminino' : '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">BI/Nº Identificação:</div><div class="info-value"><?php echo $aluno['bi'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Data Emissão BI:</div><div class="info-value"><?php echo $aluno['bi_data_emissao'] ? date('d/m/Y', strtotime($aluno['bi_data_emissao'])) : '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Local Emissão BI:</div><div class="info-value"><?php echo $aluno['bi_local_emissao'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">NUIT:</div><div class="info-value"><?php echo $aluno['nuit'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Nacionalidade:</div><div class="info-value"><?php echo $aluno['nacionalidade'] ?? 'Angolana'; ?></div></div>
                        <div class="info-row"><div class="info-label">Naturalidade:</div><div class="info-value"><?php echo $aluno['naturalidade'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Província:</div><div class="info-value"><?php echo $aluno['provincia'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Município:</div><div class="info-value"><?php echo $aluno['municipio'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Comuna:</div><div class="info-value"><?php echo $aluno['comuna'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo nl2br(htmlspecialchars($aluno['endereco'] ?? '-')); ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo $aluno['usuario_telefone'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo $aluno['usuario_email'] ?? '-'; ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-family"></i> Filiação</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome do Pai:</div><div class="info-value"><?php echo htmlspecialchars($aluno['pai_nome'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">BI do Pai:</div><div class="info-value"><?php echo $aluno['pai_bi'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone do Pai:</div><div class="info-value"><?php echo $aluno['pai_telefone'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Profissão do Pai:</div><div class="info-value"><?php echo $aluno['pai_profissao'] ?? '-'; ?></div></div>
                        <hr>
                        <div class="info-row"><div class="info-label">Nome da Mãe:</div><div class="info-value"><?php echo htmlspecialchars($aluno['mae_nome'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">BI da Mãe:</div><div class="info-value"><?php echo $aluno['mae_bi'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone da Mãe:</div><div class="info-value"><?php echo $aluno['mae_telefone'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Profissão da Mãe:</div><div class="info-value"><?php echo $aluno['mae_profissao'] ?? '-'; ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-user-tie"></i> Encarregado de Educação</div>
                    <div class="card-body">
                        <div class="info-row"><div class="info-label">Nome:</div><div class="info-value"><?php echo htmlspecialchars($aluno['encarregado_nome'] ?? '-'); ?></div></div>
                        <div class="info-row"><div class="info-label">Parentesco:</div><div class="info-value"><?php echo $aluno['encarregado_parentesco'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">BI:</div><div class="info-value"><?php echo $aluno['encarregado_bi'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Telefone:</div><div class="info-value"><?php echo $aluno['encarregado_telefone'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">E-mail:</div><div class="info-value"><?php echo $aluno['encarregado_email'] ?? '-'; ?></div></div>
                        <div class="info-row"><div class="info-label">Endereço:</div><div class="info-value"><?php echo nl2br(htmlspecialchars($aluno['encarregado_endereco'] ?? '-')); ?></div></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header"><i class="fas fa-graduation-cap"></i> Documentos</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>BI:</strong><br>
                                <?php if ($aluno['bi_documento']): ?>
                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['bi_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Certificado:</strong><br>
                                <?php if ($aluno['certificado_documento']): ?>
                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['certificado_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mt-2">
                                <strong>Atestado Médico:</strong><br>
                                <?php if ($aluno['atestado_documento']): ?>
                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['atestado_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mt-2">
                                <strong>Declaração:</strong><br>
                                <?php if ($aluno['declaracao_documento']): ?>
                                    <a href="../../uploads/alunos/documentos/<?php echo $aluno['declaracao_documento']; ?>" target="_blank" class="document-link btn btn-sm btn-info">Ver Documento</a>
                                <?php else: ?>
                                    <span class="text-muted">Não enviado</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($outros_docs)): ?>
                        <hr>
                        <strong>Outros Documentos:</strong><br>
                        <div class="mt-2">
                            <?php foreach ($outros_docs as $i => $doc): ?>
                                <a href="../../uploads/alunos/documentos/<?php echo $doc; ?>" target="_blank" class="document-link btn btn-sm btn-secondary">Documento <?php echo $i+1; ?></a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header"><i class="fas fa-table"></i> Histórico de Notas</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Disciplina</th>
                                <th>1º Bimestre</th>
                                <th>2º Bimestre</th>
                                <th>3º Bimestre</th>
                                <th>Média Final</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $notas_por_disciplina = [];
                            foreach ($notas as $nota) {
                                $notas_por_disciplina[$nota['disciplina_nome']][$nota['bimestre']] = $nota;
                            }
                            ?>
                            <?php foreach ($notas_por_disciplina as $disciplina => $bimestres): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($disciplina); ?></strong></td>
                                <?php for ($b = 1; $b <= 3; $b++): ?>
                                    <td class="text-center">
                                        <?php 
                                        $media_bimestre = $bimestres[$b]['media'] ?? null;
                                        if ($media_bimestre !== null) {
                                            $classe = $media_bimestre >= 10 ? 'text-success' : ($media_bimestre >= 7 ? 'text-warning' : 'text-danger');
                                            echo "<span class='{$classe} fw-bold'>" . number_format($media_bimestre, 1) . "</span>";
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                <?php endfor; ?>
                                <td class="text-center">
                                    <?php 
                                    $medias = array_column($bimestres, 'media');
                                    $media_final = !empty($medias) ? array_sum($medias) / count($medias) : null;
                                    if ($media_final !== null) {
                                        $classe = $media_final >= 10 ? 'text-success' : ($media_final >= 7 ? 'text-warning' : 'text-danger');
                                        echo "<span class='{$classe} fw-bold'>" . number_format($media_final, 1) . "</span>";
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php 
                                    if ($media_final !== null) {
                                        if ($media_final >= 10) {
                                            echo '<span class="badge bg-success">Aprovado</span>';
                                        } elseif ($media_final >= 7) {
                                            echo '<span class="badge bg-warning">Recuperação</span>';
                                        } else {
                                            echo '<span class="badge bg-danger">Reprovado</span>';
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $('#menuToggle').click(function() { $('#sidebar').toggleClass('open'); });
        
        // Gráfico de desempenho por disciplina
        const ctx = document.getElementById('desempenhoChart').getContext('2d');
        const disciplinas = <?php 
            $discs = [];
            $medias = [];
            foreach ($notas_por_disciplina as $disciplina => $bimestres) {
                $discs[] = $disciplina;
                $medias_bim = array_column($bimestres, 'media');
                $medias[] = !empty($medias_bim) ? array_sum($medias_bim) / count($medias_bim) : 0;
            }
            echo json_encode($discs);
        ?>;
        const medias = <?php echo json_encode($medias); ?>;
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: disciplinas,
                datasets: [{
                    label: 'Média Final',
                    data: medias,
                    backgroundColor: '#006B3E',
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 20
                    }
                }
            }
        });
    </script>
</body>
</html>