# Biblioteca Vendas — orientação a objetos e MySQL

Projeto didático de venda de livros, estruturado para demonstrar as classes e conceitos da Etapa 2.

## Requisitos

- PHP 8.1 ou superior
- MySQL 8.0 ou superior
- Extensão PDO MySQL habilitada

## Preparar o banco

1. Inicie o MySQL, por exemplo pelo XAMPP.
2. Importe database/schema.sql no phpMyAdmin ou cliente MySQL. O script cria o banco biblioteca_vendas, as tabelas e produtos de demonstração.
3. Copie .env.example para .env e ajuste usuário e senha conforme seu MySQL.

Para uma instalação existente, não importe schema.sql de novo. Faça backup e aplique uma única vez a migração descrita em `database/migrate_mercadopago_sandbox.sql`.

Exemplo para XAMPP local: usuário root e senha vazia. Use isso somente em ambiente local.

## Executar localmente

Na pasta do projeto, rode:

    php -S 127.0.0.1:8000 -t public

Abra http://127.0.0.1:8000.

## Simulação local de compra

1. Adicione um livro ao carrinho.
2. Selecione crédito, débito ou Pix e escolha o resultado simulado.
3. Para Pix, a compra começa pendente; use “Simular confirmação do Pix” para aprovar ou recusar.
4. A simulação grava o pedido e o resultado no banco local para validar o fluxo. O código Pix exibido é fictício e não pode ser pago.

O modo padrão é `PAYMENT_MODE=simulado_local`, disponível apenas por acesso local (`127.0.0.1`/`localhost`). Ele não chama o Mercado Pago, não usa credenciais e não realiza cobrança. Uma aprovação simulada reduz o estoque; uma recusa o devolve.

O formulário local permite selecionar cartões de teste oficiais de crédito (Mastercard, Visa e American Express) e débito (Elo), com campos para número, validade e código de segurança. Esses campos são validados no navegador e não são enviados ao servidor. O resultado aprovado/recusado é escolhido no seletor da simulação; isso não consulta o Mercado Pago. Consulte a [lista oficial de cartões de teste](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-cards?scope=prod).

Para uma instalação existente, faça backup e aplique uma única vez `database/migrate_mercadopago_sandbox.sql`, que adiciona os campos usados para registrar método e status do pagamento.

### Sandbox opcional do Mercado Pago

Para testar a integração externa, configure `PAYMENT_MODE=mercadopago_test`, `MP_TEST_PUBLIC_KEY` e `MP_TEST_ACCESS_TOKEN` no `.env` local, usando apenas credenciais do ambiente Teste. Nunca envie o Access Token pelo chat nem o publique no GitHub. Use os dados indicados na [documentação oficial de compras de teste](https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-payment-flow). O status Pix é consultado manualmente; este projeto não configura webhook.

No modo `mercadopago_test`, o formulário oficial do Mercado Pago coleta e tokeniza os dados do cartão. A loja envia ao Java somente o identificador e o status do pagamento, nunca o número, a validade, o CVV ou o token do cartão.

## Integração com Senior X usando Java

O projeto `Livraria-2.0-main` enviado como ZIP é um aplicativo de terminal com dados em memória. A loja PHP e MySQL continua sendo a aplicação web e a fonte dos pedidos; a nova pasta `integracao-senior-java` aproveita os conceitos de `Livro`, `Cliente` e `Venda` em um adaptador Java separado, com valores monetários em centavos e integração REST. Isso evita manter dois catálogos e estoques independentes.

O adaptador Java recebe pedidos somente quando o sandbox do Mercado Pago confirma aprovação e os envia à API e-commerce do Senior X como `PAID`. O código do produto da loja (`produtos.codigo`) é enviado como SKU; ele precisa corresponder ao SKU cadastrado no Senior. Também é necessário informar o `SENIOR_COMPANY_ID` correto da empresa. O adaptador consulta o pedido pelo ID externo antes de criar, para evitar duplicatas em uma nova tentativa.

Para ativar o fluxo local:

1. Faça backup do banco e execute `database/migrate_senior_erp.sql` uma vez. Em um banco novo, a tabela já é criada por `database/schema.sql`.
2. No `.env` local, configure as credenciais de teste do Mercado Pago e `PAYMENT_MODE=mercadopago_test`.
3. Configure o tenant, a chave da aplicação, o segredo, o `SENIOR_COMPANY_ID` e o `SENIOR_CLIENT_ID` fornecidos pelo administrador do Senior X. Não envie esses valores pelo chat nem os publique no GitHub.
4. Defina `SENIOR_SYNC_ENABLED=true` e `SENIOR_INTEGRATION_ENABLED=true`. Gere uma chave local para `SENIOR_BRIDGE_API_KEY`; o PHP e o Java leem o mesmo `.env`.
5. Com Java 17+ e Maven instalados, abra um terminal em `integracao-senior-java` e execute `mvn spring-boot:run`. O serviço escuta apenas em `127.0.0.1:8091` por padrão.
6. Inicie a loja PHP. Após uma aprovação no sandbox, consulte o recibo: ele informa se o pedido chegou ao Senior X e permite tentar novamente em caso de falha.

Se a API e-commerce ou o ambiente de teste do Senior X ainda não estiverem habilitados para a empresa, a sincronização permanecerá pendente até o administrador fornecer o acesso e o mapeamento de empresa/SKUs. A integração não altera o banco do Senior por SQL; usa a API oficial configurada.

## Classes e conceitos

- Produto: código, nome, preço em centavos e estoque; propriedades privadas e validação.
- Cliente: nome, identificação e e-mail.
- ItemPedido: compõe um produto e a quantidade comprada.
- CarrinhoDeCompras: mantém itens e calcula o total.
- Pedido: compõe cliente e itens, com transições controladas por StatusPedido.
- VendaService: reserva estoque em transação, registra o pedido e aplica o resultado do pagamento.
- GatewayPagamento: interface consumida pelo serviço. PagamentoSimulado executa o modo local; MercadoPagoGateway conecta ao sandbox opcional.
- Recibo: representa as informações mostradas após a venda.
- StatusPedido e StatusPagamento: enums para estados válidos.

O total é calculado no servidor com os preços do banco. A chave de idempotência evita pedidos duplicados ao reenviar o formulário.

## Antes de produção

Esta integração está configurada apenas para testes. Para receber dinheiro real, use credenciais de produção em uma implantação separada com HTTPS, webhook validado, reconciliação, estornos e tratamento de falhas. Não armazene dados de cartão.

## Validações automáticas no GitHub

O fluxo `.github/workflows/ci.yml` verifica a sintaxe dos arquivos PHP e executa `mvn verify` com Java 17 para compilar o adaptador Senior e rodar os testes automatizados. Essas verificações não conectam ao Mercado Pago nem ao Senior X; para isso, use credenciais de sandbox configuradas apenas no ambiente local. O fluxo começa depois que este projeto estiver em um repositório GitHub e receber um push ou pull request.

O GitHub Pages não executa o PHP nem hospeda o banco MySQL. O workflow acima apenas confere o código; para abrir a loja e testar compras no navegador, mantenha o ambiente local ou publique a aplicação em uma hospedagem que ofereça PHP e MySQL.
