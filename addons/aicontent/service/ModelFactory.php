<?php

namespace addons\aicontent\service;

use addons\aicontent\service\models\BaseModel;
use addons\aicontent\service\models\ClaudeModel;
use addons\aicontent\service\models\OpenAiModel;
use addons\aicontent\service\models\GeminiModel;
use addons\aicontent\service\models\DeepSeekModel;
use addons\aicontent\service\models\QwenModel;
use addons\aicontent\service\models\GlmModel;

class ModelFactory
{
    private static $providerMap = [
        'claude'   => ClaudeModel::class,
        'openai'   => OpenAiModel::class,
        'gemini'   => GeminiModel::class,
        'deepseek' => DeepSeekModel::class,
        'qwen'     => QwenModel::class,
        'glm'      => GlmModel::class,
    ];

    private static $keyMap = [
        'claude'   => 'claude_key',
        'openai'   => 'openai_key',
        'gemini'   => 'gemini_key',
        'deepseek' => 'deepseek_key',
        'qwen'     => 'qwen_key',
        'glm'      => 'glm_key',
    ];

    public static function create($provider = null, $model = null): BaseModel
    {
        $config   = addon_config('aicontent');
        $provider = $provider ?: ($config['default_provider'] ?? 'claude');
        $model    = $model    ?: ($config['default_model']    ?? 'claude-sonnet-4-6');

        if (!isset(self::$providerMap[$provider])) {
            throw new \InvalidArgumentException("Unknown AI provider: {$provider}");
        }

        $keyName = self::$keyMap[$provider];
        $apiKey  = $config[$keyName] ?? '';

        if (empty($apiKey)) {
            throw new \RuntimeException("API key for provider '{$provider}' is not configured.");
        }

        $maxTokens = (int) ($config['max_tokens']      ?? 1500);
        $timeout   = (int) ($config['request_timeout'] ?? 30);

        $class = self::$providerMap[$provider];
        return new $class($apiKey, $model, $maxTokens, $timeout);
    }

    public static function getProviders(): array
    {
        return [
            'claude'   => 'Claude (Anthropic)',
            'openai'   => 'OpenAI (GPT)',
            'gemini'   => 'Google Gemini',
            'deepseek' => 'DeepSeek',
            'qwen'     => 'Alibaba Qwen',
            'glm'      => 'Zhipu GLM',
        ];
    }

    public static function getModelsForProvider(string $provider): array
    {
        if (!isset(self::$providerMap[$provider])) {
            return [];
        }
        $class    = self::$providerMap[$provider];
        $instance = new $class('dummy', 'dummy');
        return $instance->getAvailableModels();
    }
}
