package br.com.bibliotecavendas.senior.api;

import br.com.bibliotecavendas.senior.domain.Venda;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.Map;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertFalse;
import static org.junit.jupiter.api.Assertions.assertThrows;

class OrderMapperTest {
    @Test
    void mapsPaidTestOrderToSeniorWithoutCardData() {
        PaidOrderRequest request = new PaidOrderRequest(
                41,
                "aprovado",
                "mercado_pago",
                "test-payment-812",
                "master",
                "Cliente de Teste",
                "teste@example.invalid",
                "12345678909",
                List.of(new PaidOrderRequest.Item("LIV-001", "Livro de teste", 2990, 2)),
                5980
        );

        Venda sale = Venda.aPartirDoPedido(request);
        Map<String, Object> mapped = OrderMapper.toSeniorRequest(sale, "company-test");
        @SuppressWarnings("unchecked")
        Map<String, Object> order = (Map<String, Object>) mapped.get("order");
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> items = (List<Map<String, Object>>) order.get("itens");

        assertEquals("PAID", order.get("status"));
        assertEquals("lv-41", order.get("externalId"));
        assertEquals("59.80", order.get("totalPaid"));
        assertEquals("29.90", items.get(0).get("price"));
        assertFalse(mapped.containsKey("token"));
        assertFalse(mapped.toString().toLowerCase().contains("cvv"));
    }

    @Test
    void rejectsUnapprovedOrders() {
        PaidOrderRequest request = new PaidOrderRequest(
                42,
                "pendente",
                "mercado_pago",
                "test-payment-813",
                "pix",
                "Cliente de Teste",
                "teste@example.invalid",
                "12345678909",
                List.of(new PaidOrderRequest.Item("LIV-001", "Livro de teste", 2990, 1)),
                2990
        );

        assertThrows(IllegalArgumentException.class, () -> Venda.aPartirDoPedido(request));
    }

    @Test
    void rejectsTotalThatDoesNotMatchBookItems() {
        PaidOrderRequest request = new PaidOrderRequest(
                43,
                "aprovado",
                "mercado_pago",
                "test-payment-814",
                "master",
                "Cliente de Teste",
                "teste@example.invalid",
                "12345678909",
                List.of(new PaidOrderRequest.Item("LIV-001", "Livro de teste", 2990, 1)),
                3990
        );

        assertThrows(IllegalArgumentException.class, () -> Venda.aPartirDoPedido(request));
    }
}
