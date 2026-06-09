<?php
// escola/pedagogico/boletim_individual.php - Boletim Individual do Aluno

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
$aluno_id = isset($_GET['aluno_id']) ? (int)$_GET['aluno_id'] : 0;
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// BUSCAR ALUNOS DA TURMA
$alunos = [];
$aluno_selecionado = null;
$turma_info = null;
$bimestres_liberados_global = [];
$total_pagamentos_global = 0;
$disciplinas = [];
$classe_ano = 0;
$limite_aprovacao = 5;
$escala_max = 10;
$is_classe_exame = false;
$ano_letivo_ano = '';

if ($turma_id > 0 && $ano_letivo_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "
        SELECT t.nome, t.ano, tr.nome as turno_nome
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE t.id = :turma_id
    ";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':turma_id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.data_nascimento,
            e.foto
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
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
    
    // Se não tem aluno selecionado e tem alunos, pegar o primeiro
    if ($aluno_id == 0 && !empty($alunos)) {
        $aluno_id = $alunos[0]['id'];
    }
    
    // Buscar dados do aluno selecionado
    if ($aluno_id > 0) {
        $sql_aluno = "SELECT id, nome, matricula, bi, data_nascimento FROM estudantes WHERE id = :id";
        $stmt_aluno = $conn->prepare($sql_aluno);
        $stmt_aluno->execute([':id' => $aluno_id]);
        $aluno_selecionado = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    }
    
    // Buscar classe da turma para determinar escala
    $sql_classe = "SELECT ano FROM turmas WHERE id = :turma_id";
    $stmt_classe = $conn->prepare($sql_classe);
    $stmt_classe->execute([':turma_id' => $turma_id]);
    $turma_classe = $stmt_classe->fetch(PDO::FETCH_ASSOC);
    $classe_ano = $turma_classe['ano'] ?? 0;
    $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
    $escala_max = ($classe_ano <= 6) ? 10 : 20;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    
    // Buscar ano letivo
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
    
    // Buscar disciplinas da turma
    $sql_disciplinas = "
        SELECT d.id, d.nome as disciplina_nome, d.codigo,
               CASE WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' OR d.nome LIKE '%portugues%' OR d.nome LIKE '%ingles%' THEN 1 ELSE 0 END as is_lingua
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disciplinas = $conn->prepare($sql_disciplinas);
    $stmt_disciplinas->execute([':turma_id' => $turma_id]);
    $disciplinas = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar pagamentos da escola
    $sql_pagamentos = "
        SELECT COUNT(*) as total_pagamentos
        FROM pagamentos 
        WHERE (tipo_pagamento LIKE '%boletim%' OR referente LIKE '%boletim%' OR referente LIKE '%BOLETIM%')
        AND status = 'pago'
        AND data_pagamento IS NOT NULL
    ";
    $stmt_pagamentos = $conn->prepare($sql_pagamentos);
    $stmt_pagamentos->execute();
    $total_pagamentos_global = $stmt_pagamentos->fetch(PDO::FETCH_ASSOC)['total_pagamentos'] ?? 0;
    
    if ($total_pagamentos_global >= 2) {
        $bimestres_liberados_global = [1, 2, 3, 4];
    } elseif ($total_pagamentos_global >= 1) {
        $bimestres_liberados_global = [1];
    }
}

$caminho_base = '/sige_Plataforma/uploads/alunos/';

