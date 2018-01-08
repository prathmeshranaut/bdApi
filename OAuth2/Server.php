<?php

namespace Xfrocks\Api\OAuth2;

use League\OAuth2\Server\AbstractServer;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Entity\ScopeEntity;
use League\OAuth2\Server\Exception\AccessDeniedException;
use League\OAuth2\Server\Exception\OAuthException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\ClientCredentialsGrant;
use League\OAuth2\Server\Grant\PasswordGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use XF\Container;
use XF\Mvc\Controller;
use Xfrocks\Api\App;
use Xfrocks\Api\Controller\OAuth2;
use Xfrocks\Api\Entity\Client;
use Xfrocks\Api\Listener;
use Xfrocks\Api\OAuth2\Entity\AccessTokenHybrid;
use Xfrocks\Api\OAuth2\Entity\ClientHybrid;
use Xfrocks\Api\OAuth2\Storage\AccessTokenStorage;
use Xfrocks\Api\OAuth2\Storage\AuthCodeStorage;
use Xfrocks\Api\OAuth2\Storage\ClientStorage;
use Xfrocks\Api\OAuth2\Storage\RefreshTokenStorage;
use Xfrocks\Api\OAuth2\Storage\ScopeStorage;
use Xfrocks\Api\OAuth2\Storage\SessionStorage;
use Xfrocks\Api\XF\Pub\Controller\Account;

class Server
{
    const SCOPE_READ = 'read';
    const SCOPE_POST = 'post';
    const SCOPE_MANAGE_ACCOUNT_SETTINGS = 'usercp';
    const SCOPE_PARTICIPATE_IN_CONVERSATIONS = 'conversate';
    const SCOPE_MANAGE_SYSTEM = 'admincp';

    /**
     * @var App
     */
    protected $app;

    /**
     * @var Container
     */
    protected $container;

    /**
     * @var bool
     */
    protected $parsedRequest = false;

    /**
     * @param App $app
     */
    public function __construct($app)
    {
        require_once(dirname(__DIR__) . '/vendor/autoload.php');

        $this->app = $app;

        $this->container = new Container();

        $this->container['grant.auth_code'] = function () {
            $authCode = new AuthCodeGrant();
            $authCode->setAuthTokenTTL($this->getOptionAuthCodeTTL());

            return $authCode;
        };

        $this->container['grant.client_credentials'] = function () {
            return new ClientCredentialsGrant();
        };

        $this->container['grant.password'] = function () {
            return new PasswordGrant();
        };

        $this->container['grant.refresh_token'] = function () {
            $refreshToken = new RefreshTokenGrant();
            $refreshToken->setRefreshTokenTTL($this->getOptionRefreshTokenTTL());

            return $refreshToken;
        };

        $this->container['server.auth'] = function (Container $c) {
            $authorizationServer = new AuthorizationServer();
            $authorizationServer->setAccessTokenTTL($this->getOptionAccessTokenTTL())
                ->setDefaultScope(self::SCOPE_READ)
                ->setScopeDelimiter(Listener::$scopeDelimiter)
                ->addGrantType($c['grant.auth_code'])
                ->addGrantType($c['grant.password'])
                ->addGrantType($c['grant.refresh_token'])
                ->addGrantType($c['grant.client_credentials'])
                ->setAccessTokenStorage($c['storage.access_token'])
                ->setAuthCodeStorage($c['storage.auth_code'])
                ->setClientStorage($c['storage.client'])
                ->setRefreshTokenStorage($c['storage.refresh_token'])
                ->setScopeStorage($c['storage.scope'])
                ->setSessionStorage($c['storage.session']);

            return $authorizationServer;
        };

        $this->container['server.resource'] = function (Container $c) {
            $resourceServer = new ResourceServer(
                $c['storage.session'],
                $c['storage.access_token'],
                $c['storage.client'],
                $c['storage.scope']
            );
            $resourceServer->setIdKey(Listener::$accessTokenParamKey);

            return $resourceServer;
        };

        $this->container['storage.access_token'] = function () {
            return new AccessTokenStorage($this->app);
        };

        $this->container['storage.auth_code'] = function () {
            return new AuthCodeStorage($this->app);
        };

        $this->container['storage.client'] = function () {
            return new ClientStorage($this->app);
        };

        $this->container['storage.refresh_token'] = function () {
            return new RefreshTokenStorage($this->app);
        };

        $this->container['storage.scope'] = function () {
            return new ScopeStorage($this->app);
        };

        $this->container['storage.session'] = function () {
            return new SessionStorage($this->app);
        };
    }

    /**
     * @return int
     */
    public function getOptionAccessTokenTTL()
    {
        return $this->app->options()->bdApi_tokenTTL;
    }

    /**
     * @return int
     */
    public function getOptionAuthCodeTTL()
    {
        return $this->app->options()->bdApi_authCodeTTL;
    }

    /**
     * @return int
     */
    public function getOptionRefreshTokenTTL()
    {
        return $this->app->options()->bdApi_refreshTokenTTLDays * 86400;
    }

    /**
     * @param string $scopeId
     * @return null|\XF\Phrase
     */
    public function getScopeDescription($scopeId)
    {
        switch ($scopeId) {
            case self::SCOPE_READ:
            case self::SCOPE_POST:
            case self::SCOPE_MANAGE_ACCOUNT_SETTINGS:
            case self::SCOPE_PARTICIPATE_IN_CONVERSATIONS:
            case self::SCOPE_MANAGE_SYSTEM:
                break;
            default:
                return null;
        }

        return \XF::phrase('bdapi_scope_' . $scopeId);
    }

