# AutoStatus (GLPI plugin)

Plugin simples para **GLPI 10.0.x** que altera automaticamente o **status do chamado (Ticket)** quando ocorrerem eventos na timeline:

- **Criação do chamado**
- **Adição de Tarefa (TicketTask)**
- **Integração com ActualTime (início/parada do timer)**
- **Adição de Acompanhamento (ITILFollowup)**

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




## Novidades v1.3.0
- Integração com **ActualTime**:
  - quando o timer **inicia**, o ticket pode ir para um status (ex.: **Em atendimento**)
  - quando o timer **para**, o ticket pode ir para outro status (ex.: **Pendente**)
  - opção de segurança: ao parar, só colocar "Pendente" se **não houver outro timer rodando** no mesmo ticket
  - filtros opcionais: aplicar somente se o status atual estiver em uma lista (início/parada)

## Novidades v1.2.1
- Followup: detecção de técnico baseada no direito **"Se tornar encarregado"** (Ticket::OWN), em vez de interface 'central'.

## Novidades v1.2.0
- Followup: dividir por usuário técnico vs não-técnico.