<?php

namespace pxlrbt\FilamentExcel\Tests\Fixtures;

use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Component;

class PostsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    /** Columns under test, set by the test before the component is booted. */
    public static array $columnsUnderTest = [];

    /** Table-level formatting defaults columns fall back to. */
    public static ?string $defaultCurrency = null;

    public function table(Table $table): Table
    {
        $table = $table
            ->query(Post::query())
            ->columns(static::$columnsUnderTest);

        if (static::$defaultCurrency !== null) {
            $table->defaultCurrency(static::$defaultCurrency);
        }

        return $table;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
