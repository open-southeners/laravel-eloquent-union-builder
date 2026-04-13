<?php

declare(strict_types=1);

namespace OpenSoutheners\LaravelEloquentUnionBuilder;

use Closure;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
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
    protected array $builders = [];

    /**
     * @var array<string, array>
     */
    protected array $selectModelsColumns = [];

    protected array $eagerLoad = [];

    protected ?string $orderByColumn = null;

    protected string $orderByDirection = 'asc';

    public function __construct(array $builders = [])
    {
        $this->builders = $builders;
    }

    public static function from(array $models): static
    {
        $unionBuilder = new static();

        foreach ($models as $model) {
            $unionBuilder->add($model::query());
        }

        return $unionBuilder;
    }

    public static function search(string $searchQuery, array $models, ?Closure $callback = null): static
    {
        $unionBuilder = new static();

        foreach ($models as $model => $value) {
            $perModelCallback = null;
            $columns = null;

            if (is_numeric($model)) {
                $model = $value;
            } elseif ($value instanceof Closure) {
                $perModelCallback = $value;
            } elseif (is_array($value)) {
                $columns = $value;
            }

            if (! class_exists($model) || ! in_array(Searchable::class, class_uses($model))) {
                throw new Exception("Model '{$model}' is invalid.");
            }

            /** @var \Laravel\Scout\Builder $scoutBuilder */
            $scoutBuilder = $model::search($searchQuery);

            if ($perModelCallback !== null) {
                $perModelCallback($scoutBuilder);
            } elseif (is_callable($callback)) {
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

    public function get(): \Illuminate\Support\Collection
    {
        $results = $this->getUnionBuilder()->get()->map(function ($result) {
            $unionModelClass = $result->union_model_class;

            return (new $unionModelClass)->newFromBuilder(
                Arr::only((array) $result, $this->selectModelsColumns[$unionModelClass] ?? [])
            );
        });

        if (! empty($this->eagerLoad)) {
            $grouped = $results->groupBy(fn ($model) => get_class($model));

            $results = $grouped->flatMap(function ($items) {
                $instance = $items->first();

                return $instance->newCollection($items->all())->load($this->eagerLoad);
            });
        }

        return collect($results);
    }

    public function paginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        return $this->getUnionBuilder()->paginate($perPage, ['*'], $pageName, $page)->through(function ($result) {
            $unionModelClass = $result->union_model_class;

            return (new $unionModelClass)->newFromBuilder(
                Arr::only((array) $result, $this->selectModelsColumns[$unionModelClass] ?? [])
            );
        });
    }

    public function simplePaginate(int $perPage = 15, string $pageName = 'page', ?int $page = null): Paginator
    {
        return $this->getUnionBuilder()->simplePaginate($perPage, ['*'], $pageName, $page)->through(function ($result) {
            $unionModelClass = $result->union_model_class;

            return (new $unionModelClass)->newFromBuilder(
                Arr::only((array) $result, $this->selectModelsColumns[$unionModelClass] ?? [])
            );
        });
    }

    public function count(): int
    {
        $unionBuilder = $this->getUnionBuilder();
        $sql = $unionBuilder->toSql();
        $connectionName = $this->builders[array_key_first($this->builders)]->getModel()->getConnectionName();

        return DB::connection($connectionName)
            ->table(DB::raw("({$sql}) as union_aggregate"))
            ->mergeBindings($unionBuilder)
            ->count();
    }

    public function toSql(): string
    {
        return $this->getUnionBuilder()->toSql();
    }

    public function dd(): void
    {
        dd($this->toSql());
    }

    public function orderByUnion(string $column, string $direction = 'asc'): static
    {
        $this->orderByColumn = $column;
        $this->orderByDirection = $direction;

        return $this;
    }

    public function with(array|string $relations): static
    {
        $this->eagerLoad = is_string($relations) ? [$relations] : $relations;

        return $this;
    }

    public function getAllSelectedColumns(): array
    {
        return array_unique(Arr::flatten($this->selectModelsColumns));
    }

    public function add(Builder $builder, array $columns = []): static
    {
        $builderModel = $builder->getModel();
        $builderModelClass = get_class($builder->getModel());

        $this->selectModelsColumns[$builderModelClass] = $columns
            ?: Schema::getColumnListing($builderModel->getTable());

        $this->builders[$builderModelClass] = $builder;

        return $this;
    }

    public function callingOnly(string $model, Closure $callback): static
    {
        if ($this->builders[$model] ?? null) {
            $callback($this->builders[$model]);
        }

        return $this;
    }

    private function getUnionBuilder(): \Illuminate\Database\Query\Builder
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
                    return in_array($item, $modelSelectedColumns) ? $item : DB::raw("Null as {$item}");
                }, $allSelectedColumns)
            );

            $builder->select($selectColumnsArr);

            $unitedBaseBuilder = is_null($unitedBaseBuilder) ? $builder->toBase() : $unitedBaseBuilder->union($builder->toBase());
        }

        if (! $unitedBaseBuilder) {
            throw new Exception('No queries found for models query union.');
        }

        if ($this->orderByColumn !== null) {
            $unitedBaseBuilder->orderBy($this->orderByColumn, $this->orderByDirection);
        }

        return $unitedBaseBuilder;
    }

    public function __call(string $method, array $arguments): static
    {
        foreach ($this->builders as $builder) {
            $this->forwardCallTo($builder, $method, $arguments);
        }

        return $this;
    }
}
