# AutoStatus (GLPI plugin)

Plugin simples para **GLPI 10.0.x** que altera automaticamente o **status do chamado (Ticket)** quando ocorrerem eventos na timeline:

- **Criação do chamado**
- **Adição de Tarefa (TicketTask)** (inclui casos onde o plugin ActualTime grava como tarefa)
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
