<?php
// escola/relatorios/gerar_pdf_multiplo.php - Gerar PDF Múltiplo de Pautas

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
// RECEBER PARÂMETROS
// ============================================
$professor_id = isset($_GET['professor_id']) ? (int)$_GET['professor_id'] : 0;
$disciplinas_param = isset($_GET['disciplinas']) ? $_GET['disciplinas'] : '';
$trimestre = isset($_GET['trimestre']) ? (int)$_GET['trimestre'] : 1;
$ano_letivo_id = isset($_GET['ano_letivo']) ? (int)$_GET['ano_letivo'] : 0;

// ============================================
// VALIDAÇÕES
// ============================================
if ($professor_id == 0) {
    die('Professor não selecionado.');
}

if (empty($disciplinas_param)) {
    die('Nenhuma disciplina selecionada.');
}

// Processar disciplinas (formato: disciplina_id_turma_id)
$disciplinas_raw = explode(',', $disciplinas_param);
$disciplinas_turmas = [];
foreach ($disciplinas_raw as $item) {
    $parts = explode('_', $item);
    if (count($parts) == 2) {
        $disciplinas_turmas[] = [
            'disciplina_id' => (int)$parts[0],
            'turma_id' => (int)$parts[1]
        ];
    }
}

// Remover duplicatas
$disciplinas_turmas = array_unique($disciplinas_turmas, SORT_REGULAR);

if (empty($disciplinas_turmas)) {
    die('Nenhuma disciplina válida selecionada.');
}

// Buscar ano letivo
if ($ano_letivo_id == 0) {
    $sql_ano = "SELECT id FROM ano_letivo WHERE ativo = 1 AND escola_id = :escola_id LIMIT 1";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':escola_id' => $escola_id]);
    $ano = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_id = $ano['id'] ?? 1;
}

// Buscar ano letivo para exibição
$sql_ano_nome = "SELECT ano FROM ano_letivo WHERE id = :id";
$stmt_ano_nome = $conn->prepare($sql_ano_nome);
$stmt_ano_nome->execute([':id' => $ano_letivo_id]);
$ano_letivo_nome = $stmt_ano_nome->fetch(PDO::FETCH_ASSOC)['ano'] ?? date('Y');

// ============================================
// BUSCAR DADOS DO PROFESSOR
// ============================================
$sql_professor = "SELECT nome FROM funcionarios WHERE id = :id AND escola_id = :escola_id";
$stmt_professor = $conn->prepare($sql_professor);
$stmt_professor->execute([':id' => $professor_id, ':escola_id' => $escola_id]);
$professor = $stmt_professor->fetch(PDO::FETCH_ASSOC);

if (!$professor) {
    die('Professor não encontrado.');
}

// ============================================
// BUSCAR DADOS DA ESCOLA
// ============================================
$sql_escola = "SELECT nome, endereco, telefone, email, nuit FROM escolas WHERE id = :id";
$stmt_escola = $conn->prepare($sql_escola);
$stmt_escola->execute([':id' => $escola_id]);
$escola_info = $stmt_escola->fetch(PDO::FETCH_ASSOC);

// ============================================
// FUNÇÕES AUXILIARES
// ============================================
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

function getStatus($media) {
    if ($media === null || $media <= 0) return ['texto' => 'Sem nota', 'classe' => 'status-sem-nota'];
    if ($media >= 14) return ['texto' => 'Aprovado', 'classe' => 'status-aprovado'];
    if ($media >= 10) return ['texto' => 'Exame', 'classe' => 'status-exame'];
    return ['texto' => 'Reprovado', 'classe' => 'status-reprovado'];
}

// ============================================
// BUSCAR DADOS DE CADA DISCIPLINA/TURMA
// ============================================
$dados_pautas = [];

