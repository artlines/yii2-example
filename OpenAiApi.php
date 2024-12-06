<?php

namespace app\core\providers\openai;

use app\core\interfaces\httpclient\HttpClientInterface;
use app\core\interfaces\httpclient\HttpResponseInterface;
use app\core\providers\openai\dto\AssistantThreadDto;
use app\core\providers\openai\dto\OpenAiAssistantRunDto;
use app\core\providers\openai\dto\OpenAiAssistantThreadMessageDto;
use app\core\providers\openai\dto\OpenAiChatMessageDto;
use app\core\providers\openai\exceptions\OpenAiApiException;

class OpenAiApi
{
    public const MODEL_CHAT = 'gpt-4';
    public const MODEL_CHAT_EXTENDED_CONTEXT = 'gpt-3.5-turbo-16k';
    public const MODEL_TEXT = 'text-davinci-003';
    public const MODEL_CODE = 'code-davinci-002';
    public const MODEL_CODE_EDIT = 'code-davinci-edit-001';

    private const DEFAULT_TOKEN_LIMIT = 2000;

    private $modelTokenLimits = [
        self::MODEL_CHAT => 8000,
        self::MODEL_TEXT => 3000,
        self::MODEL_CODE => 2000,
        self::MODEL_CHAT_EXTENDED_CONTEXT => 32000
    ];

    private const TEMPERATURE = 'temperature';

    private $defaultModelSettings = [
        self::MODEL_CHAT => [
            self::TEMPERATURE => 1
        ],
        self::MODEL_TEXT => [
            self::TEMPERATURE => 1
        ],
        self::MODEL_CHAT_EXTENDED_CONTEXT => [
            self::TEMPERATURE => 1
        ],
        self::MODEL_CODE => [
            self::TEMPERATURE => 0.3
        ],
        self::MODEL_CODE_EDIT => [
            self::TEMPERATURE => 0.3
        ]
    ];

    private $client;

    public function __construct(
        string $baseUrl,
        string $token,
        HttpClientInterface $client,
        ?string $proxyUrl = null,
        ?string $proxyAuth = null
    ) {
        $this->client = $client;

        $this->client->setBaseUrl($baseUrl);
        $this->client->setBearerAuth($token);

        if ($proxyUrl && $proxyAuth) {
            $this->client->setProxy($proxyUrl, $proxyAuth);
        }
    }

    public function completion(string $model, string $text, int $maxTokens): string
    {
        $request = $this->client->createRequest('POST', 'completions');

        $request->setData($this->getModelPayload($model));
        $request->setData([
            'prompt' => $text,
            'max_tokens' => $maxTokens
        ]);

        $response = $request->send();
        $this->checkResponse($response);

        $choice = $this->getFirstChoice($response);
        $resultMessage = $choice['text'] ?? '';

        return trim($resultMessage);
    }

    public function chatCompletion(string $model, array $messages): string
    {
        $request = $this->client->createRequest('POST', 'chat/completions');

        $request->setData($this->getModelPayload($model));
        $request->setData([
            'messages' => array_map(function (OpenAiChatMessageDto $chatMessage) {
                return [
                    'role' => $chatMessage->getRole()->getValue(),
                    'content' => $chatMessage->getContent()
                ];
            }, $messages)
        ]);

        $response = $request->send();
        $this->checkResponse($response);

        $choice = $this->getFirstChoice($response);
        $resultMessage = $choice['message']['content'] ?? '';

        return trim($resultMessage);
    }

    public function edit(string $model, ?string $text, string $instruction): string
    {
        $request = $this->client->createRequest('POST', 'edits');

        $data = $this->getModelPayload($model);

        if ($text) {
            $data['input'] = $text;
        }

        $data['instruction'] = $instruction;
        $request->setData($data);

        $response = $request->send();
        $this->checkResponse($response);

        $choice = $this->getFirstChoice($response);
        $resultMessage = $choice['text'] ?? '';

        return trim($resultMessage);
    }

    /**
     * Assistant methods
     */

