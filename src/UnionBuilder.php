<?php

namespace OpenSoutheners\LaravelEloquentUnionBuilder;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Traits\ForwardsCalls;
use Laravel\Scout\Searchable;

/**
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
final class UnionBuilder
{
    use ForwardsCalls;

    /**
     * @var array<\Illuminate\Database\Eloquent\Builder>
     */
    protected $builders = [];

    /**
     * @var array<string, array>
     */
    protected $selectModelsColumns = [];

    /**
     * Construct new instance of class.
     *
     * @param  array<\Illuminate\Database\Eloquent\Builder>  $builders
     */
    public function __construct(array $builders = [])
    {
        $this->builders = $builders;
    }

    /**
     * Make new instance of UnionBuilder by the following models classes.
     *
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>>  $models
     * @return \OpenSoutheners\LaravelEloquentUnionBuilder\UnionBuilder
     */
    public static function from(array $models)
    {
        $unionBuilder = new static();

        foreach ($models as $model => $columns) {
            if (is_numeric($model)) {
                $model = $columns;
                $columns = null;
            }

            $unionBuilder->add($model::query());
        }

        return $unionBuilder;
    }

    /**
     * Search by text content on the following models using Laravel Scout.
     *
     * @param  string  $searchQuery
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>>|array<class-string<\Illuminate\Database\Eloquent\Model>, array>  $models
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>>|array<class-string<\Illuminate\Database\Eloquent\Model>, array>  $models
     * @param  \Closure|null  $callback
     * @return \OpenSoutheners\LaravelEloquentUnionBuilder\UnionBuilder
     */
    public static function search(string $searchQuery, array $models, $callback = null)
    {
        $unionBuilder = new static();

        foreach ($models as $model => $columns) {
            if (is_numeric($model)) {
                $model = $columns;
                $columns = null;
            }

            if (! class_exists($model) || ! in_array(Searchable::class, class_uses($model))) {
                throw new Exception("Model '${model}' is invalid.");
            }

            /** @var \Laravel\Scout\Builder $scoutBuilder */
            $scoutBuilder = $model::search($searchQuery);

            if (is_callable($callback)) {
                $callback($scoutBuilder);
            }

            $modelSearchResultKeys = $scoutBuilder->keys();

            if ($modelSearchResultKeys->isEmpty()) {
                continue;
            }

            $unionBuilder->add(
                $model::query()->whereKey($modelSearchResultKeys->toArray()),
                $columns ?? Schema::getColumnListing((new $model)->getTable())
            );
        }

        return $unionBuilder;
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @return \Illuminate\Support\Collection
     */
    public function get()
    {
        return $this->getUnionBuilder()->get()->map(function ($result) {
            $unionModelClass = $result->union_model_class;

            return (new $unionModelClass)->forceFill(
                Arr::only((array) $result, $this->selectModelsColumns[$unionModelClass] ?? [])
            );
        });
    }

    /**
     * Get all Eloquent builder instances united into a single query builder.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function getUnionBuilder()
    {
        $unitedBaseBuilder = null;
        $allSelectedColumns = $this->getAllSelectedColumns();

        foreach ($this->builders as $builder) {
            $model = get_class($builder->getModel());
            $modelSelectedColumns = $this->selectModelsColumns[$model]
                ?? Schema::getColumnListing($builder->getModel()->getTable());

            if (! ($builder->getConnection() instanceof SQLiteConnection)) {
                $model = str_replace('\\', '\\\\', $model);
            }

            $selectColumnsArr = [];
            $selectColumnsArr[] = DB::raw("'".$model."' as union_model_class");
            $selectColumnsArr = array_merge(
                $selectColumnsArr,
                array_map(function ($item) use ($modelSelectedColumns) {
                    return in_array($item, $modelSelectedColumns) ? $item : DB::raw("Null as ${item}");
                }, $allSelectedColumns)
            );

            $builder->select($selectColumnsArr);

            $unitedBaseBuilder = is_null($unitedBaseBuilder) ? $builder->toBase() : $unitedBaseBuilder->union($builder->toBase());
        }

        if (! $unitedBaseBuilder) {
            throw new Exception('No queries found for models query union.');
        }

        return $unitedBaseBuilder;
    }

    /**
     * Get all model builders selected columns.
     *
     * @return array
     */
    public function getAllSelectedColumns()
    {
        return array_unique(Arr::flatten($this->selectModelsColumns));
    }

    /**
     * Add Eloquent query builder to this union builder selecting the following columns.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  array  $columns
     * @return $this
     */
    public function add(Builder $builder, array $columns = [])
    {
        $builderModel = $builder->getModel();
        $builderModelClass = get_class($builder->getModel());

        $this->selectModelsColumns[$builderModelClass] = $columns
            ?: Schema::getColumnListing($builderModel->getTable());

        $this->builders[$builderModelClass] = $builder;

        return $this;
    }

    /**
     * Forward next call only on the specified model's builder.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  \Closure  $callback
     * @return $this
     */
    public function callingOnly(string $model, Closure $callback)
    {
        $callback($this->builders[$model]);

        return $this;
    }

    /**
     * Forward method calls to all or some builders.
     *
     * @param  string  $method
     * @param  array  $arguments
     * @return $this
     */
    public function __call($method, $arguments)
    {
        foreach ($this->builders as $builder) {
            $this->forwardCallTo($builder, $method, $arguments);
        }

        return $this;
    }
}
