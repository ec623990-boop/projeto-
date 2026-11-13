package br.com.bibliotecavendas.senior.domain;

public record Livro(String sku, String titulo, long precoUnitarioCentavos, int quantidade) {
    public Livro {
        if (sku == null || sku.isBlank() || sku.length() > 23) throw new IllegalArgumentException("SKU de livro inválido.");
        if (titulo == null || titulo.isBlank()) throw new IllegalArgumentException("Livro sem título.");
        if (precoUnitarioCentavos <= 0 || quantidade <= 0) throw new IllegalArgumentException("Preço ou quantidade do livro inválidos.");
    }

    public long subtotalCentavos() {
        return Math.multiplyExact(precoUnitarioCentavos, quantidade);
    }
}
