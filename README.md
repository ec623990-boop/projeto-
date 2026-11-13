# Biblioteca Vendas

Aplicação web de livraria em PHP 8.1+, MySQL/InnoDB e um adaptador Java/Spring Boot opcional para Senior X. Mantém o projeto PHP existente como loja e fonte principal dos dados; a integração Java continua separada.

## Requisitos

- PHP 8.1 ou superior, com PDO MySQL.
- MySQL 8.0 ou MariaDB com InnoDB.
- Java 17 e Maven para executar o adaptador Senior opcional.

## Instalação em banco novo

1. Inicie o MySQL e importe `database/schema.sql`. Ele cria o banco `biblioteca_vendas`, tabelas do catálogo, pedidos, pagamentos, contas da equipe e trilha de estoque.
2. Copie `.env.example` para `.env` e configure a conexão local. Não publique `.env`, credenciais nem tokens.
3. Crie o primeiro administrador pelo terminal, na raiz do projeto: `php scripts/criar_admin.php`. Como alternativa local, depois de iniciar a loja no passo seguinte, abra `http://127.0.0.1:8000/configurar_admin.php`; essa página só aceita conexões locais e se bloqueia depois que o primeiro administrador é criado. Não passe a senha como argumento do script CLI.
4. Inicie a aplicação com `php -S 127.0.0.1:8000 -t public` e abra `http://127.0.0.1:8000`.
5. Acesse a gestão em `http://127.0.0.1:8000/admin_login.php`.

O `.env` é local e ignorado pelo Git. Mantenha credenciais reais fora do repositório.

## Atualizar banco existente

Faça backup antes de qualquer alteração. Para bancos existentes, execute o verificador seguro da aplicação. Ele identifica itens ausentes e só altera o banco depois que você confirma com a palavra APLICAR.

1. Para uma instalação anterior ao checkout Mercado Pago, aplique `database/migrate_mercadopago_sandbox.sql` se as colunas de método e detalhe de pagamento ainda não existirem.
2. Se a tabela da integração Senior ainda não existir, aplique `database/migrate_senior_erp.sql`.
3. Na raiz do projeto, execute `php scripts/migrar_gestao_catalogo.php`, revise as alterações propostas e digite APLICAR somente depois do backup.
4. Crie o primeiro administrador com `php scripts/criar_admin.php` ou pela página local `/configurar_admin.php`.

`database/migrate_gestao_catalogo.sql` é a migração manual antiga, não idempotente. Não a execute se alguma das colunas ou tabelas que ela cria já existir.

Não importe `schema.sql` sobre uma instalação existente. A migração de catálogo adiciona ISBN único; resolva manualmente eventuais duplicatas antes de qualquer adaptação local da migração.

## Funcionalidades

- Catálogo público de livros com preço, autor, categoria, ISBN e dados bibliográficos.
- Busca de livros na internet por título, autor ou ISBN usando a [API Search do Open Library](https://openlibrary.org/dev/docs/api/search). No modo local de simulação, o resultado pode ser incluído como estoque fictício com preço ajustável, capa e descrição quando disponíveis; esse item segue pelo carrinho e checkout simulado. A área administrativa também permite conferir e cadastrar livros no acervo.
- Capas retornadas pelo catálogo ou informadas por URL aparecem no painel e nas vitrines da loja; as capas públicas usam o [Covers API do Open Library](https://openlibrary.org/dev/docs/api/covers). O catálogo permite filtrar livros por título, autor, categoria, código ou ISBN.
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
- A loja conecta catálogo, carrinho e pagamento por atalhos no cabeçalho; o painel tem atalhos para acervo, estoque, vendas e contas da equipe.
- O cadastro inicial em `/configurar_admin.php` só funciona em `localhost`/`127.0.0.1` enquanto não existir administrador.
- Busca de livros por título, autor ou ISBN: disponível no painel autenticado, com preenchimento de metadados e prévia da capa; confira as informações antes de salvar.
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
- Os metadados e capas do Open Library podem estar incompletos; confira os dados e a sinopse quando disponíveis. O estoque e o preço importados pela loja são fictícios e só podem ser adicionados no servidor local com `PAYMENT_MODE=simulado_local`; não representam livros físicos disponíveis para venda real.
- A área de pedidos é um resumo administrativo; não implementa emissão fiscal, logística ou processo de devolução.
- Faça backup antes de cada migração e teste a instalação no seu próprio banco antes de publicar.
