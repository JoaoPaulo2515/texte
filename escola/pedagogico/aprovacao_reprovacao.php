<?php
// escola/pedagogico/aprovacao_reprovacao.php - Análise de Aprovação e Reprodução

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

// DISCIPLINAS
$sql_disciplinas = "SELECT id, nome, codigo FROM disciplinas ORDER BY nome ASC";
$stmt_disciplinas = $conn->prepare($sql_disciplinas);
$stmt_disciplinas->execute();
$disciplinas_lista = $stmt_disciplinas->fetchAll(PDO::FETCH_ASSOC);

// PARÂMETROS DE FILTRO
$turma_id = isset($_GET['turma_id']) ? (int)$_GET['turma_id'] : 0;
$ano_letivo_id = isset($_GET['ano_letivo_id']) ? (int)$_GET['ano_letivo_id'] : 0;
$disciplina_id = isset($_GET['disciplina_id']) ? (int)$_GET['disciplina_id'] : 0;
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
$tipo_relatorio = isset($_GET['tipo']) ? $_GET['tipo'] : 'geral';
$imprimir = isset($_GET['imprimir']) ? (int)$_GET['imprimir'] : 0;

if ($ano_letivo_id == 0 && !empty($anos_letivos)) {
    $ano_letivo_id = $anos_letivos[0]['id'];
}

