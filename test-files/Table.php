<?php

namespace EcommerceGeeks\LaravelInertiaTables\Components;

use EcommerceGeeks\LaravelInertiaTables\Components\Table\Column;
use EcommerceGeeks\LaravelInertiaTables\Components\Table\ColumnCollection;
use EcommerceGeeks\LaravelInertiaTables\Interfaces\HasExport;
use EcommerceGeeks\LaravelInertiaTables\Interfaces\HasExportRoute;
use EcommerceGeeks\LaravelInertiaTables\QueryBuilder\Filters\ColumnSearchFilter;
use EcommerceGeeks\LaravelInertiaTables\QueryBuilder\Sorts\FirstNotNullSort;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

abstract class Table extends Component
{
    protected string $componentName = 'Table';

    protected bool $selectableRows = false;

    protected bool $selectingAllRowsAllowed = false;

    protected bool $paginationDisabled = false;

    protected bool $simplePagination = false;

        /**
     * @var array<mixed>
     */
    protected array $parameters = [];

    protected bool $responsive = false;

    protected ?string $caption = null;

    public function caption(?string $caption): self
    {
        $this->caption = $caption;

        return $this;
    }

    protected function shouldAddExportButton(): bool
    {
        return $this instanceof HasExport;
    }

    protected function getExportFileName(): string
    {
        return Str::lower($this->title()).'.xlsx';
    }

    protected function createExportButton(): ComponentCollection
    {
        if ($this instanceof HasExportRoute) { // If the table has a custom export route, use that
            $routeName = $this->exportRoute();
            $routeParameters = method_exists($this, 'exportRouteParams') ? $this->exportRouteParams() : [];
        } else { // Otherwise, use the current route
            $routeName = request()->route()->getName();
            $routeParameters = request()->route()->parameters();
        }

        return new ComponentCollection([
            Link::make($routeName, 'Export', request()->toArray(), false)
                ->setParameter([...$routeParameters, 'export' => $this->tableId() ?? 1])
                ->setClass('btn btn-primary'),
        ]);
    }

    /**
     * @return \Spatie\QueryBuilder\QueryBuilder|\Illuminate\Database\Query\Builder
     */
    protected function buildQuery()
    {
        $this->setQueryBuilderGETParameters();

        $query = $this->query();
        if ($query instanceof QueryBuilder) {
            $this->setAllowedSorts($query);
            $this->setAllowedFilters($query);
            if ($defaultColumn = $this->columns()->getDefaultSortByColumn()) {
                $sort = $defaultColumn->sortUsing ?? $defaultColumn->getName();
                if (is_array($sort)) {
                    $query->defaultSort(AllowedSort::custom($defaultColumn->getDefaultSortOrder().implode('~', $sort), new FirstNotNullSort));
                } else {
                    $query->defaultSort($defaultColumn->getDefaultSortOrder().$sort);
                }
            }
        }

        // Force deterministic order if sorting on non-unique columns
        $query = $query->orderBy($this->secondarySortColumn(), $this->secondarySortDirection());

        return $query;
    }

    /**
     * Sets the GET parameters for Spatie's query builder to use the table id, so we can use multiple tables on one page.
     */
    protected function setQueryBuilderGETParameters(): void
    {
        config(['query-builder.parameters.filter' => $this->tableId() ? $this->tableId().'-filter' : 'filter']);
        config(['query-builder.parameters.sort' => $this->tableId() ? $this->tableId().'-sort' : 'sort']);
    }

    public function buttonRow(): ComponentCollection
    {
        return new ComponentCollection([]);
    }

    public function allowedFilters(): Collection
    {
        return collect([]);
    }

    public function widgets(): Collection
    {
        return collect([]);
    }

    /**
     * Applies the current table id to the allowed filters.
     * This is required for the filter to be able to retrieve
     * the correct filter value from the GET parameters.
     */
    protected function allowedFiltersWithTableId(): Collection
    {
        return $this->allowedFilters()->map(fn ($f) => $f->setTableId($this->tableId()));
    }

    public function rowActions(): Collection
    {
        return collect([]);
    }

    public function bulkActions(): Collection
    {
        return collect([]);
    }

    public function setSelectableRows(bool $isSelectable): void
    {
        $this->selectableRows = $isSelectable;
    }

    public function allowSelectingAllRows(bool $isAllowed = true): void
    {
        $this->selectingAllRowsAllowed = $isAllowed;
    }

    public function disablePagination(bool $isDisabled = true): void
    {
        $this->paginationDisabled = $isDisabled;
    }

    public function setSimplePagination(): void
    {
        $this->simplePagination = true;
    }

    /**
     * @return \Spatie\QueryBuilder\QueryBuilder|\Illuminate\Database\Query\Builder
     */
    abstract protected function query();

    protected function title(): string
    {
        return '';
    }

    protected function perPage(): ?int
    {
        return $this->paginationDisabled ? PHP_INT_MAX : null;
    }

    protected function search(string $builder): mixed
    {
        return $builder->allowedFilters([
            AllowedFilter::custom('search', new ColumnSearchFilter($this->columns())),
        ]);
    }

    /**
     * @return string column name of the column to sort
     */
    protected function getSortBy(): string
    {
        if ($sort = request()->input($this->tableId() ? $this->tableId().'-sort' : 'sort')) {
            if (str_starts_with($sort, '-')) {
                return substr($sort, 1);
            }

            return $sort;
        }

        if ($defaultColumn = $this->columns()->getDefaultSortByColumn()) {
            $sort = $defaultColumn->sortUsing ?? $defaultColumn->getName();
            if (is_array($sort)) {
                return implode('~', $sort);
            } else {
                return $sort;
            }
        }

        return '';
    }

