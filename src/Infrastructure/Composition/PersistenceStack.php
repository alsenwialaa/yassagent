<?php

declare(strict_types=1);

namespace YassinStore\AiAssistant\Infrastructure\Composition;

use YassinStore\AiAssistant\Infrastructure\Concurrency\TurnLeaseManager;
use YassinStore\AiAssistant\Infrastructure\Database\BrowserContinuityAuthorityRepository;
use YassinStore\AiAssistant\Infrastructure\Database\ActiveWorkInspector;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepAttemptRepository;
use YassinStore\AiAssistant\Infrastructure\Database\CartStepRepository;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationMaintenanceRepository;
use YassinStore\AiAssistant\Infrastructure\Database\ConversationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\MessageRepository;
use YassinStore\AiAssistant\Infrastructure\Database\MaintenanceGate;
use YassinStore\AiAssistant\Infrastructure\Database\OperationRepository;
use YassinStore\AiAssistant\Infrastructure\Database\TransactionManager;
use YassinStore\AiAssistant\Infrastructure\Database\TurnRepository;
use YassinStore\AiAssistant\Infrastructure\WordPress\Settings;

/** Focused construction boundary for canonical state and concurrency stores. */
final class PersistenceStack
{
    /** @var TransactionManager */ private $transactions;
    /** @var BrowserContinuityAuthorityRepository */ private $browserContinuity;
    /** @var ConversationRepository */ private $conversations;
    /** @var MessageRepository */ private $messages;
    /** @var ConversationMaintenanceRepository */ private $maintenance;
    /** @var TurnRepository */ private $turns;
    /** @var OperationRepository */ private $operations;
    /** @var CartStepRepository */ private $cartSteps;
    /** @var CartStepAttemptRepository */ private $cartStepAttempts;
    /** @var TurnLeaseManager */ private $leases;
    /** @var MaintenanceGate */ private $maintenanceGate;
    /** @var ActiveWorkInspector */ private $activeWork;

    public function __construct(Settings $settings)
    {
        $this->transactions = new TransactionManager();
        $this->leases = new TurnLeaseManager();
        $this->maintenanceGate = new MaintenanceGate();
        $this->activeWork = new ActiveWorkInspector();
        $this->browserContinuity = new BrowserContinuityAuthorityRepository(
            $this->transactions,
            $settings
        );
        $this->conversations = new ConversationRepository(
            $settings,
            $this->transactions,
            $this->leases,
            $this->activeWork
        );
        $this->messages = new MessageRepository();
        $this->maintenance = new ConversationMaintenanceRepository(
            $this->transactions,
            $settings,
            $this->maintenanceGate,
            $this->activeWork
        );
        $this->turns = new TurnRepository();
        $this->operations = new OperationRepository();
        $this->cartSteps = new CartStepRepository();
        $this->cartStepAttempts = new CartStepAttemptRepository();
    }

    public function transactions(): TransactionManager
    {
        return $this->transactions;
    }
    public function browserContinuity(): BrowserContinuityAuthorityRepository
    {
        return $this->browserContinuity;
    }
    public function conversations(): ConversationRepository
    {
        return $this->conversations;
    }
    public function messages(): MessageRepository
    {
        return $this->messages;
    }
    public function maintenance(): ConversationMaintenanceRepository
    {
        return $this->maintenance;
    }
    public function turns(): TurnRepository
    {
        return $this->turns;
    }
    public function operations(): OperationRepository
    {
        return $this->operations;
    }
    public function cartSteps(): CartStepRepository
    {
        return $this->cartSteps;
    }
    public function cartStepAttempts(): CartStepAttemptRepository
    {
        return $this->cartStepAttempts;
    }
    public function leases(): TurnLeaseManager
    {
        return $this->leases;
    }
    public function maintenanceGate(): MaintenanceGate
    {
        return $this->maintenanceGate;
    }
    public function activeWork(): ActiveWorkInspector
    {
        return $this->activeWork;
    }
}
