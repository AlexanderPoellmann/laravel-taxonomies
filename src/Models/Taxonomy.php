<?php namespace Lecturize\Taxonomies\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * Class Taxonomy
 * @package Lecturize\Taxonomies\Models
 * @property int                 $id
 * @property string|null         $parent_id
 * @property Taxonomy|null       $parent
 * @property EloquentCollection  $children
 * @property EloquentCollection  $siblings
 * @property EloquentCollection  $taxables
 * @property string|null         $alias_id
 * @property Taxonomy|null       $alias
 * @property string              $term_id
 * @property Term                $term
 * @property string              $taxonomy
 * @property string|null         $description
 * @property string|null         $content
 * @property string|null         $lead
 * @property int|null            $sort
 * @property array|null          $properties
 */
class Taxonomy extends Model
{
    use SoftDeletes;

    /** @inheritdoc */
    protected $fillable = [
        'parent_id',
        'alias_id',
        'term_id',
        'taxonomy',

        'description',
        'content',
        'lead',

        'sort',

        'properties',
    ];

    /** @inheritdoc */
    protected $casts = [
        'properties' => 'array',
    ];

    /** @inheritdoc */
    protected $dates = [
        'deleted_at',
    ];

    /** @inheritdoc */
    protected $with = [
        'term',
    ];

