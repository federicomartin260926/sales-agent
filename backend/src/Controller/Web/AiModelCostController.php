<?php

namespace App\Controller\Web;

use App\Entity\AiModelCostReference;
use App\Entity\Tenant;
use App\Repository\AiModelCostReferenceRepository;
use App\Service\ActiveTenantContext;
use App\Service\CommercialTokenFormatter;
use App\Service\RuntimeConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

#[Route('/ai-costs')]
final class AiModelCostController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly RuntimeConfigurationService $runtimeConfigurationService,
        private readonly ActiveTenantContext $activeTenantContext,
        private readonly ?AiModelCostReferenceRepository $aiModelCosts = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        return $this->renderIndexPage(
            $this->loadReferences(),
            $this->runtimeSnapshot(),
            []
        );
    }

    #[Route('/new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $values = $this->formDefaults();
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->formValuesFromRequest($request);
            if (!$this->isValidToken('ai_model_cost_form_new', (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateForm($values);
                if ($errors === []) {
                    $reference = new AiModelCostReference($values['usageType'], $values['model']);
                    $this->applyFormValues($reference, $values);
                    $this->entityManager->persist($reference);
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Referencia de modelo IA creada.');

                    return new RedirectResponse('/backend/ai-costs');
                }
            }
        }

        return $this->renderFormPage(
            'Nueva referencia de modelo IA',
            '/backend/ai-costs/new',
            'Guardar referencia',
            $values,
            $errors,
            false,
            null
        );
    }

    #[Route('/{id}/edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $reference = $this->findReference($id);
        if (!$reference instanceof AiModelCostReference) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->formDefaults($reference);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->formValuesFromRequest($request);
            if (!$this->isValidToken('ai_model_cost_form_'.$reference->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateForm($values, $reference);
                if ($errors === []) {
                    $this->applyFormValues($reference, $values);
                    $this->entityManager->persist($reference);
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Referencia de modelo IA actualizada.');

                    return new RedirectResponse('/backend/ai-costs');
                }
            }
        }

        return $this->renderFormPage(
            'Editar referencia de modelo IA',
            '/backend/ai-costs/'.$reference->getId()->toRfc4122().'/edit',
            'Guardar cambios',
            $values,
            $errors,
            true,
            $reference
        );
    }

    #[Route('/{id}/toggle', methods: ['POST'])]
    public function toggle(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $reference = $this->findReference($id);
        if (!$reference instanceof AiModelCostReference) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        if (!$this->isValidToken('ai_model_cost_toggle_'.$reference->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
            return new RedirectResponse('/backend/ai-costs');
        }

        $reference->setActive(!$reference->isActive());
        $this->entityManager->persist($reference);
        $this->entityManager->flush();

        $this->addFlash('success', $reference->isActive() ? 'Referencia de coste IA activada.' : 'Referencia de coste IA desactivada.');

        return new RedirectResponse('/backend/ai-costs');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadReferences(): array
    {
        $references = $this->aiModelCosts instanceof AiModelCostReferenceRepository ? $this->aiModelCosts->findAllOrdered() : [];

        if ($references === []) {
            return [
                $this->referenceView($this->fallbackChatReference()),
                $this->referenceView($this->fallbackAudioReference()),
            ];
        }

        return array_map([$this, 'referenceView'], $references);
    }

    private function fallbackChatReference(): AiModelCostReference
    {
        $reference = new AiModelCostReference(AiModelCostReference::USAGE_TYPE_LLM_CHAT, 'gpt-4.1-mini');
        $reference->setInputCostPerMillion(0.4);
        $reference->setCachedInputCostPerMillion(0.1);
        $reference->setOutputCostPerMillion(1.6);
        $reference->setCurrency('USD');
        $reference->setNotes('Referencia recomendada para chat LLM.');

        return $reference;
    }

    private function fallbackAudioReference(): AiModelCostReference
    {
        $snapshot = $this->runtimeSnapshot();
        $values = $snapshot['values'] ?? [];
        $reference = new AiModelCostReference(AiModelCostReference::USAGE_TYPE_AUDIO_TRANSCRIPTION, (string) ($values['openai_transcription_model'] ?? $values['audio_transcription_model'] ?? 'gpt-4o-mini-transcribe'));
        $reference->setCostUnit((string) ($values['audio_transcription_cost_unit'] ?? AiModelCostReference::COST_UNIT_MINUTE));
        $reference->setCostPerUnit((float) ($values['audio_transcription_cost_per_unit_eur'] ?? 0.02));
        $reference->setCurrency((string) ($values['audio_transcription_currency'] ?? 'EUR'));
        $reference->setNotes('Referencia recomendada para transcripción de audio.');

        return $reference;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeSnapshot(): array
    {
        return $this->runtimeConfigurationService->snapshot();
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefaults(?AiModelCostReference $reference = null): array
    {
        if (!$reference instanceof AiModelCostReference) {
            $snapshot = $this->runtimeSnapshot();
            $values = $snapshot['values'] ?? [];

            return [
                'usageType' => AiModelCostReference::USAGE_TYPE_LLM_CHAT,
                'model' => 'gpt-4.1-mini',
                'inputCostPerMillion' => '0.40',
                'cachedInputCostPerMillion' => '0.10',
                'outputCostPerMillion' => '1.60',
                'costUnit' => (string) ($values['audio_transcription_cost_unit'] ?? AiModelCostReference::COST_UNIT_MINUTE),
                'costPerUnit' => (string) ($values['audio_transcription_cost_per_unit_eur'] ?? '0.02'),
                'currency' => 'USD',
                'active' => true,
                'notes' => '',
            ];
        }

        return [
            'usageType' => $reference->getUsageType(),
            'model' => $reference->getModel(),
            'inputCostPerMillion' => $reference->getInputCostPerMillion() !== null ? $this->formatDecimal($reference->getInputCostPerMillion()) : '',
            'cachedInputCostPerMillion' => $reference->getCachedInputCostPerMillion() !== null ? $this->formatDecimal($reference->getCachedInputCostPerMillion()) : '',
            'outputCostPerMillion' => $reference->getOutputCostPerMillion() !== null ? $this->formatDecimal($reference->getOutputCostPerMillion()) : '',
            'costUnit' => $reference->getCostUnit() ?? AiModelCostReference::COST_UNIT_MINUTE,
            'costPerUnit' => $reference->getCostPerUnit() !== null ? $this->formatDecimal($reference->getCostPerUnit()) : '',
            'currency' => $reference->getCurrency(),
            'active' => $reference->isActive(),
            'notes' => $reference->getNotes() ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formValuesFromRequest(Request $request): array
    {
        return [
            'usageType' => trim((string) $request->request->get('usageType', AiModelCostReference::USAGE_TYPE_LLM_CHAT)),
            'model' => trim((string) $request->request->get('model', '')),
            'inputCostPerMillion' => trim((string) $request->request->get('inputCostPerMillion', '')),
            'cachedInputCostPerMillion' => trim((string) $request->request->get('cachedInputCostPerMillion', '')),
            'outputCostPerMillion' => trim((string) $request->request->get('outputCostPerMillion', '')),
            'costUnit' => trim((string) $request->request->get('costUnit', AiModelCostReference::COST_UNIT_MINUTE)),
            'costPerUnit' => trim((string) $request->request->get('costPerUnit', '')),
            'currency' => trim((string) $request->request->get('currency', '')),
            'active' => $request->request->has('active'),
            'notes' => trim((string) $request->request->get('notes', '')),
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed>|null $current
     *
     * @return list<string>
     */
    private function validateForm(array $values, ?AiModelCostReference $current = null): array
    {
        $errors = [];
        $usageType = (string) $values['usageType'];
        $model = (string) $values['model'];

        if (!in_array($usageType, [AiModelCostReference::USAGE_TYPE_LLM_CHAT, AiModelCostReference::USAGE_TYPE_AUDIO_TRANSCRIPTION], true)) {
            $errors[] = 'El tipo de uso no es válido.';
        }

        if ($model === '') {
            $errors[] = 'El modelo es obligatorio.';
        }

        if ($this->referenceExists($usageType, $model, $current)) {
            $errors[] = 'Ya existe una referencia para ese tipo de uso y modelo.';
        }

        if ($usageType === AiModelCostReference::USAGE_TYPE_LLM_CHAT) {
            foreach (['inputCostPerMillion', 'cachedInputCostPerMillion', 'outputCostPerMillion'] as $field) {
                $value = (string) $values[$field];
                if ($value === '' || !is_numeric(str_replace(',', '.', $value))) {
                    $errors[] = sprintf('El campo "%s" debe ser numérico.', $field);
                    continue;
                }

                if ((float) str_replace(',', '.', $value) < 0) {
                    $errors[] = sprintf('El campo "%s" no puede ser negativo.', $field);
                }
            }
        } else {
            if (!in_array((string) $values['costUnit'], [AiModelCostReference::COST_UNIT_MINUTE, AiModelCostReference::COST_UNIT_SECOND], true)) {
                $errors[] = 'La unidad de coste no es válida.';
            }

            $costPerUnit = (string) $values['costPerUnit'];
            if ($costPerUnit === '' || !is_numeric(str_replace(',', '.', $costPerUnit))) {
                $errors[] = 'El coste por unidad debe ser numérico.';
            } elseif ((float) str_replace(',', '.', $costPerUnit) < 0) {
                $errors[] = 'El coste por unidad no puede ser negativo.';
            }
        }

        if ((string) $values['currency'] === '') {
            $errors[] = 'La moneda es obligatoria.';
        }

        if (mb_strlen((string) $values['notes']) > 2000) {
            $errors[] = 'Las notas no pueden superar 2000 caracteres.';
        }

        return $errors;
    }

    private function applyFormValues(AiModelCostReference $reference, array $values): void
    {
        $reference->setUsageType((string) $values['usageType']);
        $reference->setModel((string) $values['model']);
        $reference->setCurrency((string) $values['currency']);
        $reference->setActive((bool) $values['active']);
        $reference->setNotes((string) $values['notes'] !== '' ? (string) $values['notes'] : null);

        if ($reference->getUsageType() === AiModelCostReference::USAGE_TYPE_LLM_CHAT) {
            $reference->setInputCostPerMillion($this->parseNullableFloat((string) $values['inputCostPerMillion']));
            $reference->setCachedInputCostPerMillion($this->parseNullableFloat((string) $values['cachedInputCostPerMillion']));
            $reference->setOutputCostPerMillion($this->parseNullableFloat((string) $values['outputCostPerMillion']));
            $reference->setCostUnit(null);
            $reference->setCostPerUnit(null);
        } else {
            $reference->setInputCostPerMillion(null);
            $reference->setCachedInputCostPerMillion(null);
            $reference->setOutputCostPerMillion(null);
            $reference->setCostUnit((string) $values['costUnit']);
            $reference->setCostPerUnit($this->parseNullableFloat((string) $values['costPerUnit']));
        }
    }

    private function parseNullableFloat(string $value): ?float
    {
        $trimmed = trim(str_replace(',', '.', $value));
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        return (float) $trimmed;
    }

    private function referenceExists(string $usageType, string $model, ?AiModelCostReference $current): bool
    {
        if ($usageType === '' || $model === '') {
            return false;
        }

        $existing = $this->aiModelCosts instanceof AiModelCostReferenceRepository
            ? $this->aiModelCosts->findOneByUsageTypeAndModel($usageType, $model)
            : null;

        if (!$existing instanceof AiModelCostReference) {
            return false;
        }

        return $current === null || $existing->getId()->toRfc4122() !== $current->getId()->toRfc4122();
    }

    /**
     * @return array{id: string, usageType: string, model: string, priceSummary: string, priceDetail: string, currency: string, active: bool, notes: string, updatedAt: string, toggle_url: string, edit_url: string, toggle_token: string}
     */
    private function referenceView(AiModelCostReference $reference): array
    {
        $priceSummary = $reference->getUsageType() === AiModelCostReference::USAGE_TYPE_AUDIO_TRANSCRIPTION
            ? sprintf(
                '%s / %s',
                $reference->getCostPerUnit() !== null ? $this->formatDecimal($reference->getCostPerUnit()) : '—',
                $reference->getCostUnit() ?? '—'
            )
            : sprintf(
                '%s / %s / %s',
                $reference->getInputCostPerMillion() !== null ? $this->formatDecimal($reference->getInputCostPerMillion()) : '—',
                $reference->getCachedInputCostPerMillion() !== null ? $this->formatDecimal($reference->getCachedInputCostPerMillion()) : '—',
                $reference->getOutputCostPerMillion() !== null ? $this->formatDecimal($reference->getOutputCostPerMillion()) : '—'
            );

        return [
            'id' => $reference->getId()->toRfc4122(),
            'usageType' => $reference->getUsageType(),
            'model' => $reference->getModel(),
            'priceSummary' => $priceSummary,
            'priceDetail' => $reference->getUsageType() === AiModelCostReference::USAGE_TYPE_AUDIO_TRANSCRIPTION
                ? 'Coste por minuto o segundo'
                : 'Input / cached / output por 1M tokens',
            'currency' => $reference->getCurrency(),
            'active' => $reference->isActive(),
            'notes' => $reference->getNotes() ?? '—',
            'updatedAt' => $reference->getUpdatedAt()->format('Y-m-d H:i'),
            'toggle_url' => '/backend/ai-costs/'.$reference->getId()->toRfc4122().'/toggle',
            'edit_url' => '/backend/ai-costs/'.$reference->getId()->toRfc4122().'/edit',
            'toggle_token' => $this->tokenValue('ai_model_cost_toggle_'.$reference->getId()->toRfc4122()),
        ];
    }

    private function renderIndexPage(array $references, array $runtimeSnapshot, array $errors): Response
    {
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= sprintf('<div class="alert alert-error">%s</div>', htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
        }

        return new Response($this->twig->render('backend/ai_model_costs/index.html.twig', [
            'page_title' => 'Modelos IA',
            'page_subtitle' => 'Modelos, precios de chat y referencias de transcripción de audio.',
            'active_nav' => 'admin-ai-costs',
            'references' => $references,
            'runtime_snapshot' => $runtimeSnapshot,
            'error_html' => $errorHtml,
            'new_url' => '/backend/ai-costs/new',
            ...$this->backendLayoutTemplateData(),
        ]));
    }

    private function renderFormPage(
        string $pageTitle,
        string $actionUrl,
        string $submitLabel,
        array $values,
        array $errors,
        bool $isEdit,
        ?AiModelCostReference $reference,
    ): Response {
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= sprintf('<div class="alert alert-error">%s</div>', htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
        }

        return new Response($this->twig->render('backend/ai_model_costs/form.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => 'Define referencias globales de coste por modelo.',
            'active_nav' => 'admin-ai-costs',
            'action_url' => $actionUrl,
            'submit_label' => $submitLabel,
            'values' => $values,
            'errors_html' => $errorHtml,
            'is_edit' => $isEdit,
            'form_token' => $this->tokenValue($isEdit && $reference instanceof AiModelCostReference ? 'ai_model_cost_form_'.$reference->getId()->toRfc4122() : 'ai_model_cost_form_new'),
            'usage_type_options' => [
                ['value' => AiModelCostReference::USAGE_TYPE_LLM_CHAT, 'label' => 'Chat LLM'],
                ['value' => AiModelCostReference::USAGE_TYPE_AUDIO_TRANSCRIPTION, 'label' => 'Transcripción de audio'],
            ],
            'cost_unit_options' => [
                ['value' => AiModelCostReference::COST_UNIT_MINUTE, 'label' => 'Por minuto'],
                ['value' => AiModelCostReference::COST_UNIT_SECOND, 'label' => 'Por segundo'],
            ],
            ...$this->backendLayoutTemplateData(),
        ]));
    }

    private function findReference(string $id): ?AiModelCostReference
    {
        if (!$this->aiModelCosts instanceof AiModelCostReferenceRepository) {
            return null;
        }

        $reference = $this->aiModelCosts->find($id);

        return $reference instanceof AiModelCostReference ? $reference : null;
    }

    private function isValidToken(string $id, string $value): bool
    {
        if ($this->csrfTokenManager === null) {
            return true;
        }

        return $this->csrfTokenManager->isTokenValid(new CsrfToken($id, $value));
    }

    private function tokenValue(string $id): string
    {
        if ($this->csrfTokenManager === null) {
            return '';
        }

        return $this->csrfTokenManager->getToken($id)->getValue();
    }

    private function formatDecimal(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * @return array<string, mixed>
     */
    private function backendLayoutTemplateData(): array
    {
        $user = $this->security->getUser();
        $displayName = is_object($user) && method_exists($user, 'getUserIdentifier')
            ? (string) $user->getUserIdentifier()
            : 'Usuario';

        return [
            'active_tenant' => $this->activeTenantTemplateData(),
            'is_super_admin' => $this->security->isGranted('ROLE_SUPER_ADMIN'),
            'can_manage_active_tenant' => $this->canManageActiveTenant(),
            'current_user_display_name' => $displayName,
            'current_user_initials' => mb_strtoupper(mb_substr($displayName, 0, 2) !== '' ? mb_substr($displayName, 0, 2) : 'SA'),
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string, edit_url: string}|null
     */
    private function activeTenantTemplateData(): ?array
    {
        $tenant = $this->activeTenantContext->getActiveTenant();
        if (!$tenant instanceof Tenant) {
            return null;
        }

        return [
            'id' => $tenant->getId()->toRfc4122(),
            'name' => $tenant->getName(),
            'slug' => $tenant->getSlug(),
            'edit_url' => sprintf('/backend/tenants/%s/edit', rawurlencode($tenant->getId()->toRfc4122())),
        ];
    }

    private function canManageActiveTenant(): bool
    {
        return $this->security->isGranted('ROLE_SUPER_ADMIN') && $this->activeTenantContext->getActiveTenant() instanceof Tenant;
    }
}
