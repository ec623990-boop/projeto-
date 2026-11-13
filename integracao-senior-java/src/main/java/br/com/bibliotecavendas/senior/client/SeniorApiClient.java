package br.com.bibliotecavendas.senior.client;

import br.com.bibliotecavendas.senior.api.OrderMapper;
import br.com.bibliotecavendas.senior.api.PaidOrderRequest;
import br.com.bibliotecavendas.senior.api.SyncOrderResponse;
import br.com.bibliotecavendas.senior.config.SeniorProperties;
import br.com.bibliotecavendas.senior.domain.Venda;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import org.springframework.http.HttpHeaders;
import org.springframework.http.MediaType;
import org.springframework.stereotype.Service;
import org.springframework.web.client.HttpClientErrorException;
import org.springframework.web.client.RestClient;
import org.springframework.web.client.RestClientResponseException;
import org.springframework.web.util.UriComponentsBuilder;

import java.time.Instant;
import java.util.LinkedHashMap;
import java.util.Map;

@Service
public class SeniorApiClient {
    private final RestClient http;
    private final SeniorProperties properties;
    private final ObjectMapper objectMapper;
    private volatile CachedToken cachedToken;

    public SeniorApiClient(RestClient.Builder builder, SeniorProperties properties, ObjectMapper objectMapper) {
        this.http = builder.build();
        this.properties = properties;
        this.objectMapper = objectMapper;
    }

    public SyncOrderResponse createOrFindOrder(PaidOrderRequest request) {
        validateConfiguration();
        Venda sale = Venda.aPartirDoPedido(request);
        Map<String, Object> seniorRequest = OrderMapper.toSeniorRequest(sale, properties.companyId());
        @SuppressWarnings("unchecked")
        Map<String, Object> seniorOrder = (Map<String, Object>) seniorRequest.get("order");
        String externalId = (String) seniorOrder.get("externalId");

        JsonNode existing = findOrder(externalId);
        if (existing != null) {
            return new SyncOrderResponse("sincronizado", externalId, extractOrderId(existing),
                    "Pedido já existente no Senior X; nenhuma duplicata foi criada.");
        }

        JsonNode created = requestSenior("POST", "/ecommerce/actions/createOrder", seniorRequest);
        String seniorOrderId = extractOrderId(created);
        if (seniorOrderId == null || seniorOrderId.isBlank()) {
            throw new SeniorApiException("O Senior X não retornou o identificador do pedido.", 502);
        }

        return new SyncOrderResponse("sincronizado", externalId, seniorOrderId,
                "Pedido pago enviado ao Senior X.");
    }

    private JsonNode findOrder(String externalId) {
        String url = UriComponentsBuilder.fromUriString(trimTrailingSlash(properties.baseUrl()))
                .path("/ecommerce/queries/getOrder")
                .queryParam("externalId", externalId)
                .build()
                .encode()
                .toUriString();
        try {
            return authorizedRequest("GET", url, null);
        } catch (HttpClientErrorException.NotFound notFound) {
            return null;
        } catch (RestClientResponseException error) {
            throw mapApiError("consultar pedido", error);
        }
    }

    private JsonNode requestSenior(String method, String path, Object body) {
        String url = trimTrailingSlash(properties.baseUrl()) + path;
        try {
            return authorizedRequest(method, url, body);
        } catch (RestClientResponseException error) {
            throw mapApiError("criar pedido", error);
        }
    }

    private JsonNode authorizedRequest(String method, String url, Object body) {
        for (int attempt = 0; attempt < 2; attempt++) {
            try {
                JsonNode response;
                if ("GET".equals(method)) {
                    response = http.get().uri(url)
                            .headers(this::addSeniorHeaders)
                            .retrieve().body(JsonNode.class);
                } else {
                    response = http.post().uri(url)
                            .contentType(MediaType.APPLICATION_JSON)
                            .headers(this::addSeniorHeaders)
                            .body(body)
                            .retrieve().body(JsonNode.class);
                }
                return response == null ? objectMapper.createObjectNode() : response;
            } catch (HttpClientErrorException.Unauthorized unauthorized) {
                cachedToken = null;
                if (attempt == 1) throw unauthorized;
            }
        }
        throw new SeniorApiException("Não foi possível autenticar no Senior X.", 502);
    }

    private void addSeniorHeaders(HttpHeaders headers) {
        headers.setBearerAuth(accessToken());
        if (properties.clientId() != null && !properties.clientId().isBlank()) {
            headers.set("client_id", properties.clientId());
        }
    }

    private synchronized String accessToken() {
        if (cachedToken != null && cachedToken.expiresAt().isAfter(Instant.now().plusSeconds(30))) {
            return cachedToken.value();
        }

        Map<String, String> login = new LinkedHashMap<>();
        login.put("accessKey", properties.accessKey());
        login.put("secret", properties.secret());
        login.put("tenantName", properties.tenantName());
        login.put("scope", "desktop");

        try {
            JsonNode response = http.post()
                    .uri(properties.authUrl())
                    .contentType(MediaType.APPLICATION_JSON)
                    .body(login)
                    .retrieve()
                    .body(JsonNode.class);
            if (response == null || response.path("jsonToken").isMissingNode()) {
                throw new SeniorApiException("O login do Senior X não retornou um token.", 502);
            }

            JsonNode tokenData = response.path("jsonToken");
            if (tokenData.isTextual()) tokenData = objectMapper.readTree(tokenData.asText());
            String token = tokenData.path("access_token").asText("");
            long expiresIn = tokenData.path("expires_in").asLong(3600);
            if (token.isBlank()) throw new SeniorApiException("O login do Senior X retornou um token inválido.", 502);

            cachedToken = new CachedToken(token, Instant.now().plusSeconds(Math.max(60, expiresIn)));
            return token;
        } catch (RestClientResponseException error) {
            throw mapApiError("autenticar no Senior X", error);
        } catch (SeniorApiException error) {
            throw error;
        } catch (Exception error) {
            throw new SeniorApiException("Não foi possível autenticar no Senior X.", error);
        }
    }

    private void validateConfiguration() {
        if (!properties.enabled()) throw new SeniorApiException("A integração Senior está desativada.", 503);
        if (isBlank(properties.baseUrl()) || isBlank(properties.authUrl()) || isBlank(properties.tenantName())
                || isBlank(properties.accessKey()) || isBlank(properties.secret()) || isBlank(properties.companyId())) {
            throw new SeniorApiException("Configure o ambiente e as credenciais de teste do Senior X.", 503);
        }
    }

    private SeniorApiException mapApiError(String action, RestClientResponseException error) {
        return new SeniorApiException("Falha ao " + action + " no Senior X (HTTP " + error.getStatusCode().value() + ").",
                error.getStatusCode().value());
    }

    private static String extractOrderId(JsonNode response) {
        String orderId = response.path("externalId").asText("");
        if (orderId.isBlank()) orderId = response.path("order").path("id").asText("");
        if (orderId.isBlank()) orderId = response.path("id").asText("");
        return orderId.isBlank() ? null : orderId;
    }

    private static String trimTrailingSlash(String value) {
        return value == null ? "" : value.replaceAll("/+$", "");
    }

    private static boolean isBlank(String value) {
        return value == null || value.isBlank();
    }

    private record CachedToken(String value, Instant expiresAt) {}
}