    /** @inheritdoc */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->table = config('lecturize.taxonomies.taxonomies.table', 'taxonomies');
    }

    /** @inheritdoc */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if ($model->getConnection()
                      ->getSchemaBuilder()
                      ->hasColumn($model->getTable(), 'uuid'))
                $model->uuid = \Webpatser\Uuid\Uuid::generate()->string;
        });

        static::saving(function ($model) {
            if (isset($model->term) && $model->term->title && ! $model->description)
                $model->description = $model->term->title;

            if (! $model->sort) {
                $sort = ($siblings = $model->siblings()->get()) ? $siblings->max('sort') : 0;
                $model->sort = ($sort + 1);
            }
        });
    }

    /**
     * Get the term, that will be displayed as this taxonomies (categories) title.
     *
     * @return BelongsTo
     */
    public function term() {
        return $this->belongsTo(config('lecturize.taxonomies.terms.model', Term::class));
    }

    /**
     * Get the parent taxonomy (categories).
     *
     * @return BelongsTo
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(config('lecturize.taxonomies.taxonomies.model', Taxonomy::class), 'parent_id');
    }

    /**
     * Get the children taxonomies (categories).
     *
     * @return HasMany
     */
    public function children(): HasMany
    {
        return $this->hasMany(config('lecturize.taxonomies.taxonomies.model', Taxonomy::class), 'parent_id');
    }

    /**
     * Get the children taxonomies (categories).
     *
     * @return Builder
     */
    public function siblings(): Builder
    {
        $class = config('lecturize.taxonomies.taxonomies.model', Taxonomy::class);
        return (new $class)->taxonomy($this->taxonomy)
                           ->where('parent_id', $this->parent_id)
                           ->orderBy('sort');
    }

    /**
     * Get the parent taxonomy (categories).
     *
     * @return BelongsTo
     */
    public function alias(): BelongsTo
    {
        return $this->belongsTo(config('lecturize.taxonomies.taxonomies.model', Taxonomy::class), 'alias_id');
    }

    /**
     * Return the related items.
     *
     * @return HasMany
     */
    public function taxables(): HasMany
    {
        return $this->hasMany(Taxable::class, 'taxonomy_id');
    }

    /**
     * An example for related posts.
     *
     * @return MorphToMany
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(config('lecturize.community.posts.model', 'App\Models\Posts\Post'), 'taxable', 'taxables');
    }

    /**
     * An example for related products.
     *
     * @return MorphToMany
     */
    public function products(): MorphToMany
    {
        return $this->morphedByMany(config('lecturize.shop.products.model', 'Lecturize\Shop\Products\Product'), 'taxable', 'taxables');
    }

    /**
     * Get the breadcrumbs for this Taxonomy.
     *
     * @param  bool  $exclude_self
     * @return Collection
     * @throws Exception
     */
    public function getBreadcrumbs(bool $exclude_self = true): Collection
    {
        $key = "taxonomies.{$this->id}.breadcrumbs";
        $key.= $exclude_self ? '.self-excluded' : '';

        return cache()->remember($key, now()->addMonth(), function() use($exclude_self) {
            $parameters = $this->getParentBreadcrumbs();

            if (! $exclude_self)
                $parameters->push($this->taxonomy);

            return $parameters->reverse()->values();
        });
    }

    /**
     * Add parent breadcrumb.
     *
     * @param  Collection|null  $parameters
     * @return Collection
     * @throws Exception
     */
    function getParentBreadcrumbs(?Collection $parameters = null): Collection
    {
        if ($parameters === null)
            $parameters = collect();

        $parameters->push([
            'title'  => $this->term->title,
            'slug'   => $this->term->slug,
            'params' => $this->getRouteParameters(),
        ]);

        if ($parent = $this->parent)
            return $parent->getParentBreadcrumbs($parameters);

        return $parameters;
    }

    /**
     * Get route parameters.
     *
     * @param  bool  $exclude_taxonomy
     * @return array
     * @throws Exception
     */
    public function getRouteParameters(bool $exclude_taxonomy = true): array
    {
        $key = "taxonomies.{$this->id}.breadcrumbs";
        $key.= $exclude_taxonomy ? '.without-taxonomy' : '';

        return cache()->remember($key, now()->addMonth(), function() use($exclude_taxonomy) {
            $parameters = $this->getParentSlugs();

            if (! $exclude_taxonomy)
                array_push($parameters, $this->taxonomy);

            return array_reverse($parameters);
        });
    }

    /**
     * Get slugs of parent terms.
     *
     * @param  array  $parameters
     * @return array
     */
    function getParentSlugs(array $parameters = []): array
    {
        array_push($parameters, $this->term->slug);

        if ($parent = $this->parent)
            return $parent->getParentSlugs($parameters);

        return $parameters;
    }

    /**
     * Scope by a given taxonomy (e.g. "blog_cat" for blog posts or "shop_cat" for shop products).
     *
     * @param  Builder  $query
     * @param  string   $taxonomy
     * @return Builder
     */
    public function scopeTaxonomy(Builder $query, string $taxonomy): Builder
    {
        return $query->where('taxonomy', $taxonomy);
    }

    /**
     * Scope by a given taxonomy prefix (e.g. to retrieve both "shop_cat_a" and "shop_cat_b" you would scope "shop_cat%").
     *
     * @param  Builder  $query
     * @param  string   $taxonomy_prefix
     * @return Builder
     */
    public function scopeTaxonomyStartsWith(Builder $query, string $taxonomy_prefix): Builder
    {
        return $query->where('taxonomy', 'like', "$taxonomy_prefix%");
    }

    /**
     * Scope by given taxonomies array, e.g. ['shop_cat_a', 'shop_cat_b'].
     *
     * @param  Builder  $query
     * @param  array    $taxonomies
     * @return Builder
     */
    public function scopeTaxonomies(Builder $query, array $taxonomies): Builder
    {
        return $query->whereIn('taxonomy', $taxonomies);
    }

    /**
     * Scope terms (category title) by given taxonomy.
     *
     * @param  Builder     $query
     * @param  string|int  $term
     * @param  string      $term_field
     * @return Builder
     */
    public function scopeTerm(Builder $query, $term, string $term_field = 'title'): Builder
    {
        $term_field = ! in_array($term_field, ['id', 'title', 'slug']) ? 'title' : $term_field;

        return $query->whereHas('term', function(Builder $q) use($term, $term_field) {
            $q->where($term_field, $term);
        });
    }

    /**
     * A simple search scope.
     *
     * @param  Builder  $query
     * @param  string   $term
     * @param  string   $taxonomy
     * @return Builder
     */
    public function scopeSearch(Builder $query, string $term, string $taxonomy): Builder
    {
        return $query->whereHas('term', function(Builder $q) use($term, $taxonomy) {
            $q->where('title', 'like', '%'. $term .'%');
        });
    }
}
