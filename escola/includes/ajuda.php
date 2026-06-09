<?php
// escola/includes/ajuda.php - Componente de Ajuda Contextual

function getAjudaConteudo($pagina) {
    $ajudas = [
        'dashboard' => [
            'titulo' => 'Dashboard - Visão Geral',
            'icone' => 'fas fa-tachometer-alt',
            'conteudo' => '
                <h5><i class="fas fa-chart-line"></i> Sobre o Dashboard</h5>
                <p>O Dashboard é a página inicial do sistema onde você pode visualizar informações resumidas da sua escola e atividades recentes.</p>
                
                <h5 class="mt-3"><i class="fas fa-info-circle"></i> O que você encontra aqui:</h5>
                <ul>
                    <li><strong>Cards de Estatísticas:</strong> Mostram números totais de alunos, professores, turmas e disciplinas</li>
                    <li><strong>Gráficos:</strong> Visualização de alunos por turma e distribuição de gênero</li>
                    <li><strong>Atividades Recentes:</strong> Últimas ações realizadas no sistema</li>
                    <li><strong>Calendário Escolar:</strong> Próximos eventos e datas importantes</li>
                    <li><strong>Acessos Rápidos:</strong> Botões para as funcionalidades mais usadas</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-lightbulb"></i> Dicas:</h5>
                <ul>
                    <li>Use os filtros para refinar as informações exibidas</li>
                    <li>Clique nos cards para acessar páginas detalhadas</li>
                    <li>Os gráficos são interativos - passe o mouse para ver detalhes</li>
                </ul>
            '
        ],
        
        'notas' => [
            'titulo' => 'Lançamento de Notas',
            'icone' => 'fas fa-edit',
            'conteudo' => '
                <h5><i class="fas fa-graduation-cap"></i> Sobre o Lançamento de Notas</h5>
                <p>Esta página permite que professores e administradores lancem e gerenciem as notas dos alunos.</p>
                
                <h5 class="mt-3"><i class="fas fa-clipboard-list"></i> Como funciona:</h5>
                <ul>
                    <li><strong>Seleção:</strong> Escolha a turma, disciplina e trimestre desejado</li>
                    <li><strong>MAC (50%):</strong> Nota da Avaliação Contínua (trabalhos, participação, etc.)</li>
                    <li><strong>NPT (50%):</strong> Nota da Prova Trimestral</li>
                    <li><strong>Média Final:</strong> Calculada automaticamente (MAC + NPT) / 2</li>
                    <li><strong>Salvar:</strong> Clique em "Salvar Notas" para registrar as alterações</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-exclamation-triangle"></i> Classes de Exame (6º, 9º, 12º):</h5>
                <ul>
                    <li>No 3º trimestre, a média é calculada como: 40% Média Parcial + 60% Exame</li>
                    <li>Para disciplinas de Línguas: Média do Exame (Oral + Escrito) / 2</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-flag-checkered"></i> Critérios de Aprovação:</h5>
                <ul>
                    <li><strong>Aprovado:</strong> Média ≥ 14</li>
                    <li><strong>Exame:</strong> Média entre 10 e 13.9</li>
                    <li><strong>Reprovado:</strong> Média < 10</li>
                </ul>
            '
        ],
        
        'perfil' => [
            'titulo' => 'Meu Perfil',
            'icone' => 'fas fa-user-circle',
            'conteudo' => '
                <h5><i class="fas fa-id-card"></i> Sobre o Meu Perfil</h5>
                <p>Esta página centraliza todas as suas informações pessoais, profissionais e financeiras.</p>
                
                <h5 class="mt-3"><i class="fas fa-user"></i> Seções disponíveis:</h5>
                <ul>
                    <li><strong>Dados Pessoais:</strong> Nome, email, telefone, BI, data de nascimento, etc.</li>
                    <li><strong>Dados Bancários:</strong> Banco, conta, IBAN, NIF</li>
                    <li><strong>Salários:</strong> Salário base, salário atual, subsídios, histórico de pagamentos</li>
                    <li><strong>Dívidas:</strong> Dívidas a pagar, a receber e vencidas com desconto em folha</li>
                    <li><strong>Solicitar Vale:</strong> Solicitação de adiantamento salarial (máx 50% do salário)</li>
                    <li><strong>Solicitar Férias:</strong> Solicitação de período de férias (mínimo 5 dias, máx 22 dias/ano)</li>
                    <li><strong>Segurança:</strong> Alteração de senha</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-lock"></i> Importante:</h5>
                <ul>
                    <li>Apenas senha e foto podem ser alteradas por você</li>
                    <li>Demais dados devem ser atualizados pela administração</li>
                    <li>Solicitações de vale e férias requerem aprovação</li>
                </ul>
            '
        ],
        
        'chamados' => [
            'titulo' => 'Chamados de Suporte',
            'icone' => 'fas fa-headset',
            'conteudo' => '
                <h5><i class="fas fa-ticket-alt"></i> Sobre os Chamados de Suporte</h5>
                <p>Sistema para solicitar assistência técnica, administrativa ou financeira.</p>
                
                <h5 class="mt-3"><i class="fas fa-plus-circle"></i> Como abrir um chamado:</h5>
                <ul>
                    <li>Clique no botão "Novo Chamado"</li>
                    <li>Preencha o título (resumo do problema)</li>
                    <li>Selecione a categoria (Técnico, Administrativo, Financeiro, Acadêmico)</li>
                    <li>Defina a prioridade (Baixa, Média, Alta)</li>
                    <li>Descreva detalhadamente o problema</li>
                    <li>Clique em "Enviar Chamado"</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-comments"></i> Acompanhamento:</h5>
                <ul>
                    <li>Você receberá notificações por email</li>
                    <li>Pode responder e anexar arquivos</li>
                    <li>Quando resolvido, marque como fechado</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-clock"></i> Tempo de resposta:</h5>
                <ul>
                    <li>Prioridade Alta: até 4 horas úteis</li>
                    <li>Prioridade Média: até 24 horas úteis</li>
                    <li>Prioridade Baixa: até 48 horas úteis</li>
                </ul>
            '
        ],
        
        'faq' => [
            'titulo' => 'FAQ - Perguntas Frequentes',
            'icone' => 'fas fa-question-circle',
            'conteudo' => '
                <h5><i class="fas fa-book"></i> Sobre a FAQ</h5>
                <p>Central de respostas para as dúvidas mais comuns sobre o sistema.</p>
                
                <h5 class="mt-3"><i class="fas fa-search"></i> Como usar:</h5>
                <ul>
                    <li><strong>Busca:</strong> Digite palavras-chave para encontrar perguntas específicas</li>
                    <li><strong>Categorias:</strong> Filtre por tema (Geral, Sistema, Notas, etc.)</li>
                    <li><strong>Clique na Pergunta:</strong> Expande para ver a resposta</li>
                    <li><strong>Copiar Link:</strong> Compartilhe uma FAQ específica com outros</li>
                    <li><strong>Feedback:</strong> Avalie se a resposta foi útil</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-star"></i> Dicas:</h5>
                <ul>
                    <li>Use palavras-chave relevantes na busca</li>
                    <li>Explore as diferentes categorias</li>
                    <li>Se não encontrar, abra um chamado de suporte</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-shield-alt"></i> Para Administradores:</h5>
                <ul>
                    <li>Você pode adicionar, editar e excluir FAQs</li>
                    <li>Ativar/desativar perguntas conforme necessidade</li>
                    <li>Controlar a ordem de exibição</li>
                </ul>
            '
        ],
        
        'financeiro' => [
            'titulo' => 'Informações Financeiras',
            'icone' => 'fas fa-money-bill',
            'conteudo' => '
                <h5><i class="fas fa-chart-line"></i> Sobre o Módulo Financeiro</h5>
                <p>Gerencie suas informações financeiras e solicitações.</p>
                
                <h5 class="mt-3"><i class="fas fa-wallet"></i> O que você pode fazer:</h5>
                <ul>
                    <li><strong>Ver Salários:</strong> Salário base, atual, subsídios e histórico</li>
                    <li><strong>Acompanhar Dívidas:</strong> Dívidas a pagar, a receber e vencidas</li>
                    <li><strong>Solicitar Vale:</strong> Adiantamento salarial (até 50% do salário)</li>
                    <li><strong>Dados Bancários:</strong> Informações para crédito de salário</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-hand-holding-usd"></i> Sobre Vales:</h5>
                <ul>
                    <li>Valor máximo: 50% do salário atual</li>
                    <li>Parcelamento: até 6 vezes mensais</li>
                    <li>Desconto automático em folha</li>
                    <li>Requisição precisa de aprovação</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-calendar-alt"></i> Sobre Férias:</h5>
                <ul>
                    <li>Direito a 22 dias úteis por ano</li>
                    <li>Solicitação com 15 dias de antecedência</li>
                    <li>Período mínimo de 5 dias consecutivos</li>
                    <li>Aprovação sujeita a disponibilidade</li>
                </ul>
            '
        ],
        
        'default' => [
            'titulo' => 'Ajuda do Sistema',
            'icone' => 'fas fa-question-circle',
            'conteudo' => '
                <h5><i class="fas fa-life-ring"></i> Precisa de ajuda?</h5>
                <p>Estamos aqui para auxiliar você!</p>
                
                <h5 class="mt-3"><i class="fas fa-headset"></i> Canais de Suporte:</h5>
                <ul>
                    <li><strong>FAQ:</strong> Consulte as perguntas frequentes</li>
                    <li><strong>Chamados:</strong> Abra um ticket de suporte</li>
                    <li><strong>Email:</strong> suporte@sigeangola.com</li>
                    <li><strong>Telefone:</strong> +244 923 456 789</li>
                </ul>
                
                <h5 class="mt-3"><i class="fas fa-clock"></i> Horário de Atendimento:</h5>
                <ul>
                    <li>Segunda a Sexta: 8h às 17h</li>
                    <li>Sábado: 8h às 12h</li>
                    <li>Emergências: 24/7</li>
                </ul>
                
                <div class="alert alert-info mt-3">
                    <i class="fas fa-info-circle"></i>
                    <strong>Dica:</strong> Explore o sistema! Quanto mais você usa, mais familiarizado fica.
                </div>
            '
        ]
    ];
    
    return $ajudas[$pagina] ?? $ajudas['default'];
}
?>

