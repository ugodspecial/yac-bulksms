<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A campaign action is not valid in its current state (for example trying to
 * confirm a campaign twice). Shown to the user as a friendly error.
 */
class CampaignStateException extends RuntimeException
{
    public function render(Request $request): JsonResponse|\Illuminate\Http\RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->with('error', $this->getMessage());
    }
}
