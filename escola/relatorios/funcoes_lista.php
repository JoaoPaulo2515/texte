<?php
// includes/funcoes_lista.php - Funções compartilhadas para lista nominal

function buscarDadosLista($conn, $escola_id, $turma_id) {
    // Buscar informações da turma
    $sql_turma_info = "SELECT nome, ano, turno, sala, capacidade FROM turmas WHERE id = :id";
    $stmt_turma_info = $conn->prepare($sql_turma_info);
    $stmt_turma_info->execute([':id' => $turma_id]);
    $turma_info = $stmt_turma_info->fetch(PDO::FETCH_ASSOC);
    
    // Buscar alunos da turma
    $sql_alunos = "
        SELECT 
            e.id,
            e.nome,
            e.matricula,
            e.data_nascimento,
            e.genero,
            e.bi,
            e.pai_nome,
            e.mae_nome,
            e.telefone,
            e.email,
            e.endereco,
            e.foto,
            m.data_matricula,
            m.status as matricula_status
        FROM matriculas m
        INNER JOIN estudantes e ON e.id = m.estudante_id
        WHERE m.turma_id = :turma_id AND m.status = 'ativa'
        ORDER BY e.nome
    ";
    $stmt_alunos = $conn->prepare($sql_alunos);
    $stmt_alunos->execute([':turma_id' => $turma_id]);
    $alunos = $stmt_alunos->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular estatísticas
    $estatisticas = [
        'total' => count($alunos),
        'masculino' => 0,
        'feminino' => 0,
        'data_nascimento' => [],
        'idades' => []
    ];
    
    $soma_idades = 0;
    
    foreach ($alunos as $aluno) {
        if ($aluno['genero'] == 'masculino') {
            $estatisticas['masculino']++;
        } elseif ($aluno['genero'] == 'feminino') {
            $estatisticas['feminino']++;
        }
        
        if (!empty($aluno['data_nascimento'])) {
            $data_nasc = new DateTime($aluno['data_nascimento']);
            $hoje = new DateTime();
            $idade = $data_nasc->diff($hoje)->y;
            $soma_idades += $idade;
            $estatisticas['idades'][] = $idade;
            
            $mes_nasc = $data_nasc->format('m');
            if (!isset($estatisticas['data_nascimento'][$mes_nasc])) {
                $estatisticas['data_nascimento'][$mes_nasc] = 0;
            }
            $estatisticas['data_nascimento'][$mes_nasc]++;
        }
    }
    
    $estatisticas['idade_media'] = $estatisticas['total'] > 0 ? round($soma_idades / $estatisticas['total'], 1) : 0;
    ksort($estatisticas['data_nascimento']);
    $estatisticas['menor_idade'] = !empty($estatisticas['idades']) ? min($estatisticas['idades']) : 0;
    $estatisticas['maior_idade'] = !empty($estatisticas['idades']) ? max($estatisticas['idades']) : 0;
    
    return [
        'turma_info' => $turma_info,
        'alunos' => $alunos,
        'estatisticas' => $estatisticas
    ];
}

function buscarDadosEscola($conn, $escola_id) {
    $sql_escola = "SELECT nome, endereco, telefone, email, logo FROM escolas WHERE id = :id";
    $stmt_escola = $conn->prepare($sql_escola);
    $stmt_escola->execute([':id' => $escola_id]);
    return $stmt_escola->fetch(PDO::FETCH_ASSOC);
}

