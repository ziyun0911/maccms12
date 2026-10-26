<?php

namespace addons\aicontent\service\models;

class QwenModel extends BaseModel
{
    const API_URL = 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions';

    public function generate(string $prompt): string
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages'   => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        $headers = [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];

        $response = $this->httpPost(self::API_URL, $headers, $payload);

        if ($response['status'] !== 200) {
            $error = $this->parseJson($response['body']);
            $msg   = $error['error']['message'] ?? $response['body'];
            throw new \RuntimeException("Qwen API error [{$response['status']}]: {$msg}");
        }

        $data = $this->parseJson($response['body']);

        return $data['choices'][0]['message']['content'] ?? '';
    }

    public function getAvailableModels(): array
    {
        return [
            'qwen-max'         => 'Qwen Max (Most powerful)',
            'qwen-plus'        => 'Qwen Plus (Balanced)',
            'qwen-turbo'       => 'Qwen Turbo (Fast)',
            'qwen-long'        => 'Qwen Long (Long context)',
        ];
    }
}
