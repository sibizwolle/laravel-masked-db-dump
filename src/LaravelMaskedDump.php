<?php

namespace BeyondCode\LaravelMaskedDumper;

use Generator;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use BeyondCode\LaravelMaskedDumper\TableDefinitions\TableDefinition;

class LaravelMaskedDump
{
    protected DumpSchema $definition;

    protected OutputStyle $output;

    public function __construct(DumpSchema $definition, OutputStyle $output)
    {
        $this->definition = $definition;
        $this->output = $output;
    }

    public function dump(): string
    {
        return implode('', iterator_to_array($this->generate()));
    }

    public function generate(): Generator
    {
        $tables = $this->definition->getDumpTables();

        $overallTableProgress = $this->output->createProgressBar(count($tables));

        foreach ($tables as $table) {
            if ($table->shouldDumpData()) {
                foreach ($this->streamTableData($table) as $statement) {
                    yield $statement;
                }
            }

            $overallTableProgress->advance();
        }
    }

    protected function transformResultForInsert($row, TableDefinition $table, Builder $query): array
    {
        return collect($row)->map(function ($value, $column) use ($table, $query) {
            if ($columnDefinition = $table->findColumn($column)) {
                $value = $columnDefinition->modifyValue($value);
            }

            return new Expression($query->grammar->escape($value, binary: str_contains($value, "\00")));

        })->toArray();
    }

    protected function streamTableData(TableDefinition $table): Generator
    {
        $queryBuilder = $this->definition->getConnection()->table($table->getDoctrineTable()->getName());

        $table->modifyQuery($queryBuilder);

        $iterator = $queryBuilder->cursor();

        $rows = [];
        foreach ($iterator as $row) {
            $rows[] = $this->transformResultForInsert((array)$row, $table, $queryBuilder);

            if (count($rows) >= $table->getChunkSize()) {
                yield $this->compileRawInsert($rows, $queryBuilder);
                $rows = [];
            }
        }

        if (empty($rows)) {
            return;
        }

        yield $this->compileRawInsert($rows, $queryBuilder);
    }

    private function compileRawInsert(array $rows, Builder $queryBuilder): string
    {
        $rawInsert = $queryBuilder->grammar->compileInsert(
            $queryBuilder, $rows
        );

        return $rawInsert . ';' . PHP_EOL;
    }
}
