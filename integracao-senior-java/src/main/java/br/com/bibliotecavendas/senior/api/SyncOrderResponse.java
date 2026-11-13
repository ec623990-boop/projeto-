package br.com.bibliotecavendas.senior.api;

public record SyncOrderResponse(
        String status,
        String externalId,
        String seniorOrderId,
        String message
) {}
