<?php

namespace App\Livewire\Admin;

use App\Models\AuditLog;
use App\Models\Plan;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Admin form for creating a new plan.
 */
#[Title('Admin · Create plan')]
#[Layout('layouts.admin')]
class PlanCreate extends Component
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255|alpha_dash|unique:plans,slug')]
    public string $slug = '';

    public string $description = '';

    #[Validate('required|integer|min:0')]
    public int $priceCents = 0;

    #[Validate('required|string|size:3')]
    public string $currency = 'USD';

    #[Validate('required|string')]
    public string $interval = Plan::INTERVAL_MONTHLY;

    public ?int $maxForms = null;

    public ?int $maxSubmissionsPerMonth = null;

    /** @var array<int, string> */
    public array $features = [];

    public string $newFeature = '';

    public bool $isActive = true;

    public bool $isDefault = false;

    #[Validate('integer|min:0')]
    public int $sort = 0;

    public function mount(): void
    {
        //
    }

    /**
     * Auto-generate a slug from the name until the user types one.
     */
    public function updatedName(): void
    {
        if ($this->slug === '' || $this->slug === Str::slug($this->previousName())) {
            $this->slug = Str::slug($this->name);
        }

        $this->previousName = $this->name;
    }

    public string $previousName = '';

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('plans', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
            'priceCents' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval' => ['required', Rule::in([
                Plan::INTERVAL_MONTHLY, Plan::INTERVAL_YEARLY, Plan::INTERVAL_ONE_TIME,
            ])],
            'maxForms' => ['nullable', 'integer', 'min:1'],
            'maxSubmissionsPerMonth' => ['nullable', 'integer', 'min:1'],
            'features' => ['array'],
            'features.*' => ['string', 'max:64'],
            'isActive' => ['boolean'],
            'isDefault' => ['boolean'],
            'sort' => ['integer', 'min:0'],
        ];
    }

    /**
     * Add a feature to the list.
     */
    public function addFeature(): void
    {
        $value = trim($this->newFeature);

        if ($value === '' || in_array($value, $this->features, true)) {
            $this->newFeature = '';

            return;
        }

        $this->features[] = $value;
        $this->newFeature = '';
    }

    /**
     * Remove a feature from the list.
     */
    public function removeFeature(int $index): void
    {
        unset($this->features[$index]);
        $this->features = array_values($this->features);
    }

    /**
     * Persist the new plan.
     */
    public function save(): void
    {
        $caller = Auth::user();
        abort_unless($caller->can('manage-plans'), 403);

        $data = $this->validate();

        DB::transaction(function () use ($data): void {
            if ($data['isDefault']) {
                Plan::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $plan = Plan::query()->create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?: null,
                'price_cents' => $data['priceCents'],
                'currency' => strtoupper($data['currency']),
                'interval' => $data['interval'],
                'max_forms' => $data['maxForms'] ?? null,
                'max_submissions_per_month' => $data['maxSubmissionsPerMonth'] ?? null,
                'features' => $this->features ?: null,
                'is_active' => $data['isActive'],
                'is_default' => $data['isDefault'],
                'sort' => $data['sort'],
            ]);

            $this->audit('plan.created', [
                'plan_id' => $plan->getKey(),
                'plan_slug' => $plan->slug,
            ]);
        });

        Flux::toast(variant: 'success', text: __('Plan created.'));

        $this->redirectRoute('admin.plans.index', navigate: true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function audit(string $action, array $metadata = []): void
    {
        try {
            AuditLog::query()->create([
                'user_id' => Auth::id(),
                'action' => $action,
                'metadata' => $metadata,
                'ip_address' => request()->ip(),
            ]);
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * @return array<int, string>
     */
    public function intervals(): array
    {
        return [
            Plan::INTERVAL_MONTHLY => 'Monthly',
            Plan::INTERVAL_YEARLY => 'Yearly',
            Plan::INTERVAL_ONE_TIME => 'One-time',
        ];
    }

    public function render(): View
    {
        return view('livewire.admin.plan-create');
    }
}
