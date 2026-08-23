<?php

namespace Tests\Feature;

use Tests\TestCase;

class OpenApiContractParityTest extends TestCase
{
    public function test_every_versioned_route_method_has_an_exact_openapi_operation(): void
    {
        $contractPath = realpath(base_path('../../packages/contracts/openapi.yaml'));
        $this->assertNotFalse($contractPath);
        $lines = file($contractPath, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $contract = [];
        $path = null;
        foreach ($lines as $line) {
            if (preg_match('/^  (\/[^:]+):\s*$/', $line, $matches) === 1) {
                $path = $matches[1];

                continue;
            }
            if ($path && preg_match('/^    (get|post|put|patch|delete):\s*$/', $line, $matches) === 1) {
                $contract[] = strtoupper($matches[1]).' '.$path;
            }
        }

        $routes = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $path = substr($route->uri(), strlen('api/v1'));
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $routes[] = $method.' '.$path;
            }
        }

        sort($contract);
        sort($routes);
        $this->assertSame($routes, $contract);
    }
}
