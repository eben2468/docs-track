<?php

declare(strict_types=1);

namespace Core;

use finfo;
use InvalidArgumentException;

/**
 * Rule-based input validation.
 *
 *   $v = Validator::make($request->all(), [
 *       'email' => 'required|email|max:150|unique:users,email',
 *       'role'  => ['required', 'in:admin,hod,staff'],
 *   ]);
 *   if ($v->fails()) { ... $v->errors() ... }
 *
 * Rules: required, nullable, string, numeric, integer, email, date, confirmed,
 * min:n, max:n, in:a,b, unique:table,column[,ignoreId[,idColumn]], exists:table,column,
 * file, mimes:pdf,jpg (extension whitelist + finfo MIME check).
 *
 * Fields without "required" that are empty skip all other rules.
 * min/max compare numbers for numeric/integer fields, kilobytes for files,
 * item counts for arrays, and character length for strings.
 */
class Validator
{
    /** Allowed MIME types per file extension for the "mimes" rule. */
    private const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'svg' => ['image/svg+xml'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'txt' => ['text/plain'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /** @var array<string, list<string>> */
    private array $rules = [];

    private bool $validated = false;

    /**
     * @param array<string, mixed> $data Input values; file fields hold $_FILES-style arrays
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $labels Optional human-readable field names
     */
    public function __construct(
        private readonly array $data,
        array $rules,
        private readonly array $labels = [],
    ) {
        foreach ($rules as $field => $fieldRules) {
            $this->rules[$field] = is_array($fieldRules) ? $fieldRules : explode('|', $fieldRules);
        }
    }

    /**
     * Create a validator and run it.
     *
     * @param array<string, mixed> $data
     * @param array<string, string|list<string>> $rules
     * @param array<string, string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): static
    {
        $validator = new static($data, $rules, $labels);
        $validator->validate();

        return $validator;
    }

    /**
     * Run every rule. Safe to call more than once.
     */
    public function validate(): void
    {
        if ($this->validated) {
            return;
        }
        $this->validated = true;

        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;
            $isFileField = $this->isFileArray($value) || in_array('file', $rules, true);

            if (!in_array('required', $rules, true) && $this->isEmpty($value)) {
                continue;
            }

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, '');
                $error = $this->check($field, $name, $param, $value, $rules, $isFileField);

                if ($error !== null) {
                    $this->errors[$field][] = $error;
                    if ($name === 'required' || $name === 'file') {
                        break;
                    }
                }
            }
        }
    }

    public function fails(): bool
    {
        $this->validate();

        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return !$this->fails();
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        $this->validate();

        return $this->errors;
    }

    /**
     * The first error for a field, or null.
     */
    public function first(string $field): ?string
    {
        return $this->errors()[$field][0] ?? null;
    }

    /**
     * Only the fields that have rules: strings trimmed, empty strings turned into null.
     *
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            $value = $this->data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;
            }
            $out[$field] = $value;
        }

        return $out;
    }

    /**
     * Whether a file entry came from a real HTTP upload. Overridable for tests.
     */
    protected function isUploadedFile(string $tmpPath): bool
    {
        return is_uploaded_file($tmpPath);
    }

    /**
     * Database used by unique/exists. Overridable for tests.
     */
    protected function db(): Database
    {
        return Database::instance();
    }

    /**
     * @param list<string> $rules
     */
    private function check(string $field, string $rule, string $param, mixed $value, array $rules, bool $isFile): ?string
    {
        $label = $this->label($field);

        return match ($rule) {
            'required' => $this->isEmpty($value) ? "$label is required." : null,
            'nullable' => null,
            'string' => is_string($value) ? null : "$label must be text.",
            'numeric' => is_numeric($value) ? null : "$label must be a number.",
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false ? null : "$label must be a whole number.",
            'email' => (is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false)
                ? null : "$label must be a valid email address.",
            'date' => $this->isDate($value) ? null : "$label must be a valid date (YYYY-MM-DD).",
            'confirmed' => ($value === ($this->data[$field . '_confirmation'] ?? null))
                ? null : "$label confirmation does not match.",
            'min' => $this->checkSize($label, $value, (float) $param, $rules, $isFile, true),
            'max' => $this->checkSize($label, $value, (float) $param, $rules, $isFile, false),
            'in' => in_array((string) (is_scalar($value) ? $value : ''), explode(',', $param), true)
                ? null : "$label is not a valid choice.",
            'unique' => $this->checkUnique($label, $value, $param),
            'exists' => $this->checkExists($label, $value, $param),
            'file' => $this->isValidUpload($value) ? null : "$label must be a successfully uploaded file.",
            'mimes' => $this->checkMimes($label, $value, $param),
            default => throw new InvalidArgumentException("Unknown validation rule [$rule]."),
        };
    }

    /**
     * @param list<string> $rules
     */
    private function checkSize(string $label, mixed $value, float $limit, array $rules, bool $isFile, bool $isMin): ?string
    {
        $limitText = rtrim(rtrim(number_format($limit, 2, '.', ''), '0'), '.');

        if ($isFile) {
            $size = (float) ($value['size'] ?? 0) / 1024;
            $unit = 'kilobytes';
        } elseif (in_array('numeric', $rules, true) || in_array('integer', $rules, true)) {
            if (!is_numeric($value)) {
                return null; // numeric/integer rule reports this
            }
            $size = (float) $value;
            $unit = '';
        } elseif (is_array($value)) {
            $size = count($value);
            $unit = 'items';
        } else {
            $size = mb_strlen(trim((string) (is_scalar($value) ? $value : '')));
            $unit = 'characters';
        }

        $ok = $isMin ? $size >= $limit : $size <= $limit;
        if ($ok) {
            return null;
        }

        $word = $isMin ? 'at least' : 'not be greater than';
        $suffix = $unit === '' ? '' : " $unit";

        return $isMin ? "$label must be $word $limitText$suffix." : "$label must $word $limitText$suffix.";
    }

    private function checkUnique(string $label, mixed $value, string $param): ?string
    {
        $parts = array_map('trim', explode(',', $param));
        [$table, $column] = $this->tableAndColumn($parts);
        $ignoreId = $parts[2] ?? '';
        $idColumn = $this->identifier($parts[3] ?? 'id');

        $sql = "SELECT COUNT(*) FROM `$table` WHERE `$column` = ?";
        $bindings = [is_string($value) ? trim($value) : $value];
        if ($ignoreId !== '' && strtolower($ignoreId) !== 'null') {
            $sql .= " AND `$idColumn` <> ?";
            $bindings[] = $ignoreId;
        }

        return (int) $this->db()->fetchValue($sql, $bindings) > 0 ? "$label has already been taken." : null;
    }

    private function checkExists(string $label, mixed $value, string $param): ?string
    {
        [$table, $column] = $this->tableAndColumn(array_map('trim', explode(',', $param)));
        $count = (int) $this->db()->fetchValue("SELECT COUNT(*) FROM `$table` WHERE `$column` = ?", [$value]);

        return $count > 0 ? null : "$label is not a valid choice.";
    }

    private function checkMimes(string $label, mixed $value, string $param): ?string
    {
        $allowed = array_map('strtolower', array_map('trim', explode(',', $param)));
        $message = "$label must be a file of type: " . implode(', ', $allowed) . '.';

        if (!$this->isValidUpload($value)) {
            return $message;
        }

        $extension = strtolower(pathinfo((string) ($value['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, $allowed, true)) {
            return $message;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file((string) $value['tmp_name']);

        return in_array($detected, self::MIME_MAP[$extension] ?? [], true) ? null : $message;
    }

    private function isValidUpload(mixed $value): bool
    {
        return $this->isFileArray($value)
            && ($value['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_string($value['tmp_name'] ?? null)
            && $this->isUploadedFile($value['tmp_name']);
    }

    private function isFileArray(mixed $value): bool
    {
        return is_array($value) && array_key_exists('tmp_name', $value) && array_key_exists('error', $value);
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if ($this->isFileArray($value)) {
            return ($value['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
        }

        return is_array($value) && $value === [];
    }

    private function isDate(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @param list<string> $parts
     * @return array{0: string, 1: string}
     */
    private function tableAndColumn(array $parts): array
    {
        if (($parts[0] ?? '') === '' || ($parts[1] ?? '') === '') {
            throw new InvalidArgumentException('unique/exists rules need "table,column".');
        }

        return [$this->identifier($parts[0]), $this->identifier($parts[1])];
    }

    /**
     * Table/column names cannot be bound as parameters, so they must be plain identifiers.
     */
    private function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid SQL identifier [$name] in validation rule.");
        }

        return $name;
    }

    private function label(string $field): string
    {
        return $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }
}