foreach ($disciplinas_turmas as $item) {
    $disciplina_id = $item['disciplina_id'];
    $turma_id = $item['turma_id'];
    
    // Buscar disciplina
    $sql_disc = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id AND escola_id = :escola_id";
    $stmt_disc = $conn->prepare($sql_disc);
    $stmt_disc->execute([':id' => $disciplina_id, ':escola_id' => $escola_id]);
    $disciplina = $stmt_disc->fetch(PDO::FETCH_ASSOC);
    
    if (!$disciplina) continue;
    
    // Buscar turma
    $sql_turma = "SELECT id, nome, ano, turno FROM turmas WHERE id = :id AND escola_id = :escola_id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':id' => $turma_id, ':escola_id' => $escola_id]);
    $turma = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if (!$turma) continue;
    
    $is_exame_classe = isClasseExame($turma['ano']);
    $is_linguagem = isLinguagem($disciplina['nome']);
    
    // Buscar alunos da turma
    $sql_alunos = "SELECT e.id, e.nome, e.matricula, e.genero
                   FROM estudantes e
                   INNER JOIN matriculas m ON m.estudante_id = e.id
                   WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo_id
                   ORDER BY e.nome";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([
        ':turma_id' => $turma_id,
        ':ano_letivo_id' => $ano_letivo_id
    ]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    $alunos_com_notas = [];
    $soma_notas = 0;
    $total_com_nota = 0;
    $aprovados = 0;
    $exame = 0;
    $reprovados = 0;
    
    foreach ($alunos as $aluno) {
        $sql_nota = "SELECT mac, npt, nota_exame_normal, nota_exame_oral, nota_exame_escrita, media_final
                     FROM notas 
                     WHERE estudante_id = :estudante_id 
                     AND disciplina_id = :disciplina_id 
                     AND trimestre = :trimestre
                     AND ano_letivo_id = :ano_letivo_id";
        $stmt_nota = $conn->prepare($sql_nota);
        $stmt_nota->execute([
            ':estudante_id' => $aluno['id'],
            ':disciplina_id' => $disciplina_id,
            ':trimestre' => $trimestre,
            ':ano_letivo_id' => $ano_letivo_id
        ]);
        $nota_data = $stmt_nota->fetch(PDO::FETCH_ASSOC);
        
        $mac = $nota_data ? (float)$nota_data['mac'] : null;
        $npt = $nota_data ? (float)$nota_data['npt'] : null;
        $exame_normal = $nota_data ? (float)$nota_data['nota_exame_normal'] : null;
        $exame_oral = $nota_data ? (float)$nota_data['nota_exame_oral'] : null;
        $exame_escrita = $nota_data ? (float)$nota_data['nota_exame_escrita'] : null;
        $media_final = $nota_data ? (float)$nota_data['media_final'] : null;
        
        // Calcular média se necessário
        if ($media_final === null) {
            if ($is_exame_classe && $trimestre == 3) {
                if ($is_linguagem) {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($exame_oral !== null) $valores[] = $exame_oral;
                    if ($exame_escrita !== null) $valores[] = $exame_escrita;
                    $media_final = !empty($valores) ? array_sum($valores) / count($valores) : null;
                } else {
                    $valores = [];
                    if ($mac !== null) $valores[] = $mac;
                    if ($exame_normal !== null) $valores[] = $exame_normal;
                    $media_final = !empty($valores) ? array_sum($valores) / count($valores) : null;
                }
            } else {
                $valores = [];
                if ($mac !== null) $valores[] = $mac;
                if ($npt !== null) $valores[] = $npt;
                $media_final = !empty($valores) ? array_sum($valores) / count($valores) : null;
            }
        }
        
        if ($media_final !== null && $media_final > 0) {
            $soma_notas += $media_final;
            $total_com_nota++;
            if ($media_final >= 14) $aprovados++;
            elseif ($media_final >= 10) $exame++;
            else $reprovados++;
        }
        
        $status_info = getStatus($media_final);
        
        $alunos_com_notas[] = [
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'],
            'mac' => $mac,
            'npt' => $npt,
            'exame_normal' => $exame_normal,
            'exame_oral' => $exame_oral,
            'exame_escrita' => $exame_escrita,
            'media_final' => $media_final,
            'status_texto' => $status_info['texto'],
            'status_classe' => $status_info['classe']
        ];
    }
    
    $dados_pautas[] = [
        'disciplina' => $disciplina,
        'turma' => $turma,
        'alunos' => $alunos_com_notas,
        'estatisticas' => [
            'total' => count($alunos),
            'aprovados' => $aprovados,
            'exame' => $exame,
            'reprovados' => $reprovados,
            'media_geral' => $total_com_nota > 0 ? round($soma_notas / $total_com_nota, 2) : 0
        ],
        'is_exame_classe' => $is_exame_classe,
        'is_linguagem' => $is_linguagem
    ];
}

if (empty($dados_pautas)) {
    die('Nenhum dado encontrado para as disciplinas selecionadas.');
}