// Função para calcular média final da disciplina
function calcularMediaFinalDisciplina($mac, $npt, $exame_normal, $exame_recurso, $exame_especial, $exame_oral, $exame_escrito, $bimestre, $is_classe_exame, $is_disciplina_lingua) {
    $mac = floatval($mac);
    $npt = floatval($npt);
    $exame_normal = floatval($exame_normal);
    $exame_recurso = floatval($exame_recurso);
    $exame_especial = floatval($exame_especial);
    $exame_oral = floatval($exame_oral);
    $exame_escrito = floatval($exame_escrito);
    
    $media_parcial = ($mac + $npt) / 2;
    
    if ($bimestre == 3 && $is_classe_exame) {
        if ($exame_recurso > 0) {
            return round($exame_recurso, 1);
        }
        if ($is_disciplina_lingua) {
            $media_exame = 0;
            if ($exame_oral > 0 && $exame_escrito > 0) {
                $media_exame = ($exame_oral + $exame_escrito) / 2;
            } elseif ($exame_oral > 0) {
                $media_exame = $exame_oral;
            } elseif ($exame_escrito > 0) {
                $media_exame = $exame_escrito;
            }
            return round(($mac * 0.4) + ($media_exame * 0.6), 1);
        } else {
            if ($exame_normal > 0) {
                return round(($mac * 0.4) + ($exame_normal * 0.6), 1);
            }
            return round($mac, 1);
        }
    }
    
    if ($exame_recurso > 0) {
        return round(($media_parcial + $exame_recurso) / 2, 1);
    } elseif ($exame_normal > 0) {
        return round(($media_parcial + $exame_normal) / 2, 1);
    } elseif ($exame_especial > 0) {
        return round($exame_especial, 1);
    }
    return round($media_parcial, 1);
}

// Buscar notas do aluno selecionado
$notas_aluno = [];
if ($aluno_selecionado && $turma_id > 0) {
    foreach ($disciplinas as $disciplina) {
        $disc = [
            'id' => $disciplina['id'],
            'disciplina_nome' => $disciplina['disciplina_nome'],
            'codigo' => $disciplina['codigo'],
            'is_lingua' => $disciplina['is_lingua']
        ];
        
        for ($bim = 1; $bim <= 4; $bim++) {
            $sql_nota = "
                SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final
                FROM notas
                WHERE estudante_id = :aluno_id 
                AND disciplina_id = :disciplina_id 
                AND bimestre = :bimestre
                AND ano_letivo_id = :ano_letivo_id
            ";
            $stmt_nota = $conn->prepare($sql_nota);
            $stmt_nota->execute([
                ':aluno_id' => $aluno_id,
                ':disciplina_id' => $disciplina['id'],
                ':bimestre' => $bim,
                ':ano_letivo_id' => $ano_letivo_id
            ]);
            $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
            
            $disc['mac_' . $bim] = $nota['mac'] ?? 0;
            $disc['npt_' . $bim] = $nota['npt'] ?? 0;
            $disc['exame_normal_' . $bim] = $nota['exame_normal'] ?? 0;
            $disc['exame_recurso_' . $bim] = $nota['exame_recurso'] ?? 0;
            $disc['exame_especial_' . $bim] = $nota['exame_especial'] ?? 0;
            $disc['exame_oral_' . $bim] = $nota['exame_oral'] ?? 0;
            $disc['exame_escrito_' . $bim] = $nota['exame_escrito'] ?? 0;
            $disc['media_' . $bim] = $nota['media_final'] ?? 0;
        }
        $notas_aluno[] = $disc;
    }
}

