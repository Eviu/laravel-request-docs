<?php

namespace Rakutentech\LaravelRequestDocs\Tests;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController;

class LRDTest extends TestCase
{
    public function testGetDocs()
    {
        $docs = $this->lrd->getDocs();

        $docSize  = 11;
        $firstDoc = $docs[0];
        $this->assertCount($docSize, $firstDoc);
        $this->assertArrayHasKey('uri', $firstDoc);
        $this->assertArrayHasKey('methods', $firstDoc);
        $this->assertArrayHasKey('middlewares', $firstDoc);
        $this->assertArrayHasKey('controller', $firstDoc);
        $this->assertArrayHasKey('controller_full_path', $firstDoc);
        $this->assertArrayHasKey('method', $firstDoc);
        $this->assertArrayHasKey('httpMethod', $firstDoc);
        $this->assertArrayHasKey('rules', $firstDoc);
        $this->assertArrayHasKey('docBlock', $firstDoc);
        $this->assertArrayHasKey('parameters', $firstDoc);
    }

    public function testDocsCanFetchAllMethods()
    {
        $docs    = $this->lrd->getDocs();
        $methods = [];
        foreach ($docs as $doc) {
            $methods = array_merge($methods, $doc['methods']);
        }
        $methods = array_unique($methods);
        sort($methods);
        $this->assertSame(['DELETE', 'GET', 'HEAD', 'POST', 'PUT'], $methods);
    }

    public function testOnlyRouteURIStartWith()
    {
        Config::set('request-docs.only_route_uri_start_with', 'welcome');
        $docs = $this->lrd->getDocs();
        foreach ($docs as $doc) {
            $this->assertStringStartsWith('welcome', $doc['uri']);
        }
    }