// ============================================
// GERAR HTML PARA PDF
// ============================================
$trimestres_nomes = ['', 'PRIMEIRO TRIMESTRE', 'SEGUNDO TRIMESTRE', 'TERCEIRO TRIMESTRE'];
$trimestre_nome = $trimestres_nomes[$trimestre] ?? $trimestre . 'º TRIMESTRE';

$html = '
<!DOCTYPE html>
<html lang="pt-AO">
<head>
    <meta charset="UTF-8">
    <title>Pautas de Notas - ' . htmlspecialchars($professor['nome']) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; 
            font-size: 11px; 
            padding: 15px; 
            background: white;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #006B3E;
            padding-bottom: 10px;
        }
        .header h1 { font-size: 18px; color: #006B3E; margin-bottom: 3px; }
        .header h2 { font-size: 14px; font-weight: normal; margin-bottom: 3px; }
        .header p { font-size: 10px; color: #666; }
        .info-professor {
            background: #f5f5f5;
            padding: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #006B3E;
            font-size: 10px;
        }
        .pauta-container {
            margin-bottom: 30px;
            page-break-after: always;
        }
        .pauta-container:last-child {
            page-break-after: auto;
        }
        .disciplina-title {
            background: #006B3E;
            color: white;
            padding: 8px 12px;
            margin-bottom: 10px;
            font-size: 13px;
            font-weight: bold;
        }
        .info-turma {
            background: #e9ecef;
            padding: 6px 10px;
            margin-bottom: 10px;
            font-size: 10px;
            display: flex;
            justify-content: space-between;
        }
        .estatisticas {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        .card-estatistica {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 5px 10px;
            min-width: 70px;
            text-align: center;
        }
        .card-estatistica .numero { font-size: 16px; font-weight: bold; color: #006B3E; }
        .card-estatistica .label { font-size: 8px; color: #666; }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 5px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #006B3E;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .nota { text-align: center; font-weight: bold; }
        .status-aprovado { color: #28a745; font-weight: bold; }
        .status-exame { color: #ffc107; font-weight: bold; }
        .status-reprovado { color: #dc3545; font-weight: bold; }
        .status-sem-nota { color: #6c757d; }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>' . htmlspecialchars($escola_info['nome'] ?? $escola_nome) . '</h1>
        <h2>PAUTAS DE NOTAS - ' . $trimestre_nome . '</h2>
        <p>Professor: ' . htmlspecialchars($professor['nome']) . ' | Ano Letivo: ' . $ano_letivo_nome . ' | Gerado em: ' . date('d/m/Y H:i:s') . '</p>
    </div>
    
    <div class="info-professor">
        <strong>Resumo das Pautas:</strong> ' . count($dados_pautas) . ' disciplina(s) | ' . $trimestre_nome . '
    </div>';

foreach ($dados_pautas as $index => $pauta) {
    $disciplina = $pauta['disciplina'];
    $turma = $pauta['turma'];
    $alunos = $pauta['alunos'];
    $estatisticas = $pauta['estatisticas'];
    $is_exame_classe = $pauta['is_exame_classe'];
    $is_linguagem = $pauta['is_linguagem'];
    
    $html .= '
    <div class="pauta-container">
        <div class="disciplina-title">
            <i class="fas fa-book"></i> ' . htmlspecialchars($disciplina['nome']) . ' - ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . '
        </div>
        <div class="info-turma">
            <span><strong>Turma:</strong> ' . $turma['ano'] . 'ª ' . htmlspecialchars($turma['nome']) . ' (' . ucfirst($turma['turno']) . ')</span>
            <span><strong>Código:</strong> ' . htmlspecialchars($disciplina['codigo'] ?? '---') . '</span>
            <span><strong>Tipo Avaliação:</strong> ';
            
            if ($is_exame_classe && $trimestre == 3) {
                if ($is_linguagem) {
                    $html .= 'MAC + Exame Oral + Exame Escrita';
                } else {
                    $html .= 'MAC + Exame Normal';
                }
            } else {
                $html .= 'MAC + NPT';
            }
            
            $html .= '</span>
        </div>
        
        <div class="estatisticas">
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas['total'] . '</div>
                <div class="label">Total Alunos</div>
            </div>
            <div class="card-estatistica">
                <div class="numero" style="color: #28a745;">' . $estatisticas['aprovados'] . '</div>
                <div class="label">Aprovados</div>
            </div>
            <div class="card-estatistica">
                <div class="numero" style="color: #ffc107;">' . $estatisticas['exame'] . '</div>
                <div class="label">Exame</div>
            </div>
            <div class="card-estatistica">
                <div class="numero" style="color: #dc3545;">' . $estatisticas['reprovados'] . '</div>
                <div class="label">Reprovados</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . number_format($estatisticas['media_geral'], 1, ',', '.') . '</div>
                <div class="label">Média Geral</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th width="4%">#</th>
                    <th width="10%">Matrícula</th>
                    <th width="25%">Aluno</th>
                    <th width="6%">Sexo</th>
                    <th width="8%">MAC</th>';
                    
                    if (!($is_exame_classe && $trimestre == 3)) {
                        $html .= '<th width="8%">NPT</th>';
                    }
                    
                    if ($is_exame_classe && $trimestre == 3) {
                        if ($is_linguagem) {
                            $html .= '<th width="8%">Exame Oral</th>';
                            $html .= '<th width="8%">Exame Escrita</th>';
                        } else {
                            $html .= '<th width="8%">Exame Normal</th>';
                        }
                    }
                    
                    $html .= '
                    <th width="8%">Média</th>
                    <th width="10%">Status</th>
                </tr>
            </thead>
            <tbody>';
    
    if (empty($alunos)) {
        $html .= '<tr><td colspan="20" class="text-center">Nenhum aluno encontrado nesta turma.</td></tr>';
    } else {
        foreach ($alunos as $idx => $aluno) {
            $html .= '<tr>
                <td class="text-center">' . ($idx + 1) . '</td>
                <td class="text-center">' . htmlspecialchars($aluno['matricula']) . '</td>
                <td>' . htmlspecialchars($aluno['nome']) . '</td>
                <td class="text-center">' . ($aluno['genero'] == 'masculino' ? 'M' : 'F') . '</td>
                <td class="text-center">' . ($aluno['mac'] !== null ? number_format($aluno['mac'], 2, ',', '.') : '---') . '</td>';
                
                if (!($is_exame_classe && $trimestre == 3)) {
                    $html .= '<td class="text-center">' . ($aluno['npt'] !== null ? number_format($aluno['npt'], 2, ',', '.') : '---') . '</td>';
                }
                
                if ($is_exame_classe && $trimestre == 3) {
                    if ($is_linguagem) {
                        $html .= '<td class="text-center">' . ($aluno['exame_oral'] !== null ? number_format($aluno['exame_oral'], 2, ',', '.') : '---') . '</td>';
                        $html .= '<td class="text-center">' . ($aluno['exame_escrita'] !== null ? number_format($aluno['exame_escrita'], 2, ',', '.') : '---') . '</td>';
                    } else {
                        $html .= '<td class="text-center">' . ($aluno['exame_normal'] !== null ? number_format($aluno['exame_normal'], 2, ',', '.') : '---') . '</td>';
                    }
                }
                
                $html .= '<td class="text-center"><strong>' . ($aluno['media_final'] !== null ? number_format($aluno['media_final'], 2, ',', '.') : '---') . '</strong></td>
                <td class="text-center ' . $aluno['status_classe'] . '">' . $aluno['status_texto'] . '</td>
            </tr>';
        }
    }
    
    $html .= '
            </tbody>
            <tfoot>
                <tr style="background: #e9ecef;">
                    <td colspan="' . (($is_exame_classe && $trimestre == 3) ? ($is_linguagem ? '9' : '8') : '7') . '" class="text-right"><strong>Média Geral da Turma:</strong></td>
                    <td colspan="2"><strong>' . number_format($estatisticas['media_geral'], 2, ',', '.') . ' valores</strong></td>
                </tr>
            </tfoot>
        </table>
    </div>';
}

$html .= '
    <div class="footer">
        <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        <p>' . htmlspecialchars($escola_info['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola_info['telefone'] ?? '') . ' | Email: ' . htmlspecialchars($escola_info['email'] ?? '') . '</p>
    </div>
</body>
</html>';

// ============================================
// GERAR PDF
// ============================================
require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Nome do arquivo
$filename = 'pautas_professor_' . preg_replace('/[^a-zA-Z0-9]/', '_', $professor['nome']) . '_' . $trimestre . 't_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ["Attachment" => true]);
exit;
?>