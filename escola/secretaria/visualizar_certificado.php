<?php
// escola/secretaria/visualizar_certificado.php - Visualizar Certificado

require_once __DIR__ . '/../../config/database.php';
session_start();

if (!isset($_SESSION['usuario_id'])) {
    exit('Acesso negado');
}

$id = (int)$_POST['id'];
$tipo = $_POST['tipo'] ?? 'conclusao';

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
    echo '<div class="alert alert-danger">Certificado não encontrado.</div>';
    exit;
}

// Função para formatar data
function formatarDataCert($data) {
    if (!$data) return '-';
    return date('d/m/Y', strtotime($data));
}

// Template do certificado baseado no tipo
function getCertificadoTemplate($cert, $escola, $tipo) {
    $logo_html = '';
    if ($escola['logo'] && file_exists('../../uploads/escolas/logos/' . $escola['logo'])) {
        $logo_html = '<img src="../../uploads/escolas/logos/' . $escola['logo'] . '" style="max-height: 80px; margin-bottom: 20px;">';
    }
    
    $titulo = '';
    $cor = '';
    $texto_certificado = '';
    
    switch ($tipo) {
        case 'conclusao':
            $titulo = 'CERTIFICADO DE CONCLUSÃO';
            $cor = '#006B3E';
            $texto_certificado = "
                <p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, 
                filho(a) de, concluiu com aproveitamento o Curso de <strong>Ensino Médio</strong> 
                na <strong>" . htmlspecialchars($escola['nome']) . "</strong>, 
                no ano letivo de <strong>" . date('Y') . "</strong>.</p>
                <p>Durante o período de estudos, demonstrou excelente desempenho e dedicação, 
                estando apto(a) a prosseguir seus estudos ou ingressar no mercado de trabalho.</p>";
            break;
        case 'frequencia':
            $titulo = 'CERTIFICADO DE FREQUÊNCIA';
            $cor = '#17a2b8';
            $texto_certificado = "
                <p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, 
                frequentou regularmente o <strong>" . htmlspecialchars($cert['turma_ano'] . 'º Ano') . "</strong> 
                na <strong>" . htmlspecialchars($escola['nome']) . "</strong>, 
                durante o ano letivo de <strong>" . date('Y') . "</strong>.</p>
                <p>Sua frequência foi satisfatória, cumprindo com as obrigações escolares estabelecidas.</p>";
            break;
        case 'aproveitamento':
            $titulo = 'CERTIFICADO DE APROVEITAMENTO';
            $cor = '#28a745';
            $texto_certificado = "
                <p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, 
                obteve excelente aproveitamento acadêmico durante o <strong>" . htmlspecialchars($cert['turma_ano'] . 'º Ano') . "</strong> 
                na <strong>" . htmlspecialchars($escola['nome']) . "</strong>.</p>
                <p>Demonstrou dedicação, participação ativa e rendimento acima da média em todas as disciplinas.</p>";
            break;
        case 'participacao':
            $titulo = 'CERTIFICADO DE PARTICIPAÇÃO';
            $cor = '#ffc107';
            $texto_certificado = "
                <p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, 
                participou ativamente das atividades e eventos promovidos pela 
                <strong>" . htmlspecialchars($escola['nome']) . "</strong> 
                durante o ano letivo de <strong>" . date('Y') . "</strong>.</p>
                <p>Sua colaboração foi fundamental para o sucesso dos eventos realizados.</p>";
            break;
        case 'estagio':
            $titulo = 'CERTIFICADO DE ESTÁGIO';
            $cor = '#6c757d';
            $texto_certificado = "
                <p>Certificamos que <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>, 
                realizou estágio curricular na <strong>" . htmlspecialchars($escola['nome']) . "</strong>, 
                cumprindo a carga horária estabelecida com dedicação e comprometimento.</p>
                <p>Durante o período, desenvolveu atividades relacionadas à sua área de formação.</p>";
            break;
        default:
            $titulo = 'CERTIFICADO';
            $cor = '#006B3E';
            $texto_certificado = "<p>Certificado emitido para <strong>" . htmlspecialchars($cert['aluno_nome']) . "</strong>.</p>";
    }
    
    return "
    <div class='certificado' style='font-family: \"Times New Roman\", serif;'>
        <div style='text-align: center; padding: 40px; border: 2px solid $cor; border-radius: 15px; background: white;'>
            $logo_html
            <hr style='border: 1px solid $cor; width: 100px;'>
            <h1 style='color: $cor; font-size: 28px; text-transform: uppercase; margin: 20px 0;'>$titulo</h1>
            <hr style='border: 1px solid $cor; width: 100px;'>
            
            <div style='text-align: left; margin: 30px 20px; font-size: 16px; line-height: 1.8;'>
                $texto_certificado
            </div>
            
            <div style='margin-top: 40px;'>
                <p><strong>Número do Certificado:</strong> " . htmlspecialchars($cert['numero_certificado']) . "</p>
                <p><strong>Data de Emissão:</strong> " . formatarDataCert($cert['data_emissao']) . "</p>
                <p><strong>Válido em todo território nacional</strong></p>
            </div>
            
            <div style='margin-top: 50px;'>
                <div style='display: flex; justify-content: space-between;'>
                    <div style='text-align: center; width: 45%;'>
                        <hr style='width: 80%;'>
                        <p><strong>" . htmlspecialchars($cert['assinado_por'] ?? 'Diretor(a)') . "</strong><br>
                        <small>Diretor(a) Geral</small></p>
                    </div>
                    <div style='text-align: center; width: 45%;'>
                        <hr style='width: 80%;'>
                        <p><strong>Secretaria Escolar</strong><br>
                        <small>Carimbo e Assinatura</small></p>
                    </div>
                </div>
            </div>
            
            <div style='margin-top: 30px; font-size: 12px; color: #666;'>
                <hr>
                <p>" . htmlspecialchars($escola['endereco'] ?? '') . " | Tel: " . htmlspecialchars($escola['telefone'] ?? '') . " | Email: " . htmlspecialchars($escola['email'] ?? '') . "</p>
                <p>Documento emitido eletronicamente - Sistema SIGE Angola</p>
            </div>
        </div>
    </div>
    
    <style>
        @media print {
            body * { visibility: hidden; }
            .certificado, .certificado * { visibility: visible; }
            .certificado { position: absolute; top: 0; left: 0; width: 100%; margin: 0; padding: 0; }
            .modal-header, .modal-footer, .btn { display: none; }
        }
        .certificado {
            max-width: 800px;
            margin: 0 auto;
        }
    </style>
    ";
}

echo getCertificadoTemplate($cert, $escola, $tipo);
?>