<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EarlyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EarlyAccessController extends Controller
{
    public function store(Request $request, EarlyAccessService $earlyAccess): JsonResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email', '')))]);
        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'plan' => ['sometimes', 'nullable', Rule::in(['base', 'premium', 'pro'])],
            'source' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $signup = $earlyAccess->request(
            $data['email'],
            $data['name'] ?? null,
            $data['plan'] ?? null,
            $data['source'] ?? 'api',
        );

        return response()->json(['data' => $earlyAccess->payload($signup)], $signup->status === EarlyAccessService::STATUS_WAITLISTED ? 202 : 200);
    }
}
