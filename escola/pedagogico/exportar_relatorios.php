<?php
// escola/pedagogico/exportar_relatorios.php - Exportar Relatórios

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
$tipo_relatorio = isset($_GET['tipo']) ? $_GET['tipo'] : 'desempenho';
$formato = isset($_GET['formato']) ? $_GET['formato'] : 'excel';
$status_filtro = isset($_GET['status_aluno']) ? $_GET['status_aluno'] : 'todos';

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
// BUSCAR DADOS CONFORME TIPO DE RELATÓRIO
// ============================================
$ano_letivo_ano = '';
if ($ano_letivo_id > 0) {
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
}

$dados_relatorio = [];
$cabecalhos = [];
$titulo_relatorio = '';

// ============================================
// RELATÓRIO DE DESEMPENHO POR TURMA
// ============================================
if ($tipo_relatorio == 'desempenho' && $turma_id > 0) {
    $titulo_relatorio = 'RELATÓRIO DE DESEMPENHO DA TURMA';
    
    // Buscar informações da turma
    $sql_turma = "SELECT nome, ano, turno_id FROM turmas WHERE id = :id";
    $stmt_turma = $conn->prepare($sql_turma);
    $stmt_turma->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT e.id, e.nome, e.matricula, e.genero
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
        ORDER BY e.nome ASC
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id, ':ano_letivo' => $ano_letivo_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar disciplinas da turma
    $sql_disc_turma = "
        SELECT d.id, d.nome, d.codigo
        FROM disciplina_turma dt
        INNER JOIN disciplinas d ON d.id = dt.disciplina_id
        WHERE dt.turma_id = :turma_id
        ORDER BY d.nome ASC
    ";
    $stmt_disc_turma = $conn->prepare($sql_disc_turma);
    $stmt_disc_turma->execute([':turma_id' => $turma_id]);
    $disciplinas_turma = $stmt_disc_turma->fetchAll(PDO::FETCH_ASSOC);
    
    // Determinar escala
    $classe_ano = $turma_info['ano'] ?? 0;
    $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
    $escala_max = ($classe_ano <= 6) ? 10 : 20;
    $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
    
    // Cabeçalhos com design
    $cabecalhos = ['#', 'ALUNO', 'MATRÍCULA', 'GÊNERO'];
    foreach ($disciplinas_turma as $index => $disc) {
        $cabecalhos[] = $disc['nome'] . ' (MÉDIA)';
        $cabecalhos[] = $disc['nome'] . ' (STATUS)';
    }
    $cabecalhos[] = 'MÉDIA GERAL';
    $cabecalhos[] = 'STATUS GERAL';
    
    $row_num = 1;
    foreach ($alunos as $aluno) {
        $linha = [
            $row_num++,
            strtoupper($aluno['nome']),
            $aluno['matricula'],
            $aluno['genero'] == 'M' ? 'MASCULINO' : ($aluno['genero'] == 'F' ? 'FEMININO' : 'NÃO INFORMADO')
        ];
        
        $soma_notas = 0;
        $count_notas = 0;
        
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
                ':bimestre' => $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                ':ano_letivo' => $ano_letivo_id
            ]);
            $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
            
            if ($nota && ($nota['media_final'] > 0 || $nota['mac'] > 0 || $nota['npt'] > 0)) {
                $is_lingua = (stripos($disc['nome'], 'português') !== false || stripos($disc['nome'], 'inglês') !== false);
                $media = calcMediaFinal(
                    $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                    $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                    $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                    $nota['exame_escrito'] ?? 0,
                    $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                    $is_classe_exame, $is_lingua
                );
                $status = $media >= $limite_aprovacao ? 'APROVADO' : ($media >= $limite_aprovacao * 0.7 ? 'RECUPERAÇÃO' : 'REPROVADO');
                $linha[] = number_format($media, 1);
                $linha[] = $status;
                $soma_notas += $media;
                $count_notas++;
            } else {
                $linha[] = '---';
                $linha[] = 'PENDENTE';
            }
        }
        
        $media_geral = $count_notas > 0 ? round($soma_notas / $count_notas, 1) : 0;
        $status_geral = $media_geral >= $limite_aprovacao ? 'APROVADO' : ($media_geral >= $limite_aprovacao * 0.7 ? 'RECUPERAÇÃO' : ($media_geral > 0 ? 'REPROVADO' : 'PENDENTE'));
        
        $linha[] = $media_geral > 0 ? number_format($media_geral, 1) : '---';
        $linha[] = $status_geral;
        
        $dados_relatorio[] = $linha;
    }
}

