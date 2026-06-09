<?php
// escola/pedagogico/desempenho_turma.php - Desempenho da Turma

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
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 1;
$imprimir = isset($_GET['imprimir']) ? (int)$_GET['imprimir'] : 0;

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// Função para calcular média final da disciplina
function calcMedia($mac, $npt, $exame_normal, $exame_recurso, $exame_especial, $exame_oral, $exame_escrito, $bimestre, $is_exame, $is_lingua) {
    $mac = floatval($mac);
    $npt = floatval($npt);
    $exame_normal = floatval($exame_normal);
    $exame_recurso = floatval($exame_recurso);
    $exame_especial = floatval($exame_especial);
    $exame_oral = floatval($exame_oral);
    $exame_escrito = floatval($exame_escrito);
    
    $media_parcial = ($mac + $npt) / 2;
    
    if ($bimestre == 3 && $is_exame) {
        if ($exame_recurso > 0) return round($exame_recurso, 1);
        if ($is_lingua) {
            $media_exame = 0;
            if ($exame_oral > 0 && $exame_escrito > 0) $media_exame = ($exame_oral + $exame_escrito) / 2;
            elseif ($exame_oral > 0) $media_exame = $exame_oral;
            elseif ($exame_escrito > 0) $media_exame = $exame_escrito;
            return round(($mac * 0.4) + ($media_exame * 0.6), 1);
        } else {
            if ($exame_normal > 0) return round(($mac * 0.4) + ($exame_normal * 0.6), 1);
            return round($mac, 1);
        }
    }
    
    if ($exame_recurso > 0) return round(($media_parcial + $exame_recurso) / 2, 1);
    if ($exame_normal > 0) return round(($media_parcial + $exame_normal) / 2, 1);
    if ($exame_especial > 0) return round($exame_especial, 1);
    return round($media_parcial, 1);
}

// ============================================
// BUSCAR DADOS DA TURMA
// ============================================
$turma_info = null;
$alunos = [];
$desempenho_disciplinas = [];
$evolucao_turma = [];
$ranking_alunos = [];
$total_alunos = 0;
$total_aprovados = 0;
$total_reprovados = 0;
$total_recuperacao = 0;
$media_geral_turma = 0;
$taxa_aprovacao = 0;
$taxa_recuperacao = 0;
$taxa_reprovacao = 0;
$classe_ano = 0;
$limite_aprovacao = 5;
$escala_max = 10;
$is_classe_exame = false;
$ano_letivo_ano = '';

// Estatísticas por gênero
$genero_estatisticas = [
    'masculino' => ['total' => 0, 'aprovados' => 0, 'recuperacao' => 0, 'reprovados' => 0, 'soma_medias' => 0, 'alunos_com_nota' => 0],
    'feminino' => ['total' => 0, 'aprovados' => 0, 'recuperacao' => 0, 'reprovados' => 0, 'soma_medias' => 0, 'alunos_com_nota' => 0]
];

