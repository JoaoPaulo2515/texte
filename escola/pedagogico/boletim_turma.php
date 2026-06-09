<?php
// escola/pedagogico/boletim_turma.php - Boletim da Turma

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
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0; // 0 = todos, 1-4 = bimestre específico

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// BUSCAR ALUNOS DA TURMA
$alunos = [];
$turma_info = null;
$bimestres_liberados_global = [];
$total_pagamentos_global = 0;
$disciplinas = [];
$classe_ano = 0;
$limite_aprovacao = 5;
$escala_max = 10;
$is_classe_exame = false;

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
    
    // Buscar classe da turma para determinar escala
    $sql_classe = "SELECT ano FROM turmas WHERE id = :turma_id";
    $stmt_classe = $conn->prepare($sql_classe);
    $stmt_classe->execute([':turma_id' => $turma_id]);
    $turma_classe = $stmt_classe->fetch(PDO::FETCH_ASSOC);
    $classe_ano = $turma_classe['ano'] ?? 0;
    $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
    $escala_max = ($classe_ano <= 6) ? 10 : 20;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    
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
    
    // Buscar pagamentos da escola (para verificar bimestres liberados)
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

// Função para gerar o HTML de um boletim individual
function gerarBoletimHTML($aluno, $disciplinas, $escola, $turma_info, $ano_letivo_ano, $limite_aprovacao, $escala_max, $is_classe_exame, $bimestres_liberados, $bimestre_filtro) {
    $html = '<div class="boletim-preview" style="page-break-after: always; margin-bottom: 30px;">';
    
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
                
                $html .= '<td class="' . $macClass . '">' . $macText . '</td>';
                $html .= '<td class="' . $nptClass . '">' . $nptText . '</td>';
                $html .= '<td class="' . $mediaClass . '"><strong>' . $mediaText . '</strong></td>';
            }
            
            // Para o filtro por bimestre, mostrar a média do bimestre como "Média do Bimestre"
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
            <div class="mt-2"><small>📌 Cálculo da Média Final: (MAC + NPT) / 2</small></div>
            <div class="mt-2 text-muted">Documento gerado por SIGE em ' . date('d/m/Y H:i:s') . '</div>
        </div>
    </div>';
    
    return $html;
}

// Processar AJAX para buscar notas de um aluno (usado no preview individual)
if (isset($_GET['ajax']) && $_GET['ajax'] == 1 && isset($_GET['aluno_id'])) {
    header('Content-Type: application/json');
    
    $aluno_id = (int)$_GET['aluno_id'];
    $turma_id = (int)$_GET['turma_id'];
    $ano_letivo_id = (int)$_GET['ano_letivo_id'];
    $bimestre_filtro_ajax = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
    
    // Buscar classe da turma
    $sql_classe = "SELECT ano FROM turmas WHERE id = :turma_id";
    $stmt_classe = $conn->prepare($sql_classe);
    $stmt_classe->execute([':turma_id' => $turma_id]);
    $turma_classe = $stmt_classe->fetch(PDO::FETCH_ASSOC);
    $classe_ano_local = $turma_classe['ano'] ?? 0;
    $limite_local = ($classe_ano_local <= 6) ? 5 : 10;
    $escala_local = ($classe_ano_local <= 6) ? 10 : 20;
    $is_exame_local = ($classe_ano_local == 6 || $classe_ano_local == 9 || $classe_ano_local == 12);
    
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
    $disciplinas_local = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);
    
    $resultado = [];
    foreach ($disciplinas_local as $disciplina) {
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
        $resultado[] = $disc;
    }
    
    echo json_encode([
        'success' => true,
        'disciplinas' => $resultado,
        'limite_aprovacao' => $limite_local,
        'escala_max' => $escala_local,
        'ano_letivo' => $ano_letivo_ano,
        'is_classe_exame' => $is_exame_local
    ]);
    exit;
}