// ============================================
// RELATÓRIO DE ALUNOS POR STATUS
// ============================================
if ($tipo_relatorio == 'alunos_status' && $ano_letivo_id > 0) {
    $titulo_relatorio = 'RELATÓRIO DE ALUNOS POR STATUS';
    
    // Buscar todas as turmas
    $sql_turmas_escola = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'";
    $stmt_turmas_escola = $conn->prepare($sql_turmas_escola);
    $stmt_turmas_escola->execute([':escola_id' => $escola_id]);
    $turmas_lista = $stmt_turmas_escola->fetchAll(PDO::FETCH_ASSOC);
    
    $cabecalhos = ['#', 'TURMA', 'ALUNO', 'MATRÍCULA', 'GÊNERO', 'MÉDIA GERAL', 'STATUS'];
    $todos_alunos = [];
    $row_num = 1;
    
    foreach ($turmas_lista as $turma) {
        $classe_ano = $turma['ano'];
        $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
        $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
        
        // Buscar alunos da turma
        $sql_alunos = "
            SELECT e.id, e.nome, e.matricula, e.genero
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
            ORDER BY e.nome ASC
        ";
        $stmt_alunos = $conn->prepare($sql_alunos);
        $stmt_alunos->execute([':turma_id' => $turma['id'], ':ano_letivo' => $ano_letivo_id]);
        $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
        
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
        
        foreach ($alunos_turma as $aluno) {
            $soma_notas = 0;
            $count_notas = 0;
            
            foreach ($disciplinas_turma as $disc) {
                $sql_nota = "
                    SELECT media_final
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
                if ($nota && $nota['media_final'] > 0) {
                    $soma_notas += $nota['media_final'];
                    $count_notas++;
                }
            }
            
            $media_geral = $count_notas > 0 ? round($soma_notas / $count_notas, 1) : 0;
            $status = $media_geral >= $limite_aprovacao ? 'aprovado' : ($media_geral >= $limite_aprovacao * 0.7 ? 'recuperacao' : ($media_geral > 0 ? 'reprovado' : 'pendente'));
            
            if ($status_filtro == 'todos' || $status_filtro == $status) {
                $status_text = $status == 'aprovado' ? 'APROVADO' : ($status == 'recuperacao' ? 'RECUPERAÇÃO' : ($status == 'reprovado' ? 'REPROVADO' : 'PENDENTE'));
                $todos_alunos[] = [
                    'row' => $row_num++,
                    'turma' => $turma['ano'] . 'ª ' . $turma['nome'],
                    'aluno' => strtoupper($aluno['nome']),
                    'matricula' => $aluno['matricula'],
                    'genero' => $aluno['genero'] == 'M' ? 'MASCULINO' : ($aluno['genero'] == 'F' ? 'FEMININO' : 'NÃO INFORMADO'),
                    'media' => $media_geral > 0 ? number_format($media_geral, 1) : '---',
                    'status' => $status_text
                ];
            }
        }
    }
    
    foreach ($todos_alunos as $aluno) {
        $dados_relatorio[] = [
            $aluno['row'],
            $aluno['turma'],
            $aluno['aluno'],
            $aluno['matricula'],
            $aluno['genero'],
            $aluno['media'],
            $aluno['status']
        ];
    }
}