    /**
     * @param array $scopes
     * @param AbstractServer $server
     * @return array
     */
    public function getScopeObjArrayFromStrArray($scopes, $server)
    {
        $result = [];
        if (!is_array($scopes)) {
            return $result;
        }

        foreach ($scopes as $scope) {
            if (!is_string($scope)) {
                continue;
            }

            $description = $this->getScopeDescription($scope);
            if ($description === null) {
                continue;
            }

            $result[$scope] = (new ScopeEntity($server))->hydrate([
                'id' => $scope,
                'description' => $description
            ]);
        }

        return $result;
    }

    /**
     * @param array $scopes
     * @return array
     */
    public function getScopeStrArrayFromObjArray($scopes)
    {
        $scopeIds = [];
        if (!is_array($scopes)) {
            return $scopeIds;
        }

        /** @var ScopeEntity $scope */
        foreach ($scopes as $scope) {
            $scopeIds[] = $scope->getId();
        }

        return $scopeIds;
    }

    /**
     * @param Account $controller
     * @return array
     * @throws \XF\Mvc\Reply\Exception
     */
    public function grantAuthCodeCheckParams($controller)
    {
        /** @var AuthorizationServer $authorizationServer */
        $authorizationServer = $this->container['server.auth'];

        /** @var AuthCodeGrant $authCodeGrant */
        $authCodeGrant = $authorizationServer->getGrantType('authorization_code');

        try {
            $params = $authCodeGrant->checkAuthorizeParams();

            if (isset($params['client'])) {
                /** @var ClientHybrid $client */
                $client = $params['client'];
                $params['client'] = $client->getXfClient();
            }

            if (isset($params['scopes'])) {
                $scopes = $params['scopes'];
                $params['scopes'] = $this->getScopeStrArrayFromObjArray($scopes);
            }

            return $params;
        } catch (OAuthException $e) {
            throw $this->buildControllerException($controller, $e);
        }
    }

    /**
     * @param Account $controller
     * @param array $params
     * @return \XF\Mvc\Reply\Redirect
     */
    public function grantAuthCodeNewAuthRequest($controller, array $params)
    {
        /** @var AuthorizationServer $authorizationServer */
        $authorizationServer = $this->container['server.auth'];
        /** @var AuthCodeGrant $authCodeGrant */
        $authCodeGrant = $authorizationServer->getGrantType('authorization_code');

        if (isset($params['client'])) {
            /** @var Client $xfClient */
            $xfClient = $params['client'];
            $params['client'] = $authorizationServer->getClientStorage()->get($xfClient->client_id);
        }

        if (isset($params['scopes'])) {
            $scopes = $params['scopes'];
            $params['scopes'] = $this->getScopeObjArrayFromStrArray($scopes, $authorizationServer);
        }

        $redirectUri = $authCodeGrant->newAuthorizeRequest(
            SessionStorage::OWNER_TYPE_USER,
            $this->app->session()->get(SessionStorage::SESSION_KEY_USER_ID),
            $params
        );

        return $controller->redirect($redirectUri);
    }

    /**
     * @param OAuth2 $controller
     * @return array
     * @throws \XF\Mvc\Reply\Exception
     */
    public function grantFinalize($controller)
    {
        /** @var AuthorizationServer $authorizationServer */
        $authorizationServer = $this->container['server.auth'];

        /** @var PasswordGrant $passwordGrant */
        $passwordGrant = $authorizationServer->getGrantType('password');
        $passwordGrant->setVerifyCredentialsCallback(function ($username, $password) use ($controller) {
            return $controller->verifyCredentials($username, $password);
        });

        $db = $controller->app()->db();
        $db->beginTransaction();
        try {
            $data = $authorizationServer->issueAccessToken();

            $db->commit();

            return $data;
        } catch (OAuthException $e) {
            $db->rollback();

            throw $this->buildControllerException($controller, $e);
        }
    }

    /**
     * @return AccessTokenHybrid|null
     */
    public function parseRequest()
    {
        if ($this->parsedRequest) {
            throw new \RuntimeException('Cannot parse request twice');
        }

        /** @var ResourceServer $resourceServer */
        $resourceServer = $this->container['server.resource'];
        $accessDenied = false;
        try {
            $resourceServer->isValidRequest(false);
        } catch (AccessDeniedException $ade) {
            $accessDenied = true;
        } catch (OAuthException $e) {
            // ignore other exception
        }

        $this->parsedRequest = true;

        if ($accessDenied) {
            return null;
        }

        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return $resourceServer->getAccessToken();
    }

    /**
     * @param Controller $controller
     * @param OAuthException $e
     * @return \XF\Mvc\Reply\Exception
     */
    protected function buildControllerException($controller, $e)
    {
        $shouldLog = ($e->httpStatusCode >= 500);

        $errors = [$e->getMessage()];
        if (\XF::$debugMode) {
            $errors = array_merge($errors, $e->getTrace());
            $shouldLog = true;
        }

        if ($shouldLog) {
            \XF::logException($e, false, 'API:', true);
        }

        if ($e->shouldRedirect()) {
            return $controller->exception($controller->redirect($e->getRedirectUri()));
        }

        // TODO: include $e->getHttpHeaders() data

        return $controller->errorException($errors, $e->httpStatusCode);
    }
}