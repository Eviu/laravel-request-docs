<?php

namespace Rakutentech\LaravelRequestDocs;

use Illuminate\Support\Arr;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Validation\ValidationRuleParser;

class LaravelRequestDocsToOpenApi
{
    use Macroable;

    private $openApi = [];

    // docs from $docs = $this->laravelRequestDocs->getDocs();
    public function openApi(array $docs): self
    {
        $this->openApi['openapi'] = config('request-docs.open_api.version', '3.0.0');
        $this->openApi['info']['version'] = config('request-docs.open_api.document_version', '1.0.0');
        $this->openApi['info']['title'] = config('request-docs.open_api.title', 'Laravel Request Docs');
        $this->openApi['info']['description'] = config('request-docs.open_api.description', 'Laravel Request Docs');
        $this->openApi['info']['license']['name'] = config('request-docs.open_api.license', 'Apache 2.0');
        $this->openApi['info']['license']['url'] = config('request-docs.open_api.license_url', 'https://www.apache.org/licenses/LICENSE-2.0.html');
        $this->openApi['servers'] = config('request-docs.open_api.servers', [[
            'url' => config('request-docs.open_api.server_url', config('app.url')),
        ]]);

        $this->docsToOpenApi($docs);

        $this->openApi['components'] = config('request-docs.open_api.components', []);

        /** @var OpenApiPipeline */
        $pipeline = app(OpenApiPipeline::class);
        [$docs, $openApi] = $pipeline
            ->send([$docs, $this->openApi])
            ->pipe([
                // default pipelines
                // none yet
            ])
            ->thenReturn();

        $this->openApi = $openApi;

        return $this;
    }

    private function docsToOpenApi(array $docs)
    {
        $this->openApi['paths'] = [];

        foreach ($docs as $doc) {
            $requestHasFile = false;
            $httpMethod = strtolower($doc['httpMethod']);
            $isGet = $httpMethod == 'get';
            $isPost = $httpMethod == 'post';
            $isPut = $httpMethod == 'put';
            $isDelete = $httpMethod == 'delete';
            $requiresAuth = !empty(array_intersect(
                config('request-docs.open_api.auth_middlewares', ['auth:api', 'auth', 'auth:sanctum']),
                $doc['middlewares']
            ));

            $doc['uri'] = '/' . ltrim($doc['uri'], '/');
            $this->openApi['paths'][$doc['uri']][$httpMethod]['description'] = $doc['docBlock'];
            $this->openApi['paths'][$doc['uri']][$httpMethod]['parameters'] = [];

            $groups = explode('/', ltrim($doc['uri'], '/'));

            if (count($groups) > 1) {
                $this->openApi['paths'][$doc['uri']][$httpMethod]['tags'] = [$groups[1]];
            }

            if ($requiresAuth) {
                $this->openApi['paths'][$doc['uri']][$httpMethod]['security'] = config('request-docs.open_api.security', []);
            }

            $this->openApi['paths'][$doc['uri']][$httpMethod]['responses'] = config('request-docs.open_api.responses', []);

            foreach ($doc['rules'] as $attribute => $rules) {
                foreach ($rules as $rule) {
                    if ($isPost || $isPut || $isDelete) {
                        $requestHasFile = $this->attributeIsFile($rule);

                        if ($requestHasFile) {
                            break 2;
                        }
                    }
                }
            }

            $contentType = $requestHasFile ? 'multipart/form-data' : 'application/json';

            $this->openApi['paths'][$doc['uri']][$httpMethod]['parameters'] = $this->makeRequestParams($doc);

            if ($isPost || $isPut) {
                $this->openApi['paths'][$doc['uri']][$httpMethod]['requestBody'] = $this->makeRequestBodyItem($contentType);
            }

            // group rules by main attribute object.*.name => object
            $groupped = collect($doc['rules'])->sortBy(function ($a, $b) {
                return $b;
            })->map(function ($rule, $attribute) {
                $undotted = explode('.', $attribute);

                return [$undotted[0], $rule, $undotted];
            })->groupBy(fn ($values, $attribute) => $values[0], true);

            foreach ($groupped as $key => $values) {
                $firstItem = $values->first();
                $attribute = $firstItem[0];
                $rules = $firstItem[1];

                foreach ($rules as $rule) {
                    if ($isGet) {
                        $parameter = $this->makeQueryParameterItem($attribute, $rule);
                        $this->openApi['paths'][$doc['uri']][$httpMethod]['parameters'][] = $parameter;
                    }
                    if ($isPost || $isPut || $isDelete) {
                        $this->openApi['paths'][$doc['uri']][$httpMethod]['requestBody']['content'][$contentType]['schema']['properties'][$attribute] = $this->makeRequestBodyContentPropertyItem($attribute, $rule, $values);
                    }
                }
            }
        }
    }