<!-- Modal de Ajuda -->
<div class="modal fade" id="modalAjuda" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%); color: white;">
                <h5 class="modal-title" id="modalAjudaTitulo">
                    <i class="fas fa-question-circle"></i> Ajuda
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalAjudaConteudo">
                <!-- Conteúdo dinâmico via JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <a href="suporte/faq.php" class="btn btn-primary-custom">
                    <i class="fas fa-book"></i> Ver FAQ
                </a>
                <a href="suporte/chamados.php" class="btn btn-info">
                    <i class="fas fa-headset"></i> Abrir Chamado
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Função para abrir ajuda contextual
function abrirAjuda(pagina) {
    // Buscar conteúdo da ajuda via AJAX ou usar dados locais
    fetch('ajax_ajuda.php?pagina=' + pagina)
        .then(response => response.json())
        .then(data => {
            document.getElementById('modalAjudaTitulo').innerHTML = '<i class="' + data.icone + '"></i> ' + data.titulo;
            document.getElementById('modalAjudaConteudo').innerHTML = data.conteudo;
            new bootstrap.Modal(document.getElementById('modalAjuda')).show();
        })
        .catch(() => {
            // Fallback: usar dados locais
            const ajudas = {
                '<?php echo basename($_SERVER['PHP_SELF'], '.php'); ?>': <?php 
                    $pagina_atual = basename($_SERVER['PHP_SELF'], '.php');
                    $ajuda = getAjudaConteudo($pagina_atual);
                    echo json_encode($ajuda);
                ?>
            };
            const ajuda = ajudas['<?php echo basename($_SERVER['PHP_SELF'], '.php'); ?>'] || ajudas['default'];
            document.getElementById('modalAjudaTitulo').innerHTML = '<i class="' + ajuda.icone + '"></i> ' + ajuda.titulo;
            document.getElementById('modalAjudaConteudo').innerHTML = ajuda.conteudo;
            new bootstrap.Modal(document.getElementById('modalAjuda')).show();
        });
}
</script>