    public function testGroupByURI()
    {
        Route::get('users', [UserController::class]);
        Route::post('users', [UserController::class]);
        Route::put('users/update', [UserController::class]);
        Route::put('api/users/', [UserController::class]);
        Route::put('api/users/{id}', [UserController::class]);
        Route::put('api/users_roles/{id}', [UserController::class]);
        Route::put('api/v1/users', [UserController::class]);
        Route::put('api/v1/users/{id}/store', [UserController::class]);
        Route::put('api/v2/users', [UserController::class]);
        Route::put('api/v99/users', [UserController::class]);

        $docs = $this->lrd->getDocs();
        $docs = $this->lrd->groupDocs($docs, 'api_uri');

        $grouped = collect($docs)
            ->map(function (array $item) {
                return collect($item)->only(['uri', 'group', 'group_index', 'httpMethod'])->toArray();
            })
            ->groupBy('group');

        $expected = [
            ''                => [
                [
                    'uri'         => '/',
                    'httpMethod'  => 'GET',
                    'group'       => '',
                    'group_index' => 0
                ]
            ],
            'api/users'       => [
                [
                    'uri'         => 'api/users',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/users',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'api/users/{id}',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/users',
                    'group_index' => 1
                ]
            ],
            'api/users_roles' => [
                [
                    'uri'         => 'api/users_roles/{id}',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/users_roles',
                    'group_index' => 0
                ]
            ],
            'api/v1/users'    => [
                [
                    'uri'         => 'api/v1/users',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/v1/users',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'api/v1/users/{id}/store',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/v1/users',
                    'group_index' => 1
                ]
            ],
            'api/v2/users'    => [
                [
                    'uri'         => 'api/v2/users',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/v2/users',
                    'group_index' => 0
                ]
            ],
            'api/v99/users'   => [
                [
                    'uri'         => 'api/v99/users',
                    'httpMethod'  => 'PUT',
                    'group'       => 'api/v99/users',
                    'group_index' => 0
                ]
            ],
            'single'          => [
                [
                    'uri'         => 'single',
                    'httpMethod'  => 'GET',
                    'group'       => 'single',
                    'group_index' => 0
                ]
            ],
            'users'           => [
                [
                    'uri'         => 'users',
                    'httpMethod'  => 'GET',
                    'group'       => 'users',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'users',
                    'httpMethod'  => 'POST',
                    'group'       => 'users',
                    'group_index' => 1
                ],
                [
                    'uri'         => 'users/update',
                    'httpMethod'  => 'PUT',
                    'group'       => 'users',
                    'group_index' => 2
                ]
            ],
            'welcome'         => [
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'GET',
                    'group'       => 'welcome',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'POST',
                    'group'       => 'welcome',
                    'group_index' => 1
                ],
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'PUT',
                    'group'       => 'welcome',
                    'group_index' => 2
                ],
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'DELETE',
                    'group'       => 'welcome',
                    'group_index' => 3
                ]
            ],
        ];
        $this->assertSame($expected, $grouped->toArray());
    }

    public function testGroupByURISorted()
    {
        // Define routes with random ordering.
        Route::post('api/v1/users/store', [UserController::class]);
        Route::get('api/v1/users', [UserController::class]);

        Route::post('api/v1/health', [UserController::class]);

        Route::put('api/v1/users/update', [UserController::class]);
        Route::delete('api/v1/users/destroy', [UserController::class]);

        Route::get('api/v1/health', [UserController::class]);

        $docs = $this->lrd->getDocs();
        $docs = $this->lrd->groupDocs($docs, 'api_uri');

        $sorted = collect($docs)
            ->filter(function (array $doc) {
                return in_array($doc['group'], ['api/v1/users', 'api/v1/health']);
            })
            ->map(function (array $doc) {
                return collect($doc)->only(['uri', 'group', 'group_index'])->toArray();
            })
            ->values();

        $expected = [
            [
                'uri'         => 'api/v1/health',
                'group'       => 'api/v1/health',
                'group_index' => 0
            ],
            [
                'uri'         => 'api/v1/health',
                'group'       => 'api/v1/health',
                'group_index' => 1
            ],
            [
                'uri'         => 'api/v1/users/store',
                'group'       => 'api/v1/users',
                'group_index' => 0
            ],
            [
                'uri'         => 'api/v1/users',
                'group'       => 'api/v1/users',
                'group_index' => 1
            ],
            [
                'uri'         => 'api/v1/users/update',
                'group'       => 'api/v1/users',
                'group_index' => 2
            ],
            [
                'uri'         => 'api/v1/users/destroy',
                'group'       => 'api/v1/users',
                'group_index' => 3
            ]
        ];
        $this->assertSame($expected, $sorted->toArray());
    }

    public function testGroupByURIBackwardCompatible()
    {
        // Set to `null` to test backward compatibility.
        Config::set('request-docs.group_by.uri_patterns', []);

        $docs    = $this->lrd->getDocs();
        $docs    = $this->lrd->groupDocs($docs, 'api_uri');
        $grouped = collect($docs)
            ->map(function (array $item) {
                return collect($item)->only(['uri', 'group', 'group_index', 'httpMethod'])->toArray();
            })
            ->groupBy('group');

        $expected = [
            ''        => [
                [
                    'uri'         => '/',
                    'httpMethod'  => 'GET',
                    'group'       => '',
                    'group_index' => 0
                ]
            ],
            'single'  => [
                [
                    'uri'         => 'single',
                    'httpMethod'  => 'GET',
                    'group'       => 'single',
                    'group_index' => 0
                ]
            ],
            'welcome' => [
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'GET',
                    'group'       => 'welcome',
                    'group_index' => 0
                ],
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'POST',
                    'group'       => 'welcome',
                    'group_index' => 1
                ],
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'PUT',
                    'group'       => 'welcome',
                    'group_index' => 2
                ],
                [
                    'uri'         => 'welcome',
                    'httpMethod'  => 'DELETE',
                    'group'       => 'welcome',
                    'group_index' => 3
                ]
            ],
        ];
        $this->assertSame($expected, $grouped->toArray());
    }

    public function testGroupByFQController()
    {
        Route::get('users', UserController::class);
        Route::post('users', UserController::class);
        Route::put('users/update', UserController::class);
        $docs = $this->lrd->getDocs();
        $docs = $this->lrd->groupDocs($docs, 'controller_full_path');

        $grouped = collect($docs)
            ->map(function (array $item) {
                return collect($item)->only(['controller_full_path', 'group', 'group_index', 'httpMethod'])->toArray();
            })
            ->groupBy('group');

        $expected = [
            'Rakutentech\LaravelRequestDocs\Tests\TestControllers\SingleActionController' => [
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\SingleActionController',
                    'httpMethod'           => 'GET',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\SingleActionController',
                    'group_index'          => 0
                ]
            ],
            'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController'         => [
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController',
                    'httpMethod'           => 'GET',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController',
                    'group_index'          => 0
                ],
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController',
                    'httpMethod'           => 'POST',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController',
                    'group_index'          => 1
                ],
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController',
                    'httpMethod'           => 'PUT',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\UserController',
                    'group_index'          => 2
                ]
            ],
            'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController'      => [
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'httpMethod'           => 'GET',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'group_index'          => 0
                ],
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'httpMethod'           => 'GET',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'group_index'          => 1
                ],
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'httpMethod'           => 'POST',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'group_index'          => 2
                ],
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'httpMethod'           => 'PUT',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'group_index'          => 3
                ],
                [
                    'controller_full_path' => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'httpMethod'           => 'DELETE',
                    'group'                => 'Rakutentech\LaravelRequestDocs\Tests\TestControllers\WelcomeController',
                    'group_index'          => 4
                ]
            ],
        ];
        $this->assertSame($expected, $grouped->toArray());
    }
}