    protected function getSortDir(): string
    {
        if ($sort = request()->input($this->tableId() ? $this->tableId().'-sort' : 'sort')) {
            return str_starts_with($sort, '-') ? '-' : '';
        }

        if ($defaultColumn = $this->columns()->getDefaultSortByColumn()) {
            return $defaultColumn->getDefaultSortOrder();
        }

        return '';
    }

    protected function getPaginationData(): \Illuminate\Contracts\Pagination\Paginator
    {
        if ($this->simplePagination) {
            $paginator = $this->buildQuery()->simplePaginate($this->perPage(), ['*'], $this->tableId() ? $this->tableId().'-page' : 'page');
        } else {
            $paginator = $this->buildQuery()->paginate($this->perPage(), ['*'], $this->tableId() ? $this->tableId().'-page' : 'page');
        }

        return $paginator->setCollection($this->transformData($paginator->getCollection()));
    }

    /**
     * Custom transformation function that can be used on the data before it is passed to the table.
     */
    protected function transformData(Collection $items): Collection
    {
        return $items;
    }

    protected function filterActions(string $actions): mixed
    {
        return $actions->filter(function ($action) {
            if (! isset($action['props']['show'])) {
                return true;
            }
            if (is_callable($action['props']['show'])) {
                return $action['props']['show']();
            }

            return $action['props']['show'];
        })->values();
    }

        /**
     * @return array<string, array>
     */
    public function getProps(): array
    {
        return [
            'buttonRow' => $this->filterActions(
                $this->buttonRow()
                    ->when($this->shouldAddExportButton(), fn ($buttons) => $buttons->merge($this->createExportButton()))
                    ->map(fn ($button) => $button->toComponentArray())
            ),
            'title' => $this->title(),
            'columns' => $this->columns(),
            'paginate' => $this->getPaginationData(),
            'sort' => [
                'by' => $this->getSortBy(),
                'dir' => $this->getSortDir(),
            ],
            'filters' => $this->getActiveFilters(),
            'allowedFilters' => $this->allowedFiltersWithTableId(),
            'selectableRows' => $this->selectableRows,
            'rowActions' => $this->filterActions(
                $this->rowActions()->map(fn ($action) => $action->toComponentArray())
            ),
            'bulkActions' => $this->filterActions(
                $this->bulkActions()->map(fn ($action) => $action->toComponentArray())
            ),
            'tableId' => $this->tableId(),
            'highlightOn' => $this->highlightOn(),
            'allRowIds' => $this->getAllRowIds(),
            'paginationDisabled' => $this->paginationDisabled,
            'simplePagination' => $this->simplePagination,
            'widgets' => $this->widgets(),
            'responsive' => $this->responsive,
            'caption' => $this->caption,
        ];
    }

    public function columns(): ColumnCollection
    {
        return new ColumnCollection;
    }

    public function getActiveFilters(): Collection
    {
        // Add active filters
        $filters = $this->allowedFiltersWithTableId()
            ->filter(fn ($f) => request()->has(($this->tableId() ? $this->tableId().'-' : '').'filter.'.$f->getPublicName()))
            ->values(); // values() is needed to ensure we can always return an array to the frontend

        // Add search filter value
        if ($this->columns()->hasSearchableColumn()) {
            $filters[] =
                [
                    'label' => 'Search',
                    'type' => 'search',
                    'value' => request()->input($this->tableId() ? $this->tableId().'-filter.search' : 'filter.search'),
                    'name' => 'search',
                ];
        }

        return $filters;
    }

    protected function setAllowedFilters(QueryBuilder $builder): void
    {
        $builder->allowedFilters(
            $this->allowedFiltersWithTableId()->map(function ($filter) {
                return $filter->getAllowedFilter();
            })->merge([AllowedFilter::custom('search', new ColumnSearchFilter($this->columns()))])->toArray()
        );
    }

    protected function setAllowedSorts(QueryBuilder $builder): void
    {
        $sorts = $this->columns()->filter(function ($column) {
            return $column->isSortable();
        });

        $sorts = $sorts->map(function (Column $column) {
            $name = $column->sortUsing ?? $column->getName();
            if (is_array($name)) {
                return AllowedSort::custom(implode('~', $name), new FirstNotNullSort);
            }

            return $name;
        });

        if (count($sorts)) {
            call_user_func_array([$builder, 'allowedSorts'], $sorts->toArray());
        }
    }

        /**
     * @param array<mixed> $parameters
     */
    public function setParameters(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * Used when multiple tables are shown on a single page. If there is only one table the table ID can be null.
     */
    protected function tableId(): ?string
    {
        return null;
    }

    /**
     * Used when sorting on non-unique columns to achieve deterministic ordering.
     */
    public function secondarySortColumn(): string
    {
        return 'id';
    }

    /**
     * Used when sorting on non-unique columns to achieve deterministic ordering.
     */
    public function secondarySortDirection(): string
    {
        return 'asc';
    }

        /**
     * @param array<mixed> $props
     */
    public function render(array $props = []): Response|BinaryFileResponse
    {
        if (request()->has('export') && method_exists($this, 'export')) {
            return $this->export()->download($this->getExportFileName());
        }

        return Inertia::render($this->componentName, array_merge($props, $this->jsonSerialize()));
    }

        /**
     * @return array<mixed>
     */
    public function highlightOn(): array
    {
        return [];
    }

        /**
     * @return array<mixed>
     */
    protected function getAllRowIds(): array
    {
        if (! $this->selectingAllRowsAllowed) {
            return [];
        }

        return $this->buildQuery()->pluck('id')->toArray();
    }

    public function setResponsive(): self
    {
        $this->responsive = true;

        return $this;
    }
}
