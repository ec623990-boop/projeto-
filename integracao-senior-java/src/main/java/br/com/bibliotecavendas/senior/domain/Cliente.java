package br.com.bibliotecavendas.senior.domain;

public record Cliente(String nome, String documento, String email) {
    public Cliente {
        if (nome == null || nome.isBlank()) throw new IllegalArgumentException("Cliente sem nome.");
        if (documento == null || !documento.matches("\\d{11}")) {
            throw new IllegalArgumentException("Documento de teste inválido.");
        }
        if (email == null || email.isBlank()) throw new IllegalArgumentException("Cliente sem e-mail.");
    }
}
