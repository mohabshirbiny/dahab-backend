<?php

namespace App\Support\Finance;

/**
 * A capped CSV export in memory (the spec 009/013 technique): UTF-8 with a
 * BOM, spreadsheet formulas neutralised (the spec 006 rule), and a closing
 * line when the cap stops it.
 */
final class Csv
{
    /** @var resource */
    private $out;

    private int $rows = 0;

    private bool $truncated = false;

    /** @param  list<string>  $header */
    public function __construct(array $header, private readonly int $cap)
    {
        $this->out = fopen('php://temp', 'r+');
        fwrite($this->out, "\xEF\xBB\xBF");
        $this->put($header);
    }

    /**
     * Add a data row; false once the cap is reached (the caller stops).
     *
     * @param  list<mixed>  $cells
     */
    public function row(array $cells): bool
    {
        if ($this->rows === $this->cap) {
            $this->truncated = true;

            return false;
        }
        $this->rows++;
        $this->put($cells);

        return true;
    }

    /** @return array{csv: string, rows: int, truncated: bool} */
    public function finish(): array
    {
        if ($this->truncated) {
            $this->put(["Export stopped at {$this->cap} rows. Narrow the dates to get the rest."]);
        }
        rewind($this->out);
        $csv = (string) stream_get_contents($this->out);
        fclose($this->out);

        return ['csv' => $csv, 'rows' => $this->rows, 'truncated' => $this->truncated];
    }

    /** @param  list<mixed>  $cells */
    private function put(array $cells): void
    {
        fputcsv($this->out, array_map([self::class, 'cell'], $cells), escape: '');
    }

    private static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && ! is_numeric($value) ? "'".$value : $value;
    }
}
