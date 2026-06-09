<?php
// escola/ajax_ajuda.php - Busca conteúdo de ajuda via AJAX

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$pagina = isset($_GET['pagina']) ? $_GET['pagina'] : 'default';

$ajudas = [
    'dashboard' => [
        'titulo' => 'Dashboard - Visão Geral',
        'icone' => 'fas fa-tachometer-alt',
        'conteudo' => '
            <div class="ajuda-section">
                <h5><i class="fas fa-chart-line"></i> Sobre o Dashboard</h5>
                <p>O Dashboard é a página inicial onde você acompanha as principais métricas da sua escola.</p>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-info-circle"></i> O que você encontra aqui:</h5>
                <ul>
                    <li><strong>Cards de Estatísticas:</strong> Alunos, professores, turmas e disciplinas</li>
                    <li><strong>Gráficos:</strong> Distribuição de alunos e desempenho</li>
                    <li><strong>Atividades Recentes:</strong> Últimas ações no sistema</li>
                    <li><strong>Calendário:</strong> Eventos e datas importantes</li>
                </ul>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-lightbulb"></i> Dicas:</h5>
                <ul>
                    <li>Clique nos cards para acessar páginas detalhadas</li>
                    <li>Use os filtros para refinar informações</li>
                    <li>Os gráficos são interativos</li>
                </ul>
            </div>
        '
    ],
    
    'index' => [
        'titulo' => 'Lançamento de Notas',
        'icone' => 'fas fa-edit',
        'conteudo' => '
            <div class="ajuda-section">
                <h5><i class="fas fa-graduation-cap"></i> Como lançar notas</h5>
                <ol>
                    <li>Selecione a <strong>Turma</strong> desejada</li>
                    <li>Escolha a <strong>Disciplina</strong></li>
                    <li>Selecione o <strong>Trimestre</strong> (1º, 2º ou 3º)</li>
                    <li>Preencha as notas <strong>MAC</strong> e <strong>NPT</strong> para cada aluno</li>
                    <li>Clique em <strong>Salvar Notas</strong></li>
                </ol>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-calculator"></i> Como a média é calculada:</h5>
                <ul>
                    <li><strong>Média Parcial:</strong> (MAC + NPT) / 2</li>
                    <li><strong>Classes de Exame (6º, 9º, 12º):</strong> 40% Média Parcial + 60% Exame</li>
                    <li><strong>Línguas:</strong> Média Exame = (Oral + Escrito) / 2</li>
                </ul>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-flag-checkered"></i> Critérios de aprovação:</h5>
                <ul>
                    <li><span class="badge bg-success">Aprovado</span> - Média ≥ 14</li>
                    <li><span class="badge bg-warning text-dark">Exame</span> - Média entre 10 e 13.9</li>
                    <li><span class="badge bg-danger">Reprovado</span> - Média &lt; 10</li>
                </ul>
            </div>
            <div class="alert alert-info mt-3">
                <i class="fas fa-video"></i> <strong>Video tutorial:</strong> <a href="#">Assista ao tutorial de lançamento de notas</a>
            </div>
        '
    ],
    
    'perfil' => [
        'titulo' => 'Meu Perfil - Gestão Completa',
        'icone' => 'fas fa-user-circle',
        'conteudo' => '
            <div class="ajuda-section">
                <h5><i class="fas fa-id-card"></i> Informações Pessoais</h5>
                <p>Seus dados pessoais estão centralizados aqui para fácil consulta:</p>
                <ul>
                    <li>Nome completo e contatos</li>
                    <li>Documentos (BI, NIF)</li>
                    <li>Dados familiares (pai, mãe)</li>
                    <li>Contacto de emergência</li>
                </ul>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-university"></i> Dados Financeiros</h5>
                <ul>
                    <li><strong>Salário:</strong> Base, atual, subsídios (alimentação, transporte)</li>
                    <li><strong>Banco:</strong> Dados bancários para crédito</li>
                    <li><strong>Dívidas:</strong> A pagar, a receber, vencidas</li>
                </ul>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-hand-holding-usd"></i> Solicitações</h5>
                <ul>
                    <li><strong>Vale:</strong> Adiantamento salarial (máx 50% do salário, até 6 parcelas)</li>
                    <li><strong>Férias:</strong> Solicitação com 15 dias de antecedência (mín 5 dias)</li>
                </ul>
            </div>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-lock"></i> <strong>Atenção:</strong> Apenas senha e foto podem ser alteradas por você. Demais dados apenas pela administração.
            </div>
        '
    ],
    
    'chamados' => [
        'titulo' => 'Chamados de Suporte',
        'icone' => 'fas fa-headset',
        'conteudo' => '
            <div class="ajuda-section">
                <h5><i class="fas fa-ticket-alt"></i> Como abrir um chamado</h5>
                <ol>
                    <li>Clique no botão <strong>"Novo Chamado"</strong></li>
                    <li>Informe um título claro do problema</li>
                    <li>Selecione a <strong>categoria</strong> (Técnico, Administrativo, etc.)</li>
                    <li>Defina a <strong>prioridade</strong> (Alta, Média, Baixa)</li>
                    <li>Descreva detalhadamente o problema</li>
                    <li>Clique em <strong>"Enviar Chamado"</strong></li>
                </ol>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-clock"></i> Tempo de resposta:</h5>
                <ul>
                    <li><span class="badge bg-danger">Alta</span> - até 4 horas úteis</li>
                    <li><span class="badge bg-warning text-dark">Média</span> - até 24 horas úteis</li>
                    <li><span class="badge bg-success">Baixa</span> - até 48 horas úteis</li>
                </ul>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-comments"></i> Acompanhamento:</h5>
                <ul>
                    <li>Você recebe notificações por email</li>
                    <li>Pode anexar arquivos às respostas</li>
                    <li>Marque como resolvido quando o problema for solucionado</li>
                </ul>
            </div>
        '
    ],
    
    'faq' => [
        'titulo' => 'FAQ - Perguntas Frequentes',
        'icone' => 'fas fa-question-circle',
        'conteudo' => '
            <div class="ajuda-section">
                <h5><i class="fas fa-search"></i> Como encontrar respostas</h5>
                <ul>
                    <li><strong>Busca:</strong> Digite palavras-chave para encontrar perguntas específicas</li>
                    <li><strong>Categorias:</strong> Filtre por tema (Geral, Sistema, Notas, etc.)</li>
                    <li><strong>Clique na pergunta:</strong> Expande para ver a resposta completa</li>
                </ul>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-link"></i> Compartilhar respostas</h5>
                <p>Clique em "Copiar link" para compartilhar uma FAQ específica com outros usuários.</p>
            </div>
            <div class="ajuda-section">
                <h5><i class="fas fa-thumbs-up"></i> Avalie as respostas</h5>
                <p>Use os botões "Útil" ou "Não útil" para nos ajudar a melhorar o conteúdo.</p>
            </div>
            <div class="alert alert-info mt-3">
                <i class="fas fa-lightbulb"></i> <strong>Não encontrou?</strong> Abra um chamado de suporte - nossa equipe está pronta para ajudar!
            </div>
        '
    ]
];

$pagina_info = $ajudas[$pagina] ?? [
    'titulo' => 'Ajuda do Sistema',
    'icone' => 'fas fa-question-circle',
    'conteudo' => '
        <div class="ajuda-section">
            <h5><i class="fas fa-life-ring"></i> Precisa de ajuda?</h5>
            <p>Estamos aqui para auxiliar você!</p>
        </div>
        <div class="ajuda-section">
            <h5><i class="fas fa-headset"></i> Canais de Suporte:</h5>
            <ul>
                <li><strong>FAQ:</strong> Perguntas frequentes</li>
                <li><strong>Chamados:</strong> Abra um ticket</li>
                <li><strong>Email:</strong> suporte@sigeangola.com</li>
                <li><strong>WhatsApp:</strong> +244 923 456 789</li>
            </ul>
        </div>
        <div class="ajuda-section">
            <h5><i class="fas fa-clock"></i> Horário de atendimento:</h5>
            <ul>
                <li>Segunda a Sexta: 8h às 18h</li>
                <li>Sábado: 8h às 12h</li>
            </ul>
        </div>
    '
];

echo json_encode($pagina_info);
?>