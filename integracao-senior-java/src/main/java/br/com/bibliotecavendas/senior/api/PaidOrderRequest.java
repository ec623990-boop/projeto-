package br.com.bibliotecavendas.senior.api;

import jakarta.validation.Valid;
import jakarta.validation.constraints.Email;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotEmpty;
import jakarta.validation.constraints.Positive;
import jakarta.validation.constraints.PositiveOrZero;
import jakarta.validation.constraints.Pattern;
import jakarta.validation.constraints.Size;

import java.util.List;

public record PaidOrderRequest(
        @Positive long orderId,
        @NotBlank String paymentStatus,
        @NotBlank String paymentProvider,
        @NotBlank @Size(max = 100) String paymentId,
        @NotBlank @Size(max = 40) String paymentMethod,
        @NotBlank @Size(max = 100) String customerName,
        @NotBlank @Email @Size(max = 190) String customerEmail,
        @NotBlank @Pattern(regexp = "\\d{11}") String customerDocument,
        @NotEmpty List<@Valid Item> items,
        @Positive long totalCents
) {
    public record Item(
            @NotBlank @Size(max = 23) String sku,
            @NotBlank @Size(max = 160) String name,
            @Positive long unitPriceCents,
            @Positive int quantity
    ) {}
}
