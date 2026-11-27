<?php

namespace App\Modules\Fiscal\Enums;

/**
 * Estados internos do documento fiscal, independentes do provedor. Somente "Autorizado" significa documento válido;
 * nenhum estado pendente é apresentado como autorizado.
 */
enum FiscalStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Processing = 'processing';
    case Authorized = 'authorized';
    case Rejected = 'rejected';
    case Denied = 'denied';
    case CancelRequested = 'cancel_requested';
    case Cancelled = 'cancelled';
    case CommunicationFailure = 'communication_failure';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Queued => 'Aguardando processamento',
            self::Processing => 'Processando',
            self::Authorized => 'Autorizado',
            self::Rejected => 'Rejeitado',
            self::Denied => 'Denegado',
            self::CancelRequested => 'Cancelamento solicitado',
            self::Cancelled => 'Cancelado',
            self::CommunicationFailure => 'Falha de comunicação',
        };
    }

    /** Em andamento no provedor: entra na conciliação e impede nova transmissão do mesmo documento. */
    public function isPending(): bool
    {
        return in_array($this, [self::Queued, self::Processing, self::CancelRequested, self::CommunicationFailure], true);
    }

    /** Pode ser editado e (re)transmitido: rascunho ou rejeitado (corrigido e reenviado com o mesmo identificador). */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Rejected], true);
    }

    /** Ordem de precedência: um retorno antigo fora de ordem não desfaz uma situação mais avançada. */
    public function rank(): int
    {
        return match ($this) {
            self::Draft, self::CommunicationFailure => 0,
            self::Queued, self::Processing => 1,
            self::Rejected, self::Denied => 3,
            self::Authorized => 4,
            self::CancelRequested => 5,
            self::Cancelled => 6,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $status): array => ['value' => $status->value, 'label' => $status->label()], self::cases());
    }
}