// ============================================
// RELATÓRIO DE NOTAS POR DISCIPLINA
// ============================================
if ($tipo_relatorio == 'notas_disciplina' && $disciplina_id > 0 && $ano_letivo_id > 0) {
    $titulo_relatorio = 'RELATÓRIO DE NOTAS POR DISCIPLINA';
    
    // Buscar informações da disciplina
    $sql_disc = "SELECT nome, codigo FROM disciplinas WHERE id = :id";
    $stmt_disc = $conn->prepare($sql_disc);
    $stmt_disc->execute([':id' => $disciplina_id]);
    $disciplina_info = $stmt_disc->fetch(PDO::FETCH_ASSOC);
    
    $titulo_relatorio .= ' - ' . strtoupper($disciplina_info['nome']);
    
    // Buscar turma específica ou todas
    if ($turma_id > 0) {
        $sql_turmas_lista = "SELECT id, nome, ano FROM turmas WHERE id = :id";
        $stmt_turmas_lista = $conn->prepare($sql_turmas_lista);
        $stmt_turmas_lista->execute([':id' => $turma_id]);
        $turmas_lista = $stmt_turmas_lista->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $sql_turmas_escola = "
            SELECT DISTINCT t.id, t.nome, t.ano
            FROM turmas t
            INNER JOIN disciplina_turma dt ON dt.turma_id = t.id
            WHERE dt.disciplina_id = :disciplina_id AND t.escola_id = :escola_id AND t.status = 'ativa'
            ORDER BY t.ano ASC, t.nome ASC
        ";
        $stmt_turmas_escola = $conn->prepare($sql_turmas_escola);
        $stmt_turmas_escola->execute([':disciplina_id' => $disciplina_id, ':escola_id' => $escola_id]);
        $turmas_lista = $stmt_turmas_escola->fetchAll(PDO::FETCH_ASSOC);
    }
    
    $cabecalhos = ['#', 'TURMA', 'ALUNO', 'MATRÍCULA', 'GÊNERO', 'NOTA', 'STATUS', 'MAC', 'NPT'];
    $row_num = 1;
    
    foreach ($turmas_lista as $turma) {
        $classe_ano = $turma['ano'];
        $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
        $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
        $is_lingua = (stripos($disciplina_info['nome'], 'português') !== false || stripos($disciplina_info['nome'], 'inglês') !== false);
        
        // Buscar alunos da turma
        $sql_alunos = "
            SELECT e.id, e.nome, e.matricula, e.genero
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
            ORDER BY e.nome ASC
        ";
        $stmt_alunos = $conn->prepare($sql_alunos);
        $stmt_alunos->execute([':turma_id' => $turma['id'], ':ano_letivo' => $ano_letivo_id]);
        $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($alunos_turma as $aluno) {
            $sql_nota = "
                SELECT mac, npt, exame_normal, exame_recurso, exame_especial, exame_oral, exame_escrito, media_final
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
            
            if ($nota && ($nota['media_final'] > 0 || $nota['mac'] > 0 || $nota['npt'] > 0)) {
                $media = calcMediaFinal(
                    $nota['mac'] ?? 0, $nota['npt'] ?? 0,
                    $nota['exame_normal'] ?? 0, $nota['exame_recurso'] ?? 0,
                    $nota['exame_especial'] ?? 0, $nota['exame_oral'] ?? 0,
                    $nota['exame_escrito'] ?? 0,
                    $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                    $is_classe_exame, $is_lingua
                );
                $status = $media >= $limite_aprovacao ? 'APROVADO' : ($media >= $limite_aprovacao * 0.7 ? 'RECUPERAÇÃO' : 'REPROVADO');
                $dados_relatorio[] = [
                    $row_num++,
                    $turma['ano'] . 'ª ' . $turma['nome'],
                    strtoupper($aluno['nome']),
                    $aluno['matricula'],
                    $aluno['genero'] == 'M' ? 'MASCULINO' : ($aluno['genero'] == 'F' ? 'FEMININO' : 'NÃO INFORMADO'),
                    number_format($media, 1),
                    $status,
                    $nota['mac'] > 0 ? number_format($nota['mac'], 1) : '---',
                    $nota['npt'] > 0 ? number_format($nota['npt'], 1) : '---'
                ];
            } else {
                $dados_relatorio[] = [
                    $row_num++,
                    $turma['ano'] . 'ª ' . $turma['nome'],
                    strtoupper($aluno['nome']),
                    $aluno['matricula'],
                    $aluno['genero'] == 'M' ? 'MASCULINO' : ($aluno['genero'] == 'F' ? 'FEMININO' : 'NÃO INFORMADO'),
                    '---',
                    'PENDENTE',
                    '---',
                    '---'
                ];
            }
        }
    }
}

// ============================================
// RELATÓRIO DE APROVEITAMENTO GERAL
// ============================================
if ($tipo_relatorio == 'aproveitamento' && $ano_letivo_id > 0) {
    $titulo_relatorio = 'RELATÓRIO DE APROVEITAMENTO GERAL';
    
    // Buscar todas as turmas
    $sql_turmas_escola = "SELECT id, nome, ano FROM turmas WHERE escola_id = :escola_id AND status = 'ativa' ORDER BY ano ASC, nome ASC";
    $stmt_turmas_escola = $conn->prepare($sql_turmas_escola);
    $stmt_turmas_escola->execute([':escola_id' => $escola_id]);
    $turmas_lista = $stmt_turmas_escola->fetchAll(PDO::FETCH_ASSOC);
    
    $cabecalhos = ['#', 'TURMA', 'TOTAL ALUNOS', 'APROVADOS', 'RECUPERAÇÃO', 'REPROVADOS', 'TAXA APROVAÇÃO', 'MÉDIA GERAL'];
    $row_num = 1;
    $total_geral_alunos = 0;
    $total_geral_aprovados = 0;
    
    foreach ($turmas_lista as $turma) {
        $classe_ano = $turma['ano'];
        $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
        
        // Buscar alunos da turma
        $sql_alunos = "
            SELECT e.id
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
        ";
        $stmt_alunos = $conn->prepare($sql_alunos);
        $stmt_alunos->execute([':turma_id' => $turma['id'], ':ano_letivo' => $ano_letivo_id]);
        $alunos_turma = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
        
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
        
        $total_alunos = count($alunos_turma);
        $aprovados = 0;
        $recuperacao = 0;
        $reprovados = 0;
        $soma_medias = 0;
        $alunos_com_nota = 0;
        
        foreach ($alunos_turma as $aluno) {
            $soma_notas = 0;
            $count_notas = 0;
            
            foreach ($disciplinas_turma as $disc) {
                $sql_nota = "
                    SELECT media_final
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
                if ($nota && $nota['media_final'] > 0) {
                    $soma_notas += $nota['media_final'];
                    $count_notas++;
                }
            }
            
            if ($count_notas > 0) {
                $media_geral = $soma_notas / $count_notas;
                $soma_medias += $media_geral;
                $alunos_com_nota++;
                
                if ($media_geral >= $limite_aprovacao) {
                    $aprovados++;
                } elseif ($media_geral >= $limite_aprovacao * 0.7) {
                    $recuperacao++;
                } else {
                    $reprovados++;
                }
            }
        }
        
        $media_turma = $alunos_com_nota > 0 ? round($soma_medias / $alunos_com_nota, 1) : 0;
        $taxa_aprovacao = $total_alunos > 0 ? round(($aprovados / $total_alunos) * 100, 1) : 0;
        
        $total_geral_alunos += $total_alunos;
        $total_geral_aprovados += $aprovados;
        
        // Barra de progresso visual para a taxa
        $barra_cor = $taxa_aprovacao >= 75 ? '#27ae60' : ($taxa_aprovacao >= 50 ? '#f39c12' : '#e74c3c');
        
        $dados_relatorio[] = [
            $row_num++,
            $turma['ano'] . 'ª ' . $turma['nome'],
            $total_alunos,
            $aprovados,
            $recuperacao,
            $reprovados,
            $taxa_aprovacao . '%',
            $media_turma > 0 ? number_format($media_turma, 1) : '---'
        ];
    }
    
    // Adicionar linha de total geral
    $taxa_geral = $total_geral_alunos > 0 ? round(($total_geral_aprovados / $total_geral_alunos) * 100, 1) : 0;
    $dados_relatorio[] = [
        '',
        'TOTAL GERAL',
        $total_geral_alunos,
        $total_geral_aprovados,
        '',
        '',
        $taxa_geral . '%',
        ''
    ];
}

