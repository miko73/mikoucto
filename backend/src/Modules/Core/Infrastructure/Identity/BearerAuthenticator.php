<?php

namespace App\Modules\Core\Infrastructure\Identity;

use App\Modules\Core\Application\Identity\AccessTokenValidator;
use App\Modules\Core\Application\Identity\UserDirectory;
use Doctrine\DBAL\Exception as DatabaseException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class BearerAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private AccessTokenValidator $validator, private UserDirectory $users) { }

    public function supports(Request $request): ?bool
    {
        return !in_array($request->getPathInfo(), ['/api/v1/health', '/api/v1/health/database'], true)
            && $request->headers->has('Authorization');
    }

    public function authenticate(Request $request): Passport
    {
        if (preg_match('/^Bearer ([a-zA-Z0-9_.-]+)$/D', $request->headers->get('Authorization', ''), $match) !== 1) {
            throw new CustomUserMessageAuthenticationException('invalid_token');
        }
        try {
            $identity = $this->validator->validate($match[1]);
            $user = $this->users->findActive($identity);
        } catch (IdentityConfigurationException|IdentityProviderUnavailable|DatabaseException) {
            throw new CustomUserMessageAuthenticationException('identity_unavailable');
        } catch (InvalidAccessToken) {
            throw new CustomUserMessageAuthenticationException('invalid_token');
        }
        if ($user === null) {
            throw new CustomUserMessageAuthenticationException('account_not_provisioned');
        }
        return new SelfValidatingPassport(new UserBadge($user->getUserIdentifier(), static fn () => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response { return null; }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $reason = $exception->getMessageKey();
        $status = match ($reason) { 'identity_unavailable' => 503, 'account_not_provisioned' => 403, default => 401 };
        return self::problem($status, match ($status) { 503 => 'Identity service unavailable', 403 => 'Account is not provisioned or active', default => 'Invalid access token' });
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::problem(401, 'Access token required');
    }

    public static function problem(int $status, string $title): JsonResponse
    {
        $headers = ['Content-Type' => 'application/problem+json', 'Cache-Control' => 'no-store'];
        if ($status === 401) { $headers['WWW-Authenticate'] = 'Bearer realm="mikoucto"'; }
        return new JsonResponse(['type' => 'about:blank', 'title' => $title, 'status' => $status], $status, $headers);
    }
}