function gerarHTMLLista($alunos, $turma_info, $escola_info, $estatisticas, $tipo_lista) {
    $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Lista Nominal - ' . htmlspecialchars($turma_info['nome']) . '</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: Arial, Helvetica, sans-serif; 
                padding: 20px;
                background: white;
            }
            .header { text-align: center; margin-bottom: 30px; }
            .header h1 { font-size: 22px; margin-bottom: 5px; color: #006B3E; }
            .header h2 { font-size: 16px; font-weight: normal; margin-bottom: 5px; }
            .header p { font-size: 12px; color: #666; }
            .info-turma { 
                background: #f5f5f5; 
                padding: 10px; 
                margin-bottom: 20px;
                border-left: 4px solid #006B3E;
            }
            .estatisticas { 
                display: flex; 
                gap: 15px; 
                margin-bottom: 20px;
                flex-wrap: wrap;
            }
            .card-estatistica {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 10px 15px;
                min-width: 100px;
                text-align: center;
            }
            .card-estatistica .numero { font-size: 20px; font-weight: bold; color: #006B3E; }
            .card-estatistica .label { font-size: 11px; color: #666; }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                margin-top: 20px;
                font-size: 11px;
            }
            th, td { 
                border: 1px solid #ddd; 
                padding: 8px; 
                text-align: left; 
                vertical-align: top;
            }
            th { 
                background: #006B3E; 
                color: white;
                font-weight: bold;
            }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 10px;
                color: #666;
                border-top: 1px solid #ddd;
                padding-top: 15px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>' . htmlspecialchars($escola_info['nome']) . '</h1>
            <h2>Lista Nominal de Alunos</h2>
            <p>Gerado em: ' . date('d/m/Y H:i:s') . '</p>
        </div>
        
        <div class="info-turma">
            <strong>Turma:</strong> ' . $turma_info['ano'] . 'ª ' . htmlspecialchars($turma_info['nome']) . ' - ' . ucfirst($turma_info['turno']) . '<br>
            <strong>Sala:</strong> ' . htmlspecialchars($turma_info['sala']) . ' | <strong>Vagas:</strong> ' . $turma_info['capacidade'] . '
        </div>';
    
    // Estatísticas da turma
    $html .= '
        <div class="estatisticas">
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas['total'] . '</div>
                <div class="label">Total Alunos</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas['masculino'] . '</div>
                <div class="label">Masculino</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas['feminino'] . '</div>
                <div class="label">Feminino</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas['idade_media'] . '</div>
                <div class="label">Idade Média</div>
            </div>
            <div class="card-estatistica">
                <div class="numero">' . $estatisticas['menor_idade'] . ' - ' . $estatisticas['maior_idade'] . '</div>
                <div class="label">Faixa Etária</div>
            </div>
        </div>';
    
    // Tabela de alunos
    $html .= '<table>
        <thead>
            <tr>
                <th width="5%">#</th>
                <th width="12%">Matrícula</th>
                <th width="25%">Nome Completo</th>
                <th width="8%">Genero</th>
                <th width="10%">Data Nasc.</th>
                <th width="12%">BI</th>
                <th width="15%">Nome do Pai</th>
                <th width="13%">Nome da Mãe</th>
            </tr>
        </thead>
        <tbody>';
    
    foreach ($alunos as $index => $aluno) {
        $data_nasc = $aluno['data_nascimento'] ? date('d/m/Y', strtotime($aluno['data_nascimento'])) : '---';
        $sexo = $aluno['genero'] == 'masculino' ? 'M' : ($aluno['genero'] == 'feminino' ? 'F' : '---');
        
        $html .= '<tr>
            <td>' . ($index + 1) . '</td>
            <td>' . htmlspecialchars($aluno['matricula']) . '</td>
            <td>' . htmlspecialchars($aluno['nome']) . '</td>
            <td>' . $sexo . '</td>
            <td>' . $data_nasc . '</td>
            <td>' . htmlspecialchars($aluno['bi']) . '</td>
            <td>' . htmlspecialchars($aluno['pai_nome']) . '</td>
            <td>' . htmlspecialchars($aluno['mae_nome']) . '</td>
        </tr>';
    }
    
    $html .= '</tbody>
    </table>
    
    <div class="footer">
        <p>Documento gerado pelo Sistema Integrado de Gestão Escolar (SIGE) - Angola</p>
        <p>' . htmlspecialchars($escola_info['endereco']) . ' | Tel: ' . htmlspecialchars($escola_info['telefone']) . ' | Email: ' . htmlspecialchars($escola_info['email']) . '</p>
    </div>
    </body>
    </html>';
    
    return $html;
}
?>