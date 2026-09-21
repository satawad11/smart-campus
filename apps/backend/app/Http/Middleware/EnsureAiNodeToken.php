<?php

namespace App\Http\Middleware;

use App\Services\Camera\AiNodeCredentialService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates /internal/v1/ai-nodes/* requests by per-node bearer
 * credential (CAM-004), replacing CAM-003's temporary shared internal.token.
 *
 * Reads `Authorization: Bearer <token>`. On success, resolves the token to
 * exactly one AiNode and attaches it to the request — controllers use this
 * resolved identity, never a client-supplied node_code, so a credential can
 * never be used to impersonate a different node. Fails closed: missing,
 * malformed, unknown, revoked, or belonging-to-a-disabled-node tokens all
 * produce the same sanitized 401 (no enumeration signal). A human Laravel
 * web session is never accepted here — machine credentials and human
 * sessions stay separate.
 */
class EnsureAiNodeToken
{
    public function __construct(
        private readonly AiNodeCredentialService $credentials,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return $this->unauthorized();
        }

        $node = $this->credentials->authenticate($token);

        if ($node === null) {
            return $this->unauthorized();
        }

        $request->attributes->set('resolved_ai_node', $node);

        return $next($request);
    }

    private function unauthorized(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or missing AI node credential.',
            'error' => ['code' => 'AI_NODE_UNAUTHENTICATED', 'fields' => new \stdClass],
        ], 401);
    }
}
