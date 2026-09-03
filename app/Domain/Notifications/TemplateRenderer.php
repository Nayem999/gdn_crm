<?php

namespace App\Domain\Notifications;

/**
 * Fills {{merge.fields}} in a template.
 *
 * Templates are admin-authored text, not Blade. They are never compiled or
 * evaluated — a template is scanned for {{name}} and those are replaced, so an
 * administrator cannot turn a message into executable code, and neither can
 * anything that ends up in the merge data.
 */
class TemplateRenderer
{
    /**
     * Matches {{ field.name }} with optional spaces. Deliberately narrow: only
     * letters, digits, dots and underscores are a field name, so nothing that
     * looks like an expression can match.
     */
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/';

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $template, array $data): string
    {
        $flat = $this->flatten($data);

        return (string) preg_replace_callback(
            self::PLACEHOLDER,
            function (array $matches) use ($flat): string {
                // An unknown field renders empty rather than leaving {{ }} in a
                // message a customer will read.
                return $this->stringify($flat[$matches[1]] ?? null);
            },
            $template
        );
    }

    /**
     * The field names a template actually uses, for validating it against the
     * event's declared list.
     *
     * @return array<int, string>
     */
    public function fieldsUsed(string $template): array
    {
        preg_match_all(self::PLACEHOLDER, $template, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Fields a template uses that its event does not offer.
     *
     * @param  array<string, string>  $available
     * @return array<int, string>
     */
    public function unknownFields(string $template, array $available): array
    {
        return array_values(array_diff($this->fieldsUsed($template), array_keys($available)));
    }

    /**
     * Turn nested data into dotted keys, so ['user' => ['name' => 'Dana']]
     * answers to {{user.name}}.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $flat = [...$flat, ...$this->flatten($value, $name)];

                continue;
            }

            $flat[$name] = $value;
        }

        return $flat;
    }

    private function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => 'Yes',
            is_array($value) => implode(', ', array_map(fn (mixed $item) => $this->stringify($item), $value)),
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            default => '',
        };
    }
}
