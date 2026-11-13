package br.com.bibliotecavendas.senior.domain;

import br.com.bibliotecavendas.senior.api.PaidOrderRequest;

import java.util.List;

public record Venda(
        long pedidoId,
        String pagamentoId,
        String metodoPagamento,
        Cliente cliente,
        List<Livro> livros,
        long totalCentavos
) {
    public Venda {
        if (pedidoId <= 0) throw new IllegalArgumentException("Número de pedido inválido.");
        if (pagamentoId == null || pagamentoId.isBlank()) throw new IllegalArgumentException("Referência de pagamento ausente.");
        if (metodoPagamento == null || metodoPagamento.isBlank()) throw new IllegalArgumentException("Método de pagamento ausente.");
        if (cliente == null || livros == null || livros.isEmpty()) throw new IllegalArgumentException("Venda incompleta.");
        livros = List.copyOf(livros);
        long itensTotal = livros.stream().mapToLong(Livro::subtotalCentavos).sum();
        if (totalCentavos <= 0 || itensTotal != totalCentavos) {
            throw new IllegalArgumentException("Total da venda não confere com os itens.");
        }
    }

    public static Venda aPartirDoPedido(PaidOrderRequest request) {
        if (!"aprovado".equals(request.paymentStatus())) {
            throw new IllegalArgumentException("Somente pedido com pagamento aprovado pode ir para o Senior X.");
        }
        if (!"mercado_pago".equals(request.paymentProvider())) {
            throw new IllegalArgumentException("A integração aceita pedidos do Mercado Pago sandbox.");
        }

        Cliente customer = new Cliente(request.customerName(), request.customerDocument(), request.customerEmail());
        List<Livro> books = request.items().stream()
                .map(item -> new Livro(item.sku(), item.name(), item.unitPriceCents(), item.quantity()))
                .toList();
        return new Venda(request.orderId(), request.paymentId(), request.paymentMethod(), customer, books, request.totalCents());
    }

    public String externalId() {
        return "lv-" + pedidoId;
    }
}
