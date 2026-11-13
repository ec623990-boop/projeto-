package br.com.bibliotecavendas.senior.api;

import br.com.bibliotecavendas.senior.client.SeniorApiClient;
import br.com.bibliotecavendas.senior.client.SeniorApiException;
import org.springframework.beans.factory.annotation.Value;
import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.validation.annotation.Validated;
import org.springframework.web.bind.annotation.ExceptionHandler;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestHeader;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RestController;
import org.springframework.web.bind.MethodArgumentNotValidException;
import org.springframework.web.server.ResponseStatusException;

import jakarta.validation.Valid;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.Map;

@RestController
@RequestMapping
@Validated
public class SeniorOrderController {
    private final SeniorApiClient seniorApiClient;

    @Value("${app.bridge-api-key:}")
    private String bridgeApiKey;

    @Value("${senior.enabled:false}")
    private boolean enabled;

    public SeniorOrderController(SeniorApiClient seniorApiClient) {
        this.seniorApiClient = seniorApiClient;
    }

    @GetMapping("/health")
    public Map<String, Object> health() {
        return Map.of("status", "ok", "seniorIntegrationEnabled", enabled);
    }

    @PostMapping("/api/orders")
    public SyncOrderResponse syncPaidOrder(
            @RequestHeader(value = "X-Bridge-Key", required = false) String suppliedKey,
            @Valid @RequestBody PaidOrderRequest order
    ) {
        if (!authorized(suppliedKey)) throw new ResponseStatusException(HttpStatus.UNAUTHORIZED);
        if (!enabled) throw new SeniorApiException("A integração Senior está desativada.", 503);
        return seniorApiClient.createOrFindOrder(order);
    }

    @ExceptionHandler(SeniorApiException.class)
    public ResponseEntity<Map<String, String>> seniorError(SeniorApiException error) {
        HttpStatus status = HttpStatus.resolve(error.statusCode());
        if (status == null || status.is2xxSuccessful()) status = HttpStatus.BAD_GATEWAY;
        return ResponseEntity.status(status).body(Map.of("message", error.getMessage()));
    }

    @ExceptionHandler(IllegalArgumentException.class)
    public ResponseEntity<Map<String, String>> invalidOrder(IllegalArgumentException error) {
        return ResponseEntity.unprocessableEntity().body(Map.of("message", error.getMessage()));
    }

    @ExceptionHandler(MethodArgumentNotValidException.class)
    public ResponseEntity<Map<String, String>> invalidRequest() {
        return ResponseEntity.badRequest().body(Map.of("message", "Os dados do pedido estão incompletos ou inválidos."));
    }

    private boolean authorized(String suppliedKey) {
        if (bridgeApiKey == null || bridgeApiKey.isBlank() || suppliedKey == null) return false;
        return MessageDigest.isEqual(
                bridgeApiKey.getBytes(StandardCharsets.UTF_8),
                suppliedKey.getBytes(StandardCharsets.UTF_8)
        );
    }
}
