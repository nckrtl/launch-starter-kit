<?php

declare(strict_types=1);

namespace App\Delivery\Data;

use Illuminate\Validation\Rule;

final class OrbitProjectConfig extends ProjectConfig
{
    public const string TYPE = 'orbit';

    public const int VERSION = 1;

    public function __construct(
        string $type,
        int $version,
        public readonly string $repository,
        public readonly string $worktreeRoot,
        public readonly string $herdrSession,
        public readonly int $concurrency,
        public readonly string $defaultFlow,
    ) {
        parent::__construct($type, $version);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in([self::TYPE])],
            'version' => ['required', 'integer', Rule::in([self::VERSION])],
            'repository' => ['required', 'string', 'max:4096', 'regex:/^\//'],
            'worktreeRoot' => ['required', 'string', 'max:4096', 'regex:/^\//'],
            'herdrSession' => ['required', 'string', 'max:100'],
            'concurrency' => ['required', 'integer', 'min:1', 'max:32'],
            'defaultFlow' => ['required', 'string', Rule::in(['discovery', 'proof'])],
        ];
    }
}
