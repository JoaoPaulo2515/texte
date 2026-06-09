<?php
// escola/pedagogico/ranking_alunos.php - Ranking dos Alunos

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
$bimestre_filtro = isset($_GET['bimestre']) ? (int)$_GET['bimestre'] : 0;
$limite = isset($_GET['limite']) ? (int)$_GET['limite'] : 50;
$imprimir = isset($_GET['imprimir']) ? (int)$_GET['imprimir'] : 0;
$certificado_id = isset($_GET['certificado_id']) ? (int)$_GET['certificado_id'] : 0;

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
// BUSCAR ANO LETIVO (DEFINIR A VARIÁVEL ANTES DE USAR)
// ============================================
$ano_letivo_ano = '';
if ($ano_letivo_id > 0) {
    $sql_ano = "SELECT ano FROM ano_letivo WHERE id = :id";
    $stmt_ano = $conn->prepare($sql_ano);
    $stmt_ano->execute([':id' => $ano_letivo_id]);
    $ano_let = $stmt_ano->fetch(PDO::FETCH_ASSOC);
    $ano_letivo_ano = $ano_let['ano'] ?? '';
}

// ============================================
// GERAR CERTIFICADO DE MÉRITO
// ============================================
if ($certificado_id > 0) {
    // Buscar dados do aluno
    $sql_aluno = "
        SELECT e.id, e.nome, e.matricula, e.bi, e.data_nascimento, e.genero,
               t.id as turma_id, t.nome as turma_nome, t.ano as turma_ano
        FROM estudantes e
        INNER JOIN matriculas m ON m.estudante_id = e.id
        INNER JOIN turmas t ON t.id = m.turma_id
        WHERE e.id = :aluno_id
        LIMIT 1
    ";
    $stmt_aluno = $conn->prepare($sql_aluno);
    $stmt_aluno->execute([':aluno_id' => $certificado_id]);
    $aluno_cert = $stmt_aluno->fetch(PDO::FETCH_ASSOC);
    
    if (!$aluno_cert) {
        die('Aluno não encontrado');
    }
    
    // Buscar posição do aluno no ranking
    $posicao = 0;
    $ranking_temp = [];
    
    $sql_turmas_escola = "SELECT id FROM turmas WHERE escola_id = :escola_id AND status = 'ativa'";
    $stmt_turmas_escola = $conn->prepare($sql_turmas_escola);
    $stmt_turmas_escola->execute([':escola_id' => $escola_id]);
    $turmas_ids = $stmt_turmas_escola->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($turmas_ids as $tid) {
        $sql_disc = "SELECT d.id, d.nome FROM disciplina_turma dt INNER JOIN disciplinas d ON d.id = dt.disciplina_id WHERE dt.turma_id = :turma_id";
        $stmt_disc = $conn->prepare($sql_disc);
        $stmt_disc->execute([':turma_id' => $tid]);
        $disc_turma = $stmt_disc->fetchAll(PDO::FETCH_ASSOC);
        
        $sql_alunos_turma = "
            SELECT e.id, e.nome
            FROM matriculas m
            INNER JOIN estudantes e ON e.id = m.estudante_id
            WHERE m.turma_id = :turma_id AND m.status = 'ativa' AND m.ano_letivo = :ano_letivo
        ";
        $stmt_alunos_turma = $conn->prepare($sql_alunos_turma);
        $stmt_alunos_turma->execute([':turma_id' => $tid, ':ano_letivo' => $ano_letivo_id]);
        $alunos_turma = $stmt_alunos_turma->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($alunos_turma as $aluno_temp) {
            $soma = 0;
            $count = 0;
            foreach ($disc_turma as $disc) {
                $sql_nota = "SELECT media_final FROM notas WHERE estudante_id = :aluno_id AND disciplina_id = :disc_id AND bimestre = :bimestre AND ano_letivo_id = :ano_letivo";
                $stmt_nota = $conn->prepare($sql_nota);
                $stmt_nota->execute([
                    ':aluno_id' => $aluno_temp['id'],
                    ':disc_id' => $disc['id'],
                    ':bimestre' => $bimestre_filtro > 0 ? $bimestre_filtro : 3,
                    ':ano_letivo' => $ano_letivo_id
                ]);
                $nota = $stmt_nota->fetch(PDO::FETCH_ASSOC);
                if ($nota && $nota['media_final'] > 0) {
                    $soma += $nota['media_final'];
                    $count++;
                }
            }
            $media = $count > 0 ? round($soma / $count, 1) : 0;
            $ranking_temp[] = ['id' => $aluno_temp['id'], 'media' => $media];
        }
    }
    
    usort($ranking_temp, function($a, $b) {
        return $b['media'] <=> $a['media'];
    });
    
    foreach ($ranking_temp as $idx => $r) {
        if ($r['id'] == $certificado_id) {
            $posicao = $idx + 1;
            break;
        }
    }
    
    // Gerar certificado em formato A4 paisagem - VERSÃO FINAL
    $data_atual = date('d/m/Y');
    $ano_letivo_texto = $ano_letivo_ano ?: date('Y');
    
    $html_certificado = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Certificado de Mérito - ' . htmlspecialchars($aluno_cert['nome']) . '</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            @page {
                size: A4 landscape;
                margin: 0;
            }
            
            body {
                font-family: "Times New Roman", Georgia, "Palatino Linotype", serif;
                background: #e8e8e8;
                display: flex;
                justify-content: center;
                align-items: center;
                min-height: 100vh;
                padding: 0;
                margin: 0;
            }
            
            .certificado {
                width: 297mm;
                height: 210mm;
                background: linear-gradient(135deg, #fff8e7 0%, #fff 50%, #fff8f0 100%);
                position: relative;
                padding: 30px 45px;
                text-align: center;
                box-shadow: 0 20px 40px rgba(0,0,0,0.2);
                page-break-after: avoid;
                page-break-inside: avoid;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }
            
            /* Borda que preenche toda a folha */
            .certificado::before {
                content: "";
                position: absolute;
                top: 12px;
                left: 12px;
                right: 12px;
                bottom: 12px;
                border: 3px double #d4af37;
                border-radius: 15px;
                pointer-events: none;
            }
            
            /* Segunda borda decorativa */
            .certificado::after {
                content: "";
                position: absolute;
                top: 6px;
                left: 6px;
                right: 6px;
                bottom: 6px;
                border: 1px solid #d4af37;
                border-radius: 12px;
                pointer-events: none;
            }
            
            /* Fundo decorativo */
            .certificado-bg {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                opacity: 0.03;
                pointer-events: none;
            }
            
            .certificado-bg i {
                position: absolute;
                font-size: 180px;
            }
            
            .certificado-bg i:nth-child(1) { top: 30px; left: 30px; transform: rotate(-15deg); }
            .certificado-bg i:nth-child(2) { bottom: 30px; right: 30px; transform: rotate(15deg); }
            .certificado-bg i:nth-child(3) { top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 250px; }
            
            /* Selo dourado */
            .selo {
                position: absolute;
                top: 50%;
                right: 50px;
                transform: translateY(-50%);
                width: 110px;
                height: 110px;
                border: 3px solid #d4af37;
                border-radius: 50%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                background: linear-gradient(135deg, rgba(212, 175, 55, 0.15), rgba(212, 175, 55, 0.05));
                z-index: 5;
            }
            
            .selo i {
                font-size: 45px;
                color: #d4af37;
            }
            
            .selo span {
                font-size: 10px;
                font-weight: bold;
                color: #b8860b;
                margin-top: 5px;
                text-transform: uppercase;
                letter-spacing: 2px;
            }
            
            /* Logo da escola */
            .logo-escola {
                position: absolute;
                top: 40px;
                left: 50px;
                width: 70px;
                height: 70px;
                background: #1e5799;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 35px;
                box-shadow: 0 3px 10px rgba(0,0,0,0.2);
                z-index: 5;
            }
            
            /* Conteúdo principal */
            .conteudo {
                flex: 1;
                display: flex;
                flex-direction: column;
                justify-content: center;
                position: relative;
                z-index: 10;
            }
            
            /* Títulos */
            .titulo {
                font-size: 42px;
                font-weight: 800;
                color: #d4af37;
                text-transform: uppercase;
                letter-spacing: 8px;
                margin-bottom: 8px;
                text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
            }
            
            .subtitulo {
                font-size: 18px;
                color: #8B7355;
                margin-bottom: 30px;
                border-bottom: 2px solid #d4af37;
                display: inline-block;
                padding-bottom: 8px;
                letter-spacing: 2px;
            }
            
            /* Nome do aluno - EM UMA LINHA ÚNICA */
            .nome-container {
                margin: 20px 0;
                padding: 10px 20px;
                background: linear-gradient(135deg, rgba(30, 87, 153, 0.08), rgba(30, 87, 153, 0.03));
                border-radius: 60px;
                display: inline-block;
                width: auto;
                max-width: 90%;
                margin-left: auto;
                margin-right: auto;
            }
            
            .nome {
                font-size: 38px;
                font-weight: 800;
                color: #1e5799;
                text-transform: uppercase;
                letter-spacing: 3px;
                text-shadow: 1px 1px 2px rgba(0,0,0,0.1);
                white-space: nowrap;
                display: inline-block;
            }
            
            /* Descrição */
            .descricao {
                font-size: 15px;
                color: #333;
                margin: 15px auto;
                line-height: 1.6;
                max-width: 75%;
            }
            
            /* Posição */
            .posicao {
                font-size: 48px;
                font-weight: 800;
                color: #d4af37;
                margin: 15px 0;
            }
            
            .posicao small {
                font-size: 15px;
                color: #666;
            }
            
            /* Informações do aluno */
            .info-aluno {
                margin-top: 15px;
                font-size: 13px;
                color: #555;
                background: rgba(248, 249, 250, 0.9);
                display: inline-block;
                padding: 8px 25px;
                border-radius: 40px;
                margin-left: auto;
                margin-right: auto;
            }
            
            /* Assinaturas */
            .assinatura {
                margin-top: 25px;
                margin-bottom: 15px;
                display: flex;
                justify-content: space-around;
                padding: 0 50px;
            }
            
            .assinatura div {
                text-align: center;
                width: 200px;
            }
            
            .assinatura .linha {
                width: 160px;
                height: 1px;
                background: #333;
                margin: 25px auto 8px;
            }
            
            .assinatura .cargo {
                font-size: 10px;
                color: #777;
                margin-top: 5px;
            }
            
            /* Rodapé */
            .footer {
                text-align: center;
                font-size: 9px;
                color: #aaa;
                padding-top: 10px;
                border-top: 1px solid rgba(212, 175, 55, 0.3);
                margin-top: 10px;
            }
            
            /* Botões de impressão */
            .no-print {
                position: fixed;
                bottom: 20px;
                left: 50%;
                transform: translateX(-50%);
                text-align: center;
                z-index: 1000;
                background: white;
                padding: 8px 15px;
                border-radius: 30px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            }
            
            .no-print button {
                background: #1e5799;
                color: white;
                border: none;
                padding: 6px 15px;
                border-radius: 5px;
                margin: 0 3px;
                cursor: pointer;
                font-size: 12px;
            }
            
            .no-print button:hover {
                opacity: 0.9;
                transform: translateY(-2px);
            }
            
            @media print {
                @page {
                    size: A4 landscape;
                    margin: 0;
                }
                body {
                    background: white;
                    padding: 0;
                    margin: 0;
                }
                .certificado {
                    box-shadow: none;
                    width: 100%;
                    height: 100%;
                    page-break-after: avoid;
                    page-break-inside: avoid;
                }
                .no-print {
                    display: none;
                }
            }
        </style>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    </head>
    <body>
        <div class="certificado">
            <!-- Fundo decorativo -->
            <div class="certificado-bg">
                <i class="fas fa-star-of-life"></i>
                <i class="fas fa-graduation-cap"></i>
                <i class="fas fa-award"></i>
            </div>
            
            <!-- Logo -->
            <div class="logo-escola">
                <i class="fas fa-graduation-cap"></i>
            </div>
            
            <!-- Selo -->
            <div class="selo">
                <i class="fas fa-award"></i>
                <span>MÉRITO</span>
            </div>
            
            <!-- Conteúdo -->
            <div class="conteudo">
                <div class="titulo">CERTIFICADO DE MÉRITO</div>
                <div class="subtitulo">Reconhecimento por Excelência Académica</div>
                
                <div class="nome-container">
                    <div class="nome">' . strtoupper(htmlspecialchars($aluno_cert['nome'])) . '</div>
                </div>
                
                <div class="descricao">
                    Pelo brilhante desempenho académico alcançado no <strong>' . ($bimestre_filtro > 0 ? $bimestre_filtro . 'º Bimestre' : 'Ano Letivo') . '</strong> de <strong>' . $ano_letivo_texto . '</strong>,
                    destacando-se entre os melhores alunos da instituição.
                </div>
                
                <div class="posicao">
                    ' . ($posicao == 1 ? '🥇' : ($posicao == 2 ? '🥈' : '🥉')) . '
                    <br>
                    <strong>' . $posicao . 'º LUGAR</strong>
                    <br>
                    <small>no Ranking Geral</small>
                </div>
                
                <div class="info-aluno">
                    📚 ' . $aluno_cert['turma_ano'] . 'ª ' . htmlspecialchars($aluno_cert['turma_nome']) . ' | 🔢 ' . htmlspecialchars($aluno_cert['matricula']) . ' | 📅 ' . $data_atual . '
                </div>
            </div>
            
            <div class="assinatura">
                <div>
                    <div class="linha"></div>
                    <div>Diretor(a) Pedagógico(a)</div>
                    <div class="cargo">Direção Pedagógica</div>
                </div>
                <div>
                    <div class="linha"></div>
                    <div>Coordenador(a) da Turma</div>
                    <div class="cargo">Coordenação</div>
                </div>
                <div>
                    <div class="linha"></div>
                    <div>Professor(a) Orientador(a)</div>
                    <div class="cargo">Orientação Educacional</div>
                </div>
            </div>
            
            <div class="footer">
                ' . htmlspecialchars($escola['nome']) . ' - ' . htmlspecialchars($escola['endereco'] ?? '') . ' | Tel: ' . htmlspecialchars($escola['telefone'] ?? '') . '
            </div>
        </div>
        
        <div class="no-print">
            <button onclick="window.print()"><i class="fas fa-print"></i> Imprimir Certificado</button>
            <button onclick="window.close()"><i class="fas fa-times"></i> Fechar</button>
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
    
    echo $html_certificado;
    exit;
}

// ============================================
// BUSCAR DADOS PARA O RANKING
// ============================================
$ranking_geral = [];
$ranking_por_turma = [];
$ranking_por_genero = ['masculino' => [], 'feminino' => []];
$estatisticas_ranking = [
    'total_alunos' => 0,
    'media_geral' => 0,
    'melhor_media' => 0,
    'pior_media' => 0,
    'melhor_aluno' => '',
    'pior_aluno' => ''
];

if ($ano_letivo_id > 0) {
    // Buscar todas as turmas da escola
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
    
    $todos_alunos = [];
    $soma_medias_total = 0;
    $total_alunos_com_nota = 0;
    
    foreach ($turmas_escola as $turma) {
        $classe_ano = $turma['ano'];
        $limite_aprovacao = ($classe_ano <= 6) ? 5 : 10;
        $escala_max = ($classe_ano <= 6) ? 10 : 20;
        $is_classe_exame = ($classe_ano == 6 || $classe_ano == 9 || $classe_ano == 12);
        
        // Buscar alunos da turma
        $sql_alunos = "
            SELECT e.id, e.nome, e.matricula, e.genero, e.bi, e.data_nascimento, e.foto
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
            SELECT d.id, d.nome,
                   CASE WHEN d.nome LIKE '%português%' OR d.nome LIKE '%inglês%' THEN 1 ELSE 0 END as is_lingua
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
            $disciplinas_notas = [];
            
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
                        $disciplinas_notas[] = [
                            'nome' => $disc['nome'],
                            'nota' => $media
                        ];
                    }
                }
            }
            
            $media_geral = $count_notas > 0 ? round($soma_notas / $count_notas, 1) : 0;
            
            if ($media_geral > 0) {
                $soma_medias_total += $media_geral;
                $total_alunos_com_nota++;
                
                if ($media_geral > $estatisticas_ranking['melhor_media']) {
                    $estatisticas_ranking['melhor_media'] = $media_geral;
                    $estatisticas_ranking['melhor_aluno'] = $aluno['nome'];
                }
                if ($estatisticas_ranking['pior_media'] == 0 || $media_geral < $estatisticas_ranking['pior_media']) {
                    $estatisticas_ranking['pior_media'] = $media_geral;
                    $estatisticas_ranking['pior_aluno'] = $aluno['nome'];
                }
            }
            
            $status = 'pendente';
            if ($media_geral >= $limite_aprovacao) {
                $status = 'aprovado';
            } elseif ($media_geral >= $limite_aprovacao * 0.7) {
                $status = 'recuperacao';
            } elseif ($media_geral > 0) {
                $status = 'reprovado';
            }
            
            $aluno_info = [
                'id' => $aluno['id'],
                'nome' => $aluno['nome'],
                'matricula' => $aluno['matricula'],
                'bi' => $aluno['bi'],
                'genero' => $aluno['genero'],
                'data_nascimento' => $aluno['data_nascimento'],
                'foto' => $aluno['foto'],
                'turma_id' => $turma['id'],
                'turma_nome' => $turma['nome'],
                'turma_ano' => $turma['ano'],
                'turno' => $turma['turno_nome'],
                'media' => $media_geral,
                'status' => $status,
                'disciplinas' => $disciplinas_notas
            ];
            
            $todos_alunos[] = $aluno_info;
            
            // Adicionar ao ranking por turma
            if (!isset($ranking_por_turma[$turma['id']])) {
                $ranking_por_turma[$turma['id']] = [
                    'turma_nome' => $turma['nome'],
                    'turma_ano' => $turma['ano'],
                    'turno' => $turma['turno_nome'],
                    'alunos' => []
                ];
            }
            $ranking_por_turma[$turma['id']]['alunos'][] = $aluno_info;
            
            // Adicionar ao ranking por gênero
            $genero = strtolower($aluno['genero'] ?? '');
            if ($genero == 'masculino' || $genero == 'm' || $genero == 'male') {
                $ranking_por_genero['masculino'][] = $aluno_info;
            } elseif ($genero == 'feminino' || $genero == 'f' || $genero == 'female') {
                $ranking_por_genero['feminino'][] = $aluno_info;
            }
        }
    }
    
    // Ordenar ranking geral por média (decrescente)
    usort($todos_alunos, function($a, $b) {
        return $b['media'] <=> $a['media'];
    });
    $ranking_geral = array_slice($todos_alunos, 0, $limite);
    
    // Ordenar ranking por turma
    foreach ($ranking_por_turma as $key => $turma_ranking) {
        usort($ranking_por_turma[$key]['alunos'], function($a, $b) {
            return $b['media'] <=> $a['media'];
        });
        $ranking_por_turma[$key]['alunos'] = array_slice($ranking_por_turma[$key]['alunos'], 0, $limite);
    }
    
    // Ordenar ranking por gênero
    foreach ($ranking_por_genero as $key => $genero_ranking) {
        usort($ranking_por_genero[$key], function($a, $b) {
            return $b['media'] <=> $a['media'];
        });
        $ranking_por_genero[$key] = array_slice($ranking_por_genero[$key], 0, $limite);
    }
    
    $estatisticas_ranking['total_alunos'] = count($todos_alunos);
    $estatisticas_ranking['media_geral'] = $total_alunos_com_nota > 0 ? round($soma_medias_total / $total_alunos_com_nota, 1) : 0;
}

