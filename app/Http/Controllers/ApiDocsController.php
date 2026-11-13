<?php

namespace App\Http\Controllers;

use App\Support\ApiDocs;
use Illuminate\View\View;

class ApiDocsController extends Controller
{
    public function index(): View
    {
        $catalog = ApiDocs::catalog();

        foreach ($catalog['sections'] as &$section) {
            foreach ($section['groups'] as &$group) {
                foreach ($group['endpoints'] as &$endpoint) {
                    foreach ($endpoint['scenarios'] as &$scenario) {
                        $scenario = array_merge($scenario, ApiDocs::resolveScenario($scenario));
                    }
                    unset($scenario);
                }
                unset($endpoint);
            }
            unset($group);
        }
        unset($section);

        return view('docs.api', [
            'catalog' => $catalog,
            'brand' => config('app.name', 'Cellogent'),
            'baseUrl' => rtrim((string) config('app.url'), '/').'/api',
        ]);
    }
}
