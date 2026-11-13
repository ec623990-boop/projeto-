# Biblioteca Vendas

Aplicação web de livraria em PHP 8.1+, MySQL/InnoDB e um adaptador Java/Spring Boot opcional para Senior X. Mantém o projeto PHP existente como loja e fonte principal dos dados; a integração Java continua separada.

## Requisitos

- PHP 8.1 ou superior, com PDO MySQL.
- MySQL 8.0 ou MariaDB com InnoDB.
- Java 17 e Maven para executar o adaptador Senior opcional.

## Instalação em banco novo

1. Inicie o MySQL e importe `database/schema.sql`. Ele cria o banco `biblioteca_vendas`, tabelas do catálogo, pedidos, pagamentos, contas da equipe e trilha de estoque.
2. Copie `.env.example` para `.env` e configure a conexão local. Não publique `.env`, credenciais nem tokens.
3. Crie o primeiro administrador no terminal, na raiz do projeto: `php scripts/criar_admin.php`. O script pergunta os dados; não passe a senha como argumento. Após criar a conta, remova ou proteja o script no servidor publicado.
4. Inicie a aplicação com `php -S 127.0.0.1:8000 -t public` e abra `http://127.0.0.1:8000`.
5. Acesse a gestão em `http://127.0.0.1:8000/admin_login.php`.

O `.env` é local e ignorado pelo Git. Mantenha credenciais reais fora do repositório.

## Atualizar banco existente

Faça backup antes de qualquer alteração. As migrações são pontuais e não devem ser repetidas.

1. Para uma instalação anterior ao checkout Mercado Pago, aplique `database/migrate_mercadopago_sandbox.sql` se as colunas de método e detalhe de pagamento ainda não existirem.
2. Se a tabela da integração Senior ainda não existir, aplique `database/migrate_senior_erp.sql`.
3. Aplique uma única vez `database/migrate_gestao_catalogo.sql` para adicionar os metadados dos livros, usuários da equipe e histórico de estoque.
4. Crie o primeiro administrador com `php scripts/criar_admin.php`.

Não importe `schema.sql` sobre uma instalação existente. A migração de catálogo adiciona ISBN único; resolva manualmente eventuais duplicatas antes de qualquer adaptação local da migração.

## Funcionalidades

- Catálogo público de livros com preço, autor, categoria, ISBN e dados bibliográficos.
- Consulta de metadados por ISBN no Google Books dentro da sessão da equipe; os dados são conferidos e gravados pela equipe.
- Painel de gestão com indicadores do mês, livros ativos, estoque e alertas de estoque mínimo.
- Cadastro e edição de livros, ativação/desativação de itens, entrada/saída de estoque, localização e trilha de movimentações vinculada à equipe.
- Login administrativo com `password_hash`/`password_verify`, bloqueio temporário após tentativas inválidas, CSRF, cookies HttpOnly e autorização validada no servidor.
- Administrador inicial por script CLI; administradores podem criar contas de equipe e outros administradores.
- Histórico resumido de vendas e exportação CSV disponível somente para a equipe autenticada.
- Compra de teste no modo local ou no sandbox Mercado Pago; o catálogo e o painel não oferecem operação real de cobrança.
- Notificação de pagamento Mercado Pago validada por assinatura, consulta do pagamento ao provedor, conferência do pedido, moeda, valor e modo de teste. O processamento idempotente atualiza o pedido e a trilha de estoque.
- Integração Senior X opcional por adaptador Java já existente; pedidos pagos podem ser enviados quando as credenciais empresariais forem configuradas.

## Simulação local e sandbox

Por padrão, `PAYMENT_MODE=simulado_local` não chama provedores e não movimenta dinheiro. O resultado do cartão pode ser escolhido na interface de simulação; Pix começa pendente e pode ser confirmado pelo botão de teste. Uma recusa repõe o estoque e registra o estorno na trilha.

Para o sandbox externo, configure `PAYMENT_MODE=mercadopago_test`, `MP_TEST_PUBLIC_KEY` e `MP_TEST_ACCESS_TOKEN` com credenciais de teste. O formulário do Mercado Pago tokeniza os dados do cartão; o projeto não deve receber nem armazenar número, validade ou CVV. Use somente cartões de teste fornecidos pela [documentação do Mercado Pago](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-cards).

Para confirmação automática opcional do sandbox, defina `MP_TEST_WEBHOOK_SECRET` com o segredo de assinatura e configure a URL pública de teste como `https://SEU_HOST/mercadopago_webhook.php` nas notificações do Mercado Pago. Em `localhost`, o provedor não consegue alcançar o endpoint. A configuração valida `x-signature` com HMAC-SHA256 e consulta o estado do pagamento à API antes de atualizar o pedido. Consulte a [documentação oficial de Webhooks](https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks). Não use credenciais de produção neste projeto.

## Acesso administrativo

- Login: `/admin_login.php`.
- Painel: `/admin.php`.
- Consulta Google Books por ISBN: disponível no formulário de cadastro autenticado.
- CSV de vendas: `/exportar_vendas.php`, protegido por autenticação da equipe.

O primeiro administrador é criado pelo script CLI. Leitores da loja não recebem acesso à equipe. A conta pública do catálogo não exige cadastro para navegação; compras de teste coletam apenas os dados necessários para o pedido.

## Senior X / Java

O diretório `integracao-senior-java` mantém o adaptador Spring Boot existente. A loja PHP/MySQL é a fonte de pedidos, catálogo e estoque; o Java encaminha pedidos aprovados ao Senior X pela API configurada, sem acesso SQL direto ao ERP.

Para ativar, configure no `.env` apenas as credenciais fornecidas pela empresa (`SENIOR_*`) e `SENIOR_SYNC_ENABLED=true`, `SENIOR_INTEGRATION_ENABLED=true`. Use `SENIOR_BRIDGE_API_KEY` como segredo compartilhado entre PHP e Java. Com Java 17+ e Maven, inicie o serviço de dentro de `integracao-senior-java` usando `mvn spring-boot:run`. A sincronização permanece desativada até que o acesso, a empresa e os SKUs estejam configurados.

## Verificações

Execute `php tests/php/run.php` para conferir a validação de ISBN e assinatura de webhook. O GitHub Actions também faz lint dos arquivos PHP e executa os testes Java/Maven do adaptador. Essas verificações não conectam ao MySQL, Mercado Pago ou Senior X.

O GitHub Pages não executa PHP nem fornece MySQL. Para testar a loja em navegador, use o servidor PHP local ou hospedagem com PHP, MySQL e HTTPS público para notificações do provedor.

## Limites

- O gateway externo está restrito ao ambiente de teste; não configura vendas reais.
- O histórico de estoque registra entradas/saídas administrativas, reservas, vendas confirmadas e estornos; ajustes devem ser feitos no painel para preservar a auditoria.
- Os metadados do Google Books podem estar incompletos; confira os campos antes de salvar.
- A área de pedidos é um resumo administrativo; não implementa emissão fiscal, logística ou processo de devolução.
- Faça backup antes de cada migração e teste a instalação no seu próprio banco antes de publicar.
