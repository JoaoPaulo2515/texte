<?php
// escola/secretaria/certificados_modelos.php - Modelos de Certificados

function getModeloCertificado($tipo, $dados) {
    $modelos = [
        'conclusao' => [
            'titulo' => 'CERTIFICADO DE CONCLUSÃO',
            'cor' => '#006B3E',
            'texto' => "Certificamos que <strong>{NOME_ALUNO}</strong>, 
                        concluiu com aproveitamento o Curso de <strong>Ensino Médio</strong> 
                        na <strong>{NOME_ESCOLA}</strong>, 
                        no ano letivo de <strong>{ANO}</strong>.",
            'icone' => 'fa-graduation-cap'
        ],
        'frequencia' => [
            'titulo' => 'CERTIFICADO DE FREQUÊNCIA',
            'cor' => '#17a2b8',
            'texto' => "Certificamos que <strong>{NOME_ALUNO}</strong>, 
                        frequentou regularmente o <strong>{ANO}º Ano</strong> 
                        na <strong>{NOME_ESCOLA}</strong>, 
                        durante o ano letivo de <strong>{ANO_ATUAL}</strong>.",
            'icone' => 'fa-calendar-check'
        ],
        'aproveitamento' => [
            'titulo' => 'CERTIFICADO DE APROVEITAMENTO',
            'cor' => '#28a745',
            'texto' => "Certificamos que <strong>{NOME_ALUNO}</strong>, 
                        obteve excelente aproveitamento acadêmico durante o 
                        <strong>{ANO}º Ano</strong> na <strong>{NOME_ESCOLA}</strong>.",
            'icone' => 'fa-chart-line'
        ],
        'participacao' => [
            'titulo' => 'CERTIFICADO DE PARTICIPAÇÃO',
            'cor' => '#ffc107',
            'texto' => "Certificamos que <strong>{NOME_ALUNO}</strong>, 
                        participou ativamente das atividades e eventos promovidos 
                        pela <strong>{NOME_ESCOLA}</strong> 
                        durante o ano letivo de <strong>{ANO_ATUAL}</strong>.",
            'icone' => 'fa-users'
        ],
        'estagio' => [
            'titulo' => 'CERTIFICADO DE ESTÁGIO',
            'cor' => '#6c757d',
            'texto' => "Certificamos que <strong>{NOME_ALUNO}</strong>, 
                        realizou estágio curricular na <strong>{NOME_ESCOLA}</strong>, 
                        cumprindo a carga horária estabelecida com dedicação e comprometimento.",
            'icone' => 'fa-briefcase'
        ]
    ];
    
    return $modelos[$tipo] ?? $modelos['conclusao'];
}

// Função para gerar HTML do certificado
function gerarHTMLCertificado($cert, $escola, $tipo) {
    $modelo = getModeloCertificado($tipo, $cert);
    
    $replace = [
        '{NOME_ALUNO}' => htmlspecialchars($cert['aluno_nome']),
        '{NOME_ESCOLA}' => htmlspecialchars($escola['nome']),
        '{ANO}' => $cert['turma_ano'] ?? date('Y'),
        '{ANO_ATUAL}' => date('Y'),
        '{NUMERO_CERTIFICADO}' => htmlspecialchars($cert['numero_certificado']),
        '{DATA_EMISSAO}' => date('d/m/Y', strtotime($cert['data_emissao'])),
        '{ASSINADO_POR}' => htmlspecialchars($cert['assinado_por'] ?? 'Diretor(a)')
    ];
    
    $texto = str_replace(array_keys($replace), array_values($replace), $modelo['texto']);
    
    $logo_html = '';
    if ($escola['logo'] && file_exists('../../uploads/escolas/logos/' . $escola['logo'])) {
        $logo_html = '<img src="../../uploads/escolas/logos/' . $escola['logo'] . '" style="max-height: 100px; margin-bottom: 20px;">';
    }
    
    return "
    <div style='font-family: \"Times New Roman\", Times, serif;'>
        <div style='text-align: center; padding: 40px; border: 2px solid {$modelo['cor']}; border-radius: 15px; background: white;'>
            $logo_html
            <hr style='border: 1px solid {$modelo['cor']}; width: 100px;'>
            <h1 style='color: {$modelo['cor']}; font-size: 28px; text-transform: uppercase; margin: 20px 0;'>{$modelo['titulo']}</h1>
            <hr style='border: 1px solid {$modelo['cor']}; width: 100px;'>
            
            <div style='text-align: left; margin: 40px 30px; font-size: 16px; line-height: 1.8;'>
                $texto
            </div>
            
            <div style='margin-top: 30px;'>
                <p><strong>Número do Certificado:</strong> {$replace['{NUMERO_CERTIFICADO}']}</p>
                <p><strong>Data de Emissão:</strong> {$replace['{DATA_EMISSAO}']}</p>
            </div>
            
            <div style='margin-top: 50px;'>
                <div style='display: flex; justify-content: space-between;'>
                    <div style='text-align: center; width: 45%;'>
                        <hr style='width: 80%;'>
                        <p><strong>{$replace['{ASSINADO_POR}']}</strong><br><small>Diretor(a) Geral</small></p>
                    </div>
                    <div style='text-align: center; width: 45%;'>
                        <hr style='width: 80%;'>
                        <p><strong>Secretaria Escolar</strong><br><small>Carimbo e Assinatura</small></p>
                    </div>
                </div>
            </div>
            
            <div style='margin-top: 40px; font-size: 11px; color: #666; border-top: 1px solid #ddd; padding-top: 20px;'>
                <p>" . htmlspecialchars($escola['endereco'] ?? '') . " | Tel: " . htmlspecialchars($escola['telefone'] ?? '') . " | Email: " . htmlspecialchars($escola['email'] ?? '') . "</p>
                <p>Documento emitido eletronicamente - Sistema SIGE Angola</p>
            </div>
        </div>
    </div>";
}
?>