// ============================================
// GERAR ARQUIVO CSV (EXCEL) COM DESIGN MELHORADO
// ============================================
if ($formato == 'excel') {
    $filename = str_replace(' ', '_', $titulo_relatorio) . '_' . date('Ymd_His') . '.csv';
    
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    // Adicionar BOM para UTF-8
    echo "\xEF\xBB\xBF";
    
    $output = fopen('php://output', 'w');
    
    // Linha de separação
    fputcsv($output, ['']);
    fputcsv($output, ['=' . str_repeat('=', 80)]);
    fputcsv($output, ['']);
    
    // Cabeçalho da escola
    fputcsv($output, [strtoupper($escola['nome'])]);
    fputcsv($output, [$escola['endereco'] ?? '']);
    fputcsv($output, ['Telefone: ' . ($escola['telefone'] ?? '') . ' | Email: ' . ($escola['email'] ?? '')]);
    fputcsv($output, ['']);
    
    // Título do relatório
    fputcsv($output, [$titulo_relatorio]);
    fputcsv($output, ['']);
    
    // Informações do filtro
    fputcsv($output, ['Ano Letivo: ' . $ano_letivo_ano]);
    if ($bimestre_filtro > 0) {
        fputcsv($output, ['Bimestre: ' . $bimestre_filtro . 'º Bimestre']);
    } else {
        fputcsv($output, ['Bimestre: Média Final']);
    }
    if ($status_filtro != 'todos' && $tipo_relatorio == 'alunos_status') {
        fputcsv($output, ['Status: ' . strtoupper($status_filtro)]);
    }
    fputcsv($output, ['Data de emissão: ' . date('d/m/Y H:i:s')]);
    fputcsv($output, ['']);
    fputcsv($output, ['=' . str_repeat('=', 80)]);
    fputcsv($output, ['']);
    
    // Adicionar cabeçalhos das colunas
    if (!empty($cabecalhos)) {
        fputcsv($output, $cabecalhos);
        fputcsv($output, array_fill(0, count($cabecalhos), '-'));
    } else if (!empty($dados_relatorio)) {
        fputcsv($output, array_keys($dados_relatorio[0]));
        fputcsv($output, array_fill(0, count($dados_relatorio[0]), '-'));
    }
    
    // Adicionar dados
    foreach ($dados_relatorio as $linha) {
        fputcsv($output, $linha);
    }
    
    // Rodapé
    fputcsv($output, ['']);
    fputcsv($output, ['=' . str_repeat('=', 80)]);
    fputcsv($output, ['']);
    fputcsv($output, ['Relatório gerado eletronicamente pelo Sistema de Gestão Escolar (SIGE)']);
    fputcsv($output, ['© ' . date('Y') . ' - Todos os direitos reservados']);
    
    fclose($output);
    exit;
}