    protected function attributeIsFile(string $rule)
    {
        [$rule] = ValidationRuleParser::parse($rule);

        return str_contains($rule, 'file') || str_contains($rule, 'image');
    }

    protected function makeRequestParams($doc)
    {
        $parameters = [];

        foreach ($doc['parameters'] as $name=>$type) {
            $parameters[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => [
                    'type' => $type,
                ],
            ];
        }

        return $parameters;
    }

    protected function makeQueryParameterItem(string $attribute, string $rule): array
    {
        $parameter = [
            'name'        => $attribute,
            'description' => $rule,
            'in'          => 'query',
            'style'       => 'form',
            'required'    => str_contains($rule, 'required'),
            'schema'      => [
                'type' => $this->getAttributeType($rule),
            ],
        ];

        return $parameter;
    }

    protected function makeRequestBodyItem(string $contentType): array
    {
        $requestBody = [
            'description' => 'Request body',
            'content'     => [
                $contentType => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ],
        ];

        return $requestBody;
    }

    protected function makeRequestBodyContentPropertyItem(string $attribute, string $rule, $groupped): array
    {
        $type = $this->getAttributeType($rule);

        $arraySchema = $this->getArraySchema($type, $attribute, $rule, $groupped);

        return [
            'type' => $type,
            'nullable' => str_contains($rule, 'nullable'),
            'format' => $this->getFormat($rule, $type),
            'description' => $this->getAttributeDescription($attribute),
            ...$arraySchema,
        ];
    }

    protected function getArraySchema(string $type, string $attribute, string $rule, \Illuminate\Support\Collection $groupped)
    {
        if ($type != 'array') {
            return [];
        }

        $schema = [
            'items' => [
                'type' => 'string',
                'description' => $this->getAttributeDescription($attribute),
            ],
        ];

        foreach ($groupped->skip(1)->toArray() as $field => $value) {
            $fields = $value[2];
            $fields = array_filter(array_splice($fields, 1), function ($item) {
                return $item !== '*' && !is_numeric($item);
            });

            // flat array
            if (count($fields) == 0) {
                //dd($value);
                $schema['items']['type'] = $this->getAttributeType(implode('|', $value[1]));
                continue;
            }

            $schema['items']['type'] = 'object';
            $schema['items']['properties'] = $schema['items']['properties'] ?? [];

            Arr::set(
                $schema['items']['properties'],
                implode('.', $fields),
                $this->mapSubAttribute($field, $fields, implode('|', $value[1]))
            );
        }

        return $schema;
    }

    protected function mapSubAttribute($fullKey, $undotted, string $rule)
    {
        $type = $this->getAttributeType($rule);

        $definition = [
            'type' => $type,
            'description' => $this->getAttributeDescription($fullKey),
        ];

        if ($type == 'array') {
            // TODO: detect correct sub type
            $definition['items']['type'] = 'string';
        }

        if (count($undotted) < 2) {
            return $definition;
        }

        // TODO: neasted object
        $definition['type'] = 'object';
        $definition['items']['properties'] = [];

        return $definition;
    }

    protected function getAttributeDescription(string $attribute)
    {
        return Arr::get(trans('validation.attributes'), $attribute, '');
    }

    protected function getFormat($rule, $type)
    {
        if ($this->attributeIsFile($rule)) {
            return 'binary';
        }

        [$rule] = ValidationRuleParser::parse($rule);

        if (str_contains($rule, 'email')) {
            return 'email';
        }

        if (str_contains($rule, 'DateFormat')) {
            return 'date';
        }

        if (str_contains($rule, 'date')) {
            return 'date-time';
        }

        return $type;
    }

    protected function getAttributeType(string $rule): string
    {
        if (str_contains($rule, 'string') || $this->attributeIsFile($rule)) {
            return 'string';
        }

        if (str_contains($rule, 'array')) {
            return 'array';
        }

        if (str_contains($rule, 'integer')) {
            return 'integer';
        }

        // float or integer
        if (str_contains($rule, 'numeric')) {
            return 'number';
        }

        if (str_contains($rule, 'boolean')) {
            return 'boolean';
        }

        return 'string';
    }

    public function toJson(): string
    {
        return collect($this->openApi)->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function toArray(): array
    {
        return $this->openApi;
    }
}
