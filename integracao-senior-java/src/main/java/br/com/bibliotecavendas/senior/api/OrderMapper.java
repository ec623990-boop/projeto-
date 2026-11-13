package br.com.bibliotecavendas.senior.api;

import br.com.bibliotecavendas.senior.domain.Livro;
import br.com.bibliotecavendas.senior.domain.Venda;

import java.math.BigDecimal;
import java.time.LocalDate;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

public final class OrderMapper {
    private OrderMapper() {}

    public static Map<String, Object> toSeniorRequest(Venda source, String companyId) {
        if (companyId == null || companyId.isBlank()) {
            throw new IllegalStateException("Configure SENIOR_COMPANY_ID antes de sincronizar pedidos.");
        }

        String externalId = source.externalId();
        List<Map<String, Object>> items = new ArrayList<>();
        for (Livro item : source.livros()) {
            Map<String, Object> mappedItem = new LinkedHashMap<>();
            mappedItem.put("sku", item.sku());
            mappedItem.put("price", money(item.precoUnitarioCentavos()));
            mappedItem.put("quantity", item.quantidade());
            mappedItem.put("total", money(item.subtotalCentavos()));
            mappedItem.put("discount", "0.00");
            mappedItem.put("itemNote", item.titulo());
            items.add(mappedItem);
        }

        Map<String, Object> customer = new LinkedHashMap<>();
        customer.put("document", source.cliente().documento());
        customer.put("name", source.cliente().nome());
        customer.put("email", source.cliente().email());

        Map<String, Object> order = new LinkedHashMap<>();
        order.put("companyId", companyId);
        order.put("externalId", externalId);
        order.put("externalNumber", Long.toString(source.pedidoId()));
        order.put("status", "PAID");
        order.put("issueDate", LocalDate.now().toString());
        order.put("customer", customer);
        order.put("itens", items);
        order.put("totalPaid", money(source.totalCentavos()));
        order.put("shippingCost", "0.00");
        order.put("discount", "0.00");
        order.put("saleChannelName", "Biblioteca Vendas");
        order.put("observation", "Pagamento de teste Mercado Pago; referência " + source.pagamentoId()
                + "; método " + source.metodoPagamento());
        order.put("_discriminator", "erpxOrder");

        return Map.of("order", order);
    }

    private static String money(long cents) {
        return BigDecimal.valueOf(cents, 2).setScale(2).toPlainString();
    }
}
