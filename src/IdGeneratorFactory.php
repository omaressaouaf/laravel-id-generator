<?php

namespace Omaressaouaf\LaravelIdGenerator;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Omaressaouaf\LaravelIdGenerator\Exceptions\InvalidGeneratorException;

class IdGeneratorFactory
{
    public function generateFromConfig(string $generatorName): string
    {
        $config = config('laravel-id-generator.' . $generatorName);
        if (!$config) {
            throw new InvalidGeneratorException();
        }

        if (!Arr::has($config, 'field')) {
            throw new InvalidGeneratorException();
        }

        return $this->generate(
            $generatorName,
            Arr::get($config, 'field'),
            Arr::get($config, 'padding') ?? 5,
            Arr::get($config, 'prefix') ?? '',
            Arr::get($config, 'suffix') ?? '',
        );
    }

    public function generate(
        string $modelOrTable,
        string $field = 'id',
        int $paddingLength = 5,
        ?string $prefix = '',
        ?string $suffix = '',
    ): string {
        $prefix = $this->parseVariables($prefix);
        $suffix = $this->parseVariables($suffix);

        $prefixLength = strlen($prefix);
        $suffixLength = strlen($suffix);

        $query = is_subclass_of($modelOrTable, Model::class)
            ? $modelOrTable::query()
            : DB::table($modelOrTable);

        $havingArguments = match (DB::connection()->getDriverName()) {
            'sqlite' => [
                "SUBSTR(max_id_without_prefix, 1, LENGTH(max_id_without_prefix) - {$suffixLength}) GLOB ?",
                ['[0-9]*'],
            ],
            'pgsql' => [
                "SUBSTR(SUBSTR({$field}, {$prefixLength} + 1), 1, LENGTH(SUBSTR({$field}, {$prefixLength} + 1)) - {$suffixLength}) ~ ?",
                ['^[0-9]+$'],
            ],
            default => [
                "SUBSTR(max_id_without_prefix, 1, LENGTH(max_id_without_prefix) - {$suffixLength}) REGEXP ?",
                ['^[0-9]+$'],
            ]
        };

        $maxId = $query
            ->where($field, 'like', $prefix . '%' . $suffix)
            ->select("{$field} as max_id")
            // Select max id without prefix  : (e.g CL-00001/2022 => 00001/2022)
            ->selectRaw("SUBSTR({$field}, {$prefixLength} + 1) as max_id_without_prefix")
            // Remove suffix from previously created max_id_without_prefix and check if the sliced id is a number with regex (e.g 00001/2022 => 00001)
            ->groupBy($field)
            ->havingRaw(...$havingArguments)
            ->orderBy($field, 'desc')
            ->first()
            ?->max_id;

        // Assumed length of the entire id
        $length = $prefixLength + $paddingLength + $suffixLength;

        // adaptive length in case the stripped id length surpasses the padding length (e.g 1000 while padding is 3)
        $adaptiveLength = $paddingLength + strlen($maxId) - $length;

        // stripped max id from maxId starting from prefix length and extracting according to adaptive length
        $strippedMaxId = Str::substr($maxId, $prefixLength, $adaptiveLength);

        $nextStrippedId = (int) $strippedMaxId + 1;

        return $prefix . Str::padLeft((string) $nextStrippedId, $paddingLength, '0') . $suffix;
    }

    private function parseVariables(?string $value): ?string
    {
        if (is_null($value)) {
            return $value;
        }

        return Str::swap([
            '{DATE}' => now()->toDateString(),
            '{MONTH}' => now()->format('Y-m'),
            '{YEAR}' => now()->format('Y'),
        ], $value);
    }
}
