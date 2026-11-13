package br.com.bibliotecavendas.senior.config;

import org.springframework.boot.context.properties.ConfigurationProperties;

@ConfigurationProperties(prefix = "senior")
public record SeniorProperties(
        boolean enabled,
        String baseUrl,
        String authUrl,
        String tenantName,
        String accessKey,
        String secret,
        String clientId,
        String companyId
) {}
