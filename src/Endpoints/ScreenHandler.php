<?php

declare(strict_types=1);

namespace ProEnroll\Api\Endpoints;

use ProEnroll\Api\Http\Request;
use ProEnroll\Api\Middleware\JwtTokenMiddleware;
use ProEnroll\Api\Services\ProRepository;

abstract class ScreenHandler
{
    protected ProRepository $pros;

    public function __construct()
    {
        $this->pros = new ProRepository();
    }

    abstract public function handle(Request $request): void;

    protected function requireAuth(Request $request): bool
    {
        return JwtTokenMiddleware::require($request);
    }

    protected function uid(Request $request): string
    {
        return (string) ($request->authUser['sub'] ?? '');
    }

    protected function ensurePro(Request $request): array
    {
        $user = $request->authUser;
        return $this->pros->upsertFromAuth(
            $this->uid($request),
            $user['phone'] ?? null,
        );
    }
}