// Processar geração de PDF (impressão)
if (isset($_GET['imprimir']) && $_GET['imprimir'] == 1 && $aluno_selecionado) {
    $html_boletim = gerarBoletimHTML(
        $aluno_selecionado, $notas_aluno, $escola, $turma_info, 
        $ano_letivo_ano, $limite_aprovacao, $escala_max, 
        $is_classe_exame, $bimestres_liberados_global, $bimestre_filtro
    );
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Boletim - ' . htmlspecialchars($aluno_selecionado['nome']) . '</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; padding: 20px; }
            .boletim-preview { max-width: 1200px; margin: 0 auto; }
            .boletim-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e5799; padding-bottom: 15px; }
            .info-aluno { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
            .table-boletim { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10px; }
            .table-boletim th { background: #1e5799; color: white; padding: 6px; text-align: center; }
            .table-boletim td { border: 1px solid #ddd; padding: 4px; text-align: center; }
            .table-boletim td.text-start { text-align: left; }
            .media-geral { text-align: center; padding: 12px; background: #e8f4fd; border-radius: 8px; margin-top: 15px; }
            .legenda-notas { background: #f8f9fa; padding: 12px; border-radius: 8px; margin-top: 15px; font-size: 10px; }
            .status-aprovado { color: #27ae60; font-weight: bold; }
            .status-recuperacao { color: #f39c12; font-weight: bold; }
            .status-reprovado { color: #e74c3c; font-weight: bold; }
            .nota-alta { color: #27ae60; font-weight: bold; }
            .nota-baixa { color: #e74c3c; font-weight: bold; }
            @media print {
                body { padding: 0; margin: 0; }
            }
        </style>
    </head>
    <body>
        ' . $html_boletim . '
        <script>
            window.onload = function() { setTimeout(function() { window.print(); }, 500); };
        </script>
    </body>
    </html>';
    exit;
}

// Função para gerar o HTML do boletim
function gerarBoletimHTML($aluno, $disciplinas, $escola, $turma_info, $ano_letivo_ano, $limite_aprovacao, $escala_max, $is_classe_exame, $bimestres_liberados, $bimestre_filtro) {
    $html = '<div class="boletim-preview">';
    
    // Cabeçalho
    $html .= '
        <div class="boletim-header">
            <h2>' . htmlspecialchars($escola['nome']) . '</h2>
            <p>' . htmlspecialchars($escola['endereco'] ?? '') . '</p>
            <p>Ano Letivo: ' . htmlspecialchars($ano_letivo_ano) . '</p>
            <h4>BOLETIM DE NOTAS - ' . $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']) . '</h4>
            ' . ($bimestre_filtro > 0 ? '<p><strong>' . $bimestre_filtro . 'º Bimestre</strong></p>' : '<p><strong>Todos os Bimestres</strong></p>') . '
        </div>
        
        <div class="info-aluno">
            <div class="row">
                <div class="col-md-4"><strong>Aluno:</strong> ' . htmlspecialchars($aluno['nome']) . '</div>
                <div class="col-md-4"><strong>Matrícula:</strong> ' . htmlspecialchars($aluno['matricula']) . '</div>
                <div class="col-md-4"><strong>BI:</strong> ' . htmlspecialchars($aluno['bi'] ?? 'N/A') . '</div>
            </div>
        </div>
        
        <table class="table-boletim">
            <thead>
    ';
    
    // Cabeçalho da tabela baseado no filtro de bimestre
    if ($bimestre_filtro == 0) {
        $html .= '
            <tr>
                <th rowspan="2">Disciplina</th>
                <th colspan="3">1º Bim</th><th colspan="3">2º Bim</th>
                <th colspan="3">3º Bim</th><th colspan="3">4º Bim</th>
                <th rowspan="2">Média</th><th rowspan="2">Status</th>
            </tr>
            <tr>
                <th>MAC</th><th>NPT</th><th>MF</th>
                <th>MAC</th><th>NPT</th><th>MF</th>
                <th>MAC</th><th>NPT</th><th>MF</th>
                <th>MAC</th><th>NPT</th><th>MF</th>
            </tr>
        ';
    } else {
        $html .= '
            <tr>
                <th>Disciplina</th>
                <th colspan="3">' . $bimestre_filtro . 'º Bimestre</th>
                <th>Média</th><th>Status</th>
            </tr>
            <tr><th>MAC</th><th>NPT</th><th>MF</th><th></th><th></th></tr>
        ';
    }
    
    $html .= '</thead><tbody>';
    
    // Calcular médias e montar linhas
    $somaMedias = 0;
    $totalDiscComNota = 0;
    
    foreach ($disciplinas as $disc) {
        $medias = [];
        for ($b = 1; $b <= 4; $b++) {
            $mac = floatval($disc['mac_' . $b] ?? 0);
            $npt = floatval($disc['npt_' . $b] ?? 0);
            $exame_normal = floatval($disc['exame_normal_' . $b] ?? 0);
            $exame_recurso = floatval($disc['exame_recurso_' . $b] ?? 0);
            $exame_especial = floatval($disc['exame_especial_' . $b] ?? 0);
            $exame_oral = floatval($disc['exame_oral_' . $b] ?? 0);
            $exame_escrito = floatval($disc['exame_escrito_' . $b] ?? 0);
            
            $medias[$b] = calcularMediaFinalDisciplina(
                $mac, $npt, $exame_normal, $exame_recurso, $exame_especial,
                $exame_oral, $exame_escrito, $b, $is_classe_exame, $disc['is_lingua']
            );
        }
        
        // Calcular média anual para status
        $mediaAnual = ($medias[1] + $medias[2] + $medias[3] + $medias[4]) / 4;
        if ($mediaAnual > 0) {
            $somaMedias += $mediaAnual;
            $totalDiscComNota++;
        }
        
        $statusDisc = $mediaAnual >= $limite_aprovacao ? 'Aprovado' : ($mediaAnual >= $limite_aprovacao * 0.7 ? 'Recuperação' : ($mediaAnual > 0 ? 'Reprovado' : 'Pendente'));
        $statusClass = $statusDisc === 'Aprovado' ? 'status-aprovado' : ($statusDisc === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
        
        $html .= '<tr>';
        $html .= '<td class="text-start"><strong>' . htmlspecialchars($disc['disciplina_nome']) . '</strong></td>';
        
        if ($bimestre_filtro == 0) {
            // Mostrar todos os bimestres
            for ($b = 1; $b <= 4; $b++) {
                $liberado = in_array($b, $bimestres_liberados);
                $mac = floatval($disc['mac_' . $b] ?? 0);
                $npt = floatval($disc['npt_' . $b] ?? 0);
                $media = $medias[$b];
                
                if (!$liberado) {
                    $html .= '<td colspan="3" style="background:#f8f9fa; text-align:center;">🔒</td>';
                } else {
                    $macClass = $mac >= $limite_aprovacao ? 'nota-alta' : ($mac > 0 ? 'nota-baixa' : '');
                    $nptClass = $npt >= $limite_aprovacao ? 'nota-alta' : ($npt > 0 ? 'nota-baixa' : '');
                    $mediaClass = $media >= $limite_aprovacao ? 'nota-alta' : ($media > 0 ? 'nota-baixa' : '');
                    $macText = $mac > 0 ? number_format($mac, 1) : '-';
                    $nptText = $npt > 0 ? number_format($npt, 1) : '-';
                    $mediaText = $media > 0 ? number_format($media, 1) : '-';
                    
                    $html .= '<td class="' . $macClass . '">' . $macText . '</td>';
                    $html .= '<td class="' . $nptClass . '">' . $nptText . '</td>';
                    $html .= '<td class="' . $mediaClass . '"><strong>' . $mediaText . '</strong></td>';
                }
            }
            $mediaAnualText = $mediaAnual > 0 ? number_format($mediaAnual, 1) : '-';
            $mediaAnualClass = $mediaAnual >= $limite_aprovacao ? 'nota-alta' : ($mediaAnual > 0 ? 'nota-baixa' : '');
            $html .= '<td class="' . $mediaAnualClass . '"><strong>' . $mediaAnualText . '</strong></td>';
            $html .= '<td class="' . $statusClass . '">' . $statusDisc . '</td>';
        } else {
            // Mostrar apenas o bimestre selecionado
            $b = $bimestre_filtro;
            $liberado = in_array($b, $bimestres_liberados);
            $mac = floatval($disc['mac_' . $b] ?? 0);
            $npt = floatval($disc['npt_' . $b] ?? 0);
            $media = $medias[$b];
            
            if (!$liberado) {
                $html .= '<td colspan="3" style="background:#f8f9fa; text-align:center;">🔒</td>';
            } else {
                $macClass = $mac >= $limite_aprovacao ? 'nota-alta' : ($mac > 0 ? 'nota-baixa' : '');
                $nptClass = $npt >= $limite_aprovacao ? 'nota-alta' : ($npt > 0 ? 'nota-baixa' : '');
                $mediaClass = $media >= $limite_aprovacao ? 'nota-alta' : ($media > 0 ? 'nota-baixa' : '');
                $macText = $mac > 0 ? number_format($mac, 1) : '-';
                $nptText = $npt > 0 ? number_format($npt, 1) : '-';
                $mediaText = $media > 0 ? number_format($media, 1) : '-';
                
                $html .= '<td class="' . $macClass . '">' . $macText . '<td>';
                $html .= '<td class="' . $nptClass . '">' . $nptText . '<td>';
                $html .= '<td class="' . $mediaClass . '"><strong>' . $mediaText . '</strong></td>';
            }
            
            $mediaBimestreText = $media > 0 ? number_format($media, 1) : '-';
            $mediaBimestreClass = $media >= $limite_aprovacao ? 'nota-alta' : ($media > 0 ? 'nota-baixa' : '');
            $statusBimestre = $media >= $limite_aprovacao ? 'Aprovado' : ($media >= $limite_aprovacao * 0.7 ? 'Recuperação' : ($media > 0 ? 'Reprovado' : 'Pendente'));
            $statusBimestreClass = $statusBimestre === 'Aprovado' ? 'status-aprovado' : ($statusBimestre === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
            
            $html .= '<td class="' . $mediaBimestreClass . '"><strong>' . $mediaBimestreText . '</strong></td>';
            $html .= '<td class="' . $statusBimestreClass . '">' . $statusBimestre . '</td>';
        }
        
        $html .= '</tr>';
    }
    
    $mediaGeral = $totalDiscComNota > 0 ? number_format($somaMedias / $totalDiscComNota, 1) : '0.0';
    $statusGeral = floatval($mediaGeral) >= $limite_aprovacao ? 'Aprovado' : (floatval($mediaGeral) >= $limite_aprovacao * 0.7 ? 'Recuperação' : (floatval($mediaGeral) > 0 ? 'Reprovado' : 'Pendente'));
    $statusGeralClass = $statusGeral === 'Aprovado' ? 'status-aprovado' : ($statusGeral === 'Recuperação' ? 'status-recuperacao' : 'status-reprovado');
    
    $html .= '
            </tbody>
        </table>
        
        <div class="media-geral">
            <strong>MÉDIA GERAL:</strong> ' . $mediaGeral . ' pontos &nbsp;&nbsp;&nbsp;
            <strong>STATUS:</strong> <span class="' . $statusGeralClass . '">' . $statusGeral . '</span><br>
            <small>Escala: 0-' . $escala_max . ' | Mínimo aprovação: ' . $limite_aprovacao . ' pontos</small>
        </div>
        
        <div class="legenda-notas">
            <h6>Legenda</h6>
            <div class="row">
                <div class="col-md-3"><span class="nota-alta">MAC</span> - Média Atividades Classe</div>
                <div class="col-md-3"><span class="nota-baixa">NPT</span> - Nota Prova Trimestral</div>
                <div class="col-md-3"><span class="nota-alta">MF</span> - Média Final</div>
                <div class="col-md-3"><span class="nota-alta">🔒</span> - Bimestre bloqueado</div>
            </div>
            ' . ($is_classe_exame ? '<div class="mt-2"><small>⚠️ Classes de Exame (6ª, 9ª, 12ª): 3º Bimestre = 40% MAC + 60% Exame</small></div>' : '') . '
            <div class="mt-2"><small>📌 Exames complementares substituem a média quando disponíveis</small></div>
            <div class="mt-2"><small>📌 Cálculo da Média Final: (MAC + NPT) / 2</small></div>
            <div class="mt-2 text-muted">Documento gerado por SIGE em ' . date('d/m/Y H:i:s') . '</div>
        </div>
    </div>';
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletim Individual - SIGE Angola</title>
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
        .btn-imprimir { background: #1e5799; color: white; padding: 10px 25px; border: none; border-radius: 8px; cursor: pointer; margin-left: 10px; }
        .btn-filtrar:hover, .btn-imprimir:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .table-alunos { width: 100%; border-collapse: collapse; }
        .table-alunos th { background: #f8f9fa; padding: 12px; text-align: center; border-bottom: 2px solid #1e5799; }
        .table-alunos td { padding: 10px; border-bottom: 1px solid #ecf0f1; vertical-align: middle; }
        .aluno-info { display: flex; align-items: center; gap: 10px; }
        .aluno-foto { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; border: 2px solid #1e5799; }
        .aluno-foto-placeholder { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #1e5799, #2c3e50); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
        
        /* Preview do Boletim */
        .boletim-preview {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .boletim-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #1e5799; padding-bottom: 15px; }
        .info-aluno { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd; }
        .table-boletim { width: 100%; border-collapse: collapse; font-size: 11px; }
        .table-boletim th { background: #1e5799; color: white; padding: 8px; text-align: center; }
        .table-boletim td { border: 1px solid #ddd; padding: 6px; text-align: center; }
        .table-boletim td.text-start { text-align: left; }
        .media-geral { text-align: center; padding: 15px; background: #e8f4fd; border-radius: 8px; margin-top: 20px; }
        .legenda-notas { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-top: 20px; font-size: 11px; border: 1px solid #ddd; }
        .status-aprovado { color: #27ae60; font-weight: bold; }
        .status-recuperacao { color: #f39c12; font-weight: bold; }
        .status-reprovado { color: #e74c3c; font-weight: bold; }
        .nota-alta { color: #27ae60; font-weight: bold; }
        .nota-baixa { color: #e74c3c; font-weight: bold; }
        
        @media (max-width: 768px) {
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-alunos { font-size: 11px; }
            .table-boletim { font-size: 9px; }
            .table-boletim th, .table-boletim td { padding: 4px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-user-graduate"></i> Boletim Individual</h1>
            <p>Visualize e imprima o boletim de um aluno específico</p>
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
                        <select name="turma_id" class="filtro-select" onchange="this.form.submit()">
                            <option value="">Selecione</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno_nome']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Aluno</label>
                        <select name="aluno_id" class="filtro-select">
                            <option value="">Selecione</option>
                            <?php foreach ($alunos as $aluno): ?>
                                <option value="<?php echo $aluno['id']; ?>" <?php echo ($aluno_id == $aluno['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($aluno['nome']); ?> (<?php echo htmlspecialchars($aluno['matricula']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="0">Todos os Bimestres</option>
                            <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                            <option value="4" <?php echo ($bimestre_filtro == 4) ? 'selected' : ''; ?>>4º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">Buscar</button>
                        <?php if ($aluno_selecionado): ?>
                            <button type="button" class="btn-imprimir" onclick="imprimirBoletim()">
                                <i class="fas fa-print"></i> Imprimir Boletim
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Boletim do Aluno -->
    <?php if ($aluno_selecionado && !empty($notas_aluno)): ?>
        <div class="boletim-preview">
            <?php echo gerarBoletimHTML(
                $aluno_selecionado, $notas_aluno, $escola, $turma_info, 
                $ano_letivo_ano, $limite_aprovacao, $escala_max, 
                $is_classe_exame, $bimestres_liberados_global, $bimestre_filtro
            ); ?>
        </div>
    <?php elseif ($aluno_selecionado && empty($notas_aluno)): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle"></i> Nenhuma nota encontrada para este aluno.
        </div>
    <?php elseif ($turma_id > 0 && empty($alunos)): ?>
        <div class="alert alert-info">Nenhum aluno encontrado para esta turma.</div>
    <?php elseif ($turma_id > 0 && $aluno_id == 0): ?>
        <div class="alert alert-info">Selecione um aluno para visualizar o boletim.</div>
    <?php endif; ?>
</div>

<script>
    function imprimirBoletim() {
        const url = window.location.href + '&imprimir=1';
        window.open(url, '_blank');
    }
    
    // Auto-submit quando selecionar turma
    document.querySelector('select[name="turma_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>