<style>
.btn-ajuda {
    position: fixed;
    bottom: 30px;
    right: 30px;
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #006B3E 0%, #1A2A6C 100%);
    color: white;
    border: none;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
    cursor: pointer;
    z-index: 1000;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.btn-ajuda:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 20px rgba(0,0,0,0.3);
}

.btn-ajuda i {
    font-size: 28px;
}

.btn-ajuda .tooltip-text {
    position: absolute;
    right: 70px;
    background: #333;
    color: white;
    padding: 5px 10px;
    border-radius: 5px;
    font-size: 12px;
    white-space: nowrap;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
}

.btn-ajuda:hover .tooltip-text {
    opacity: 1;
}

@media (max-width: 768px) {
    .btn-ajuda {
        bottom: 20px;
        right: 20px;
        width: 50px;
        height: 50px;
    }
    .btn-ajuda i {
        font-size: 24px;
    }
}

.ajuda-section {
    margin-bottom: 20px;
}

.ajuda-section h5 {
    color: #006B3E;
    margin-bottom: 10px;
    padding-bottom: 5px;
    border-bottom: 2px solid #006B3E;
}

.ajuda-section ul {
    padding-left: 20px;
}

.ajuda-section li {
    margin-bottom: 8px;
}
</style>

<!-- Botão de Ajuda Flutuante -->
<button class="btn-ajuda" onclick="abrirAjuda('<?php echo basename($_SERVER['PHP_SELF'], '.php'); ?>')">
    <i class="fas fa-question"></i>
    <span class="tooltip-text">Precisa de ajuda?</span>
</button>