// ============================================
// GERAR HTML PARA IMPRESSÃO COM DESIGN BONITO
// ============================================
if ($formato == 'html') {
    // Calcular estatísticas para o rodapé
    $total_registros = count($dados_relatorio);
    $status_color = '';
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . $titulo_relatorio . ' - ' . $escola['nome'] . '</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                font-size: 12px;
                padding: 20px;
                background: #f5f5f5;
            }
            
            .report-container {
                max-width: 1300px;
                margin: 0 auto;
                background: white;
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            /* Header */
            .report-header {
                background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
                color: white;
                padding: 30px;
                text-align: center;
                position: relative;
            }
            
            .report-header h1 {
                font-size: 28px;
                margin-bottom: 10px;
                letter-spacing: 2px;
            }
            
            .report-header h2 {
                font-size: 18px;
                font-weight: normal;
                opacity: 0.9;
                margin-bottom: 15px;
            }
            
            .report-header p {
                font-size: 12px;
                opacity: 0.7;
            }
            
            /* Info Box */
            .info-box {
                background: #f8f9fa;
                padding: 20px 30px;
                border-bottom: 1px solid #e9ecef;
                display: flex;
                justify-content: space-between;
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .info-item {
                background: white;
                padding: 8px 15px;
                border-radius: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                font-size: 12px;
            }
            
            .info-item strong {
                color: #1e5799;
            }
            
            /* Table */
            .table-wrapper {
                overflow-x: auto;
                padding: 20px;
            }
            
            .report-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }
            
            .report-table th {
                background: linear-gradient(135deg, #1e5799, #2c3e50);
                color: white;
                padding: 12px 8px;
                text-align: center;
                font-weight: 700;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                border: 1px solid #2c3e50;
            }
            
            .report-table td {
                padding: 10px 8px;
                text-align: center;
                border: 1px solid #e9ecef;
                vertical-align: middle;
            }
            
            .report-table tr:nth-child(even) {
                background-color: #f8f9fa;
            }
            
            .report-table tr:hover {
                background-color: #e8f4fd;
                transition: 0.3s;
            }
            
            /* Status Badges */
            .status-aprovado {
                background: #d4edda;
                color: #155724;
                padding: 4px 10px;
                border-radius: 20px;
                font-weight: bold;
                display: inline-block;
                font-size: 10px;
            }
            
            .status-recuperacao {
                background: #fff3cd;
                color: #856404;
                padding: 4px 10px;
                border-radius: 20px;
                font-weight: bold;
                display: inline-block;
                font-size: 10px;
            }
            
            .status-reprovado {
                background: #f8d7da;
                color: #721c24;
                padding: 4px 10px;
                border-radius: 20px;
                font-weight: bold;
                display: inline-block;
                font-size: 10px;
            }
            
            .status-pendente {
                background: #e2e3e5;
                color: #383d41;
                padding: 4px 10px;
                border-radius: 20px;
                font-weight: bold;
                display: inline-block;
                font-size: 10px;
            }
            
            /* Nota styling */
            .nota-alta {
                color: #27ae60;
                font-weight: bold;
            }
            
            .nota-baixa {
                color: #e74c3c;
                font-weight: bold;
            }
            
            /* Footer */
            .report-footer {
                background: #f8f9fa;
                padding: 20px 30px;
                text-align: center;
                border-top: 1px solid #e9ecef;
                font-size: 10px;
                color: #6c757d;
            }
            
            .report-footer p {
                margin: 5px 0;
            }
            
            /* Buttons */
            .action-buttons {
                text-align: center;
                padding: 20px;
                background: #f8f9fa;
                border-top: 1px solid #e9ecef;
            }
            
            .btn-print, .btn-close {
                padding: 8px 20px;
                border: none;
                border-radius: 30px;
                cursor: pointer;
                font-size: 12px;
                font-weight: 600;
                margin: 0 5px;
                transition: all 0.3s ease;
            }
            
            .btn-print {
                background: linear-gradient(135deg, #27ae60, #2ecc71);
                color: white;
            }
            
            .btn-print:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(39,174,96,0.3);
            }
            
            .btn-close {
                background: #6c757d;
                color: white;
            }
            
            .btn-close:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(108,117,125,0.3);
            }
            
            /* Text alignment */
            .text-left { text-align: left; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .fw-bold { font-weight: bold; }
            
            @media print {
                body {
                    background: white;
                    padding: 0;
                    margin: 0;
                }
                .report-container {
                    box-shadow: none;
                    border-radius: 0;
                }
                .action-buttons {
                    display: none;
                }
                .report-header {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .report-table th {
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
            }
        </style>
    </head>
    <body>
        <div class="report-container">
            <div class="report-header">
                <h1>' . strtoupper(htmlspecialchars($escola['nome'])) . '</h1>
                <h2>' . $titulo_relatorio . '</h2>
                <p>' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . '</p>
            </div>
            
            <div class="info-box">
                <div class="info-item"><strong>📅 Ano Letivo:</strong> ' . $ano_letivo_ano . '</div>
                <div class="info-item"><strong>📊 Bimestre:</strong> ' . ($bimestre_filtro > 0 ? $bimestre_filtro . 'º Bimestre' : 'Média Final') . '</div>
                <div class="info-item"><strong>📄 Total de Registros:</strong> ' . $total_registros . '</div>
                <div class="info-item"><strong>📅 Data de Emissão:</strong> ' . date('d/m/Y H:i:s') . '</div>
            </div>
            
            <div class="table-wrapper">
                <table class="report-table">
                    <thead>
                        <tr>';
    
    if (!empty($cabecalhos)) {
        foreach ($cabecalhos as $cabecalho) {
            $html .= '<th>' . htmlspecialchars($cabecalho) . '</th>';
        }
    } else if (!empty($dados_relatorio)) {
        foreach (array_keys($dados_relatorio[0]) as $key) {
            $html .= '<th>' . htmlspecialchars($key) . '</th>';
        }
    }
    
    $html .= '
                        </tr>
                    </thead>
                    <tbody>';
    
    foreach ($dados_relatorio as $linha) {
        $html .= '<tr>';
        foreach ($linha as $index => $valor) {
            // Aplicar estilos especiais para status e notas
            $cell_class = '';
            if (strpos($valor, 'APROVADO') !== false) {
                $cell_class = 'status-aprovado';
                $valor = '<span class="' . $cell_class . '">' . htmlspecialchars($valor) . '</span>';
            } elseif (strpos($valor, 'RECUPERAÇÃO') !== false) {
                $cell_class = 'status-recuperacao';
                $valor = '<span class="' . $cell_class . '">' . htmlspecialchars($valor) . '</span>';
            } elseif (strpos($valor, 'REPROVADO') !== false) {
                $cell_class = 'status-reprovado';
                $valor = '<span class="' . $cell_class . '">' . htmlspecialchars($valor) . '</span>';
            } elseif (strpos($valor, 'PENDENTE') !== false) {
                $cell_class = 'status-pendente';
                $valor = '<span class="' . $cell_class . '">' . htmlspecialchars($valor) . '</span>';
            } elseif (is_numeric($valor) && $valor > 0 && $valor < 10) {
                $nota = floatval($valor);
                $classe_nota = $nota >= 4.5 ? 'nota-alta' : ($nota > 0 ? 'nota-baixa' : '');
                if ($classe_nota) {
                    $valor = '<span class="' . $classe_nota . '">' . htmlspecialchars($valor) . '</span>';
                }
            } elseif (is_numeric($valor) && $valor >= 10) {
                $nota = floatval($valor);
                $classe_nota = $nota >= 9.5 ? 'nota-alta' : ($nota > 0 ? 'nota-baixa' : '');
                if ($classe_nota) {
                    $valor = '<span class="' . $classe_nota . '">' . htmlspecialchars($valor) . '</span>';
                }
            }
            
            $html .= '<td class="text-center">' . $valor . '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '
                    </tbody>
                </table>
            </div>
            
            <div class="report-footer">
                <p>Relatório gerado eletronicamente pelo Sistema de Gestão Escolar (SIGE)</p>
                <p>© ' . date('Y') . ' - Todos os direitos reservados</p>
            </div>
            
            <div class="action-buttons">
                <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Imprimir Relatório</button>
                <button class="btn-close" onclick="window.close()"><i class="fas fa-times"></i> Fechar</button>
            </div>
        </div>
        
        <script>
            window.onload = function() { 
                setTimeout(function() { 
                    window.print(); 
                }, 500); 
            };
        </script>
    </body>
    </html>';
    
    echo $html;
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exportar Relatórios - SIGE Angola</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f0f2f5 0%, #e9ecef 100%); padding: 20px; min-height: 100vh; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, #1e5799 0%, #2c3e50 100%);
            color: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .btn-voltar {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 10px 24px;
            border-radius: 40px;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.3);
            font-weight: 600;
        }
        .btn-voltar:hover { background: rgba(255,255,255,0.3); transform: translateY(-2px); color: white; }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .card-header {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            padding: 15px 25px;
            font-weight: bold;
            font-size: 16px;
        }
        .card-body { padding: 25px; }
        
        /* Form Elements */
        .form-label { font-weight: 600; font-size: 13px; color: #2c3e50; margin-bottom: 8px; }
        .form-select, .form-control {
            border-radius: 12px;
            border: 2px solid #e9ecef;
            padding: 10px 15px;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        .form-select:focus, .form-control:focus {
            border-color: #1e5799;
            box-shadow: 0 0 0 3px rgba(30,87,153,0.1);
            outline: none;
        }
        
        /* Tipo Cards */
        .tipo-card {
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            border-radius: 16px;
            background: #f8f9fa;
            height: 100%;
        }
        .tipo-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.1); background: #fff; }
        .tipo-card.selected { border-color: #1e5799; background: linear-gradient(135deg, #e8f4fd, #fff); }
        .tipo-card i { font-size: 40px; margin-bottom: 10px; }
        
        /* Buttons */
        .btn-exportar, .btn-preview {
            padding: 12px 28px;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .btn-exportar {
            background: linear-gradient(135deg, #27ae60, #2ecc71);
            color: white;
        }
        .btn-preview {
            background: linear-gradient(135deg, #1e5799, #2c3e50);
            color: white;
            margin-left: 10px;
        }
        .btn-exportar:hover, .btn-preview:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0,0,0,0.15); }
        
        /* Info Icons */
        .info-icon {
            display: inline-block;
            width: 35px;
            height: 35px;
            line-height: 35px;
            text-align: center;
            background: #f8f9fa;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        @media (max-width: 768px) {
            body { padding: 10px; }
            .header { flex-direction: column; text-align: center; }
            .tipo-card { margin-bottom: 15px; }
            .btn-exportar, .btn-preview { width: 100%; margin: 5px 0; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-file-export"></i> Exportar Relatórios</h1>
            <p>Exporte relatórios em Excel (CSV) ou visualize para impressão com design profissional</p>
        </div>
        <a href="index.php" class="btn-voltar"><i class="fas fa-arrow-left"></i> Voltar</a>
    </div>
    
    <div class="card">
        <div class="card-header">
            <i class="fas fa-sliders-h"></i> Configuração do Relatório
        </div>
        <div class="card-body">
            <form method="GET" action="" id="formRelatorio" target="_blank">
                <div class="row mb-4">
                    <div class="col-md-12">
                        <label class="form-label"><i class="fas fa-chart-line"></i> Tipo de Relatório</label>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <div class="tipo-card text-center p-4 <?php echo ($tipo_relatorio == 'desempenho') ? 'selected' : ''; ?>" onclick="selecionarTipo('desempenho')">
                                    <i class="fas fa-chart-line text-primary"></i>
                                    <div class="fw-bold mt-2">Desempenho por Turma</div>
                                    <small class="text-muted">Médias e status por disciplina</small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="tipo-card text-center p-4 <?php echo ($tipo_relatorio == 'alunos_status') ? 'selected' : ''; ?>" onclick="selecionarTipo('alunos_status')">
                                    <i class="fas fa-users text-success"></i>
                                    <div class="fw-bold mt-2">Alunos por Status</div>
                                    <small class="text-muted">Lista de alunos por situação</small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="tipo-card text-center p-4 <?php echo ($tipo_relatorio == 'notas_disciplina') ? 'selected' : ''; ?>" onclick="selecionarTipo('notas_disciplina')">
                                    <i class="fas fa-book text-warning"></i>
                                    <div class="fw-bold mt-2">Notas por Disciplina</div>
                                    <small class="text-muted">Desempenho por disciplina</small>
                                </div>
                            </div>
                            <div class="col-md-3 mb-3">
                                <div class="tipo-card text-center p-4 <?php echo ($tipo_relatorio == 'aproveitamento') ? 'selected' : ''; ?>" onclick="selecionarTipo('aproveitamento')">
                                    <i class="fas fa-chart-pie text-danger"></i>
                                    <div class="fw-bold mt-2">Aproveitamento Geral</div>
                                    <small class="text-muted">Estatísticas por turma</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-calendar-alt"></i> Ano Letivo</label>
                        <select name="ano_letivo_id" class="form-select" required>
                            <?php foreach ($anos_letivos as $ano): ?>
                                <option value="<?php echo $ano['id']; ?>" <?php echo ($ano_letivo_id == $ano['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ano['ano']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><i class="fas fa-layer-group"></i> Bimestre</label>
                        <select name="bimestre" class="form-select">
                            <option value="0" <?php echo ($bimestre_filtro == 0) ? 'selected' : ''; ?>>Média Final</option>
                            <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                        </select>
                    </div>
                    <div class="col-md-3" id="divTurma" style="display: <?php echo ($tipo_relatorio == 'desempenho' || $tipo_relatorio == 'notas_disciplina') ? 'block' : 'none'; ?>">
                        <label class="form-label"><i class="fas fa-building"></i> Turma</label>
                        <select name="turma_id" class="form-select">
                            <option value="">Selecione uma turma</option>
                            <?php foreach ($turmas as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo ($turma_id == $t['id']) ? 'selected' : ''; ?>>
                                    <?php echo $t['ano']; ?>ª - <?php echo htmlspecialchars($t['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" id="divDisciplina" style="display: <?php echo ($tipo_relatorio == 'notas_disciplina') ? 'block' : 'none'; ?>">
                        <label class="form-label"><i class="fas fa-book-open"></i> Disciplina</label>
                        <select name="disciplina_id" class="form-select">
                            <option value="">Selecione uma disciplina</option>
                            <?php foreach ($disciplinas_lista as $d): ?>
                                <option value="<?php echo $d['id']; ?>" <?php echo ($disciplina_id == $d['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3" id="divStatus" style="display: <?php echo ($tipo_relatorio == 'alunos_status') ? 'block' : 'none'; ?>">
                        <label class="form-label"><i class="fas fa-flag-checkered"></i> Status do Aluno</label>
                        <select name="status_aluno" class="form-select">
                            <option value="todos">Todos</option>
                            <option value="aprovado">Aprovados</option>
                            <option value="recuperacao">Recuperação</option>
                            <option value="reprovado">Reprovados</option>
                            <option value="pendente">Pendentes</option>
                        </select>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-md-12 text-end">
                        <button type="submit" name="formato" value="excel" class="btn-exportar">
                            <i class="fas fa-file-excel"></i> Exportar para Excel
                        </button>
                        <button type="submit" name="formato" value="html" class="btn-preview">
                            <i class="fas fa-eye"></i> Visualizar / Imprimir
                        </button>
                    </div>
                </div>
                
                <input type="hidden" name="tipo" id="tipoRelatorio" value="<?php echo $tipo_relatorio; ?>">
            </form>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <i class="fas fa-info-circle"></i> Informações dos Relatórios
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="d-flex align-items-start">
                        <div class="info-icon"><i class="fas fa-chart-line text-primary"></i></div>
                        <div>
                            <strong>Desempenho por Turma</strong><br>
                            <small class="text-muted">Média por disciplina e status de cada aluno</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-flex align-items-start">
                        <div class="info-icon"><i class="fas fa-users text-success"></i></div>
                        <div>
                            <strong>Alunos por Status</strong><br>
                            <small class="text-muted">Lista completa filtrada por situação</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-flex align-items-start">
                        <div class="info-icon"><i class="fas fa-book text-warning"></i></div>
                        <div>
                            <strong>Notas por Disciplina</strong><br>
                            <small class="text-muted">Desempenho em disciplina específica</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-flex align-items-start">
                        <div class="info-icon"><i class="fas fa-chart-pie text-danger"></i></div>
                        <div>
                            <strong>Aproveitamento Geral</strong><br>
                            <small class="text-muted">Estatísticas consolidadas por turma</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-flex align-items-start">
                        <div class="info-icon"><i class="fas fa-file-excel text-success"></i></div>
                        <div>
                            <strong>Exportar para Excel</strong><br>
                            <small class="text-muted">Formato CSV compatível com Excel</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <div class="d-flex align-items-start">
                        <div class="info-icon"><i class="fas fa-print text-info"></i></div>
                        <div>
                            <strong>Visualizar/Imprimir</strong><br>
                            <small class="text-muted">Visualização otimizada para impressão</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function selecionarTipo(tipo) {
        document.getElementById('tipoRelatorio').value = tipo;
        
        // Atualizar visual dos cards
        document.querySelectorAll('.tipo-card').forEach(card => {
            card.classList.remove('selected');
        });
        event.currentTarget.classList.add('selected');
        
        // Mostrar/esconder campos conforme o tipo
        const divTurma = document.getElementById('divTurma');
        const divDisciplina = document.getElementById('divDisciplina');
        const divStatus = document.getElementById('divStatus');
        
        if (tipo === 'desempenho') {
            divTurma.style.display = 'block';
            divDisciplina.style.display = 'none';
            divStatus.style.display = 'none';
        } else if (tipo === 'notas_disciplina') {
            divTurma.style.display = 'block';
            divDisciplina.style.display = 'block';
            divStatus.style.display = 'none';
        } else if (tipo === 'alunos_status') {
            divTurma.style.display = 'none';
            divDisciplina.style.display = 'none';
            divStatus.style.display = 'block';
        } else if (tipo === 'aproveitamento') {
            divTurma.style.display = 'none';
            divDisciplina.style.display = 'none';
            divStatus.style.display = 'none';
        }
    }
    
    // Validação antes de enviar
    document.getElementById('formRelatorio')?.addEventListener('submit', function(e) {
        const tipo = document.getElementById('tipoRelatorio').value;
        
        if (tipo === 'desempenho') {
            const turma = document.querySelector('select[name="turma_id"]').value;
            if (!turma) {
                e.preventDefault();
                alert('⚠️ Selecione uma turma para gerar o relatório de desempenho.');
                return false;
            }
        }
        
        if (tipo === 'notas_disciplina') {
            const turma = document.querySelector('select[name="turma_id"]').value;
            const disciplina = document.querySelector('select[name="disciplina_id"]').value;
            if (!turma) {
                e.preventDefault();
                alert('⚠️ Selecione uma turma para gerar o relatório de notas por disciplina.');
                return false;
            }
            if (!disciplina) {
                e.preventDefault();
                alert('⚠️ Selecione uma disciplina para gerar o relatório de notas por disciplina.');
                return false;
            }
        }
        
        return true;
    });
</script>
</body>
</html>