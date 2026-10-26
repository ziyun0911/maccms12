<?php

namespace addons\aicontent\service\models;

abstract class BaseModel
{
    protected $apiKey;

    protected $model;

    protected $maxTokens;

    protected $timeout;

    public function __construct(string $apiKey, string $model, int $maxTokens = 1500, int $timeout = 30)
    {
        $this->apiKey    = $apiKey;
        $this->model     = $model;
        $this->maxTokens = $maxTokens;
        $this->timeout   = $timeout;
    }

    abstract public function generate(string $prompt): string;

    abstract public function getAvailableModels(): array;

    public function testConnection(): bool
    {
        try {
            $result = $this->generate('Reply with the single word: OK');
            return !empty($result);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected function httpPost(string $url, array $headers, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => $this->buildHeaders($headers),
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_VERBOSE        => false,
            ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $safeError = preg_replace('/https?:\/\/\S+/', '[API_URL]', $error);
            throw new \RuntimeException("cURL error: {$safeError}");
        }

        return ['status' => $status, 'body' => $body];
    }

    private function buildHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $out[] = "{$key}: {$value}";
        }
        return $out;
    }

    protected function parseJson(string $body): array
    {
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
        }
        return $data;
    }
}
