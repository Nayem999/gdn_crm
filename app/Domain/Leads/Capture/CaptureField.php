<?php

namespace App\Domain\Leads\Capture;

/**
 * One field on a public capture form.
 *
 * A **fixed vocabulary**, not free text. The form builder can choose which of
 * these to show and which to require; it cannot invent a key, because the key
 * decides which lead column a submission writes to. A form that could name its
 * own target column would be a public write path naming a column.
 */
final readonly class CaptureField
{
    /**
     * The lead attributes a form may collect, and how each is validated.
     *
     * @return array<string, array{label: string, rules: array<int, string>, type: string}>
     */
    public static function catalogue(): array
    {
        return [
            'first_name' => ['label' => 'First name', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'last_name' => ['label' => 'Last name', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'email' => ['label' => 'Email', 'rules' => ['email', 'max:255'], 'type' => 'email'],
            'phone' => ['label' => 'Phone', 'rules' => ['string', 'max:64'], 'type' => 'tel'],
            'mobile' => ['label' => 'Mobile', 'rules' => ['string', 'max:64'], 'type' => 'tel'],
            'company_name' => ['label' => 'Company', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'job_title' => ['label' => 'Job title', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'website' => ['label' => 'Website', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'city' => ['label' => 'City', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'country' => ['label' => 'Country', 'rules' => ['string', 'max:255'], 'type' => 'text'],
            'description' => ['label' => 'Message', 'rules' => ['string', 'max:5000'], 'type' => 'textarea'],
        ];
    }

    private function __construct(
        public string $key,
        public string $label,
        public bool $required,
        public string $type,
        /** @var array<int, string> */
        public array $rules,
    ) {}

    public static function fromStored(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }

        $key = (string) ($stored['key'] ?? '');
        $spec = self::catalogue()[$key] ?? null;

        // A key the catalogue does not hold is dropped rather than rendered: a
        // stored form can outlive a field the catalogue once had.
        if ($spec === null) {
            return null;
        }

        $label = trim((string) ($stored['label'] ?? ''));

        return new self(
            key: $key,
            label: $label === '' ? $spec['label'] : $label,
            required: (bool) ($stored['required'] ?? false),
            type: $spec['type'],
            rules: $spec['rules'],
        );
    }

    /**
     * @return array{key: string, label: string, required: bool}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'required' => $this->required];
    }

    /**
     * @return array<int, string>
     */
    public function validationRules(): array
    {
        return [$this->required ? 'required' : 'nullable', ...$this->rules];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::catalogue());
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::catalogue() as $key => $spec) {
            $options[$key] = $spec['label'];
        }

        return $options;
    }
}
