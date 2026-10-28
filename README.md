# AutoStatus (GLPI plugin)

Plugin simples para **GLPI 10.0.x** que altera automaticamente o **status do chamado (Ticket)** quando ocorrerem eventos na timeline:

- **Criação do chamado**
- **Adição de Tarefa (TicketTask)** (inclui casos onde o plugin ActualTime grava como tarefa)
- **Adição de Acompanhamento (ITILFollowup)**
- **Início/retomada e pausa/encerramento dos cronômetros do ActualTime London** (integração opcional)

## ActualTime London — v1.4.0

Integração específica com [iezak/actualtime, branch london/glpi10](https://github.com/iezak/actualtime/tree/london/glpi10),
com os eventos adicionados na versão **3.2.6-london.2**. A versão anterior grava os
cronômetros diretamente no banco e não dispara os eventos de criação/atualização
de objetos do GLPI; atualizar somente o AutoStatus não é suficiente.

Em **Configuração > Plugins > AutoStatus > Configurar > Integração com ActualTime London**:

1. Defina **Habilitar integração = Sim** (desabilitada por padrão).
2. Escolha o status **Ao iniciar ou retomar** (padrão: **Em atendimento**).
3. Escolha o status **Ao pausar ou encerrar** (padrão: **Pendente**).
4. Mantenha **Aguardar todos os cronômetros do chamado = Sim** para tarefas simultâneas.

| Evento | Comportamento padrão |
| --- | --- |
| Iniciar ou retomar uma tarefa | Chamado em atendimento |
| Pausar/encerrar uma tarefa com outra ainda rodando no mesmo chamado | Mantém o status atual |
| Pausar/encerrar o último cronômetro do chamado | Chamado pendente |
| Encerrar a tarefa | O ActualTime continua concluindo a tarefa e atualizando sua duração |
| Consultar contadores ou criar/editar/excluir apontamentos históricos | Não altera status por esta integração |

A conferência inclui **todos os técnicos e tarefas do mesmo chamado**. Cronômetros
de outros chamados, problemas, mudanças ou projetos não interferem. Registros sem
horário inicial não são considerados cronômetros ativos.

Os filtros de status de início e de pausa são independentes da regra de criação
de tarefas. **Não alterar tickets Solucionados/Fechados**, **Ignorar tarefas
privadas** e a opção **Não alterar** continuam sendo respeitados. Chamados na
lixeira são ignorados. Uma tarefa privada rodando ainda participa da conferência
de cronômetros do chamado, mesmo quando seus próprios eventos são ignorados.

Atualize os dois plugins nas pastas existentes `autostatus` e `actualtime`, primeiro
no espelho, com backup do banco e das pastas. Use a atualização normal do GLPI se
solicitada, **sem desinstalar**. As configurações já salvas são preservadas, inclusive
as chaves `actualtime_*` da antiga branch de teste. Esta integração usa somente os
apontamentos do ActualTime e não cria tabela de cronômetros no AutoStatus.

### Validação

```bash
php tests/regression/actualtime.php
```

Para executar as duas implementações juntas, com os repositórios lado a lado:

```bash
cd ../actualtime
AUTOSTATUS_PLUGIN_DIR=../autostatus php tests/regression/timer-events.php
```

Os testes usam adaptadores de GLPI em memória, sem banco real. No espelho, confira
iniciar A/B no mesmo chamado, pausar A mantendo atendimento, pausar B aplicando
pendente, retomar A, e encerrar A mantendo a conclusão da tarefa. Repita com outro
técnico e verifique os filtros, os status solucionado/fechado e os acompanhamentos.

## Instalação

1. Copie a pasta `autostatus` para: `<GLPI_ROOT>/plugins/autostatus`
2. No GLPI: **Configuração > Plugins**
3. Clique em **Instalar** e depois **Ativar**
4. Clique na engrenagem (configurar) do plugin **AutoStatus** e ajuste os status desejados.

## Configuração (exemplo)

- Quando criar o chamado -> **Novo**
- Quando adicionar uma tarefa -> **Em atendimento**
- Quando adicionar um acompanhamento -> **Pendente**

## Observações

- Por padrão, não altera chamados já **Solucionados** ou **Fechados** (opção na tela).
- O plugin só altera followups cujo `itemtype` seja `Ticket` (não mexe em followups de Problemas/Mudanças).


## Novidades v1.1.0
- Filtros: aplicar regra somente quando o status atual estiver em uma lista (checkboxes)
- Followup: opção de status diferente para followup do Requerente vs Técnico/outros
- Opção para ignorar tarefas privadas e/ou acompanhamentos privados


## Novidades v1.2.1
- Followup: detecção de técnico baseada no direito **"Se tornar encarregado"** (Ticket::OWN), em vez de interface 'central'.

## Novidades v1.2.0
- Followup: dividir por usuário técnico vs não-técnico.

## Revisão v1.2.2 — Compatibilidade e isolamento por entidade

- Corrigida a classificação de técnico nas respostas: o direito `Ticket::OWN` agora é considerado apenas quando o perfil vale para a **entidade do chamado** ou para uma **entidade ancestral com vínculo recursivo**. Um perfil recursivo em outra entidade não classifica o usuário como técnico.
- Corrigida a descrição da tela de configuração, que ainda mencionava apenas a interface Central; a regra efetiva é baseada no direito **Se tornar encarregado**.
- A instalação/reinstalação inicializa apenas configurações ausentes, sem substituir as opções de status já gravadas pelo administrador.
- A declaração de compatibilidade foi limitada à linha **GLPI 10.0.x / PHP 8.1+**, revisada com base no GLPI 10.0.26.
- Nenhuma configuração de status ou filtro existente é alterada ao atualizar o código; os hooks continuam atuando apenas após criação de chamados, tarefas e acompanhamentos.
- **Integração:** o plugin `mandatoryplanning` valida eventos externos do planejamento, portanto não compartilha os objetos/hook de inserção usados aqui. Já `londonrestrictcat` possui um hook de validação pré-atualização de chamados, que também é acionado ao alterar status: testar chamados em categorias restritas, sobretudo quando o acesso é concedido pela exceção de participante direto.

### Testes recomendados

1. Salvar a configuração de status e atualizar/reinstalar em **homologação**; confirmar que as opções continuam iguais.
2. Adicionar tarefa em chamado aberto: aplicar o status configurado.
3. Adicionar acompanhamento por técnico e por não-técnico: confirmar o status correspondente.
4. Testar um usuário com direito de técnico em entidade **diferente** da entidade do chamado.
5. Confirmar que chamados solucionados/fechados continuam protegidos quando `ignore_solved_closed` está ligado.
6. Testar as ações automáticas e a edição de chamados da categoria restrita com `londonrestrictcat` ativo.

