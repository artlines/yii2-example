<?php

namespace app\core\entities\Staff\vo;

class WorkloadType
{
    public const TYPE_WORK = 'work';
    public const TYPE_IDLE = 'idle';
    public const TYPE_VACATION = 'vacation';
    public const TYPE_AWAY = 'away';
    public const TYPE_SICK = 'sick';

    private static $values = [
        self::TYPE_WORK,
        self::TYPE_IDLE,
        self::TYPE_VACATION,
        self::TYPE_AWAY,
        self::TYPE_SICK,
    ];

    private static $labels = [
        self::TYPE_WORK => 'Работа',
        self::TYPE_IDLE => 'Простой',
        self::TYPE_VACATION => 'Отпуск',
        self::TYPE_AWAY => 'Отгул',
        self::TYPE_SICK => 'Больничный'
    ];

    private $value;

    public function __construct(string $value)
    {
        if (!in_array($value, self::$values)) {
            throw new \RuntimeException('Wrong value: ' . $value);
        }

        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLabel(): string
    {
        return self::$labels[$this->value] ?? '-';
    }

    public static function getLabels(): array
    {
        return self::$labels;
    }

    public function isWork(): bool
    {
        return $this->value == self::TYPE_WORK;
    }

    public function isIdle(): bool
    {
        return $this->value == self::TYPE_IDLE;
    }

    public function isVacation(): bool
    {
        return $this->value == self::TYPE_VACATION;
    }

    public static function getRestTypes(): array
    {
        return array_slice(self::$values, 1);
    }
}
