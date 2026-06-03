<?php

namespace App\Controller\Web;

use App\Entity\CommercialPlan;
use App\Entity\Tenant;
use App\Repository\CommercialPlanRepository;
use App\Service\ActiveTenantContext;
use App\Service\CommercialTokenFormatter;
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

#[Route('/plans')]
final class CommercialPlanController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly Environment $twig,
        private readonly ActiveTenantContext $activeTenantContext,
        private readonly ?CommercialPlanRepository $commercialPlans = null,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        return $this->renderIndexPage($this->loadPlans(), []);
    }

    #[Route('/{id}/edit', methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request): Response
    {
        if (!$this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return new RedirectResponse('/backend/login');
        }

        $plan = $this->findPlan($id);
        if (!$plan instanceof CommercialPlan) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $values = $this->formDefaults($plan);
        $errors = [];

        if ($request->isMethod('POST')) {
            $values = $this->formValuesFromRequest($request);
            if (!$this->isValidToken('commercial_plan_form_'.$plan->getId()->toRfc4122(), (string) $request->request->get('_csrf_token'))) {
                $errors[] = 'La sesión del formulario ha expirado. Vuelve a intentarlo.';
            } else {
                $errors = $this->validateForm($values);
                if ($errors === []) {
                    $this->applyFormValues($plan, $values);
                    $this->entityManager->persist($plan);
                    $this->entityManager->flush();

                    $this->addFlash('success', 'Plan comercial actualizado.');

                    return new RedirectResponse('/backend/plans');
                }
            }
        }

        return $this->renderFormPage(
            'Editar plan comercial',
            '/backend/plans/'.$plan->getId()->toRfc4122().'/edit',
            'Guardar cambios',
            $values,
            $errors,
            $plan
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadPlans(): array
    {
        $plans = $this->commercialPlans instanceof CommercialPlanRepository ? $this->commercialPlans->findAllOrdered() : [];

        return array_map([$this, 'planView'], $plans);
    }

    /**
     * @return array<string, mixed>
     */
    private function formDefaults(?CommercialPlan $plan = null): array
    {
        if (!$plan instanceof CommercialPlan) {
            return [
                'code' => 'starter',
                'name' => '',
                'description' => '',
                'active' => true,
                'featured' => false,
                'monthlyPriceEur' => '',
                'yearlyPriceEur' => '',
                'currency' => 'EUR',
                'displayOrder' => '0',
                'features' => "{}",
                'limits' => "{}",
                'stripeProductId' => '',
                'stripeMonthlyPriceId' => '',
                'stripeYearlyPriceId' => '',
            ];
        }

        return [
            'code' => $plan->getCode(),
            'name' => $plan->getName(),
            'description' => $plan->getDescription() ?? '',
            'active' => $plan->isActive(),
            'featured' => $plan->isFeatured(),
            'monthlyPriceEur' => $plan->getMonthlyPriceEur() ?? '',
            'yearlyPriceEur' => $plan->getYearlyPriceEur() ?? '',
            'currency' => $plan->getCurrency(),
            'displayOrder' => (string) $plan->getDisplayOrder(),
            'features' => json_encode($plan->getFeatures(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}",
            'limits' => json_encode($plan->getLimits(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: "{}",
            'stripeProductId' => $plan->getStripeProductId() ?? '',
            'stripeMonthlyPriceId' => $plan->getStripeMonthlyPriceId() ?? '',
            'stripeYearlyPriceId' => $plan->getStripeYearlyPriceId() ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formValuesFromRequest(Request $request): array
    {
        return [
            'code' => trim((string) $request->request->get('code', '')),
            'name' => trim((string) $request->request->get('name', '')),
            'description' => trim((string) $request->request->get('description', '')),
            'active' => $request->request->has('active'),
            'featured' => $request->request->has('featured'),
            'monthlyPriceEur' => trim((string) $request->request->get('monthlyPriceEur', '')),
            'yearlyPriceEur' => trim((string) $request->request->get('yearlyPriceEur', '')),
            'currency' => trim((string) $request->request->get('currency', 'EUR')),
            'displayOrder' => trim((string) $request->request->get('displayOrder', '0')),
            'features' => trim((string) $request->request->get('features', '{}')),
            'limits' => trim((string) $request->request->get('limits', '{}')),
            'stripeProductId' => trim((string) $request->request->get('stripeProductId', '')),
            'stripeMonthlyPriceId' => trim((string) $request->request->get('stripeMonthlyPriceId', '')),
            'stripeYearlyPriceId' => trim((string) $request->request->get('stripeYearlyPriceId', '')),
        ];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<string>
     */
    private function validateForm(array $values): array
    {
        $errors = [];

        if ($values['code'] === '') {
            $errors[] = 'El código es obligatorio.';
        } elseif (mb_strlen($values['code']) > 50) {
            $errors[] = 'El código no puede superar 50 caracteres.';
        }

        if ($values['name'] === '') {
            $errors[] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($values['name']) > 255) {
            $errors[] = 'El nombre no puede superar 255 caracteres.';
        }

        if ($values['description'] !== '' && mb_strlen($values['description']) > 5000) {
            $errors[] = 'La descripción no puede superar 5000 caracteres.';
        }

        if ($values['currency'] === '') {
            $errors[] = 'La moneda es obligatoria.';
        } elseif (mb_strlen($values['currency']) > 10) {
            $errors[] = 'La moneda no puede superar 10 caracteres.';
        }

        if (!is_numeric($values['displayOrder'])) {
            $errors[] = 'El orden debe ser numérico.';
        }

        if ($values['monthlyPriceEur'] !== '' && !is_numeric(str_replace(',', '.', $values['monthlyPriceEur']))) {
            $errors[] = 'El precio mensual debe ser numérico.';
        }

        if ($values['yearlyPriceEur'] !== '' && !is_numeric(str_replace(',', '.', $values['yearlyPriceEur']))) {
            $errors[] = 'El precio anual debe ser numérico.';
        }

        foreach (['features', 'limits'] as $field) {
            if ($values[$field] === '') {
                $values[$field] = '{}';
            }

            $decoded = json_decode((string) $values[$field], true);
            if (!is_array($decoded)) {
                $errors[] = sprintf('El campo "%s" debe contener JSON válido.', $field);
            }
        }

        foreach (['stripeProductId', 'stripeMonthlyPriceId', 'stripeYearlyPriceId'] as $field) {
            if (mb_strlen((string) $values[$field]) > 255) {
                $errors[] = sprintf('El campo "%s" no puede superar 255 caracteres.', $field);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function applyFormValues(CommercialPlan $plan, array $values): void
    {
        $plan->setName((string) $values['name']);
        $plan->setDescription($values['description'] !== '' ? (string) $values['description'] : null);
        $plan->setActive((bool) $values['active']);
        $plan->setFeatured((bool) $values['featured']);
        $plan->setMonthlyPriceEur($values['monthlyPriceEur'] !== '' ? (string) $values['monthlyPriceEur'] : null);
        $plan->setYearlyPriceEur($values['yearlyPriceEur'] !== '' ? (string) $values['yearlyPriceEur'] : null);
        $plan->setCurrency((string) $values['currency']);
        $plan->setDisplayOrder((int) $values['displayOrder']);
        $plan->setFeatures((array) json_decode((string) $values['features'], true) ?: []);
        $plan->setLimits((array) json_decode((string) $values['limits'], true) ?: []);
        $plan->setStripeProductId($values['stripeProductId'] !== '' ? (string) $values['stripeProductId'] : null);
        $plan->setStripeMonthlyPriceId($values['stripeMonthlyPriceId'] !== '' ? (string) $values['stripeMonthlyPriceId'] : null);
        $plan->setStripeYearlyPriceId($values['stripeYearlyPriceId'] !== '' ? (string) $values['stripeYearlyPriceId'] : null);
    }

    /**
     * @return array<string, mixed>
     */
    private function planView(CommercialPlan $plan): array
    {
        return [
            'id' => $plan->getId()->toRfc4122(),
            'code' => $plan->getCode(),
            'name' => $plan->getName(),
            'description' => $plan->getDescription() ?? '—',
            'active' => $plan->isActive(),
            'featured' => $plan->isFeatured(),
            'monthlyPriceEur' => $plan->getMonthlyPriceEur() ?? '—',
            'yearlyPriceEur' => $plan->getYearlyPriceEur() ?? '—',
            'currency' => $plan->getCurrency(),
            'displayOrder' => $plan->getDisplayOrder(),
            'tokensPerMonth' => $this->commercialPlanTokensPerMonthLabel($plan),
            'edit_url' => '/backend/plans/'.$plan->getId()->toRfc4122().'/edit',
            'summary' => $this->commercialPlanLabel($plan),
        ];
    }

    private function commercialPlanTokensPerMonthLabel(CommercialPlan $plan): string
    {
        $limits = $plan->getLimits();
        $tokens = $limits['included_monthly_ai_tokens'] ?? null;

        if (!is_numeric($tokens)) {
            return '—';
        }

        return CommercialTokenFormatter::formatCommercialMillionTokens((int) round((float) $tokens));
    }

    private function commercialPlanLabel(CommercialPlan $plan): string
    {
        $priceParts = array_filter([
            $plan->getMonthlyPriceEur() !== null ? $this->formatMoneyValue($plan->getMonthlyPriceEur()) : null,
            $plan->getYearlyPriceEur() !== null ? $this->formatMoneyValue($plan->getYearlyPriceEur()) : null,
        ]);

        if ($priceParts === []) {
            return $plan->getName();
        }

        return sprintf('%s (%s %s)', $plan->getName(), implode('/', $priceParts), $plan->getCurrency());
    }

    private function formatMoneyValue(string|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $formatted = rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function renderIndexPage(array $plans, array $errors): Response
    {
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= sprintf('<div class="alert alert-error">%s</div>', htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
        }

        return new Response($this->twig->render('backend/commercial_plans/index.html.twig', [
            'page_title' => 'Planes comerciales',
            'page_subtitle' => 'Gestiona el catálogo base de planes del producto.',
            'active_nav' => 'admin-commercial-plans',
            'plans' => $plans,
            'error_html' => $errorHtml,
            ...$this->backendLayoutTemplateData(),
        ]));
    }

    private function renderFormPage(
        string $pageTitle,
        string $actionUrl,
        string $submitLabel,
        array $values,
        array $errors,
        ?CommercialPlan $plan,
    ): Response {
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= sprintf('<div class="alert alert-error">%s</div>', htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8'));
        }

        return new Response($this->twig->render('backend/commercial_plans/form.html.twig', [
            'page_title' => $pageTitle,
            'page_subtitle' => 'Define precios, features, límites y preparación para Stripe.',
            'active_nav' => 'admin-commercial-plans',
            'action_url' => $actionUrl,
            'submit_label' => $submitLabel,
            'values' => $values,
            'errors_html' => $errorHtml,
            'is_edit' => $plan instanceof CommercialPlan,
            'form_token' => $this->tokenValue($plan instanceof CommercialPlan ? 'commercial_plan_form_'.$plan->getId()->toRfc4122() : 'commercial_plan_form_new'),
            ...$this->backendLayoutTemplateData(),
        ]));
    }

    private function findPlan(string $id): ?CommercialPlan
    {
        if (!$this->commercialPlans instanceof CommercialPlanRepository) {
            return null;
        }

        $plan = $this->commercialPlans->find($id);

        return $plan instanceof CommercialPlan ? $plan : null;
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
