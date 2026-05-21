<?php

namespace App\Tests\Unit;

use App\Domain\CommercialDomainSchema;
use PHPUnit\Framework\TestCase;

final class CommercialDomainSchemaTest extends TestCase
{
    public function testTenantSalesPolicyValidationAcceptsTheExpectedShape(): void
    {
        $policy = [
            'positioning' => 'Responder con claridad y foco comercial.',
            'qualificationFocus' => 'Identificar volumen, canal y urgencia.',
            'handoffRules' => 'Derivar a humano si piden seguimiento manual.',
            'salesBoundaries' => [
                'No prometer integraciones inexistentes.',
            ],
            'notes' => 'Usar como base para todos los leads del negocio.',
        ];

        self::assertNull(CommercialDomainSchema::validateTenantSalesPolicy($policy));
        self::assertSame(
            'Responder con claridad y foco comercial. · Identificar volumen, canal y urgencia. · Derivar a humano si piden seguimiento manual.',
            CommercialDomainSchema::summarizeTenantSalesPolicy($policy)
        );
    }

    public function testProductSalesPolicyValidationRejectsUnknownKeys(): void
    {
        $policy = [
            'positioning' => 'Oferta consultiva',
            'extra' => 'not-allowed',
        ];

        self::assertSame(
            'contains unsupported keys: extra',
            CommercialDomainSchema::validateProductSalesPolicy($policy)
        );
    }

    public function testPlaybookConfigValidationAcceptsStructuredScoringWhenProvided(): void
    {
        $config = [
            'objective' => 'Calificar leads entrantes.',
            'qualificationQuestions' => [
                '¿Qué negocio tienes?',
            ],
            'scoring' => [
                'maxScore' => 10,
                'handoffThreshold' => 7,
                'positiveSignals' => [
                    'El lead conoce su volumen.',
                ],
                'negativeSignals' => [
                    'No hay decisión clara.',
                ],
            ],
            'agendaRules' => [
                'Proponer agenda cuando el lead supera el umbral.',
            ],
            'handoffRules' => [
                'Derivar a humano si piden cierre manual.',
            ],
            'allowedActions' => [
                'askQuestion',
                'handoffToHuman',
            ],
            'notes' => 'Guía de ejemplo.',
        ];

        self::assertNull(CommercialDomainSchema::validatePlaybookConfig($config));
        self::assertSame(
            'Calificar leads entrantes. · ¿Qué negocio tienes? · score 7/10',
            CommercialDomainSchema::summarizePlaybookConfig($config)
        );
    }

    public function testPlaybookConfigValidationAcceptsEmptyConfig(): void
    {
        self::assertNull(CommercialDomainSchema::validatePlaybookConfig([]));
        self::assertSame('Sin resumen', CommercialDomainSchema::summarizePlaybookConfig([]));
    }

    public function testPlaybookConfigValidationAcceptsPartialConfig(): void
    {
        $config = [
            'objective' => 'Complementar la política general para esta campaña.',
            'handoffRules' => ['Derivar si el lead pide trato personalizado.'],
        ];

        self::assertNull(CommercialDomainSchema::validatePlaybookConfig($config));
    }
}
