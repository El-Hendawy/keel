<?php

declare(strict_types=1);

namespace Keel\Build\Http;

use Keel\Build\Types\TypeAnalyzer;
use Keel\Build\Types\TypeInfo;

/**
 * Builds an OpenAPI 3.1 document from the same types that validate requests.
 * There is one source of truth: if the code compiles, the document is accurate.
 */
final class OpenApiGenerator
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /** @var array<string, string> class => component name */
    private array $names = [];

    private bool $secured = false;

    public function __construct(private readonly TypeAnalyzer $types, private readonly bool $toon = false)
    {
    }

    /**
     * @param list<RouteDefinition> $routes
     * @return array<string, mixed>
     */
    public function generate(array $routes, string $title, string $version): array
    {
        $paths = [];
        foreach ($routes as $route) {
            $path = (string) preg_replace('/\{(\w+)(?::[^}]+)?\}/', '{$1}', $route->path);
            $paths[$path][strtolower($route->method)] = $this->operation($route);
        }
        ksort($paths);

        $this->schemas['Problem'] = [
            'type' => 'object',
            'description' => 'RFC 9457 problem details.',
            'properties' => [
                'type' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'status' => ['type' => 'integer'],
                'detail' => ['type' => 'string'],
                'instance' => ['type' => 'string'],
                'errors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'path' => ['type' => 'string'],
                            'code' => ['type' => 'string'],
                            'message' => ['type' => 'string'],
                        ],
                        'required' => ['path', 'code', 'message'],
                    ],
                ],
            ],
            'required' => ['type', 'title', 'status'],
        ];
        ksort($this->schemas);

        $components = ['schemas' => $this->schemas];
        if ($this->secured) {
            $components['securitySchemes'] = ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'A Keel token (keel_...) or a JWT.']];
        }

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => $title, 'version' => $version],
            'paths' => $paths,
            'components' => $components,
        ] + ($this->secured ? ['security' => [['bearer' => []]]] : []);
    }

    /** @return array<string, mixed> */
    private function operation(RouteDefinition $route): array
    {
        $operation = [];
        if ($route->summary !== null) {
            $operation['summary'] = $route->summary;
        }
        $operation['operationId'] = $route->name ?? lcfirst((string) substr((string) strrchr('\\' . $route->class, '\\'), 1));
        if ($route->module !== null) {
            $operation['tags'] = [$route->module];
        }

        $parameters = [];
        foreach ($route->pathParams as $name => $type) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $this->schema($type, [], true)];
        }
        if ($route->input !== null && $route->inputSource === 'query') {
            foreach ($this->types->inputFields($route->input) as $field) {
                $parameters[] = [
                    'name' => $field->name,
                    'in' => 'query',
                    'required' => !$field->hasDefault,
                    'schema' => $this->schema($field->type, $field->constraints, true),
                ];
            }
        }
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($route->input !== null && $route->inputSource === 'body') {
            $operation['requestBody'] = [
                'required' => true,
                'content' => $this->content($this->reference($route->input, true)),
            ];
        }

        $responses = [];
        if ($route->output !== null) {
            $responses[(string) $route->status] = [
                'description' => 'Success',
                'content' => $this->content($this->reference($route->output, false)),
            ];
        } elseif ($route->returnsResponse) {
            $responses[(string) $route->status] = ['description' => 'Success'];
        } else {
            $responses[(string) $route->status] = ['description' => 'No content'];
        }
        $problem = ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]]
            + ($this->toon ? [\Keel\Http\Toon\Toon::MEDIA_TYPE => ['schema' => ['$ref' => '#/components/schemas/Problem']]] : []);
        if ($route->input !== null) {
            $responses['422'] = ['description' => 'Validation failed', 'content' => $problem];
        }
        if ($route->pathParams !== []) {
            $responses['404'] = ['description' => 'Not found', 'content' => $problem];
        }
        if ($route->auth !== null) {
            $this->secured = true;
            if ($route->auth['anonymous']) {
                $operation['security'] = [];
            } else {
                $operation['security'] = [['bearer' => $route->auth['scopes']]];
                $responses['401'] = ['description' => 'Not authenticated', 'content' => $problem];
                if ($route->auth['scopes'] !== [] || $route->auth['policy'] !== null) {
                    $responses['403'] = ['description' => 'Not allowed', 'content' => $problem];
                }
            }
        }
        $responses['429'] = ['description' => 'Rate limited', 'content' => $problem];
        $operation['responses'] = $responses;

        return $operation;
    }

    /**
     * The same schema as JSON and, with ->withToon(), as TOON (same data model, other syntax).
     *
     * @param array<string, mixed> $schema
     * @return array<string, array{schema: array<string, mixed>}>
     */
    private function content(array $schema): array
    {
        return ['application/json' => ['schema' => $schema]] + ($this->toon ? [\Keel\Http\Toon\Toon::MEDIA_TYPE => ['schema' => $schema]] : []);
    }

    /**
     * @param list<\Keel\Validation\Constraint> $constraints
     * @return array<string, mixed>
     */
    private function schema(TypeInfo $type, array $constraints, bool $input): array
    {
        $schema = match ($type->kind) {
            TypeInfo::STRING => ['type' => 'string'],
            TypeInfo::INT => ['type' => 'integer'],
            TypeInfo::FLOAT => ['type' => 'number'],
            TypeInfo::BOOL => ['type' => 'boolean'],
            TypeInfo::DATETIME => ['type' => 'string', 'format' => 'date-time'],
            TypeInfo::ENUM => $this->enum($type->classOrFail()),
            TypeInfo::OBJECT => $this->reference($type->classOrFail(), $input),
            TypeInfo::LIST => ['type' => 'array', 'items' => $this->schema($type->item ?? new TypeInfo(TypeInfo::STRING), [], $input)],
        };
        foreach ($constraints as $constraint) {
            $schema += $constraint->schema();
        }
        if ($type->nullable) {
            $schema = isset($schema['$ref'])
                ? ['oneOf' => [$schema, ['type' => 'null']]]
                : ['type' => [$schema['type'] ?? 'string', 'null']] + $schema;
        }

        return $schema;
    }

    /** @return array<string, mixed> */
    private function enum(string $class): array
    {
        $values = TypeInfo::enumValues($class);

        return ['type' => \is_int($values[0] ?? '') ? 'integer' : 'string', 'enum' => $values];
    }

    /**
     * @param class-string $class
     * @return array{'$ref': string}
     */
    private function reference(string $class, bool $input): array
    {
        $key = ($input ? 'in:' : 'out:') . $class;
        if (!isset($this->names[$key])) {
            $short = (string) substr((string) strrchr('\\' . $class, '\\'), 1);
            $name = \in_array($short, $this->names, true) ? str_replace('\\', '.', $class) . ($input ? '.Input' : '') : $short;
            $this->names[$key] = $name;
            $this->schemas[$name] = [];
            $this->schemas[$name] = $this->objectSchema($class, $input);
        }

        return ['$ref' => '#/components/schemas/' . $this->names[$key]];
    }

    /**
     * @param class-string $class
     * @return array<string, mixed>
     */
    private function objectSchema(string $class, bool $input): array
    {
        $fields = $input ? $this->types->inputFields($class) : $this->types->outputFields($class);

        $properties = [];
        $required = [];
        foreach ($fields as $field) {
            $property = $this->schema($field->type, $field->constraints, $input);
            if ($field->description !== null) {
                $property['description'] = $field->description;
            }
            $properties[$field->name] = $property;
            if (!$input || !$field->hasDefault) {
                $required[] = $field->name;
            }
        }

        $schema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        if ($input) {
            $schema['additionalProperties'] = false;
        }

        return $schema;
    }
}
