<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $price_cents
 * @property string $currency
 * @property string $interval
 * @property int|null $max_forms
 * @property int|null $max_submissions_per_month
 * @property array<int, string>|null $features
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'price_cents',
    'currency',
    'interval',
    'max_forms',
    'max_submissions_per_month',
    'features',
    'is_active',
    'is_default',
    'sort',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * The billing intervals a plan can declare.
     */
    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    public const INTERVAL_ONE_TIME = 'one_time';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'max_forms' => 'integer',
            'max_submissions_per_month' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * Get the subscriptions for this plan.
     *
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Get the active subscriptions for this plan.
     *
     * @return HasMany<Subscription, $this>
     */
    public function activeSubscriptions(): HasMany
    {
        return $this->subscriptions()->active();
    }

    /**
     * Pretty-print the price (e.g. "$19.00 / month").
     */
    public function formattedPrice(): string
    {
        $amount = number_format($this->price_cents / 100, 2);
        $symbol = match (strtoupper($this->currency)) {
            'USD', 'CAD', 'AUD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($this->currency).' ',
        };

        $price = $symbol.$amount;

        if ($this->interval === self::INTERVAL_ONE_TIME) {
            return $price;
        }

        return $price.' / '.($this->interval === self::INTERVAL_YEARLY ? 'year' : 'month');
    }

    /**
     * Determine whether the plan has an unlimited form cap.
     */
    public function hasUnlimitedForms(): bool
    {
        return $this->max_forms === null;
    }

    /**
     * Determine whether the plan has an unlimited monthly submission cap.
     */
    public function hasUnlimitedSubmissions(): bool
    {
        return $this->max_submissions_per_month === null;
    }

    /**
     * Scope to active plans.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to plans ordered by display position.
     *
     * @param  Builder<Plan>  $query
     * @return Builder<Plan>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
