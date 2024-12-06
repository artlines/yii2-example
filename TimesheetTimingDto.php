<?php

namespace app\core\dto;

class TimesheetTimingDto
{
    private $id;
    private $project;
    private $userEmail;
    private $status;
    private $comment;
    private $dateReceive;
    private $dateApproval;
    private $reportPeriod;
    private $isReceiveLate = false;
    private $isApprovalLate = false;

    public function __construct(
        int $id,
        BitrixProjectDto $project,
        string $userEmail,
        string $status,
        ?string $comment,
        \DateTime $dateReceive,
        ?\DateTime $dateApproval,
        \DatePeriod $reportPeriod
    ) {
        $this->id = $id;
        $this->project = $project;
        $this->userEmail = $userEmail;
        $this->status = $status;
        $this->comment = $comment;
        $this->dateReceive = $dateReceive;
        $this->dateApproval = $dateApproval;
        $this->reportPeriod = $reportPeriod;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUserEmail(): string
    {
        return $this->userEmail;
    }

    public function getProject(): BitrixProjectDto
    {
        return $this->project;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getComment(): ?string
    {
        return $this->comment ?: null;
    }

    public function getDateReceive(): \DateTime
    {
        return $this->dateReceive;
    }

    public function getDateApproval(): ?\DateTime
    {
        return $this->dateApproval;
    }

    public function getReportPeriod(): \DatePeriod
    {
        return $this->reportPeriod;
    }

    public function isReceiveLate(): bool
    {
        return $this->isReceiveLate;
    }

    public function setReceiveLate(bool $isReceiveLate = true): void
    {
        $this->isReceiveLate = $isReceiveLate;
    }

    public function isApprovalLate(): bool
    {
        return $this->isApprovalLate;
    }

    public function setApprovalLate(bool $isApprovalLate = true): void
    {
        $this->isApprovalLate = $isApprovalLate;
    }
}
