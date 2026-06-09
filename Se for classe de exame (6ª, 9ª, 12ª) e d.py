Se for classe de exame (6ª, 9ª, 12ª) e disciplina de língua (Português/Inglês) → Exame Oral + Escrito

Se for classe de exame e disciplina NORMAL → Exame Normal

Se NÃO for classe de exame → NENHUM exame (apenas MAC e NPT)


 Lógica de Classes de Exame:

    Detecta automaticamente se é classe de exame (6ª, 9ª, 12ª)

    Aplica fórmula: Média Final = (MAC + NPT)/2 × 0.4 + Exame × 0.6

3. Lógica de Disciplinas de Língua:

    Detecta Português e Inglês

    Para 3º bimestre: Média Exame = (Oral + Escrito)/2

4. Tabela com colunas para Exame:

    Exame Normal (para todas as classes)

    Exame diferenciado para línguas (Oral/Escrito)

5. Informações de Regras:

    Escala (0-10 ou 0-20)

    Indicação se é classe de exame

    Indicação se é disciplina de língua

6. Cálculo da Média Anual:

    Recalcula todas as médias aplicando as regras específicas



    Principais regras implementadas:
1. Se NÃO for classe de exame (1ª a 5ª, 7ª a 8ª, 10ª a 11ª):

    NENHUM exame

    Apenas MAC e NPT

    Média = (MAC + NPT) / 2

2. Se for classe de exame (6ª, 9ª, 12ª) e disciplina de Língua:

    3º bimestre: Exame Oral + Exame Escrito

    Média Final 3º Bim = (MAC + NPT)/2 × 0.4 + [(Oral + Escrito)/2] × 0.6

3. Se for classe de exame (6ª, 9ª, 12ª) e disciplina NORMAL:

    3º bimestre: Exame Normal

    Média Final 3º Bim = (MAC + NPT)/2 × 0.4 + (Exame Normal) × 0.6