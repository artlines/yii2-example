<?php

namespace app\core\services;

use app\components\BitrixWebhookClientFactory;
use app\core\dto\BitrixDealDto;
use app\core\interfaces\LoggerInterface;
use app\core\providers\bitrix\api\Crm\DealApi;
use app\core\providers\bitrix\api\Crm\TimelineCommentApi;
use app\core\providers\bitrix\api\user\ApiUser;
use app\core\providers\openai\OpenAiApi;
use app\core\providers\openai\dto\OpenAiChatMessageDto;
use app\core\providers\openai\exceptions\OpenAiApiException;
use app\core\providers\openai\vo\OpenAiChatRole;
use app\core\providers\trello\CardDto;
use app\core\providers\trello\TrelloApi;
use app\core\services\Staff\WorkloadService;
use app\core\vo\Grade;
use app\models\LdapUsers;
use yii\helpers\ArrayHelper;

class CounselingService
{
    private $trelloApi;
    private $openAiApi;
    private $workloadService;
    private $logger;
    private $bitrixDealApi;
    private $bitrixUserApi;
    private $bitrixTimelineCommentApi;

    public function __construct(
        TrelloApi $trelloApi,
        OpenAiApi $openAiApi,
        WorkloadService $workloadService,
        LoggerInterface $logger,
        BitrixWebhookClientFactory $bitrixWebhookClientFactory
    ) {
        $this->trelloApi = $trelloApi;
        $this->openAiApi = $openAiApi;
        $this->workloadService = $workloadService;
        $this->logger = $logger;

        $this->bitrixDealApi = new DealApi(
            $bitrixWebhookClientFactory->getClient(ArrayHelper::getValue(\Yii::$app->params, 'bitrix24.hr'))
        );
        $this->bitrixUserApi = new ApiUser(
            $bitrixWebhookClientFactory->getClient(ArrayHelper::getValue(\Yii::$app->params, 'bitrix24.hr'))
        );

        $this->bitrixTimelineCommentApi = new TimelineCommentApi(
            $bitrixWebhookClientFactory->getClient(ArrayHelper::getValue(\Yii::$app->params, 'bitrix24.my_crm'))
        );
    }

    public function sendStaffRecommendationsToTrello(CardDto $cardDto): void
    {
        $chatGptResponseArray = $this->getGptResponse($cardDto->getDescription());

        if ($chatGptResponseArray) {
            $usersNeedleWorkload = $this->getUsersNeedleTextFromWorkload($chatGptResponseArray);
            $usersNeedleFailedDeals = $this->getUsersNeedleTextFromFailedDeals($chatGptResponseArray);

            $this->trelloApi->createCardComment(
                $cardDto->getId(),
                $usersNeedleWorkload . PHP_EOL . PHP_EOL . $usersNeedleFailedDeals
            );
        }
    }

    public function sendStaffRecommendationsToBitrix(int $id, int $typeId, string $description): void
    {
        $chatGptResponseArray = $this->getGptResponse($description);

        if ($chatGptResponseArray) {
            $usersNeedleWorkload = $this->getUsersNeedleTextFromWorkload($chatGptResponseArray);
            $usersNeedleFailedDeals = $this->getUsersNeedleTextFromFailedDeals($chatGptResponseArray);

            $this->bitrixTimelineCommentApi->add([
                'ENTITY_ID' => $id,
                'ENTITY_TYPE_ID' => $typeId,
                'COMMENT' => $usersNeedleWorkload . PHP_EOL . PHP_EOL . $usersNeedleFailedDeals
            ]);
        }
    }

    private function getGptResponse(string $description): ?array
    {
        if (!$description) {
            throw new \RuntimeException('Пустое описание вакансии.');
        }

        $grades = Grade::getValuesBasic();
        $profiles = array_diff(LdapUsers::getProfiles(), ['Прочее']);

        $messageText = '...';

        $message = [
            new OpenAiChatMessageDto(
                new OpenAiChatRole(OpenAiChatRole::ROLE_USER),
                $messageText
            )
        ];

        try {
            $chatGptResponse = $this->openAiApi->chatCompletion(OpenAiApi::MODEL_CHAT, $message);
            $chatGptResponseArray = json_decode($chatGptResponse, true);

            if (!$chatGptResponseArray) {
                $chatGptResponse = $this->openAiApi->chatCompletion(OpenAiApi::MODEL_CHAT, $message);
                $chatGptResponseArray = json_decode($chatGptResponse, true);
            }

            if ($chatGptResponseArray) {
                $this->logger->debug('Получен ответ gpt: ' . PHP_EOL . $chatGptResponse, 'counseling');

                return $chatGptResponseArray;
            }
        } catch (OpenAiApiException | \Exception $exception) {
            $this->logger->error($exception->getMessage() . ', trace: ' . $exception->getTraceAsString());
        }

        return null;
    }

    private function getUsersNeedleTextFromWorkload(array $chatGptResponseArray): string
    {
        $usersNeedle = '';
        $usersNeedleText = '';
        $userWorkloads = [];
        $stacks = [];

        try {
            foreach ($chatGptResponseArray as $pair) {
                foreach ($pair as $grade => $stack) {
                    $userWorkloads[] = $this->workloadService->getUsersWorkloads([
                        'profile' => $stack,
                        'grade' => $grade,
                        'is_user_marked' => true
                    ]);

                    $stacks[$stack][] = $grade;
                }
            }

            foreach ($stacks as $stack => $grades) {
                if (count($grades) == 3) {
                    $usersNeedleText .= "- {$stack} (любого уровня)" . PHP_EOL;
                } else {
                    $grades = implode(', ', $grades);
                    $usersNeedleText .= "- {$stack} ({$grades})" . PHP_EOL;
                }

                if (empty(array_merge(...$userWorkloads))) {
                    $userWorkloads[] = $this->workloadService->getUsersWorkloads([
                        'profile' => $stack,
                        'is_user_marked' => true
                    ]);
                }
            }

            $userWorkloads = array_merge(...$userWorkloads);

            $usersNeedleText = $usersNeedleText
                ? 'Из Вашего запроса были определены следующие профили кандидатов: '
                    . PHP_EOL . $usersNeedleText . PHP_EOL
                : $usersNeedleText;

            $this->logger->debug('$userWorkloads: '
                . PHP_EOL . json_encode($userWorkloads), 'counseling');

            foreach ($userWorkloads as $userWorkload) {
                $user = $userWorkload->getUser();
                $usersNeedle .= "- {$user->givenname} {$user->familyname}, {$user->profile}";
                $usersNeedle .= $user->grade ? "({$user->grade})" : '';
                $usersNeedle .= $userWorkload->getPerHourPay() ? ", ставка {$userWorkload->getPerHourPay()}" : '';
                $usersNeedle .= $user->resume ? ", ссылка на резюме: {$user->resume}" : '';
                $usersNeedle .= PHP_EOL;
            }
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . ', trace: ' . $exception->getTraceAsString());
        }

        $spreadsheetLink = '...';

        $usersNeedle = $usersNeedle
            ? $usersNeedleText . 'В пристрое (' . $spreadsheetLink . ') найдены следующие кандидаты: '
                . PHP_EOL . $usersNeedle
            : $usersNeedleText . 'В пристрое (' . $spreadsheetLink . ') сотрудники по данным профилям не найдены.';

        $this->logger->debug(
            'Получен ответ о сотрудниках пристроя: ' . PHP_EOL . $usersNeedle,
            'counseling'
        );

        return trim($usersNeedle);
    }
}