if ($turma_id > 0 && $ano_letivo_id > 0) {
    // Buscar informações da turma
    $sql_turma_info = "
        SELECT t.id, t.nome, t.ano, tr.nome as turno_nome,
               (SELECT COUNT(*) FROM matriculas WHERE turma_id = t.id AND status = 'ativa' AND ano_letivo = :ano_letivo) as total_alunos
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE t.id = :turma_id
    ";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':turma_id' => $turma_id, ':ano_letivo' => $ano_letivo_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma com gênero
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.bi,
            e.genero,
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
    
    // Buscar disciplinas da turma
    $sql_disc_turma = "
        SELECT d.id, d.nome, d.codigo,
               CASE WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' OR d.nome LIKE '%portugues%' OR d.nome LIKE '%ingles%' THEN 1 ELSE 0 END as is_lingua
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disc_turma = $conn->prepare($sql_disc_turma);
    $stmt_disc_turma->execute([':turma_id' => $turma_id]);
    $disciplinas_turma = $stmt_disc_turma->fetchAll(PDO::FETCH_ASSOC);
    
    // Determinar escala da turma
    $classe_ano = $turma_info['ano'] ?? 0;
    $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
    $escala_max = ($classe_ano <= 6) ? 10 : 20;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    
    // Buscar ano letivo
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
    
    $total_alunos = count($alunos);
    $soma_medias_gerais = 0;
    
    // Inicializar contadores por gênero
    foreach ($alunos as $aluno) {
        $genero = strtolower($aluno['genero'] ?? '');
        if ($genero == 'masculino' || $genero == 'm' || $genero == 'male') {
            $genero_estatisticas['masculino']['total']++;
        } elseif ($genero == 'feminino' || $genero == 'f' || $genero == 'female') {
            $genero_estatisticas['feminino']['total']++;
        }
    }
    
    // Calcular estatísticas gerais e por gênero
    foreach ($alunos as $aluno) {
        $genero = strtolower($aluno['genero'] ?? '');
        $genero_key = ($genero == 'masculino' || $genero == 'm' || $genero == 'male') ? 'masculino' : (($genero == 'feminino' || $genero == 'f' || $genero == 'female') ? 'feminino' : null);
        
        $soma_notas_aluno = 0;
        $count_disciplinas = 0;
        
        foreach ($disciplinas_turma as $disc) {
            $sql_nota = "
                SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final
                FROM notas
                WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
            ";
            $stmt_nota = $conn->prepare($sql_nota);
            $stmt_nota->execute([
                ':aluno_id' => $aluno['id'],
                ':disc_id' => $disc['id'],
                ':bimestre' => $bimestre_filtro,
                ':ano_letivo' => $ano_letivo_id
            ]);
            $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($nota) {
                $media = calcMedia(
                    $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                    $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                    $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                    $nota['exame_escrito'] ?? 0,
                    $bimestre_filtro, $is_classe_exame, $disc['is_lingua']
                );
                if ($media > 0) {
                    $soma_notas_aluno += $media;
                    $count_disciplinas++;
                }
            }
        }
        
        if ($count_disciplinas > 0) {
            $media_geral = $soma_notas_aluno / $count_disciplinas;
            $soma_medias_gerais += $media_geral;
            
            // Estatísticas por gênero
            if ($genero_key) {
                $genero_estatisticas[$genero_key]['soma_medias'] += $media_geral;
                $genero_estatisticas[$genero_key]['alunos_com_nota']++;
                
                if ($media_geral >= $limite_aprovacao) {
                    $total_aprovados++;
                    $genero_estatisticas[$genero_key]['aprovados']++;
                } elseif ($media_geral >= $limite_aprovacao * 0.7) {
                    $total_recuperacao++;
                    $genero_estatisticas[$genero_key]['recuperacao']++;
                } elseif ($media_geral > 0) {
                    $total_reprovados++;
                    $genero_estatisticas[$genero_key]['reprovados']++;
                }
            }
        }
    }
    
    // Calcular médias por gênero
    foreach ($genero_estatisticas as $key => $data) {
        if ($data['alunos_com_nota'] > 0) {
            $genero_estatisticas[$key]['media'] = round($data['soma_medias'] / $data['alunos_com_nota'], 1);
        } else {
            $genero_estatisticas[$key]['media'] = 0;
        }
        $genero_estatisticas[$key]['taxa_aprovacao'] = $data['total'] > 0 ? round(($data['aprovados'] / $data['total']) * 100, 1) : 0;
    }
    
    $media_geral_turma = $total_alunos > 0 ? round($soma_medias_gerais / $total_alunos, 1) : 0;
    $taxa_aprovacao = $total_alunos > 0 ? round(($total_aprovados / $total_alunos) * 100, 1) : 0;
    $taxa_recuperacao = $total_alunos > 0 ? round(($total_recuperacao / $total_alunos) * 100, 1) : 0;
    $taxa_reprovacao = $total_alunos > 0 ? round(($total_reprovados / $total_alunos) * 100, 1) : 0;
    
    // Desempenho por disciplina
    foreach ($disciplinas_turma as $disc) {
        $soma_notas_disc = 0;
        $count_notas_disc = 0;
        
        foreach ($alunos as $aluno) {
            $sql_nota = "
                SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final
                FROM notas
                WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
            ";
            $stmt_nota = $conn->prepare($sql_nota);
            $stmt_nota->execute([
                ':aluno_id' => $aluno['id'],
                ':disc_id' => $disc['id'],
                ':bimestre' => $bimestre_filtro,
                ':ano_letivo' => $ano_letivo_id
            ]);
            $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($nota) {
                $media = calcMedia(
                    $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                    $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                    $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                    $nota['exame_escrito'] ?? 0,
                    $bimestre_filtro, $is_classe_exame, $disc['is_lingua']
                );
                if ($media > 0) {
                    $soma_notas_disc += $media;
                    $count_notas_disc++;
                }
            }
        }
        
        $media_disciplina = $count_notas_disc > 0 ? round($soma_notas_disc / $count_notas_disc, 1) : 0;
        $desempenho_disciplinas[] = [
            'id' => $disc['id'],
            'nome' => $disc['nome'],
            'codigo' => $disc['codigo'],
            'media' => $media_disciplina,
            'total_alunos' => $count_notas_disc
        ];
    }
    
    usort($desempenho_disciplinas, function($a, $b) {
        return $b['media'] <=> $a['media'];
    });
    
    // Evolução da turma por bimestre (apenas 3 bimestres)
    for ($bim = 1; $bim <= 3; $bim++) {
        $soma_medias_bim = 0;
        $count_alunos_bim = 0;
        
        foreach ($alunos as $aluno) {
            $soma_notas_aluno_bim = 0;
            $count_disc_bim = 0;
            
            foreach ($disciplinas_turma as $disc) {
                $sql_nota = "
                    SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final
                    FROM notas
                    WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
                ";
                $stmt_nota = $conn->prepare($sql_nota);
                $stmt_nota->execute([
                    ':aluno_id' => $aluno['id'],
                    ':disc_id' => $disc['id'],
                    ':bimestre' => $bim,
                    ':ano_letivo' => $ano_letivo_id
                ]);
                $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
                
                if ($nota) {
                    $media = calcMedia(
                        $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                        $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                        $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                        $nota['exame_escrito'] ?? 0,
                        $bim, $is_classe_exame, $disc['is_lingua']
                    );
                    if ($media > 0) {
                        $soma_notas_aluno_bim += $media;
                        $count_disc_bim++;
                    }
                }
            }
            
            if ($count_disc_bim > 0) {
                $media_aluno_bim = $soma_notas_aluno_bim / $count_disc_bim;
                $soma_medias_bim += $media_aluno_bim;
                $count_alunos_bim++;
            }
        }
        
        $media_turma_bim = $count_alunos_bim > 0 ? round($soma_medias_bim / $count_alunos_bim, 1) : 0;
        $evolucao_turma[] = [
            'bimestre' => $bim,
            'media' => $media_turma_bim,
            'total_alunos' => $count_alunos_bim
        ];
    }
    
    // Ranking dos alunos
    foreach ($alunos as $aluno) {
        $soma_notas_aluno = 0;
        $count_disciplinas = 0;
        
        foreach ($disciplinas_turma as $disc) {
            $sql_nota = "
                SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final
                FROM notas
                WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
            ";
            $stmt_nota = $conn->prepare($sql_nota);
            $stmt_nota->execute([
                ':aluno_id' => $aluno['id'],
                ':disc_id' => $disc['id'],
                ':bimestre' => $bimestre_filtro,
                ':ano_letivo' => $ano_letivo_id
            ]);
            $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($nota) {
                $media = calcMedia(
                    $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                    $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                    $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                    $nota['exame_escrito'] ?? 0,
                    $bimestre_filtro, $is_classe_exame, $disc['is_lingua']
                );
                $soma_notas_aluno += $media;
                $count_disciplinas++;
            }
        }
        
        $media_geral = $count_disciplinas > 0 ? round($soma_notas_aluno / $count_disciplinas, 1) : 0;
        
        $status = 'pendente';
        if ($media_geral >= $limite_aprovacao) {
            $status = 'aprovado';
        } elseif ($media_geral >= $limite_aprovacao * 0.7) {
            $status = 'recuperacao';
        } elseif ($media_geral > 0) {
            $status = 'reprovado';
        }
        
        $ranking_alunos[] = [
            'id' => $aluno['id'],
            'nome' => $aluno['nome'],
            'matricula' => $aluno['matricula'],
            'genero' => $aluno['genero'] ?? '',
            'media' => $media_geral,
            'status' => $status
        ];
    }
    
    usort($ranking_alunos, function($a, $b) {
        return $b['media'] <=> $a['media'];
    });
}