// Função para calcular média final da disciplina
function calcMediaFinal($mac, $npt, $exame_normal, $exame_recurso, $exame_especial, $exame_oral, $exame_escrito, $bimestre, $is_exame, $is_lingua) {
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
// BUSCAR DADOS PARA ANÁLISE
// ============================================
$ano_letivo_ano = '';
$dados_analise = [];
$estatisticas_gerais = [
    'total_alunos' => 0,
    'total_aprovados' => 0,
    'total_recuperacao' => 0,
    'total_reprovados' => 0,
    'taxa_aprovacao' => 0,
    'taxa_recuperacao' => 0,
    'taxa_reprovacao' => 0
];

// Estatísticas por gênero
$genero_estatisticas = [
    'masculino' => ['total' => 0, 'aprovados' => 0, 'recuperacao' => 0, 'reprovados' => 0, 'soma_medias' => 0, 'alunos_com_nota' => 0],
    'feminino' => ['total' => 0, 'aprovados' => 0, 'recuperacao' => 0, 'reprovados' => 0, 'soma_medias' => 0, 'alunos_com_nota' => 0]
];

// Dados para gráficos
$dados_graficos = [
    'aprovacao_por_turma' => [],
    'media_por_turma' => [],
    'aprovacao_por_ano' => [],
    'distribuicao_notas' => []
];

if ($ano_letivo_id > 0) {
    // Buscar ano letivo
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
}

// ============================================
// RELATÓRIO GERAL DA ESCOLA
// ============================================
if ($tipo_relatorio == 'geral' && $ano_letivo_id > 0) {
    $sql_turmas_escola = "
        SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE t.escola_id = :escola_id AND t.status = 'ativa'
        ORDER BY t.ano ASC, t.nome ASC
    ";
    $stmt_turmas_escola = $conn->prepare($sql_turmas_escola);
    $stmt_turmas_escola->execute([':escola_id' => $escola_id]);
    $turmas_escola = $stmt_turmas_escola->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($turmas_escola as $turma) {
        $classe_ano = $turma['ano'];
        $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
        $escala_max = ($classe_ano <= 6) ? 10 : 20;
        $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
        
        // Buscar alunos da turma com gênero
        $sql_alunos_turma = "
            SELECT e.id, e.nome, e.matricula, e.genero
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
        ";
        $stmt_alunos_turma = $conn->prepare($sql_alunos_turma);
        $stmt_alunos_turma->execute([':turma_id' => $turma['id'], ':ano_letivo' => $ano_letivo_id]);
        $alunos_turma = $stmt_alunos_turma->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar disciplinas da turma
        $sql_disc_turma = "
            SELECT d.id, d.nome
            FROM disciplina_turma dt
            INNER JOIN disciplinas d ON d.id = dt.disciplina_id
            WHERE dt.turma_id = :turma_id
        ";
        $stmt_disc_turma = $conn->prepare($sql_disc_turma);
        $stmt_disc_turma->execute([':turma_id' => $turma['id']]);
        $disciplinas_turma = $stmt_disc_turma->fetchAll(PDO::FETCH_ASSOC);
        
        $total_alunos_turma = count($alunos_turma);
        $aprovados_turma = 0;
        $recuperacao_turma = 0;
        $reprovados_turma = 0;
        $soma_medias_turma = 0;
        $count_notas_turma = 0;
        
        // Estatísticas por gênero na turma
        $genero_turma = [
            'masculino' => ['total' => 0, 'aprovados' => 0, 'recuperacao' => 0, 'reprovados' => 0, 'soma_medias' => 0],
            'feminino' => ['total' => 0, 'aprovados' => 0, 'recuperacao' => 0, 'reprovados' => 0, 'soma_medias' => 0]
        ];
        
        foreach ($alunos_turma as $aluno) {
            $genero = strtolower($aluno['genero'] ?? '');
            $genero_key = ($genero == 'masculino' || $genero == 'm' || $genero == 'male') ? 'masculino' : (($genero == 'feminino' || $genero == 'f' || $genero == 'female') ? 'feminino' : null);
            
            if ($genero_key) {
                $genero_turma[$genero_key]['total']++;
                $genero_estatisticas[$genero_key]['total']++;
            }
            
            $soma_notas = 0;
            $count_notas = 0;
            
            foreach ($disciplinas_turma as $disc) {
                $sql_nota = "
                    SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito
                    FROM notas
                    WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
                ";
                $stmt_nota = $conn->prepare($sql_nota);
                $stmt_nota->execute([
                    ':aluno_id' => $aluno['id'],
                    ':disc_id' => $disc['id'],
                    ':bimestre' => $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                    ':ano_letivo' => $ano_letivo_id
                ]);
                $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
                
                if ($nota) {
                    $is_lingua = (stripos($disc['nome'], 'português') !== false || stripos($disc['nome'], 'inglês') !== false);
                    $media = calcMediaFinal(
                        $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                        $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                        $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                        $nota['exame_escrito'] ?? 0,
                        $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                        $is_classe_exame, $is_lingua
                    );
                    if ($media > 0) {
                        $soma_notas += $media;
                        $count_notas++;
                    }
                }
            }
            
            if ($count_notas > 0) {
                $media_geral = $soma_notas / $count_notas;
                $soma_medias_turma += $media_geral;
                $count_notas_turma++;
                
                if ($genero_key) {
                    $genero_turma[$genero_key]['soma_medias'] += $media_geral;
                }
                
                if ($media_geral >= $limite_aprovacao) {
                    $aprovados_turma++;
                    if ($genero_key) {
                        $genero_turma[$genero_key]['aprovados']++;
                        $genero_estatisticas[$genero_key]['aprovados']++;
                    }
                } elseif ($media_geral >= $limite_aprovacao * 0.7) {
                    $recuperacao_turma++;
                    if ($genero_key) {
                        $genero_turma[$genero_key]['recuperacao']++;
                        $genero_estatisticas[$genero_key]['recuperacao']++;
                    }
                } else {
                    $reprovados_turma++;
                    if ($genero_key) {
                        $genero_turma[$genero_key]['reprovados']++;
                        $genero_estatisticas[$genero_key]['reprovados']++;
                    }
                }
            }
        }
        
        $media_turma = $count_notas_turma > 0 ? round($soma_medias_turma / $count_notas_turma, 1) : 0;
        
        $dados_analise[] = [
            'turma_id' => $turma['id'],
            'turma_nome' => $turma['nome'],
            'turma_ano' => $turma['ano'],
            'turno' => $turma['turno_nome'],
            'total_alunos' => $total_alunos_turma,
            'aprovados' => $aprovados_turma,
            'recuperacao' => $recuperacao_turma,
            'reprovados' => $reprovados_turma,
            'media_turma' => $media_turma,
            'taxa_aprovacao' => $total_alunos_turma > 0 ? round(($aprovados_turma / $total_alunos_turma) * 100, 1) : 0,
            'taxa_recuperacao' => $total_alunos_turma > 0 ? round(($recuperacao_turma / $total_alunos_turma) * 100, 1) : 0,
            'taxa_reprovacao' => $total_alunos_turma > 0 ? round(($reprovados_turma / $total_alunos_turma) * 100, 1) : 0,
            'genero' => $genero_turma
        ];
        
        // Dados para gráficos
        $dados_graficos['aprovacao_por_turma'][] = [
            'nome' => $turma['ano'] . 'ª ' . $turma['nome'],
            'taxa' => $total_alunos_turma > 0 ? round(($aprovados_turma / $total_alunos_turma) * 100, 1) : 0
        ];
        $dados_graficos['media_por_turma'][] = [
            'nome' => $turma['ano'] . 'ª ' . $turma['nome'],
            'media' => $media_turma
        ];
        
        $estatisticas_gerais['total_alunos'] += $total_alunos_turma;
        $estatisticas_gerais['total_aprovados'] += $aprovados_turma;
        $estatisticas_gerais['total_recuperacao'] += $recuperacao_turma;
        $estatisticas_gerais['total_reprovados'] += $reprovados_turma;
    }
    
    // Calcular médias por gênero
    foreach ($genero_estatisticas as $key => $data) {
        if ($data['alunos_com_nota'] > 0) {
            $genero_estatisticas[$key]['media'] = round($data['soma_medias'] / $data['alunos_com_nota'], 1);
        } else {
            $genero_estatisticas[$key]['media'] = 0;
        }
        $genero_estatisticas[$key]['taxa_aprovacao'] = $data['total'] > 0 ? round(($data['aprovados'] / $data['total']) * 100, 1) : 0;
        $genero_estatisticas[$key]['taxa_recuperacao'] = $data['total'] > 0 ? round(($data['recuperacao'] / $data['total']) * 100, 1) : 0;
        $genero_estatisticas[$key]['taxa_reprovacao'] = $data['total'] > 0 ? round(($data['reprovados'] / $data['total']) * 100, 1) : 0;
    }
    
    $estatisticas_gerais['taxa_aprovacao'] = $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_aprovados'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
    $estatisticas_gerais['taxa_recuperacao'] = $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_recuperacao'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
    $estatisticas_gerais['taxa_reprovacao'] = $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_reprovados'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
}

// ============================================
// RELATÓRIO POR TURMA
// ============================================
if ($tipo_relatorio == 'turma' && $turma_id > 0 && $ano_letivo_id > 0) {
    // Buscar informações da turma
    $sql_turma = "
        SELECT t.id, t.nome, t.ano, tr.nome as turno_nome
        FROM turmas t
        LEFT JOIN turnos tr ON tr.id = t.turno_id
        WHERE t.id = :turma_id
    ";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':turma_id' => $turma_id]);
    $turma_info = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    if ($turma_info) {
        $classe_ano = $turma_info['ano'];
        $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
        $escala_max = ($classe_ano <= 6) ? 10 : 20;
        $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
        
        // Buscar alunos da turma
        $sql_alunos_turma = "
            SELECT e.id, e.nome, e.matricula, e.genero
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
            ORDER BY e.nome ASC
        ";
        $stmt_alunos_turma = $conn->prepare($sql_alunos_turma);
        $stmt_alunos_turma->execute([':turma_id' => $turma_id, ':ano_letivo' => $ano_letivo_id]);
        $alunos_turma = $stmt_alunos_turma->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar disciplinas da turma
        $sql_disc_turma = "
            SELECT d.id, d.nome, d.codigo,
                   CASE WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' THEN 1 ELSE 0 END as is_lingua
            FROM disciplina_turma dt
            INNER JOIN disciplinas d ON d.id = dt.disciplina_id
            WHERE dt.turma_id = :turma_id
            ORDER BY d.nome ASC
        ";
        $stmt_disc_turma = $conn->prepare($sql_disc_turma);
        $stmt_disc_turma->execute([':turma_id' => $turma_id]);
        $disciplinas_turma = $stmt_disc_turma->fetchAll(PDO::FETCH_ASSOC);
        
        $total_alunos = count($alunos_turma);
        $distribuicao_notas = array_fill(0, $escala_max + 1, 0);
        $aprovados_por_disciplina = [];
        $medias_por_bimestre = [1 => 0, 2 => 0, 3 => 0];
        
        foreach ($disciplinas_turma as $disc) {
            $aprovados_por_disciplina[$disc['id']] = ['nome' => $disc['nome'], 'aprovados' => 0, 'total' => 0];
        }
        
        foreach ($alunos_turma as $aluno) {
            $genero = strtolower($aluno['genero'] ?? '');
            $genero_key = ($genero == 'masculino' || $genero == 'm' || $genero == 'male') ? 'masculino' : (($genero == 'feminino' || $genero == 'f' || $genero == 'female') ? 'feminino' : null);
            
            if ($genero_key) {
                $genero_estatisticas[$genero_key]['total']++;
            }
            
            $soma_notas = 0;
            $count_notas = 0;
            $disciplinas_aluno = [];
            
            foreach ($disciplinas_turma as $disc) {
                $sql_nota = "
                    SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito
                    FROM notas
                    WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
                ";
                $stmt_nota = $conn->prepare($sql_nota);
                $stmt_nota->execute([
                    ':aluno_id' => $aluno['id'],
                    ':disc_id' => $disc['id'],
                    ':bimestre' => $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                    ':ano_letivo' => $ano_letivo_id
                ]);
                $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
                
                if ($nota) {
                    $media = calcMediaFinal(
                        $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                        $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                        $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                        $nota['exame_escrito'] ?? 0,
                        $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                        $is_classe_exame, $disc['is_lingua']
                    );
                    if ($media > 0) {
                        $soma_notas += $media;
                        $count_notas++;
                        $aprovados_por_disciplina[$disc['id']]['total']++;
                        if ($media >= $limite_aprovacao) {
                            $aprovados_por_disciplina[$disc['id']]['aprovados']++;
                        }
                        $nota_int = (int)round($media);
                        if ($nota_int >= 0 && $nota_int <= $escala_max) {
                            $distribuicao_notas[$nota_int]++;
                        }
                        $disciplinas_aluno[] = [
                            'nome' => $disc['nome'],
                            'media' => $media,
                            'status' => $media >= $limite_aprovacao ? 'Aprovado' : ($media >= $limite_aprovacao * 0.7 ? 'Recuperação' : 'Reprovado')
                        ];
                    } else {
                        $disciplinas_aluno[] = ['nome' => $disc['nome'], 'media' => '-', 'status' => 'Sem nota'];
                    }
                } else {
                    $disciplinas_aluno[] = ['nome' => $disc['nome'], 'media' => '-', 'status' => 'Sem nota'];
                }
            }
            
            // Calcular médias por bimestre
            for ($bim = 1; $bim <= 3; $bim++) {
                $soma_bim = 0;
                $count_bim = 0;
                foreach ($disciplinas_turma as $disc) {
                    $sql_nota_bim = "
                        SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito
                        FROM notas
                        WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
                    ";
                    $stmt_nota_bim = $conn->prepare($sql_nota_bim);
                    $stmt_nota_bim->execute([
                        ':aluno_id' => $aluno['id'],
                        ':disc_id' => $disc['id'],
                        ':bimestre' => $bim,
                        ':ano_letivo' => $ano_letivo_id
                    ]);
                    $nota_bim = $stmt_nota_bim->fetch(PDO::FETCH_ASSOC);
                    if ($nota_bim) {
                        $media_bim = calcMediaFinal(
                            $nota_bim['mac'] ?? 0, $nota_bim['npt'] ?? 0,
                            $nota_bim['exame_normal'] ?? 0, $nota_bim['exame_recurso'] ?? 0,
                            $nota_bim['exame_especial'] ?? 0, $nota_bim['exame_oral'] ?? 0,
                            $nota_bim['exame_escrito'] ?? 0,
                            $bim, $is_classe_exame, $disc['is_lingua']
                        );
                        if ($media_bim > 0) {
                            $soma_bim += $media_bim;
                            $count_bim++;
                        }
                    }
                }
                if ($count_bim > 0) {
                    $medias_por_bimestre[$bim] += $soma_bim / $count_bim;
                }
            }
            
            $media_geral = $count_notas > 0 ? round($soma_notas / $count_notas, 1) : 0;
            if ($genero_key) {
                $genero_estatisticas[$genero_key]['soma_medias'] += $media_geral;
                $genero_estatisticas[$genero_key]['alunos_com_nota']++;
            }
            
            if ($media_geral >= $limite_aprovacao) {
                $status_geral = 'Aprovado';
                $estatisticas_gerais['total_aprovados']++;
                if ($genero_key) $genero_estatisticas[$genero_key]['aprovados']++;
            } elseif ($media_geral >= $limite_aprovacao * 0.7) {
                $status_geral = 'Recuperação';
                $estatisticas_gerais['total_recuperacao']++;
                if ($genero_key) $genero_estatisticas[$genero_key]['recuperacao']++;
            } elseif ($media_geral > 0) {
                $status_geral = 'Reprovado';
                $estatisticas_gerais['total_reprovados']++;
                if ($genero_key) $genero_estatisticas[$genero_key]['reprovados']++;
            } else {
                $status_geral = 'Sem nota';
            }
            
            $dados_analise[] = [
                'aluno_id' => $aluno['id'],
                'aluno_nome' => $aluno['nome'],
                'matricula' => $aluno['matricula'],
                'genero' => $aluno['genero'],
                'media_geral' => $media_geral,
                'status_geral' => $status_geral,
                'disciplinas' => $disciplinas_aluno
            ];
        }
        
        // Média das médias por bimestre
        $total_alunos_bim = count($alunos_turma);
        foreach ($medias_por_bimestre as $bim => $soma) {
            $medias_por_bimestre[$bim] = $total_alunos_bim > 0 ? round($soma / $total_alunos_bim, 1) : 0;
        }
        
        // Calcular taxas de aprovação por disciplina
        $aprovacao_disciplinas = [];
        foreach ($aprovados_por_disciplina as $disc) {
            $taxa = $disc['total'] > 0 ? round(($disc['aprovados'] / $disc['total']) * 100, 1) : 0;
            $aprovacao_disciplinas[] = [
                'nome' => $disc['nome'],
                'taxa' => $taxa,
                'aprovados' => $disc['aprovados'],
                'total' => $disc['total']
            ];
        }
        usort($aprovacao_disciplinas, function($a, $b) {
            return $b['taxa'] <=> $a['taxa'];
        });
        
        $estatisticas_gerais['total_alunos'] = $total_alunos;
        $estatisticas_gerais['taxa_aprovacao'] = $total_alunos > 0 ? round(($estatisticas_gerais['total_aprovados'] / $total_alunos) * 100, 1) : 0;
        $estatisticas_gerais['taxa_recuperacao'] = $total_alunos > 0 ? round(($estatisticas_gerais['total_recuperacao'] / $total_alunos) * 100, 1) : 0;
        $estatisticas_gerais['taxa_reprovacao'] = $total_alunos > 0 ? round(($estatisticas_gerais['total_reprovados'] / $total_alunos) * 100, 1) : 0;
        
        // Calcular médias por gênero
        foreach ($genero_estatisticas as $key => $data) {
            if ($data['alunos_com_nota'] > 0) {
                $genero_estatisticas[$key]['media'] = round($data['soma_medias'] / $data['alunos_com_nota'], 1);
            } else {
                $genero_estatisticas[$key]['media'] = 0;
            }
            $genero_estatisticas[$key]['taxa_aprovacao'] = $data['total'] > 0 ? round(($data['aprovados'] / $data['total']) * 100, 1) : 0;
        }
        
        usort($dados_analise, function($a, $b) {
            return $b['media_geral'] <=> $a['media_geral'];
        });
        
        $dados_graficos['distribuicao_notas'] = $distribuicao_notas;
        $dados_graficos['aprovacao_disciplinas'] = $aprovacao_disciplinas;
        $dados_graficos['medias_por_bimestre'] = $medias_por_bimestre;
    }
}

// ============================================
// RELATÓRIO POR DISCIPLINA
// ============================================
if ($tipo_relatorio == 'disciplina' && $disciplina_id > 0 && $ano_letivo_id > 0) {
    // Buscar informações da disciplina
    $sql_disciplina = "SELECT id, nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disciplina = $conn->prepare($sql_disciplina);
    $stmt_disciplina->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disciplina->fetch(PDO::FETCH_ASSOC);
    
    if ($disciplina_info) {
        $is_lingua = (stripos($disciplina_info['nome'], 'português') !== false || stripos($disciplina_info['nome'], 'inglês') !== false);
        
        // Buscar turmas que têm esta disciplina
        $sql_turmas_disc = "
            SELECT DISTINCT t.id, t.nome, t.ano, tr.nome as turno_nome
            FROM turmas t
            INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
            LEFT JOIN turnos tr ON tr.id = t.turno_id
            WHERE dt.disciplina_id = :disciplina_id AND t.escola_id = :escola_id AND t.status = 'ativa'
            ORDER BY t.ano ASC, t.nome ASC
        ";
        $stmt_turmas_disc = $conn->prepare($sql_turmas_disc);
        $stmt_turmas_disc->execute([':disciplina_id' => $disciplina_id, ':escola_id' => $escola_id]);
        $turmas_disc = $stmt_turmas_disc->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($turmas_disc as $turma) {
            $classe_ano = $turma['ano'];
            $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
            $escala_max = ($classe_ano <= 6) ? 10 : 20;
            $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
            
            // Buscar alunos da turma
            $sql_alunos_turma = "
                SELECT e.id, e.nome, e.matricula, e.genero
                FROM matriculas m
                INNER JOIN estudantes e ON e.id = m.estudante_id
                WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
            ";
            $stmt_alunos_turma = $conn->prepare($sql_alunos_turma);
            $stmt_alunos_turma->execute([':turma_id' => $turma['id'], ':ano_letivo' => $ano_letivo_id]);
            $alunos_turma = $stmt_alunos_turma->fetchAll(PDO::FETCH_ASSOC);
            
            $total_alunos_turma = count($alunos_turma);
            $aprovados_turma = 0;
            $recuperacao_turma = 0;
            $reprovados_turma = 0;
            $soma_notas_turma = 0;
            $count_notas_turma = 0;
            $distribuicao_turma = array_fill(0, $escala_max + 1, 0);
            
            foreach ($alunos_turma as $aluno) {
                $genero = strtolower($aluno['genero'] ?? '');
                $genero_key = ($genero == 'masculino' || $genero == 'm' || $genero == 'male') ? 'masculino' : (($genero == 'feminino' || $genero == 'f' || $genero == 'female') ? 'feminino' : null);
                
                if ($genero_key) {
                    $genero_estatisticas[$genero_key]['total']++;
                }
                
                $sql_nota = "
                    SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito
                    FROM notas
                    WHERE estudante_id = :aluno_id AND disciplina_id = :disciplina_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo
                ";
                $stmt_nota = $conn->prepare($sql_nota);
                $stmt_nota->execute([
                    ':aluno_id' => $aluno['id'],
                    ':disciplina_id' => $disciplina_id,
                    ':bimestre' => $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                    ':ano_letivo' => $ano_letivo_id
                ]);
                $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
                
                if ($nota) {
                    $media = calcMediaFinal(
                        $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                        $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                        $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                        $nota['exame_escrito'] ?? 0,
                        $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                        $is_classe_exame, $is_lingua
                    );
                    if ($media > 0) {
                        $soma_notas_turma += $media;
                        $count_notas_turma++;
                        
                        if ($genero_key) {
                            $genero_estatisticas[$genero_key]['soma_medias'] += $media;
                            $genero_estatisticas[$genero_key]['alunos_com_nota']++;
                        }
                        
                        $nota_int = (int)round($media);
                        if ($nota_int >= 0 && $nota_int <= $escala_max) {
                            $distribuicao_turma[$nota_int]++;
                        }
                        
                        if ($media >= $limite_aprovacao) {
                            $aprovados_turma++;
                            if ($genero_key) $genero_estatisticas[$genero_key]['aprovados']++;
                        } elseif ($media >= $limite_aprovacao * 0.7) {
                            $recuperacao_turma++;
                            if ($genero_key) $genero_estatisticas[$genero_key]['recuperacao']++;
                        } else {
                            $reprovados_turma++;
                            if ($genero_key) $genero_estatisticas[$genero_key]['reprovados']++;
                        }
                    }
                }
            }
            
            $media_turma = $count_notas_turma > 0 ? round($soma_notas_turma / $count_notas_turma, 1) : 0;
            
            $dados_analise[] = [
                'turma_id' => $turma['id'],
                'turma_nome' => $turma['nome'],
                'turma_ano' => $turma['ano'],
                'turno' => $turma['turno_nome'],
                'total_alunos' => $total_alunos_turma,
                'aprovados' => $aprovados_turma,
                'recuperacao' => $recuperacao_turma,
                'reprovados' => $reprovados_turma,
                'media_turma' => $media_turma,
                'taxa_aprovacao' => $total_alunos_turma > 0 ? round(($aprovados_turma / $total_alunos_turma) * 100, 1) : 0,
                'taxa_recuperacao' => $total_alunos_turma > 0 ? round(($recuperacao_turma / $total_alunos_turma) * 100, 1) : 0,
                'taxa_reprovacao' => $total_alunos_turma > 0 ? round(($reprovados_turma / $total_alunos_turma) * 100, 1) : 0,
                'distribuicao' => $distribuicao_turma
            ];
            
            $estatisticas_gerais['total_alunos'] += $total_alunos_turma;
            $estatisticas_gerais['total_aprovados'] += $aprovados_turma;
            $estatisticas_gerais['total_recuperacao'] += $recuperacao_turma;
            $estatisticas_gerais['total_reprovados'] += $reprovados_turma;
            
            // Acumular distribuição de notas
            foreach ($distribuicao_turma as $nota => $count) {
                if (!isset($dados_graficos['distribuicao_notas'][$nota])) {
                    $dados_graficos['distribuicao_notas'][$nota] = 0;
                }
                $dados_graficos['distribuicao_notas'][$nota] += $count;
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
        
        $estatisticas_gerais['taxa_aprovacao'] = $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_aprovados'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
        $estatisticas_gerais['taxa_recuperacao'] = $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_recuperacao'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
        $estatisticas_gerais['taxa_reprovacao'] = $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_reprovados'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0;
        
        usort($dados_analise, function($a, $b) {
            return $b['taxa_aprovacao'] <=> $a['taxa_aprovacao'];
        });
        
        // Dados para gráficos
        $dados_graficos['aprovacao_por_turma'] = array_map(function($item) {
            return [
                'nome' => $item['turma_ano'] . 'ª ' . $item['turma_nome'],
                'taxa' => $item['taxa_aprovacao']
            ];
        }, $dados_analise);
        $dados_graficos['media_por_turma'] = array_map(function($item) {
            return [
                'nome' => $item['turma_ano'] . 'ª ' . $item['turma_nome'],
                'media' => $item['media_turma']
            ];
        }, $dados_analise);
    }
}

// ============================================
// GERAR HTML PARA IMPRESSÃO/PDF
// ============================================
if ($imprimir == 1 && $tipo_relatorio != 'geral') {
    $html_relatorio = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Relatório de Aprovação e Reprodução</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; font-size: 11px; padding: 20px; }
            .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #1e5799; padding-bottom: 15px; }
            .header h2 { color: #1e5799; margin-bottom: 5px; }
            .stats-grid { display: flex; gap: 15px; margin-bottom: 30px; flex-wrap: wrap; }
            .stat-card { background: #f8f9fa; padding: 15px; border-radius: 8px; text-align: center; flex: 1; min-width: 120px; }
            .stat-number { font-size: 24px; font-weight: bold; }
            .stat-label { font-size: 10px; color: #666; }
            .table-desempenho { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .table-desempenho th { background: #1e5799; color: white; padding: 8px; text-align: center; font-size: 10px; }
            .table-desempenho td { border: 1px solid #ddd; padding: 6px; text-align: center; }
            .table-desempenho td.text-start { text-align: left; }
            .footer { text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #ddd; font-size: 9px; color: #666; }
            .progress { height: 8px; background: #e9ecef; border-radius: 4px; }
            .progress-bar { height: 8px; border-radius: 4px; }
            @media print {
                body { padding: 0; margin: 0; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h2>' . htmlspecialchars($escola['nome']) . '</h2>
            <p>' . htmlspecialchars($escola['endereco'] ?? '') . '</p>
            <h4>RELATÓRIO DE APROVAÇÃO E REPRODUÇÃO</h4>
            <p>Ano Letivo: ' . $ano_letivo_ano . ' | ' . ($bimestre_filtro > 0 ? $bimestre_filtro . 'º Bimestre' : 'Média Final') . '</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number" style="color: #27ae60;">' . $estatisticas_gerais['total_aprovados'] . '</div>
                <div class="stat-label">Aprovados</div>
                <small>' . $estatisticas_gerais['taxa_aprovacao'] . '%</small>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f39c12;">' . $estatisticas_gerais['total_recuperacao'] . '</div>
                <div class="stat-label">Recuperação</div>
                <small>' . $estatisticas_gerais['taxa_recuperacao'] . '%</small>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #e74c3c;">' . $estatisticas_gerais['total_reprovados'] . '</div>
                <div class="stat-label">Reprovados</div>
                <small>' . $estatisticas_gerais['taxa_reprovacao'] . '%</small>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #1e5799;">' . $estatisticas_gerais['total_alunos'] . '</div>
                <div class="stat-label">Total de Alunos</div>
            </div>
        </div>';
    
    if ($tipo_relatorio == 'turma') {
        $html_relatorio .= '
        <h5>📊 Ranking dos Alunos</h5>
        <table class="table-desempenho">
            <thead><tr><th>#</th><th>Aluno</th><th>Matrícula</th><th>Média</th><th>Status</th></tr></thead>
            <tbody>';
        foreach ($dados_analise as $index => $aluno) {
            $status_class = $aluno['status_geral'] == 'Aprovado' ? 'color:#27ae60;' : ($aluno['status_geral'] == 'Recuperação' ? 'color:#f39c12;' : 'color:#e74c3c;');
            $html_relatorio .= '
                <tr>
                    <td><strong>' . ($index + 1) . 'º</strong></td>
                    <td class="text-start">' . htmlspecialchars($aluno['aluno_nome']) . '</td>
                    <td>' . htmlspecialchars($aluno['matricula']) . '</td>
                    <td><strong>' . number_format($aluno['media_geral'], 1) . '</strong></td>
                    <td style="' . $status_class . '; font-weight:bold;">' . $aluno['status_geral'] . '</td>
                </tr>';
        }
        $html_relatorio .= '</tbody></table>';
    } elseif ($tipo_relatorio == 'disciplina') {
        $html_relatorio .= '
        <h5>📊 Desempenho por Turma</h5>
        <table class="table-desempenho">
            <thead><tr><th>#</th><th>Turma</th><th>Alunos</th><th>Aprovados</th><th>Recuperação</th><th>Reprovados</th><th>Média</th><th>Taxa Aprovação</th></tr></thead>
            <tbody>';
        foreach ($dados_analise as $index => $turma) {
            $percentual = $turma['taxa_aprovacao'];
            $cor = $percentual >= 75 ? '#28a745' : ($percentual >= 50 ? '#ffc107' : '#dc3545');
            $html_relatorio .= '
                <tr>
                    <td><strong>' . ($index + 1) . 'º</strong></td>
                    <td class="text-start"><strong>' . $turma['turma_ano'] . 'ª - ' . htmlspecialchars($turma['turma_nome']) . '</strong></td>
                    <td>' . $turma['total_alunos'] . '</td>
                    <td style="color:#27ae60;">' . $turma['aprovados'] . '</td>
                    <td style="color:#f39c12;">' . $turma['recuperacao'] . '</td>
                    <td style="color:#e74c3c;">' . $turma['reprovados'] . '</td>
                    <td><strong>' . number_format($turma['media_turma'], 1) . '</strong></td>
                    <td>
                        <div style="width:100px; display:inline-block;">
                            <div style="background:#e9ecef; border-radius:3px;">
                                <div style="width:' . $percentual . '%; background:' . $cor . '; height:6px; border-radius:3px;"></div>
                            </div>
                            <small>' . $percentual . '%</small>
                        </div>
                    </td>
                </tr>';
        }
        $html_relatorio .= '</tbody></table>';
    }
    
    $html_relatorio .= '
        <div class="footer">
            <p>Relatório gerado eletronicamente pelo Sistema de Gestão Escolar (SIGE)</p>
            <p>Data de emissão: ' . date('d/m/Y H:i:s') . '</p>
        </div>
        <script>window.onload = function() { setTimeout(function() { window.print(); }, 500); };<\/script>
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
    <title>Análise de Aprovação e Reprodução - SIGE Angola</title>
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
        
        .chart-container { position: relative; height: 300px; margin-bottom: 20px; }
        
        @media (max-width: 768px) {
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-desempenho { font-size: 11px; }
            .table-desempenho th, .table-desempenho td { padding: 6px; }
            .chart-container { height: 250px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-chart-line"></i> Análise de Aprovação e Reprodução</h1>
            <p>Análise detalhada de aprovação, recuperação e reprovação com gráficos interativos</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">← Voltar</a>
            <?php if (($tipo_relatorio == 'turma' && $turma_id > 0) || ($tipo_relatorio == 'disciplina' && $disciplina_id > 0)): ?>
                <a href="?tipo=<?php echo $tipo_relatorio; ?>&turma_id=<?php echo $turma_id; ?>&disciplina_id=<?php echo $disciplina_id; ?>&ano_letivo_id=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre_filtro; ?>&imprimir=1" class="btn-pdf" target="_blank">
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
                        <label>Tipo de Relatório</label>
                        <select name="tipo" class="filtro-select" onchange="this.form.submit()">
                            <option value="geral" <?php echo ($tipo_relatorio == 'geral') ? 'selected' : ''; ?>>Geral da Escola</option>
                            <option value="turma" <?php echo ($tipo_relatorio == 'turma') ? 'selected' : ''; ?>>Por Turma</option>
                            <option value="disciplina" <?php echo ($tipo_relatorio == 'disciplina') ? 'selected' : ''; ?>>Por Disciplina</option>
                        </select>
                    </div>
                    
                    <?php if ($tipo_relatorio == 'turma'): ?>
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
                    <?php endif; ?>
                    
                    <?php if ($tipo_relatorio == 'disciplina'): ?>
                    <div class="filtro-group">
                        <label>Disciplina</label>
                        <select name="disciplina_id" class="filtro-select">
                            <option value="">Selecione</option>
                            <?php foreach ($disciplinas_lista as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo ($disciplina_id == $d['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['nome']); ?> (<?php echo htmlspecialchars($d['codigo']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="filtro-group">
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="0">Média Final</option>
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
    
    <?php if ($tipo_relatorio == 'geral' && $ano_letivo_id > 0): ?>
    
    <!-- Cards de Estatísticas Gerais -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card stat-aprovado">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_aprovados']; ?></div>
                <div class="stat-label">Aprovados</div>
                <small><?php echo $estatisticas_gerais['taxa_aprovacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-recuperacao">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_recuperacao']; ?></div>
                <div class="stat-label">Recuperação</div>
                <small><?php echo $estatisticas_gerais['taxa_recuperacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-reprovado">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_reprovados']; ?></div>
                <div class="stat-label">Reprovados</div>
                <small><?php echo $estatisticas_gerais['taxa_reprovacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-media">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_alunos']; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie"></i> Distribuição Geral
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoGeral"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-venus-mars"></i> Comparativo por Gênero
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoGenero"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar"></i> Taxa de Aprovação por Turma
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoAprovacaoTurma"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i> Média por Turma
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoMediaTurma"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Estatísticas por Gênero -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-chart-bar"></i> Estatísticas Detalhadas por Gênero
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
                            <th>Média</th>
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
                                <td><strong><?php echo number_format($data['media'], 1); ?></strong></td>
                                <td><?php echo $data['taxa_aprovacao']; ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Detalhamento por Turma -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-building"></i> Detalhamento por Turma
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-desempenho">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Turma</th>
                            <th>Turno</th>
                            <th>Total</th>
                            <th>Aprovados</th>
                            <th>Recuperação</th>
                            <th>Reprovados</th>
                            <th>Média</th>
                            <th>Taxa Aprovação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dados_analise as $index => $turma): 
                            $percentual = $turma['taxa_aprovacao'];
                            $barra_cor = $percentual >= 75 ? 'success' : ($percentual >= 50 ? 'warning' : 'danger');
                        ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo $turma['turma_ano']; ?>ª - <?php echo htmlspecialchars($turma['turma_nome']); ?></strong></td>
                                <td><?php echo ucfirst($turma['turno'] ?? ''); ?></td>
                                <td><?php echo $turma['total_alunos']; ?></td>
                                <td class="text-success"><?php echo $turma['aprovados']; ?> (<?php echo $turma['taxa_aprovacao']; ?>%)</td>
                                <td class="text-warning"><?php echo $turma['recuperacao']; ?> (<?php echo $turma['taxa_recuperacao']; ?>%)</td>
                                <td class="text-danger"><?php echo $turma['reprovados']; ?> (<?php echo $turma['taxa_reprovacao']; ?>%)</td>
                                <td><strong><?php echo number_format($turma['media_turma'], 1); ?></strong> / <?php echo $escala_max; ?></td>
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
    
    <?php elseif ($tipo_relatorio == 'turma' && $turma_id > 0 && !empty($dados_analise)): ?>
    
    <!-- Informações da Turma -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-info-circle"></i> Turma: <?php echo $turma_info['ano']; ?>ª - <?php echo htmlspecialchars($turma_info['nome']); ?>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4"><strong>Turno:</strong> <?php echo ucfirst($turma_info['turno_nome'] ?? 'Não definido'); ?></div>
                        <div class="col-md-4"><strong>Total de Alunos:</strong> <?php echo $total_alunos; ?></div>
                        <div class="col-md-4"><strong>Escala:</strong> 0-<?php echo $escala_max; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card stat-aprovado">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_aprovados']; ?></div>
                <div class="stat-label">Aprovados</div>
                <small><?php echo $estatisticas_gerais['taxa_aprovacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-recuperacao">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_recuperacao']; ?></div>
                <div class="stat-label">Recuperação</div>
                <small><?php echo $estatisticas_gerais['taxa_recuperacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-reprovado">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_reprovados']; ?></div>
                <div class="stat-label">Reprovados</div>
                <small><?php echo $estatisticas_gerais['taxa_reprovacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-media">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_alunos']; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie"></i> Distribuição de Status
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoStatusTurma"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar"></i> Distribuição de Notas
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoDistribuicaoNotas"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i> Evolução das Médias por Bimestre
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoEvolucaoBimestre"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar"></i> Taxa de Aprovação por Disciplina
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoAprovacaoDisciplina"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Estatísticas por Gênero -->
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
                            <th>Média</th>
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
    
    <!-- Ranking dos Alunos -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-trophy"></i> Ranking dos Alunos
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
                            <th>Média</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dados_analise as $index => $aluno): 
                            $medalha = '';
                            $posicao = $index + 1;
                            if ($posicao == 1) $medalha = '<i class="fas fa-crown medalha-ouro"></i> ';
                            elseif ($posicao == 2) $medalha = '<i class="fas fa-medal medalha-prata"></i> ';
                            elseif ($posicao == 3) $medalha = '<i class="fas fa-medal medalha-bronze"></i> ';
                            
                            $genero_icon = ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M') ? '♂' : '♀';
                            $status_class = '';
                            if ($aluno['status_geral'] == 'Aprovado') $status_class = 'badge-aprovado';
                            elseif ($aluno['status_geral'] == 'Recuperação') $status_class = 'badge-recuperacao';
                            elseif ($aluno['status_geral'] == 'Reprovado') $status_class = 'badge-reprovado';
                            else $status_class = 'badge-reprovado';
                        ?>
                            <tr>
                                <td><span class="ranking-pos"><?php echo $medalha . $posicao; ?>º</span></td>
                                <td class="text-start"><strong><?php echo htmlspecialchars($aluno['aluno_nome']); ?></strong> <small><?php echo $genero_icon; ?></small></td>
                                <td><?php echo ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M') ? 'Masculino' : 'Feminino'; ?></td>
                                <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                <td><strong><?php echo number_format($aluno['media_geral'], 1); ?></strong> / <?php echo $escala_max; ?></td>
                                <td><span class="<?php echo $status_class; ?>"><?php echo $aluno['status_geral']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php elseif ($tipo_relatorio == 'disciplina' && $disciplina_id > 0 && !empty($dados_analise)): ?>
    
    <!-- Informações da Disciplina -->
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-book"></i> Disciplina: <?php echo htmlspecialchars($disciplina_info['nome']); ?> (<?php echo htmlspecialchars($disciplina_info['codigo']); ?>)
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4"><strong>Total de Alunos:</strong> <?php echo $estatisticas_gerais['total_alunos']; ?></div>
                        <div class="col-md-4"><strong>Média Geral:</strong> <?php echo $estatisticas_gerais['total_alunos'] > 0 ? round(($estatisticas_gerais['total_aprovados'] / $estatisticas_gerais['total_alunos']) * 100, 1) : 0; ?>%</div>
                        <div class="col-md-4"><strong>Escala:</strong> 0-<?php echo $escala_max; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card stat-aprovado">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_aprovados']; ?></div>
                <div class="stat-label">Aprovados</div>
                <small><?php echo $estatisticas_gerais['taxa_aprovacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-recuperacao">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_recuperacao']; ?></div>
                <div class="stat-label">Recuperação</div>
                <small><?php echo $estatisticas_gerais['taxa_recuperacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-reprovado">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_reprovados']; ?></div>
                <div class="stat-label">Reprovados</div>
                <small><?php echo $estatisticas_gerais['taxa_reprovacao']; ?>%</small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card stat-media">
                <div class="stat-number"><?php echo $estatisticas_gerais['total_alunos']; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
        </div>
    </div>
    
    <!-- Gráficos -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-pie"></i> Distribuição Geral
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoStatusDisciplina"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar"></i> Distribuição de Notas
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoDistribuicaoNotas"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar"></i> Taxa de Aprovação por Turma
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoAprovacaoTurma"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i> Média por Turma
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoMediaTurma"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Estatísticas por Gênero -->
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
                            <th>Média</th>
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
    
    <!-- Desempenho por Turma -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="fas fa-building"></i> Desempenho por Turma
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="table-desempenho">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Turma</th>
                            <th>Turno</th>
                            <th>Alunos</th>
                            <th>Aprovados</th>
                            <th>Recuperação</th>
                            <th>Reprovados</th>
                            <th>Média</th>
                            <th>Taxa Aprovação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dados_analise as $index => $turma): 
                            $percentual = $turma['taxa_aprovacao'];
                            $barra_cor = $percentual >= 75 ? 'success' : ($percentual >= 50 ? 'warning' : 'danger');
                        ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><strong><?php echo $turma['turma_ano']; ?>ª - <?php echo htmlspecialchars($turma['turma_nome']); ?></strong></td>
                                <td><?php echo ucfirst($turma['turno'] ?? ''); ?></td>
                                <td><?php echo $turma['total_alunos']; ?></td>
                                <td class="text-success"><?php echo $turma['aprovados']; ?> (<?php echo $turma['taxa_aprovacao']; ?>%)</td>
                                <td class="text-warning"><?php echo $turma['recuperacao']; ?> (<?php echo $turma['taxa_recuperacao']; ?>%)</td>
                                <td class="text-danger"><?php echo $turma['reprovados']; ?> (<?php echo $turma['taxa_reprovacao']; ?>%)</td>
                                <td><strong><?php echo number_format($turma['media_turma'], 1); ?></strong> / <?php echo $escala_max; ?></td>
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
    
    <?php elseif ($tipo_relatorio == 'turma' && $turma_id > 0 && empty($dados_analise)): ?>
        <div class="alert alert-info">Nenhum dado encontrado para esta turma.</div>
    <?php elseif ($tipo_relatorio == 'disciplina' && $disciplina_id > 0 && empty($dados_analise)): ?>
        <div class="alert alert-info">Nenhum dado encontrado para esta disciplina.</div>
    <?php elseif ($ano_letivo_id == 0): ?>
        <div class="alert alert-info">Selecione um ano letivo para visualizar os dados.</div>
    <?php endif; ?>
</div>

<script>
    <?php if ($tipo_relatorio == 'geral' && $ano_letivo_id > 0): ?>
    // Gráfico Geral
    new Chart(document.getElementById('graficoGeral'), {
        type: 'doughnut',
        data: {
            labels: ['Aprovados', 'Recuperação', 'Reprovados'],
            datasets: [{
                data: [<?php echo $estatisticas_gerais['total_aprovados']; ?>, <?php echo $estatisticas_gerais['total_recuperacao']; ?>, <?php echo $estatisticas_gerais['total_reprovados']; ?>],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    
    // Gráfico por Gênero
    new Chart(document.getElementById('graficoGenero'), {
        type: 'bar',
        data: {
            labels: ['Masculino', 'Feminino'],
            datasets: [
                { label: 'Aprovados', data: [<?php echo $genero_estatisticas['masculino']['aprovados']; ?>, <?php echo $genero_estatisticas['feminino']['aprovados']; ?>], backgroundColor: '#28a745' },
                { label: 'Recuperação', data: [<?php echo $genero_estatisticas['masculino']['recuperacao']; ?>, <?php echo $genero_estatisticas['feminino']['recuperacao']; ?>], backgroundColor: '#ffc107' },
                { label: 'Reprovados', data: [<?php echo $genero_estatisticas['masculino']['reprovados']; ?>, <?php echo $genero_estatisticas['feminino']['reprovados']; ?>], backgroundColor: '#dc3545' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Número de Alunos' } } } }
    });
    
    // Gráfico de Aprovação por Turma
    new Chart(document.getElementById('graficoAprovacaoTurma'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($dados_graficos['aprovacao_por_turma'], 'nome')); ?>,
            datasets: [{
                label: 'Taxa de Aprovação (%)',
                data: <?php echo json_encode(array_column($dados_graficos['aprovacao_por_turma'], 'taxa')); ?>,
                backgroundColor: '#1e5799',
                borderRadius: 5
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Taxa (%)' } } } }
    });
    
    // Gráfico de Média por Turma
    new Chart(document.getElementById('graficoMediaTurma'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_column($dados_graficos['media_por_turma'], 'nome')); ?>,
            datasets: [{
                label: 'Média da Turma',
                data: <?php echo json_encode(array_column($dados_graficos['media_por_turma'], 'media')); ?>,
                borderColor: '#1e5799',
                backgroundColor: 'rgba(30, 87, 153, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Média' } } } }
    });
    <?php endif; ?>
    
    <?php if ($tipo_relatorio == 'turma' && $turma_id > 0 && !empty($dados_analise)): ?>
    // Gráfico de Status da Turma
    new Chart(document.getElementById('graficoStatusTurma'), {
        type: 'doughnut',
        data: {
            labels: ['Aprovados', 'Recuperação', 'Reprovados'],
            datasets: [{
                data: [<?php echo $estatisticas_gerais['total_aprovados']; ?>, <?php echo $estatisticas_gerais['total_recuperacao']; ?>, <?php echo $estatisticas_gerais['total_reprovados']; ?>],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    
    // Gráfico de Distribuição de Notas
    const labelsNotas = [];
    const dadosNotas = [];
    <?php for ($i = 0; $i <= $escala_max; $i++): ?>
        <?php if (($dados_graficos['distribuicao_notas'][$i] ?? 0) > 0 || $i == 0 || $i == $escala_max): ?>
            labelsNotas.push(<?php echo $i; ?>);
            dadosNotas.push(<?php echo $dados_graficos['distribuicao_notas'][$i] ?? 0; ?>);
        <?php endif; ?>
    <?php endfor; ?>
    new Chart(document.getElementById('graficoDistribuicaoNotas'), {
        type: 'bar',
        data: { labels: labelsNotas, datasets: [{ label: 'Número de Alunos', data: dadosNotas, backgroundColor: '#1e5799', borderRadius: 5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Alunos' } }, x: { title: { display: true, text: 'Notas' } } } }
    });
    
    // Gráfico de Evolução por Bimestre
    new Chart(document.getElementById('graficoEvolucaoBimestre'), {
        type: 'line',
        data: {
            labels: ['1º Bimestre', '2º Bimestre', '3º Bimestre'],
            datasets: [{
                label: 'Média da Turma',
                data: [<?php echo $dados_graficos['medias_por_bimestre'][1] ?? 0; ?>, <?php echo $dados_graficos['medias_por_bimestre'][2] ?? 0; ?>, <?php echo $dados_graficos['medias_por_bimestre'][3] ?? 0; ?>],
                borderColor: '#1e5799',
                backgroundColor: 'rgba(30, 87, 153, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Média' } } } }
    });
    
    // Gráfico de Aprovação por Disciplina
    new Chart(document.getElementById('graficoAprovacaoDisciplina'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($dados_graficos['aprovacao_disciplinas'] ?? [], 'nome')); ?>,
            datasets: [{
                label: 'Taxa de Aprovação (%)',
                data: <?php echo json_encode(array_column($dados_graficos['aprovacao_disciplinas'] ?? [], 'taxa')); ?>,
                backgroundColor: '#1e5799',
                borderRadius: 5
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Taxa (%)' } } } }
    });
    <?php endif; ?>
    
    <?php if ($tipo_relatorio == 'disciplina' && $disciplina_id > 0 && !empty($dados_analise)): ?>
    // Gráfico de Status da Disciplina
    new Chart(document.getElementById('graficoStatusDisciplina'), {
        type: 'doughnut',
        data: {
            labels: ['Aprovados', 'Recuperação', 'Reprovados'],
            datasets: [{
                data: [<?php echo $estatisticas_gerais['total_aprovados']; ?>, <?php echo $estatisticas_gerais['total_recuperacao']; ?>, <?php echo $estatisticas_gerais['total_reprovados']; ?>],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    
    // Gráfico de Distribuição de Notas
    const labelsNotasDisc = [];
    const dadosNotasDisc = [];
    <?php for ($i = 0; $i <= $escala_max; $i++): ?>
        <?php if (($dados_graficos['distribuicao_notas'][$i] ?? 0) > 0 || $i == 0 || $i == $escala_max): ?>
            labelsNotasDisc.push(<?php echo $i; ?>);
            dadosNotasDisc.push(<?php echo $dados_graficos['distribuicao_notas'][$i] ?? 0; ?>);
        <?php endif; ?>
    <?php endfor; ?>
    new Chart(document.getElementById('graficoDistribuicaoNotas'), {
        type: 'bar',
        data: { labels: labelsNotasDisc, datasets: [{ label: 'Número de Alunos', data: dadosNotasDisc, backgroundColor: '#1e5799', borderRadius: 5 }] },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Alunos' } }, x: { title: { display: true, text: 'Notas' } } } }
    });
    
    // Gráfico de Aprovação por Turma
    new Chart(document.getElementById('graficoAprovacaoTurma'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($dados_graficos['aprovacao_por_turma'], 'nome')); ?>,
            datasets: [{
                label: 'Taxa de Aprovação (%)',
                data: <?php echo json_encode(array_column($dados_graficos['aprovacao_por_turma'], 'taxa')); ?>,
                backgroundColor: '#1e5799',
                borderRadius: 5
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Taxa (%)' } } } }
    });
    
    // Gráfico de Média por Turma
    new Chart(document.getElementById('graficoMediaTurma'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($dados_graficos['media_por_turma'], 'nome')); ?>,
            datasets: [{
                label: 'Média da Turma',
                data: <?php echo json_encode(array_column($dados_graficos['media_por_turma'], 'media')); ?>,
                backgroundColor: '#1e5799',
                borderRadius: 5
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Média' } } } }
    });
    <?php endif; ?>
    
    // Auto-submit ao selecionar tipo de relatório
    document.querySelector('select[name="tipo"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>