$caminho_base = '/sige_Plataforma/uploads/alunos/';
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ranking dos Alunos - SIGE Angola</title>
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
        
        .table-ranking { width: 100%; border-collapse: collapse; }
        .table-ranking th { background: #f8f9fa; padding: 12px; text-align: center; border-bottom: 2px solid #1e5799; font-size: 12px; }
        .table-ranking td { padding: 10px; border-bottom: 1px solid #ecf0f1; text-align: center; vertical-align: middle; }
        .table-ranking tr:hover { background: #f8f9fa; }
        
        .ranking-pos { font-weight: bold; font-size: 18px; }
        .medalha-ouro { color: #ffd700; }
        .medalha-prata { color: #c0c0c0; }
        .medalha-bronze { color: #cd7f32; }
        
        .badge-aprovado { background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 12px; font-size: 11px; }
        .badge-recuperacao { background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 12px; font-size: 11px; }
        .badge-reprovado { background: #f8d7da; color: #721c24; padding: 4px 8px; border-radius: 12px; font-size: 11px; }
        
        .chart-container { position: relative; height: 300px; margin-bottom: 20px; }
        .btn-certificado { background: linear-gradient(135deg, #d4af37, #b8860b); color: white; border: none; padding: 4px 10px; border-radius: 20px; font-size: 11px; cursor: pointer; transition: all 0.3s ease; }
        .btn-certificado:hover { transform: translateY(-2px); box-shadow: 0 3px 10px rgba(212, 175, 55, 0.3); }
        
        .nav-tabs .nav-link { color: #2c3e50; font-weight: 600; }
        .nav-tabs .nav-link.active { background: #1e5799; color: white; border-color: #1e5799; }
        
        .modal-detalhes .modal-header { background: linear-gradient(135deg, #1e5799, #2c3e50); color: white; }
        .disciplina-item { background: #f8f9fa; border-radius: 8px; padding: 10px; margin-bottom: 8px; border-left: 4px solid #1e5799; }
        .nota-alta { color: #27ae60; font-weight: bold; }
        .nota-baixa { color: #e74c3c; font-weight: bold; }
        
        @media (max-width: 768px) {
            .filtros-row { flex-direction: column; }
            .filtro-group { width: 100%; }
            .table-ranking { font-size: 11px; }
            .table-ranking th, .table-ranking td { padding: 6px; }
            .chart-container { height: 250px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <h1><i class="fas fa-trophy"></i> Ranking dos Alunos</h1>
            <p>Classificação geral dos alunos por desempenho académico</p>
        </div>
        <div>
            <a href="index.php" class="btn-voltar">← Voltar</a>
            <a href="?ano_letivo_id=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre_filtro; ?>&limite=<?php echo $limite; ?>&imprimir=1" class="btn-pdf" target="_blank">
                <i class="fas fa-file-pdf"></i> Baixar PDF
            </a>
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
                        <label>Bimestre</label>
                        <select name="bimestre" class="filtro-select">
                            <option value="0">Média Final</option>
                            <option value="1" <?php echo ($bimestre_filtro == 1) ? 'selected' : ''; ?>>1º Bimestre</option>
                            <option value="2" <?php echo ($bimestre_filtro == 2) ? 'selected' : ''; ?>>2º Bimestre</option>
                            <option value="3" <?php echo ($bimestre_filtro == 3) ? 'selected' : ''; ?>>3º Bimestre</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <label>Limite de Alunos</label>
                        <select name="limite" class="filtro-select">
                            <option value="10" <?php echo ($limite == 10) ? 'selected' : ''; ?>>Top 10</option>
                            <option value="20" <?php echo ($limite == 20) ? 'selected' : ''; ?>>Top 20</option>
                            <option value="30" <?php echo ($limite == 30) ? 'selected' : ''; ?>>Top 30</option>
                            <option value="50" <?php echo ($limite == 50) ? 'selected' : ''; ?>>Top 50</option>
                            <option value="100" <?php echo ($limite == 100) ? 'selected' : ''; ?>>Top 100</option>
                            <option value="9999" <?php echo ($limite == 9999) ? 'selected' : ''; ?>>Todos</option>
                        </select>
                    </div>
                    <div class="filtro-group">
                        <button type="submit" class="btn-filtrar">Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Cards de Estatísticas -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: #1e5799;"><?php echo $estatisticas_ranking['total_alunos']; ?></div>
                <div class="stat-label">Total de Alunos</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: #27ae60;"><?php echo number_format($estatisticas_ranking['media_geral'], 1); ?></div>
                <div class="stat-label">Média Geral</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: #ffd700;"><?php echo number_format($estatisticas_ranking['melhor_media'], 1); ?></div>
                <div class="stat-label">Melhor Média</div>
                <small><?php echo htmlspecialchars($estatisticas_ranking['melhor_aluno']); ?></small>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card">
                <div class="stat-number" style="color: #e74c3c;"><?php echo number_format($estatisticas_ranking['pior_media'], 1); ?></div>
                <div class="stat-label">Pior Média</div>
                <small><?php echo htmlspecialchars($estatisticas_ranking['pior_aluno']); ?></small>
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
                    <div class="chart-container">
                        <canvas id="graficoStatus"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-bar"></i> Distribuição das Notas
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="graficoDistribuicao"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Abas para os diferentes rankings -->
    <ul class="nav nav-tabs mb-3" id="rankingTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="geral-tab" data-bs-toggle="tab" data-bs-target="#geral" type="button" role="tab">
                <i class="fas fa-trophy"></i> Ranking Geral
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="turmas-tab" data-bs-toggle="tab" data-bs-target="#turmas" type="button" role="tab">
                <i class="fas fa-building"></i> Por Turma
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="genero-tab" data-bs-toggle="tab" data-bs-target="#genero" type="button" role="tab">
                <i class="fas fa-venus-mars"></i> Por Gênero
            </button>
        </li>
    </ul>
    
    <div class="tab-content">
        <!-- Ranking Geral -->
        <div class="tab-pane fade show active" id="geral" role="tabpanel">
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-trophy"></i> Top <?php echo $limite; ?> Alunos - Ranking Geral
                    <span class="badge bg-light text-dark ms-2"><?php echo count($ranking_geral); ?> alunos</span>
                </div>
                <div class="card-body" style="padding: 0;">
                    <div class="table-responsive">
                        <table class="table-ranking">
                            <thead>
                                <tr>
                                    <th>Pos</th>
                                    <th>Aluno</th>
                                    <th>Matrícula</th>
                                    <th>Turma</th>
                                    <th>Média</th>
                                    <th>Status</th>
                                    <th>Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($ranking_geral as $index => $aluno): 
                                    $medalha = '';
                                    $posicao = $index + 1;
                                    if ($posicao == 1) $medalha = '<i class="fas fa-crown medalha-ouro"></i> ';
                                    elseif ($posicao == 2) $medalha = '<i class="fas fa-medal medalha-prata"></i> ';
                                    elseif ($posicao == 3) $medalha = '<i class="fas fa-medal medalha-bronze"></i> ';
                                    
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
                                    
                                    $genero_icon = ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M') ? '♂' : '♀';
                                ?>
                                    <tr>
                                        <td><span class="ranking-pos"><?php echo $medalha . $posicao; ?>º</span></td>
                                        <td class="text-start">
                                            <?php echo $genero_icon; ?> <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                        <td><?php echo $aluno['turma_ano']; ?>ª - <?php echo htmlspecialchars($aluno['turma_nome']); ?> (<?php echo ucfirst($aluno['turno'] ?? ''); ?>)</td>
                                        <td><strong><?php echo number_format($aluno['media'], 1); ?></strong> / <?php echo ($aluno['turma_ano'] <= 6 ? 10 : 20); ?></td>
                                        <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                        <td>
                                            <button class="btn btn-sm btn-info me-1" onclick="verDetalhesAluno(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>', '<?php echo addslashes($aluno['matricula']); ?>', '<?php echo addslashes($aluno['turma_nome']); ?>', <?php echo $aluno['turma_ano']; ?>, <?php echo json_encode($aluno['disciplinas']); ?>)">
                                                <i class="fas fa-eye"></i> Ver
                                            </button>
                                            <?php if ($posicao <= 3): ?>
                                                <button class="btn-certificado" onclick="gerarCertificado(<?php echo $aluno['id']; ?>)">
                                                    <i class="fas fa-award"></i> Certificado
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($ranking_geral)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhum aluno encontrado</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Ranking por Turma -->
        <div class="tab-pane fade" id="turmas" role="tabpanel">
            <?php foreach ($ranking_por_turma as $turma_id => $turma_ranking): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <i class="fas fa-building"></i> Turma <?php echo $turma_ranking['turma_ano']; ?>ª - <?php echo htmlspecialchars($turma_ranking['turma_nome']); ?> (<?php echo ucfirst($turma_ranking['turno'] ?? ''); ?>)
                    </div>
                    <div class="card-body" style="padding: 0;">
                        <div class="table-responsive">
                            <table class="table-ranking">
                                <thead>
                                    <tr>
                                        <th>Pos</th>
                                        <th>Aluno</th>
                                        <th>Matrícula</th>
                                        <th>Média</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($turma_ranking['alunos'] as $index => $aluno): 
                                        $posicao = $index + 1;
                                        $status_class = $aluno['status'] == 'aprovado' ? 'badge-aprovado' : ($aluno['status'] == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado');
                                        $status_text = $aluno['status'] == 'aprovado' ? 'Aprovado' : ($aluno['status'] == 'recuperacao' ? 'Recuperação' : 'Reprovado');
                                        $genero_icon = ($aluno['genero'] == 'Masculino' || $aluno['genero'] == 'masculino' || $aluno['genero'] == 'M') ? '♂' : '♀';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo $posicao; ?>º</strong></td>
                                            <td class="text-start"><?php echo $genero_icon; ?> <strong><?php echo htmlspecialchars($aluno['nome']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($aluno['matricula']); ?></td>
                                            <td><strong><?php echo number_format($aluno['media'], 1); ?></strong> / <?php echo ($turma_ranking['turma_ano'] <= 6 ? 10 : 20); ?></td>
                                            <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                            <td>
                                                <button class="btn btn-sm btn-info" onclick="verDetalhesAluno(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>', '<?php echo addslashes($aluno['matricula']); ?>', '<?php echo addslashes($aluno['turma_nome']); ?>', <?php echo $turma_ranking['turma_ano']; ?>, <?php echo json_encode($aluno['disciplinas']); ?>)">
                                                    <i class="fas fa-eye"></i> Ver
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($ranking_por_turma)): ?>
                <div class="alert alert-info">Nenhuma turma encontrada</div>
            <?php endif; ?>
        </div>
        
        <!-- Ranking por Gênero -->
        <div class="tab-pane fade" id="genero" role="tabpanel">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-mars"></i> Ranking Masculino
                        </div>
                        <div class="card-body" style="padding: 0;">
                            <div class="table-responsive">
                                <table class="table-ranking">
                                    <thead><tr><th>Pos</th><th>Aluno</th><th>Turma</th><th>Média</th><th>Status</th><th>Ações</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($ranking_por_genero['masculino'] as $index => $aluno): 
                                            $posicao = $index + 1;
                                            $status_class = $aluno['status'] == 'aprovado' ? 'badge-aprovado' : ($aluno['status'] == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado');
                                            $status_text = $aluno['status'] == 'aprovado' ? 'Aprovado' : ($aluno['status'] == 'recuperacao' ? 'Recuperação' : 'Reprovado');
                                        ?>
                                            <tr>
                                                <td><strong><?php echo $posicao; ?>º</strong></td>
                                                <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                                <td><?php echo $aluno['turma_ano']; ?>ª <?php echo htmlspecialchars($aluno['turma_nome']); ?></td>
                                                <td><strong><?php echo number_format($aluno['media'], 1); ?></strong> / <?php echo ($aluno['turma_ano'] <= 6 ? 10 : 20); ?></td>
                                                <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" onclick="verDetalhesAluno(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>', '<?php echo addslashes($aluno['matricula']); ?>', '<?php echo addslashes($aluno['turma_nome']); ?>', <?php echo $aluno['turma_ano']; ?>, <?php echo json_encode($aluno['disciplinas']); ?>)">
                                                        <i class="fas fa-eye"></i> Ver
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($ranking_por_genero['masculino'])): ?>
                                            <tr><td colspan="6" class="text-center">Nenhum aluno masculino encontrado</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-venus"></i> Ranking Feminino
                        </div>
                        <div class="card-body" style="padding: 0;">
                            <div class="table-responsive">
                                <table class="table-ranking">
                                    <thead><tr><th>Pos</th><th>Aluno</th><th>Turma</th><th>Média</th><th>Status</th><th>Ações</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($ranking_por_genero['feminino'] as $index => $aluno): 
                                            $posicao = $index + 1;
                                            $status_class = $aluno['status'] == 'aprovado' ? 'badge-aprovado' : ($aluno['status'] == 'recuperacao' ? 'badge-recuperacao' : 'badge-reprovado');
                                            $status_text = $aluno['status'] == 'aprovado' ? 'Aprovado' : ($aluno['status'] == 'recuperacao' ? 'Recuperação' : 'Reprovado');
                                        ?>
                                            <tr>
                                                <td><strong><?php echo $posicao; ?>º</strong></td>
                                                <td><?php echo htmlspecialchars($aluno['nome']); ?></td>
                                                <td><?php echo $aluno['turma_ano']; ?>ª <?php echo htmlspecialchars($aluno['turma_nome']); ?></td>
                                                <td><strong><?php echo number_format($aluno['media'], 1); ?></strong> / <?php echo ($aluno['turma_ano'] <= 6 ? 10 : 20); ?></td>
                                                <td><span class="<?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                                <td>
                                                    <button class="btn btn-sm btn-info" onclick="verDetalhesAluno(<?php echo $aluno['id']; ?>, '<?php echo addslashes($aluno['nome']); ?>', '<?php echo addslashes($aluno['matricula']); ?>', '<?php echo addslashes($aluno['turma_nome']); ?>', <?php echo $aluno['turma_ano']; ?>, <?php echo json_encode($aluno['disciplinas']); ?>)">
                                                        <i class="fas fa-eye"></i> Ver
                                                    </button>
                                                </td>
                                            <tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($ranking_por_genero['feminino'])): ?>
                                            <tr><td colspan="6" class="text-center">Nenhuma aluna encontrada</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Detalhes do Aluno -->
<div class="modal fade modal-detalhes" id="modalDetalhesAluno" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-graduate"></i> Detalhes do Aluno</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDetalhesBody">
                <div class="text-center py-4">
                    <i class="fas fa-spinner fa-spin fa-2x"></i>
                    <p>Carregando...</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-certificado" id="btnCertificadoModal" style="display: none;" onclick="gerarCertificadoFromModal()">
                    <i class="fas fa-award"></i> Emitir Certificado de Mérito
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    let alunoAtualModal = null;
    
    // Gráfico de Status
    const statusData = {
        aprovados: <?php 
            $aprovados = 0;
            foreach ($ranking_geral as $aluno) {
                if ($aluno['status'] == 'aprovado') $aprovados++;
            }
            echo $aprovados;
        ?>,
        recuperacao: <?php 
            $recuperacao = 0;
            foreach ($ranking_geral as $aluno) {
                if ($aluno['status'] == 'recuperacao') $recuperacao++;
            }
            echo $recuperacao;
        ?>,
        reprovados: <?php 
            $reprovados = 0;
            foreach ($ranking_geral as $aluno) {
                if ($aluno['status'] == 'reprovado') $reprovados++;
            }
            echo $reprovados;
        ?>
    };
    
    new Chart(document.getElementById('graficoStatus'), {
        type: 'doughnut',
        data: {
            labels: ['Aprovados', 'Recuperação', 'Reprovados'],
            datasets: [{
                data: [statusData.aprovados, statusData.recuperacao, statusData.reprovados],
                backgroundColor: ['#28a745', '#ffc107', '#dc3545'],
                borderWidth: 0
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
    
    // Distribuição de Notas
    const medias = <?php 
        $medias = [];
        foreach ($ranking_geral as $aluno) {
            if ($aluno['media'] > 0) {
                $medias[] = $aluno['media'];
            }
        }
        echo json_encode($medias);
    ?>;
    
    const bins = [0, 2, 4, 6, 8, 10, 12, 14, 16, 18, 20];
    const counts = new Array(bins.length - 1).fill(0);
    medias.forEach(media => {
        for (let i = 0; i < bins.length - 1; i++) {
            if (media >= bins[i] && media < bins[i + 1]) {
                counts[i]++;
                break;
            }
        }
    });
    
    new Chart(document.getElementById('graficoDistribuicao'), {
        type: 'bar',
        data: {
            labels: bins.slice(0, -1).map((v, i) => `${v}-${bins[i+1]}`),
            datasets: [{ label: 'Alunos', data: counts, backgroundColor: '#1e5799', borderRadius: 5 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, title: { display: true, text: 'Número de Alunos' } } } }
    });
    
    function verDetalhesAluno(id, nome, matricula, turmaNome, turmaAno, disciplinas) {
        alunoAtualModal = { id: id, nome: nome };
        
        const modalBody = document.getElementById('modalDetalhesBody');
        const btnCertificado = document.getElementById('btnCertificadoModal');
        
        let disciplinasHtml = '';
        const limiteAprovacao = turmaAno <= 6 ? 5 : 10;
        
        if (disciplinas && disciplinas.length > 0) {
            disciplinas.sort((a, b) => b.nota - a.nota);
            disciplinas.forEach(disc => {
                const notaClass = disc.nota >= limiteAprovacao ? 'nota-alta' : (disc.nota > 0 ? 'nota-baixa' : '');
                const statusDisc = disc.nota >= limiteAprovacao ? '✓ Aprovado' : (disc.nota >= limiteAprovacao * 0.7 ? '⚠️ Recuperação' : (disc.nota > 0 ? '✗ Reprovado' : '— Sem nota'));
                disciplinasHtml += `
                    <div class="disciplina-item d-flex justify-content-between align-items-center">
                        <div><strong>${disc.nome}</strong></div>
                        <div><span class="${notaClass}">${disc.nota.toFixed(1)}</span> / ${turmaAno <= 6 ? 10 : 20}</div>
                        <div><small class="text-muted">${statusDisc}</small></div>
                    </div>
                `;
            });
        } else {
            disciplinasHtml = '<p class="text-muted text-center">Nenhuma disciplina encontrada</p>';
        }
        
        modalBody.innerHTML = `
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="info-card p-3 bg-light rounded">
                        <p><strong><i class="fas fa-user-graduate"></i> Nome:</strong> ${nome}</p>
                        <p><strong><i class="fas fa-id-card"></i> Matrícula:</strong> ${matricula}</p>
                        <p><strong><i class="fas fa-building"></i> Turma:</strong> ${turmaAno}ª - ${turmaNome}</p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-card p-3 bg-light rounded">
                        <p><strong><i class="fas fa-chart-line"></i> Escala de Avaliação:</strong> 0-${turmaAno <= 6 ? 10 : 20}</p>
                        <p><strong><i class="fas fa-flag-checkered"></i> Mínimo para Aprovação:</strong> ${limiteAprovacao} pontos</p>
                        <p><strong><i class="fas fa-calendar-alt"></i> Ano Letivo:</strong> <?php echo $ano_letivo_ano; ?></p>
                    </div>
                </div>
            </div>
            <h6 class="mt-3"><i class="fas fa-book-open"></i> Desempenho por Disciplina</h6>
            ${disciplinasHtml}
        `;
        
        // Verificar se o aluno está entre os 3 primeiros colocados
        <?php 
        $posicoes_top3 = [];
        foreach ($ranking_geral as $idx => $a) {
            if ($idx < 3) {
                $posicoes_top3[] = $a['id'];
            }
        }
        ?>
        const top3Ids = <?php echo json_encode($posicoes_top3); ?>;
        
        if (top3Ids.includes(id)) {
            btnCertificado.style.display = 'inline-block';
        } else {
            btnCertificado.style.display = 'none';
        }
        
        new bootstrap.Modal(document.getElementById('modalDetalhesAluno')).show();
    }
    
    function gerarCertificado(alunoId) {
        window.open('?ano_letivo_id=<?php echo $ano_letivo_id; ?>&bimestre=<?php echo $bimestre_filtro; ?>&certificado_id=' + alunoId, '_blank');
    }
    
    function gerarCertificadoFromModal() {
        if (alunoAtualModal) {
            gerarCertificado(alunoAtualModal.id);
        }
    }
    
    document.querySelector('select[name="ano_letivo_id"]')?.addEventListener('change', function() {
        document.getElementById('formFiltros').submit();
    });
</script>
</body>
</html>