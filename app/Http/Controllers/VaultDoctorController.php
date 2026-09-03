<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Vault\Doctor\VaultDoctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Local-only diagnostic endpoint so `vault:doctor --fpm` can compare the FPM
 * SAPI against the CLI run. Reachable by an authenticated admin (browser),
 * or by a validly signed URL (the `vault:doctor` command itself), since the
 * command runs under a different SAPI and has no browser session to offer.
 * The environment check happens per-request (not at route-registration time)
 * so it stays live even though routes are loaded once at boot.
 */
final class VaultDoctorController extends Controller
{
    public function __invoke(Request $request, VaultDoctor $doctor): JsonResponse
    {
        abort_unless(app()->environment('local'), 404);

        if (! $request->hasValidSignature()) {
            $user = $request->user();

            if (! $user || ! $user->hasRole('admin')) {
                throw new HttpException(403, 'Forbidden.');
            }
        }

        return response()->json([
            'sapi' => PHP_SAPI,
            'checks' => $doctor->run()->map(fn ($check) => $check->toArray())->values(),
        ]);
    }
}