    public function createThreadAndRun(string $assistantId, array $messages): OpenAiAssistantRunDto
    {
        $request = $this->client->createRequest('POST', 'threads/runs');
        $request->setHeader('OpenAI-Beta', 'assistants=v1');

        $request->setData([
            'assistant_id' => $assistantId,
            'thread' => [
                'messages' => array_map(function (OpenAiChatMessageDto $message) {
                    return [
                        'role' => $message->getRole()->getValue(),
                        'content' => $message->getContent()
                    ];
                }, $messages)
            ]
        ]);

        $response = $request->send();
        $this->checkResponse($response);

        $run = $response->getData();

        return new OpenAiAssistantRunDto($run['id'], $run['thread_id'], $run['status']);
    }

    public function createMessage(string $threadId, OpenAiChatMessageDto $message): void
    {
        $request = $this->client->createRequest('POST', "threads/{$threadId}/messages");
        $request->setHeader('OpenAI-Beta', 'assistants=v1');

        $request->setData([
            'role' => $message->getRole()->getValue(),
            'content' => $message->getContent(),
            'file_ids' => [$message->getFileId()]
        ]);

        $response = $request->send();
        $this->checkResponse($response);
    }

    public function createRun(string $assistantId, string $threadId): OpenAiAssistantRunDto
    {
        $request = $this->client->createRequest('POST', "threads/{$threadId}/runs");
        $request->setHeader('OpenAI-Beta', 'assistants=v1');

        $request->setData([
            'assistant_id' => $assistantId
        ]);

        $response = $request->send();
        $this->checkResponse($response);

        $run = $response->getData();

        return new OpenAiAssistantRunDto($run['id'], $run['thread_id'], $run['status']);
    }

    public function retrieveRun(string $threadId, string $runId): OpenAiAssistantRunDto
    {
        $request = $this->client->createRequest('GET', "threads/{$threadId}/runs/{$runId}");
        $request->setHeader('OpenAI-Beta', 'assistants=v1');

        $response = $request->send();
        $this->checkResponse($response);

        $run = $response->getData();

        return new OpenAiAssistantRunDto($run['id'], $run['thread_id'], $run['status']);
    }

    public function getAssistantAnswer(string $threadId): OpenAiAssistantThreadMessageDto
    {
        $request = $this->client->createRequest('GET', "threads/{$threadId}/messages");
        $request->setHeader('OpenAI-Beta', 'assistants=v1');

        $response = $request->send();
        $this->checkResponse($response);
        $answer = current($response->getData()['data']);
        // вырезаем ссылки на аннотации типа 【0†source】
        // https://community.openai.com/t/assistant-citations-annotations-array-is-always-empty/476752/26
        $text = preg_replace('/\【[^\]]*\】/', '', $answer['content'][0]['text']['value']);

        return new OpenAiAssistantThreadMessageDto($text);
    }

    /**
     * /Assistant methods
     */

    private function checkResponse(HttpResponseInterface $response): void
    {
        $data = $response->getData();

        if (isset($data['error'])) {
            throw new OpenAiApiException($data['error']['message'] ?? '', $response->getStatusCode());
        }
    }

    private function getFirstChoice(HttpResponseInterface $response): array
    {
        $responseData = $response->getData();
        $choices = $responseData['choices'] ?? [];

        if (!$choices) {
            throw new OpenAiApiException('No choices provided');
        }

        return array_shift($choices);
    }

    private function getModelPayload(string $model): array
    {
        return array_merge(
            [
                'model' => $model
            ],
            $this->defaultModelSettings[$model] ?? []
        );
    }

    public function getModelTokenLimit(string $model): int
    {
        return $this->modelTokenLimits[$model] ?? self::DEFAULT_TOKEN_LIMIT;
    }

    public function getEstimateTokenCount(string $text, bool $latinText = true): int
    {
        // Для текстов с латинским алфавитом токенизация выгоднее более чем в четыре раза
        $factor = $latinText ? 0.25 : 1.1;

        return round(mb_strlen($text) * $factor);
    }
}
