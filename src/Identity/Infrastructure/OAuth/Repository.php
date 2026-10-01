<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\OAuth;

use App\Identity\Infrastructure\OAuth\Entity\AccessToken;
use App\Identity\Infrastructure\OAuth\Entity\AuthCode;
use App\Identity\Infrastructure\OAuth\Entity\Client;
use App\Identity\Infrastructure\OAuth\Entity\RefreshToken;
use App\Identity\Infrastructure\OAuth\Entity\Scope;
use League\OAuth2\Server\Entities\{AccessTokenEntityInterface, AuthCodeEntityInterface, ClientEntityInterface, RefreshTokenEntityInterface, ScopeEntityInterface};
use League\OAuth2\Server\Repositories\{AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface};
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Separate OAuth database; never opens the email processing SQLite. Unknown token IDs fail closed. */
final class Repository implements AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, ClientRepositoryInterface, RefreshTokenRepositoryInterface, ScopeRepositoryInterface
{
    public const SCOPES = ['configuration:read', 'configuration:write'];
    private ?\PDO $connection = null;
    public function __construct(private readonly Settings $settings, private readonly HttpClientInterface $http)
    {
    }
    private function db(): \PDO
    {
        if ($this->connection) {
            return $this->connection;
        }
        $this->settings->read();
        $db = new \PDO('sqlite:'.$this->settings->directory.'/oauth.sqlite', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=5000; CREATE TABLE IF NOT EXISTS tokens (kind TEXT NOT NULL, id TEXT NOT NULL, expires INTEGER NOT NULL, revoked INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (kind,id)); CREATE TABLE IF NOT EXISTS attempts (id TEXT PRIMARY KEY, started INTEGER NOT NULL, failures INTEGER NOT NULL)');
        chmod($this->settings->directory.'/oauth.sqlite', 0600);
        return $this->connection = $db;
    }
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $config = $this->settings->read();
        if ($clientIdentifier === ($config['client_id'] ?? '') && !empty($config['redirect_uris'])) {
            return new Client($clientIdentifier, $config['redirect_uris'], !empty($config['client_secret_hash']));
        }
        // Only fetch the exact ChatGPT metadata URL forms; no arbitrary URLs, redirects, or private-network fetches.
        if (!preg_match('~^https://chatgpt\.com/oauth/(?:[a-zA-Z0-9_-]+/)?client\.json$~D', $clientIdentifier)) {
            return null;
        }
        try {
            $response = $this->http->request('GET', $clientIdentifier, ['max_redirects' => 0, 'timeout' => 5, 'max_duration' => 5]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = $response->getContent();
            if (strlen($body) > 65536) {
                return null;
            }
            $metadata = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
            if (($metadata['client_id'] ?? null) !== $clientIdentifier || empty($metadata['redirect_uris']) || !is_array($metadata['redirect_uris'])) {
                return null;
            }
            foreach ($metadata['redirect_uris'] as $redirect) {
                if (!is_string($redirect) || !preg_match('~^https://chatgpt\.com/[^\s#]*$~D', $redirect)) {
                    return null;
                }
            }
            $methods = $metadata['token_endpoint_auth_methods_supported'] ?? [$metadata['token_endpoint_auth_method'] ?? 'none'];
            if (!in_array('none', $methods, true)) {
                return null;
            }
            return new Client($clientIdentifier, $metadata['redirect_uris']);
        } catch (\Throwable) {
            return null;
        }
    }
    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        if (!in_array($grantType, ['authorization_code', 'refresh_token'], true)) {
            return false;
        }
        $client = $this->getClientEntity($clientIdentifier);
        if (!$client) {
            return false;
        }
        if (!$client->isConfidential()) {
            return true;
        }
        return $clientSecret !== null && password_verify($clientSecret, $this->settings->read()['client_secret_hash']);
    }
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, self::SCOPES, true) ? new Scope($identifier) : null;
    }
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        return $scopes;
    }
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessToken($this->settings->baseUrl, $this->settings->resource());
        $token->setClient($clientEntity);
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        return $token;
    }
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCode();
    }
    public function getNewRefreshToken(): RefreshTokenEntityInterface
    {
        return new RefreshToken();
    }
    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->persist('access', $accessTokenEntity);
    }
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->persist('code', $authCodeEntity);
    }
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->persist('refresh', $refreshTokenEntity);
    }
    public function revokeAccessToken(string $tokenId): void
    {
        $this->revoke('access', $tokenId);
    }
    public function revokeAuthCode(string $codeId): void
    {
        $this->revoke('code', $codeId);
    }
    public function revokeRefreshToken(string $tokenId): void
    {
        $this->revoke('refresh', $tokenId);
    }
    public function isAccessTokenRevoked(string $tokenId): bool
    {
        return $this->revoked('access', $tokenId);
    }
    public function isAuthCodeRevoked(string $codeId): bool
    {
        return $this->revoked('code', $codeId);
    }
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        return $this->revoked('refresh', $tokenId);
    }
    private function persist(string $kind, object $token): void
    {
        try {
            $this->db()->prepare('INSERT INTO tokens(kind,id,expires) VALUES(?,?,?)')->execute([$kind, $token->getIdentifier(), $token->getExpiryDateTime()->getTimestamp()]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw \League\OAuth2\Server\Exception\UniqueTokenIdentifierConstraintViolationException::create();
            } throw $e;
        }
    }
    private function revoke(string $kind, string $id): void
    {
        $this->db()->prepare('UPDATE tokens SET revoked=1 WHERE kind=? AND id=?')->execute([$kind, $id]);
    }
    private function revoked(string $kind, string $id): bool
    {
        $query = $this->db()->prepare('SELECT revoked,expires FROM tokens WHERE kind=? AND id=?');
        $query->execute([$kind, $id]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return !$row || (bool) $row['revoked'] || (int) $row['expires'] <= time();
    }
    /** Global owner limit prevents changing IPs from bypassing the login throttle. */
    public function attemptLogin(string $password): bool
    {
        $db = $this->db();
        $now = time();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $db->prepare('DELETE FROM attempts WHERE started<?')->execute([$now - 900]);
            $row = $db->query("SELECT failures FROM attempts WHERE id='owner'")->fetchColumn();
            if ($row !== false && (int) $row >= 10) {
                $db->exec('COMMIT');
                return false;
            }
            $valid = password_verify($password, $this->settings->read()['owner_password_hash']);
            if ($valid) {
                $db->exec("DELETE FROM attempts WHERE id='owner'");
            } else {
                $db->prepare("INSERT INTO attempts(id,started,failures) VALUES('owner',?,1) ON CONFLICT(id) DO UPDATE SET failures=failures+1")->execute([$now]);
            }
            $db->exec('COMMIT');
            return $valid;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }
}
