<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PackageController extends Controller
{
    public function index(): JsonResponse
    {
        $packages = Package::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $packages
        ]);
    }

    public function show($id): JsonResponse
    {
        $package = Package::where('is_active', true)->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $package
        ]);
    }

    public function requestUpgrade(Request $request): JsonResponse
    {
        $request->validate([
            'package_id' => 'required|uuid|exists:packages,id',
            'message' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $package = Package::findOrFail($request->package_id);

        // Here you could log the upgrade request, send notifications, etc.
        // For now, we'll just return the WhatsApp contact info

        $whatsappNumber = env('WHATSAPP_NUMBER', '+1234567890');
        $message = urlencode("Hi! I'm interested in upgrading to the {$package->name} package for SCSTrade Watchlist Manager. My email: {$user->email}");

        return response()->json([
            'success' => true,
            'message' => 'Upgrade request received',
            'data' => [
                'whatsapp_url' => "https://wa.me/{$whatsappNumber}?text={$message}",
                'whatsapp_number' => $whatsappNumber,
                'package' => $package,
            ]
        ]);
    }
}