// ============================================
// GERAR HTML PARA IMPRESSÃO/PDF
// ============================================
if ($imprimir == 1 && $turma_id > 0 && $turma_info) {
    $html_relatorio = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Desempenho - ' . $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']) . '</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e5799; padding-bottom: 15px; }
            .header h2 { color: #1e5799; margin-bottom: 5px; }
            .info-turma { background: #f8f9fa; padding: 10px; border-radius: 8px; margin-bottom: 20px; }
            .stats-grid { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
            .stat-card { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; flex: 1; min-width: 120px; }
            .stat-number { font-size: 24px; font-weight: bold; }
            .stat-label { font-size: 10px; color: #666; }
            .table-desempenho { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .table-desempenho th { background: #1e5799; color: white; padding: 8px; text-align: center; font-size: 10px; }
            .table-desempenho td { border: 1px solid #ddd; padding: 6px; text-align: center; }
            .table-desempenho td.text-start { text-align: left; }
            .progress { height: 6px; background: #e9ecef; border-radius: 3px; }
            .progress-bar { height: 6px; border-radius: 3px; }
            .badge-aprovado { background: #d4edda; color: #155724; padding: 2px 6px; border-radius: 10px; font-size: 9px; }
            .badge-recuperacao { background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 10px; font-size: 9px; }
            .badge-reprovado { background: #f8d7da; color: #721c24; padding: 2px 6px; border-radius: 10px; font-size: 9px; }
            .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 9px; color: #666; }
            @media print {
                body { padding: 0; margin: 0; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>' . htmlspecialchars($escola['nome']) . '</h2>
            <p>' . htmlspecialchars($escola['endereco'] ?? '') . '</p>
            <h4>RELATÓRIO DE DESEMPENHO DA TURMA</h4>
            <p><strong>' . $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']) . '</strong> - ' . ucfirst($turma_info['turno_nome'] ?? '') . '</p>
            <p>Ano Letivo: ' . $ano_letivo_ano . ' | Bimestre: ' . $bimestre_filtro . 'º Bimestre</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" style="color: #27ae60;">' . $total_aprovados . '</div>
                <div class="stat-label">Aprovados</div>
                <small>' . $taxa_aprovacao . '%</small>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f39c12;">' . $total_recuperacao . '</div>
                <div class="stat-label">Recuperação</div>
                <small>' . $taxa_recuperacao . '%</small>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #e74c3c;">' . $total_reprovados . '</div>
                <div class="stat-label">Reprovados</div>
                <small>' . $taxa_reprovacao . '%</small>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #1e5799;">' . $media_geral_turma . '</div>
                <div class="stat-label">Média Geral</div>
                <small>0-' . $escala_max . '</small>
            </div>
        </div>
        
        <h5>📊 Desempenho por Disciplina</h5>
        <table class="table-desempenho">
            <thead>
                <tr><th>#</th><th>Disciplina</th><th>Média</th><th>Avaliados</th><th>Desempenho</th></tr>
            </thead>
            <tbody>';
    
    foreach ($desempenho_disciplinas as $index => $disc) {
        $percentual = $escala_max > 0 ? round(($disc['media'] / $escala_max) * 100, 1) : 0;
        $cor = $percentual >= 75 ? '#28a745' : ($percentual >= 50 ? '#ffc107' : '#dc3545');
        $html_relatorio .= '
            <tr>
                <td>' . ($index + 1) . '</td>
                <td class="text-start"><strong>' . htmlspecialchars($disc['nome']) . '</strong></td>
                <td>' . number_format($disc['media'], 1) . ' / ' . $escala_max . '</td>
                <td>' . $disc['total_alunos'] . ' / ' . $total_alunos . '</td>
                <td style="width: 200px;">
                    <div style="background:#e9ecef; border-radius:3px;">
                        <div style="width:' . $percentual . '%; background:' . $cor . '; height:6px; border-radius:3px;"></div>
                    </div>
                    <small>' . $percentual . '%</small>
                </td>
            </tr>';
    }
    
    $html_relatorio .= '
            </tbody>
        </table>
        
        <h5>👥 Desempenho por Gênero</h5>
        <table class="table-desempenho">
            <thead>
                <tr><th>Gênero</th><th>Total Alunos</th><th>Aprovados</th><th>Recuperação</th><th>Reprovados</th><th>Média Geral</th><th>Taxa Aprovação</th></tr>
            </thead>
            <tbody>';
    
    foreach ($genero_estatisticas as $key => $data) {
        $genero_nome = $key == 'masculino' ? 'Masculino' : 'Feminino';
        $html_relatorio .= '
            <tr>
                <td><strong>' . $genero_nome . '</strong></td>
                <td>' . $data['total'] . '</td>
                <td style="color:#27ae60;">' . $data['aprovados'] . '</td>
                <td style="color:#f39c12;">' . $data['recuperacao'] . '</td>
                <td style="color:#e74c3c;">' . $data['reprovados'] . '</td>
                <td><strong>' . number_format($data['media'], 1) . '</strong> / ' . $escala_max . '</td>
                <td>' . $data['taxa_aprovacao'] . '%</td>
            </tr>';
    }
    
    $html_relatorio .= '
            </tbody>
        </table>
        
        <h5>🏆 Ranking dos Alunos</h5>
        <table class="table-desempenho">
            <thead>
                <tr><th>Pos</th><th>Aluno</th><th>Gênero</th><th>Matrícula</th><th>Média</th><th>Status</th></tr>
            </thead>
            <tbody>';
    
    foreach ($ranking_alunos as $index => $aluno) {
        $genero_icon = $aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M' ? '♂' : '♀';
        $status_class = '';
        $status_text = '';
        if ($aluno['status'] == 'aprovado') {
            $status_class = 'badge-aprovado';
            $status_text = 'Aprovado';
        } elseif ($aluno['status'] == 'recuperacao') {
            $status_class = 'badge-recuperacao';
            $status_text = 'Recuperação';
        } elseif ($aluno['status'] == 'reprovado') {
            $status_class = 'badge-reprovado';
            $status_text = 'Reprovado';
        } else {
            $status_text = 'Sem nota';
        }
        
        $html_relatorio .= '
            <tr>
                <td><strong>' . ($index + 1) . 'º</strong></td>
                <td class="text-start">' . htmlspecialchars($aluno['nome']) . ' <small>' . $genero_icon . '</small></td>
                <td class="text-start">' . ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M' ? 'Masculino' : 'Feminino') . '</td>
                <td>' . htmlspecialchars($aluno['matricula']) . '</td>
                <td><strong>' . number_format($aluno['media'], 1) . '</strong> / ' . $escala_max . '</td>
                <td><span class="' . $status_class . '">' . $status_text . '</span></td>
            </tr>';
    }
    
    $html_relatorio .= '
            </tbody>
        </table>
        
        <h5>📈 Evolução por Bimestre</h5>
        <table class="table-desempenho">
            <thead>
                <tr><th>Bimestre</th><th>Média da Turma</th><th>Alunos Avaliados</th><th>Evolução</th></tr>
            </thead>
            <tbody>';
    
    $anterior = 0;
    foreach ($evolucao_turma as $bim) {
        $variacao = '';
        $cor_variacao = '';
        if ($anterior > 0) {
            $diff = $bim['media'] - $anterior;
            $variacao = ($diff > 0 ? '▲ +' . number_format($diff, 1) : ($diff < 0 ? '▼ ' . number_format($diff, 1) : '='));
            $cor_variacao = $diff > 0 ? 'color:#27ae60;' : ($diff < 0 ? 'color:#e74c3c;' : 'color:#666;');
        }
        $html_relatorio .= '
            <tr>
                <td><strong>' . $bim['bimestre'] . 'º Bimestre</strong></td>
                <td>' . number_format($bim['media'], 1) . ' / ' . $escala_max . '</td>
                <td>' . $bim['total_alunos'] . ' alunos</td>
                <td style="' . $cor_variacao . '">' . $variacao . '</td>
            </tr>';
        $anterior = $bim['media'];
    }
    
    $html_relatorio .= '
            </tbody>
        </table>
        
        <div class="footer">
            <p>Relatório gerado eletronicamente pelo Sistema de Gestão Escolar (SIGE)</p>
            <p>Data de emissão: ' . date('d/m/Y H:i:s') . '</p>
            <p>Escala de Avaliação: 0 a ' . $escala_max . ' pontos | Mínimo para aprovação: ' . $limite_aprovacao . ' pontos</p>
        </div>
        
        <script>
            window.onload = function() { setTimeout(function() { window.print(); }, 500); };
        </script>
    </body>
    </html>';
    
    echo $html_relatorio;
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Desempenho da Turma - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; }
        .header { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; }
        .btn-voltar { background: rgba(255,255,255,0.2); color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; }
        .btn-pdf { background: #dc3545; color: white; padding: 8px 20px; border-radius: 8px; text-decoration: none; margin-left: 10px; }
        .btn-pdf:hover, .btn-voltar:hover { opacity: 0.9; transform: translateY(-2px); color: white; }
        .card { background: white; border-radius: 12px; margin-bottom: 20px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .card-header { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; padding: 12px 20px; font-weight: bold; }
        .card-body { padding: 20px; }
        .filtros-row { display: flex; gap: 20px; flex-wrap: wrap; align-items: flex-end; }
        .filtro-group { flex: 1; min-width: 180px; }
        .filtro-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 12px; }
        .filtro-select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; }
        .btn-filtrar { background: #27ae60; color: white; padding: 10px 25px; border: none; border-radius: 8px; cursor: pointer; }
        .btn-filtrar:hover { opacity: 0.9; transform: translateY(-2px); }
        
        .stat-card { background: white; border-radius: 16px; padding: 20px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.08); height: 100%; }
        .stat-number { font-size: 32px; font-weight: 800; }
        .stat-label { font-size: 12px; color: #7f8c8d; text-transform: uppercase; }
        .stat-aprovado .stat-number { color: #27ae60; }
        .stat-reprovado .stat-number { color: #e74c3c; }
        .stat-recuperacao .stat-number { color: #f39c12; }
        .stat-media .stat-number { color: #1e5799; }
        
        .table-desempenho { width: 100%; border-collapse: collapse; }
        .table-desempenho th { background: #f8f9fa; padding: 12px; text-align: center; border-bottom: 2px solid #1e5799; font-size: 12px; }
        .table-desempenho td { padding: 10px; border-bottom: 1px solid #ecf0f1; text-align: center; vertical-align: middle; }
        .table-desempenho tr:hover { background: #f8f9fa; }
        
        .progress { height: 8px; border-radius: 10px; }
        .badge-aprovado { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 12px; font-size: 11px; }
        .badge-recuperacao { background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 12px; font-size: 11px; }
        .badge-reprovado { background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 12px; font-size: 11px; }
        
        .ranking-pos { font-weight: bold; font-size: 16px; }
        .medalha-ouro { color: #ffd700; }
        .medalha-prata { color: #c0c0c0; }
        .medalha-bronze { color: #cd7f32; }
        
        @media (max-width: 768px) {
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-desempenho { font-size: 11px; }
            .table-desempenho th, .table-desempenho td { padding: 6px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-chart-line"></i> Desempenho da Turma</h1>
            <p>Análise detalhada do desempenho académico da turma</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">← Voltar</a>
            <?php if ($turma_id > 0 && $turma_info): ?>
                <a href="?turma_id=<?php echo $turma_id; ?>&ano_letivo_id=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre_filtro; ?>&imprimir=1" class="btn-pdf" target="_blank">
                    <i class="fas fa-file-pdf"></i> Baixar PDF
                </a>
            <?php endif; ?>
        </div>
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
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($turma_id > 0 && $turma_info): ?>
    
    <!-- Informações da Turma -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Informações da Turma
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Turma:</strong> <?php echo $turma_info['ano']; ?>ª - <?php echo htmlspecialchars($turma_info['nome']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Turno:</strong> <?php echo ucfirst($turma_info['turno_nome'] ?? 'Não definido'); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Total de Alunos:</strong> <?php echo $total_alunos; ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Bimestre:</strong> <?php echo $bimestre_filtro; ?>º Bimestre
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card stat-aprovado">
                <div class="stat-number"><?php echo $total_aprovados; ?></div>
                <div class="stat-label">Aprovados</div>
                <small><?php echo $taxa_aprovacao; ?>% da turma</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-recuperacao">
                <div class="stat-number"><?php echo $total_recuperacao; ?></div>
                <div class="stat-label">Recuperação</div>
                <small><?php echo $taxa_recuperacao; ?>% da turma</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-reprovado">
                <div class="stat-number"><?php echo $total_reprovados; ?></div>
                <div class="stat-label">Reprovados</div>
                <small><?php echo $taxa_reprovacao; ?>% da turma</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-media">
                <div class="stat-number"><?php echo $media_geral_turma; ?></div>
                <div class="stat-label">Média Geral</div>
                <small>Escala 0-<?php echo $escala_max; ?></small>
            </div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie"></i> Distribuição por Status
                </div>
                <div class="card-body">
                    <canvas id="graficoStatus" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i> Evolução por Bimestre
                </div>
                <div class="card-body">
                    <canvas id="graficoEvolucao" style="max-height: 300px;"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Desempenho por Gênero -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-venus-mars"></i> Desempenho por Gênero
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-desempenho">
                    <thead>
                        <tr>
                            <th>Gênero</th>
                            <th>Total</th>
                            <th>Aprovados</th>
                            <th>Recuperação</th>
                            <th>Reprovados</th>
                            <th>Média Geral</th>
                            <th>Taxa Aprovação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($genero_estatisticas as $key => $data): 
                            $genero_nome = $key == 'masculino' ? 'Masculino' : 'Feminino';
                            $genero_icon = $key == 'masculino' ? '♂' : '♀';
                        ?>
                            <tr>
                                <td><strong><?php echo $genero_icon . ' ' . $genero_nome; ?></strong></td>
                                <td><?php echo $data['total']; ?></td>
                                <td class="text-success"><?php echo $data['aprovados']; ?> (<?php echo $data['total'] > 0 ? round(($data['aprovados'] / $data['total']) * 100, 1) : 0; ?>%)</td>
                                <td class="text-warning"><?php echo $data['recuperacao']; ?> (<?php echo $data['total'] > 0 ? round(($data['recuperacao'] / $data['total']) * 100, 1) : 0; ?>%)</td>
                                <td class="text-danger"><?php echo $data['reprovados']; ?> (<?php echo $data['total'] > 0 ? round(($data['reprovados'] / $data['total']) * 100, 1) : 0; ?>%)</td>
                                <td><strong><?php echo number_format($data['media'], 1); ?></strong> / <?php echo $escala_max; ?></td>
                                <td><?php echo $data['taxa_aprovacao']; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Desempenho por Disciplina -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-book"></i> Desempenho por Disciplina - <?php echo $bimestre_filtro; ?>º Bimestre
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-desempenho">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Disciplina</th>
                            <th>Código</th>
                            <th>Média da Turma</th>
                            <th>Alunos Avaliados</th>
                            <th>Desempenho</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($desempenho_disciplinas as $index => $disc): 
                            $percentual = $escala_max > 0 ? round(($disc['media'] / $escala_max) * 100, 1) : 0;
                            $barra_cor = $percentual >= 75 ? 'success' : ($percentual >= 50 ? 'warning' : 'danger');
                        ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo htmlspecialchars($disc['nome']); ?></strong></td>
                                <td><?php echo htmlspecialchars($disc['codigo']); ?></td>
                                <td><span class="fw-bold"><?php echo number_format($disc['media'], 1); ?></span> / <?php echo $escala_max; ?></td>
                                <td><?php echo $disc['total_alunos']; ?> / <?php echo $total_alunos; ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span><?php echo $percentual; ?>%</span>
                                        <div class="progress flex-grow-1">
                                            <div class="progress-bar bg-<?php echo $barra_cor; ?>" style="width: <?php echo $percentual; ?>%"></div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Ranking dos Alunos -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-trophy"></i> Ranking dos Alunos - <?php echo $bimestre_filtro; ?>º Bimestre
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-desempenho">
                    <thead>
                        <tr>
                            <th>Pos</th>
                            <th>Aluno</th>
                            <th>Gênero</th>
                            <th>Matrícula</th>
                            <th>Média Geral</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ranking_alunos as $index => $aluno): 
                            $medalha = '';
                            $posicao = $index + 1;
                            if ($posicao == 1) $medalha = '<i class="fas fa-crown medalha-ouro"></i> ';
                            elseif ($posicao == 2) $medalha = '<i class="fas fa-medal medalha-prata"></i> ';
                            elseif ($posicao == 3) $medalha = '<i class="fas fa-medal medalha-bronze"></i> ';
                            
                            $genero_icon = ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M') ? '♂' : '♀';
                            $genero_texto = ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M') ? 'Masculino' : 'Feminino';
                            
                            $status_class = '';
                            $status_text = '';
                            if ($aluno['status'] == 'aprovado') {
                                $status_class = 'badge-aprovado';
                                $status_text = 'Aprovado';
                            } elseif ($aluno['status'] == 'recuperacao') {
                                $status_class = 'badge-recuperacao';
                                $status_text = 'Recuperação';
                            } elseif ($aluno['status'] == 'reprovado') {
                                $status_class = 'badge-reprovado';
                                $status_text = 'Reprovado';
                            } else {
                                $status_text = 'Sem nota';
                            }
                        ?>
                            <tr>
                                <td><span class="ranking-pos"><?php echo $medalha . $posicao; ?>º</span></td>
                                <td class="text-start"><strong><?php echo htmlspecialchars($aluno['nome']); ?></strong> <small><?php echo $genero_icon; ?></small></td>
                                <td class="text-start"><?php echo $genero_texto; ?></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><span class="fw-bold"><?php echo number_format($aluno['media'], 1); ?></span> / <?php echo $escala_max; ?></td>
                                <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($turma_id > 0 && !$turma_info): ?>
        <div class="alert alert-info">Nenhuma informação encontrada para esta turma.</div>
    <?php endif; ?>
</div>

<script>
    <?php if ($turma_id > 0 && $turma_info): ?>
    // Gráfico de Status
    const ctxStatus = document.getElementById('graficoStatus')?.getContext('2d');
    if (ctxStatus) {
        new Chart(ctxStatus, {
            type: 'doughnut',
            data: {
                labels: ['Aprovados', 'Recuperação', 'Reprovados'],
                datasets: [{
                    data: [<?php echo $total_aprovados; ?>, <?php echo $total_recuperacao; ?>, <?php echo $total_reprovados; ?>],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }
    
    // Gráfico de Evolução (apenas 3 bimestres)
    const ctxEvolucao = document.getElementById('graficoEvolucao')?.getContext('2d');
    if (ctxEvolucao) {
        new Chart(ctxEvolucao, {
            type: 'line',
            data: {
                labels: ['1º Bimestre', '2º Bimestre', '3º Bimestre'],
                datasets: [{
                    label: 'Média da Turma',
                    data: [<?php echo $evolucao_turma[0]['media'] ?? 0; ?>, <?php echo $evolucao_turma[1]['media'] ?? 0; ?>, <?php echo $evolucao_turma[2]['media'] ?? 0; ?>],
                    borderColor: '#1e5799',
                    backgroundColor: 'rgba(30, 87, 153, 0.1)',
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#1e5799',
                    pointBorderColor: '#fff',
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { position: 'top' }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: <?php echo $escala_max; ?>,
                        title: { display: true, text: 'Média (0-<?php echo $escala_max; ?>)' }
                    }
                }
            }
        });
    }
    <?php endif; ?>
    
    // Auto-submit ao selecionar turma
    document.querySelector('select[name="turma_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>