<?php

namespace app\core\entities\Staff;

use app\core\entities\Staff\data\CurrencyRatesData;
use app\core\vo\Currency;

class CurrencyRate extends CurrencyRatesData
{
    public static function create(
        Currency $currency,
        float $rate,
        \DateTime $startDate,
        ?string $updatedBy = null
    ): self {
        self::checkCurrency($currency);

        $model = new static();

        $model->code = $currency->getValue();
        $model->rate = $rate;
        $model->start_time = $startDate->format('Y-m-d');

        if ($updatedBy) {
            $model->updated_by = $updatedBy;
        }

        return $model;
    }

    public function edit(
        Currency $currency,
        float $rate,
        \DateTime $startDate,
        ?string $updatedBy = null
    ): void {
        self::checkCurrency($currency);

        $this->code = $currency->getValue();
        $this->rate = $rate;
        $this->start_time = $startDate->format('Y-m-d');
        $this->updated_by = $updatedBy;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrency(): Currency
    {
        return new Currency($this->code);
    }

    public function getRate(): float
    {
        return (float) $this->rate;
    }

    public function getStartDate(): \DateTime
    {
        return \DateTime::createFromFormat('Y-m-d', $this->start_time);
    }

    public function getUpdatedAt(): ?\DateTime
    {
        return $this->updated_at ? (new \DateTime())->setTimestamp($this->updated_at) : null;
    }

    private static function checkCurrency(Currency $currency): void
    {
        if ($currency->isDefaultCurrency()) {
            throw new \DomainException('No rates for default currency');
        }
    }
}
