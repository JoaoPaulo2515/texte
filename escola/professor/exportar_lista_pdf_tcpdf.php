<?php
// escola/professor/exportar_lista_pdf_tcpdf.php
require_once 'includes/auth.php';
$professor = checkProfessorAuth();
$conn = getConnection();

$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$professor_id = $professor['professor_id'];
$escola_id = $professor['escola_id'];

// Verificar se o professor tem acesso a esta turma
$sql_verifica = "
    SELECT COUNT(*) 
    FROM professor_disciplina_turma 
    WHERE professor_id = :professor_id AND turma_id = :turma_id
";
$stmt_verifica = $conn->prepare($sql_verifica);
$stmt_verifica->execute([':professor_id' => $professor_id, ':turma_id' => $turma_id]);
if ($stmt_verifica->fetchColumn() == 0) {
    die('Acesso negado!');
}

// Buscar dados da turma
$sql_turma = "SELECT nome, ano, turno, sala FROM turmas WHERE id = :turma_id AND escola_id = :escola_id";
$stmt_turma = $conn->prepare($sql_turma);
$stmt_turma->execute([':turma_id' => $turma_id, ':escola_id' => $escola_id]);
$turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);

// Buscar nome da escola
$sql_escola = "SELECT nome FROM escolas WHERE id = :escola_id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':escola_id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);
$nome_escola = $escola ? $escola['nome'] : 'Escola';

// Buscar alunos
$sql_alunos = "
    SELECT 
        e.nome,
        e.matricula,
        e.data_nascimento,
        e.email,
        e.telefone,
        e.bi,
        e.sexo
    FROM matriculas m
    INNER JOIN estudantes e ON e.id = m.estudante_id
    WHERE m.turma_id = :turma_id AND m.status = 'ativa'
    ORDER BY e.nome
";
$stmt_alunos = $conn->prepare($sql_alunos);
$stmt_alunos->execute([':turma_id' => $turma_id]);
$alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);

// Criar PDF usando a biblioteca nativa do PHP
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Lista Nominal - <?php echo $turma['ano'] . 'ª ' . $turma['nome']; ?></title>
    <style>
        @media print {
            body { margin: 0; padding: 20px; }
            .no-print { display: none; }
            .page-break { page-break-before: always; }
        }
        
        body {
            font-family: Arial, Helvetica, sans-serif;
            margin: 0;
            padding: 20px;
            font-size: 12px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            color: #006B3E;
            margin: 0;
            font-size: 20px;
        }
        
        .header h3 {
            color: #1A2A6C;
            margin: 5px 0;
            font-size: 16px;
        }
        
        .info {
            margin-bottom: 20px;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 5px;
        }
        
        .info p {
            margin: 5px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            vertical-align: top;
        }
        
        th {
            background-color: #006B3E;
            color: white;
            font-weight: bold;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .assinaturas {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        
        .assinatura {
            text-align: center;
            width: 45%;
        }
        
        .linha {
            border-top: 1px solid #000;
            margin-top: 30px;
            padding-top: 5px;
        }
        
        .btn-print {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #006B3E;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 40px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 1000;
        }
        
        .btn-print:hover {
            background: #004d2e;
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print();">
        <i class="fas fa-print"></i> Imprimir / Salvar PDF
    </button>
    
    <div class="header">
        <h1><?php echo htmlspecialchars($nome_escola); ?></h1>
        <h3>LISTA NOMINAL DE ALUNOS</h3>
        <p><strong><?php echo $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']); ?></strong> 
           - Turno: <?php echo ucfirst($turma['turno']); ?> 
           - Sala: <?php echo $turma['sala'] ?: 'Não definida'; ?></p>
    </div>
    
    <div class="info">
        <p><strong>Professor:</strong> <?php echo htmlspecialchars($professor['nome']); ?></p>
        <p><strong>Data de emissão:</strong> <?php echo date('d/m/Y H:i:s'); ?></p>
        <p><strong>Total de alunos:</strong> <?php echo count($alunos); ?></p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th width="5%">Nº</th>
                <th width="35%">Nome do Aluno</th>
                <th width="12%">Matrícula</th>
                <th width="8%">Sexo</th>
                <th width="12%">Data Nasc.</th>
                <th width="13%">BI</th>
                <th width="15%">Telefone</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($alunos as $index => $aluno): 
                $idade = '';
                if (!empty($aluno['data_nascimento'])) {
                    $data_nasc = new DateTime($aluno['data_nascimento']);
                    $hoje = new DateTime();
                    $idade = $data_nasc->diff($hoje)->y;
                }
            ?>
            <tr>
                <td style="text-align: center;"><?php echo $index + 1; ?></td>
                <td><?php echo strtoupper(htmlspecialchars($aluno['nome'])); ?></td>
                <td style="text-align: center;"><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                <td style="text-align: center;"><?php echo $aluno['sexo'] == 'M' ? 'M' : ($aluno['sexo'] == 'F' ? 'F' : '-'); ?></td>
                <td style="text-align: center;"><?php echo date('d/m/Y', strtotime($aluno['data_nascimento'])); ?><br><small><?php echo $idade; ?> anos</small></td>
                <td><?php echo htmlspecialchars($aluno['bi'] ?? '---'); ?></td>
                <td><?php echo htmlspecialchars($aluno['telefone'] ?? '---'); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    
    <div class="assinaturas">
        <div class="assinatura">
            <div class="linha"></div>
            <p>Assinatura do Professor</p>
            <p><?php echo htmlspecialchars($professor['nome']); ?></p>
        </div>
        <div class="assinatura">
            <div class="linha"></div>
            <p>Carimbo da Escola / Direção</p>
            <p><?php echo htmlspecialchars($nome_escola); ?></p>
        </div>
    </div>
    
    <div class="footer">
        <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        <p>Este documento é válido como lista nominal oficial da turma.</p>
    </div>
    
    <script>
        // Auto-print opcional
        // window.onload = function() { setTimeout(function() { window.print(); }, 1000); }
    </script>
</body>
</html>