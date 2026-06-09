<?php
// escola/secretaria/imprimir_certificado.php - Impressão de Certificado

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: ../../login.php');
    exit;
}

$id = (int)$_GET['id'];
$tipo = $_GET['tipo'] ?? 'conclusao';

$db = Database::getInstance();
$conn = $db->getConnection();
$escola_id = $_SESSION['escola_id'];

// Buscar dados do certificado
$sql = "SELECT c.*, e.nome as aluno_nome, e.matricula, e.data_nascimento, e.bi, 
               t.nome as turma_nome, t.ano as turma_ano,
               u.nome as emissor_nome
        FROM certificados c 
        LEFT JOIN estudantes e ON e.id = c.aluno_id 
        LEFT JOIN matriculas m ON m.estudante_id = e.id AND m.status = 'ativa'
        LEFT JOIN turmas t ON t.id = m.turma_id
        LEFT JOIN usuarios u ON u.id = c.created_by
        WHERE c.id = :id AND c.escola_id = :escola_id";
$stmt = $conn->prepare($sql);
$stmt->execute([':id' => $id, ':escola_id' => $escola_id]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

// Buscar dados da escola
$sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola = $stmt_escola->fetch(PDO::FETCH_ASSOC);

if (!$cert) {
    die('Certificado não encontrado.');
}

function formatarDataCert($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}
?>
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado - <?php echo htmlspecialchars($cert['aluno_nome']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Times New Roman', Times, serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .certificado-wrapper { max-width: 900px; width: 100%; }
        .certificado { background: white; padding: 40px; border-radius: 15px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        @media print {
            body { background: white; padding: 0; margin: 0; }
            .certificado { box-shadow: none; padding: 20px; }
            .btn-imprimir, .btn-voltar { display: none; }
        }
        .btn-imprimir { background: #006B3E; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; margin-right: 10px; }
        .btn-voltar { background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-imprimir:hover { background: #004d2d; }
        .acoes { text-align: center; margin-top: 20px; }
    </style>
</head>
<body>
    <div class="certificado-wrapper">
        <div class="certificado">
            <?php
            $cor = '#006B3E';
            $titulo = '';
            switch ($tipo) {
                case 'conclusao': $titulo = 'CERTIFICADO DE CONCLUSÃO'; $cor = '#006B3E'; break;
                case 'frequencia': $titulo = 'CERTIFICADO DE FREQUÊNCIA'; $cor = '#17a2b8'; break;
                case 'aproveitamento': $titulo = 'CERTIFICADO DE APROVEITAMENTO'; $cor = '#28a745'; break;
                case 'participacao': $titulo = 'CERTIFICADO DE PARTICIPAÇÃO'; $cor = '#ffc107'; break;
                case 'estagio': $titulo = 'CERTIFICADO DE ESTÁGIO'; $cor = '#6c757d'; break;
                default: $titulo = 'CERTIFICADO'; $cor = '#006B3E';
            }
            
            $texto = '';
            if ($tipo == 'conclusao') {
                $texto = "<p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, concluiu com aproveitamento o Curso de <strong>Ensino Médio</strong> na <strong>" . htmlspecialchars($escola['nome']) . "</strong>, no ano letivo de <strong>" . date('Y') . "</strong>.</p>";
            } elseif ($tipo == 'frequencia') {
                $texto = "<p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, frequentou regularmente o <strong>" . htmlspecialchars($cert['turma_ano'] . 'º Ano') . "</strong> na <strong>" . htmlspecialchars($escola['nome']) . "</strong>, durante o ano letivo de <strong>" . date('Y') . "</strong>.</p>";
            } elseif ($tipo == 'aproveitamento') {
                $texto = "<p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, obteve excelente aproveitamento acadêmico durante o <strong>" . htmlspecialchars($cert['turma_ano'] . 'º Ano') . "</strong> na <strong>" . htmlspecialchars($escola['nome']) . "</strong>.</p>";
            } elseif ($tipo == 'participacao') {
                $texto = "<p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, participou ativamente das atividades e eventos promovidos pela <strong>" . htmlspecialchars($escola['nome']) . "</strong> durante o ano letivo de <strong>" . date('Y') . "</strong>.</p>";
            } else {
                $texto = "<p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, realizou estágio curricular na <strong>" . htmlspecialchars($escola['nome']) . "</strong>, cumprindo a carga horária estabelecida.</p>";
            }
            ?>
            
            <div style="text-align: center;">
                <?php if ($escola['logo'] && file_exists('../../uploads/escolas/logos/' . $escola['logo'])): ?>
                    <img src="../../uploads/escolas/logos/<?php echo $escola['logo']; ?>" style="max-height: 80px; margin-bottom: 20px;">
                <?php endif; ?>
                <hr style="border: 1px solid <?php echo $cor; ?>; width: 100px; margin: 0 auto;">
                <h1 style="color: <?php echo $cor; ?>; font-size: 28px; text-transform: uppercase; margin: 20px 0;"><?php echo $titulo; ?></h1>
                <hr style="border: 1px solid <?php echo $cor; ?>; width: 100px; margin: 0 auto;">
            </div>
            
            <div style="margin: 40px 20px; font-size: 16px; line-height: 1.8;">
                <?php echo $texto; ?>
            </div>
            
            <div style="margin-top: 30px; text-align: center;">
                <p><strong>Número do Certificado:</strong> <?php echo htmlspecialchars($cert['numero_certificado']); ?></p>
                <p><strong>Data de Emissão:</strong> <?php echo formatarDataCert($cert['data_emissao']); ?></p>
            </div>
            
            <div style="margin-top: 50px;">
                <div style="display: flex; justify-content: space-between;">
                    <div style="text-align: center; width: 45%;">
                        <hr style="width: 80%; margin: 0 auto;">
                        <p><strong><?php echo htmlspecialchars($cert['assinado_por'] ?? 'Diretor(a)'); ?></strong><br><small>Diretor(a) Geral</small></p>
                    </div>
                    <div style="text-align: center; width: 45%;">
                        <hr style="width: 80%; margin: 0 auto;">
                        <p><strong>Secretaria Escolar</strong><br><small>Carimbo e Assinatura</small></p>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 40px; font-size: 11px; color: #666; text-align: center; border-top: 1px solid #ddd; padding-top: 20px;">
                <p><?php echo htmlspecialchars($escola['endereco'] ?? ''); ?> | Tel: <?php echo htmlspecialchars($escola['telefone'] ?? ''); ?> | Email: <?php echo htmlspecialchars($escola['email'] ?? ''); ?></p>
                <p>Documento emitido eletronicamente - Sistema SIGE Angola</p>
            </div>
        </div>
        
        <div class="acoes">
            <button class="btn-imprimir" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
            <a href="certificados.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
        </div>
    </div>
    
    <script>
        // Auto-print quando a página carregar (opcional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>