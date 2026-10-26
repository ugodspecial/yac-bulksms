<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Sending is unsafe or impossible right now (provider not configured, balance
 * empty, sending paused, sender ID not approved, no eligible recipients).
 */
class CampaignPreflightException extends RuntimeException
{
    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
