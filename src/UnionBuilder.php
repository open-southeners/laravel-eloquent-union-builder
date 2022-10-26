<?php

namespace OpenSoutheners\LaravelEloquentUnionBuilder;

use Exception;
use Illuminate\Database\Eloquent\Builder;
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
     * @var array<string>
     */
    protected $callingOnly = [];

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
     * @return UnionBuilder
     */
    public static function from(array $models)
    {
        $builders = [];

        foreach ($models as $model) {
            $builders[$model] = $model::query();
        }

        return new static($builders);
    }

    /**
     * Search by text content on the following models using Laravel Scout.
     *
     * @param  string  $searchQuery
     * @param  array<class-string<\Illuminate\Database\Eloquent\Model>>|array<class-string<\Illuminate\Database\Eloquent\Model>, array>  $models
     * @return UnionBuilder
     */
    public static function search(string $searchQuery, array $models)
    {
        $unionBuilder = new static();

        foreach ($models as $model => $columns) {
            if (is_numeric($model)) {
                $model = $columns;
                $columns = null;
            }

            if (! class_use($model, Searchable::class)) {
                throw new Exception("Model '${model}' does not use Laravel Scout.");
            }

            $modelSearchResultKeys = $model::search($searchQuery)->keys();

            if ($modelSearchResultKeys->isEmpty()) {
                continue;
            }

            $unionBuilder->add(
                $model::query()->whereKey($modelSearchResultKeys->toArray()),
                $columns ?? Schema::getColumnListing($model::getTableName())
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

            return new $unionModelClass((array) $result);
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
            $modelSelectedColumns = $this->selectModelsColumns[$model];

            $selectColumnsArr = [];
            $selectColumnsArr[] = DB::raw("'".str_replace('\\', '\\\\', $model)."' as union_model_class");
            $selectColumnsArr = array_merge(
                $selectColumnsArr,
                array_map(function ($item) use ($modelSelectedColumns) {
                    return in_array($item, $modelSelectedColumns) ? $item : DB::raw("Null as ${item}");
                }, $allSelectedColumns)
            );

            $builder->select($selectColumnsArr);

            $unitedBaseBuilder = is_null($unitedBaseBuilder) ? $builder->toBase() : $unitedBaseBuilder->union($builder);
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
     * @param  Builder  $builder
     * @param  array  $columns
     * @return UnionBuilder
     */
    public function add(Builder $builder, array $columns = [])
    {
        $builderModel = $builder->getModel();

        $this->selectModelsColumns[get_class($builderModel)] = $columns
            ?: Schema::getColumnListing($builderModel->getTable());

        $this->builders[] = $builder;

        return $this;
    }

    /**
     * Forward next call only on the specified model's builder.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return $this
     */
    public function callOnly($model)
    {
        $this->callingOnly[] = $model;

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
            if (! empty($this->callingOnly) && ! in_array(get_class($builder->getModel()), $this->callingOnly)) {
                continue;
            }

            $this->forwardCallTo($builder, $method, $arguments);
        }

        $this->callingOnly = [];

        return $this;
    }
}