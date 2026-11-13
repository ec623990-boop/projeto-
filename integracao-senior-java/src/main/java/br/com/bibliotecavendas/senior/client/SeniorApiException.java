package br.com.bibliotecavendas.senior.client;

public class SeniorApiException extends RuntimeException {
    private final int statusCode;

    public SeniorApiException(String message, int statusCode) {
        super(message);
        this.statusCode = statusCode;
    }

    public SeniorApiException(String message, Throwable cause) {
        super(message, cause);
        this.statusCode = 502;
    }

    public int statusCode() {
        return statusCode;
    }
}
