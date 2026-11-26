<?php

namespace Shirahcan\AiClient\Laravel\Terminal;

use Throwable;

/**
 * After a Build changes the page a record is drawn on, the assistant drafts the record's own fields its guide lists
 * (1.80.0, shared console C5; Portify's HostFieldDraft until then): a blog post's answer, search description and
 * reader questions, a landing page's headline and button. Nothing is saved here: the console offers them to Apply or
 * Dismiss, and Apply goes through the record's own save path in the product.
 *
 * AI justification (the product's to keep): the person acted, their Build changed the page. ONE small call, only in
 * the console, only when the page changed and the guide lists fields for this record.
 *
 * The product supplies its field limits, which fields it drafts only while empty, the page's text, the record's
 * facts, and the model call itself (its own AI path, prompts and gates).
 */
abstract class HostFieldDrafter
{
    private const QUESTIONS_MAX = 8;

    /**
     * The fields a kind of record has, with their labels and column limits.
     *
     * @return array<string, array{label: string, max: int}>
     */
    abstract protected function fieldSpecs(string $hostKind): array;

    /**
     * The page's words, as the model reads them.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    abstract protected function text(array $nodes): string;

    /**
     * The record's facts the model reads (its title, summary...).
     *
     * @param  array{kind: string, label: string, record: mixed}  $host
     * @return array<string, mixed>
     */
    abstract protected function recordFacts(array $host): array;

    /**
     * The model call: the payload JSON in, the drafted values out; null when it did not answer.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    abstract protected function call(array $payload): ?array;

    /**
     * Fields drafted only while the record's own value is empty: a title someone wrote is theirs.
     *
     * @return array<string, list<string>>
     */
    protected function onlyWhileEmpty(): array
    {
        return [];
    }

    /** The record's id, for the Apply that saves the drafted values on it. */
    protected function recordId(mixed $record): string
    {
        return is_object($record) && method_exists($record, 'getKey') ? (string) $record->getKey() : (string) ($record['id'] ?? '');
    }

    /** One of the record's values, to tell whether a field is still empty. */
    protected function recordValue(mixed $record, string $field): string
    {
        return trim((string) (is_array($record) ? ($record[$field] ?? '') : ($record->{$field} ?? '')));
    }

    /**
     * @param  array<string, mixed>  $guide
     * @param  array{kind: string, label: string, record: mixed}  $host
     * @param  list<array<string, mixed>>  $nodes  the page as the assistant left it
     * @return array{host: string, record_id: string, title: string, for: string, fields: list<array{key: string, label: string, value: string}>, values: array<string, mixed>}|null
     */
    public function draft(array $guide, array $host, array $nodes): ?array
    {
        $fields = $this->fieldsFor($guide, $host['kind']);
        foreach ($this->onlyWhileEmpty()[$host['kind']] ?? [] as $field) {
            if ($this->recordValue($host['record'], $field) !== '') {
                unset($fields[$field]);
            }
        }
        $text = $this->text($nodes);
        if ($fields === [] || trim($text) === '') {
            return null;
        }

        try {
            $out = $this->call([
                'guide' => ['name' => (string) ($guide['name'] ?? ''), 'instructions' => (string) ($guide['instructions'] ?? '')],
                'record' => $this->recordFacts($host),
                'fields' => array_values(array_map(static fn (string $key, array $spec) => ['key' => $key, 'label' => $spec['label'], 'max' => $spec['max'], 'rule' => $spec['rule'] ?? null], array_keys($fields), $fields)),
                'page_text' => $text,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $values = self::clean(is_array($out) ? $out : [], $fields);
        if ($values === []) {
            return null;
        }

        return [
            'host' => $host['kind'],
            'record_id' => $this->recordId($host['record']),
            'title' => 'Suggested for '.$host['label'],
            'for' => $host['label'],
            'fields' => array_values(array_map(static fn (string $key) => ['key' => $key, 'label' => $fields[$key]['label'], 'value' => self::shown($values[$key])], array_keys($values))),
            'values' => $values,
        ];
    }

    /**
     * The fields the guide lists for this kind of record, with their labels and limits. A template's own rule for a
     * field replaces the guide's words; the label and the column's limit never change.
     *
     * @param  array<string, mixed>  $guide
     * @return array<string, array{label: string, max: int, rule?: string}>
     */
    public function fieldsFor(array $guide, string $hostKind): array
    {
        $specs = $this->fieldSpecs($hostKind);
        $rules = (array) ($guide['template']['field_rules'] ?? []);
        $out = [];
        foreach ((array) ($guide['host_fields'] ?? []) as $pair) {
            $field = (string) ($pair['field'] ?? '');
            if (($pair['host'] ?? null) === $hostKind && isset($specs[$field])) {
                $out[$field] = $specs[$field];
                if (is_string($rules[$field] ?? null) && trim($rules[$field]) !== '') {
                    $out[$field]['rule'] = trim($rules[$field]);
                }
            }
        }

        return $out;
    }

    /**
     * Each value in its column's shape and limit; anything else dropped. `reader_questions` is a list of {q, a}.
     *
     * @param  array<string, mixed>  $out
     * @param  array<string, array{label: string, max: int, rule?: string}>  $fields
     * @return array<string, mixed>
     */
    public static function clean(array $out, array $fields): array
    {
        $values = [];
        foreach ($fields as $key => $spec) {
            $raw = $out[$key] ?? null;
            if ($key === 'reader_questions') {
                $questions = [];
                foreach (is_array($raw) ? $raw : [] as $item) {
                    $q = is_array($item) ? trim((string) ($item['q'] ?? '')) : '';
                    if ($q !== '') {
                        $questions[] = ['q' => mb_substr($q, 0, 300), 'a' => mb_substr(trim((string) ($item['a'] ?? '')), 0, $spec['max'])];
                    }
                }
                if ($questions !== []) {
                    $values[$key] = array_slice($questions, 0, self::QUESTIONS_MAX);
                }

                continue;
            }

            $text = is_string($raw) ? trim(preg_replace('/\s+/u', ' ', $raw) ?? '') : '';
            if ($text !== '') {
                $values[$key] = mb_substr($text, 0, $spec['max']);
            }
        }

        return $values;
    }

    /** A value as the console draws it: reader questions one per line. */
    private static function shown(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(static fn (array $qa) => 'Q: '.$qa['q'].($qa['a'] !== '' ? "\nA: ".$qa['a'] : ''), $value));
        }

        return (string) $value;
    }
}
