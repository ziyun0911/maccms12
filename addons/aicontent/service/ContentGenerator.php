<?php

namespace addons\aicontent\service;

class ContentGenerator
{
    private $model;
    private $config;

    public function __construct(string $provider = null, string $modelName = null)
    {
        $this->config = addon_config('aicontent');
        $this->model  = ModelFactory::create($provider, $modelName);
    }

    public function generate(array $data, string $contentType = 'video'): array
    {
        $prompt = $this->buildPrompt($data, $contentType);
        $raw    = $this->model->generate($prompt);
        return $this->parseResponse($raw);
    }

    public function enhance(string $draft, string $field, array $context, string $contentType): string
    {
        $lang    = $this->config['language'] ?? 'zh-cn';
        $title   = $context['title'] ?? '';
        $typeLabel = $contentType === 'article' ? 'article' : 'video/film';

        $fieldLabel = $field === 'content'
            ? 'full description'
            : 'short summary';

        $langInstruction = (strpos($lang, 'zh') === 0)
            ? 'Write in Chinese (中文).'
            : 'Write in ' . strtoupper($lang) . '.';

        $prompt = "You are a professional content editor for a media platform.\n\n"
            . "The user has written a draft {$fieldLabel} for a {$typeLabel}"
            . ($title ? " titled \"{$title}\"" : '') . ".\n\n"
            . "Draft:\n{$draft}\n\n"
            . "Please enhance this draft: fix grammar, improve clarity, make it more engaging and professional. "
            . "Keep the same meaning and approximate length. {$langInstruction} "
            . "Return ONLY the enhanced text with no explanation, no quotes, no prefix.";

        return trim($this->model->generate($prompt));
    }

    public function buildPrompt(array $data, string $contentType): string
    {
        $templateKey = 'prompt_' . $contentType;

        $template = $this->config[$templateKey]
            ?? $this->config['prompt_video']
            ?? $this->getDefaultTemplate($contentType);

        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }

        $template = preg_replace('/\{[a-z_]+\}/', 'N/A', $template);

        return $template;
    }

    public function parseResponse(string $raw): array
    {
        $raw = trim($raw);

        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', $raw);

        $decoded = json_decode($raw, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'description' => $decoded['description'] ?? '',
                'tags'        => $this->normalizeTags($decoded['tags'] ?? []),
                'seo_title'   => $decoded['seo_title']   ?? '',
                'raw'         => $raw,
            ];
        }

        return [
            'description' => $raw,
            'tags'        => [],
            'seo_title'   => '',
            'raw'         => $raw,
        ];
    }

    private function normalizeTags($tags): array
    {
        if (is_array($tags)) {
            return array_values(array_filter(array_map('strval', $tags)));
        }
        if (is_string($tags)) {
            return array_filter(array_map('trim', explode(',', $tags)));
        }
        return [];
    }

    private function getDefaultTemplate(string $contentType): string
    {
        if ($contentType === 'article') {
            return "Based on the article titled \"{title}\" in the category \"{type}\", generate content in JSON format with keys: description, tags (array), seo_title. Reply ONLY with valid JSON.";
        }
        return "Based on the video titled \"{title}\" ({year}, {area}), starring {actor}, directed by {director}, genre: {type}, generate content in JSON format with keys: description, tags (array), seo_title. Reply ONLY with valid JSON.";
    }
}