// Processar geração em massa via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gerar_massa']) && $_POST['gerar_massa'] == 1) {
    $alunos_ids = isset($_POST['alunos_ids']) ? explode(',', $_POST['alunos_ids']) : [];
    $bimestre_post = isset($_POST['bimestre']) ? (int)$_POST['bimestre'] : 0;
    
    if (empty($alunos_ids)) {
        die('Nenhum aluno selecionado');
    }
    
    $boletins_html = [];
    $ano_letivo_ano = '';
    
    // Buscar ano letivo
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
    
    foreach ($alunos_ids as $aluno_id) {
        // Buscar dados do aluno
        $sql_aluno = "SELECT id, nome, matricula, bi FROM estudantes WHERE id = :id";
        $stmt_aluno = $conn->prepare($sql_aluno);
        $stmt_aluno->execute([':id' => $aluno_id]);
        $aluno = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
        
        if (!$aluno) continue;
        
        // Buscar notas do aluno
        $disciplinas_aluno = [];
        foreach ($disciplinas as $disciplina) {
            $disc = [
                'id' => $disciplina['id'],
                'disciplina_nome' => $disciplina['disciplina_nome'],
                'codigo' => $disciplina['codigo'],
                'is_lingua' => $disciplina['is_lingua']
            ];
            
            for ($bim = 1; $bim <= 4; $bim++) {
                $sql_nota = "
                    SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito
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
            }
            $disciplinas_aluno[] = $disc;
        }
        
        $boletins_html[] = gerarBoletimHTML(
            $aluno, $disciplinas_aluno, $escola, $turma_info, 
            $ano_letivo_ano, $limite_aprovacao, $escala_max, 
            $is_classe_exame, $bimestres_liberados_global, $bimestre_post
        );
    }
    
    // Enviar HTML para impressão
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>Boletins da Turma - ' . $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']) . '</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 12px; padding: 20px; }
            .boletim-preview { page-break-after: always; margin-bottom: 30px; }
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
                .boletim-preview { page-break-after: always; }
            }
        </style>
    </head>
    <body>
        ' . implode('', $boletins_html) . '
        <script>
            window.onload = function() { setTimeout(function() { window.print(); setTimeout(function() { window.close(); }, 500); }, 500); };
        <\/script>
    </body>
    </html>';
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletim da Turma - SIGE Angola</title>
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
        .btn-gerar { background: #1e5799; color: white; padding: 10px 25px; border: none; border-radius: 8px; cursor: pointer; margin-left: 10px; }
        .btn-gerar:hover, .btn-filtrar:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .table-alunos { width: 100%; border-collapse: collapse; }
        .table-alunos th { background: #f8f9fa; padding: 12px; text-align: center; border-bottom: 2px solid #1e5799; }
        .table-alunos td { padding: 10px; border-bottom: 1px solid #ecf0f1; vertical-align: middle; }
        
        .selecionados-lista { background: #f8f9fa; border-radius: 8px; padding: 15px; margin-top: 15px; }
        .aluno-selecionado { display: inline-block; background: #e9ecef; padding: 5px 10px; border-radius: 20px; margin: 5px; }
        
        @media (max-width: 768px) {
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-alunos { font-size: 11px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-file-pdf"></i> Boletim da Turma</h1>
            <p>Visualize e imprima boletins de todos os alunos da turma</p>
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
                                    <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?> (<?php echo ucfirst($t['turno_nome']); ?>)
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
                        <?php if ($turma_id > 0 && !empty($alunos)): ?>
                            <button type="button" class="btn-gerar" onclick="gerarTodosBoletins()">
                                <i class="fas fa-print"></i> Gerar Todos os Boletins
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma_id > 0 && !empty($alunos)): ?>
    <div class="card">
        <div class="card-header">
            <i class="fas fa-users"></i> Alunos da Turma (<?php echo count($alunos); ?> alunos)
            <button type="button" class="btn btn-sm btn-secondary float-end" onclick="selecionarTodos()">Selecionar Todos</button>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-alunos">
                    <thead>
                        <tr><th style="width: 40px;"><input type="checkbox" id="selecionarTodosCheckbox" onchange="toggleSelecionarTodos()"></th><th>#</th><th>Aluno</th><th>Matrícula</th><th>BI</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alunos as $index => $aluno): ?>
                            <tr>
                                <td><input type="checkbox" class="aluno-checkbox" data-id="<?php echo $aluno['id']; ?>" data-nome="<?php echo htmlspecialchars($aluno['nome']); ?>"></td>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><?php echo htmlspecialchars($aluno['bi'] ?? '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="card" id="selecionadosCard" style="display: none;">
        <div class="card-header">
            <i class="fas fa-check-circle"></i> Alunos Selecionados
        </div>
        <div class="card-body">
            <div id="selecionadosLista"></div>
            <button type="button" class="btn-gerar mt-2" onclick="gerarSelecionados()">
                <i class="fas fa-print"></i> Gerar Boletins dos Selecionados
            </button>
        </div>
    </div>
    <?php elseif ($turma_id > 0 && empty($alunos)): ?>
        <div class="alert alert-info">Nenhum aluno encontrado para esta turma.</div>
    <?php endif; ?>
</div>

<script>
    let alunosSelecionados = [];
    let bimestreAtual = <?php echo $bimestre_filtro; ?>;
    
    function toggleSelecionarTodos() {
        const checkboxes = document.querySelectorAll('.aluno-checkbox');
        const selecionarTodos = document.getElementById('selecionarTodosCheckbox');
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
    
    function adicionarAlunoSelecionado(cb) {
        const aluno = {
            id: cb.dataset.id,
            nome: cb.dataset.nome
        };
        if (!alunosSelecionados.find(a => a.id == aluno.id)) {
            alunosSelecionados.push(aluno);
        }
    }
    
    function removerAlunoSelecionado(cb) {
        const id = cb.dataset.id;
        alunosSelecionados = alunosSelecionados.filter(a => a.id != id);
    }
    
    function selecionarTodos() {
        const checkboxes = document.querySelectorAll('.aluno-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = true;
            adicionarAlunoSelecionado(cb);
        });
        document.getElementById('selecionarTodosCheckbox').checked = true;
        atualizarListaSelecionados();
    }
    
    function atualizarListaSelecionados() {
        const listaDiv = document.getElementById('selecionadosLista');
        const card = document.getElementById('selecionadosCard');
        
        if (alunosSelecionados.length === 0) {
            card.style.display = 'none';
        } else {
            card.style.display = 'block';
            let html = '<div class="selecionados-lista">';
            alunosSelecionados.forEach((aluno, idx) => {
                html += `<span class="aluno-selecionado">
                            ${aluno.nome}
                            <button type="button" class="btn btn-sm btn-danger ms-1" onclick="removerSelecionado(${idx})">&times;</button>
                        </span>`;
            });
            html += '</div>';
            listaDiv.innerHTML = html;
        }
    }
    
    function removerSelecionado(index) {
        const aluno = alunosSelecionados[index];
        alunosSelecionados.splice(index, 1);
        
        const checkboxes = document.querySelectorAll('.aluno-checkbox');
        checkboxes.forEach(cb => {
            if (cb.dataset.id == aluno.id) {
                cb.checked = false;
            }
        });
        
        document.getElementById('selecionarTodosCheckbox').checked = false;
        atualizarListaSelecionados();
    }
    
    function gerarTodosBoletins() {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.target = '_blank';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'gerar_massa';
        input.value = '1';
        form.appendChild(input);
        
        const inputBimestre = document.createElement('input');
        inputBimestre.type = 'hidden';
        inputBimestre.name = 'bimestre';
        inputBimestre.value = bimestreAtual;
        form.appendChild(inputBimestre);
        
        const alunosIds = <?php echo json_encode(array_column($alunos, 'id')); ?>;
        const inputIds = document.createElement('input');
        inputIds.type = 'hidden';
        inputIds.name = 'alunos_ids';
        inputIds.value = alunosIds.join(',');
        form.appendChild(inputIds);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    function gerarSelecionados() {
        if (alunosSelecionados.length === 0) {
            alert('Selecione pelo menos um aluno.');
            return;
        }
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        form.target = '_blank';
        
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'gerar_massa';
        input.value = '1';
        form.appendChild(input);
        
        const inputBimestre = document.createElement('input');
        inputBimestre.type = 'hidden';
        inputBimestre.name = 'bimestre';
        inputBimestre.value = bimestreAtual;
        form.appendChild(inputBimestre);
        
        const ids = alunosSelecionados.map(a => a.id).join(',');
        const inputIds = document.createElement('input');
        inputIds.type = 'hidden';
        inputIds.name = 'alunos_ids';
        inputIds.value = ids;
        form.appendChild(inputIds);
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }
    
    document.getElementById('formFiltros')?.addEventListener('change', function() {
        this.submit();
    });
</script>
</body>
</html>