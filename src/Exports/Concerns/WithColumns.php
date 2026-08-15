<?php

namespace pxlrbt\FilamentExcel\Exports\Concerns;

use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Tables;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Collection;
use pxlrbt\FilamentExcel\Columns\Column;
use ReflectionFunction;

use function Livewire\invade;

trait WithColumns
{
    public Closure|array $columns = [];

    public Closure|array $generatedColumns = [];

    protected ?Collection $cachedMap = null;

    protected ?string $columnsSource = null;

    public function withColumns(Closure|array|string|null $columns = null): static
    {
        if (is_callable($columns)) {
            $this->columns = $columns;

            return $this;
        }

        $columns = is_array($columns) ? $columns : func_get_args();

        $this->columns = $columns;

        return $this;
    }

    public function getColumns(): array
    {
        $columns = $this->evaluate($this->generatedColumns);

        foreach ($this->evaluate($this->columns) as $column) {
            if ($this->columnsSource === 'table' && array_key_exists($column->getName(), $columns)) {
                $column->tableColumn = $columns[$column->getName()]->tableColumn;
            }

            $columns[$column->getName()] = $column;
        }

        return $columns;
    }

    public function fromTable(): static
    {
        $this->generatedColumns = fn () => ($this->cachedMap ??= $this->createFieldMappingFromTable())->toArray();

        $this->columnsSource = 'table';

        $this->useTableQuery();

        return $this;
    }

    public function fromForm(): static
    {
        $this->generatedColumns = fn () => ($this->cachedMap ??= $this->createFieldMappingFromForm())->toArray();

        $this->columnsSource = 'form';

        return $this;
    }

    public function fromModel(): static
    {
        $this->generatedColumns = function () {
            $mapping = $this->getResourceClass() !== null
                ? $this->createFieldMappingFromForm()
                : collect();

            return collect($this->getModelClass()::first()->getAttributes())
                ->map(
                    fn ($attribute, $key) => $mapping->has($key)
                    ? Column::make($key)->heading($mapping->get($key)->getHeading())
                    : Column::make($key)
                )
                ->toArray();
        };

        $this->columnsSource = 'model';

        return $this;
    }

    protected function createFieldMappingFromForm(): Collection
    {
        /**
         * @var Form $form
         */
        $form = $this->getResourceClass()::form(new Schema($this->getLivewire()));
        $form->model($this->getModelInstance());

        $components = collect($form->getComponents());
        $extracted = collect();

        $extractComponents = function ($components, $parentPath = '') use (&$extractComponents, &$extracted) {
            foreach ($components as $component) {
                $children = $component->getChildComponents();

                if (
                    $component instanceof Repeater
                    || $component instanceof Builder
                ) {
                    continue;
                }

                if (count($children) > 0) {
                    $relationshipName = method_exists($component, 'getRelationshipName')
                        ? $component->getRelationshipName()
                        : null;

                    $newPath = $relationshipName
                        ? ($parentPath ? $parentPath.'.'.$relationshipName : $relationshipName)
                        : $parentPath;

                    $extractComponents($children, $newPath);

                    continue;
                }

                if ($component instanceof Field) {
                    $fieldName = $component->getName();
                    $fullPath = $parentPath ? $parentPath.'.'.$fieldName : $fieldName;

                    $extracted->put($fullPath, [
                        'field' => $component,
                        'path' => $fullPath,
                    ]);
                }
            }
        };

        $extractComponents($components);

        return $extracted
            ->mapWithKeys(fn ($data) => [
                $data['path'] => Column::make($data['path'])
                    ->heading($data['field']->getLabel()),
            ]);
    }

    protected function rebindFormatStateUsingForClosuresWithFallback($invadedColumn)
    {
        if ($invadedColumn->formatStateUsing === null) {
            return;
        }

        $reflect = new ReflectionFunction($invadedColumn->formatStateUsing);
        $vars = $reflect->getClosureUsedVariables();

        foreach ($vars as $name => $value) {
            // dateTime(), time() and the iso* variants default $format to a closure that
            // reads the format off the table. Resolve it here, while the column is still
            // mounted — it gets detached before the export runs. Record-dependent user
            // closures throw when evaluated this early, so those are left untouched.
            if ($value instanceof Closure) {
                $vars[$name] = rescue(fn () => $invadedColumn->evaluate($value), fn () => $value, report: false);

                continue;
            }

            if (! is_null($value)) {
                continue;
            }

            $vars[$name] = match ($name) {
                'currency' => $invadedColumn->getTable()->getDefaultCurrency(),
                'locale' => $invadedColumn->getTable()->getDefaultNumberLocale() ?? config('app.locale'),
                'format' => $invadedColumn->isDate()
                    ? $invadedColumn->getTable()->getDefaultDateDisplayFormat()
                    : $invadedColumn->getTable()->getDefaultTimeDisplayFormat(),
                default => $value,
            };
        }

        match (true) {
            $invadedColumn->isMoney() => $invadedColumn->money(...$vars),
            $invadedColumn->isNumeric() => $invadedColumn->numeric(...$vars),
            // Every iso* variant funnels through isoDate(), so isDate() alone can't tell
            // the two apart — rebinding an ISO column with date() turns 'L' into a leap
            // year flag. The closure body is the only place the difference survives.
            $invadedColumn->isDate() && $this->usesIsoFormatting($invadedColumn->formatStateUsing) => $invadedColumn->isoDate(...$vars),
            $invadedColumn->isDate() => $invadedColumn->date(...$vars),
            $invadedColumn->isTime() => $invadedColumn->time(...$vars),

            default => null,
        };
    }

    protected function usesIsoFormatting(Closure $formatStateUsing): bool
    {
        $reflect = new ReflectionFunction($formatStateUsing);

        if (! $reflect->getFileName()) {
            return false;
        }

        $source = implode('', array_slice(
            file($reflect->getFileName()),
            $reflect->getStartLine() - 1,
            $reflect->getEndLine() - $reflect->getStartLine() + 1
        ));

        return str_contains($source, 'isoFormat(');
    }

    protected function createFieldMappingFromTable(): Collection
    {
        $livewire = $this->getLivewire();

        return collect($livewire->getTable()->getColumns())
            ->when(
                $livewire->getTable()->hasToggleableColumns(),
                fn ($collection) => $collection->reject(
                    fn (Tables\Columns\Column $column) => $livewire->isTableColumnToggledHidden($column->getName())
                )
            )
            ->mapWithKeys(function (Tables\Columns\Column $column) {
                $clonedCol = clone $column;

                // Invade for protected properties
                $invadedColumn = invade($clonedCol);

                $this->rebindFormatStateUsingForClosuresWithFallback($invadedColumn);

                $exportColumn = Column::make($column->getName())
                    ->heading($column->getLabel())
                    ->getStateUsing($invadedColumn->getStateUsing)
                    ->tableColumn($clonedCol);

                rescue(fn () => $exportColumn->formatStateUsing($invadedColumn->formatStateUsing), report: false);

                return [
                    $column->getName() => $exportColumn,
                ];
            });
    }
}
