<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ProductConfiguration;
use Illuminate\Http\Request;

class ProductConfigurationController extends Controller
{
    public function show(Request $request, Product $product, ProductConfiguration $configuration)
    {
        return response()->json($configuration->read($request, $product))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Product $product, ProductConfiguration $configuration)
    {
        return response()->json($configuration->save($request, $product))->header('Cache-Control', 'no-store');
    }

    public function operation(Request $request, Product $product, ProductConfiguration $configuration)
    {
        $data = $request->validate(['key' => ['required', 'string', 'min:8', 'max:100']]);

        return response()->json(['operation' => $configuration->operation($request, $product, $data['key'])])->header('Cache-Control', 'no-store');
    }
}
